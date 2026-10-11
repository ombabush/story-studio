<?php

namespace Ombabush\StoryStudio\Interaction;

use Ombabush\StoryStudio\Models\Page;
use Ombabush\StoryStudio\Models\Story;
use Ombabush\StoryStudio\Support\Version;

/**
 * The reader's view of a page's interactions — a whitelist, like Presenter (CANON SR-09).
 *
 *   - a jump whose target page this reader may not see is not sent at all: no id, no label, no count;
 *   - a card travels only with an interaction that opens it;
 *   - a page whose picture changed since its contours were drawn sends no regions, only the reason
 *     (C-16); text links do not depend on the picture and stay;
 *   - a text block whose runs no longer spell the published caption/paragraph is dropped, so a link
 *     never lands on the wrong words.
 *
 * Nothing here is evaluated by the browser as code: shapes are numbers and a path string the
 * validator already reduced to M/L/Q/C/Z.
 */
class ProfileProjector
{
    public function enabled(Story $story): bool
    {
        return (bool) config('story-studio.reader.interaction', false) && is_array($story->interaction);
    }

    /**
     * @param  array<string, true>  $visible  public ids of every page this reader may open
     * @return array|null null when the page has no interactions for this reader
     */
    public function page(Story $story, Page $page, array $visible, array $presented): ?array
    {
        if (! $this->enabled($story)) {
            return null;
        }
        $profile = Draft::of($story);
        $binding = Draft::binding($profile, $page->public_id);
        if (! $binding) {
            return null;
        }
        // A draft may hold half-made interactions; the reader gets only the ones that validate.
        $invalid = Draft::analyse($story)['invalid'];

        $image = $page->currentImage;
        $bound = $story->interaction_surfaces[$page->public_id] ?? null;
        $hasRegions = collect($binding['interactions'])->contains(fn ($it) => ($it['anchor']['type'] ?? null) === 'region');
        $stale = $hasRegions && (! $image || Version::broken($image) || Version::sha1($image) !== $bound);
        $blocks = $this->textBlocks($binding['textBlocks'] ?? [], $presented);

        $cards = collect($profile['cards'] ?? [])->keyBy('id');
        $groups = collect($binding['groups'] ?? [])->keyBy('id');
        $items = [];
        $usedCards = [];
        foreach (array_values($binding['interactions'] ?? []) as $i => $it) {
            if (isset($invalid[$it['id'] ?? '']) || ! isset($it['action']['type'], $it['anchor']['type'])) {
                continue;
            }
            $action = $it['action'];
            if ($action['type'] === 'jump' && ! isset($visible[$action['pageId']])) {
                continue;   // a branch into a page this reader cannot open does not exist for them
            }
            if ($action['type'] === 'open-card' && ! $cards->has($action['cardId'])) {
                continue;
            }
            $anchor = $it['anchor'];
            if ($anchor['type'] === 'region' && $stale) {
                continue;
            }
            if ($anchor['type'] === 'text' && ! isset($blocks[$anchor['blockId']])) {
                continue;
            }
            if ($action['type'] === 'open-card') {
                $usedCards[$action['cardId']] = true;
            }
            $items[] = [
                'id' => $it['id'],
                'kind' => $it['kind'],
                'label' => $it['label'],
                'anchor' => $anchor,
                'action' => $this->action($action),
                'essential' => ($it['importance'] ?? 'supplementary') === 'essential',
                'order' => $it['order'] ?? $i,
                'group' => isset($it['groupId'], $groups[$it['groupId']]) ? ['id' => $it['groupId'], 'label' => $groups[$it['groupId']]['label']] : null,
                // An author's wish, applied by the reader after its own settings (EFFECTS.md).
                'effect' => $it['presentation']['preferredEffect'] ?? null,
            ];
        }
        usort($items, fn ($a, $b) => $a['order'] <=> $b['order']);

        // Anchors are where a jump lands inside this page (a panel, a detail): public geometry only.
        $anchors = collect($binding['anchors'] ?? [])->map(fn ($a) => ['id' => $a['id'], 'shape' => $a['shape']])->values()->all();
        if (! $items && ! $stale && ! $anchors) {
            return null;
        }

        return [
            'language' => $profile['language'],
            'items' => $items,
            'stale' => $stale,
            'anchors' => $stale ? [] : $anchors,
            'blocks' => array_values($blocks),
            'cards' => $cards->only(array_keys($usedCards))->map(fn ($c) => array_intersect_key($c, array_flip(['id', 'kind', 'title', 'body'])))->values()->all(),
        ];
    }

    private function action(array $action): array
    {
        return match ($action['type']) {
            'jump' => array_filter(['type' => 'jump', 'pageId' => $action['pageId'], 'anchorId' => $action['anchorId'] ?? null]),
            'open-card' => ['type' => 'open-card', 'cardId' => $action['cardId']],
            'open-url' => ['type' => 'open-url', 'url' => $action['url'], 'host' => parse_url($action['url'], PHP_URL_HOST)],
            default => ['type' => $action['type']],
        };
    }

    /**
     * Text blocks that still spell a published text of this page: the caption, or one paragraph.
     *
     * @return array<string, array{id: string, target: string, runs: array}>
     */
    private function textBlocks(array $blocks, array $presented): array
    {
        $texts = ['caption' => (string) ($presented['caption'] ?? '')];
        foreach ((array) ($presented['paragraphs'] ?? []) as $i => $para) {
            $texts['p'.$i] = (string) $para;
        }
        $out = [];
        foreach ($blocks as $b) {
            $joined = implode('', array_column($b['runs'], 'text'));
            $target = array_search($joined, $texts, true);
            if ($target !== false && $joined !== '') {
                $out[$b['id']] = ['id' => $b['id'], 'target' => $target, 'runs' => $b['runs']];
            }
        }

        return $out;
    }
}
