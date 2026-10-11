<?php

namespace Ombabush\StoryStudio\Support;

use Illuminate\Http\Request;
use Ombabush\StoryStudio\Actions\ReadingProgress;
use Ombabush\StoryStudio\Models\Page;
use Ombabush\StoryStudio\Models\Story;

/**
 * What one Reader instance boots with: a light manifest of every readable page (id, type, ratio, chapter,
 * story time) and full content only for a window around the starting page — the rest comes in chunks.
 */
class ReaderPayload
{
    public function __construct(
        private Access $access,
        private ReadingOrder $order,
        private Presenter $presenter,
        private ReadingProgress $progress,
        private ReaderIdentity $identity,
        private Texts $texts,
        private Attachments $attachments,
        private ReaderPrefs $prefs,
        private ReaderCatalog $catalog,
    ) {}

    public function build(Request $request, Story $story, ?string $startId = null, bool $askPreview = false): array
    {
        $user = $request->user();
        $preview = $this->access->previewing($user, $story, $askPreview);
        $editor = $this->access->canManage($user, $story);
        ['pages' => $pages, 'chapters' => $chapters] = $this->order->build($story,
            fn (Page $p) => $p->isPublic() || ($preview && ! $p->hidden));

        $index = 0;
        $startId ??= $this->progress->last($story, $this->identity->key($request))?->public_id;
        if ($startId) {
            $found = $pages->search(fn (Page $p) => $p->public_id === $startId);
            $index = $found === false ? 0 : $found;
        }
        $half = intdiv((int) config('story-studio.reader.initial_window', 6), 2);
        $window = $pages->slice(max(0, $index - $half), $half * 2 + 1);

        $title = fn (string $subject, $node) => (object) array_map(fn ($f) => $f['title'] ?? null,
            array_filter($this->texts->forReader($story, $subject, $node->public_id, $editor), fn ($f) => isset($f['title'])));
        $chapterList = [];
        $nodes = [];
        foreach ($chapters as $c) {
            $chapterList[] = ['id' => $c['node']->public_id, 'no' => $c['no'], 'title' => $c['node']->title, 'volume' => $c['volume']?->title,
                'tr' => $title('node', $c['node']), 'volumeTr' => $c['volume'] ? $title('node', $c['volume']) : (object) []];
            $nodes[$c['node']->public_id] = true;
        }
        $hasTime = $pages->contains(fn (Page $p) => $p->time_rank !== null);
        $visible = $pages->mapWithKeys(fn (Page $p) => [$p->public_id => true])->all();
        $route = config('story-studio.routes.reader.name');
        $prefs = $this->prefs->for($this->identity->key($request), $story);
        $uiLang = $this->catalog->uiLanguage($prefs['uiLang'] ?? null);

        return [
            'story' => ['id' => $story->public_id, 'title' => $story->title, 'authors' => $story->authors, 'direction' => $story->direction,
                'published' => $story->isPublished(), 'tr' => (object) $this->texts->forReader($story, 'story', $story->public_id, $editor)],
            // What this Reader can do and what this edition offers; the reader's own settings on top.
            'catalog' => $this->catalog->toArray($uiLang),
            'edition' => $story->edition()->toArray(),
            'prefs' => (object) $prefs,
            'music' => $this->attachments->forReader($story, $visible, $nodes),
            'interaction' => app(\Ombabush\StoryStudio\Interaction\ProfileProjector::class)->enabled($story),
            'manifest' => $pages->map(fn (Page $p) => $this->presenter->manifestEntry($p, $chapters))->values()->all(),
            'pages' => $window->map(fn (Page $p) => $this->presenter->page($p, $chapters, $editor, $preview, $visible))->values()->all(),
            'chapters' => $chapterList,
            'start' => $pages[$index]->public_id ?? null,
            // The "as told / as it happened" switch exists only when there is a timeline to switch to.
            'timeline' => $hasTime,
            'preview' => $preview,
            'editor' => $editor,
            'endpoints' => [
                'pages' => route($route.'pages', ['story' => $story->public_id] + ($preview ? ['preview' => 1] : [])),
                'progress' => route($route.'progress', ['story' => $story->public_id]),
                'marks' => route($route.'marks', ['story' => $story->public_id]),
                'prefs' => route($route.'prefs', ['story' => $story->public_id]),
                'studio' => $editor && config('story-studio.routes.studio.enabled')
                    ? route(config('story-studio.routes.studio.name').'edit', ['story' => $story->public_id]) : null,
            ],
            // The reader's own bookmarks and notes, only on pages this reader can open.
            'marks' => $this->marks($request, $story),
            'tuning' => ['chunk' => (int) config('story-studio.reader.chunk', 12), 'ahead' => (int) config('story-studio.reader.preload_ahead', 3)],
        ];
    }

    /** @return array<int, array{page: string, kind: string, text: ?string, at: string}> this reader's marks */
    public function marks(Request $request, Story $story): array
    {
        $user = $request->user();

        return \Ombabush\StoryStudio\Models\Mark::where('story_id', $story->id)->where('reader_key', $this->identity->key($request))
            ->with('page.story')->orderBy('updated_at')->get()
            ->filter(fn ($m) => $m->page && $this->access->canSeePage($user, $m->page, true))
            ->map(fn ($m) => ['page' => $m->page->public_id, 'kind' => $m->kind, 'text' => $m->text, 'at' => $m->updated_at->toIso8601String()])
            ->values()->all();
    }

    /** A chunk of pages by id — each one re-checked, so a guessed id returns nothing. */
    public function chunk(Request $request, Story $story, array $ids, bool $askPreview): array
    {
        $user = $request->user();
        $preview = $this->access->previewing($user, $story, $askPreview);
        $editor = $this->access->canManage($user, $story);
        $want = array_flip(array_slice($ids, 0, 2 * (int) config('story-studio.reader.chunk', 12)));
        ['pages' => $readable, 'chapters' => $chapters] = $this->order->build($story,
            fn (Page $p) => $p->isPublic() || ($preview && ! $p->hidden));
        // Interactions may jump only into pages this reader can open, so the whole readable set is the
        // reference, not just the requested chunk.
        $visible = $readable->mapWithKeys(fn (Page $p) => [$p->public_id => true])->all();

        return $readable->filter(fn (Page $p) => isset($want[$p->public_id]))
            ->map(fn (Page $p) => $this->presenter->page($p, $chapters, $editor, $preview, $visible))->values()->all();
    }
}
