<?php

namespace Ombabush\StoryStudio\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Ombabush\StoryStudio\Interaction\ProfileValidator;
use Ombabush\StoryStudio\Models\Page;
use Ombabush\StoryStudio\Models\Story;
use Ombabush\StoryStudio\Support\Version;
use ZipArchive;

/**
 * `.story.zip` (or an unpacked folder) → an edition, in the importer order of FORMAT.md:
 * retain → inventory → ZIP limits → schema → references → assets (hash, real MIME, decode) → preview →
 * apply. Nothing is written to the library before apply, and apply never publishes (CANON: import does
 * not publish). Re-importing the same package returns the edition it made the first time (C-41).
 */
class ImportPackage
{
    public const MAX_TOTAL = 1024 * 1024 * 1024;

    public const MAX_ASSET = 40 * 1024 * 1024;

    public const MAX_RATIO = 100;

    public const MAX_PIXELS = 40_000_000;

    public function __construct(private StoryEditor $editor, private ProfileValidator $validator) {}

    /**
     * @return array{ok: bool, errors: array<int, string>, dir: ?string, retained: ?string, summary: array, existing: ?string}
     */
    public function inspect(string $source): array
    {
        $plan = ['ok' => false, 'errors' => [], 'dir' => null, 'retained' => null, 'summary' => [], 'existing' => null];
        if (is_dir($source)) {
            $dir = rtrim($source, '/');
        } else {
            $plan['retained'] = $this->retain($source);
            $dir = $this->unzip($source, $plan['errors']);
            if (! $dir) {
                return $plan;
            }
            $plan['dir'] = $dir;
        }

        $storyBytes = @file_get_contents("$dir/story.json");
        $story = json_decode((string) $storyBytes, true);
        if (! is_array($story) || ($story['schema'] ?? null) !== 1 || ! is_array($story['pages'] ?? null)) {
            $plan['errors'][] = 'story.json: missing or not a Story Studio snapshot (schema 1)';

            return $plan;
        }
        $profile = null;
        if (is_file("$dir/interaction.json")) {
            if (filesize("$dir/interaction.json") > 8 * 1024 * 1024) {
                $plan['errors'][] = 'interaction.json: larger than 8 MiB';

                return $plan;
            }
            $profile = json_decode((string) file_get_contents("$dir/interaction.json"), true);
            $pageIds = array_column($story['pages'], 'id');
            foreach ($this->validator->validate($profile, $pageIds) as $e) {
                $plan['errors'][] = 'interaction.json'.$e;
            }
            if (is_array($profile)) {
                if ('sha256:'.hash('sha256', $storyBytes) !== ($profile['revision'] ?? null)) {
                    $plan['errors'][] = 'interaction.json/revision: does not match the sha256 of story.json — the contours belong to another version';
                }
                if (($profile['storyId'] ?? null) !== ($story['story']['id'] ?? null)) {
                    $plan['errors'][] = 'interaction.json/storyId: is not the id of story.json';
                }
                foreach ((array) ($profile['assets'] ?? []) as $a) {
                    $this->checkAsset($dir, $a, $plan['errors']);
                }
            }
        }

        // Pictures of pages without an interaction binding are found by the sha1 the snapshot records.
        $bySha1 = [];
        foreach (is_dir("$dir/assets") ? File::allFiles("$dir/assets") : [] as $f) {
            $bySha1[sha1_file($f->getRealPath())] = $f->getRealPath();
        }
        $missing = collect($story['pages'])->filter(fn ($p) => isset($p['image']['sha1']) && ! isset($bySha1[$p['image']['sha1']]))->count();
        if ($missing) {
            $plan['errors'][] = "assets/: $missing page picture(s) named in story.json are not in the package";
        }

        $revision = 'sha256:'.hash('sha256', (string) $storyBytes);
        $plan['existing'] = Story::all()->first(fn (Story $s) => ($s->settings['source']['revision'] ?? null) === $revision)?->public_id;
        $plan['summary'] = [
            'title' => (string) ($story['story']['title'] ?? ''),
            'pages' => count($story['pages']),
            'chapters' => collect($story['nodes'] ?? [])->where('kind', 'chapter')->count(),
            'interactions' => collect($profile['pages'] ?? [])->sum(fn ($p) => count($p['interactions'] ?? [])),
            'cards' => count($profile['cards'] ?? []),
            'revision' => $revision,
        ];
        $plan['story'] = $story;
        $plan['profile'] = $profile;
        $plan['pictures'] = $bySha1;
        $plan['source_dir'] = $dir;
        $plan['ok'] = ! $plan['errors'];

        return $plan;
    }

