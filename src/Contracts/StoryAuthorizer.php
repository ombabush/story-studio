<?php

namespace Ombabush\StoryStudio\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Ombabush\StoryStudio\Models\Story;

/**
 * The one place a host app decides who edits what. The package never reads roles, teams or an
 * admin flag itself — it asks this.
 */
interface StoryAuthorizer
{
    /** May this user open the Studio at all and create stories? */
    public function canUseStudio(?Authenticatable $user): bool;

    /** May this user edit the story, see its drafts and its internal layer? */
    public function canManage(?Authenticatable $user, Story $story): bool;
}
