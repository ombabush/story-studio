<?php

namespace Ombabush\StoryStudio\Livewire\Studio;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use Ombabush\StoryStudio\Actions\StoryEditor;
use Ombabush\StoryStudio\Models\Node;
use Ombabush\StoryStudio\Models\Page;
use Ombabush\StoryStudio\Models\Story;
use Ombabush\StoryStudio\Support\Access;
use Ombabush\StoryStudio\Support\Presenter;
use Ombabush\StoryStudio\Support\ReadingOrder;
use Ombabush\StoryStudio\Support\UploadPlan;
use Ombabush\StoryStudio\Support\Version;

/**
 * The edition editor: tree · page grid · inspector. Thin on purpose — every change is a StoryEditor call;
 * this class only checks access, keeps the screen state and turns results into toasts.
 *
 * Frequent reversible changes (move, status) run without a question and offer Undo; the snapshot lives
 * in the session under a random token, so the browser can ask for an undo but cannot write one.
 * Everything destructive or outward-facing (delete, publish, merge, split) goes through the confirm dialog.
 */
#[Layout('story-studio::layouts.studio')]
class Editor extends Component
{
    use WithFileUploads;

    #[Locked]
    public string $storyId;

    /** 'all', or a node public id. */
    public string $node = 'all';

    public string $filter = 'all';

    public ?string $selected = null;

    public array $form = [];

    public array $uploads = [];

    /** Relative paths of the files in $uploads (folder upload), same order. Client-supplied, used only as labels. */
    public array $uploadPaths = [];

    /** Holds server temp paths — locked, so the browser can read it but never point it at another file. */
    #[Locked]
    public ?array $plan = null;

    public string $uploadWhere = 'here';

    public $replacement = null;

    public array $settings = [];

    public ?array $toast = null;

    public bool $showVersions = false;

    /** The Interaction layer of the selected page is open (SR-02). */
    public bool $ixOpen = false;

    /** Why the last export was refused, in the author's words (empty = nothing to show). */
    public array $exportErrors = [];

    public function mount(Story $story): void
    {
        abort_unless(app(Access::class)->canManage(auth()->user(), $story), 404);
        $this->storyId = $story->public_id;
        $first = app(ReadingOrder::class)->build($story)['pages']->first();
        $this->selectPage($first?->public_id);
    }

    public function story(): Story
    {
        $story = Story::where('public_id', $this->storyId)->firstOrFail();
        abort_unless(app(Access::class)->canManage(auth()->user(), $story), 404);

        return $story;
    }

    private function editor(): StoryEditor
    {
        return app(StoryEditor::class);
    }

    private function nodeModel(?string $publicId): ?Node
    {
        if (! $publicId || $publicId === 'all' || $publicId === 'root') {
            return null;
        }

        return Node::where('story_id', $this->story()->id)->where('public_id', $publicId)->firstOrFail();
    }

    private function pageModel(?string $publicId): ?Page
    {
        return $publicId ? Page::where('story_id', $this->story()->id)->where('public_id', $publicId)->first() : null;
    }

    /** The chapter new material goes into: the selected chapter, else the selected page's, else the last one. */
    private function targetChapter(): ?Node
    {
        $node = $this->nodeModel($this->node);
        if ($node?->kind === Node::CHAPTER) {
            return $node;
        }
        if ($page = $this->pageModel($this->selected)) {
            return $page->node;
        }
        $story = $this->story();

        return Node::where('story_id', $story->id)->where('kind', Node::CHAPTER)
            ->when($node, fn ($q) => $q->where('parent_id', $node->id))->defaultOrder('desc')->first();
    }

    // ── selection & inspector ───────────────────────────────────────────────────────────────

    public function selectNode(string $node): void
    {
        $this->node = $node;
    }

    public function selectPage(?string $id): void
    {
        $page = $this->pageModel($id);
        $this->selected = $page?->public_id;
        $this->showVersions = false;
        $this->form = $page ? [
            'type' => $page->type,
            'status' => $page->status,
            'hidden' => $page->hidden,
            'caption' => (string) $page->caption,
            'body' => (string) $page->body,
            'note' => (string) $page->note,
            'dialogue' => (string) $page->dialogue,
            'alt' => (string) $page->alt,
            'time_label' => (string) $page->time_label,
            'time_rank' => $page->time_rank,
            'time_place' => (string) $page->time_place,
            'time_who' => (string) $page->time_who,
            'hints' => ['pair' => \Ombabush\StoryStudio\Support\PageHints::clean($page->hints)['pair'] ?? 'auto',
                'pan' => ['seconds' => \Ombabush\StoryStudio\Support\PageHints::clean($page->hints)['pan']['seconds'] ?? '']],
        ] : [];
    }

