<?php

namespace Ombabush\StoryStudio\Tests\Feature;

use Ombabush\StoryStudio\Interaction\ProfileValidator;
use Ombabush\StoryStudio\Models\Page;
use Ombabush\StoryStudio\Models\Story;

/**
 * Story Reader SR-01 on the server side (docs/FORMAT.md): the profile is validated as closed data,
 * the fixture binds by revision and asset hash, and the reader receives a whitelist — no branch into a
 * page it may not open, no card it does not reach, no contour drawn for another picture.
 */
class InteractionProfileTest extends FeatureTestCase
{
    private function fixtureDir(): string
    {
        return $this->fixturePath();
    }

    private function profile(): array
    {
        return json_decode(file_get_contents($this->fixtureDir().'/interaction.json'), true);
    }

    private function validate(array $profile): array
    {
        return app(ProfileValidator::class)->validate($profile, ['BOOK_01_CHAPTER_01_PIER', 'BOOK_01_CHAPTER_01_LIGHTHOUSE']);
    }

    private function imported(bool $publish = true): Story
    {
        config(['story-studio.reader.interaction' => true]);
        $this->artisan('story-studio:fixture', ['source' => $this->fixtureDir()] + ($publish ? ['--publish' => true] : []))->assertSuccessful();

        return Story::sole();
    }

    private function readerPages(Story $story): array
    {
        $html = $this->get(route(config('story-studio.routes.reader.name').'show', $story))->assertOk()->getContent();
        preg_match('~<script type="application/json" id="[^"]+-data">(.+?)</script>~s', $html, $m);

        return json_decode(html_entity_decode($m[1]), true);
    }

    public function test_both_reference_examples_validate(): void
    {
        $this->assertSame([], $this->validate($this->profile()));
        $minimal = json_decode(file_get_contents($this->fixtureDir().'/minimal.json'), true);
        $this->assertSame([], $this->validate($minimal));
    }

    /** C-33…C-36: unknown fields and actions, hostile paths and URLs, broken references. */
    public function test_bad_profiles_fail_with_an_exact_reason(): void
    {
        $cases = [
            'unknown field' => [fn (&$p) => $p['pages'][0]['interactions'][0]['onClick'] = 'alert(1)', 'onClick: is not a field'],
            'unknown action' => [fn (&$p) => $p['pages'][0]['interactions'][0]['action'] = ['type' => 'run-script'], 'unknown action'],
            'javascript url' => [fn (&$p) => $p['pages'][0]['interactions'][0]['action'] = ['type' => 'open-url', 'url' => 'javascript:alert(1)'], 'https://'],
            'credentials url' => [fn (&$p) => $p['pages'][0]['interactions'][0]['action'] = ['type' => 'open-url', 'url' => 'https://u:p@evil.test/'], 'https://'],
            'relative path command' => [fn (&$p) => $p['pages'][0]['interactions'][0]['anchor']['shape'] = ['type' => 'path', 'd' => 'm 10 10 l 20 20 l 10 30 z', 'fillRule' => 'evenodd'], 'M L Q C Z'],
            'arc in path' => [fn (&$p) => $p['pages'][0]['interactions'][0]['anchor']['shape'] = ['type' => 'path', 'd' => 'M 10 10 A 5 5 0 0 1 20 20 Z', 'fillRule' => 'evenodd'], 'M L Q C Z'],
            'open subpath' => [fn (&$p) => $p['pages'][0]['interactions'][0]['anchor']['shape'] = ['type' => 'path', 'd' => 'M 10 10 L 20 20 L 10 30', 'fillRule' => 'evenodd'], 'not closed'],
            'outside the plane' => [fn (&$p) => $p['pages'][0]['interactions'][0]['anchor']['shape'] = ['type' => 'rect', 'x' => 900, 'y' => 10, 'width' => 200, 'height' => 10], 'inside 0…1000'],
            'self-intersecting polygon' => [fn (&$p) => $p['pages'][0]['interactions'][0]['anchor']['shape'] = ['type' => 'polygon', 'points' => [[0, 0], [100, 100], [100, 0], [0, 100]]], 'simple closed polygon'],
            'duplicate interaction id' => [fn (&$p) => $p['pages'][0]['interactions'][1]['id'] = $p['pages'][0]['interactions'][0]['id'], 'duplicate interaction id'],
            'missing card' => [fn (&$p) => $p['pages'][0]['interactions'][0]['action'] = ['type' => 'open-card', 'cardId' => 'NOBODY'], 'names no card'],
            'missing page' => [fn (&$p) => $p['pages'][0]['interactions'][1]['action']['pageId'] = 'NOWHERE', 'names no page'],
            'missing anchor' => [fn (&$p) => $p['pages'][0]['interactions'][2]['action']['anchorId'] = 'NOWHERE', 'names no anchor'],
            'missing run' => [fn (&$p) => $p['pages'][0]['interactions'][5]['anchor']['runId'] = 'NOWHERE', 'names no text run'],
            'no label in the original language' => [fn (&$p) => $p['pages'][0]['interactions'][0]['label'] = ['en' => 'About Ilya'], 'original language (ru)'],
            'essential card' => [fn (&$p) => $p['pages'][0]['interactions'][0]['importance'] = 'essential', 'only for jump'],
            'wrong profile' => [fn (&$p) => $p['profile'] = 'story-interaction/2.0', 'must be exactly'],
        ];
        foreach ($cases as $name => [$break, $expect]) {
            $profile = $this->profile();
            $break($profile);
            $errors = implode("\n", $this->validate($profile));
            $this->assertStringContainsString($expect, $errors, "case «{$name}» did not fail as expected");
        }
    }

