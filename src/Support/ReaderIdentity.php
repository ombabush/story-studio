<?php

namespace Ombabush\StoryStudio\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** A signed-in reader is 'u:<id>'; a guest gets a random token kept in the session. */
class ReaderIdentity
{
    public function key(Request $request): string
    {
        if ($user = $request->user()) {
            return 'u:'.$user->getAuthIdentifier();
        }
        $token = $request->session()->get('story_studio_reader');
        if (! $token) {
            $token = Str::random(32);
            $request->session()->put('story_studio_reader', $token);
        }

        return 'g:'.$token;
    }
}
