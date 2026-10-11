<?php

namespace Ombabush\StoryStudio\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Ombabush\StoryStudio\Models\Concerns\StoryStudioModel;

class Story extends Model
{
    use StoryStudioModel;

    public const BASE_TABLE = 'stories';

    protected $guarded = ['id', 'public_id'];

    protected $casts = [
        'settings' => 'array',
        'published_at' => 'datetime',
        'interaction' => 'array',
        'interaction_surfaces' => 'array',
    ];

    public function nodes(): HasMany
    {
        return $this->hasMany(Node::class)->orderBy('_lft');
    }

    public function pages(): HasMany
    {
        return $this->hasMany(Page::class);
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }

    public function isRtl(): bool
    {
        return $this->direction === 'rtl';
    }

    /** The edition's languages and reader views, typed (docs/DATA-MODEL.md §1.1). */
    public function edition(): \Ombabush\StoryStudio\Support\Edition
    {
        return \Ombabush\StoryStudio\Support\Edition::of($this);
    }
}
