<?php

namespace Ombabush\StoryStudio\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Ombabush\StoryStudio\Actions\StoryEditor;
use Ombabush\StoryStudio\Models\Node;
use Ombabush\StoryStudio\Models\Page;
use Ombabush\StoryStudio\Models\Story;
use Ombabush\StoryStudio\Support\UploadPlan;

/**
 * A folder or a ZIP/CBZ from disk → an edition, through the same plan the Studio previews: natural sort,
 * subfolders become chapters, guessed spreads and panoramas, duplicates already in the edition skipped.
 * Re-running on the same source adds nothing new — that is what makes it safe to repeat.
 */
class ImportCommand extends Command
{
    protected $signature = 'story-studio:import
        {path : A folder of images, or a .zip / .cbz}
        {--story= : public id of an existing edition to add to}
        {--title= : title of a new edition (default: the folder or file name)}
        {--authors= : authors of a new edition}
        {--rtl : a new edition reads right to left (manga)}
        {--owner= : user id that owns a new edition}
        {--ready : mark the pages ready instead of draft}
        {--dry-run : print the plan, write nothing}';

    protected $description = 'Import a folder or a CBZ/ZIP as a Story Studio edition (subfolders become chapters)';

    public function handle(StoryEditor $editor, UploadPlan $plans): int
    {
        $path = rtrim((string) $this->argument('path'), '/');
        $archive = is_file($path) && in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['zip', 'cbz'], true);
        if (! is_dir($path) && ! $archive) {
            $this->error("Not a folder or a .zip/.cbz: {$path}");

            return self::FAILURE;
        }

        $story = $this->resolveStory($editor, $path);
        if (! $story) {
            return self::FAILURE;
        }

        $plan = $archive ? $plans->fromArchive($story, $path) : $plans->fromDirectory($story, $path);
        try {
            $this->printPlan($plan);
            if ($this->option('dry-run')) {
                if (! $story->exists) {
                    $this->line('Dry run: the edition was not created.');
                }

                return self::SUCCESS;
            }
            if (! $story->exists) {
                $story->save();
            }

            $count = $this->commit($editor, $story, $plan);
        } finally {
            $plans->discard($plan);
        }

        $this->info("Imported {$count} page(s) into «{$story->title}» ({$story->public_id}).");

        return self::SUCCESS;
    }

    /** An existing edition by --story, or a new one (unsaved until the plan is accepted). */
    private function resolveStory(StoryEditor $editor, string $path): ?Story
    {
        if ($id = $this->option('story')) {
            $story = Story::where('public_id', $id)->first();
            if (! $story) {
                $this->error("No edition with public id {$id}.");
            }

            return $story;
        }

        return new Story([
            'title' => $this->option('title') ?: pathinfo($path, PATHINFO_FILENAME),
            'authors' => $this->option('authors'),
            'direction' => $this->option('rtl') ? 'rtl' : 'ltr',
            'owner_id' => $this->option('owner'),
        ]);
    }

    private function printPlan(array $plan): void
    {
        $rows = [];
        foreach ($plan['groups'] as $group) {
            $files = collect($group['files']);
            $rows[] = [$group['folder'], $group['chapter'], $files->where('skip', false)->count(), $files->where('skip', true)->count(),
                $files->where('type', '!=', 'image')->countBy('type')->map(fn ($n, $t) => "{$t}×{$n}")->implode(' ')];
        }
        $this->table(['Folder', 'Chapter', 'New', 'Duplicates', 'Guessed'], $rows);
    }

    private function commit(StoryEditor $editor, Story $story, array $plan): int
    {
        $status = $this->option('ready') ? Page::READY : Page::DRAFT;
        // One flat folder is the edition itself; folders are chapters only when there is a folder.
        $chaptered = count($plan['groups']) > 1 || ($plan['groups'][0]['folder'] ?? '—') !== '—';
        $count = 0;

        DB::transaction(function () use ($editor, $story, $plan, $status, $chaptered, &$count) {
            foreach ($plan['groups'] as $group) {
                $files = array_values(array_filter($group['files'], fn ($f) => ! $f['skip']));
                if (! $files) {
                    continue;
                }
                $node = $chaptered ? $this->chapter($editor, $story, $group['chapter']) : null;
                $items = array_map(fn ($f) => ['file' => $f['path'], 'name' => $f['name'], 'type' => $f['type']], $files);
                $editor->addImagePages($story, $node, $items, null, $status);
                $count += count($items);
            }
        });

        return $count;
    }

    /** A chapter of that name is reused, so a second import of a grown folder appends, not duplicates. */
    private function chapter(StoryEditor $editor, Story $story, string $title): Node
    {
        return Node::where('story_id', $story->id)->where('kind', Node::CHAPTER)->where('title', $title)->first()
            ?? $editor->addNode($story, Node::CHAPTER, $title);
    }
}
