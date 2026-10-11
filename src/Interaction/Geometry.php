<?php

namespace Ombabush\StoryStudio\Interaction;

/**
 * The closed geometry subset of the interaction profile (FORMAT.md, «Геометрия»): a 0…1000 plane and
 * an SVG path grammar of uppercase absolute M, L, Q, C, Z only. Parsing is a tokenizer over that
 * grammar — nothing here hands a string to an SVG engine, so a hostile `d` is an error, not a render.
 */
final class Geometry
{
    public const MAX_D_BYTES = 16384;

    public const MAX_COMMANDS = 1024;

    public const MAX_SUBPATHS = 16;

    private const ARITY = ['M' => 2, 'L' => 2, 'Q' => 4, 'C' => 6];

    /** @return string|null an error, or null when `d` is canonical */
    public static function checkPath(mixed $d): ?string
    {
        if (! is_string($d) || $d === '') {
            return 'must be a non-empty path string';
        }
        if (strlen($d) > self::MAX_D_BYTES) {
            return 'is longer than '.self::MAX_D_BYTES.' bytes';
        }
        if (! preg_match('/^[MLQCZ0-9. ]+$/', $d) || str_contains($d, '  ') || $d !== trim($d)) {
            return 'may contain only M L Q C Z, numbers and single spaces (uppercase absolute commands, no commas or exponents)';
        }

        $tokens = explode(' ', $d);
        $commands = 0;
        $subpaths = 0;
        $drawing = 0;
        $open = false;
        for ($i = 0, $n = count($tokens); $i < $n; $i++) {
            $t = $tokens[$i];
            if ($t === 'Z') {
                if (! $open) {
                    return 'Z without an open subpath';
                }
                if ($drawing < 2) {
                    return 'every subpath needs at least two drawing segments';
                }
                $open = false;
                $commands++;

                continue;
            }
            if (! isset(self::ARITY[$t])) {
                return "unexpected token «{$t}» (each set of numbers needs its own command letter)";
            }
            if ($t === 'M') {
                if ($open) {
                    return 'a subpath must end with Z before the next M';
                }
                $open = true;
                $drawing = 0;
                if (++$subpaths > self::MAX_SUBPATHS) {
                    return 'more than '.self::MAX_SUBPATHS.' subpaths';
                }
            } elseif (! $open) {
                return "{$t} outside a subpath: every subpath starts with M";
            } else {
                $drawing++;
            }
            for ($k = 1; $k <= self::ARITY[$t]; $k++) {
                $num = $tokens[$i + $k] ?? null;
                if ($num === null || ! preg_match('/^(\d{1,4})(\.\d{1,3})?$/', $num) || (float) $num > 1000) {
                    return "{$t} needs ".self::ARITY[$t].' numbers 0…1000 with at most three decimals';
                }
            }
            $i += self::ARITY[$t];
            if (++$commands > self::MAX_COMMANDS) {
                return 'more than '.self::MAX_COMMANDS.' commands';
            }
        }
        if ($open) {
            return 'the last subpath is not closed with Z';
        }

        return $subpaths === 0 ? 'has no subpath' : null;
    }

    /** @param  array<int, array{0: float|int, 1: float|int}>  $pts */
    public static function polygonSelfIntersects(array $pts): bool
    {
        $n = count($pts);
        for ($i = 0; $i < $n; $i++) {
            $a = $pts[$i];
            $b = $pts[($i + 1) % $n];
            for ($j = $i + 1; $j < $n; $j++) {
                // Neighbouring edges share a vertex by construction; that is not a crossing.
                if ($j === $i || ($j + 1) % $n === $i || ($i + 1) % $n === $j) {
                    continue;
                }
                if (self::segmentsCross($a, $b, $pts[$j], $pts[($j + 1) % $n])) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function segmentsCross(array $p1, array $p2, array $p3, array $p4): bool
    {
        $d = fn ($a, $b, $c) => ($b[0] - $a[0]) * ($c[1] - $a[1]) - ($b[1] - $a[1]) * ($c[0] - $a[0]);
        $d1 = $d($p3, $p4, $p1);
        $d2 = $d($p3, $p4, $p2);
        $d3 = $d($p1, $p2, $p3);
        $d4 = $d($p1, $p2, $p4);

        return (($d1 > 0 && $d2 < 0) || ($d1 < 0 && $d2 > 0)) && (($d3 > 0 && $d4 < 0) || ($d3 < 0 && $d4 > 0));
    }
}