    /** @return array{story: Story, map: array<string, string>, created: bool} */
    public function apply(array $plan, ?Authenticatable $owner = null): array
    {
        if (! $plan['ok']) {
            throw new \InvalidArgumentException('The package has errors; nothing was imported.');
        }
        if ($plan['existing']) {
            return ['story' => Story::where('public_id', $plan['existing'])->firstOrFail(), 'map' => [], 'created' => false];
        }
        $story = $plan['story'];
        $profile = $plan['profile'];
        $dir = $plan['source_dir'];
        $bindings = collect($profile['pages'] ?? [])->keyBy('id');
        $assets = collect($profile['assets'] ?? [])->keyBy('id');

        return DB::transaction(function () use ($story, $profile, $plan, $dir, $bindings, $assets, $owner) {
            $meta = $story['story'];
            $model = $this->editor->createStory(['title' => $meta['title'] ?? 'Без названия', 'description' => $meta['description'] ?? null,
                'authors' => is_array($meta['authors'] ?? null) ? implode(', ', $meta['authors']) : ($meta['authors'] ?? null),
                'direction' => $meta['direction'] ?? 'ltr',
                'settings' => ['source' => ['storyId' => $meta['id'] ?? null, 'revision' => $plan['summary']['revision'], 'retained' => $plan['retained']]]], $owner);

            // The edition's own languages and views travel in its settings; the import label is added here.
            if (is_array($meta['settings'] ?? null)) {
                $this->editor->updateStory($model, ['languages' => $meta['settings']['languages'] ?? null, 'views' => $meta['settings']['views'] ?? null]);
            }
            $nodes = [];
            foreach ($story['nodes'] ?? [] as $n) {
                $nodes[$n['id']] = $this->editor->addNode($model, $n['kind'], (string) $n['title'], isset($n['parent']) ? ($nodes[$n['parent']] ?? null) : null);
            }

            $map = [];
            $surfaces = [];
            foreach ($story['pages'] as $p) {
                $node = isset($p['node']) ? ($nodes[$p['node']] ?? null) : null;
                $assetId = $bindings[$p['id']]['surface']['assetId'] ?? null;
                $file = $assetId ? $dir.'/'.$assets[$assetId]['path'] : ($plan['pictures'][$p['image']['sha1'] ?? ''] ?? null);
                $page = $file
                    ? $this->editor->addImagePages($model, $node, [['file' => $file, 'name' => $p['image']['name'] ?? basename($file), 'type' => $p['type'] ?? 'image']], null, Page::READY)->first()
                    : $this->editor->addTextPage($model, $node, ['status' => Page::READY]);
                $this->editor->updatePage($page, array_intersect_key($p, array_flip(['type', 'caption', 'body', 'alt', 'time_label', 'time_rank', 'time_place', 'time_who', 'hints', 'regions'])));
                $map[$p['id']] = $page->public_id;
                if ($file && $assetId) {
                    $surfaces[$page->public_id] = Version::sha1($page->fresh()->currentImage);
                }
            }
            if ($profile) {
                $model->forceFill(['interaction' => $this->rewrite($profile, $map), 'interaction_surfaces' => $surfaces ?: null])->save();
            }
            // Translations and music keep their meaning under this host's new ids.
            $ids = $map + collect($nodes)->mapWithKeys(fn ($n, $id) => [$id => $n->public_id])->all();
            app(\Ombabush\StoryStudio\Support\Texts::class)->replace($model, (array) ($story['texts'] ?? []), $ids);
            app(\Ombabush\StoryStudio\Support\Attachments::class)->replace($model, (array) ($story['attachments'] ?? []), $ids, false);

            return ['story' => $model->fresh(), 'map' => $map, 'created' => true];
        });
    }

    public function discard(array $plan): void
    {
        if (! empty($plan['dir']) && str_starts_with($plan['dir'], storage_path('app/story-studio-tmp'))) {
            File::deleteDirectory($plan['dir']);
        }
    }

    // ── internals ───────────────────────────────────────────────────────────────────────────

    /** The draft keeps the profile's meaning with this host's ids: pages and every jump target. */
    private function rewrite(array $profile, array $map): array
    {
        $draft = ['profile' => ProfileValidator::PROFILE, 'language' => $profile['language'], 'cards' => $profile['cards'], 'pages' => []];
        foreach ($profile['pages'] as $page) {
            foreach ($page['interactions'] as &$it) {
                if ($it['action']['type'] === 'jump') {
                    $it['action']['pageId'] = $map[$it['action']['pageId']] ?? $it['action']['pageId'];
                }
            }
            unset($it);
            unset($page['surface']);
            $page['id'] = $map[$page['id']];
            $draft['pages'][] = $page;
        }

        return $draft;
    }

