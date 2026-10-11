<?php

namespace Ombabush\StoryStudio\Livewire\Studio;

use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;
use Ombabush\StoryStudio\Actions\ImportPackage;
use Ombabush\StoryStudio\Actions\StoryEditor;
use Ombabush\StoryStudio\Models\Page;
use Ombabush\StoryStudio\Models\Story;
use Ombabush\StoryStudio\Support\Access;

/** The shelf: every edition the user may edit, and "+ Новое издание". */
#[Layout('story-studio::layouts.studio')]
class Index extends Component
{
    use WithFileUploads;

    /** A .story.zip waiting for its preview; the plan lives in the session (it names server paths). */
    public $package = null;

    public ?array $preview = null;

    public ?string $importToken = null;

    public string $title = '';

    public string $authors = '';

    public string $direction = 'ltr';

    public bool $creating = false;

    public function mount(): void
    {
        abort_unless(app(Access::class)->canUseStudio(auth()->user()), 404);
    }

    public function create()
    {
        $this->validate(['title' => 'required|string|max:255', 'authors' => 'nullable|string|max:255', 'direction' => 'in:ltr,rtl']);
        $story = app(StoryEditor::class)->createStory(['title' => $this->title, 'authors' => $this->authors ?: null,
            'direction' => $this->direction], auth()->user());

        return $this->redirectRoute(config('story-studio.routes.studio.name').'edit', ['story' => $story->public_id]);
    }

    public function updatedPackage(): void
    {
        $this->validate(['package' => 'file|max:'.(1024 * 1024)]);
        $importer = app(ImportPackage::class);
        $plan = $importer->inspect($this->package->getRealPath());
        $this->importToken = \Illuminate\Support\Str::random(24);
        session()->put('story-studio.import.'.$this->importToken, $plan);
        $this->preview = ['ok' => $plan['ok'], 'errors' => array_slice($plan['errors'], 0, 30), 'summary' => $plan['summary'], 'existing' => $plan['existing']];
    }

    /** Apply the previewed package: a new private edition, never published by the import itself. */
    public function importPackage()
    {
        $plan = $this->importToken ? session()->pull('story-studio.import.'.$this->importToken) : null;
        if (! $plan || ! $plan['ok']) {
            return null;
        }
        $importer = app(ImportPackage::class);
        try {
            $result = $importer->apply($plan, auth()->user());
        } finally {
            $importer->discard($plan);
        }

        return $this->redirectRoute(config('story-studio.routes.studio.name').'edit', ['story' => $result['story']->public_id]);
    }

    public function cancelImport(): void
    {
        $plan = $this->importToken ? session()->pull('story-studio.import.'.$this->importToken) : null;
        $plan && app(ImportPackage::class)->discard($plan);
        $this->reset('package', 'preview', 'importToken');
    }

    public function render()
    {
        $access = app(Access::class);
        $user = auth()->user();
        $stories = Story::query()->latest('updated_at')->get()->filter(fn (Story $s) => $access->canManage($user, $s))->values();
        $counts = Page::whereIn('story_id', $stories->pluck('id'))->selectRaw('story_id, count(*) as c, sum(case when status = ? then 1 else 0 end) as d', [Page::DRAFT])
            ->groupBy('story_id')->get()->keyBy('story_id');
        $covers = [];
        foreach ($stories as $s) {
            $first = Page::where('story_id', $s->id)->whereNotNull('current_media_id')->with('currentImage')
                ->orderBy('node_id')->orderBy('position')->first();
            $covers[$s->id] = $first?->currentImage && ! \Ombabush\StoryStudio\Support\Version::broken($first->currentImage)
                ? app(\Ombabush\StoryStudio\Support\Presenter::class)->imageUrls($s, $first->currentImage, true)['thumb'] : null;
        }

        return view('story-studio::studio.index', compact('stories', 'counts', 'covers'));
    }
}
