<?php

namespace Ombabush\StoryStudio\Models;

use Illuminate\Database\Eloquent\Model;

/** Translated fields of one subject (story, node or page) in one language — see Support\Texts. */
class Text extends Model
{
    public const BASE_TABLE = 'texts';

    protected $guarded = ['id'];

    protected $casts = ['fields' => 'array'];

    public function getTable(): string
    {
        return config('story-studio.table_prefix', 'ss_').self::BASE_TABLE;
    }
}
