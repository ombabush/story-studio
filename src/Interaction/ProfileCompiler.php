<?php

namespace Ombabush\StoryStudio\Interaction;

use Ombabush\StoryStudio\Models\Page;
use Ombabush\StoryStudio\Models\Story;
use Ombabush\StoryStudio\Support\Snapshot;
use Ombabush\StoryStudio\Support\Version;

/**
 * Draft → public profile, bound to the exact bytes of the edition's public snapshot (FORMAT.md):
 *
 *   story.json        the existing Snapshot (schema 1, public layer only), written ONCE to bytes;
 *   interaction.json  revision = sha256 of those bytes; one image asset per page picture; only the
 *                     interactions of exported pages, only cards they open; draft-only data never goes;
 *   assets/           the original picture files the regions were drawn on.
 *
 * Export is the gate the draft is not: any error blocks it (C-38 included — contours not confirmed
 * for a replaced picture). A jump into an unexported page is an error, not a silent drop, because the
 * author would lose a branch without being told.
 */
class ProfileCompiler
{
    public function __construct(private Snapshot $snapshots, private ProfileValidator $validator) {}

    /**
     * @return array{story_json: string, profile: ?array, files: array<string, string>, errors: array<int, string>}
     *               files: archive path → local file
     */
    public function compile(Story $story): array
    {
        $snapshot = $this->snapshots->export($story);
        $storyJson = json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
        $out = ['story_json' => $storyJson, 'profile' => null, 'files' => [], 'errors' => []];
        if (! is_array($story->interaction)) {
            return $out;    // a plain edition: no profile, a normal non-interactive book
        }

        $draft = Draft::of($story);
        $public = array_flip(array_column($snapshot['pages'], 'id'));
        $pages = Page::where('story_id', $story->id)->whereIn('public_id', array_keys($public))->with('currentImage')->get()->keyBy('public_id');

        $assets = [];
        $bindings = [];
        $usedCards = [];
        foreach ($draft['pages'] as $binding) {
            $pid = $binding['id'];
            if (! isset($public[$pid])) {
                continue;   // a page readers cannot see takes its interactions with it
            }
            $page = $pages[$pid];
            $image = $page->currentImage && ! Version::broken($page->currentImage) ? $page->currentImage : null;
            $surface = null;
            if ($image && in_array($page->type, ['image', 'spread', 'panorama', 'mixed'], true)) {
                $asset = $this->asset($pid, $image);
                if (is_string($asset)) {
                    $out['errors'][] = "/pages/$pid: $asset";
                } else {
                    $assets[] = $asset['entry'];
                    $out['files'][$asset['entry']['path']] = $asset['file'];
                    $surface = ['assetId' => $asset['entry']['id']];
                }
            }
            $hasRegions = collect($binding['interactions'])->contains(fn ($it) => ($it['anchor']['type'] ?? null) === 'region');
            if ($hasRegions && $image && ($story->interaction_surfaces[$pid] ?? null) !== Version::sha1($image)) {
                $out['errors'][] = "/pages/$pid: the picture was replaced after the contours were drawn — confirm them first";
            }
            foreach ($binding['interactions'] as $it) {
                if (($it['action']['type'] ?? null) === 'jump' && ! isset($public[$it['action']['pageId'] ?? ''])) {
                    $out['errors'][] = "/pages/$pid/interactions/{$it['id']}: jumps to a page readers cannot open";
                }
                if (($it['action']['type'] ?? null) === 'open-card') {
                    $usedCards[$it['action']['cardId'] ?? ''] = true;
                }
            }
            $bindings[] = ['id' => $pid, 'surface' => $surface] + array_intersect_key($binding, array_flip(['interactions', 'anchors', 'groups', 'textBlocks', 'protectedAreas']));
        }

        $profile = [
            'profile' => ProfileValidator::PROFILE,
            'storyId' => $story->public_id,
            'revision' => 'sha256:'.hash('sha256', $storyJson),
            'language' => $draft['language'],
            'assets' => $assets,
            'cards' => array_values(array_filter($draft['cards'], fn ($c) => isset($usedCards[$c['id'] ?? '']))),
            'pages' => $bindings,
        ];
        if (! $bindings) {
            return $out;
        }
        $out['errors'] = array_merge($out['errors'], $this->validator->validate($profile, array_keys($public)), $this->textMatches($profile, $snapshot));
        $out['profile'] = $profile;

        return $out;
    }

    /** @return array{entry: array, file: string}|string the asset, or why it cannot be one */
    private function asset(string $pageId, $image): array|string
    {
        $file = $image->getPath();
        $type = $image->mime_type;
        if (! in_array($type, ProfileValidator::IMAGE_TYPES, true)) {
            return "the picture is $type; the profile carries JPEG, PNG or WebP only";
        }
        if (! is_file($file)) {
            return 'the picture file is missing on disk';
        }
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$type];

        return ['file' => $file, 'entry' => ['id' => 'IMG_'.$pageId, 'path' => 'assets/'.$pageId.'.'.$ext, 'mediaType' => $type,
            'sha256' => hash_file('sha256', $file), 'width' => (int) Version::width($image), 'height' => (int) Version::height($image)]];
    }

    /** A text block must still spell a published caption or paragraph, or its link lands on the wrong words. */
    private function textMatches(array $profile, array $snapshot): array
    {
        $errors = [];
        $pages = collect($snapshot['pages'])->keyBy('id');
        $presenter = app(\Ombabush\StoryStudio\Support\Presenter::class);
        foreach ($profile['pages'] as $p) {
            $page = $pages[$p['id']];
            $texts = array_merge([(string) $page['caption']], $presenter->paragraphs($page['body']));
            foreach ((array) ($p['textBlocks'] ?? []) as $b) {
                if (! in_array(implode('', array_column($b['runs'], 'text')), $texts, true)) {
                    $errors[] = "/pages/{$p['id']}/textBlocks/{$b['id']}: the text changed since the link was made";
                }
            }
        }

        return $errors;
    }
}
