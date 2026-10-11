<?php

namespace Ombabush\StoryStudio\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Ombabush\StoryStudio\Contracts\StoryAuthorizer;
use Ombabush\StoryStudio\Models\Story;

/**
 * Owner edits their own stories; a user passing the host's Gate ability (config manage_ability)
 * edits all of them. A host that defines no ability gets owner-only editing — never "everyone".
 */
class DefaultAuthorizer implements StoryAuthorizer
{
    public function canUseStudio(?Authenticatable $user): bool
    {
        return $user !== null;
    }

    public function canManage(?Authenticatable $user, Story $story): bool
    {
        if ($user === null) {
            return false;
        }
        if ($this->isSuper($user)) {
            return true;
        }

        return $story->owner_id !== null && (string) $story->owner_id === (string) $user->getAuthIdentifier();
    }

    private function isSuper(Authenticatable $user): bool
    {
        $ability = config('story-studio.manage_ability');

        return $ability && Gate::has($ability) && Gate::forUser($user)->allows($ability);
    }
}
