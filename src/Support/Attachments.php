<?php

namespace Ombabush\StoryStudio\Support;

use Illuminate\Support\Collection;
use InvalidArgumentException;
use Ombabush\StoryStudio\Models\Attachment;
use Ombabush\StoryStudio\Models\Node;
use Ombabush\StoryStudio\Models\Page;
use Ombabush\StoryStudio\Models\Story;

/**
 * Typed items on the edition, a chapter or a page (ss_attachments, docs/DATA-MODEL.md §1.3).
 * Each kind has one normaliser here; data that does not fit is refused, never stored half-right.
 *
 *   track — {title, artist?, seconds?, url?}   music for a place in the story
 *   link  — {label, url}                       listen elsewhere (Spotify, Apple Music…)
 */
class Attachments
{
    public const KINDS = ['track', 'link'];

    public function normalize(string $kind, array $data): array
    {
        $text = fn (string $k, int $max) => mb_substr(trim((string) ($data[$k] ?? '')), 0, $max);
        $url = $this->url($data['url'] ?? null);

        return match ($kind) {
            'track' => array_filter([
                'title' => $text('title', 200) ?: throw new InvalidArgumentException('A track needs a title.'),
                'artist' => $text('artist', 200),
                'seconds' => is_numeric($data['seconds'] ?? null) && $data['seconds'] > 0 ? min(86400, (int) $data['seconds']) : null,
                'url' => $url,
            ], fn ($v) => $v !== null && $v !== ''),
            'link' => [
                'label' => $text('label', 120) ?: throw new InvalidArgumentException('A link needs a label.'),
                'url' => $url ?? throw new InvalidArgumentException('A link needs an https:// address.'),
            ],
            default => throw new InvalidArgumentException('Unknown attachment kind.'),
        };
    }

    /** https only — a reader always sees the host before leaving the book (FORMAT open-url). */
    public function url(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (! filter_var($value, FILTER_VALIDATE_URL) || strtolower((string) parse_url($value, PHP_URL_SCHEME)) !== 'https') {
            throw new InvalidArgumentException('Only https:// addresses.');
        }

        return mb_substr($value, 0, 2000);
    }

    /** @return Collection<int, Attachment> */
    public function of(Story $story, string $scope, ?string $scopeId): Collection
    {
        return Attachment::where('story_id', $story->id)->where('scope', $scope)
            ->where('scope_id', $scope === 'story' ? null : $scopeId)->orderBy('position')->orderBy('id')->get();
    }

    public function add(Story $story, string $scope, ?string $scopeId, string $kind, array $data): Attachment
    {
        if (! in_array($scope, Attachment::SCOPES, true)) {
            throw new InvalidArgumentException('Unknown scope.');
        }
        $scopeId = $scope === 'story' ? null : $scopeId;

        return Attachment::create(['story_id' => $story->id, 'scope' => $scope, 'scope_id' => $scopeId, 'kind' => $kind,
            'data' => $this->normalize($kind, $data),
            'position' => (int) $this->of($story, $scope, $scopeId)->max('position') + 1]);
    }

    public function update(Attachment $item, array $data): Attachment
    {
        $item->update(['data' => $this->normalize($item->kind, $data + $item->data)]);

        return $item;
    }

    /** One step up or down among the items of the same scope and kind. */
    public function move(Attachment $item, int $delta): void
    {
        $story = Story::findOrFail($item->story_id);
        $list = $this->of($story, $item->scope, $item->scope_id)->where('kind', $item->kind)->values();
        $at = $list->search(fn ($a) => $a->id === $item->id);
        $to = $at + ($delta < 0 ? -1 : 1);
        if ($at === false || ! isset($list[$to])) {
            return;
        }
        $items = $list->all();
        [$items[$at], $items[$to]] = [$items[$to], $items[$at]];
        foreach ($items as $i => $a) {
            $a->update(['position' => $i + 1]);
        }
    }

    /**
     * The Reader's music: tracks per scope and the edition's links, only for chapters and pages this
     * reader can open. Priority (page → chapter → edition) is the reader's to apply.
     *
     * @param  array<string, true>  $pages  readable page ids
     * @param  array<string, true>  $nodes  node ids of those pages' chapters
     */
    public function forReader(Story $story, array $pages, array $nodes): array
    {
        $out = ['story' => [], 'links' => [], 'node' => [], 'page' => []];
        foreach (Attachment::where('story_id', $story->id)->orderBy('position')->orderBy('id')->get() as $a) {
            $item = ['id' => $a->public_id] + $a->data;
            if ($a->kind === 'link' && $a->scope === 'story') {
                $out['links'][] = $item;
            } elseif ($a->kind === 'track' && $a->scope === 'story') {
                $out['story'][] = $item;
            } elseif ($a->kind === 'track' && ($a->scope === 'node' ? isset($nodes[$a->scope_id]) : isset($pages[$a->scope_id]))) {
                $out[$a->scope][$a->scope_id][] = $item;
            }
        }
        $out['node'] = (object) $out['node'];
        $out['page'] = (object) $out['page'];

        return $out;
    }

    /** Attachments of published subjects, for the snapshot. */
    public function export(Story $story, array $pageIds, array $nodeIds): array
    {
        $pages = array_flip($pageIds);
        $nodes = array_flip($nodeIds);

        return Attachment::where('story_id', $story->id)->orderBy('scope')->orderBy('position')->orderBy('id')->get()
            ->filter(fn (Attachment $a) => $a->scope === 'story' || ($a->scope === 'node' ? isset($nodes[$a->scope_id]) : isset($pages[$a->scope_id])))
            ->map(fn (Attachment $a) => ['id' => $a->public_id, 'scope' => $a->scope, 'scopeId' => $a->scope_id, 'kind' => $a->kind, 'data' => $a->data])
            ->values()->all();
    }

    /** Mirror a snapshot's attachments; bad rows are skipped, ids mapped through $map when given. */
    public function replace(Story $story, array $rows, array $map = [], bool $keepIds = true): void
    {
        Attachment::where('story_id', $story->id)->delete();
        $position = [];
        foreach ($rows as $r) {
            $scope = (string) ($r['scope'] ?? '');
            if ($map && $scope !== 'story' && ! isset($map[$r['scopeId'] ?? ''])) {
                continue;
            }
            $scopeId = $scope === 'story' ? null : ($map[$r['scopeId'] ?? ''] ?? (string) ($r['scopeId'] ?? ''));
            if (! in_array($scope, Attachment::SCOPES, true) || ($scope !== 'story' && $scopeId === '')) {
                continue;
            }
            try {
                $data = $this->normalize((string) ($r['kind'] ?? ''), (array) ($r['data'] ?? []));
            } catch (InvalidArgumentException) {
                continue;
            }
            $key = $scope.':'.$scopeId;
            $a = new Attachment(['story_id' => $story->id, 'scope' => $scope, 'scope_id' => $scopeId, 'kind' => $r['kind'],
                'data' => $data, 'position' => $position[$key] = ($position[$key] ?? 0) + 1]);
            $id = (string) ($r['id'] ?? '');
            if ($keepIds && preg_match('/^[0-9a-hjkmnp-tv-z]{26}$/i', $id) && ! Attachment::where('public_id', $id)->exists()) {
                $a->public_id = strtolower($id);
            }
            $a->save();
        }
    }

    /** A chapter or a page was deleted: what hung on it goes too. */
    public static function forget(string $scope, Page|Node $model): void
    {
        Attachment::where('story_id', $model->story_id)->where('scope', $scope)->where('scope_id', $model->public_id)->delete();
    }
}
