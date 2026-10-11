<?php

namespace Ombabush\StoryStudio\Tests\Feature;

use Ombabush\StoryStudio\Tests\Host\User;
use Livewire\Livewire;
use Ombabush\StoryStudio\Actions\ExportPackage;
use Ombabush\StoryStudio\Actions\ImportPackage;
use Ombabush\StoryStudio\Livewire\Studio\AttachmentList;
use Ombabush\StoryStudio\Livewire\Studio\Editor;
use Ombabush\StoryStudio\Livewire\Studio\Translations;
use Ombabush\StoryStudio\Models\Attachment;
use Ombabush\StoryStudio\Models\Page;
use Ombabush\StoryStudio\Models\ReaderPref;
use Ombabush\StoryStudio\Models\Story;
use Ombabush\StoryStudio\Models\Text;
use Ombabush\StoryStudio\Support\Attachments;
use Ombabush\StoryStudio\Support\Edition;
use Ombabush\StoryStudio\Support\Snapshot;
use Ombabush\StoryStudio\Support\Texts;

/**
 * The universal Story Reader data model (docs/DATA-MODEL.md): typed edition settings,
 * translations, attachments, layout hints, the reader's own settings and the catalog. What a reader
 * may see, what travels in a snapshot and what stays private is the point of every test here.
 */
class DataModelTest extends FeatureTestCase
{
    private function payload(Story $story, array $query = []): array
    {
        $html = $this->get(route(config('story-studio.routes.reader.name').'show', ['story' => $story] + $query))->assertOk()->getContent();
        preg_match('~<script type="application/json" id="[^"]+-data">(.+?)</script>~s', $html, $m);

        return json_decode(html_entity_decode($m[1]), true);
    }

    private function bilingual(): array
    {
        [$story, $a, $b, $p] = $this->edition();
        $this->editor()->updateStory($story, ['languages' => ['original' => 'ru', 'offered' => ['ru', 'en']]]);
        $this->editor()->updatePage($p['a1'], ['caption' => 'Катер уходит.', 'body' => "Первый абзац.\n\nВторой.", 'dialogue' => 'КАПИТАН: До весны.']);

        return [$story->fresh(), $a, $b, $p];
    }

    public function test_edition_settings_are_typed_and_bad_values_never_reach_the_reader(): void
    {
        [$story] = $this->edition();
        $this->assertSame('ru', $story->edition()->original);   // host default (config reader.original)
        $this->assertNotContains('script', $story->edition()->views);

        $this->editor()->updateStory($story, [
            'languages' => ['original' => 'RU', 'offered' => ['en', 'pt_BR', 'not a tag', 'en']],
            'views' => ['offered' => ['book', 'script', 'nonsense', 'read'], 'default' => 'script'],
        ]);
        $e = Edition::of($story->fresh());
        $this->assertSame(['ru', 'en', 'pt-BR'], $e->languages);
        $this->assertSame(['read', 'book'], $e->views);          // catalog order; the editor-only view is not an edition choice
        $this->assertSame('read', $e->defaultView);

        // The legacy `modes` key still narrows the views.
        $this->assertSame(['read'], Edition::from(['modes' => ['read']])->views);

        $p = $this->payload($story->fresh());
        $this->assertSame(['ru', 'en', 'pt-BR'], $p['edition']['languages']);
        $this->assertSame(['read', 'book'], $p['edition']['views']);
        $this->assertArrayHasKey('script', $p['catalog']['views']);   // the catalog knows it; the reader offers it to editors only
        $this->assertTrue($p['catalog']['views']['script']['editor']);
    }

