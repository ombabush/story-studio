<?php

namespace Ombabush\StoryStudio\Support;

/** "page 2" before "page 10", folders before their files, case-insensitive. */
class NaturalSort
{
    public static function paths(array $paths): array
    {
        usort($paths, fn ($a, $b) => strnatcasecmp((string) $a, (string) $b));

        return $paths;
    }

    public static function by(array $items, callable $key): array
    {
        usort($items, fn ($a, $b) => strnatcasecmp((string) $key($a), (string) $key($b)));

        return $items;
    }
}
