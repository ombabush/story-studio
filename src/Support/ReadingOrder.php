<?php

namespace Ombabush\StoryStudio\Support;

use Illuminate\Support\Collection;
use Ombabush\StoryStudio\Models\Node;
use Ombabush\StoryStudio\Models\Page;
use Ombabush\StoryStudio\Models\Story;

/**
 * The narrative order is the tree read top to bottom: pages loose in the story first, then each volume's
 * loose pages and chapters — nodes in nested-set order (_lft), pages by their position in the node. Numbering is derived here and never stored, so a move
 * renumbers everything without touching a page's identity.
 */
class ReadingOrder
{
    /** @return array{pages: Collection<int, Page>, chapters: array<int, array>} */
    public function build(Story $story, ?callable $filter = null): array
    {
        $nodes = Node::where('story_id', $story->id)->defaultOrder()->get();
        $pages = Page::where('story_id', $story->id)->with('currentImage')->orderBy('position')->get();
        $byNode = $pages->groupBy(fn (Page $p) => $p->node_id ?? 0);
        $children = $nodes->groupBy(fn (Node $n) => $n->parent_id ?? 0);

        $ordered = collect();
        $chapters = [];
        $chapterNo = 0;

        $walk = function (int $parentId, ?Node $volume) use (&$walk, $byNode, $children, &$ordered, &$chapters, &$chapterNo, $filter) {
            foreach ($byNode[$parentId] ?? [] as $page) {
                if (! $filter || $filter($page)) {
                    $ordered->push($page);
                }
            }
            foreach ($children[$parentId] ?? [] as $node) {
                if ($node->kind === Node::CHAPTER) {
                    $chapterNo++;
                    $chapters[$node->id] = ['node' => $node, 'no' => $chapterNo, 'volume' => $volume];
                }
                $walk($node->id, $node->kind === Node::VOLUME ? $node : $volume);
            }
        };
        $walk(0, null);

        // Inherit story time: a page without its own time takes the previous page's, for the timeline.
        $rank = null;
        $inChapter = [];
        foreach ($ordered->values() as $i => $page) {
            $page->setAttribute('n', $i + 1);
            $key = $page->node_id ?? 0;
            $inChapter[$key] = ($inChapter[$key] ?? 0) + 1;
            $page->setAttribute('n_in_chapter', $inChapter[$key]);
            $page->setAttribute('chapter_no', $chapters[$page->node_id]['no'] ?? null);
            $rank = $page->time_rank ?? $rank;
            $page->setAttribute('effective_rank', $rank);
        }

        return ['pages' => $ordered->values(), 'chapters' => $chapters];
    }

    /** The full tree for the Studio: volumes → chapters with their counts. */
    public function tree(Story $story): array
    {
        $nodes = Node::where('story_id', $story->id)->defaultOrder()->get();
        $counts = Page::where('story_id', $story->id)->selectRaw('node_id, count(*) as c')->groupBy('node_id')->pluck('c', 'node_id');
        $children = $nodes->groupBy(fn (Node $n) => $n->parent_id ?? 0);
        $no = 0;

        $make = function (int $parentId) use (&$make, $children, $counts, &$no) {
            return collect($children[$parentId] ?? [])->map(function (Node $n) use (&$make, $counts, &$no) {
                $chapterNo = $n->kind === Node::CHAPTER ? ++$no : null;
                $kids = $make($n->id);
                $own = (int) ($counts[$n->id] ?? 0);

                return [
                    'id' => $n->public_id,
                    'kind' => $n->kind,
                    'title' => $n->title,
                    'no' => $chapterNo,
                    'count' => $own,
                    'total' => $own + array_sum(array_column($kids, 'total')),
                    'children' => $kids,
                ];
            })->all();
        };

        return ['loose' => (int) ($counts[''] ?? $counts[0] ?? 0), 'nodes' => $make(0)];
    }
}
