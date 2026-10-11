<?php

namespace Ombabush\StoryStudio\Livewire\Studio;

use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Ombabush\StoryStudio\Models\Attachment;
use Ombabush\StoryStudio\Models\Node;
use Ombabush\StoryStudio\Models\Page;
use Ombabush\StoryStudio\Models\Story;
use Ombabush\StoryStudio\Support\Access;
use Ombabush\StoryStudio\Support\Attachments;

/**
 * Attachments of one scope — the edition, a chapter or a page (docs/DATA-MODEL.md §1.3):
 * music tracks and, on the edition, listen-elsewhere links. One component for every scope. Adding and
 * editing save at once; removing asks through the editor's confirm dialog (Editor::removeAttachment).
 */
class AttachmentList extends Component
{
    #[Locked]
    public string $storyId;

    #[Locked]
    public string $scope;

    #[Locked]
    public ?string $scopeId = null;

    #[Locked]
    public array $kinds = ['track'];

    public array $draft = [];

    public ?string $editing = null;

    public array $edit = [];

    public ?string $error = null;

    public function mount(string $story, string $scope, ?string $id = null, array $kinds = ['track']): void
    {
        $model = Story::where('public_id', $story)->firstOrFail();
        abort_unless(app(Access::class)->canManage(auth()->user(), $model), 404);
        abort_unless(in_array($scope, Attachment::SCOPES, true), 404);
        $this->storyId = $story;
        $this->scope = $scope;
        $this->scopeId = $scope === 'story' ? null : $id;
        if ($scope !== 'story') {
            $exists = ($scope === 'node' ? Node::query() : Page::query())->where('story_id', $model->id)->where('public_id', $id)->exists();
            abort_unless($exists, 404);
        }
        $this->kinds = array_values(array_intersect($kinds, Attachments::KINDS)) ?: ['track'];
        $this->resetDraft();
    }

    private function story(): Story
    {
        $story = Story::where('public_id', $this->storyId)->firstOrFail();
        abort_unless(app(Access::class)->canManage(auth()->user(), $story), 404);

        return $story;
    }

    private function resetDraft(?string $kind = null): void
    {
        $this->draft = ['kind' => $kind ?? $this->kinds[0], 'title' => '', 'artist' => '', 'time' => '', 'url' => '', 'label' => ''];
    }

    /** "3:25", "205" or empty → seconds. */
    public static function seconds(string $time): ?int
    {
        $time = trim($time);
        if ($time === '') {
            return null;
        }
        if (preg_match('/^(\d+):([0-5]?\d)$/', $time, $m)) {
            return (int) $m[1] * 60 + (int) $m[2];
        }

        return ctype_digit($time) ? (int) $time : throw new InvalidArgumentException('Длительность — «3:25» или число секунд.');
    }

    private function data(array $f): array
    {
        return ['title' => $f['title'] ?? '', 'artist' => $f['artist'] ?? '', 'seconds' => self::seconds((string) ($f['time'] ?? '')),
            'url' => $f['url'] ?? '', 'label' => $f['label'] ?? ''];
    }

    public function add(): void
    {
        try {
            $kind = in_array($this->draft['kind'] ?? null, $this->kinds, true) ? $this->draft['kind'] : $this->kinds[0];
            app(Attachments::class)->add($this->story(), $this->scope, $this->scopeId, $kind, $this->data($this->draft));
            $this->resetDraft($kind);
            $this->error = null;
        } catch (InvalidArgumentException $e) {
            $this->error = self::human($e->getMessage());
        }
    }

    public function startEdit(string $id): void
    {
        $item = $this->item($id);
        $this->editing = $id;
        $this->edit = ['title' => $item->data['title'] ?? '', 'artist' => $item->data['artist'] ?? '', 'url' => $item->data['url'] ?? '',
            'label' => $item->data['label'] ?? '', 'time' => isset($item->data['seconds']) ? intdiv($item->data['seconds'], 60).':'.str_pad((string) ($item->data['seconds'] % 60), 2, '0', STR_PAD_LEFT) : ''];
    }

    public function saveEdit(): void
    {
        if (! $this->editing) {
            return;
        }
        try {
            $item = $this->item($this->editing);
            $data = $this->data($this->edit);
            // An emptied optional field is removed, not kept from before.
            $item->update(['data' => app(Attachments::class)->normalize($item->kind, $data)]);
            $this->editing = null;
            $this->error = null;
        } catch (InvalidArgumentException $e) {
            $this->error = self::human($e->getMessage());
        }
    }

    public function move(string $id, int $delta): void
    {
        app(Attachments::class)->move($this->item($id), $delta);
    }

    #[On('ss-attachments')]
    public function refresh(): void {}

    private function item(string $id): Attachment
    {
        return Attachment::where('story_id', $this->story()->id)->where('scope', $this->scope)
            ->where('scope_id', $this->scopeId)->where('public_id', $id)->firstOrFail();
    }

    public static function human(string $message): string
    {
        return [
            'A track needs a title.' => 'У дорожки нет названия.',
            'A link needs a label.' => 'У ссылки нет подписи.',
            'A link needs an https:// address.' => 'Ссылке нужен адрес https://…',
            'Only https:// addresses.' => 'Только адреса https://…',
        ][$message] ?? $message;
    }

    public function render()
    {
        return view('story-studio::studio.attachments', [
            'items' => app(Attachments::class)->of($this->story(), $this->scope, $this->scopeId),
        ]);
    }
}
