<?php

namespace Ombabush\StoryStudio\Interaction;

use Ombabush\StoryStudio\Models\Page;
use Ombabush\StoryStudio\Models\Story;

/**
 * The editable interaction profile of an edition (`ss_stories.interaction`): the same shape as the
 * public profile, minus what the exporter fills in (assets, revision, surfaces). A draft may be wrong
 * — a region without a label, a jump into a page that was deleted — and is still saved (CLI-HANDOFF
 * SR-02: draft validation never blocks saving). What is wrong is computed here, per interaction, so
 * the Reader skips exactly those and the exporter refuses to publish them.
 */
final class Draft
{
    /** @var array<string, array> per-request cache: story id + updated_at → analysis */
    private static array $cache = [];

    public static function blank(string $language): array
    {
        return ['profile' => ProfileValidator::PROFILE, 'language' => $language, 'cards' => [], 'pages' => []];
    }

    /** The draft with every key present, whatever an import or an older version stored. */
    public static function of(Story $story): array
    {
        // A new draft is written in the edition's original language (DATA-MODEL §1.1).
        $original = \Ombabush\StoryStudio\Support\Edition::of($story)->original;
        $d = is_array($story->interaction) ? $story->interaction : self::blank($original);
        $out = ['profile' => ProfileValidator::PROFILE, 'language' => (string) ($d['language'] ?? $original),
            'cards' => array_values((array) ($d['cards'] ?? [])), 'pages' => []];
        foreach ((array) ($d['pages'] ?? []) as $p) {
            if (! is_array($p) || ! isset($p['id'])) {
                continue;
            }
            $out['pages'][] = ['id' => $p['id']] + array_intersect_key($p, array_flip(['anchors', 'groups', 'textBlocks', 'protectedAreas']))
                + ['interactions' => array_values((array) ($p['interactions'] ?? []))];
        }

        return $out;
    }

    public static function binding(array $draft, string $pageId): ?array
    {
        foreach ($draft['pages'] as $p) {
            if ($p['id'] === $pageId) {
                return $p;
            }
        }

        return null;
    }

    /**
     * Validate the draft as a profile over this story: every page that has a picture gets a stand-in
     * image asset (the exporter makes the real one), every story page is a valid jump target.
     *
     * @return array{errors: array<int, string>, invalid: array<string, array<int, string>>, pageErrors: array<string, array<int, string>>}
     */
    public static function analyse(Story $story): array
    {
        $key = $story->id.'@'.$story->updated_at?->format('U.u').'#'.md5(json_encode($story->interaction));
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }
        $draft = self::of($story);
        $pages = Page::where('story_id', $story->id)->get(['id', 'public_id', 'current_media_id', 'type']);
        $withImage = $pages->filter(fn (Page $p) => $p->current_media_id !== null)->pluck('public_id')->flip();

        $assets = [];
        $profile = ['profile' => ProfileValidator::PROFILE, 'storyId' => 'STORY', 'revision' => 'sha256:'.str_repeat('0', 64),
            'language' => $draft['language'], 'assets' => [], 'cards' => $draft['cards'], 'pages' => []];
        foreach ($draft['pages'] as $p) {
            $surface = null;
            if (isset($withImage[$p['id']])) {
                $assets[] = ['id' => 'A'.$p['id'], 'path' => 'assets/'.$p['id'].'.png', 'sha256' => str_repeat('0', 64),
                    'mediaType' => 'image/png', 'width' => 1, 'height' => 1];
                $surface = ['assetId' => 'A'.$p['id']];
            }
            $profile['pages'][] = ['id' => $p['id'], 'surface' => $surface] + $p;
        }
        $profile['assets'] = $assets;
        if (! $profile['pages']) {
            return self::$cache[$key] = ['errors' => [], 'invalid' => [], 'pageErrors' => []];
        }

        $errors = app(ProfileValidator::class)->validate($profile, $pages->pluck('public_id')->all());
        $invalid = [];
        $pageErrors = [];
        foreach ($errors as $e) {
            if (preg_match('~^/pages/(\d+)/interactions/(\d+)~', $e, $m)) {
                $id = $profile['pages'][$m[1]]['interactions'][$m[2]]['id'] ?? ('#'.$m[1].'.'.$m[2]);
                $invalid[(string) $id][] = $e;
            } elseif (preg_match('~^/pages/(\d+)~', $e, $m)) {
                $pageErrors[$profile['pages'][$m[1]]['id'] ?? '?'][] = $e;
            } elseif (preg_match('~^/cards/(\d+)~', $e, $m)) {
                $pageErrors['cards'][] = $e;
            }
        }

        return self::$cache[$key] = ['errors' => $errors, 'invalid' => $invalid, 'pageErrors' => $pageErrors];
    }
}