    /** Fields save themselves; the inspector shows "● сохранено". */
    public function updatedForm($value, string $key): void
    {
        if (! ($page = $this->pageModel($this->selected))) {
            return;
        }
        // Layout hints are one typed field (PageHints); its parts arrive as hints.pair, hints.pan.seconds.
        if (str_starts_with($key, 'hints.')) {
            $this->editor()->updatePage($page, ['hints' => $this->form['hints'] ?? []]);

            return;
        }
        if (in_array($key, StoryEditor::PAGE_FIELDS, true)) {
            $this->editor()->updatePage($page, [$key => $value]);
        }
    }

    public function stepPage(int $delta): void
    {
        $ids = $this->visibleIds();
        $at = array_search($this->selected, $ids, true);
        $next = $ids[$at === false ? 0 : max(0, min(count($ids) - 1, $at + $delta))] ?? null;
        $this->selectPage($next);
    }

    // ── tree ────────────────────────────────────────────────────────────────────────────────

    public function addVolume(): void
    {
        $node = $this->editor()->addNode($this->story(), Node::VOLUME, 'Новый том');
        $this->flash('Том добавлен');
        $this->node = $node->public_id;
    }

    public function addChapter(?string $volume = null): void
    {
        $parent = $this->nodeModel($volume);
        $chapter = $this->editor()->addNode($this->story(), Node::CHAPTER, 'Новая глава', $parent?->kind === Node::VOLUME ? $parent : null);
        $this->node = $chapter->public_id;
        $this->flash('Глава добавлена');
    }

    public function renameNode(string $id, string $title): void
    {
        $this->editor()->renameNode($this->nodeModel($id), $title);
    }

    public function deleteNode(string $id): void
    {
        $node = $this->nodeModel($id);
        $title = $node->title;
        $this->editor()->deleteNode($node);
        $this->node = 'all';
        $this->selectPage($this->visibleIds()[0] ?? null);
        $this->flash("Удалено: «{$title}»");
    }

    public function mergeNode(string $id): void
    {
        $node = $this->nodeModel($id);
        $next = $this->editor()->mergeWithNext($node);
        $this->flash($next ? "«{$next->title}» слита с «{$node->title}»" : 'Следующей главы нет');
    }

    public function splitHere(): void
    {
        if ($page = $this->pageModel($this->selected)) {
            $new = $this->editor()->splitBefore($page);
            $this->node = $new->public_id;
            $this->flash('Глава разрезана перед стр. '.$this->numberOf($page->public_id));
        }
    }

    // ── pages ───────────────────────────────────────────────────────────────────────────────

    /** Drag-and-drop and the "Move to…" dialog both land here. Undo-able, no question asked. */
    public function movePages(array $ids, string $target, ?string $before = null): void
    {
        $story = $this->story();
        $node = $this->nodeModel($target);
        $snapshot = $this->editor()->movePages($story, $ids, $node, $before);
        if ($snapshot) {
            $label = $node ? $node->title : $story->title;
            $this->flash('Перемещено '.$this->plural(count($ids)).' в «'.$label.'»', $snapshot);
        }
    }

    public function setStatus(array $ids, string $status): void
    {
        $map = ['ready' => ['ready', false], 'draft' => ['draft', null], 'hidden' => [null, true], 'shown' => [null, false]];
        if (! isset($map[$status])) {
            return;
        }
        [$s, $hidden] = $map[$status];
        $snapshot = $this->editor()->setStatus($this->story(), $ids, $s, $hidden);
        $this->selectPage($this->selected);
        $this->flash('Статус: '.$this->plural(count($ids)), $snapshot);
    }

    public function deletePages(array $ids): void
    {
        $n = $this->editor()->deletePages($this->story(), $ids);
        if (in_array($this->selected, $ids, true)) {
            $this->selectPage($this->visibleIds()[0] ?? null);
        }
        $this->flash('Удалено '.$this->plural($n));
    }

    public function addTextPage(): void
    {
        $page = $this->editor()->addTextPage($this->story(), $this->targetChapter(), ['caption' => 'Новая текстовая страница']);
        $this->selectPage($page->public_id);
        $this->flash('Текстовая страница добавлена');
    }

    public function updatedReplacement(): void
    {
        $this->validate(['replacement' => 'image|max:'.(1024 * (int) config('story-studio.storage.max_upload_mb', 40))]);
        if ($page = $this->pageModel($this->selected)) {
            $this->editor()->replaceImage($page, $this->replacement, $this->replacement->getClientOriginalName());
            $this->flash('Картинка заменена · старая — в «Истории версий»');
        }
        $this->replacement = null;
    }

