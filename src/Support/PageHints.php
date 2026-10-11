<?php

namespace Ombabush\StoryStudio\Support;

/**
 * Layout hints of one page (ss_pages.hints), typed (docs/DATA-MODEL.md §1.4):
 *   pair         auto — the picture's width decides; alone — always on its own; start — a pair begins here
 *   pan.seconds  pace of a panorama's auto-scroll
 * Unknown keys and bad values are dropped on write, so the column only ever holds this shape.
 */
final class PageHints
{
    public const PAIR = ['auto', 'alone', 'start'];

    public static function clean(mixed $hints): ?array
    {
        $hints = is_array($hints) ? $hints : [];
        $out = [];
        if (in_array($hints['pair'] ?? null, self::PAIR, true) && $hints['pair'] !== 'auto') {
            $out['pair'] = $hints['pair'];
        }
        $seconds = $hints['pan']['seconds'] ?? null;
        if (is_numeric($seconds) && $seconds >= 3 && $seconds <= 600) {
            $out['pan'] = ['seconds' => (int) $seconds];
        }

        return $out ?: null;
    }
}
