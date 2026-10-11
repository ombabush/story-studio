<?php

namespace Ombabush\StoryStudio\Actions;

use Ombabush\StoryStudio\Models\Page;
use Ombabush\StoryStudio\Models\Progress;
use Ombabush\StoryStudio\Models\Story;

/** Remembers the page, not its number: after any reorder "continue" opens the same material. */
class ReadingProgress
{
    public function save(Story $story, string $readerKey, Page $page): void
    {
        Progress::updateOrCreate(['story_id' => $story->id, 'reader_key' => $readerKey], ['page_id' => $page->id]);
    }

    public function last(Story $story, string $readerKey): ?Page
    {
        return Progress::where('story_id', $story->id)->where('reader_key', $readerKey)->first()?->page;
    }
}