    public function test_translations_reach_the_reader_and_internal_fields_stay_with_the_editor(): void
    {
        [$story, $a, , $p] = $this->bilingual();
        $texts = app(Texts::class);
        $texts->set($story, 'page', $p['a1']->public_id, 'en', ['caption' => 'The launch leaves.', 'body' => "First.\n\nSecond.", 'dialogue' => 'CAPTAIN: Till spring.']);
        $texts->set($story, 'node', $a->public_id, 'en', ['title' => 'Arrival']);
        $texts->set($story, 'story', $story->public_id, 'en', ['title' => 'North Spit']);
        $texts->set($story, 'page', $p['a2']->public_id, 'de', ['caption' => 'Nicht angeboten']);   // a language the edition does not offer

        $this->expectsTranslationToBeRefusedInTheOriginal($story, $p['a1']);

        $guest = $this->payload($story);
        $a1 = collect($guest['pages'])->firstWhere('id', $p['a1']->public_id);
        $this->assertSame('The launch leaves.', $a1['tr']['en']['caption']);
        $this->assertSame(['First.', 'Second.'], $a1['tr']['en']['paragraphs']);
        $this->assertArrayNotHasKey('dialogue', $a1['tr']['en']);
        $this->assertStringNotContainsString('Till spring', json_encode($guest));
        $this->assertStringNotContainsString('Nicht angeboten', json_encode($guest));
        $this->assertSame('Arrival', collect($guest['chapters'])->firstWhere('id', $a->public_id)['tr']['en']);
        $this->assertSame('North Spit', $guest['story']['tr']['en']['title']);

        $this->actingAs($this->admin());
        $editor = collect($this->payload($story)['pages'])->firstWhere('id', $p['a1']->public_id);
        $this->assertSame(['CAPTAIN: Till spring.'], $editor['tr']['en']['dialogue']);

        // An emptied field is "not translated"; a row with nothing left is gone.
        $texts->set($story, 'page', $p['a1']->public_id, 'en', ['caption' => '', 'body' => '', 'dialogue' => '']);
        $this->assertSame(0, Text::where('subject_id', $p['a1']->public_id)->count());
    }

    private function expectsTranslationToBeRefusedInTheOriginal(Story $story, Page $page): void
    {
        try {
            app(Texts::class)->set($story, 'page', $page->public_id, 'ru', ['caption' => 'x']);
            $this->fail('A translation into the original language was accepted.');
        } catch (\InvalidArgumentException) {
            $this->assertTrue(true);
        }
    }

    public function test_music_is_scoped_https_only_and_follows_page_visibility(): void
    {
        [$story, $a, , $p] = $this->edition();
        $att = app(Attachments::class);
        $att->add($story, 'story', null, 'track', ['title' => 'Low Tide', 'artist' => 'Halvor', 'seconds' => 214]);
        $att->add($story, 'story', null, 'link', ['label' => 'Spotify', 'url' => 'https://open.spotify.com/x']);
        $att->add($story, 'node', $a->public_id, 'track', ['title' => 'Westerly']);
        $att->add($story, 'page', $p['b1']->public_id, 'track', ['title' => 'Wall of Water', 'url' => 'https://music.example/w']);

        foreach ([['link', ['label' => 'x', 'url' => 'http://plain.example']], ['link', ['label' => 'x', 'url' => 'javascript:alert(1)']],
            ['track', ['title' => '']], ['tape', ['title' => 'x']]] as [$kind, $data]) {
            try {
                $att->add($story, 'story', null, $kind, $data);
                $this->fail("Accepted a bad $kind.");
            } catch (\InvalidArgumentException) {
            }
        }
        $this->assertSame(4, Attachment::count());

        $music = $this->payload($story)['music'];
        $this->assertSame(['Low Tide'], array_column($music['story'], 'title'));
        $this->assertSame('Spotify', $music['links'][0]['label']);
        $this->assertSame('Westerly', $music['node'][$a->public_id][0]['title']);
        $this->assertSame('Wall of Water', $music['page'][$p['b1']->public_id][0]['title']);

        // A page the reader cannot open takes its music with it (SR-09: nothing about it leaks).
        $this->editor()->setStatus($story, [$p['b1']->public_id], Page::DRAFT);
        $this->assertStringNotContainsString('Wall of Water', json_encode($this->payload($story->fresh())));
    }

