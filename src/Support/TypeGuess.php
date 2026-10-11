<?php

namespace Ombabush\StoryStudio\Support;

/** Wider than tall — a spread; more than twice as wide — a panorama. Changed with one click later. */
class TypeGuess
{
    public static function fromSize(?int $width, ?int $height): string
    {
        if (! $width || ! $height) {
            return 'image';
        }
        $ratio = $width / $height;

        return $ratio > 2.0 ? 'panorama' : ($ratio > 1.0 ? 'spread' : 'image');
    }
}
