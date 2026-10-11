<?php

namespace Ombabush\StoryStudio\View;

use Illuminate\View\Component;
use Ombabush\StoryStudio\Models\Story;
use Ombabush\StoryStudio\Support\Access;
use Ombabush\StoryStudio\Support\ReaderPayload;

/**
 * <x-story-studio::reader :story="$story" /> — embeds a Reader in any page of the host app. Several on
 * one page are fine: each gets its own root and state, the assets are printed once.
 */
class Reader extends Component
{
    public array $payload;

    public string $uid;

    public function __construct(
        public Story $story,
        public ?string $page = null,
        public bool $preview = false,
        public bool $fullscreen = false,
        public ?string $back = null,
        public string $theme = '',
    ) {
        abort_unless(app(Access::class)->canRead(request()->user(), $story), 404);
        $this->payload = app(ReaderPayload::class)->build(request(), $story, $page, $preview);
        $this->payload['back'] = $back;
        $this->uid = 'ssr-'.substr(md5(uniqid('', true)), 0, 8);
        $this->theme = $theme ?: (string) config('story-studio.theme.default', 'dark');
    }

    public function render()
    {
        return view('story-studio::reader.reader');
    }
}
