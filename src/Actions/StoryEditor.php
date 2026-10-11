<?php

namespace Ombabush\StoryStudio\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Ombabush\StoryStudio\Models\Node;
use Ombabush\StoryStudio\Models\Page;
use Ombabush\StoryStudio\Models\Story;
use Ombabush\StoryStudio\Support\ImageStore;
use Ombabush\StoryStudio\Support\ReadingOrder;
use Ombabush\StoryStudio\Support\TypeGuess;
use Ombabush\StoryStudio\Support\Version;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Every change the Studio can make, without Livewire in sight — the same calls serve the editor, the
 * import command and any future API. Authorization is the caller's job (Access), not this class's.
 *
 * Reversible bulk changes (move, status) return a snapshot; restore() puts back exactly what was there.
 */
class StoryEditor
{
    /** Fields an editor may write on a page. Everything else (ids, story, image pointer) is not input. */
    public const PAGE_FIELDS = ['type', 'caption', 'body', 'note', 'dialogue', 'alt', 'time_label', 'time_rank',
        'time_place', 'time_who', 'hints', 'regions', 'status', 'hidden'];

    public function __construct(private ImageStore $images, private ReadingOrder $order) {}

    public function createStory(array $attributes, ?Authenticatable $owner = null): Story
    {
        $title = trim((string) ($attributes['title'] ?? ''));
        if ($title === '') {
            throw new InvalidArgumentException('A story needs a title.');
        }

        return Story::create([
            'title' => $title,
            'authors' => $attributes['authors'] ?? null,
            'description' => $attributes['description'] ?? null,
            'direction' => ($attributes['direction'] ?? 'ltr') === 'rtl' ? 'rtl' : 'ltr',
            'settings' => $attributes['settings'] ?? null,
            'owner_id' => $owner?->getAuthIdentifier(),
        ]);
    }

    public function updateStory(Story $story, array $attributes): Story
    {
        $story->fill(array_intersect_key($attributes, array_flip(['title', 'authors', 'description', 'direction', 'settings'])));
        // Languages and views are written through Edition, so the column only ever holds the typed shape.
        if (array_key_exists('languages', $attributes) || array_key_exists('views', $attributes)) {
            $current = (array) $story->settings;
            $current['languages']['original'] ??= $story->edition()->original;
            $story->settings = \Ombabush\StoryStudio\Support\Edition::write($current,
                $attributes['languages'] ?? null, $attributes['views'] ?? null);
        }
        if ($story->direction !== 'rtl') {
            $story->direction = 'ltr';
        }
        $story->save();

        return $story;
    }

    public function publish(Story $story): void
    {
        $story->forceFill(['published_at' => $story->published_at ?? now()])->save();
    }

    public function unpublish(Story $story): void
    {
        $story->forceFill(['published_at' => null])->save();
    }

    /** Pages go one by one through their model, so MediaLibrary removes their files with them. */
    public function deleteStory(Story $story): void
    {
        DB::transaction(function () use ($story) {
            Page::where('story_id', $story->id)->get()->each->delete();
            $story->delete();
        });
    }

    // ── tree ────────────────────────────────────────────────────────────────────────────────

    public function addNode(Story $story, string $kind, string $title, ?Node $parent = null): Node
    {
        if (! in_array($kind, [Node::VOLUME, Node::CHAPTER], true)) {
            throw new InvalidArgumentException("Unknown node kind [$kind].");
        }
        if ($parent && $parent->story_id !== $story->id) {
            throw new InvalidArgumentException('Parent belongs to another story.');
        }

        // Appended as the last child of $parent, or the last root of the story's tree.
        return Node::create([
            'story_id' => $story->id,
            'kind' => $kind,
            'title' => trim($title) !== '' ? trim($title) : ($kind === Node::VOLUME ? 'Новый том' : 'Новая глава'),
        ], $parent);
    }

    public function renameNode(Node $node, string $title): void
    {
        if (trim($title) !== '') {
            $node->update(['title' => trim($title)]);
        }
    }

    /** Deletes the node, its sub-nodes and their pages (with files). The UI asks first. */
    public function deleteNode(Node $node): void
    {
        DB::transaction(function () use ($node) {
            $ids = $node->descendants()->pluck('id')->push($node->id);
            Page::whereIn('node_id', $ids)->get()->each->delete();
            // The tree removes descendants by query, without model events: their texts and music go here.
            $node->descendants()->get()->each(function (Node $d) {
                \Ombabush\StoryStudio\Support\Texts::forget('node', $d);
                \Ombabush\StoryStudio\Support\Attachments::forget('node', $d);
            });
            // NodeTrait removes the descendants and closes the gap in _lft/_rgt.
            $node->delete();
        });
    }