    public function test_reader_settings_are_whitelisted_private_and_merged(): void
    {
        [$story] = $this->edition();
        $url = route(config('story-studio.routes.reader.name').'prefs', $story);
        $this->actingAs($reader = $this->user());

        $this->postJson($url, ['scope' => 'global', 'prefs' => ['theme' => 'paper', 'textSize' => 400, 'ui' => 'rubbish',
            'lang' => ['primary' => 'en', 'fallback' => '<b>'], 'ix' => ['mode' => 'off', 'fx' => 'ink', 'kinds' => ['character' => 'quiet', 'alien' => 'ink']],
            'evil' => 'x']])->assertOk();
        $merged = $this->postJson($url, ['scope' => 'story', 'prefs' => ['view' => 'book', 'order' => 'sideways', 'theme' => 'light']])->assertOk()->json('prefs');

        $this->assertSame('paper', $merged['theme']);            // theme is general: the story scope cannot set it
        $this->assertSame(160, $merged['textSize']);
        $this->assertArrayNotHasKey('ui', $merged);
        $this->assertSame(['primary' => 'en'], $merged['lang']);
        $this->assertSame(['mode' => 'off', 'fx' => 'ink', 'kinds' => ['character' => 'quiet']], $merged['ix']);
        $this->assertSame('book', $merged['view']);
        $this->assertArrayNotHasKey('order', $merged);
        $this->assertArrayNotHasKey('evil', $merged);
        $this->assertSame('book', $this->payload($story)['prefs']['view']);

        // null removes a key; another reader and a guest have their own settings.
        $this->postJson($url, ['scope' => 'story', 'prefs' => ['view' => null]])->assertOk();
        $this->assertArrayNotHasKey('view', $this->payload($story)['prefs']);
        $this->actingAs($this->user());
        $this->assertSame([], $this->payload($story)['prefs']);
        $this->assertSame(1, ReaderPref::where('reader_key', 'u:'.$reader->id)->count());   // general only: the story row emptied and went

        $snapshot = json_encode(app(Snapshot::class)->export($story->fresh()));
        $this->assertStringNotContainsString('paper', $snapshot);
    }

    public function test_the_interface_speaks_the_readers_language(): void
    {
        [$story] = $this->edition();
        $p = $this->payload($story);
        $this->assertSame('ru', $p['catalog']['uiLang']);
        $this->assertSame('Обзор', $p['catalog']['strings']['views']['grid']);
        $this->assertEqualsCanonicalizing(['ru', 'en'], array_column($p['catalog']['uiLanguages'], 'code'));

        $this->postJson(route(config('story-studio.routes.reader.name').'prefs', $story), ['scope' => 'global', 'prefs' => ['uiLang' => 'en']])->assertOk();
        $html = $this->get(route(config('story-studio.routes.reader.name').'show', $story))->assertOk();
        $html->assertSee('lang="en"', false);
        $this->assertSame('Overview', $this->payload($story)['catalog']['strings']['views']['grid']);
    }

    public function test_layout_hints_are_typed_and_pairing_reaches_the_manifest(): void
    {
        [$story, , , $p] = $this->edition();
        $this->editor()->updatePage($p['a2'], ['hints' => ['pair' => 'start', 'pan' => ['seconds' => 24], 'script' => 'alert(1)']]);
        $this->editor()->updatePage($p['a3'], ['hints' => ['pair' => 'sideways', 'pan' => ['seconds' => 1]]]);
        $this->assertSame(['pair' => 'start', 'pan' => ['seconds' => 24]], $p['a2']->fresh()->hints);
        $this->assertNull($p['a3']->fresh()->hints);

        $manifest = collect($this->payload($story)['manifest'])->keyBy('id');
        $this->assertSame('start', $manifest[$p['a2']->public_id]['pair']);
        $this->assertArrayNotHasKey('pair', $manifest[$p['a1']->public_id]);
    }

