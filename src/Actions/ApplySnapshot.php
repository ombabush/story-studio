<?php

namespace Ombabush\StoryStudio\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Ombabush\StoryStudio\Models\Node;
use Ombabush\StoryStudio\Models\Page;
use Ombabush\StoryStudio\Models\Story;
use Ombabush\StoryStudio\Support\ImageStore;
use Ombabush\StoryStudio\Support\Snapshot;
use Ombabush\StoryStudio\Support\Version;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * The receiving side of Snapshot: makes this database hold exactly the published edition the author
 * sent, under the same public ids, so a link shared from one side opens the same page on the other.
 *
 * The receiver is a mirror, not an editor: anything not in the snapshot goes (a page made private on
 * the author's side disappears here), and only the current picture of each page is kept — the
 * version history stays with the author.
 */
class ApplySnapshot
{
    public function __construct(private ImageStore $images) {}

    /**
     * @return array{story: Story, need: array<int, array{page: string, sha1: string}>} pictures to send next
     */
    public function apply(array $snapshot, Authenticatable $owner): array
    {
        if (($snapshot['schema'] ?? null) !== Snapshot::SCHEMA) {
            throw new InvalidArgumentException('Unknown snapshot schema.');
        }
        $meta = $snapshot['story'] ?? [];
        $id = (string) ($meta['id'] ?? '');
        if (! preg_match('/^[0-9a-hjkmnp-tv-z]{26}$/i', $id) || trim((string) ($meta['title'] ?? '')) === '') {
            throw new InvalidArgumentException('A snapshot needs a story id and a title.');
        }

        return DB::transaction(function () use ($snapshot, $meta, $id, $owner) {
            $story = Story::where('public_id', $id)->first();
            // The same id under another account is somebody else's edition: never overwrite it.
            if ($story && (string) $story->owner_id !== (string) $owner->getAuthIdentifier()) {
                throw new InvalidArgumentException('That story belongs to another account.');
            }
            $story ??= (new Story)->forceFill(['public_id' => $id, 'owner_id' => $owner->getAuthIdentifier()]);
            $story->fill([
                'title' => trim((string) $meta['title']),
                'authors' => $meta['authors'] ?? null,
                'description' => $meta['description'] ?? null,
                'direction' => ($meta['direction'] ?? 'ltr') === 'rtl' ? 'rtl' : 'ltr',
                'settings' => is_array($meta['settings'] ?? null) ? $meta['settings'] : null,
            ]);
            $story->published_at ??= now();
            $story->save();

            $nodes = $this->nodes($story, (array) ($snapshot['nodes'] ?? []));
            $need = $this->pages($story, (array) ($snapshot['pages'] ?? []), $nodes);
            // A mirror: exactly the translations and attachments the author published, nothing older.
            app(\Ombabush\StoryStudio\Support\Texts::class)->replace($story, (array) ($snapshot['texts'] ?? []));
            app(\Ombabush\StoryStudio\Support\Attachments::class)->replace($story, (array) ($snapshot['attachments'] ?? []));

            return ['story' => $story->fresh(), 'need' => $need];
        });
    }

    /**
     * Bind the applied snapshot to the exact bytes it arrived as. A changed snapshot drops the stored
     * interaction profile at once: its contours belong to the previous version until a profile for
     * this revision arrives (applyProfile).
     */
    public function recordRevision(Story $story, string $rawSnapshot): string
    {
        $revision = 'sha256:'.hash('sha256', $rawSnapshot);
        if ($story->snapshot_revision !== $revision) {
            $story->forceFill(['snapshot_revision' => $revision, 'interaction' => null, 'interaction_surfaces' => null])->save();
        }

        return $revision;
    }

    /**
     * The interaction sidecar (SR-02). Applied whole or not at all: it must validate, name the revision
     * of the snapshot this host holds, and every surface asset must be byte-for-byte (sha256) the
     * picture this host has for that page — so a profile never outlines a picture it was not drawn on.
     */
    public function applyProfile(Story $story, array $profile): void
    {
        $pageIds = Page::where('story_id', $story->id)->pluck('public_id')->all();
        $errors = app(\Ombabush\StoryStudio\Interaction\ProfileValidator::class)->validate($profile, $pageIds);
        if ($errors) {
            throw new InvalidArgumentException('The interaction profile is invalid: '.implode('; ', array_slice($errors, 0, 5)));
        }
        if (! $story->snapshot_revision || ($profile['revision'] ?? null) !== $story->snapshot_revision) {
            throw new InvalidArgumentException('The interaction profile belongs to another snapshot revision.');
        }
        if (($profile['storyId'] ?? null) !== $story->public_id) {
            throw new InvalidArgumentException('The interaction profile is for another story.');
        }
        $assets = collect($profile['assets'])->keyBy('id');
        $surfaces = [];
        foreach ($profile['pages'] as $binding) {
            if (! ($binding['surface']['assetId'] ?? null)) {
                continue;
            }
            $asset = $assets[$binding['surface']['assetId']];
            $image = Page::where('story_id', $story->id)->where('public_id', $binding['id'])->first()?->currentImage;
            if (! $image || ! is_file($image->getPath()) || hash_file('sha256', $image->getPath()) !== $asset['sha256']) {
                throw new InvalidArgumentException("Page {$binding['id']}: this host's picture is not the one the contours were drawn on.");
            }
            $surfaces[$binding['id']] = Version::sha1($image);
        }
        $story->forceFill(['interaction' => $profile, 'interaction_surfaces' => $surfaces ?: null])->save();
    }

    /** The second step: one picture the receiver asked for, checked against the hash it asked for. */
    public function attachImage(Story $story, string $pageId, UploadedFile|string $file, string $sha1, ?string $name = null): Media
    {
        $page = Page::where('story_id', $story->id)->where('public_id', $pageId)->firstOrFail();
        $path = $file instanceof UploadedFile ? $file->getRealPath() : $file;
        if (! hash_equals(strtolower($sha1), (string) sha1_file($path))) {
            throw new InvalidArgumentException('The file does not match the announced hash.');
        }
        $media = $this->images->addVersion($page, $file, $name);
        $page->getMedia(Page::VERSIONS)->reject(fn (Media $m) => $m->id === $media->id)->each->delete();

        return $media;
    }

    /** Pages are detached first, so rebuilding the tree never cascades into them. */
    private function nodes(Story $story, array $incoming): array
    {
        Page::where('story_id', $story->id)->update(['node_id' => null]);
        // Rebuilt from scratch each time: the tree is a few dozen rows, and a fresh build cannot drift.
        Node::where('story_id', $story->id)->delete();

        $made = [];
        foreach ($incoming as $n) {
            if (! in_array($n['kind'] ?? null, [Node::VOLUME, Node::CHAPTER], true) || empty($n['id'])) {
                continue;
            }
            $node = (new Node)->forceFill(['public_id' => (string) $n['id'], 'story_id' => $story->id,
                'kind' => $n['kind'], 'title' => trim((string) ($n['title'] ?? '')) ?: '—']);
            $parent = isset($n['parent']) ? ($made[$n['parent']] ?? null) : null;
            $parent ? $node->appendToNode($parent)->save() : $node->saveAsRoot();
            $made[$node->public_id] = $node;
        }

        return $made;
    }

    private function pages(Story $story, array $incoming, array $nodes): array
    {
        $keep = [];
        $need = [];
        $position = [];
        foreach ($incoming as $p) {
            $pid = (string) ($p['id'] ?? '');
            if ($pid === '') {
                continue;
            }
            $page = Page::where('public_id', $pid)->first();
            if ($page && $page->story_id !== $story->id) {
                continue;   // an id from another story — not ours to move
            }
            $node = isset($p['node']) ? ($nodes[$p['node']] ?? null) : null;
            $key = $node?->id ?? 0;
            $position[$key] = ($position[$key] ?? 0) + 1;

            $fields = array_intersect_key($p, array_flip(Snapshot::PAGE_FIELDS));
            if (! in_array($fields['type'] ?? 'image', Page::TYPES, true)) {
                $fields['type'] = 'image';
            }
            $page ??= (new Page)->forceFill(['public_id' => $pid, 'story_id' => $story->id]);
            $page->forceFill($fields + ['node_id' => $node?->id, 'position' => $position[$key], 'status' => Page::READY, 'hidden' => false])->save();
            $keep[] = $page->id;

            $sha1 = $p['image']['sha1'] ?? null;
            $current = $page->currentImage;
            if (! $sha1) {
                $page->getMedia(Page::VERSIONS)->each->delete();
                $page->forceFill(['current_media_id' => null])->save();
            } elseif (! $current || Version::sha1($current) !== $sha1) {
                $need[] = ['page' => $pid, 'sha1' => $sha1];
            }
        }
        // Gone on the author's side (unpublished, hidden, back to draft, deleted) — gone here, files too.
        Page::where('story_id', $story->id)->whereNotIn('id', $keep ?: [0])->get()->each->delete();

        return $need;
    }
}
