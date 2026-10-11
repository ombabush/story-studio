<?php

namespace Ombabush\StoryStudio\Tests\Feature;

use Ombabush\StoryStudio\Tests\Host\User;
use Ombabush\StoryStudio\Models\Page;

/**
 * Who sees what. A closed edition, a draft, a hidden page, the author's internal layer and an old image
 * version must not reach a reader by any path — the reader page, a page chunk by guessed id, or a media
 * link. Every refusal is a 404, so nothing admits it exists.
 */
class ReaderAccessTest extends FeatureTestCase
{
    private function reader(): string
    {
        return config('story-studio.routes.reader.name');
    }

    public function test_an_unpublished_edition_is_404_for_a_guest_and_open_for_the_admin(): void
    {
        [$story] = $this->edition(published: false);

        $this->get(route($this->reader().'show', $story))->assertNotFound();
        $this->actingAs($this->admin())->get(route($this->reader().'show', $story))->assertOk()->assertSee('Тест');
    }

    public function test_a_guest_sees_only_ready_visible_pages_and_never_the_internal_layer(): void
    {
        [$story, , , $p] = $this->edition();
        $this->editor()->updatePage($p['a1'], ['note' => 'секретная заметка', 'dialogue' => 'ИМЯ: реплика', 'caption' => 'Видно всем']);
        $this->editor()->setStatus($story, [$p['a2']->public_id], Page::DRAFT);
        $this->editor()->setStatus($story, [$p['a3']->public_id], null, true);

        $html = $this->get(route($this->reader().'show', $story))->assertOk()->getContent();
        // The payload is JSON with \uXXXX escapes: compare in that form, or "not contains" proves nothing.
        $enc = fn (string $s) => trim(json_encode($s), '"');

        $this->assertStringContainsString($enc('Видно всем'), $html);
        $this->assertStringNotContainsString($enc('секретная заметка'), $html);
        $this->assertStringNotContainsString($enc('ИМЯ: реплика'), $html);
        $this->assertStringNotContainsString($p['a2']->public_id, $html);
        $this->assertStringNotContainsString($p['a3']->public_id, $html);
    }

    public function test_a_guessed_draft_id_in_a_chunk_returns_nothing_and_preview_needs_an_editor(): void
    {
        [$story, , , $p] = $this->edition();
        $this->editor()->setStatus($story, [$p['a2']->public_id], Page::DRAFT);
        $url = route($this->reader().'pages', ['story' => $story, 'ids' => $p['a1']->public_id.','.$p['a2']->public_id, 'preview' => 1]);

        $this->assertSame([$p['a1']->public_id], array_column($this->getJson($url)->json('pages'), 'id'));

        $stranger = $this->user();
        $this->assertSame([$p['a1']->public_id], array_column($this->actingAs($stranger)->getJson($url)->json('pages'), 'id'));

        $pages = $this->actingAs($this->admin())->getJson($url)->json('pages');
        $this->assertSame([$p['a1']->public_id, $p['a2']->public_id], array_column($pages, 'id'));
        $this->assertTrue($pages[1]['draft']);
        $this->assertArrayHasKey('internal', $pages[1]);
    }

    public function test_media_serves_the_current_image_and_hides_old_versions_and_drafts(): void
    {
        [$story, , , $p] = $this->edition();
        $old = $p['a1']->currentImage;
        $this->editor()->replaceImage($p['a1'], $this->png('v2.png', seed: 77));
        $current = $p['a1']->fresh()->currentImage;
        $media = fn ($image) => route($this->reader().'media', ['story' => $story, 'image' => $image->uuid, 'size' => 'original']);

        $this->get($media($current))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get($media($old))->assertNotFound();
        $this->actingAs($this->admin())->get($media($old))->assertOk();
        auth()->logout();

        $this->editor()->setStatus($story, [$p['a1']->public_id], Page::DRAFT);
        $this->get($media($current))->assertNotFound();
    }

    public function test_media_of_another_edition_is_404_under_this_edition(): void
    {
        [$story] = $this->edition();
        [, , , $other] = $this->edition();

        $this->get(route($this->reader().'media', ['story' => $story, 'image' => $other['a1']->currentImage->uuid]))->assertNotFound();
    }

    public function test_progress_is_kept_for_a_visible_page_only(): void
    {
        [$story, , , $p] = $this->edition();
        $this->editor()->setStatus($story, [$p['b1']->public_id], Page::DRAFT);
        $url = route($this->reader().'progress', $story);

        $this->post($url, ['page' => $p['a2']->public_id])->assertNoContent();
        $this->post($url, ['page' => $p['b1']->public_id])->assertNotFound();

        // The same guest reopens the edition where it stopped (the guest key lives in the session).
        $this->get(route($this->reader().'show', $story))->assertOk()
            ->assertSee('"start":"'.$p['a2']->public_id.'"', false);
    }
}
