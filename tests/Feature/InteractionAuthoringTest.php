<?php

namespace Ombabush\StoryStudio\Tests\Feature;

use Ombabush\StoryStudio\Tests\Host\User;
use Livewire\Livewire;
use Ombabush\StoryStudio\Actions\EditInteractions;
use Ombabush\StoryStudio\Actions\ExportPackage;
use Ombabush\StoryStudio\Actions\ImportPackage;
use Ombabush\StoryStudio\Interaction\Draft;
use Ombabush\StoryStudio\Livewire\Studio\Interactions;
use Ombabush\StoryStudio\Models\Story;
use ZipArchive;

/**
 * Authoring (docs/FORMAT.md): the Studio's Interaction layer, the .story.zip export/import
 * and their guarantees — drafts save even when wrong, export refuses anything wrong, a roundtrip keeps
 * the meaning, nothing private leaves, a bad archive is refused before a byte is applied.
 */
class InteractionAuthoringTest extends FeatureTestCase
{
    private function edit(): EditInteractions
    {
        return app(EditInteractions::class);
    }

    private function fixture(): Story
    {
        config(['story-studio.reader.interaction' => true]);
        $this->artisan('story-studio:fixture', ['source' => $this->fixturePath()])->assertSuccessful();

        return Story::sole();
    }

    private function export(Story $story): array
    {
        $file = sys_get_temp_dir().'/ss-'.bin2hex(random_bytes(4)).'.story.zip';

        return app(ExportPackage::class)->export($story, $file) + ['file' => $file];
    }

    private function zip(array $entries): string
    {
        $file = sys_get_temp_dir().'/ss-'.bin2hex(random_bytes(4)).'.zip';
        $zip = new ZipArchive;
        $zip->open($file, ZipArchive::CREATE);
        foreach ($entries as $name => $bytes) {
            $zip->addFromString($name, $bytes);
        }
        $zip->close();

        return $file;
    }

    public function test_a_draft_saves_while_wrong_and_the_reader_skips_exactly_the_wrong_item(): void
    {
        [$story, , , $p] = $this->edition();
        config(['story-studio.reader.interaction' => true]);
        $v = EditInteractions::version($story);

        [$v, $id] = $this->edit()->addRegion($story, $v, $p['a1']->public_id, ['type' => 'rect', 'x' => 100, 'y' => 100, 'width' => 200, 'height' => 300]);
        $this->assertArrayHasKey($id, Draft::analyse($story->fresh())['invalid']);   // no label, no card yet

        [$v, $card] = $this->edit()->card($story->fresh(), $v, ['kind' => 'character', 'title' => ['ru' => 'Илья'], 'body' => ['ru' => 'Смотритель']]);
        $v = $this->edit()->update($story->fresh(), $v, $id, ['label' => ['ru' => 'Узнать про Илью'], 'action' => ['type' => 'open-card', 'cardId' => $card]]);
        $this->assertSame([], Draft::analyse($story->fresh())['invalid']);
    }

    /** C-39: two editors — the second save is a conflict, not a silent loss of the first one's geometry. */
    public function test_a_stale_save_is_a_conflict(): void
    {
        [$story, , , $p] = $this->edition();
        $old = EditInteractions::version($story);
        $this->edit()->addRegion($story, $old, $p['a1']->public_id, ['type' => 'rect', 'x' => 1, 'y' => 1, 'width' => 10, 'height' => 10]);

        $this->expectExceptionMessage(EditInteractions::CONFLICT);
        $this->edit()->addRegion($story->fresh(), $old, $p['a1']->public_id, ['type' => 'rect', 'x' => 1, 'y' => 1, 'width' => 10, 'height' => 10]);
    }

    public function test_a_text_link_cuts_the_published_caption_into_runs(): void
    {
        [$story, , , $p] = $this->edition();
        $this->editor()->updatePage($p['a1'], ['caption' => 'Илья остался на причале.']);

        [, $id] = $this->edit()->textLink($story, EditInteractions::version($story), $p['a1']->public_id, 'caption', 'Илья остался на причале.', 0, 4);

        $binding = Draft::binding(Draft::of($story->fresh()), $p['a1']->public_id);
        $this->assertSame(['Илья', ' остался на причале.'], array_column($binding['textBlocks'][0]['runs'], 'text'));
        $this->assertSame('text', collect($binding['interactions'])->firstWhere('id', $id)['anchor']['type']);
    }

    /** C-32 + C-41: export → import keeps ids, shapes, actions and labels; importing again makes no copy. */
    public function test_export_import_roundtrip_keeps_the_meaning_and_is_idempotent(): void
    {
        $story = $this->fixture();
        $first = $this->export($story);
        $this->assertSame([], $first['errors']);

        $importer = app(ImportPackage::class);
        $plan = $importer->inspect($first['file']);
        $this->assertTrue($plan['ok'], implode("\n", $plan['errors']));
        $copy = $importer->apply($plan)['story'];
        $importer->discard($plan);
        $this->assertNotSame($story->public_id, $copy->public_id);

        $again = $importer->inspect($first['file']);
        $this->assertSame($copy->public_id, $again['existing']);
        $this->assertFalse($importer->apply($again)['created']);
        $importer->discard($again);
        $this->assertSame(2, Story::count());

        // Same meaning: every interaction id, kind, label, shape and action type survives.
        $meaning = fn (Story $s) => collect(Draft::of($s)['pages'])->flatMap(fn ($p) => $p['interactions'])
            ->map(fn ($it) => [$it['id'], $it['kind'], $it['label'], $it['anchor']['shape'] ?? $it['anchor']['runId'], $it['action']['type']])->sortBy(0)->values()->all();
        $this->assertSame($meaning($story->fresh()), $meaning($copy));
        $this->assertSame([], $this->export($copy)['errors']);
    }