    public function test_the_fixture_imports_with_host_ids_and_refuses_a_foreign_revision(): void
    {
        $story = $this->imported(false);

        // The draft keeps where it came from: the revision makes a second import return this edition.
        $this->assertStringStartsWith('sha256:', $story->settings['source']['revision']);
        $this->assertSame('STORY_LIGHTHOUSE_DEMO', $story->settings['source']['storyId']);
        $ids = Page::where('story_id', $story->id)->pluck('public_id')->all();
        $this->assertEqualsCanonicalizing($ids, array_column($story->interaction['pages'], 'id'));
        $this->assertContains($story->interaction['pages'][0]['interactions'][1]['action']['pageId'], $ids);
        $this->assertCount(2, $story->interaction_surfaces);

        // The same contours against another story.json are refused before anything is written.
        $dir = sys_get_temp_dir().'/ss-fixture-'.bin2hex(random_bytes(3));
        @mkdir($dir.'/assets', 0777, true);
        foreach (['interaction.json', 'assets/pier.png', 'assets/lighthouse.png'] as $f) {
            copy($this->fixtureDir().'/'.$f, $dir.'/'.$f);
        }
        file_put_contents($dir.'/story.json', str_replace('Илья остался', 'Илья ушёл', file_get_contents($this->fixtureDir().'/story.json')));
        $this->artisan('story-studio:fixture', ['source' => $dir])->expectsOutputToContain('does not match the sha256 of story.json')->assertFailed();
        $this->assertSame(1, Story::count());
    }

    /** C-01: without the flag (or without a profile) the reader is exactly the old one. */
    public function test_without_the_flag_nothing_changes(): void
    {
        $story = $this->imported();
        config(['story-studio.reader.interaction' => false]);

        $payload = $this->readerPages($story);
        $this->assertFalse($payload['interaction']);
        $this->assertArrayNotHasKey('interaction', $payload['pages'][0]);
    }

    public function test_the_reader_gets_the_public_projection(): void
    {
        $story = $this->imported();
        $page = $this->readerPages($story)['pages'][0]['interaction'];

        $this->assertSame('ru', $page['language']);
        $this->assertCount(6, $page['items']);
        $this->assertSame(['caption'], array_column($page['blocks'], 'target'));
        $this->assertEqualsCanonicalizing(['CHARACTER_ILYA', 'SHELL_NOTE', 'CAPTAIN_NOTE'], array_column($page['cards'], 'id'));
        $this->assertSame([true, true], array_values(array_map(fn ($i) => $i['essential'], array_filter($page['items'], fn ($i) => $i['action']['type'] === 'jump'))));
        // A jump into a detail carries its anchor, and the page sends the anchor's public geometry (SR-03 zoom).
        $stay = collect($page['items'])->firstWhere('id', 'PIER_STAY');
        $this->assertSame('PIER_DETAIL', $stay['action']['anchorId']);
        $this->assertSame(['PIER_DETAIL'], array_column($page['anchors'], 'id'));
    }

    /** C-17: a branch into a page this reader cannot open is not sent — no id, no label, no count. */
    public function test_a_branch_into_a_hidden_page_does_not_exist_for_the_reader(): void
    {
        $story = $this->imported();
        $lighthouse = Page::where('story_id', $story->id)->orderByDesc('id')->first();
        $this->editor()->setStatus($story, [$lighthouse->public_id], Page::DRAFT);

        $payload = $this->readerPages($story);
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString($lighthouse->public_id, $json);
        $this->assertStringNotContainsString('Пройти к маяку', $json);
        $this->assertCount(5, $payload['pages'][0]['interaction']['items']);
    }

    /** C-16: a new picture switches the old contours off and says why; text links stay. */
    public function test_a_replaced_picture_turns_its_contours_off(): void
    {
        $story = $this->imported();
        $pier = Page::where('story_id', $story->id)->orderBy('id')->first();
        $this->editor()->replaceImage($pier, $this->png('redrawn.png', 800, 1100, 42));

        $page = $this->readerPages($story)['pages'][0]['interaction'];
        $this->assertTrue($page['stale']);
        $this->assertSame(['text'], array_values(array_unique(array_map(fn ($i) => $i['anchor']['type'], $page['items']))));
    }

    /** The profile never travels with the public snapshot schema 1 (SR-02 negotiates that). */
    public function test_the_public_snapshot_is_unchanged(): void
    {
        $story = $this->imported();
        $snapshot = app(\Ombabush\StoryStudio\Support\Snapshot::class)->export($story);

        $this->assertSame(1, $snapshot['schema']);
        $this->assertStringNotContainsString('PIER_ILYA_PROFILE', json_encode($snapshot));
    }
}