    public function useVersion(string $imageId): void
    {
        $page = $this->pageModel($this->selected);
        $image = $page?->getMedia(Page::VERSIONS)->firstWhere('uuid', $imageId);
        if ($image) {
            $this->editor()->useVersion($page, $image);
            $this->flash('Вернули v'.Version::number($image));
        }
    }

    // ── upload ──────────────────────────────────────────────────────────────────────────────

    public function updatedUploads(): void
    {
        $this->validate(['uploads.*' => 'file|max:'.(1024 * (int) config('story-studio.storage.max_upload_mb', 40) * 8)]);
        $this->plan = app(UploadPlan::class)->make($this->story(), $this->uploads, $this->uploadPaths);
        $this->uploadWhere = count($this->plan['groups']) > 1 || $this->targetChapter() === null ? 'new' : 'here';
    }

    public function toggleSkip(int $group, int $file): void
    {
        if (isset($this->plan['groups'][$group]['files'][$file])) {
            $this->plan['groups'][$group]['files'][$file]['skip'] = ! $this->plan['groups'][$group]['files'][$file]['skip'];
        }
    }

    public function shiftFile(int $group, int $file, int $delta): void
    {
        $files = $this->plan['groups'][$group]['files'] ?? [];
        $to = $file + $delta;
        if (isset($files[$file], $files[$to])) {
            [$files[$file], $files[$to]] = [$files[$to], $files[$file]];
            $this->plan['groups'][$group]['files'] = $files;
        }
    }

    public function cancelUpload(): void
    {
        app(UploadPlan::class)->discard($this->plan);
        $this->reset('uploads', 'uploadPaths', 'plan');
    }

    public function commitUpload(): void
    {
        if (! $this->plan) {
            return;
        }
        $story = $this->story();
        $here = $this->uploadWhere === 'here' ? $this->targetChapter() : null;
        $count = 0;
        DB::transaction(function () use ($story, $here, &$count) {
            foreach ($this->plan['groups'] as $group) {
                $files = array_values(array_filter($group['files'], fn ($f) => ! $f['skip']));
                if (! $files) {
                    continue;
                }
                $node = $here ?? $this->editor()->addNode($story, Node::CHAPTER, $group['chapter']);
                $items = array_map(fn ($f) => ['file' => $f['path'], 'name' => $f['name'], 'type' => $f['type']], $files);
                $this->editor()->addImagePages($story, $node, $items);
                $count += count($items);
                $this->node = $node->public_id;
            }
        });
        $this->cancelUpload();
        $this->flash('Загружено '.$this->plural($count).' · как черновики');
    }

    // ── interactions ────────────────────────────────────────────────────────────────────────

    /**
     * «These contours are right for the new picture»: asked through the confirm dialog, because it
     * puts the regions back in front of readers.
     */
    public function confirmSurface(string $pageId): void
    {
        $story = $this->story();
        $page = $this->pageModel($pageId);
        if (! $page) {
            return;
        }
        app(\Ombabush\StoryStudio\Actions\EditInteractions::class)
            ->confirmSurface($story, \Ombabush\StoryStudio\Actions\EditInteractions::version($story), $page->public_id);
        $this->dispatch('ix-refresh');
        $this->flash('Контуры привязаны к новой картинке');
    }

    // ── story ───────────────────────────────────────────────────────────────────────────────

    public function openSettings(): void
    {
        $story = $this->story();
        $edition = $story->edition();
        $this->settings = ['title' => $story->title, 'authors' => (string) $story->authors,
            'description' => (string) $story->description, 'direction' => $story->direction,
            'original' => $edition->original, 'translations' => implode(', ', $edition->translated()),
            'views' => array_fill_keys($edition->views, true), 'defaultView' => $edition->defaultView];
    }

