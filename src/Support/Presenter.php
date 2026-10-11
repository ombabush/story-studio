<?php

namespace Ombabush\StoryStudio\Support;

use Ombabush\StoryStudio\Interaction\ProfileProjector;
use Ombabush\StoryStudio\Models\Page;
use Ombabush\StoryStudio\Models\Story;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * The only place a page becomes JSON for the Reader. Public output is a whitelist: the internal layer
 * (author's note, balloon dialogue), statuses, versions and file paths are added only for an editor.
 */
class Presenter
{
    public function manifestEntry(Page $page, array $chapters): array
    {
        $chapter = $chapters[$page->node_id] ?? null;

        $out = [
            'id' => $page->public_id,
            'n' => $page->n,
            'ch' => $chapter ? $chapter['node']->public_id : null,
            'type' => $page->type,
            'ar' => $this->ratio($page),
            'rank' => $page->effective_rank,
        ];
        // Pairing is decided from the manifest, before the page itself has loaded.
        if ($pair = PageHints::clean($page->hints)['pair'] ?? null) {
            $out['pair'] = $pair;
        }

        return $out;
    }

    /** @param  array<string, true>|null  $visible  pages this reader may open — enables interactions */
    public function page(Page $page, array $chapters, bool $editor, bool $preview, ?array $visible = null): array
    {
        $chapter = $chapters[$page->node_id] ?? null;
        $image = $page->currentImage;
        $out = [
            'id' => $page->public_id,
            'n' => $page->n,
            'nInChapter' => $page->n_in_chapter,
            'chapter' => $chapter ? ['id' => $chapter['node']->public_id, 'no' => $chapter['no'], 'title' => $chapter['node']->title,
                'volume' => $chapter['volume']?->title] : null,
            'type' => $page->type,
            'ar' => $this->ratio($page),
            'image' => $image && ! Version::broken($image) ? $this->imageUrls($page->story, $image, $preview) : null,
            'missing' => in_array($page->type, ['image', 'spread', 'panorama'], true) && (! $image || Version::broken($image)),
            'caption' => $page->caption,
            'paragraphs' => $this->paragraphs($page->body),
            'alt' => $page->alt,
            'time' => array_filter(['label' => $page->time_label, 'place' => $page->time_place, 'who' => $page->time_who]),
            'draft' => $page->status !== Page::READY,
            'hints' => PageHints::clean($page->hints),
            // Translations of the public fields, shaped like the original (DATA-MODEL §1.2).
            'tr' => (object) $this->translations($page, $editor),
        ];
        if ($visible !== null && ($interaction = app(ProfileProjector::class)->page($page->story, $page, $visible, $out))) {
            $out['interaction'] = $interaction;
        }
        if ($editor) {
            $out['internal'] = [
                'note' => $page->note,
                'dialogue' => array_values(array_filter(array_map('trim', explode("\n", (string) $page->dialogue)))),
                'status' => $page->status,
                'hidden' => $page->hidden,
            ];
        }

        return $out;
    }

    private function translations(Page $page, bool $editor): array
    {
        $out = [];
        foreach (app(Texts::class)->forReader($page->story, 'page', $page->public_id, $editor) as $lang => $f) {
            $out[$lang] = array_filter([
                'caption' => $f['caption'] ?? null,
                'paragraphs' => isset($f['body']) ? $this->paragraphs($f['body']) : null,
                'alt' => $f['alt'] ?? null,
                'time' => array_filter(['label' => $f['time_label'] ?? null, 'place' => $f['time_place'] ?? null]) ?: null,
                'dialogue' => $editor && isset($f['dialogue']) ? array_values(array_filter(array_map('trim', explode("\n", $f['dialogue'])))) : null,
            ], fn ($v) => $v !== null);
        }

        return $out;
    }

    public function imageUrls(Story $story, Media $image, bool $preview): array
    {
        $params = fn (string $size) => array_filter(['story' => $story->public_id, 'image' => $image->uuid, 'size' => $size,
            'preview' => $preview ? 1 : null, 'v' => substr((string) Version::sha1($image), 0, 8)]);

        return [
            'src' => route(config('story-studio.routes.reader.name').'media', $params('display')),
            'thumb' => route(config('story-studio.routes.reader.name').'media', $params('thumb')),
            'width' => Version::width($image),
            'height' => Version::height($image),
        ];
    }

    public function ratio(Page $page): float
    {
        $image = $page->currentImage;
        if ($image && ! Version::broken($image) && Version::width($image) && Version::height($image)) {
            return round(Version::width($image) / Version::height($image), 4);
        }

        return match ($page->type) {
            'spread' => 1.4,
            'panorama' => 3.0,
            default => 0.7,
        };
    }

    /** Blank line = new paragraph. Plain text only: the Reader escapes it, nothing is rendered as HTML. */
    public function paragraphs(?string $body): array
    {
        $body = trim(str_replace("\r\n", "\n", (string) $body));

        return $body === '' ? [] : array_values(array_filter(array_map('trim', preg_split('/\n\s*\n/', $body))));
    }
}
