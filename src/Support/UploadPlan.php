<?php

namespace Ombabush\StoryStudio\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Ombabush\StoryStudio\Models\Page;
use Ombabush\StoryStudio\Models\Story;
use ZipArchive;

/**
 * The preview before anything is written: what tree the upload becomes (subfolders → chapters, natural
 * sort), the guessed type of each page, and which files are already in the edition. ZIP/CBZ archives
 * are opened here and their folders become chapters the same way.
 */
class UploadPlan
{
    public const IMAGE_EXT = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif'];

    /**
     * @param  array<int, UploadedFile>  $uploads
     * @param  array<int, string>  $paths  relative paths from a folder drop, same order as $uploads
     */
    public function make(Story $story, array $uploads, array $paths = []): array
    {
        $entries = [];
        $tmp = null;
        foreach (array_values($uploads) as $i => $file) {
            $rel = $this->cleanPath($paths[$i] ?? $file->getClientOriginalName());
            $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
            if (in_array($ext, ['zip', 'cbz'], true)) {
                $tmp ??= $this->tmpDir();
                $prefix = pathinfo($rel, PATHINFO_FILENAME);
                foreach ($this->unzip($file->getRealPath(), $tmp.'/'.$i) as $inner => $real) {
                    $entries[] = ['rel' => $prefix.'/'.$inner, 'real' => $real, 'preview' => null];
                }
            } elseif (in_array($ext, self::IMAGE_EXT, true)) {
                $entries[] = ['rel' => $rel, 'real' => $file->getRealPath(), 'preview' => $this->previewUrl($file)];
            }
        }

        return $this->plan($story, $entries, $tmp);
    }

    /** Same plan from a directory on disk (the import command). */
    public function fromDirectory(Story $story, string $dir): array
    {
        $entries = [];
        foreach (File::allFiles($dir) as $f) {
            if (in_array(strtolower($f->getExtension()), self::IMAGE_EXT, true)) {
                $entries[] = ['rel' => $this->cleanPath($f->getRelativePathname()), 'real' => $f->getRealPath(), 'preview' => null];
            }
        }

        return $this->plan($story, $entries, null);
    }

    /** Same plan from a ZIP/CBZ on disk (the import command); its folders become chapters. */
    public function fromArchive(Story $story, string $archive): array
    {
        $tmp = $this->tmpDir();
        $entries = [];
        foreach ($this->unzip($archive, $tmp) as $inner => $real) {
            $entries[] = ['rel' => $inner, 'real' => $real, 'preview' => null];
        }

        return $this->plan($story, $entries, $tmp);
    }

    public function discard(?array $plan): void
    {
        if (! empty($plan['tmp']) && str_starts_with($plan['tmp'], $this->tmpRoot())) {
            File::deleteDirectory($plan['tmp']);
        }
    }

    private function plan(Story $story, array $entries, ?string $tmp): array
    {
        // sha1 of each page's current picture → that page: a file already in the edition starts skipped.
        $known = [];
        foreach (Page::where('story_id', $story->id)->whereNotNull('current_media_id')->with('currentImage')->get() as $page) {
            if ($page->currentImage && ($sha1 = Version::sha1($page->currentImage))) {
                $known[$sha1] = $page->public_id;
            }
        }

        $groups = [];
        foreach (NaturalSort::by($entries, fn ($e) => $e['rel']) as $e) {
            $folder = trim(str_contains($e['rel'], '/') ? dirname($e['rel']) : '', './');
            $size = @getimagesize($e['real']);
            $sha1 = sha1_file($e['real']);
            $groups[$folder] ??= ['folder' => $folder ?: '—', 'chapter' => $this->chapterName($folder), 'files' => []];
            $groups[$folder]['files'][] = [
                'name' => basename($e['rel']),
                'path' => $e['real'],
                'preview' => $e['preview'],
                'width' => $size[0] ?? null,
                'height' => $size[1] ?? null,
                'type' => TypeGuess::fromSize($size[0] ?? null, $size[1] ?? null),
                'dup' => $known[$sha1] ?? null,
                // Duplicates start skipped: re-uploading a folder must not double the edition.
                'skip' => isset($known[$sha1]),
            ];
        }

        return ['groups' => array_values($groups), 'tmp' => $tmp, 'count' => count($entries)];
    }

    private function chapterName(string $folder): string
    {
        if ($folder === '') {
            return 'Новая глава';
        }
        $name = basename($folder);
        // "01 - Arrival", "ch_02_storm" → keep the words, drop the numbering noise.
        $clean = trim(preg_replace('/^(ch(apter)?|гл(ава)?)?[\s._-]*\d+[\s._-]*/iu', '', str_replace('_', ' ', $name)));

        return $clean !== '' ? Str::ucfirst($clean) : $name;
    }

    private function cleanPath(string $path): string
    {
        $parts = array_filter(explode('/', str_replace('\\', '/', $path)), fn ($p) => $p !== '' && $p !== '.' && $p !== '..' && ! str_starts_with($p, '.') && $p !== '__MACOSX');

        return implode('/', $parts);
    }

    /** @return array<string, string> inner path => extracted real path (images only, no traversal) */
    private function unzip(string $archive, string $into): array
    {
        $out = [];
        $zip = new ZipArchive;
        if ($zip->open($archive) !== true) {
            return $out;
        }
        File::ensureDirectoryExists($into);
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $this->cleanPath((string) $zip->getNameIndex($i));
            if ($name === '' || ! in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), self::IMAGE_EXT, true)) {
                continue;
            }
            $target = $into.'/'.$i.'.'.strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $stream = $zip->getStream((string) $zip->getNameIndex($i));
            if ($stream) {
                file_put_contents($target, $stream);
                fclose($stream);
                $out[$name] = $target;
            }
        }
        $zip->close();

        return $out;
    }

    private function previewUrl(UploadedFile $file): ?string
    {
        try {
            return method_exists($file, 'temporaryUrl') ? $file->temporaryUrl() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function tmpRoot(): string
    {
        return storage_path('app/story-studio-tmp');
    }

    private function tmpDir(): string
    {
        $dir = $this->tmpRoot().'/'.Str::random(16);
        File::ensureDirectoryExists($dir);

        return $dir;
    }
}
