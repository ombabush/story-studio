<?php

namespace Ombabush\StoryStudio\Models;

use Illuminate\Database\Eloquent\Model;

/** A reader's own settings: general (story_id null) or for one edition. Private, never published. */
class ReaderPref extends Model
{
    public const BASE_TABLE = 'reader_prefs';

    protected $guarded = ['id'];

    protected $casts = ['prefs' => 'array'];

    public function getTable(): string
    {
        return config('story-studio.table_prefix', 'ss_').self::BASE_TABLE;
    }
}