    /** The original archive is kept as received: a bad apply can be redone from the same bytes. */
    private function retain(string $zip): string
    {
        $path = trim((string) config('story-studio.storage.imports', 'story-studio/imports'), '/').'/'.hash_file('sha256', $zip).'.story.zip';
        $disk = Storage::disk((string) config('story-studio.storage.disk', 'local'));
        if (! $disk->exists($path)) {
            $stream = fopen($zip, 'rb');
            $disk->put($path, $stream);
            is_resource($stream) && fclose($stream);
        }

        return $path;
    }

    /** ZIP limits before a single byte is decoded (FORMAT «Валидация»). */
    private function unzip(string $source, array &$errors): ?string
    {
        $zip = new ZipArchive;
        if ($zip->open($source, ZipArchive::RDONLY) !== true) {
            $errors[] = 'not a readable ZIP archive';

            return null;
        }
        $seen = [];
        $total = 0;
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $st = $zip->statIndex($i);
            $name = (string) $st['name'];
            if (str_ends_with($name, '/')) {
                continue;
            }
            $zip->getExternalAttributesIndex($i, $os, $attr);
            $mode = ($attr >> 16) & 0170000;
            if ($os === ZipArchive::OPSYS_UNIX && $mode === 0120000) {
                $errors[] = "$name: symbolic links are not allowed";

                continue;
            }
            if ($name !== 'story.json' && $name !== 'interaction.json' && (! preg_match('#^assets/[A-Za-z0-9_./-]+$#', $name)
                || preg_match('#(^|/)\.{1,2}(/|$)|//#', $name) || strlen($name) > 240)) {
                $errors[] = "$name: not an allowed path (story.json, interaction.json, assets/…)";

                continue;
            }
            $key = mb_strtolower($name);
            if (isset($seen[$key])) {
                $errors[] = "$name: duplicate name";

                continue;
            }
            $seen[$key] = true;
            $size = (int) $st['size'];
            $total += $size;
            if (str_starts_with($name, 'assets/') && $size > self::MAX_ASSET) {
                $errors[] = "$name: larger than 40 MiB";
            }
            if ($size > 0 && (int) $st['comp_size'] > 0 && $size / (int) $st['comp_size'] > self::MAX_RATIO) {
                $errors[] = "$name: compression ratio above 100:1";
            }
            $entries[$i] = $name;
        }
        if ($total > self::MAX_TOTAL) {
            $errors[] = 'archive unpacks to more than 1 GiB';
        }
        if ($errors) {
            $zip->close();

            return null;
        }
        $dir = storage_path('app/story-studio-tmp/pkg-'.Str::random(12));
        foreach ($entries as $i => $name) {
            File::ensureDirectoryExists(dirname("$dir/$name"));
            $in = $zip->getStream($name);
            $out = fopen("$dir/$name", 'wb');
            stream_copy_to_stream($in, $out, self::MAX_ASSET + 1);
            fclose($in);
            fclose($out);
        }
        $zip->close();

        return $dir;
    }

    private function checkAsset(string $dir, array $a, array &$errors): void
    {
        $file = $dir.'/'.($a['path'] ?? '');
        if (! is_file($file)) {
            $errors[] = "{$a['path']}: listed in interaction.json but not in the package";

            return;
        }
        if (hash_file('sha256', $file) !== ($a['sha256'] ?? null)) {
            $errors[] = "{$a['path']}: sha256 differs from interaction.json";
        }
        $real = (new \finfo(FILEINFO_MIME_TYPE))->file($file);
        if ($real !== ($a['mediaType'] ?? null)) {
            $errors[] = "{$a['path']}: is $real, declared {$a['mediaType']}";
        }
        if (in_array($a['mediaType'] ?? null, ProfileValidator::IMAGE_TYPES, true)) {
            $size = @getimagesize($file);
            if (! $size) {
                $errors[] = "{$a['path']}: does not decode as an image";
            } elseif ($size[0] !== ($a['width'] ?? null) || $size[1] !== ($a['height'] ?? null)) {
                $errors[] = "{$a['path']}: is {$size[0]}×{$size[1]}, declared {$a['width']}×{$a['height']}";
            } elseif ($size[0] * $size[1] > self::MAX_PIXELS) {
                $errors[] = "{$a['path']}: more than 40 megapixels";
            }
        }
    }
}