    public function saveSettings(): void
    {
        $this->validate(['settings.title' => 'required|string|max:255', 'settings.authors' => 'nullable|string|max:255',
            'settings.description' => 'nullable|string|max:5000', 'settings.direction' => 'in:ltr,rtl',
            'settings.original' => ['required', 'string', 'max:35', fn ($a, $v, $fail) => \Ombabush\StoryStudio\Support\Edition::tag($v) ? null : $fail('Код языка: ru, en, pt-BR…')]]);
        $offered = array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', (string) ($this->settings['translations'] ?? '')))));
        $bad = array_filter($offered, fn ($l) => ! \Ombabush\StoryStudio\Support\Edition::tag($l));
        if ($bad) {
            $this->addError('settings.translations', 'Не код языка: '.implode(', ', $bad));

            return;
        }
        $views = array_keys(array_filter((array) ($this->settings['views'] ?? [])));
        if (! $views) {
            $this->addError('settings.views', 'Оставьте читателю хотя бы один вид.');

            return;
        }
        $this->editor()->updateStory($this->story(), array_intersect_key($this->settings, array_flip(['title', 'authors', 'description', 'direction'])) + [
            'languages' => ['original' => $this->settings['original'], 'offered' => array_merge([$this->settings['original']], $offered)],
            'views' => ['offered' => $views, 'default' => $this->settings['defaultView'] ?? null],
        ]);
        $this->settings = [];
        $this->flash('Настройки сохранены');
    }

    /** Asked through the confirm dialog: the item disappears for readers (and from Cloud after publishing). */
    public function removeAttachment(string $id): void
    {
        $item = \Ombabush\StoryStudio\Models\Attachment::where('story_id', $this->story()->id)->where('public_id', $id)->first();
        if ($item) {
            $label = $item->data['title'] ?? $item->data['label'] ?? '';
            $item->delete();
            $this->dispatch('ss-attachments');
            $this->flash('Убрано: «'.$label.'»');
        }
    }

    /** The edition as a .story.zip (SR-02). Refused with the reasons when the profile has errors. */
    public function exportPackage()
    {
        $story = $this->story();
        $file = storage_path('app/story-studio-tmp/export-'.Str::random(10).'.story.zip');
        \Illuminate\Support\Facades\File::ensureDirectoryExists(dirname($file));
        $result = app(\Ombabush\StoryStudio\Actions\ExportPackage::class)->export($story, $file);
        if ($result['errors']) {
            $this->exportErrors = array_values(array_unique(array_map([Interactions::class, 'human'], $result['errors'])));

            return null;
        }
        $this->exportErrors = [];

        return response()->download($file, Str::slug($story->title ?: 'story').'.story.zip')->deleteFileAfterSend();
    }

    public function publish(): void
    {
        $this->editor()->publish($this->story());
        $this->flash('Издание опубликовано — читатели видят готовые страницы');
    }

    public function unpublish(): void
    {
        $this->editor()->unpublish($this->story());
        $this->flash('Издание снято с публикации');
    }

    // ── undo & toast ────────────────────────────────────────────────────────────────────────

    public function undo(string $token): void
    {
        $snapshot = session()->pull('story-studio.undo.'.$token);
        if (! is_array($snapshot) || ($snapshot['story'] ?? null) !== $this->storyId) {
            $this->toast = ['text' => 'Отменить уже нельзя', 'undo' => null];

            return;
        }
        $this->editor()->restore($this->story(), $snapshot['pages']);
        $this->selectPage($this->selected);
        $this->toast = ['text' => 'Отменено', 'undo' => null];
    }

    public function hideToast(): void
    {
        $this->toast = null;
    }

    private function flash(string $text, ?array $snapshot = null): void
    {
        $token = null;
        if ($snapshot) {
            $token = Str::random(24);
            session()->put('story-studio.undo.'.$token, ['story' => $this->storyId, 'pages' => $snapshot]);
        }
        $this->toast = ['text' => $text, 'undo' => $token];
    }

