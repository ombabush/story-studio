<?php

namespace Ombabush\StoryStudio\Support;

use Ombabush\StoryStudio\Models\ReaderPref;
use Ombabush\StoryStudio\Models\Story;

/**
 * A reader's own settings (ss_reader_prefs, docs/DATA-MODEL.md §2). Two scopes: general
 * (interface, theme, text size, languages, highlight) and per edition (view, order, a language override).
 * Everything is whitelisted here; unknown keys and bad values are dropped, never stored.
 */
class ReaderPrefs
{
    public const GLOBAL_KEYS = ['ui', 'theme', 'textSize', 'uiLang', 'lang', 'ix'];

    public const STORY_KEYS = ['view', 'order', 'lang'];

    public function __construct(private ReaderCatalog $catalog) {}

    /** General settings with this edition's on top. */
    public function for(string $readerKey, Story $story): array
    {
        $rows = ReaderPref::where('reader_key', $readerKey)
            ->where(fn ($q) => $q->whereNull('story_id')->orWhere('story_id', $story->id))->get();
        $general = (array) ($rows->firstWhere('story_id', null)?->prefs ?? []);
        $own = (array) ($rows->firstWhere('story_id', $story->id)?->prefs ?? []);

        return array_replace($this->clean($general, self::GLOBAL_KEYS, $story), $this->clean($own, self::STORY_KEYS, $story));
    }

    /** Merge a change into one scope; a null value removes the key. Returns the merged settings. */
    public function save(string $readerKey, Story $story, string $scope, array $changes): array
    {
        $storyId = $scope === 'story' ? $story->id : null;
        $keys = $scope === 'story' ? self::STORY_KEYS : self::GLOBAL_KEYS;
        $row = ReaderPref::firstOrNew(['reader_key' => $readerKey, 'story_id' => $storyId]);
        $prefs = (array) ($row->prefs ?? []);
        foreach (array_intersect_key($changes, array_flip($keys)) as $k => $v) {
            if ($v === null) {
                unset($prefs[$k]);
            } else {
                $prefs[$k] = $v;
            }
        }
        $prefs = $this->clean($prefs, $keys, $story);
        if ($prefs) {
            $row->prefs = $prefs;
            $row->save();
        } elseif ($row->exists) {
            $row->delete();
        }

        return $this->for($readerKey, $story);
    }

    private function clean(array $prefs, array $keys, Story $story): array
    {
        $edition = Edition::of($story);
        $out = [];
        foreach (array_intersect_key($prefs, array_flip($keys)) as $k => $v) {
            $v = match ($k) {
                'ui' => in_array($v, ReaderCatalog::UI_MODES, true) ? $v : null,
                'theme' => in_array($v, $this->catalog->themes(), true) ? $v : null,
                'textSize' => is_numeric($v) ? max(80, min(160, (int) $v)) : null,
                'uiLang' => in_array($v, $this->catalog->languages(), true) ? $v : null,
                'lang' => $this->lang($v),
                'ix' => $this->ix($v),
                'view' => is_string($v) && isset($this->catalog->views()[$v]) ? $v : null,
                'order' => in_array($v, ['told', 'happened'], true) ? $v : null,
                default => null,
            };
            if ($v !== null && $v !== []) {
                $out[$k] = $v;
            }
        }

        return $out;
    }

    private function lang(mixed $v): ?array
    {
        if (! is_array($v)) {
            return null;
        }

        return array_filter([
            'primary' => Edition::tag($v['primary'] ?? null),
            'fallback' => Edition::tag($v['fallback'] ?? null),
            'uiFirst' => ! empty($v['uiFirst']) ? true : null,
        ], fn ($x) => $x !== null) ?: null;
    }

    private function ix(mixed $v): ?array
    {
        if (! is_array($v)) {
            return null;
        }
        $effects = $this->catalog->effects();
        $kinds = [];
        foreach ((array) ($v['kinds'] ?? []) as $kind => $fx) {
            if (in_array($kind, ReaderCatalog::KINDS, true) && in_array($fx, $effects, true)) {
                $kinds[$kind] = $fx;
            }
        }

        return array_filter([
            'mode' => in_array($v['mode'] ?? null, ReaderCatalog::IX_MODES, true) ? $v['mode'] : null,
            'fx' => ($v['fx'] ?? null) === 'author' || in_array($v['fx'] ?? null, $effects, true) ? $v['fx'] : null,
            'kinds' => $kinds ?: null,
        ], fn ($x) => $x !== null) ?: null;
    }
}
