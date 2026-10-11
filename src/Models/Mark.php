<?php

namespace Ombabush\StoryStudio\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A reader's bookmark or note on a page — private to that reader (see the migration). */
class Mark extends Model
{
    public const BASE_TABLE = 'marks';

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