    /** Appends the next chapter of the same parent to this one and removes the emptied chapter. */
    public function mergeWithNext(Node $chapter): ?Node
    {
        $next = $chapter->nextSiblings()->where('kind', Node::CHAPTER)->defaultOrder()->first();
        if (! $next) {
            return null;
        }
        DB::transaction(function () use ($chapter, $next) {
            $base = (int) Page::where('node_id', $chapter->id)->max('position');
            foreach (Page::where('node_id', $next->id)->orderBy('position')->get() as $i => $page) {
                $page->forceFill(['node_id' => $chapter->id, 'position' => $base + $i + 1])->save();
            }
            // The merged chapter's music stays, now under the chapter it joined.
            \Ombabush\StoryStudio\Models\Attachment::where('story_id', $chapter->story_id)->where('scope', 'node')
                ->where('scope_id', $next->public_id)->update(['scope_id' => $chapter->public_id]);
            $next->delete();
        });

        return $next;
    }

    /** Pages from this one to the end of its chapter go to a new chapter right after it. */
    public function splitBefore(Page $page, ?string $title = null): Node
    {
        $chapter = $page->node;
        if (! $chapter) {
            throw new InvalidArgumentException('Only a page inside a chapter can split it.');
        }

        return DB::transaction(function () use ($page, $chapter, $title) {
            $new = new Node(['story_id' => $chapter->story_id, 'kind' => Node::CHAPTER, 'title' => $title ?: $chapter->title.' (2)']);
            $new->insertAfterNode($chapter);
            $tail = Page::where('node_id', $chapter->id)->where('position', '>=', $page->position)->orderBy('position')->get();
            foreach ($tail as $i => $p) {
                $p->forceFill(['node_id' => $new->id, 'position' => $i + 1])->save();
            }

            return $new;
        });
    }

    // ── pages ───────────────────────────────────────────────────────────────────────────────

    /**
     * @param  array<int, array{file: UploadedFile|string, name?: string}>  $files  already in reading order
     * @return Collection<int, Page>
     */
    public function addImagePages(Story $story, ?Node $node, array $files, ?Page $before = null, string $status = Page::DRAFT): Collection
    {
        $this->assertNode($story, $node);
        $created = collect();
        foreach ($files as $item) {
            $page = Page::create([
                'story_id' => $story->id,
                'node_id' => $node?->id,
                'position' => 0,
                'type' => 'image',
                'status' => $status,
            ]);
            $image = $this->images->addVersion($page, $item['file'], $item['name'] ?? null);
            $page->forceFill(['type' => $item['type'] ?? TypeGuess::fromSize(Version::width($image), Version::height($image))])->save();
            $created->push($page);
        }
        $this->place($story, $created->pluck('public_id')->all(), $node, $before?->public_id);

        return $created->map->fresh();
    }

    public function addTextPage(Story $story, ?Node $node, array $attributes = [], ?Page $before = null): Page
    {
        $this->assertNode($story, $node);
        $page = Page::create(['story_id' => $story->id, 'node_id' => $node?->id, 'position' => 0, 'type' => 'text', 'status' => Page::DRAFT]
            + $this->pageFields($attributes));
        $this->place($story, [$page->public_id], $node, $before?->public_id);

        return $page->fresh();
    }

    public function updatePage(Page $page, array $fields): Page
    {
        $page->fill($this->pageFields($fields))->save();

        return $page;
    }

    public function replaceImage(Page $page, UploadedFile|string $file, ?string $name = null): Media
    {
        return $this->images->addVersion($page, $file, $name);
    }

    /** "Вернуть" an older version: it becomes current again, nothing is deleted. */
    public function useVersion(Page $page, Media $image): void
    {
        if ($image->model_type !== $page->getMorphClass() || (int) $image->model_id !== $page->id || $image->collection_name !== Page::VERSIONS) {
            throw new InvalidArgumentException('That image belongs to another page.');
        }
        $page->forceFill(['current_media_id' => $image->id])->save();
    }

    /**
     * Moves pages (in their current reading order) into $target, before $beforeId or at the end.
     * Returns the snapshot restore() needs to undo it.
     */
    public function movePages(Story $story, array $pageIds, ?Node $target, ?string $beforeId = null): array
    {
        $this->assertNode($story, $target);
        $moving = $this->pagesInOrder($story, $pageIds);
        if ($moving->isEmpty()) {
            return [];
        }
        $snapshot = $this->snapshot($story, $moving->pluck('node_id')->push($target?->id)->unique()->all());
        $this->place($story, $moving->pluck('public_id')->all(), $target, $beforeId);

        return $snapshot;
    }

    /** Status and visibility for many pages; null leaves that field as it is. Returns an undo snapshot. */
    public function setStatus(Story $story, array $pageIds, ?string $status, ?bool $hidden = null): array
    {
        if ($status !== null && ! in_array($status, [Page::DRAFT, Page::READY], true)) {
            throw new InvalidArgumentException("Unknown status [$status].");
        }
        $pages = Page::where('story_id', $story->id)->whereIn('public_id', $pageIds)->get();
        $snapshot = $pages->mapWithKeys(fn (Page $p) => [$p->public_id => ['status' => $p->status, 'hidden' => $p->hidden]])->all();
        foreach ($pages as $page) {
            $page->forceFill(array_filter(['status' => $status, 'hidden' => $hidden], fn ($v) => $v !== null))->save();
        }

        return $snapshot;
    }