    private function plural(int $n): string
    {
        $mod10 = $n % 10;
        $mod100 = $n % 100;
        $word = $mod10 === 1 && $mod100 !== 11 ? 'страницу' : ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14) ? 'страницы' : 'страниц');

        return $n.' '.$word;
    }

    // ── view data ───────────────────────────────────────────────────────────────────────────

    private function numberOf(string $id): ?int
    {
        return app(ReadingOrder::class)->build($this->story())['pages']->firstWhere('public_id', $id)?->n;
    }

    /** Page ids of the grid as shown (node + filter), in reading order. */
    private function visibleIds(): array
    {
        return collect($this->sections())->flatMap(fn ($s) => array_column($s['cards'], 'id'))->all();
    }

    private function sections(): array
    {
        $story = $this->story();
        ['pages' => $pages, 'chapters' => $chapters] = app(ReadingOrder::class)->build($story);
        $node = $this->nodeModel($this->node);
        $nodeIds = $node ? $this->subtree($node) : null;

        $pages = $pages->filter(function (Page $p) use ($nodeIds) {
            if ($nodeIds !== null && ! in_array($p->node_id, $nodeIds, true)) {
                return false;
            }

            return match ($this->filter) {
                'draft' => $p->status === Page::DRAFT,
                'hidden' => $p->hidden,
                'notext' => trim((string) $p->caption) === '' && trim((string) $p->body) === '',
                default => true,
            };
        });

        $presenter = app(Presenter::class);
        $media = config('media-library.media_model', \Spatie\MediaLibrary\MediaCollections\Models\Media::class);
        $versionCounts = $media::where('model_type', (new Page)->getMorphClass())->where('collection_name', Page::VERSIONS)
            ->whereIn('model_id', $pages->pluck('id'))->selectRaw('model_id, count(*) as c')->groupBy('model_id')->pluck('c', 'model_id');
        $sections = [];
        foreach ($pages as $p) {
            $key = $p->node_id ?? 0;
            if (! isset($sections[$key])) {
                $c = $chapters[$p->node_id] ?? null;
                $sections[$key] = ['id' => $c ? $c['node']->public_id : 'root', 'no' => $c['no'] ?? null,
                    'title' => $c ? $c['node']->title : 'Без главы', 'volume' => $c ? $c['volume']?->title : null, 'cards' => []];
            }
            $image = $p->currentImage;
            $sections[$key]['cards'][] = [
                'id' => $p->public_id,
                'n' => $p->n,
                'nInChapter' => $p->n_in_chapter,
                'type' => $p->type,
                'status' => $p->status,
                'hidden' => $p->hidden,
                'hasText' => $p->hasText(),
                'caption' => $p->caption,
                'thumb' => $image && ! Version::broken($image) ? $presenter->imageUrls($story, $image, true)['thumb'] : null,
                'missing' => in_array($p->type, ['image', 'spread', 'panorama'], true) && (! $image || Version::broken($image)),
                'ar' => $presenter->ratio($p),
                'versions' => $versionCounts[$p->id] ?? 0,
            ];
        }

        // An empty chapter still shows, as a drop target.
        if ($node?->kind === Node::CHAPTER && ! $sections) {
            $c = $chapters[$node->id] ?? null;
            $sections[$node->id] = ['id' => $node->public_id, 'no' => $c['no'] ?? null, 'title' => $node->title, 'volume' => null, 'cards' => []];
        }

        return array_values($sections);
    }

    private function subtree(Node $node): array
    {
        return $node->descendants()->pluck('id')->push($node->id)->all();
    }

    public function render()
    {
        $story = $this->story();
        $order = app(ReadingOrder::class);
        $page = $this->pageModel($this->selected);
        $presenter = app(Presenter::class);
        $sections = $this->sections();
        $all = $order->build($story)['pages'];
        $current = $page ? $all->firstWhere('public_id', $page->public_id) : null;

        return view('story-studio::studio.editor', [
            'story' => $story,
            'tree' => $order->tree($story),
            'sections' => $sections,
            'total' => $all->count(),
            'page' => $current,
            'image' => $current?->currentImage,
            'imageUrls' => $current?->currentImage && ! Version::broken($current->currentImage) ? $presenter->imageUrls($story, $current->currentImage, true) : null,
            'versions' => $current ? $current->versions() : collect(),
            'pageAr' => $current ? $presenter->ratio($current) : 0.7,
            'readerUrl' => route(config('story-studio.routes.reader.name').'show', array_filter(['story' => $story->public_id,
                'page' => $this->selected, 'preview' => 1])),
            'nodeTitle' => $this->nodeTitle(),
            'moveTargets' => $this->moveTargets($order->tree($story)),
            'readerViews' => app(\Ombabush\StoryStudio\Support\ReaderCatalog::class)->readerViews(),
            'edition' => $story->edition(),
            'ixCount' => $current ? count(\Ombabush\StoryStudio\Interaction\Draft::binding(\Ombabush\StoryStudio\Interaction\Draft::of($story), $current->public_id)['interactions'] ?? []) : 0,
        ]);
    }

    private function nodeTitle(): string
    {
        $node = $this->nodeModel($this->node);

        return $node ? ($node->kind === Node::VOLUME ? 'Том '.$node->title : $node->title) : 'Всё издание';
    }

    private function moveTargets(array $tree): array
    {
        $out = [];
        foreach ($tree['nodes'] as $n) {
            if ($n['kind'] === Node::CHAPTER) {
                $out[] = ['id' => $n['id'], 'title' => $n['title'], 'no' => $n['no'], 'count' => $n['count'], 'volume' => null];
            }
            foreach ($n['children'] as $c) {
                if ($c['kind'] === Node::CHAPTER) {
                    $out[] = ['id' => $c['id'], 'title' => $c['title'], 'no' => $c['no'], 'count' => $c['count'], 'volume' => $n['title']];
                }
            }
        }

        return $out;
    }
}
