<?php

namespace Ombabush\StoryStudio\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Ombabush\StoryStudio\Models\Page;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Adds a picture version to a page through Spatie MediaLibrary. The source file is never moved: an import
 * reads from the owner's own folder, and a library that "adds" by moving would empty it.
 */
class ImageStore
{
    /** @param  UploadedFile|string  $file  an upload or a local path */
    public function addVersion(Page $page, UploadedFile|string $file, ?string $originalName = null): Media
    {
        $source = $file instanceof UploadedFile ? $file->getRealPath() : $file;
        $originalName ??= $file instanceof UploadedFile ? $file->getClientOriginalName() : basename($file);
        $size = @getimagesize($source);
        $version = (int) $page->getMedia(Page::VERSIONS)->max(fn (Media $m) => Version::number($m)) + 1;
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION) ?: 'bin');

        $media = $page->addMedia($source)
            ->preservingOriginal()
            ->usingName(Str::limit($originalName, 250, ''))
            ->usingFileName('v'.$version.'-'.Str::slug(pathinfo($originalName, PATHINFO_FILENAME) ?: 'page').'.'.$ext)
            ->withCustomProperties([
                'version' => $version,
                'width' => $size[0] ?? null,
                'height' => $size[1] ?? null,
                'sha1' => sha1_file($source),
                'broken' => $size === false || ! $this->decodes($source, $size),
            ])
            ->toMediaCollection(Page::VERSIONS);

        $page->forceFill(['current_media_id' => $media->id])->save();
        $page->unsetRelation('currentImage');

        return $media;
    }

    /** A file that declares its size but is cut short (an interrupted download) is broken, not a page. */
    private function decodes(string $source, array $size): bool
    {
        $tail = @file_get_contents($source, false, null, max(0, filesize($source) - 16));
        if ($tail === false) {
            return false;
        }
        // PNG ends with an IEND chunk and JPEG with an EOI marker: a missing tail is the common failure.
        return match ($size['mime'] ?? '') {
            'image/png' => str_contains($tail, 'IEND'),
            'image/jpeg' => str_contains(substr($tail, -4), "\xFF\xD9"),
            default => true,
        };
    }
}
