<?php

namespace Ombabush\StoryStudio\Tests\Feature;

use Livewire\Livewire;
use Ombabush\StoryStudio\Livewire\Studio\Editor;
use Ombabush\StoryStudio\Livewire\Studio\Index;
use Ombabush\StoryStudio\Models\Page;
use Ombabush\StoryStudio\Models\Story;

/**
 * The Studio editor: moves with Undo and no question, and an undo token that the browser can ask for
 * but not forge. Who may open it at all is the host's wiring (Local: tests/Feature/StoryStudio).
 */
class StudioTest extends FeatureTestCase
{
    private function studio(): string
    {
        return config('story-studio.routes.studio.name');
    }

    public function test_a_new_edition_is_created_owned_by_its_creator(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin)->test(Index::class)
            ->set('title', 'Мой комикс')->set('direction', 'rtl')->call('create')
            ->assertRedirect();

        $story = Story::firstWhere('title', 'Мой комикс');
        $this->assertSame(['rtl', $admin->id], [$story->direction, (int) $story->owner_id]);
    }

    public function test_move_offers_undo_and_undo_restores_the_order(): void
    {
        [$story, , $b, $p] = $this->edition();

        $c = Livewire::actingAs($this->admin())->test(Editor::class, ['story' => $story])
            ->call('movePages', [$p['a1']->public_id], $b->public_id);
        $this->assertSame(['a2', 'a3', 'b1', 'b2', 'a1'], $this->order($story));
        $token = $c->get('toast')['undo'];
        $this->assertNotNull($token);

        $c->call('undo', $token);
        $this->assertSame(['a1', 'a2', 'a3', 'b1', 'b2'], $this->order($story));

        // A token is spent once; a second undo changes nothing.
        $c->call('undo', $token)->assertSet('toast.text', 'Отменить уже нельзя');
    }

    public function test_an_undo_token_of_another_edition_is_refused(): void
    {
        [$one, , $b1, $p1] = $this->edition();
        [$two] = $this->edition();
        $admin = $this->admin();

        $token = Livewire::actingAs($admin)->test(Editor::class, ['story' => $one])
            ->call('movePages', [$p1['a1']->public_id], $b1->public_id)->get('toast')['undo'];

        Livewire::actingAs($admin)->test(Editor::class, ['story' => $two])
            ->call('undo', $token)->assertSet('toast.text', 'Отменить уже нельзя');
        $this->assertSame(['a2', 'a3', 'b1', 'b2', 'a1'], $this->order($one));
    }

    public function test_inspector_fields_save_themselves_and_only_writable_ones(): void
    {
        [$story, , , $p] = $this->edition();

        Livewire::actingAs($this->admin())->test(Editor::class, ['story' => $story])
            ->call('selectPage', $p['a2']->public_id)
            ->set('form.caption', 'Новая подпись')
            ->set('form.status', Page::DRAFT)
            ->set('form.story_id', 999);

        $page = $p['a2']->fresh();
        $this->assertSame(['Новая подпись', Page::DRAFT, $story->id], [$page->caption, $page->status, $page->story_id]);
    }

    public function test_the_story_id_cannot_be_swapped_from_the_browser(): void
    {
        [$story] = $this->edition();
        [$other] = $this->edition();

        $this->expectException(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);
        Livewire::actingAs($this->admin())->test(Editor::class, ['story' => $story])->set('storyId', $other->public_id);
    }
}
