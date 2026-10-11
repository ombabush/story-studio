<?php

namespace Ombabush\StoryStudio\Tests\Conformance;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Ombabush\StoryStudio\Models\Story;
use Ombabush\StoryStudio\Support\Snapshot;
use Ombabush\StoryStudio\Tests\TestCase;

/**
 * The package's promise to any host (PACKAGE-EXTRACTION PK-01): it installs, migrates, reads, guards
 * and edits with nothing but its declared dependencies. OmBabush-specific behaviour is tested in the
 * apps; this suite must never need them.
 */
class BareHostTest extends TestCase
{
    public function test_it_boots_without_any_host_application_code(): void
    {
        $this->assertFalse(class_exists('App\\Models\\User'), 'the bare host has no App namespace');
        foreach (['stories', 'nodes', 'pages', 'progress', 'marks', 'texts', 'attachments', 'reader_prefs'] as $t) {
            $this->assertTrue(Schema::hasTable('ss_'.$t), "ss_$t");
        }
        foreach (['story-studio.asset', 'story-studio.reader.show', 'story-studio.reader.media', 'story-studio.studio.index', 'story-studio.studio.edit'] as $name) {
            $this->assertTrue(Route::has($name), $name);
        }
    }

    public function test_assets_are_served_by_the_package_without_a_build_step(): void
    {
        $this->get(route('story-studio.asset', 'story-studio.js'))->assertOk();
        $this->get(route('story-studio.asset', 'story-studio.css'))->assertOk();
    }

    public function test_a_published_edition_reads_for_a_guest_and_its_picture_comes_through_the_guarded_route(): void
    {
        [$story, , , $pages] = $this->edition();

        $this->get($this->readerUrl('show', $story))->assertOk()->assertSee('Тест');

        $image = $pages['a1']->currentImage;
        $this->get($this->readerUrl('media', ['story' => $story, 'image' => $image->uuid, 'size' => 'original']))->assertOk();
    }

    public function test_a_closed_edition_and_its_pictures_are_404_for_everyone_but_the_owner(): void
    {
        $owner = $this->user('owner');
        [$story, , , $pages] = $this->edition(false, $owner);
        $media = $this->readerUrl('media', ['story' => $story, 'image' => $pages['a1']->currentImage->uuid, 'size' => 'original']);

        $this->get($this->readerUrl('show', $story))->assertNotFound();
        $this->get($media)->assertNotFound();
        $this->actingAs($this->user('stranger'))->get($this->readerUrl('show', $story))->assertNotFound();
        $this->actingAs($owner)->get($this->readerUrl('show', $story))->assertOk();
    }

    public function test_the_studio_needs_a_login_and_the_default_authorizer_gives_only_your_own_editions(): void
    {
        $owner = $this->user('owner');
        [$story] = $this->edition(owner: $owner);

        $this->getJson(route('story-studio.studio.index'))->assertUnauthorized();
        $this->actingAs($owner)->get(route('story-studio.studio.index'))->assertOk()->assertSee('Тест');
        $this->actingAs($owner)->get(route('story-studio.studio.edit', $story))->assertOk();
        // No Gate ability defined in this host: a stranger is not a super-editor, ever.
        $this->actingAs($this->user('stranger'))->get(route('story-studio.studio.edit', $story))->assertNotFound();
    }

    public function test_two_embedded_readers_get_separate_roots_and_the_assets_once(): void
    {
        [$a] = $this->edition();
        [$b] = $this->edition();

        $html = $this->get('/host/two-readers/'.$a->public_id.'/'.$b->public_id)->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'story-studio.js'));
        preg_match_all('/id="(ssr-[0-9a-f]{8})-data"/', $html, $ids);
        $this->assertCount(2, array_unique($ids[1]), 'each reader has its own root');
    }

    public function test_the_bundled_fixture_imports_and_reads_with_interactions_off_by_default(): void
    {
        $this->artisan('story-studio:fixture', ['source' => $this->fixturePath(), '--publish' => true])->assertSuccessful();
        $story = Story::firstOrFail();

        $this->assertFalse($this->payload($story)['interaction'], 'interaction stays behind its flag');

        config(['story-studio.reader.interaction' => true]);
        $payload = $this->payload($story);
        $this->assertTrue($payload['interaction']);
        $this->assertNotEmpty(array_filter(array_column($payload['pages'], 'interaction')));
    }

    public function test_the_public_snapshot_carries_no_internal_layer(): void
    {
        [$story, , , $pages] = $this->edition();
        $this->editor()->updatePage($pages['a1'], ['note' => 'private note', 'dialogue' => 'NAME: line', 'caption' => 'Public']);

        $wire = json_encode(app(Snapshot::class)->export($story->fresh()));

        $this->assertStringContainsString('Public', $wire);
        $this->assertStringNotContainsString('private note', $wire);
        $this->assertStringNotContainsString('NAME: line', $wire);
    }

    public function test_a_host_that_names_no_language_writes_and_speaks_its_app_locale(): void
    {
        [$story] = $this->edition();

        $this->assertNull(config('story-studio.reader.original'));
        $this->assertSame('en', $this->payload($story)['edition']['original']);
        $this->assertSame('en', $this->payload($story)['catalog']['uiLang']);
    }

    public function test_a_host_can_keep_readers_off_third_party_fonts(): void
    {
        [$story] = $this->edition();

        $this->assertStringContainsString('fonts.googleapis.com', $this->get($this->readerUrl('show', $story))->getContent());

        config(['story-studio.fonts' => false]);
        $this->assertStringNotContainsString('fonts.googleapis.com', $this->get($this->readerUrl('show', $story))->getContent());
    }

    /** The Reader's JSON config as the page ships it. */
    private function payload(Story $story): array
    {
        $html = $this->get($this->readerUrl('show', $story))->assertOk()->getContent();
        preg_match('/<script type="application\/json" id="ssr-[0-9a-f]{8}-data">(.*?)<\/script>/s', $html, $m);

        return json_decode($m[1], true);
    }
}
