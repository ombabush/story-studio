<?php

namespace Ombabush\StoryStudio\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Progress extends Model
{
    public const BASE_TABLE = 'progress';

    protected $guarded = ['id'];

    public function getTable(): string
    {
        return config('story-studio.table_prefix', 'ss_').self::BASE_TABLE;
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }
}
