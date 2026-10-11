<?php

namespace Ombabush\StoryStudio\Livewire\Studio;

use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Ombabush\StoryStudio\Actions\EditInteractions;
use Ombabush\StoryStudio\Interaction\Draft;
use Ombabush\StoryStudio\Interaction\ProfileValidator;
use Ombabush\StoryStudio\Models\Page;
use Ombabush\StoryStudio\Models\Story;
use Ombabush\StoryStudio\Support\Access;
use Ombabush\StoryStudio\Support\Presenter;
use Ombabush\StoryStudio\Support\ReadingOrder;
use Ombabush\StoryStudio\Support\Version;

/**
 * The Interaction layer of one page (docs/FORMAT.md): draw regions on the picture, say what
 * each opens, write its label, pick a card or a target page. Thin like Editor: every change is an
 * EditInteractions call. The draft saves as it goes and may be incomplete; what is wrong is listed
 * next to the item and keeps it out of the Reader and the export.
 *
 * Changes are frequent and reversible, so none asks a question; each can be undone (Undo/Redo keep
 * whole-draft snapshots in the session, so the browser can ask for an undo but cannot write one).
 */
class Interactions extends Component
{
    #[Locked]
    public string $storyId;

    #[Locked]
    public string $pageId;

    #[Locked]
    public string $version = '';

    public ?string $selected = null;

    public array $form = [];

    public string $tab = 'items';

    public array $card = [];

    public ?string $error = null;

    public ?string $toast = null;

    public function mount(string $story, string $page): void
    {
        $model = Story::where('public_id', $story)->firstOrFail();
        abort_unless(app(Access::class)->canManage(auth()->user(), $model), 404);
        $this->storyId = $story;
        $this->pageId = $page;
        $this->version = EditInteractions::version($model);
        $first = Draft::binding(Draft::of($model), $page)['interactions'][0]['id'] ?? null;
        $this->select($first);
    }

    private function story(): Story
    {
        $story = Story::where('public_id', $this->storyId)->firstOrFail();
        abort_unless(app(Access::class)->canManage(auth()->user(), $story), 404);

        return $story;
    }

    private function edit(): EditInteractions
    {
        return app(EditInteractions::class);
    }

    /** One change: snapshot for Undo, apply, keep the new version; a conflict reloads instead of overwriting. */
    private function apply(callable $change, string $done = ''): mixed
    {
        $story = $this->story();
        $before = [$story->interaction, $story->interaction_surfaces];
        try {
            $result = $change($story, $this->version);
        } catch (InvalidArgumentException $e) {
            $this->error = $e->getMessage();
            if ($e->getMessage() === EditInteractions::CONFLICT) {
                $this->version = EditInteractions::version($story->fresh());
            }

            return null;
        }
        $this->version = is_array($result) ? $result[0] : $result;
        $history = session()->get($this->historyKey(), ['undo' => [], 'redo' => []]);
        $history['undo'][] = $before;
        $history['undo'] = array_slice($history['undo'], -50);
        $history['redo'] = [];
        session()->put($this->historyKey(), $history);
        $this->error = null;
        $this->toast = $done ?: null;

        return $result;
    }

    private function historyKey(): string
    {
        return 'story-studio.ix-history.'.$this->storyId.'.'.auth()->id();
    }

    // ── canvas ──────────────────────────────────────────────────────────────────────────────

    public function addRegion(array $shape): void
    {
        $result = $this->apply(fn ($s, $v) => $this->edit()->addRegion($s, $v, $this->pageId, $this->shape($shape)), 'Область добавлена');
        if ($result) {
            $this->select($result[1]);
        }
    }

    public function setShape(string $id, ?array $shape, string $which = 'shape'): void
    {
        $this->apply(fn ($s, $v) => $this->edit()->setShape($s, $v, $id, $shape ? $this->shape($shape) : null, $which),
            $which === 'outline' ? ($shape ? 'Точный контур сохранён' : 'Точный контур убран') : 'Область сохранена');
    }

    public function protect(?array $shape, ?int $remove = null): void
    {
        $this->apply(fn ($s, $v) => $this->edit()->protect($s, $v, $this->pageId, $shape ? $this->shape($shape) : null, $remove),
            $remove === null ? 'Защищённая область добавлена' : 'Защищённая область убрана');
    }

    /** Numbers only, rounded to the format's three decimals; the validator judges the rest. */
    private function shape(array $s): array
    {
        $r = fn ($v) => round((float) $v, 3);

        return match ($s['type'] ?? null) {
            'rect' => ['type' => 'rect', 'x' => $r($s['x']), 'y' => $r($s['y']), 'width' => $r($s['width']), 'height' => $r($s['height'])],
            'ellipse' => ['type' => 'ellipse', 'cx' => $r($s['cx']), 'cy' => $r($s['cy']), 'rx' => $r($s['rx']), 'ry' => $r($s['ry'])],
            'polygon' => ['type' => 'polygon', 'points' => array_map(fn ($p) => [$r($p[0]), $r($p[1])], array_slice((array) $s['points'], 0, 512))],
            'path' => ['type' => 'path', 'd' => (string) $s['d'], 'fillRule' => ($s['fillRule'] ?? '') === 'nonzero' ? 'nonzero' : 'evenodd'],
            default => throw new InvalidArgumentException('Unknown shape.'),
        };
    }

