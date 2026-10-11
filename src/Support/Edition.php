<?php

namespace Ombabush\StoryStudio\Support;

use Ombabush\StoryStudio\Models\Story;

/**
 * An edition's own settings (ss_stories.settings), typed: which languages it offers and which views a
 * reader may choose. Read through here only — defaults, the legacy `modes` key and bad values are
 * resolved in one place (docs/DATA-MODEL.md §1.1).
 */
final class Edition
{
    public const TAG = '/^[a-z]{2,8}(-[A-Za-z0-9]{1,8})*$/';

    /**
     * @param  string[]  $languages  original first
     * @param  string[]  $views  reader views, catalog order; never an editor-only view
     */
    private function __construct(
        public readonly string $original,
        public readonly array $languages,
        public readonly array $views,
        public readonly string $defaultView,
    ) {}

    public static function of(Story $story): self
    {
        $settings = (array) ($story->settings ?? []);
        // Before an edition named its language, its interaction draft already did.
        $settings['languages']['original'] ??= $story->interaction['language'] ?? null;

        return self::from($settings);
    }

    public static function from(array $settings): self
    {
        $catalog = app(ReaderCatalog::class);
        $original = self::tag($settings['languages']['original'] ?? null) ?? self::tag($catalog->get('original'))
            ?? self::tag(config('app.locale')) ?? 'en';
        $languages = [$original];
        foreach ((array) ($settings['languages']['offered'] ?? []) as $lang) {
            if (($lang = self::tag($lang)) && ! in_array($lang, $languages, true)) {
                $languages[] = $lang;
            }
        }

        $readerViews = $catalog->readerViews();
        $chosen = $settings['views']['offered'] ?? $settings['modes'] ?? null;
        $views = $chosen ? array_values(array_intersect($readerViews, (array) $chosen)) : $readerViews;
        $views = $views ?: $readerViews;
        // Unless the edition chose, a reader starts on the first single-page view, not on an overview.
        $pageViews = array_values(array_filter($views, fn ($v) => $catalog->views()[$v]['layout'] === 'page'));
        $default = in_array($settings['views']['default'] ?? null, $views, true) ? $settings['views']['default'] : ($pageViews[0] ?? $views[0]);

        return new self($original, $languages, $views, $default);
    }

    /** A clean `settings` array: this edition's choices merged over whatever else the key holds. */
    public static function write(array $current, ?array $languages = null, ?array $views = null): array
    {
        if ($languages !== null) {
            $current['languages'] = ['original' => $languages['original'] ?? null, 'offered' => array_values((array) ($languages['offered'] ?? []))];
        }
        if ($views !== null) {
            $current['views'] = ['offered' => array_values((array) ($views['offered'] ?? [])), 'default' => $views['default'] ?? null];
        }
        unset($current['modes']);
        $edition = self::from($current);
        $current['languages'] = ['original' => $edition->original, 'offered' => $edition->languages];
        $current['views'] = ['offered' => $edition->views, 'default' => $edition->defaultView];

        return $current;
    }

    public function translated(): array
    {
        return array_values(array_diff($this->languages, [$this->original]));
    }

    public function toArray(): array
    {
        return ['original' => $this->original, 'languages' => $this->languages, 'views' => $this->views, 'defaultView' => $this->defaultView];
    }

    public static function tag(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = str_replace('_', '-', trim($value));
        $parts = explode('-', $value, 2);
        $value = strtolower($parts[0]).(isset($parts[1]) ? '-'.$parts[1] : '');

        return preg_match(self::TAG, $value) ? $value : null;
    }
}