    public function test_export_refuses_errors_and_unconfirmed_contours(): void
    {
        $story = $this->fixture();
        $pier = $story->pages()->orderBy('id')->first();

        $this->editor()->replaceImage($pier, $this->png('redrawn.png', 800, 1100, 9));
        $refused = $this->export($story->fresh());
        $this->assertNull($refused['path']);
        $this->assertStringContainsString('confirm them first', implode("\n", $refused['errors']));

        $this->edit()->confirmSurface($story->fresh(), EditInteractions::version($story->fresh()), $pier->public_id);
        $this->assertSame([], $this->export($story->fresh())['errors']);

        $first = collect(Draft::of($story->fresh())['pages'][0]['interactions'])->first()['id'];
        $this->edit()->update($story->fresh(), EditInteractions::version($story->fresh()), $first, ['action' => ['type' => 'open-url', 'url' => 'javascript:alert(1)']]);
        $this->assertNull($this->export($story->fresh())['path']);
    }

    /** Nothing private in the package: no notes, balloon dialogue, drafts or old picture versions. */
    public function test_the_package_carries_only_the_public_layer(): void
    {
        $story = $this->fixture();
        $pages = $story->pages()->orderBy('id')->get();
        $this->editor()->updatePage($pages[0], ['note' => 'секрет автора', 'dialogue' => 'ИМЯ: реплика']);
        $this->editor()->addTextPage($story, null, ['caption' => 'Черновая страница']);

        $result = $this->export($story->fresh());
        $zip = new ZipArchive;
        $zip->open($result['file']);
        $names = [];
        $all = '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
            if (str_ends_with($zip->getNameIndex($i), '.json')) {
                $all .= $zip->getFromIndex($i);
            }
        }
        $this->assertStringNotContainsString('секрет автора', $all);
        $this->assertStringNotContainsString('ИМЯ: реплика', $all);
        $this->assertStringNotContainsString('Черновая страница', $all);
        $this->assertCount(2, array_filter($names, fn ($n) => str_starts_with($n, 'assets/')));
    }

    /** C-36 / C-37: hostile or inconsistent archives are refused before anything is written. */
    public function test_bad_archives_are_refused_before_apply(): void
    {
        $story = $this->fixture();
        $good = $this->export($story)['file'];
        $zip = new ZipArchive;
        $zip->open($good);
        $storyJson = $zip->getFromName('story.json');
        $profile = $zip->getFromName('interaction.json');
        $zip->close();

        $cases = [
            'traversal' => [['story.json' => $storyJson, '../evil.json' => '{}'], 'not an allowed path'],
            'stray file' => [['story.json' => $storyJson, 'run.sh' => 'rm -rf /'], 'not an allowed path'],
            'foreign revision' => [['story.json' => str_replace('Смотритель', 'Сторож', $storyJson), 'interaction.json' => $profile], 'does not match the sha256'],
            'missing assets' => [['story.json' => $storyJson, 'interaction.json' => $profile], 'not in the package'],
        ];
        foreach ($cases as $name => [$entries, $expect]) {
            $plan = app(ImportPackage::class)->inspect($this->zip($entries));
            $this->assertFalse($plan['ok'], $name);
            $this->assertStringContainsString($expect, implode("\n", $plan['errors']), $name);
            app(ImportPackage::class)->discard($plan);
        }
        $this->assertSame(1, Story::count());
    }

    /** The Studio buttons: export refuses with reasons; the shelf previews an import before writing it. */
    public function test_the_studio_exports_and_the_shelf_imports_with_a_preview(): void
    {
        $story = $this->fixture();
        $admin = $this->admin();
        $file = $this->export($story)['file'];

        Livewire::actingAs($admin)->test(\Ombabush\StoryStudio\Livewire\Studio\Editor::class, ['story' => $story])
            ->call('exportPackage')->assertFileDownloaded();

        $upload = fn () => \Illuminate\Http\UploadedFile::fake()->createWithContent('book.story.zip', file_get_contents($file));
        $c = Livewire::actingAs($admin)->test(\Ombabush\StoryStudio\Livewire\Studio\Index::class)
            ->set('package', $upload())
            ->assertSee('2 стр.')->assertSee('6 интерактивных мест')->assertDontSee('уже импортирован');
        $this->assertSame(1, Story::count());   // the preview wrote nothing
        $c->call('importPackage')->assertRedirect();
        $this->assertSame(2, Story::count());

        Livewire::actingAs($admin)->test(\Ombabush\StoryStudio\Livewire\Studio\Index::class)
            ->set('package', $upload())->assertSee('уже импортирован')
            ->call('importPackage')->assertRedirect();
        $this->assertSame(2, Story::count());   // the same package again: the edition it made, no copy
    }

    public function test_the_studio_layer_draws_and_undoes(): void
    {
        [$story, , , $p] = $this->edition();
        $admin = $this->admin();

        $c = Livewire::actingAs($admin)->test(Interactions::class, ['story' => $story->public_id, 'page' => $p['a1']->public_id])
            ->call('addRegion', ['type' => 'rect', 'x' => 10, 'y' => 10, 'width' => 100, 'height' => 100])
            ->assertSee('Нет подписи на языке оригинала');
        $this->assertCount(1, Draft::of($story->fresh())['pages'][0]['interactions']);

        $c->call('undo');
        $this->assertSame([], Draft::of($story->fresh())['pages']);
        $c->call('redo');
        $this->assertCount(1, Draft::of($story->fresh())['pages'][0]['interactions']);

        // Somebody who may not edit the edition gets no layer at all.
        Livewire::actingAs($this->user())->test(Interactions::class, ['story' => $story->public_id, 'page' => $p['a1']->public_id])
            ->assertStatus(404);
    }
}