    // ── list & form ─────────────────────────────────────────────────────────────────────────

    public function select(?string $id): void
    {
        $this->selected = $id;
        $this->tab = 'items';
        $it = $id ? $this->item($id) : null;
        $this->form = $it ? [
            'kind' => $it['kind'] ?? 'object',
            'label' => (array) ($it['label'] ?? []),
            'action' => $it['action']['type'] ?? 'open-card',
            'pageId' => $it['action']['pageId'] ?? '',
            'cardId' => $it['action']['cardId'] ?? '',
            'url' => $it['action']['url'] ?? '',
            'essential' => ($it['importance'] ?? null) === 'essential',
            'groupId' => $it['groupId'] ?? '',
            'effect' => $it['presentation']['preferredEffect'] ?? '',
        ] : [];
    }

    /** The form saves itself, field by field, like the page inspector. */
    public function updatedForm(): void
    {
        if (! $this->selected) {
            return;
        }
        $f = $this->form;
        $action = match ($f['action']) {
            'jump' => ['type' => 'jump', 'pageId' => $f['pageId']],
            'open-url' => ['type' => 'open-url', 'url' => $f['url']],
            default => ['type' => 'open-card', 'cardId' => $f['cardId']],
        };
        $this->apply(fn ($s, $v) => $this->edit()->update($s, $v, $this->selected, ['kind' => $f['kind'], 'label' => $f['label'],
            'action' => $action, 'essential' => (bool) $f['essential'], 'groupId' => $f['groupId'], 'effect' => $f['effect']]));
    }

    public function delete(string $id): void
    {
        $this->apply(fn ($s, $v) => $this->edit()->delete($s, $v, $id), 'Место удалено');
        $this->select(Draft::binding(Draft::of($this->story()), $this->pageId)['interactions'][0]['id'] ?? null);
    }

    public function move(string $id, int $delta): void
    {
        $this->apply(fn ($s, $v) => $this->edit()->move($s, $v, $id, $delta));
    }

    public function addGroup(string $label): void
    {
        $result = $this->apply(fn ($s, $v) => $this->edit()->group($s, $v, $this->pageId, $label ?: 'Продолжить историю'), 'Группа добавлена');
        if ($result && $this->selected) {
            $this->form['groupId'] = $result[1];
            $this->updatedForm();
        }
    }

    public function textLink(string $target, int $start, int $end): void
    {
        $text = $this->publishedText($target);
        $result = $this->apply(fn ($s, $v) => $this->edit()->textLink($s, $v, $this->pageId, $target, $text, $start, $end), 'Слово стало ссылкой');
        if ($result) {
            $this->select($result[1]);
        }
    }

    public function setLanguage(string $language): void
    {
        $this->apply(fn ($s, $v) => $this->edit()->language($s, $v, trim($language)), 'Язык оригинала: '.$language);
    }

    // ── cards ───────────────────────────────────────────────────────────────────────────────

    public function editCard(?string $id = null): void
    {
        $c = $id ? collect(Draft::of($this->story())['cards'])->firstWhere('id', $id) : null;
        $this->card = ['id' => $c['id'] ?? '', 'kind' => $c['kind'] ?? 'character', 'title' => (array) ($c['title'] ?? []), 'body' => (array) ($c['body'] ?? [])];
        $this->tab = 'card';
    }

    public function saveCard(): void
    {
        $result = $this->apply(fn ($s, $v) => $this->edit()->card($s, $v, $this->card), 'Карточка сохранена');
        if ($result) {
            $this->card['id'] = $result[1];
            if ($this->selected && ($this->form['action'] ?? null) === 'open-card' && ! $this->form['cardId']) {
                $this->form['cardId'] = $result[1];
                $this->updatedForm();
            }
        }
    }

    public function deleteCard(string $id): void
    {
        $this->apply(fn ($s, $v) => $this->edit()->deleteCard($s, $v, $id), 'Карточка удалена');
        $this->card = [];
        $this->tab = 'items';
    }

    // ── undo / redo ─────────────────────────────────────────────────────────────────────────

    public function undo(): void
    {
        $this->travel('undo', 'redo', 'Отменено');
    }

    public function redo(): void
    {
        $this->travel('redo', 'undo', 'Повторено');
    }

    private function travel(string $from, string $to, string $done): void
    {
        $history = session()->get($this->historyKey(), ['undo' => [], 'redo' => []]);
        $state = array_pop($history[$from]);
        if (! $state) {
            return;
        }
        $story = $this->story();
        $current = [$story->interaction, $story->interaction_surfaces];
        try {
            $this->version = $this->edit()->restore($story, $this->version, $state[0], $state[1]);
        } catch (InvalidArgumentException $e) {
            $this->error = $e->getMessage();

            return;
        }
        $history[$to][] = $current;
        session()->put($this->historyKey(), $history);
        $this->toast = $done;
        $this->select($this->item((string) $this->selected) ? $this->selected : (Draft::binding(Draft::of($story->fresh()), $this->pageId)['interactions'][0]['id'] ?? null));
    }

