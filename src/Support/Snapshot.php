<?php

namespace Ombabush\StoryStudio\Support;

use Ombabush\StoryStudio\Models\Node;
use Ombabush\StoryStudio\Models\Page;
use Ombabush\StoryStudio\Models\Story;

/**
 * The public layer of an edition as one JSON document — what leaves the author's machine when an edition
 * is published elsewhere (Local → Cloud). Built from a whitelist, like the Reader's Presenter: ready and
 * visible pages only, never the author's note, the balloon dialogue, statuses or old picture versions.
 * Pictures travel separately, by content hash, only when the receiver does not have them yet.
 * Translations (`texts`) and music (`attachments`) ride along as optional keys of the same schema.
 */
class Snapshot
{
    public const SCHEMA = 1;

    public const PAGE_FIELDS = ['type', 'caption', 'body', 'alt', 'time_label', 'time_rank', 'time_place', 'time_who', 'hints', 'regions'];

    public function __construct(private ReadingOrder $order, private Texts $texts, private Attachments $attachments) {}

    public function export(Story $story): array
    {
        $nodes = Node::where('story_id', $story->id)->defaultOrder()->get();
        $byId = $nodes->keyBy('id');
        $pages = $this->order->build($story, fn (Page $p) => $p->isPublic())['pages'];

        $pageIds = $pages->pluck('public_id')->all();
        $nodeIds = $nodes->pluck('public_id')->all();

        return [
            'schema' => self::SCHEMA,
            'story' => [
                'id' => $story->public_id,
                'title' => $story->title,
                'authors' => $story->authors,
                'description' => $story->description,
                'direction' => $story->direction,
                'settings' => $story->settings,
            ],
            // Pre-order (_lft): a parent always comes before its children, so the receiver builds in one pass.
            'nodes' => $nodes->map(fn (Node $n) => [
                'id' => $n->public_id,
                'parent' => $n->parent_id ? $byId[$n->parent_id]?->public_id : null,
                'kind' => $n->kind,
                'title' => $n->title,
            ])->values()->all(),
            // Reading order; the receiver numbers positions from it.
            'pages' => $pages->map(function (Page $p) use ($byId) {
                $image = $p->currentImage && ! Version::broken($p->currentImage) ? $p->currentImage : null;

                return ['id' => $p->public_id, 'node' => $p->node_id ? $byId[$p->node_id]?->public_id : null]
                    + $p->only(self::PAGE_FIELDS)
                    + ['image' => $image ? [
                        'sha1' => Version::sha1($image),
                        'name' => $image->name,
                        'width' => Version::width($image),
                        'height' => Version::height($image),
                    ] : null];
            })->values()->all(),
            // Added within schema 1 (DATA-MODEL §1.5): a receiver that does not know these keys ignores
            // them and keeps working. Public translations and attachments of published subjects only.
            'texts' => $this->texts->export($story, $pageIds, $nodeIds),
            'attachments' => $this->attachments->export($story, $pageIds, $nodeIds),
        ];
    }
}