    /** Puts back exactly what a snapshot recorded: chapter, position, status, visibility. */
    public function restore(Story $story, array $snapshot): void
    {
        DB::transaction(function () use ($story, $snapshot) {
            $pages = Page::where('story_id', $story->id)->whereIn('public_id', array_keys($snapshot))->get()->keyBy('public_id');
            foreach ($snapshot as $id => $was) {
                if ($page = $pages[$id] ?? null) {
                    $page->forceFill(array_intersect_key($was, array_flip(['node_id', 'position', 'status', 'hidden'])))->save();
                }
            }
        });
    }

    public function deletePages(Story $story, array $pageIds): int
    {
        $pages = Page::where('story_id', $story->id)->whereIn('public_id', $pageIds)->get();
        $nodes = $pages->pluck('node_id')->unique()->all();
        DB::transaction(fn () => $pages->each->delete());
        foreach ($nodes as $nodeId) {
            $this->renumber($story, $nodeId);
        }

        return $pages->count();
    }

    // ── internals ───────────────────────────────────────────────────────────────────────────

    private function pageFields(array $fields): array
    {
        $out = array_intersect_key($fields, array_flip(self::PAGE_FIELDS));
        if (isset($out['type']) && ! in_array($out['type'], Page::TYPES, true)) {
            unset($out['type']);
        }
        if (isset($out['status']) && ! in_array($out['status'], [Page::DRAFT, Page::READY], true)) {
            unset($out['status']);
        }
        if (array_key_exists('time_rank', $out)) {
            $out['time_rank'] = is_numeric($out['time_rank']) ? (int) $out['time_rank'] : null;
        }
        if (array_key_exists('hidden', $out)) {
            $out['hidden'] = (bool) $out['hidden'];
        }
        if (array_key_exists('hints', $out)) {
            $out['hints'] = \Ombabush\StoryStudio\Support\PageHints::clean($out['hints']);
        }
        foreach (['caption', 'body', 'note', 'dialogue', 'alt', 'time_label', 'time_place', 'time_who'] as $text) {
            if (array_key_exists($text, $out)) {
                $out[$text] = is_string($out[$text]) && trim($out[$text]) !== '' ? $out[$text] : null;
            }
        }

        return $out;
    }

    private function assertNode(Story $story, ?Node $node): void
    {
        if ($node && $node->story_id !== $story->id) {
            throw new InvalidArgumentException('That chapter belongs to another story.');
        }
    }

    /** Puts the given pages into $node before $beforeId (or at the end) and renumbers every node touched. */
    private function place(Story $story, array $publicIds, ?Node $node, ?string $beforeId): void
    {
        DB::transaction(function () use ($story, $publicIds, $node, $beforeId) {
            $moving = Page::where('story_id', $story->id)->whereIn('public_id', $publicIds)->get()
                ->sortBy(fn (Page $p) => array_search($p->public_id, $publicIds, true))->values();
            $sources = $moving->pluck('node_id')->unique()->all();

            $rest = Page::where('story_id', $story->id)->where('node_id', $node?->id)
                ->whereNotIn('public_id', $publicIds)->orderBy('position')->get()->values();
            $at = $beforeId ? $rest->search(fn (Page $p) => $p->public_id === $beforeId) : false;
            $at = $at === false ? $rest->count() : $at;
            $final = $rest->slice(0, $at)->concat($moving)->concat($rest->slice($at))->values();

            foreach ($final as $i => $page) {
                if ($page->node_id !== $node?->id || $page->position !== $i + 1) {
                    $page->forceFill(['node_id' => $node?->id, 'position' => $i + 1])->save();
                }
            }
            foreach ($sources as $nodeId) {
                if ($nodeId !== $node?->id) {
                    $this->renumber($story, $nodeId);
                }
            }
        });
    }

    private function renumber(Story $story, ?int $nodeId): void
    {
        foreach (Page::where('story_id', $story->id)->where('node_id', $nodeId)->orderBy('position')->get() as $i => $page) {
            if ($page->position !== $i + 1) {
                $page->forceFill(['position' => $i + 1])->save();
            }
        }
    }

    private function snapshot(Story $story, array $nodeIds): array
    {
        $query = Page::where('story_id', $story->id)->where(function ($q) use ($nodeIds) {
            $ids = array_filter($nodeIds, fn ($id) => $id !== null);
            $q->whereIn('node_id', $ids ?: [0]);
            if (in_array(null, $nodeIds, true)) {
                $q->orWhereNull('node_id');
            }
        });

        return $query->get()->mapWithKeys(fn (Page $p) => [$p->public_id => ['node_id' => $p->node_id, 'position' => $p->position]])->all();
    }

    private function pagesInOrder(Story $story, array $pageIds): Collection
    {
        $want = array_flip($pageIds);

        return $this->order->build($story)['pages']->filter(fn (Page $p) => isset($want[$p->public_id]))->values();
    }
}