    #[On('ix-refresh')]
    public function refresh(): void
    {
        $this->version = EditInteractions::version($this->story());
    }

    // ── view data ───────────────────────────────────────────────────────────────────────────

    /** The validator speaks in paths (it serves importers too); the author gets one plain sentence. */
    public static function human(string $error): string
    {
        $rules = [
            '~/label.*needs text in the original language \((.+)\)~' => 'Нет подписи на языке оригинала ($1)',
            '~/label/.*must be 1…~' => 'Подпись пустая',
            '~/title.*needs text in the original language \((.+)\)~' => 'У карточки нет названия на языке оригинала ($1)',
            '~/cardId: names no card~' => 'Не выбрана карточка',
            '~/pageId: names no page~' => 'Не выбрана страница перехода',
            '~/anchorId: names no anchor~' => 'Якорь на целевой странице не найден',
            '~/url: ~' => 'Адрес сайта должен начинаться с https:// и быть полным',
            '~/importance: essential is allowed only for jump~' => 'Обязательной может быть только развилка-переход',
            '~/groupId: names no group~' => 'Группа не найдена',
            '~/anchor: a region needs an artwork surface~' => 'У страницы нет картинки — область рисовать не на чем',
            '~/anchor: names no text run~' => 'Ссылка в тексте потеряла своё слово',
            '~rect must have~' => 'Прямоугольник выходит за край страницы',
            '~ellipse must have~' => 'Эллипс выходит за край страницы',
            '~/points: ~' => 'Многоугольник некорректен (самопересечение или мало точек)',
            '~/d: (.+)$~' => 'Контур некорректен: $1',
            '~duplicate~' => 'Повторяющийся идентификатор',
        ];
        foreach ($rules as $re => $text) {
            if (preg_match($re, $error, $m)) {
                return isset($m[1]) ? str_replace('$1', $m[1], $text) : $text;
            }
        }

        return $error;
    }

    private function item(string $id): ?array
    {
        foreach (Draft::binding(Draft::of($this->story()), $this->pageId)['interactions'] ?? [] as $it) {
            if (($it['id'] ?? null) === $id) {
                return $it;
            }
        }

        return null;
    }

    private function publishedText(string $target): string
    {
        $page = Page::where('public_id', $this->pageId)->firstOrFail();
        if ($target === 'caption') {
            return (string) $page->caption;
        }
        $paragraphs = app(Presenter::class)->paragraphs($page->body);

        return (string) ($paragraphs[(int) substr($target, 1)] ?? '');
    }

    public function render()
    {
        $story = $this->story();
        $draft = Draft::of($story);
        $binding = Draft::binding($draft, $this->pageId) ?? ['interactions' => [], 'groups' => [], 'protectedAreas' => []];
        $analysis = Draft::analyse($story);
        $page = Page::where('story_id', $story->id)->where('public_id', $this->pageId)->with('currentImage')->firstOrFail();
        $image = $page->currentImage && ! Version::broken($page->currentImage) ? $page->currentImage : null;
        $presenter = app(Presenter::class);
        $hasRegions = collect($binding['interactions'])->contains(fn ($it) => ($it['anchor']['type'] ?? null) === 'region');
        $history = session()->get($this->historyKey(), ['undo' => [], 'redo' => []]);

        return view('story-studio::studio.interactions', [
            'story' => $story,
            'page' => $page,
            'language' => $draft['language'],
            'items' => $binding['interactions'],
            'groups' => $binding['groups'] ?? [],
            'protected' => $binding['protectedAreas'] ?? [],
            'invalid' => $analysis['invalid'],
            'pageErrors' => $analysis['pageErrors'][$this->pageId] ?? [],
            'cards' => $draft['cards'],
            'imageUrl' => $image ? $presenter->imageUrls($story, $image, true)['src'] : null,
            'ar' => $presenter->ratio($page),
            'stale' => $hasRegions && $image && ($story->interaction_surfaces[$this->pageId] ?? null) !== Version::sha1($image),
            'targets' => app(ReadingOrder::class)->build($story)['pages']->map(fn (Page $p) => ['id' => $p->public_id, 'n' => $p->n, 'caption' => $p->caption])->values()->all(),
            'texts' => array_filter(['caption' => (string) $page->caption] + collect($presenter->paragraphs($page->body))->mapWithKeys(fn ($t, $i) => ['p'.$i => $t])->all(), fn ($t) => $t !== ''),
            'kinds' => ProfileValidator::KINDS,
            'effects' => ProfileValidator::EFFECTS,
            'canUndo' => (bool) $history['undo'],
            'canRedo' => (bool) $history['redo'],
        ]);
    }
}
