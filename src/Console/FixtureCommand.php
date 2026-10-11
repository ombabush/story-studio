<?php

namespace Ombabush\StoryStudio\Console;

use Illuminate\Console\Command;
use Ombabush\StoryStudio\Actions\ExportPackage;
use Ombabush\StoryStudio\Actions\ImportPackage;
use Ombabush\StoryStudio\Actions\StoryEditor;
use Ombabush\StoryStudio\Models\Story;

/**
 * `.story.zip` from the command line, through the same ImportPackage / ExportPackage the Studio uses:
 *
 *   story-studio:fixture <dir|zip>         import (a folder with story.json is the bundled fixture's form)
 *   story-studio:fixture <story> --export=<file.story.zip>
 *
 * Import never publishes unless asked, and re-importing the same package returns the first edition.
 */
class FixtureCommand extends Command
{
    protected $signature = 'story-studio:fixture {source : a .story.zip, a folder with story.json, or (with --export) a story public id}
        {--export= : write the edition to this .story.zip instead of importing}
        {--owner= : user id that owns an imported edition}
        {--publish : publish an imported edition}';

    protected $description = 'Import a .story.zip / fixture folder as an edition, or export an edition to .story.zip';

    public function handle(ImportPackage $importer, ExportPackage $exporter, StoryEditor $editor): int
    {
        $source = rtrim((string) $this->argument('source'), '/');
        if ($target = $this->option('export')) {
            $story = Story::where('public_id', $source)->first();
            if (! $story) {
                $this->error("No edition $source.");

                return self::FAILURE;
            }
            $result = $exporter->export($story, $target);
            foreach ($result['errors'] as $e) {
                $this->error($e);
            }
            if ($result['path']) {
                $this->info("Exported «{$story->title}» → {$result['path']}");
            }

            return $result['path'] ? self::SUCCESS : self::FAILURE;
        }

        $plan = $importer->inspect($source);
        try {
            foreach ($plan['errors'] as $e) {
                $this->error($e);
            }
            if (! $plan['ok']) {
                return self::FAILURE;
            }
            $owner = $this->option('owner') ? config('story-studio.user_model')::find($this->option('owner')) : null;
            $result = $importer->apply($plan, $owner);
            if ($this->option('publish') && $result['created']) {
                $editor->publish($result['story']);
            }
        } finally {
            $importer->discard($plan);
        }

        $title = $result['story']->title;
        $this->info($result['created'] ? "Created «{$title}» → {$result['story']->public_id}" : "Already imported: «{$title}» → {$result['story']->public_id}");
        foreach ($result['map'] as $from => $to) {
            $this->line("  $from → $to");
        }

        return self::SUCCESS;
    }
}
