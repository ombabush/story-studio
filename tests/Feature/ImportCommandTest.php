<?php

namespace Ombabush\StoryStudio\Tests\Feature;

use Ombabush\StoryStudio\Models\Node;
use Ombabush\StoryStudio\Models\Page;
use Ombabush\StoryStudio\Models\Story;
use ZipArchive;

/** Folder or CBZ → edition: natural sort, subfolders become chapters, a repeat import adds nothing. */
class ImportCommandTest extends FeatureTestCase
{
    private function folder(): string
    {
        $dir = $this->tmp.'/src';
        // Written out of order and with numbers that sort wrong as strings: 10 must come after 2.
        $this->png('01 - Arrival/page10.png', seed: 1, dir: $dir);
        $this->png('01 - Arrival/page2.png', seed: 2, dir: $dir);
        $this->png('01 - Arrival/page1.png', seed: 3, dir: $dir);
        $this->png('ch_02_storm/a.png', 300, 100, 4, $dir);
        $this->png('.hidden/x.png', seed: 5, dir: $dir);

        return $dir;
    }

    public function test_a_folder_becomes_chapters_in_natural_order_and_a_repeat_adds_nothing(): void
    {
        $dir = $this->folder();

        $this->artisan('story-studio:import', ['path' => $dir, '--title' => 'Импорт', '--ready' => true])->assertSuccessful();

        $story = Story::firstWhere('title', 'Импорт');
        $this->assertSame(['Arrival', 'Storm'], Node::where('story_id', $story->id)->defaultOrder()->pluck('title')->all());
        $this->assertSame(['page1', 'page2', 'page10', 'a'], $this->order($story));
        // Three times wider than tall: guessed a panorama at import.
        $this->assertSame('panorama', Page::where('story_id', $story->id)->orderByDesc('id')->first()->type);
        $this->assertSame(0, Page::where('story_id', $story->id)->where('status', Page::DRAFT)->count());

        $this->artisan('story-studio:import', ['path' => $dir, '--story' => $story->public_id])->assertSuccessful();
        $this->assertSame(4, Page::where('story_id', $story->id)->count());
        $this->assertSame(2, Node::where('story_id', $story->id)->count());
    }

    public function test_a_cbz_imports_like_a_folder(): void
    {
        $dir = $this->folder();
        $cbz = $this->tmp.'/book.cbz';
        $zip = new ZipArchive;
        $zip->open($cbz, ZipArchive::CREATE);
        foreach (['01 - Arrival/page1.png', '01 - Arrival/page2.png', '01 - Arrival/page10.png', 'ch_02_storm/a.png'] as $f) {
            $zip->addFile($dir.'/'.$f, $f);
        }
        $zip->close();

        $this->artisan('story-studio:import', ['path' => $cbz, '--rtl' => true])->assertSuccessful();

        $story = Story::firstWhere('title', 'book');
        $this->assertSame('rtl', $story->direction);
        $this->assertSame(['page1', 'page2', 'page10', 'a'], $this->order($story));
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->artisan('story-studio:import', ['path' => $this->folder(), '--dry-run' => true])->assertSuccessful();

        $this->assertSame(0, Story::count());
    }

    public function test_the_demo_has_every_page_type(): void
    {
        $this->artisan('story-studio:demo', ['--publish' => true])->assertSuccessful();

        $story = Story::sole();
        $this->assertTrue($story->isPublished());
        $this->assertEqualsCanonicalizing(Page::TYPES, Page::where('story_id', $story->id)->distinct()->pluck('type')->all());
    }
}
