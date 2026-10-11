<?php

namespace Ombabush\StoryStudio\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Ombabush\StoryStudio\Contracts\StoryAuthorizer;
use Ombabush\StoryStudio\Models\Page;
use Ombabush\StoryStudio\Models\Story;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Every read path (reader page, page chunks, media, progress) goes through here, so a direct link to
 * a page or a file is checked exactly like the reader itself.
 */
class Access
{
    public function __construct(private StoryAuthorizer $authorizer) {}

    public function canManage(?Authenticatable $user, Story $story): bool
    {
        return $this->authorizer->canManage($user, $story);
    }

    public function canUseStudio(?Authenticatable $user): bool
    {
        return $this->authorizer->canUseStudio($user);
    }

    /** Preview (drafts visible, marked) only for someone who may edit the story. */
    public function previewing(?Authenticatable $user, Story $story, bool $asked): bool
    {
        return $asked && $this->canManage($user, $story);
    }

    public function canRead(?Authenticatable $user, Story $story): bool
    {
        return $story->isPublished() || $this->canManage($user, $story);
    }

    public function canSeePage(?Authenticatable $user, Page $page, bool $preview): bool
    {
        if (! $this->canRead($user, $page->story)) {
            return false;
        }
        if ($page->isPublic()) {
            return true;
        }

        // A draft shows only in the author's preview; a hidden page shows nowhere in reading.
        return $preview && ! $page->hidden && $this->canManage($user, $page->story);
    }

    /** Media: the current image of a visible page; older versions only for the editor. */
    public function canSeeImage(?Authenticatable $user, Page $page, Media $image, bool $preview): bool
    {
        if ($this->canManage($user, $page->story)) {
            return true;
        }

        return (int) $page->current_media_id === (int) $image->id && $this->canSeePage($user, $page, $preview);
    }
}
