<?php

namespace Ombabush\StoryStudio\Tests\Feature;

use Ombabush\StoryStudio\Models\Node;
use Ombabush\StoryStudio\Models\Page;
use Ombabush\StoryStudio\Models\Progress;
use Ombabush\StoryStudio\Support\Version;

/**
 * Order is a position, not a date or a file name: insert between two pages, move to another chapter,
 * undo exactly, split and merge chapters — and a page keeps its identity through all of it.
 */
class StoryEditorTest extends FeatureTestCase
{
    public function test_pages_move_before_a_page_in_another_chapter_and_undo_restores_exactly(): void
    {
        [$story, $a, $b, $p] = $this->edition();

        $snapshot = $this->editor()->movePages($story, [$p['a3']->public_id, $p['a1']->public_id], $b, $p['b2']->public_id);

        // The moved pages keep their reading order among themselves, not the order they were picked in.
        $this->assertSame(['a2', 'b1', 'a1', 'a3', 'b2'], $this->order($story));
        // Positions are dense in both chapters after the move.
        $this->assertSame([1], Page::where('node_id', $a->id)->orderBy('position')->pluck('position')->all());
        $this->assertSame([1, 2, 3, 4], Page::where('node_id', $b->id)->orderBy('position')->pluck('position')->all());

        $this->editor()->restore($story, $snapshot);
        $this->assertSame(['a1', 'a2', 'a3', 'b1', 'b2'], $this->order($story));
    }

    public function test_status_change_is_undone_to_each_page_own_previous_state(): void
    {
        [$story, , , $p] = $this->edition();
        $this->editor()->setStatus($story, [$p['a2']->public_id], Page::DRAFT, true);

        $snapshot = $this->editor()->setStatus($story, [$p['a1']->public_id, $p['a2']->public_id], Page::READY, false);
        $this->editor()->restore($story, $snapshot);

        $this->assertSame([Page::READY, false], [$p['a1']->fresh()->status, $p['a1']->fresh()->hidden]);
        $this->assertSame([Page::DRAFT, true], [$p['a2']->fresh()->status, $p['a2']->fresh()->hidden]);
    }

    public function test_split_and_merge_keep_the_reading_order(): void
    {
        [$story, $a, , $p] = $this->edition();

        $new = $this->editor()->splitBefore($p['a2']->fresh());
        $this->assertSame(['a1', 'a2', 'a3', 'b1', 'b2'], $this->order($story));
        $this->assertSame(['A', 'A (2)', 'B'], Node::where('story_id', $story->id)->defaultOrder()->pluck('title')->all());
        $this->assertSame($new->id, $p['a3']->fresh()->node_id);

        $this->editor()->mergeWithNext($a->fresh());
        $this->assertSame(['a1', 'a2', 'a3', 'b1', 'b2'], $this->order($story));
        $this->assertSame(['A', 'B'], Node::where('story_id', $story->id)->defaultOrder()->pluck('title')->all());
    }

    public function test_replacing_an_image_keeps_the_page_and_its_texts_and_keeps_the_old_version(): void
    {
        [$story, , , $p] = $this->edition();
        $page = $this->editor()->updatePage($p['a1'], ['caption' => 'Подпись', 'note' => 'Заметка']);
        $old = $page->current_media_id;

        $this->editor()->replaceImage($page, $this->png('new.png', seed: 99));
        $page = $page->fresh();

        $this->assertSame(['Подпись', 'Заметка'], [$page->caption, $page->note]);
        $this->assertNotEquals($old, $page->current_media_id);
        // Both versions are Spatie media of the page; the old one is still there, files and all.
        $this->assertSame([2, 1], $page->versions()->map(fn ($m) => Version::number($m))->all());
        $this->assertFileExists($page->versions()->last()->getPath());
        $this->assertTrue($page->currentImage->hasGeneratedConversion('thumb'));

        $this->editor()->useVersion($page, $page->versions()->firstWhere('id', $old));
        $this->assertEquals($old, $page->fresh()->current_media_id);
    }

    public function test_type_is_guessed_from_the_picture_shape(): void
    {
        [$story, $a] = $this->edition();
        $made = $this->editor()->addImagePages($story, $a, [
            ['file' => $this->png('tall.png', 60, 90, 1)],
            ['file' => $this->png('wide.png', 140, 100, 2)],
            ['file' => $this->png('pano.png', 300, 100, 3)],
        ]);

        $this->assertSame(['image', 'spread', 'panorama'], $made->pluck('type')->all());
    }

    public function test_a_page_cut_short_is_kept_as_broken_not_dropped(): void
    {
        [$story, $a] = $this->edition();
        $path = $this->png('cut.png', seed: 5);
        file_put_contents($path, substr(file_get_contents($path), 0, -20));

        $page = $this->editor()->addImagePages($story, $a, [['file' => $path]])->first();

        $this->assertTrue(Version::broken($page->currentImage));
        $this->assertFalse($page->currentImage->hasGeneratedConversion('display'));
    }

    public function test_reading_progress_points_at_the_page_and_survives_a_reorder(): void
    {
        [$story, , $b, $p] = $this->edition();
        $progress = app(\Ombabush\StoryStudio\Actions\ReadingProgress::class);
        $progress->save($story, 'g:x', $p['a2']);

        $this->editor()->movePages($story, [$p['a2']->public_id], $b);

        $this->assertSame($p['a2']->public_id, $progress->last($story, 'g:x')->public_id);
        $this->assertSame(1, Progress::count());
    }

    public function test_a_node_of_another_story_is_refused(): void
    {
        [$story] = $this->edition();
        [, $foreign] = $this->edition();

        $this->expectException(\InvalidArgumentException::class);
        $this->editor()->addTextPage($story, $foreign);
    }
}
