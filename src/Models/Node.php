<?php

namespace Ombabush\StoryStudio\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kalnoy\Nestedset\NodeTrait;
use Ombabush\StoryStudio\Models\Concerns\StoryStudioModel;

/**
 * A volume or a chapter — the table of contents, a kalnoy/nestedset tree with one tree per story.
 * Both levels are optional: a short story can hold its pages directly. Pages are not tree nodes: they
 * are leaves with their own position, so moving a page never rewrites the whole book's _lft/_rgt.
 */
class Node extends Model
{
    use NodeTrait;
    use StoryStudioModel;

    public const BASE_TABLE = 'nodes';

    public const VOLUME = 'volume';

    public const CHAPTER = 'chapter';

    protected $guarded = ['id', 'public_id', '_lft', '_rgt'];

    /** One tree per story: siblings and bounds are counted inside the story only. */
    protected function getScopeAttributes(): array
    {
        return ['story_id'];
    }

    protected static function booted(): void
    {
        static::deleted(function (Node $node) {
            \Ombabush\StoryStudio\Support\Texts::forget('node', $node);
            \Ombabush\StoryStudio\Support\Attachments::forget('node', $node);
        });
    }

    public function story(): BelongsTo
    {
        return $this->belongsTo(Story::class);
    }

    public function pages(): HasMany
    {
        return $this->hasMany(Page::class)->orderBy('position');
    }
}
