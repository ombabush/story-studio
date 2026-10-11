{{--
    Interaction layer of one page (SR-02). Left: the picture with its regions, drawn and moved by the
    ssIxCanvas helper; right: the list of places, the selected one's form, cards and text links.
    The canvas re-reads its data from the JSON block below after every server change (wire:key on the
    version), so the drawing never disagrees with the saved draft.
--}}
@php
    $kindNames = ['nav' => 'переход', 'character' => 'персонаж', 'object' => 'предмет', 'place' => 'место', 'note' => 'сноска', 'media' => 'медиа', 'reference' => 'справка'];
    $effectNames = ['outline' => 'Контур', 'brackets' => 'Скобки', 'ink' => 'Чернила', 'quiet' => 'Тихий', 'spotlight' => 'Прожектор', 'soft-focus' => 'Размытие вокруг', 'halo' => 'Ореол', 'lift' => 'Подъём', 'duotone' => 'Дуотон'];
    $label = fn ($it) => trim((string) (($it['label'][$language] ?? '') ?: (collect($it['label'] ?? [])->first() ?? ''))) ?: '— без подписи —';
    $other = $language === 'en' ? 'ru' : 'en';
    $canvas = ['items' => collect($items)->filter(fn ($it) => ($it['anchor']['type'] ?? null) === 'region')->map(fn ($it) => [
        'id' => $it['id'], 'shape' => $it['anchor']['shape'], 'outline' => $it['anchor']['outline'] ?? null, 'invalid' => isset($invalid[$it['id']]),
    ])->values()->all(), 'protected' => $protected, 'selected' => $selected];
