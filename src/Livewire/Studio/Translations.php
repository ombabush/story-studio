<?php

namespace Ombabush\StoryStudio\Livewire\Studio;

use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Ombabush\StoryStudio\Models\Node;
use Ombabush\StoryStudio\Models\Page;
use Ombabush\StoryStudio\Models\Story;
use Ombabush\StoryStudio\Support\Access;
use Ombabush\StoryStudio\Support\Texts;

/**
 * Translations of one subject — the edition, a chapter or a page (docs/DATA-MODEL.md §1.2).
 * One component for all three: the fields come from Texts::FIELDS, the original is shown beside each
 * field, every field saves itself on blur. Languages are the edition's own (⚙ Настройки).
 */
class Translations extends Component
{
    #[Locked]
    public string $storyId;

    #[Locked]
    public string $subject;

    #[Locked]
    public string $subjectId;

    public string $lang = '';

    public array $fields = [];

    public ?string $error = null;

    public function mount(string $story, string $subject, string $id): void
    {
        $model = Story::where('public_id', $story)->firstOrFail();
        abort_unless(app(Access::class)->canManage(auth()->user(), $model), 404);
        abort_unless(isset(Texts::FIELDS[$subject]), 404);
        $this->storyId = $story;
        $this->subject = $subject;
        $this->subjectId = $subject === 'story' ? $story : $id;
        $this->original();
        $this->lang = $model->edition()->translated()[0] ?? '';
        $this->load();
    }

    private function story(): Story
    {
        $story = Story::where('public_id', $this->storyId)->firstOrFail();
        abort_unless(app(Access::class)->canManage(auth()->user(), $story), 404);

        return $story;
    }

    /** The subject's own columns — the original every translation is made from. */
    private function original(): array
    {
        $story = $this->story();
        $model = match ($this->subject) {
            'story' => $story,
            'node' => Node::where('story_id', $story->id)->where('public_id', $this->subjectId)->first(),
            'page' => Page::where('story_id', $story->id)->where('public_id', $this->subjectId)->first(),
        };
        abort_unless($model, 404);   // a subject of another edition does not exist here

        return collect(Texts::FIELDS[$this->subject])->mapWithKeys(fn ($f) => [$f => (string) $model->getAttribute($f)])->all();
    }

    private function load(): void
    {
        $stored = $this->lang ? (app(Texts::class)->of($this->story(), $this->subject, $this->subjectId)[$this->lang] ?? []) : [];
        $this->fields = collect(Texts::FIELDS[$this->subject])->mapWithKeys(fn ($f) => [$f => (string) ($stored[$f] ?? '')])->all();
    }

    public function updatedLang(): void
    {
        if (! in_array($this->lang, $this->story()->edition()->translated(), true)) {
            $this->lang = '';
        }
        $this->error = null;
        $this->load();
    }

    public function updatedFields($value, string $key): void
    {
        if (! $this->lang || ! in_array($key, Texts::FIELDS[$this->subject], true)) {
            return;
        }
        try {
            app(Texts::class)->set($this->story(), $this->subject, $this->subjectId, $this->lang, [$key => (string) $value]);
            $this->error = null;
        } catch (InvalidArgumentException $e) {
            $this->error = 'Не сохранилось: '.$e->getMessage();
        }
    }

    public function render()
    {
        $story = $this->story();
        $done = app(Texts::class)->of($story, $this->subject, $this->subjectId);

        return view('story-studio::studio.translations', [
            'languages' => $story->edition()->translated(),
            'original' => $this->original(),
            'originalLang' => $story->edition()->original,
            'done' => array_map(fn ($f) => count($f), $done),
        ]);
    }
}
