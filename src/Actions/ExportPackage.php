<?php

namespace Ombabush\StoryStudio\Actions;

use Ombabush\StoryStudio\Interaction\ProfileCompiler;
use Ombabush\StoryStudio\Models\Story;
use RuntimeException;
use ZipArchive;

/**
 * An edition as a `.story.zip` (FORMAT.md): story.json + interaction.json + assets/. Plain files, no
 * reader runtime, no draft: notes, balloon dialogue, drafts, hidden pages and old picture versions
 * never enter it, because story.json is the public Snapshot and assets are only current pictures.
 * An edition without interactions exports story.json and its pictures — a normal book.
 */
class ExportPackage
{
    public function __construct(private ProfileCompiler $compiler) {}

    /** @return array{path: ?string, errors: array<int, string>} the written zip, or why it was refused */
    public function export(Story $story, string $target): array
    {
        $compiled = $this->compiler->compile($story);
        if ($compiled['errors']) {
            return ['path' => null, 'errors' => $compiled['errors']];
        }
        $files = $compiled['files'];
        if (! $compiled['profile']) {
            // No interactions: still carry the pictures so the package is a whole book.
            foreach ($story->pages()->with('currentImage')->get() as $page) {
                $image = $page->currentImage;
                if ($image && $page->isPublic() && is_file($image->getPath())) {
                    $files['assets/'.$page->public_id.'.'.strtolower(pathinfo($image->file_name, PATHINFO_EXTENSION))] = $image->getPath();
                }
            }
        }

        @unlink($target);
        $zip = new ZipArchive;
        if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Cannot write $target.");
        }
        $zip->addFromString('story.json', $compiled['story_json']);
        if ($compiled['profile']) {
            $zip->addFromString('interaction.json', json_encode($compiled['profile'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
        }
        foreach ($files as $path => $file) {
            $zip->addFile($file, $path);
            $zip->setCompressionName($path, ZipArchive::CM_STORE);   // pictures are already compressed
        }
        $zip->close();

        return ['path' => $target, 'errors' => []];
    }
}
