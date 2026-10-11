<?php

namespace Ombabush\StoryStudio\Support;

/**
 * What this Reader can do, as data (docs/DATA-MODEL.md §3): views, effects, kinds, themes,
 * interface languages and every interface string. The browser receives it whole and knows nothing by
 * name, so a new view or language is a config line and a lang file, not a code change.
 *
 * Hosts replace the whole `reader` config array; a key they do not list falls back to the package's.
 */
class ReaderCatalog
{
    public const LAYOUTS = ['grid', 'page', 'pair', 'scroll', 'story'];

    public const FITS = ['page', 'width'];

    public const TEXTS = ['none', 'below', 'subtitle', 'beside', 'script', 'story'];

    public const UI_MODES = ['edges', 'bars'];

    public const IX_MODES = ['on', 'on-demand', 'off'];

    public const KINDS = ['nav', 'character', 'object', 'place', 'note', 'media', 'reference'];

    private ?array $defaults = null;

    public function get(string $key): mixed
    {
        $this->defaults ??= (require __DIR__.'/../../config/story-studio.php')['reader'];

        return config('story-studio.reader.'.$key) ?? $this->defaults[$key] ?? null;
    }

    /** @return array<string, array{key: ?string, layout: string, fit: string, text: string, editor: bool, cells: int}> */
    public function views(): array
    {
        $out = [];
        foreach ((array) $this->get('views') as $name => $v) {
            if (! is_array($v) || ! in_array($v['layout'] ?? null, self::LAYOUTS, true)) {
                continue;
            }
            $out[$name] = [
                'key' => isset($v['key']) ? (string) $v['key'] : null,
                'layout' => $v['layout'],
                'fit' => in_array($v['fit'] ?? null, self::FITS, true) ? $v['fit'] : 'page',
                'text' => in_array($v['text'] ?? null, self::TEXTS, true) ? $v['text'] : 'below',
                'editor' => (bool) ($v['editor'] ?? false),
                'cells' => max(2, min(9, (int) ($v['cells'] ?? 4))),
            ];
        }

        return $out ?: ['read' => ['key' => '1', 'layout' => 'page', 'fit' => 'page', 'text' => 'below', 'editor' => false, 'cells' => 4]];
    }

    /** View names a reader (not only an editor) may be offered. */
    public function readerViews(): array
    {
        return array_keys(array_filter($this->views(), fn ($v) => ! $v['editor']));
    }

    public function effects(): array
    {
        return array_values(array_unique(array_merge(['outline'], (array) $this->get('effects'))));
    }

    public function languages(): array
    {
        $out = [];
        foreach ((array) $this->get('languages') as $lang) {
            if (is_file(__DIR__.'/../../resources/lang/'.$lang.'/reader.php')) {
                $out[] = $lang;
            }
        }

        return $out ?: ['en'];
    }

    public function themes(): array
    {
        return array_values(array_intersect((array) $this->get('themes'), ['dark', 'light', 'paper'])) ?: ['dark'];
    }

    public function marks(): array
    {
        return array_values((array) $this->get('marks')) ?: ['bookmark'];
    }

    /** Interface language: the reader's choice, else the host's default, else the first one shipped. */
    public function uiLanguage(?string $chosen): string
    {
        $languages = $this->languages();
        foreach ([$chosen, $this->get('language'), app()->getLocale()] as $lang) {
            if (is_string($lang) && in_array($lang, $languages, true)) {
                return $lang;
            }
        }

        return $languages[0];
    }

    public function toArray(string $uiLang): array
    {
        return [
            'views' => $this->views(),
            'effects' => $this->effects(),
            'kinds' => self::KINDS,
            'marks' => $this->marks(),
            'themes' => $this->themes(),
            'uiModes' => self::UI_MODES,
            'ixModes' => self::IX_MODES,
            'uiLanguages' => array_map(fn ($l) => ['code' => $l, 'name' => trans('story-studio::reader.self', [], $l)], $this->languages()),
            'uiLang' => $uiLang,
            'strings' => (array) trans('story-studio::reader', [], $uiLang),
        ];
    }

    /** One interface string on the server side (Blade), in the reader's interface language. */
    public function t(string $key, string $uiLang, array $replace = []): string
    {
        return (string) trans('story-studio::reader.'.$key, $replace, $uiLang);
    }
}