    public function test_the_snapshot_carries_public_texts_and_music_and_an_import_keeps_them(): void
    {
        [$story, $a, , $p] = $this->bilingual();
        app(Texts::class)->set($story, 'page', $p['a1']->public_id, 'en', ['caption' => 'The launch leaves.', 'dialogue' => 'CAPTAIN: Till spring.']);
        app(Texts::class)->set($story, 'node', $a->public_id, 'en', ['title' => 'Arrival']);
        app(Attachments::class)->add($story, 'node', $a->public_id, 'track', ['title' => 'Westerly']);
        app(Attachments::class)->add($story, 'page', $p['b2']->public_id, 'track', ['title' => 'Hidden later']);
        $this->editor()->updatePage($p['b2'], ['hidden' => true]);

        $snap = app(Snapshot::class)->export($story->fresh());
        $this->assertSame(Snapshot::SCHEMA, $snap['schema']);
        $this->assertSame(['ru', 'en'], $snap['story']['settings']['languages']['offered']);
        $json = json_encode($snap, JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('The launch leaves.', $json);
        $this->assertStringNotContainsString('Till spring', $json);      // internal, even translated
        $this->assertStringNotContainsString('Hidden later', $json);     // music of an unpublished page
        $this->assertSame(['Westerly'], array_column(array_column($snap['attachments'], 'data'), 'title'));

        $file = sys_get_temp_dir().'/ss-'.bin2hex(random_bytes(4)).'.story.zip';
        $this->assertSame([], app(ExportPackage::class)->export($story->fresh(), $file)['errors']);
        $importer = app(ImportPackage::class);
        $plan = $importer->inspect($file);
        $this->assertTrue($plan['ok'], implode("\n", $plan['errors']));
        ['story' => $copy, 'map' => $map] = $importer->apply($plan);
        $importer->discard($plan);

        $this->assertSame(['ru', 'en'], $copy->edition()->languages);
        $this->assertSame('The launch leaves.', app(Texts::class)->of($copy, 'page', $map[$p['a1']->public_id])['en']['caption']);
        $chapter = $copy->nodes()->where('title', 'A')->sole();
        $this->assertSame('Arrival', app(Texts::class)->of($copy, 'node', $chapter->public_id)['en']['title']);
        $this->assertSame(['Westerly'], app(Attachments::class)->of($copy, 'node', $chapter->public_id)->pluck('data.title')->all());
    }

    public function test_deleting_takes_texts_and_music_along_and_a_merge_keeps_chapter_music(): void
    {
        [$story, $a, $b, $p] = $this->bilingual();
        app(Texts::class)->set($story, 'page', $p['a1']->public_id, 'en', ['caption' => 'x']);
        app(Attachments::class)->add($story, 'page', $p['a1']->public_id, 'track', ['title' => 'Page song']);
        app(Attachments::class)->add($story, 'node', $b->public_id, 'track', ['title' => 'Chapter B song']);

        $this->editor()->deletePages($story, [$p['a1']->public_id]);
        $this->assertSame(0, Text::count());
        $this->assertSame(['Chapter B song'], Attachment::pluck('data')->pluck('title')->all());

        $this->editor()->mergeWithNext($a);
        $this->assertSame($a->public_id, Attachment::sole()->scope_id);

        $this->editor()->deleteNode($a->fresh());
        $this->assertSame(0, Attachment::count());
    }

    public function test_the_studio_edits_languages_views_translations_and_music(): void
    {
        [$story, $a, , $p] = $this->edition();
        $this->actingAs($this->admin());

        Livewire::test(Editor::class, ['story' => $story])
            ->call('openSettings')
            ->set('settings.translations', 'en, de')
            ->set('settings.views', ['read' => true, 'book' => true])
            ->set('settings.defaultView', 'book')
            ->call('saveSettings')->assertHasNoErrors();
        $e = $story->fresh()->edition();
        $this->assertSame(['ru', 'en', 'de'], $e->languages);
        $this->assertSame(['read', 'book'], $e->views);
        $this->assertSame('book', $e->defaultView);

        Livewire::test(Editor::class, ['story' => $story])->call('openSettings')->set('settings.views', [])->call('saveSettings')
            ->assertHasErrors('settings.views');

        Livewire::test(Translations::class, ['story' => $story->public_id, 'subject' => 'page', 'id' => $p['a1']->public_id])
            ->assertSet('lang', 'en')
            ->set('fields.caption', 'The launch leaves.')
            ->set('lang', 'de')->set('fields.caption', 'Das Boot legt ab.');
        $this->assertSame(['en', 'de'], array_keys(app(Texts::class)->of($story->fresh(), 'page', $p['a1']->public_id)));

        $list = Livewire::test(AttachmentList::class, ['story' => $story->public_id, 'scope' => 'node', 'id' => $a->public_id])
            ->set('draft.title', 'Westerly')->set('draft.time', '4:08')->call('add')->assertSet('error', null)
            ->set('draft.title', 'Bad')->set('draft.url', 'http://plain.example')->call('add');
        $this->assertSame('Только адреса https://…', $list->get('error'));
        $track = Attachment::sole();
        $this->assertSame(['title' => 'Westerly', 'seconds' => 248], $track->data);

        Livewire::test(Editor::class, ['story' => $story])->call('removeAttachment', $track->public_id)->assertDispatched('ss-attachments');
        $this->assertSame(0, Attachment::count());

        // A page of another edition is not a subject here.
        [$other, , , $q] = $this->edition();
        Livewire::test(Translations::class, ['story' => $story->public_id, 'subject' => 'page', 'id' => $q['a1']->public_id])->assertStatus(404);
    }

    public function test_layout_hints_save_from_the_inspector(): void
    {
        [$story, , , $p] = $this->edition();
        $this->actingAs($this->admin());
        Livewire::test(Editor::class, ['story' => $story])->call('selectPage', $p['a2']->public_id)
            ->set('form.hints.pair', 'alone');
        $this->assertSame(['pair' => 'alone'], $p['a2']->fresh()->hints);
    }
}