@endphp
<div class="ss-ixe" x-data="ssIxCanvas()" @keydown.window="key($event)">
    <script type="application/json" data-ss-ixe-data>@json($canvas)</script>

    <div class="ss-dialog__head">
        <div class="ss-grow">
            <div class="ss-dialog__title">Интерактив · стр. {{ $targets ? collect($targets)->firstWhere('id', $page->public_id)['n'] ?? '' : '' }}</div>
            <div class="ss-dialog__sub">Нарисуйте область, назовите её и скажите, что она открывает. Черновик сохраняется сам; ошибки не пускают место к читателям и в экспорт.</div>
        </div>
        <label class="ss-muted" style="font-size:12px;display:flex;align-items:center;gap:6px" title="Язык оригинала: подписи на нём обязательны">
            Язык <input class="ss-input" style="width:64px;height:30px" value="{{ $language }}" @change="$wire.setLanguage($event.target.value)">
        </label>
        <button type="button" class="ss-btn ss-btn--sm" wire:click="undo" @disabled(! $canUndo) title="Отменить · ⌘Z">↶</button>
        <button type="button" class="ss-btn ss-btn--sm" wire:click="redo" @disabled(! $canRedo) title="Повторить · ⇧⌘Z">↷</button>
        <button type="button" class="ss-btn ss-btn--icon ss-btn--ghost" @click="$dispatch('ss-ix-close')" title="Закрыть · Esc">✕</button>
    </div>

    @if($stale)
        <div class="ss-ixe__stale">
            <span class="ss-grow">Картинка страницы заменена после того, как нарисованы контуры. Пока вы не подтвердите, что они верны для новой картинки, читатели их не видят и экспорт не выпускает.</span>
            <button type="button" class="ss-btn ss-btn--sm"
                    @click="ask({title: 'Контуры верны для новой картинки?', text: 'Читатели снова увидят эти области на странице, а экспорт привяжет их к новой картинке.', record: @js('стр. · '.($page->caption ?: $page->public_id)), verb: 'Подтвердить', action: 'confirmSurface', args: [@js($page->public_id)]})">Проверил — верны</button>
        </div>
    @endif

    <div class="ss-ixe__cols">
        {{-- ── canvas ─────────────────────────────────────────────── --}}
        <div class="ss-ixe__left">
            <div class="ss-ixe__tools ss-seg ss-seg--mono" role="toolbar" aria-label="Инструменты">
                @foreach(['select' => 'Выбрать', 'rect' => '▭', 'ellipse' => '◯', 'polygon' => '⬠', 'pen' => '✎', 'protect' => '▨'] as $m => $t)
                    <button type="button" :aria-pressed="mode === '{{ $m }}'" @click="setMode('{{ $m }}')"
                            title="{{ ['select' => 'Выбрать и двигать область', 'rect' => 'Прямоугольник', 'ellipse' => 'Эллипс', 'polygon' => 'Многоугольник: клики по точкам, двойной клик — замкнуть', 'pen' => 'Перо: обведите рукой', 'protect' => 'Защищённая область — превью её не закрывает'][$m] }}">{{ $t }}</button>
                @endforeach
            </div>
            <div class="ss-seg" style="margin-top:6px" x-show="['rect', 'ellipse', 'polygon', 'pen'].includes(mode)" x-cloak>
                <button type="button" :aria-pressed="target === 'new'" @click="target = 'new'">Новое место</button>
                <button type="button" :aria-pressed="target === 'shape'" @click="target = 'shape'" :disabled="!data.selected">Заменить область</button>
                <button type="button" :aria-pressed="target === 'outline'" @click="target = 'outline'" :disabled="!data.selected" title="Тонкий контур для подсветки; попадание считается по области">Точный контур</button>
            </div>
            <div class="ss-ixe__stage">
                <div class="ss-ixe__art" style="--ar: {{ $ar }}" x-ref="art"
                     @pointerdown="down($event)" @pointermove="moveAt($event)" @pointerup="up($event)" @pointercancel="cancel()" @dblclick="closePolygon()">
                    @if($imageUrl)
                        <img src="{{ $imageUrl }}" alt="" draggable="false">
                    @else
                        <div class="ss-grid__empty" style="position:absolute;inset:0">У страницы нет картинки — здесь можно делать только текстовые ссылки.</div>
                    @endif
                    <div class="ss-ixe__svg" x-html="svg()"></div>
                </div>
            </div>
            <div class="ss-muted" style="font-size:12px" x-text="hint()"></div>
            <details class="ss-ixe__import">
                <summary>Вставить SVG-путь…</summary>
                <textarea class="ss-textarea ss-textarea--mono" rows="3" x-model="importD" placeholder="d из SVG: M 10 10 L 200 40 … Z (относительные команды и H/V/S/T переведу сам; дуги A — нет)"></textarea>
                <div style="display:flex;gap:6px;align-items:center;font-size:12px" class="ss-muted">
                    размер исходника <input class="ss-input" style="width:80px;height:30px" type="number" x-model.number="importW"> ×
                    <input class="ss-input" style="width:80px;height:30px" type="number" x-model.number="importH">
                    <button type="button" class="ss-btn ss-btn--sm" @click="importPath()">Применить</button>
                </div>
                <div class="ss-label--accent" style="font-size:12px" x-text="importError"></div>
            </details>
            @if($protected)
                <div class="ss-ixe__chips">
                    <span class="ss-label">Защищено</span>
                    @foreach($protected as $i => $area)
                        <button type="button" class="ss-btn ss-btn--sm" wire:click="protect(null, {{ $i }})" title="Убрать защищённую область">▨ {{ $i + 1 }} ✕</button>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- ── list, form, cards ───────────────────────────────────── --}}
        <div class="ss-ixe__right">
            <div class="ss-seg">
                <button type="button" aria-pressed="{{ $tab === 'items' ? 'true' : 'false' }}" wire:click="$set('tab', 'items')">Места · {{ count($items) }}</button>
                <button type="button" aria-pressed="{{ in_array($tab, ['cards', 'card'], true) ? 'true' : 'false' }}" wire:click="$set('tab', 'cards')">Карточки · {{ count($cards) }}</button>
                <button type="button" aria-pressed="{{ $tab === 'text' ? 'true' : 'false' }}" wire:click="$set('tab', 'text')">Ссылки в тексте</button>
            </div>

            @if($pageErrors)
                <div class="ss-ixe__errors">@foreach($pageErrors as $e)<div>{{ \Ombabush\StoryStudio\Livewire\Studio\Interactions::human($e) }}</div>@endforeach</div>
            @endif

            @if($tab === 'items')
                <ul class="ss-ixe__list">
                    @forelse($items as $it)
                        <li wire:key="ixi-{{ $it['id'] }}">
                            <button type="button" class="ss-ixe__row" aria-current="{{ $selected === $it['id'] ? 'true' : 'false' }}" wire:click="select('{{ $it['id'] }}')">
                                <span class="ss-grow ss-ellipsis">{{ $label($it) }}</span>
                                <span class="ss-faint">{{ $kindNames[$it['kind'] ?? ''] ?? '' }} · {{ ($it['anchor']['type'] ?? '') === 'text' ? 'текст' : 'область' }}</span>
                                @if(isset($invalid[$it['id']]))<span class="ss-ixe__bad" title="Есть ошибки — читатели это место не видят">!</span>@endif
                            </button>
                        </li>
                    @empty
                        <li class="ss-muted" style="padding:8px 0">Мест пока нет. Выберите инструмент слева и обведите персонажа, табличку или предмет.</li>
                    @endforelse
                </ul>

                @if($selected && $form)
                    <div class="ss-ixe__form" wire:key="form-{{ $selected }}">
                        @if(isset($invalid[$selected]))
                            <div class="ss-ixe__errors">@foreach($invalid[$selected] as $e)<div>{{ \Ombabush\StoryStudio\Livewire\Studio\Interactions::human($e) }}</div>@endforeach</div>
                        @endif
                        <label class="ss-field"><span class="ss-label">Подпись · {{ $language }}</span>
                            <input class="ss-input" wire:model.blur="form.label.{{ $language }}" placeholder="Узнать про Илью"></label>
                        <label class="ss-field"><span class="ss-label">Подпись · {{ $other }} (необязательно)</span>
                            <input class="ss-input" wire:model.blur="form.label.{{ $other }}"></label>
                        <label class="ss-field"><span class="ss-label">Что это</span>
                            <select class="ss-input" wire:model.change="form.kind">@foreach($kinds as $k)<option value="{{ $k }}">{{ $kindNames[$k] }}</option>@endforeach</select></label>
                        <label class="ss-field"><span class="ss-label">Что открывает</span>
                            <select class="ss-input" wire:model.change="form.action">
                                <option value="open-card">Карточку</option><option value="jump">Страницу издания</option><option value="open-url">Сайт (https)</option>
                            </select></label>
                        @if($form['action'] === 'open-card')
                            <div class="ss-field"><span class="ss-label">Карточка</span>
                                <div style="display:flex;gap:6px">
                                    <select class="ss-input" wire:model.change="form.cardId"><option value="">— выберите —</option>
                                        @foreach($cards as $c)<option value="{{ $c['id'] }}">{{ ($c['title'][$language] ?? '') ?: $c['id'] }}</option>@endforeach</select>
                                    <button type="button" class="ss-btn" wire:click="editCard" title="Новая карточка">+</button>
                                </div></div>
                        @elseif($form['action'] === 'jump')
                            <label class="ss-field"><span class="ss-label">Страница</span>
                                <select class="ss-input" wire:model.change="form.pageId"><option value="">— выберите —</option>
                                    @foreach($targets as $t)<option value="{{ $t['id'] }}">стр. {{ $t['n'] }}{{ $t['caption'] ? ' · '.\Illuminate\Support\Str::limit($t['caption'], 40) : '' }}</option>@endforeach</select></label>
                            <label class="ss-ixe__check"><input type="checkbox" wire:model.change="form.essential"> Обязательная развилка — всегда видна кнопкой «Продолжить историю»</label>
                        @else
                            <label class="ss-field"><span class="ss-label">Адрес · только https://</span>
                                <input class="ss-input" wire:model.blur="form.url" placeholder="https://"></label>
                        @endif
                        <div class="ss-field"><span class="ss-label">Группа · нарисованное меню, развилка</span>
                            <div style="display:flex;gap:6px">
                                <select class="ss-input" wire:model.change="form.groupId"><option value="">— нет —</option>
                                    @foreach($groups as $g)<option value="{{ $g['id'] }}">{{ $g['label'][$language] ?? $g['id'] }}</option>@endforeach</select>
                                <button type="button" class="ss-btn" wire:click="addGroup('Продолжить историю')" title="Новая группа «Продолжить историю»">+</button>
                            </div></div>
                        <label class="ss-field"><span class="ss-label">Подсветка · предпочтение автора</span>
                            <select class="ss-input" wire:model.change="form.effect"><option value="">как решит читатель</option>
                                @foreach($effects as $e)<option value="{{ $e }}">{{ $effectNames[$e] }}</option>@endforeach</select>
                            <span class="ss-faint" style="font-size:11px">Читалка пока рисует контур; остальные эффекты появятся позже — выбор сохранится.</span></label>
                        <div style="display:flex;gap:6px">
                            <button type="button" class="ss-btn ss-btn--sm" wire:click="move('{{ $selected }}', -1)" title="Выше в списке и в выборе">↑</button>
                            <button type="button" class="ss-btn ss-btn--sm" wire:click="move('{{ $selected }}', 1)" title="Ниже">↓</button>
                            <span class="ss-grow"></span>
                            <button type="button" class="ss-btn ss-btn--sm ss-btn--danger" wire:click="delete('{{ $selected }}')" title="Удалить место — можно отменить">Удалить</button>
                        </div>
                    </div>
                @endif
            @elseif($tab === 'cards')
                <ul class="ss-ixe__list">
                    @forelse($cards as $c)
                        <li wire:key="ixc-{{ $c['id'] }}"><button type="button" class="ss-ixe__row" wire:click="editCard('{{ $c['id'] }}')">
                            <span class="ss-grow ss-ellipsis">{{ ($c['title'][$language] ?? '') ?: '— без названия —' }}</span><span class="ss-faint">{{ $kindNames[$c['kind']] ?? $c['kind'] }}</span></button></li>
                    @empty
                        <li class="ss-muted" style="padding:8px 0">Карточек нет. Карточка — короткая публичная справка о персонаже, предмете или месте.</li>
                    @endforelse
                </ul>
                <button type="button" class="ss-btn" wire:click="editCard">+ Карточка</button>
            @elseif($tab === 'card')
                <div class="ss-ixe__form">
                    <label class="ss-field"><span class="ss-label">Что это</span>
                        <select class="ss-input" wire:model="card.kind">@foreach(['character', 'object', 'place', 'note', 'reference'] as $k)<option value="{{ $k }}">{{ $kindNames[$k] }}</option>@endforeach</select></label>
                    <label class="ss-field"><span class="ss-label">Название · {{ $language }}</span><input class="ss-input" wire:model="card.title.{{ $language }}"></label>
                    <label class="ss-field"><span class="ss-label">Название · {{ $other }}</span><input class="ss-input" wire:model="card.title.{{ $other }}"></label>
                    <label class="ss-field"><span class="ss-label">Текст · {{ $language }} — видят все читатели</span><textarea class="ss-textarea ss-textarea--serif" rows="5" wire:model="card.body.{{ $language }}"></textarea></label>
                    <label class="ss-field"><span class="ss-label">Текст · {{ $other }}</span><textarea class="ss-textarea ss-textarea--serif" rows="3" wire:model="card.body.{{ $other }}"></textarea></label>
                    <div style="display:flex;gap:6px">
                        <button type="button" class="ss-btn ss-btn--primary" wire:click="saveCard">Сохранить</button>
                        <button type="button" class="ss-btn" wire:click="$set('tab', 'cards')">К списку</button>
                        <span class="ss-grow"></span>
                        @if(! empty($card['id']))
                            <button type="button" class="ss-btn ss-btn--danger" wire:click="deleteCard('{{ $card['id'] }}')" title="Удалить карточку — можно отменить">Удалить</button>
                        @endif
                    </div>
                </div>
            @else
                <p class="ss-muted" style="margin:0;font-size:13px">Выделите слово мышью и нажмите «Сделать ссылкой». Ссылка привязана к этому тексту: если текст изменится, она перестанет показываться, пока вы её не переделаете.</p>
                @forelse($texts as $target => $text)
                    <div class="ss-ixe__text" data-ss-text-target="{{ $target }}">{{ $text }}</div>
                @empty
                    <div class="ss-muted">У страницы нет подписи и текста.</div>
                @endforelse
                @if($texts)
                    <button type="button" class="ss-btn" @click="linkSelection()">Сделать ссылкой</button>
                    <div class="ss-label--accent" style="font-size:12px" x-text="importError"></div>
                @endif
            @endif

            @if($error)<div class="ss-ixe__errors">{{ $error }}</div>@endif
        </div>
    </div>

    @if($toast)
        <div class="ss-toast" wire:key="ixt-{{ md5($toast.$version) }}" x-data="{ on: true }" x-show="on" x-init="setTimeout(() => on = false, 7000)">
            <span>{{ $toast }}</span>
            @if($canUndo)<button type="button" class="ss-btn ss-btn--sm ss-btn--primary" wire:click="undo">Отменить</button>@endif
        </div>
    @endif
</div>
