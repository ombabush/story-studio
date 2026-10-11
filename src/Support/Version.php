<?php

namespace Ombabush\StoryStudio\Support;

use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * What Story Studio records about one picture version, in the media item's custom properties: its number,
 * its pixel size (the reader lays out pages before they load), the content hash (duplicates on upload)
 * and whether the file was readable at all. One reader for those keys, so nobody spells them twice.
 */
class Version
{
    public static function number(Media $media): int
    {
        return (int) $media->getCustomProperty('version', 0);
    }

    public static function width(Media $media): ?int
    {
        return $media->getCustomProperty('width');
    }

    public static function height(Media $media): ?int
    {
        return $media->getCustomProperty('height');
    }

    public static function sha1(Media $media): ?string
    {
        return $media->getCustomProperty('sha1');
    }

    public static function broken(Media $media): bool
    {
        return (bool) $media->getCustomProperty('broken', false);
    }
}
