{{--
    Edition editor (design: Admin.dc.html — tree · grid · inspector).
    Anything whose arguments depend on the current page or node carries a wire:key with that id: Alpine
    binds a handler once, so an element reused for another page would keep acting on the old one.
--}}
@php
    $tags = ['image' => 'КАР', 'spread' => 'РАЗ', 'panorama' => 'ПАН', 'text' => 'ТЕК', 'mixed' => 'СМШ'];
    $typeNames = ['image' => 'Картинка', 'spread' => 'Разворот', 'panorama' => 'Панорама', 'text' => 'Текстовая', 'mixed' => 'Смешанная'];
    $studio = config('story-studio.routes.studio.name');
    $drafts = collect($sections)->flatMap(fn ($s) => $s['cards'])->where('status', 'draft')->count();
    $shown = collect($sections)->sum(fn ($s) => count($s['cards']));
@endphp
<div class="ss-root ss-studio" data-ss-root x-data="ssEditor()" :data-ss-theme="theme" :style="'--thumb:' + thumb + 'px'"
     @keydown.window="key($event)" @ss-open-move.window="openMove(JSON.parse(document.getElementById('ss-move-targets').textContent))"
     @dragend.window="endDrag()">
    <script type="application/json" id="ss-move-targets">@json($moveTargets)</script>

    {{-- ── header ─────────────────────────────────────────────── --}}
    <header class="ss-studio__head" wire:key="head-{{ $story->published_at ? 'on' : 'off' }}">
        <button type="button" class="ss-btn ss-btn--icon ss-only-narrow" @click="treeOpen = !treeOpen" title="Тома и главы">☰</button>
        <div class="ss-studio__logo">{{ mb_substr(config('story-studio.host_label') ?: 'S', 0, 1) }}</div>
        <nav class="ss-studio__crumbs">
            @if(config('story-studio.host_home'))
                <a href="{{ config('story-studio.host_home') }}" class="ss-faint ss-only-wide">{{ config('story-studio.host_label') ?: 'Home' }}</a><i class="ss-only-wide">/</i>
            @endif
            <a href="{{ route($studio.'index') }}" class="ss-muted ss-only-wide">Издания</a><i class="ss-only-wide">/</i>
            <b>{{ $story->title }}</b>
            <span class="ss-studio__pub {{ $story->published_at ? 'ss-studio__pub--on' : 'ss-studio__pub--off' }}">{{ $story->published_at ? 'опубликовано' : 'не опубликовано' }}</span>
        </nav>
        <div class="ss-grow"></div>
        @if(config('story-studio.studio_header_view'))
            @include(config('story-studio.studio_header_view'), ['story' => $story])
        @endif
        <label class="ss-btn" title="Загрузить файлы, ZIP или CBZ">
            <span class="ss-mono">↑</span><span class="ss-only-wide">Загрузить</span>
            <input type="file" multiple accept="image/*,.zip,.cbz" style="display:none" @change="pick($event)">
        </label>
        <label class="ss-btn ss-btn--icon ss-only-wide" title="Загрузить папку — подпапки станут главами">
            <span>▤</span><input type="file" webkitdirectory multiple style="display:none" @change="pick($event)">
        </label>
        <button type="button" class="ss-btn ss-btn--icon" wire:click="openSettings" title="Настройки издания">⚙</button>
        <button type="button" class="ss-btn ss-btn--icon ss-only-wide" wire:click="exportPackage" title="Экспорт .story.zip — публичные страницы, картинки и интерактив">⇩</button>
        <button type="button" class="ss-btn ss-btn--icon" @click="toggleTheme()" title="Тема: тёмная / светлая">◐</button>
        @if($story->published_at)
            <button type="button" class="ss-btn ss-only-wide" @click="ask({title: @js('Снять «'.$story->title.'» с публикации?'), text: 'Читалка закроется для всех, кроме редакторов; закладки читателей сохранятся.', record: @js($story->title), verb: 'Снять', danger: true, action: 'unpublish'})">Снять с публикации</button>
        @else
            <button type="button" class="ss-btn ss-only-wide" @click="ask({title: @js('Опубликовать «'.$story->title.'»?'), text: 'Читатели увидят готовые страницы; черновики и скрытые останутся невидимыми.', record: @js($story->title), verb: 'Опубликовать', action: 'publish'})">Опубликовать</button>
        @endif
        <a href="{{ $readerUrl }}" class="ss-btn ss-btn--primary" title="Открыть читалку на выбранной странице">Читать <span class="ss-mono">→</span></a>
    </header>

    <div class="ss-studio__progress" x-data="{ pct: null }" @ss-upload-progress.window="pct = $event.detail.pct; if (pct === 100) setTimeout(() => pct = null, 600)" x-show="pct !== null" x-cloak>
        <span class="ss-mono ss-muted">Загрузка</span><div><i :style="'width:' + pct + '%'"></i></div><span class="ss-mono" x-text="pct + '%'"></span>
    </div>

    <div class="ss-studio__cols">
        {{-- ── tree ─────────────────────────────────────────────── --}}
        <aside class="ss-tree" :data-open="treeOpen ? '' : null">
            <div class="ss-tree__head">
                <b>{{ $story->title }}</b>
                <span>{{ $total }} стр. · {{ $story->direction === 'rtl' ? 'справа налево' : 'слева направо' }}@if($story->authors) · {{ $story->authors }}@endif</span>
            </div>
            <div class="ss-tree__body" x-data="{ renaming: null }">
                <button type="button" class="ss-tree__all" wire:click="selectNode('all')" aria-current="{{ $node === 'all' ? 'true' : 'false' }}">
                    <span class="ss-grow">Всё издание</span><span class="ss-tree__count">{{ $total }}</span>
                </button>
                @foreach($tree['nodes'] as $n)
                    @if($n['kind'] === 'volume')
                        <div wire:key="vol-{{ $n['id'] }}-{{ md5($n['title']) }}">
                            <div class="ss-tree__vol">
                                <button type="button" wire:click="selectNode('{{ $n['id'] }}')" aria-current="{{ $node === $n['id'] ? 'true' : 'false' }}"
                                        @dblclick="renaming = '{{ $n['id'] }}'" x-show="renaming !== '{{ $n['id'] }}'">Том {{ $n['title'] }}</button>
                                <template x-if="renaming === '{{ $n['id'] }}'">
                                    <input class="ss-tree__rename" value="{{ $n['title'] }}" x-init="$nextTick(() => $el.select())"
                                           @keydown.enter="$wire.renameNode('{{ $n['id'] }}', $el.value); renaming = null" @keydown.escape.stop="renaming = null"
                                           @blur="$wire.renameNode('{{ $n['id'] }}', $el.value); renaming = null">
                                </template>
                                <span class="ss-tree__count">{{ $n['total'] }}</span>
                                <button type="button" class="ss-tree__mini" wire:click="addChapter('{{ $n['id'] }}')" title="+ глава">+</button>
                                <button type="button" class="ss-tree__mini" title="Удалить том…"
                                        @click="ask({title: @js('Удалить том «'.$n['title'].'»?'), text: @js('Том, его главы и '.$n['total'].' стр. удалятся вместе с картинками. Отменить нельзя.'), record: @js('Том '.$n['title']), verb: 'Удалить', danger: true, action: 'deleteNode', args: [@js($n['id'])]})">✕</button>
                            </div>
                            @foreach($n['children'] as $c)
                                @include('story-studio::studio.partials.chapter', ['c' => $c, 'loose' => false])
                            @endforeach
                        </div>
                    @else
                        @include('story-studio::studio.partials.chapter', ['c' => $n, 'loose' => true])
                    @endif
                @endforeach
                <div style="display:flex;gap:6px">
                    <button type="button" class="ss-tree__add" wire:click="addVolume">+ Том</button>
                    <button type="button" class="ss-tree__add" wire:click="addChapter">+ Глава</button>
                </div>
            </div>
            <div class="ss-tree__foot">Двойной клик — переименовать<br>Бросьте страницы на главу — в конец</div>
        </aside>

        {{-- ── grid ─────────────────────────────────────────────── --}}
        <main class="ss-grid-wrap" @dragover="gridDragOver($event)" @dragleave.self="fileOver = false" @drop="gridDrop($event)">
            <div class="ss-grid-head">
                <div class="ss-grid-head__title">
                    <b class="ss-ellipsis">{{ $nodeTitle }}</b>
                    <span>{{ $shown }} стр.@if($drafts) · {{ $drafts }} черновиков@endif</span>
                </div>
                <button type="button" class="ss-btn ss-btn--sm" wire:click="addTextPage" title="+ текстовая страница">+ Текст</button>
                <div class="ss-seg ss-seg--surface">
                    @foreach(['all' => 'Все', 'draft' => 'Черновики', 'hidden' => 'Скрытые', 'notext' => 'Без текста'] as $f => $label)
                        <button type="button" wire:click="$set('filter', '{{ $f }}')" aria-pressed="{{ $filter === $f ? 'true' : 'false' }}">{{ $label }}</button>
                    @endforeach
                </div>
                <label class="ss-thumbsize ss-only-wide" title="Размер миниатюр">
                    <span class="ss-mono" style="font-size:11px">▫</span>
                    <input type="range" min="90" max="260" step="10" :value="thumb" @input="setThumb(+$event.target.value)">
                    <span class="ss-mono" style="font-size:15px">▭</span>
                </label>
            </div>

            <div class="ss-selbar" x-show="selected.length" x-cloak>
                <b>Выбрано <span x-text="selected.length"></span></b>
                <span class="ss-grow"></span>
                <button type="button" class="ss-btn ss-btn--sm ss-btn--primary" @click="$dispatch('ss-open-move')" title="Переместить в… · M">Переместить в… <span class="ss-kbd">M</span></button>
                <button type="button" class="ss-btn ss-btn--sm" @click="$wire.setStatus(selected, 'ready')">Готово</button>
                <button type="button" class="ss-btn ss-btn--sm" @click="$wire.setStatus(selected, 'draft')">Черновик</button>
                <button type="button" class="ss-btn ss-btn--sm" @click="$wire.setStatus(selected, 'hidden')">Скрыть</button>
                <button type="button" class="ss-btn ss-btn--sm" @click="$wire.setStatus(selected, 'shown')">Показать</button>
                <button type="button" class="ss-btn ss-btn--sm ss-btn--icon" title="Удалить…"
                        @click="ask({title: 'Удалить ' + selected.length + ' стр.?', text: 'Картинки со всеми версиями и тексты будут удалены без возможности отмены.', record: selected.length + ' выбранных страниц', verb: 'Удалить', danger: true, action: 'deletePages', args: [selected.slice()], clear: true})">⌫</button>
                <button type="button" class="ss-btn ss-btn--sm ss-btn--icon ss-btn--ghost" @click="clear()" title="Снять выделение · Esc">✕</button>
            </div>

            <div class="ss-grid">
                @forelse($sections as $sec)
                    <section class="ss-grid__section" wire:key="sec-{{ $sec['id'] }}" data-ss-section="{{ $sec['id'] }}">
                        @if($node === 'all' || count($sections) > 1 || ($sec['volume'] ?? null))
                            <div class="ss-grid__sechead">
                                @if($sec['volume'])<span>{{ $sec['volume'] }}</span>@endif
                                <b>@if($sec['no'])Гл. {{ $sec['no'] }} · @endif{{ $sec['title'] }}</b>
                                <span>{{ count($sec['cards']) }} стр.</span>
                            </div>
                        @endif
                        @if(! $sec['cards'])
                            <div class="ss-grid__empty" @dragover.prevent @drop="dropOnNode($event, '{{ $sec['id'] }}')">Пустая глава — перетащите сюда страницы или файлы</div>
                        @endif
                        <div class="ss-grid__cards">
                            @foreach($sec['cards'] as $c)
                                <div class="ss-card" wire:key="card-{{ $c['id'] }}" data-ss-card="{{ $c['id'] }}" draggable="true"
                                     @if($selected === $c['id']) data-current @endif @if($c['hidden']) data-hidden @endif
                                     :data-selected="isSel('{{ $c['id'] }}') ? '' : null" :data-dragging="dragIds && dragIds.indexOf('{{ $c['id'] }}') >= 0 ? '' : null"
                                     @click="click($event, '{{ $c['id'] }}')" @dblclick="window.location = @js(route(config('story-studio.routes.reader.name').'show', ['story' => $story->public_id, 'page' => $c['id'], 'preview' => 1]))"
                                     @dragstart="dragStart($event, '{{ $c['id'] }}')" @dragover="dragOverCard($event, '{{ $c['id'] }}')" @drop="dropOnCard($event, '{{ $sec['id'] }}')">
                                    <div class="ss-card__art">
                                        <div class="ss-card__fit" style="--ar: {{ $c['ar'] }}">
                                            @include('story-studio::studio.partials.art', ['p' => $c])
                                        </div>
                                        <div class="ss-card__check" @click.stop="toggle('{{ $c['id'] }}')" title="Выбрать · ⌘-клик"><span x-show="isSel('{{ $c['id'] }}')">✓</span></div>
                                        @if($c['versions'] > 1)<div class="ss-card__ver" title="Есть старые версии">v{{ $c['versions'] }}</div>@endif
                                    </div>
                                    <div class="ss-card__meta">
                                        <b>{{ str_pad((string) $c['n'], 3, '0', STR_PAD_LEFT) }}</b>
                                        <span class="ss-faint">·{{ $c['nInChapter'] }}</span>
                                        <span class="ss-grow"></span>
                                        @if($c['hasText'])<span class="ss-muted" title="Есть текст">¶</span>@endif
                                        <span class="ss-card__dot {{ $c['status'] === 'ready' ? 'ss-card__dot--ready' : '' }} {{ $c['hidden'] ? 'ss-card__dot--hidden' : '' }}"
                                              title="{{ $c['hidden'] ? 'Скрыта' : ($c['status'] === 'ready' ? 'Готово' : 'Черновик') }}"></span>
                                        <span class="ss-card__tag" title="{{ $typeNames[$c['type']] ?? '' }}">{{ $tags[$c['type']] ?? '?' }}</span>
                                    </div>
                                    <template x-if="dropAt && dropAt.id === '{{ $c['id'] }}' && dropAt.side === 'l'"><div class="ss-card__bar ss-card__bar--l"></div></template>
                                    <template x-if="dropAt && dropAt.id === '{{ $c['id'] }}' && dropAt.side === 'r'"><div class="ss-card__bar ss-card__bar--r"></div></template>
                                </div>
                            @endforeach
                        </div>
                    </section>
                @empty
                    <div class="ss-grid__empty" style="height:220px;flex-direction:column;gap:8px">
                        <b style="font-size:15px;color:var(--ss-fg)">Здесь пока пусто</b>
                        <span>Перетащите сюда папку, файлы, ZIP или CBZ — до записи покажем превью.</span>
                    </div>
                @endforelse
            </div>

            <div class="ss-dropzone" x-show="fileOver" x-cloak><b>Отпустите — покажем превью до записи</b><span>папка · файлы · ZIP · CBZ</span></div>
        </main>

        {{-- ── inspector ────────────────────────────────────────── --}}
        <aside class="ss-insp" :data-open="inspOpen ? '' : null" wire:key="insp-{{ $selected ?? 'none' }}">
            @if($page)
                <div class="ss-insp__head">
                    <div class="ss-grow" style="display:flex;flex-direction:column;gap:2px">
                        <b>Страница {{ str_pad((string) $page->n, 3, '0', STR_PAD_LEFT) }}</b>
                        <span class="ss-ellipsis">@if($page->chapter_no){{ $page->n_in_chapter }}-я в гл. {{ $page->chapter_no }} · {{ $page->node?->title }}@else без главы @endif</span>
                    </div>
                    <button type="button" class="ss-btn ss-btn--sm ss-btn--icon" wire:click="stepPage(-1)" title="Предыдущая · ⇧↑">↑</button>
                    <button type="button" class="ss-btn ss-btn--sm ss-btn--icon" wire:click="stepPage(1)" title="Следующая · ⇧↓">↓</button>
                    <button type="button" class="ss-btn ss-btn--sm ss-btn--icon ss-btn--ghost ss-only-narrow" @click="inspOpen = false" title="Закрыть">✕</button>
                </div>
                <div class="ss-insp__body" x-data="{ over: false }">
                    <div class="ss-insp__preview" @dragover.prevent.stop="over = true" @dragleave="over = false"
                         @drop.prevent.stop="over = false; if ($event.dataTransfer.files[0]) $wire.upload('replacement', $event.dataTransfer.files[0])">
                        <div class="ss-card__fit" style="--ar: {{ $pageAr }}">
                            @include('story-studio::studio.partials.art', ['p' => [
                                'type' => $page->type, 'thumb' => $imageUrls['src'] ?? null, 'missing' => in_array($page->type, ['image', 'spread', 'panorama'], true) && ! $imageUrls,
                                'caption' => $page->caption, 'n' => $page->n, 'status' => $page->status]])
                        </div>
                        <div class="ss-insp__drop" x-show="over" x-cloak>Отпустите, чтобы заменить картинку.<br>Место, тексты и заметки останутся.</div>
                    </div>
                    <div class="ss-insp__ver">
                        <span class="ss-mono">v{{ $image ? \Ombabush\StoryStudio\Support\Version::number($image) : 0 }}</span><span>·</span>
                        <button type="button" wire:click="$toggle('showVersions')">История версий</button>
                        <span class="ss-grow"></span>
                        <label style="cursor:pointer;text-decoration:underline;text-underline-offset:3px">Заменить…<input type="file" accept="image/*" style="display:none" wire:model="replacement"></label>
                    </div>
                    @if($showVersions)
                        <div class="ss-insp__versions">
                            @forelse($versions as $v)
                                <div wire:key="ver-{{ $v->uuid }}">
                                    <span class="ss-mono" style="width:26px">v{{ \Ombabush\StoryStudio\Support\Version::number($v) }}</span>
                                    <span class="ss-grow ss-muted ss-ellipsis">{{ $v->created_at?->format('d.m.Y H:i') }} · {{ $v->name }}@if(\Ombabush\StoryStudio\Support\Version::broken($v)) · битый файл @endif</span>
                                    @if((int) $v->id === (int) $page->current_media_id)
                                        <span class="ss-mono ss-label--accent" style="font-size:10px">ТЕКУЩАЯ</span>
                                    @else
                                        <button type="button" class="ss-btn ss-btn--sm" style="height:26px" wire:click="useVersion('{{ $v->uuid }}')">Вернуть</button>
                                    @endif
                                </div>
                            @empty
                                <div class="ss-muted">Картинок у страницы нет</div>
                            @endforelse
                        </div>
                    @endif
                    <a href="{{ $readerUrl }}" class="ss-btn" style="justify-content:center">Читать отсюда <span class="ss-mono">→</span></a>
                    <button type="button" class="ss-btn" style="justify-content:center" wire:click="$set('ixOpen', true)" title="Области на рисунке и ссылки в тексте">◇ Интерактив · {{ $ixCount }}</button>

                    <div class="ss-field">
                        <span class="ss-label">Тип</span>
                        <div class="ss-seg ss-seg--mono">
                            @foreach($typeNames as $t => $label)
                                <button type="button" wire:click="$set('form.type', '{{ $t }}')" title="{{ $label }}" aria-pressed="{{ ($form['type'] ?? '') === $t ? 'true' : 'false' }}">{{ $tags[$t] }}</button>
                            @endforeach
                        </div>
                    </div>
                    <div class="ss-field">
                        <span class="ss-label">Статус</span>
                        <div style="display:flex;align-items:center;gap:10px">
                            <div class="ss-seg ss-grow">
                                <button type="button" wire:click="$set('form.status', 'draft')" aria-pressed="{{ ($form['status'] ?? '') === 'draft' ? 'true' : 'false' }}">Черновик</button>
                                <button type="button" wire:click="$set('form.status', 'ready')" aria-pressed="{{ ($form['status'] ?? '') === 'ready' ? 'true' : 'false' }}">Готово</button>
                            </div>
                            <span class="ss-muted" style="font-size:12px">Скрыта</span>
                            <button type="button" class="ss-switch" role="switch" aria-checked="{{ ! empty($form['hidden']) ? 'true' : 'false' }}"
                                    wire:click="$set('form.hidden', {{ ! empty($form['hidden']) ? 'false' : 'true' }})" title="Скрытая страница не показывается читателям вовсе"></button>
                        </div>
                    </div>
                    <label class="ss-field">
                        <span class="ss-label">{{ $page->type === 'text' ? 'Заголовок' : 'Подпись · 1–2 строки' }}</span>
                        <input class="ss-input" wire:model.blur="form.caption" placeholder="Подпись под страницей">
                    </label>
                    <label class="ss-field">
                        <span class="ss-label">Текст · рассказ, сценарий</span>
                        <textarea class="ss-textarea ss-textarea--serif" rows="7" wire:model.blur="form.body" placeholder="Новый абзац — пустая строка"></textarea>
                    </label>
                    <label class="ss-field">
                        <span class="ss-label ss-label--accent">Заметка автора · служебное</span>
                        <textarea class="ss-textarea" rows="2" wire:model.blur="form.note"></textarea>
                    </label>
                    <label class="ss-field">
                        <span class="ss-label">Реплики из баблов</span>
                        <textarea class="ss-textarea ss-textarea--mono" rows="2" wire:model.blur="form.dialogue" placeholder="ИМЯ: реплика"></textarea>
                    </label>
                    <label class="ss-field">
                        <span class="ss-label">Alt для скринридера</span>
                        <input class="ss-input" wire:model.blur="form.alt" placeholder="Что на странице">
                    </label>
                    <div class="ss-field">
                        <span class="ss-label">Время истории</span>
                        <div class="ss-insp__grid3">
                            <input class="ss-input" wire:model.blur="form.time_label" placeholder="Когда" title="Дата, эпоха или «раньше / позже»">
                            <input class="ss-input" wire:model.blur="form.time_place" placeholder="Где">
                            <input class="ss-input" wire:model.blur="form.time_who" placeholder="Кто">
                        </div>
                        <input class="ss-input" style="height:34px;font-size:12.5px" type="number" wire:model.blur="form.time_rank"
                               placeholder="Порядок во времени истории — число для «как случилось»">
                    </div>
                    {{-- Layout hints (PageHints): how the page pairs in a spread, how fast a panorama moves. --}}
                    <div class="ss-field">
                        <span class="ss-label">В развороте</span>
                        <div class="ss-seg">
                            @foreach(['auto' => 'Как выйдет', 'alone' => 'Всегда одна', 'start' => 'Начинает пару'] as $k => $label)
                                <button type="button" wire:click="$set('form.hints.pair', '{{ $k }}')" aria-pressed="{{ ($form['hints']['pair'] ?? 'auto') === $k ? 'true' : 'false' }}"
                                        title="{{ ['auto' => 'Пару решает ширина картинки', 'alone' => 'Обложка, широкий рисунок — без соседки', 'start' => 'С этой страницы начинается пара: сдвиг на ½'][$k] }}">{{ $label }}</button>
                            @endforeach
                        </div>
                        @if($page->type === 'panorama')
                            <input class="ss-input" style="height:34px;font-size:12.5px" type="number" min="3" max="600" wire:model.blur="form.hints.pan.seconds" placeholder="Панорама: секунд на проход (Кино)">
                        @endif
                    </div>
                    @php($tl = count($edition->translated()))
                    <details class="ss-field" wire:key="tr-page-{{ $page->public_id }}">
                        <summary class="ss-label">Языки · {{ $tl ? strtoupper(implode(' ', $edition->translated())) : 'только '.$edition->original }}</summary>
                        <div>
                            @livewire('story-studio.studio.translations', ['story' => $story->public_id, 'subject' => 'page', 'id' => $page->public_id], key('trp-'.$page->public_id))
                            @if($page->node && $tl)
                                <span class="ss-label" style="display:block;margin-top:10px">Глава {{ $page->chapter_no }} · {{ $page->node->title }}</span>
                                @livewire('story-studio.studio.translations', ['story' => $story->public_id, 'subject' => 'node', 'id' => $page->node->public_id], key('trn-'.$page->node->public_id.'-'.$page->public_id))
                            @endif
                        </div>
                    </details>
                    <details class="ss-field" wire:key="mu-page-{{ $page->public_id }}">
                        <summary class="ss-label">Музыка</summary>
                        <div>
                            <span class="ss-label" style="display:block">Эта страница</span>
                            @livewire('story-studio.studio.attachments', ['story' => $story->public_id, 'scope' => 'page', 'id' => $page->public_id], key('mup-'.$page->public_id))
                            @if($page->node)
                                <span class="ss-label" style="display:block;margin-top:10px">Вся глава {{ $page->chapter_no }} · {{ $page->node->title }}</span>
                                @livewire('story-studio.studio.attachments', ['story' => $story->public_id, 'scope' => 'node', 'id' => $page->node->public_id], key('mun-'.$page->node->public_id.'-'.$page->public_id))
                            @endif
                            <span class="ss-faint" style="font-size:11.5px">Читатель слышит страницу, иначе главу, иначе издание (⚙).</span>
                        </div>
                    </details>
                    <div class="ss-insp__danger">
                        @if($page->node_id && $page->n_in_chapter > 1)
                            <button type="button" class="ss-btn" title="Страницы с этой и дальше уйдут в новую главу"
                                    @click="ask({title: 'Разрезать главу перед стр. {{ $page->n }}?', text: 'Страницы с этой и до конца главы уйдут в новую главу сразу после неё.', record: @js('Гл. '.$page->chapter_no.' · '.$page->node?->title), verb: 'Разрезать', action: 'splitHere'})">✂ Разрезать главу перед этой страницей</button>
                        @endif
                        <button type="button" class="ss-btn ss-muted"
                                @click="ask({title: 'Удалить страницу {{ $page->n }}?', text: 'Картинка со всеми версиями и тексты будут удалены без возможности отмены.', record: @js('стр. '.$page->n.($page->caption ? ' · '.$page->caption : '')), verb: 'Удалить', danger: true, action: 'deletePages', args: [[@js($page->public_id)]]})">⌫ Удалить страницу…</button>
                    </div>
                </div>
                <div class="ss-insp__foot"><span wire:loading.remove>● сохранено</span><span wire:loading>… сохраняю</span><span>⇧↓ / ⇧↑ — соседняя страница, не выходя из поля</span></div>
            @else
                <div class="ss-insp__empty">Выберите страницу — здесь появятся её тексты, тип и статус.</div>
            @endif
        </aside>
    </div>

    {{-- ── Move to… (M) ──────────────────────────────────────────── --}}
    <template x-if="move">
        <div class="ss-modal" @click.self="move = null">
            <div class="ss-dialog">
                <div class="ss-dialog__head">
                    <div class="ss-grow"><div class="ss-dialog__title">Переместить <span x-text="move.ids.length"></span> стр.</div><div class="ss-dialog__sub">порядок внутри выбора сохранится</div></div>
                    <button type="button" class="ss-btn ss-btn--icon ss-btn--ghost" @click="move = null" title="Отмена · Esc">✕</button>
                </div>
                <div class="ss-dialog__body" style="flex-direction:row;flex-wrap:wrap;align-items:flex-start">
                    <div style="flex:1 1 260px;display:flex;flex-direction:column;gap:4px">
                        <span class="ss-label" style="padding:0 4px 6px">Куда</span>
                        <template x-for="t in JSON.parse(document.getElementById('ss-move-targets').textContent)" :key="t.id">
                            <button type="button" class="ss-radio" :aria-checked="move.to === t.id" @click="move.to = t.id; move.before = null">
                                <span class="ss-mono ss-faint" x-text="t.no"></span>
                                <span class="ss-grow ss-ellipsis" x-text="(t.volume ? t.volume + ' · ' : '') + t.title"></span>
                                <span class="ss-mono ss-faint" x-text="t.count"></span>
                            </button>
                        </template>
                    </div>
                    <div style="flex:1 1 220px;display:flex;flex-direction:column;gap:6px">
                        <span class="ss-label" style="padding:0 4px 6px">Место в главе</span>
                        <button type="button" class="ss-radio" :aria-checked="move.mode === 'end'" @click="move.mode = 'end'">В конец главы</button>
                        <button type="button" class="ss-radio" :aria-checked="move.mode === 'before'" @click="move.mode = 'before'">Перед страницей…</button>
                        <template x-if="move.mode === 'before'">
                            <select class="ss-input" x-model="move.before">
                                <option value="">— выберите —</option>
                                <template x-for="o in beforeOptions()" :key="o.id">
                                    <option :value="o.id" x-text="o.label"></option>
                                </template>
                            </select>
                        </template>
                        <p class="ss-muted" style="font-size:12px;line-height:1.5;margin:6px 0 0">Сквозная нумерация обновится во всём издании; тексты, заметки и время страниц не меняются. После переноса — «Отменить».</p>
                    </div>
                </div>
                <div class="ss-dialog__foot">
                    <span class="ss-dialog__hint">Enter — переместить · Esc — отмена</span>
                    <button type="button" class="ss-btn" @click="move = null">Отмена</button>
                    <button type="button" class="ss-btn ss-btn--primary" @click="doMove()" :disabled="!move.to || (move.mode === 'before' && !move.before)">Переместить</button>
                </div>
            </div>
        </div>
    </template>

    @if($exportErrors)
        <div class="ss-modal" wire:key="export-errors">
            <div class="ss-dialog ss-dialog--sm" role="alertdialog" aria-modal="true">
                <div class="ss-dialog__head"><div class="ss-dialog__title ss-grow">Экспорт не выпущен</div></div>
                <div class="ss-dialog__body">
                    <div class="ss-confirm__text">Интерактив издания содержит ошибки. Исправьте их в слое «Интерактив» нужных страниц — черновик сохраняется, но наружу уходит только целиком правильное.</div>
                    <ul style="margin:0;padding-left:18px;font-size:13px">@foreach($exportErrors as $e)<li>{{ $e }}</li>@endforeach</ul>
                </div>
                <div class="ss-dialog__foot" style="justify-content:flex-end"><button type="button" class="ss-btn" wire:click="$set('exportErrors', [])">Понятно</button></div>
            </div>
        </div>
    @endif

    {{-- ── Interaction layer of the selected page (SR-02) ─────────────── --}}
    @if($ixOpen && $page)
        <div class="ss-modal" wire:key="ix-{{ $page->public_id }}" @ss-ix-close.window="$wire.set('ixOpen', false)">
            <div class="ss-dialog ss-dialog--xl">
                @livewire('story-studio.studio.interactions', ['story' => $story->public_id, 'page' => $page->public_id], key('ixl-'.$page->public_id))
            </div>
        </div>
    @endif

    {{-- ── the one confirm dialog ─────────────────────────────────── --}}
    <template x-if="confirm">
        {{-- Above any other dialog: it can be asked from inside one (removing music in Настройки). --}}
        <div class="ss-modal" style="z-index:70" @click.self="confirm = null">
            <div class="ss-dialog ss-dialog--sm" role="alertdialog" aria-modal="true">
                <div class="ss-dialog__head"><div class="ss-dialog__title ss-grow" x-text="confirm.title"></div></div>
                <div class="ss-dialog__body">
                    <div class="ss-confirm__text" x-text="confirm.text"></div>
                    <template x-if="confirm.record"><div class="ss-confirm__record" x-text="confirm.record"></div></template>
                </div>
                <div class="ss-dialog__foot" style="justify-content:flex-end">
                    <button type="button" class="ss-btn" @click="confirm = null">Отмена</button>
                    <button type="button" class="ss-btn" :class="confirm.danger ? 'ss-btn--danger' : 'ss-btn--primary'" @click="confirmYes()" x-text="confirm.verb"></button>
                </div>
            </div>
        </div>
    </template>

    {{-- ── upload preview ─────────────────────────────────────────── --}}
    @if($plan)
        @php($keep = collect($plan['groups'])->sum(fn ($g) => collect($g['files'])->where('skip', false)->count()))
        <div class="ss-modal" wire:key="plan">
            <div class="ss-dialog ss-dialog--lg">
                <div class="ss-dialog__head">
                    <div class="ss-grow"><div class="ss-dialog__title">Превью загрузки</div><div class="ss-dialog__sub">{{ $plan['count'] }} файлов · {{ count($plan['groups']) }} {{ count($plan['groups']) === 1 ? 'группа' : 'групп' }}</div></div>
                    <button type="button" class="ss-btn ss-btn--icon ss-btn--ghost" wire:click="cancelUpload" title="Отмена">✕</button>
                </div>
                <div class="ss-upload">
                    <div class="ss-upload__list">
                        @foreach($plan['groups'] as $gi => $g)
                            <div class="ss-upload__group">
                                <div class="ss-upload__ghead">
                                    <span class="ss-mono ss-faint" style="font-size:11px">{{ $g['folder'] }}</span><span class="ss-mono ss-faint">→</span>
                                    <b class="ss-grow" style="font-size:13px">{{ $uploadWhere === 'here' ? 'в выбранную главу' : $g['chapter'] }}</b>
                                    <span class="ss-mono ss-faint" style="font-size:11px">{{ count($g['files']) }} файлов</span>
                                </div>
                                @foreach($g['files'] as $fi => $f)
                                    <div class="ss-upload__row" @if($f['skip']) data-skip @endif wire:key="up-{{ $gi }}-{{ $fi }}-{{ md5($f['name']) }}">
                                        <div class="ss-upload__thumb" @if($f['preview']) style="background-image:url('{{ $f['preview'] }}')" @endif></div>
                                        <span class="ss-mono ss-ellipsis" style="font-size:12px;width:200px">{{ $f['name'] }}</span>
                                        <span class="ss-mono ss-faint" style="font-size:11px;width:84px">{{ $f['width'] ? $f['width'].'×'.$f['height'] : 'не картинка?' }}</span>
                                        <span class="ss-grow" style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
                                            <span class="ss-card__tag">{{ $tags[$f['type']] }}</span>
                                            @if($f['type'] !== 'image')<span class="ss-muted" style="font-size:12px">{{ $f['type'] === 'panorama' ? 'шире вдвое → панорама' : 'шире высоты → разворот' }}</span>@endif
                                            @if($f['dup'])<span class="ss-label--accent" style="font-size:12px">уже есть в издании</span>@endif
                                        </span>
                                        @if($f['dup'])
                                            <button type="button" class="ss-btn ss-btn--sm" wire:click="toggleSkip({{ $gi }}, {{ $fi }})">{{ $f['skip'] ? 'Пропустить ✓' : 'Загрузить' }}</button>
                                        @endif
                                        <button type="button" class="ss-btn ss-btn--sm ss-btn--icon" wire:click="shiftFile({{ $gi }}, {{ $fi }}, -1)" title="Выше">↑</button>
                                        <button type="button" class="ss-btn ss-btn--sm ss-btn--icon" wire:click="shiftFile({{ $gi }}, {{ $fi }}, 1)" title="Ниже">↓</button>
                                    </div>
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                    <div class="ss-upload__side">
                        <div class="ss-field">
                            <span class="ss-label">Куда вставить</span>
                            <button type="button" class="ss-radio" aria-checked="{{ $uploadWhere === 'here' ? 'true' : 'false' }}" wire:click="$set('uploadWhere', 'here')">В конец выбранной главы</button>
                            <button type="button" class="ss-radio" aria-checked="{{ $uploadWhere === 'new' ? 'true' : 'false' }}" wire:click="$set('uploadWhere', 'new')">Новые главы — по папкам</button>
                        </div>
                        <div class="ss-field ss-muted" style="font-size:12px;line-height:1.5">
                            <span class="ss-label">Правила</span>
                            <div>Естественная сортировка: 2 раньше 10.</div>
                            <div>Подпапки и архивы → главы.</div>
                            <div>Шире высоты — разворот, шире вдвое — панорама.</div>
                            <div>Дубли уже загруженного пропускаются.</div>
                        </div>
                    </div>
                </div>
                <div class="ss-dialog__foot">
                    <span class="ss-grow ss-muted" style="font-size:13px">Страницы встанут черновиками — читатели их не увидят, пока не «Готово».</span>
                    <button type="button" class="ss-btn" wire:click="cancelUpload">Отмена</button>
                    <button type="button" class="ss-btn ss-btn--primary" wire:click="commitUpload" wire:loading.attr="disabled" @disabled(! $keep)>Загрузить {{ $keep }}</button>
                </div>
            </div>
        </div>
    @endif

    {{-- ── edition settings ───────────────────────────────────────── --}}
    @if($settings)
        <div class="ss-modal" wire:key="settings">
            <div class="ss-dialog">
                <div class="ss-dialog__head"><div class="ss-grow"><div class="ss-dialog__title">Настройки издания</div><div class="ss-dialog__sub">что видят читатели</div></div>
                    <button type="button" class="ss-btn ss-btn--icon ss-btn--ghost" wire:click="$set('settings', [])">✕</button></div>
                <div class="ss-dialog__body">
                  {{-- The form holds the fields that save together; translations and music below save as typed
                       and carry forms of their own, which may not nest — hence form="ss-settings" on Save. --}}
                  <form id="ss-settings" wire:submit="saveSettings" style="display:flex;flex-direction:column;gap:14px">
                    <label class="ss-field"><span class="ss-label">Название</span><input class="ss-input" wire:model="settings.title" required>@error('settings.title')<span class="ss-label--accent">{{ $message }}</span>@enderror</label>
                    <label class="ss-field"><span class="ss-label">Авторы</span><input class="ss-input" wire:model="settings.authors"></label>
                    <label class="ss-field"><span class="ss-label">Описание</span><textarea class="ss-textarea" rows="3" wire:model="settings.description"></textarea></label>
                    <div class="ss-field"><span class="ss-label">Направление чтения</span>
                        <div class="ss-seg">
                            <button type="button" wire:click="$set('settings.direction', 'ltr')" aria-pressed="{{ ($settings['direction'] ?? 'ltr') === 'ltr' ? 'true' : 'false' }}">Слева → направо</button>
                            <button type="button" wire:click="$set('settings.direction', 'rtl')" aria-pressed="{{ ($settings['direction'] ?? '') === 'rtl' ? 'true' : 'false' }}">Справа ← налево</button>
                        </div>
                    </div>
                    {{-- DATA-MODEL §1.1: the edition's languages and the views its readers may choose. --}}
                    <div class="ss-insp__grid3" style="grid-template-columns:110px 1fr">
                        <label class="ss-field"><span class="ss-label">Язык оригинала</span><input class="ss-input ss-mono" wire:model="settings.original" placeholder="ru">@error('settings.original')<span class="ss-label--accent">{{ $message }}</span>@enderror</label>
                        <label class="ss-field"><span class="ss-label">Переводы · коды через запятую</span><input class="ss-input ss-mono" wire:model="settings.translations" placeholder="en, de">@error('settings.translations')<span class="ss-label--accent">{{ $message }}</span>@enderror</label>
                    </div>
                    @php($catalog = app(\Ombabush\StoryStudio\Support\ReaderCatalog::class))
                    @php($ui = $catalog->uiLanguage(null))
                    <div class="ss-field"><span class="ss-label">Виды для читателя · ★ — открывается первым</span>
                        <div style="display:flex;flex-wrap:wrap;gap:6px">
                            @foreach($readerViews as $v)
                                <span class="ss-seg" wire:key="v-{{ $v }}">
                                    <button type="button" wire:click="$set('settings.views.{{ $v }}', {{ ! empty($settings['views'][$v]) ? 'false' : 'true' }})" aria-pressed="{{ ! empty($settings['views'][$v]) ? 'true' : 'false' }}">{{ $catalog->t('views.'.$v, $ui) }}</button>
                                    <button type="button" wire:click="$set('settings.defaultView', '{{ $v }}')" aria-pressed="{{ ($settings['defaultView'] ?? '') === $v ? 'true' : 'false' }}" title="Открывать этим видом" @disabled(empty($settings['views'][$v]))>★</button>
                                </span>
                            @endforeach
                        </div>
                        @error('settings.views')<span class="ss-label--accent">{{ $message }}</span>@enderror
                        <span class="ss-faint" style="font-size:11.5px">«Сценарий» видят только редакторы — это не настройка издания.</span>
                    </div>
                  </form>
                  {{-- Saved as they are typed, apart from the form above: translations and music of the edition. --}}
                    <details class="ss-field" open><summary class="ss-label">Переводы названия и описания</summary>
                        @livewire('story-studio.studio.translations', ['story' => $story->public_id, 'subject' => 'story', 'id' => $story->public_id], key('trs-'.$story->public_id.'-'.md5(implode(',', $edition->languages))))</details>
                    <details class="ss-field" open><summary class="ss-label">Музыка издания · микс и «слушать в…»</summary>
                        @livewire('story-studio.studio.attachments', ['story' => $story->public_id, 'scope' => 'story', 'kinds' => ['track', 'link']], key('mus-'.$story->public_id))</details>
                </div>
                <div class="ss-dialog__foot" style="justify-content:flex-end">
                    <button type="button" class="ss-btn" wire:click="$set('settings', [])">Отмена</button>
                    <button type="submit" form="ss-settings" class="ss-btn ss-btn--primary">Сохранить</button>
                </div>
            </div>
        </div>
    @endif

    {{-- ── toast with Undo ─────────────────────────────────────────── --}}
    @if($toast)
        <div class="ss-toast" wire:key="toast-{{ md5(json_encode($toast)) }}" x-data="{ on: true }" x-show="on" x-init="setTimeout(() => on = false, 9000)">
            <span>{{ $toast['text'] }}</span>
            @if($toast['undo'])
                <button type="button" class="ss-btn ss-btn--sm ss-btn--primary" wire:click="undo('{{ $toast['undo'] }}')">Отменить</button>
            @endif
            <button type="button" class="ss-btn ss-btn--icon ss-btn--ghost" wire:click="hideToast" title="Скрыть">✕</button>
        </div>
    @endif
</div>
