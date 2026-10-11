<?php

namespace Ombabush\StoryStudio\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Ombabush\StoryStudio\Models\Concerns\StoryStudioModel;
use Ombabush\StoryStudio\Support\Version;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * A page. Its picture is Spatie media: every upload or replacement is one more item in the 'versions'
 * collection, current_media_id says which one is shown. The original is kept untouched; the reader
 * copy and the grid thumbnail are conversions, rebuildable with media-library:regenerate.
 */
class Page extends Model implements HasMedia
{
    use InteractsWithMedia;
    use StoryStudioModel;

    public const BASE_TABLE = 'pages';

    public const VERSIONS = 'versions';

    public const TYPES = ['image', 'spread', 'panorama', 'text', 'mixed'];

    public const DRAFT = 'draft';

    public const READY = 'ready';

    protected $guarded = ['id', 'public_id'];

    protected $casts = [
        'hidden' => 'boolean',
        'hints' => 'array',
        'regions' => 'array',
        'time_rank' => 'integer',
    ];

    protected static function booted(): void
    {
        // Translations and attachments point at the public id, not a foreign key: they go with the page.
        static::deleted(function (Page $page) {
            \Ombabush\StoryStudio\Support\Texts::forget('page', $page);
            \Ombabush\StoryStudio\Support\Attachments::forget('page', $page);
        });
    }

    public function story(): BelongsTo
    {
        return $this->belongsTo(Story::class);
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    public function currentImage(): BelongsTo
    {
        return $this->belongsTo(config('media-library.media_model', Media::class), 'current_media_id');
    }

    /** Every version of the picture, newest first. */
    public function versions()
    {
        return $this->getMedia(self::VERSIONS)->sortByDesc(fn (Media $m) => Version::number($m))->values();
    }

    public function registerMediaCollections(): void
    {
        // Originals never live on a public disk: they go out through the reader's media route only.
        $this->addMediaCollection(self::VERSIONS)->useDisk((string) config('story-studio.storage.disk', 'local'));
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        // A file cut short (an interrupted download) is kept as a version and shown as a grey stand-in;
        // converting it would only throw.
        if ($media && Version::broken($media)) {
            return;
        }
        $display = (int) config('story-studio.storage.display_max', 2560);
        $thumb = (int) config('story-studio.storage.thumb_max', 480);

        $this->addMediaConversion('display')->fit(Fit::Max, $display, $display)->format('jpg')->quality(86)
            ->performOnCollections(self::VERSIONS)->nonQueued();
        $this->addMediaConversion('thumb')->fit(Fit::Max, $thumb, $thumb)->format('jpg')->quality(80)
            ->performOnCollections(self::VERSIONS)->nonQueued();
    }

    /**
     * What a reader without editing rights may see.
     *
     * Inside the model `$this->hidden` is Eloquent's own Model::$hidden (the serialization list, an
     * empty array), not the column — `! []` is true, and every hidden page went out to readers. Read
     * the column through getAttribute(); from outside, `$page->hidden` reaches the column as usual.
     */
    public function isPublic(): bool
    {
        return $this->status === self::READY && ! $this->getAttribute('hidden');
    }

    public function hasText(): bool
    {
        return trim((string) $this->body) !== '' || trim((string) $this->note) !== '';
    }
}
