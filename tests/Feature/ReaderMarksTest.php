<?php

namespace Ombabush\StoryStudio\Tests\Feature;

use Ombabush\StoryStudio\Tests\Host\User;
use Ombabush\StoryStudio\Models\Mark;
use Ombabush\StoryStudio\Models\Page;
use Ombabush\StoryStudio\Support\Snapshot;

/**
 * A reader's bookmarks and notes (Story Reader v2): private reader state — another reader never sees
 * them, they never enter a snapshot, and a page the reader cannot open cannot be marked.
 */
class ReaderMarksTest extends FeatureTestCase
{
    private function marksUrl($story): string
    {
        return route(config('story-studio.routes.reader.name').'marks', $story);
    }

    private function payload($story): array
    {
        $html = $this->get(route(config('story-studio.routes.reader.name').'show', $story))->assertOk()->getContent();
        preg_match('~<script type="application/json" id="[^"]+-data">(.+?)</script>~s', $html, $m);

        return json_decode(html_entity_decode($m[1]), true);
    }

    public function test_a_reader_sets_and_clears_a_bookmark_and_a_note(): void
    {
        [$story, , , $p] = $this->edition();
        $reader = $this->user();
        $this->actingAs($reader);

        $this->postJson($this->marksUrl($story), ['page' => $p['a2']->public_id, 'kind' => 'bookmark', 'on' => true])->assertOk();
        $marks = $this->postJson($this->marksUrl($story), ['page' => $p['a2']->public_id, 'kind' => 'note', 'text' => 'Вернуться сюда'])
            ->assertOk()->json('marks');
        $this->assertEqualsCanonicalizing(['bookmark', 'note'], array_column($marks, 'kind'));
        $this->assertSame(['Вернуться сюда'], array_values(array_filter(array_column($marks, 'text'))));
        $this->assertCount(2, $this->payload($story)['marks']);

        // Writing the same mark again replaces it; an empty note and on:false remove.
        $this->postJson($this->marksUrl($story), ['page' => $p['a2']->public_id, 'kind' => 'note', 'text' => 'Иначе'])->assertOk();
        $this->assertSame(2, Mark::count());
        $this->postJson($this->marksUrl($story), ['page' => $p['a2']->public_id, 'kind' => 'note', 'text' => '  '])->assertOk();
        $this->postJson($this->marksUrl($story), ['page' => $p['a2']->public_id, 'kind' => 'bookmark', 'on' => false])->assertOk();
        $this->assertSame(0, Mark::count());
    }

    public function test_marks_are_private_to_their_reader_and_never_in_a_snapshot(): void
    {
        [$story, , , $p] = $this->edition();
        $this->actingAs($this->user())
            ->postJson($this->marksUrl($story), ['page' => $p['a1']->public_id, 'kind' => 'note', 'text' => 'моя тайная мысль'])->assertOk();

        $this->actingAs($this->user());
        $this->assertSame([], $this->payload($story)['marks']);

        auth()->logout();
        $this->assertSame([], $this->payload($story)['marks']);   // a guest has their own key

        $snapshot = json_encode(app(Snapshot::class)->export($story->fresh()), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('моя тайная мысль', $snapshot);
    }

    public function test_a_page_the_reader_cannot_open_cannot_be_marked_and_hidden_marks_are_not_sent(): void
    {
        [$story, , , $p] = $this->edition();
        $reader = $this->user();
        $this->actingAs($reader)->postJson($this->marksUrl($story), ['page' => $p['b1']->public_id, 'kind' => 'bookmark', 'on' => true])->assertOk();

        $this->editor()->setStatus($story, [$p['b1']->public_id], Page::DRAFT);
        $this->assertSame([], $this->payload($story)['marks']);
        $this->postJson($this->marksUrl($story), ['page' => $p['b1']->public_id, 'kind' => 'bookmark', 'on' => true])->assertNotFound();
        $this->postJson($this->marksUrl($story), ['page' => $p['a1']->public_id, 'kind' => 'highlight'])->assertStatus(422);
    }
}
