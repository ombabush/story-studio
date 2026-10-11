<?php

namespace Ombabush\StoryStudio\Models;

use Illuminate\Database\Eloquent\Model;
use Ombabush\StoryStudio\Models\Concerns\StoryStudioModel;

/**
 * A typed item on the edition, a chapter or a page: a music track, a listen-elsewhere link. A new kind
 * is an entry in Support\Attachments, not a column (docs/DATA-MODEL.md §1.3).
 */
class Attachment extends Model
{
    use StoryStudioModel;

    public const BASE_TABLE = 'attachments';

    public const SCOPES = ['story', 'node', 'page'];

    protected $guarded = ['id', 'public_id'];

    protected $casts = ['data' => 'array', 'position' => 'integer'];
}
