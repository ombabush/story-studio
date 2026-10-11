<?php

namespace Ombabush\StoryStudio\Models\Concerns;

use Illuminate\Database\Eloquent\Concerns\HasUlids;

/**
 * Prefixed table + a stable public id. The numeric id never leaves the server; URLs, progress and
 * links carry public_id, so a reorder or a re-import never sends a reader to a different page.
 */
trait StoryStudioModel
{
    use HasUlids;

    public function getTable(): string
    {
        return config('story-studio.table_prefix', 'ss_').static::BASE_TABLE;
    }

    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
