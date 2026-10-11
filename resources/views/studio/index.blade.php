{{--
    The shelf: every edition this user may edit, newest change first, and "+ Новое издание".
    Theme follows the editor's choice (localStorage 'ss-theme'), so leaving an edition does not flash.
--}}
@php
    $studio = config('story-studio.routes.studio.name');
@endphp
<div class="ss-root ss-shelf" data-ss-root
     x-data="{ theme: (() => { try { return localStorage.getItem('ss-theme') === 'light' ? 'light' : 'dark' } catch (e) { return 'dark' } })() }"
     :data-ss-theme="theme">
    <header class="ss-studio__head">
        <div class="ss-studio__logo">{{ mb_substr(config('story-studio.host_label') ?: 'S', 0, 1) }}</div>
        <nav class="ss-studio__crumbs">
            @if(config('story-studio.host_home'))
                <a href="{{ config('story-studio.host_home') }}" class="ss-faint">{{ config('story-studio.host_label') ?: 'Home' }}</a><i>/</i>
            @endif
            <b>Издания</b>
        </nav>
        <div class="ss-grow"></div>
        <label class="ss-btn" title="Импорт .story.zip — покажу, что внутри, до записи">⇧ Импорт<input type="file" accept=".zip,application/zip" style="display:none" wire:model="package"></label>
        <button type="button" class="ss-btn ss-btn--primary" wire:click="$set('creating', true)">+ Новое издание</button>
    </header>

    <div class="ss-shelf__body">
        @if($stories->isEmpty())
            <div class="ss-grid__empty">
                Изданий пока нет. Создайте первое — комикс, мангу или книгу-историю — и загрузите страницы папкой, ZIP или CBZ.
            </div>
        @else
            <div class="ss-shelf__grid">
                @foreach($stories as $s)
                    @php $c = $counts[$s->id] ?? null; @endphp
                    <a href="{{ route($studio.'edit', ['story' => $s->public_id]) }}" class="ss-shelf__card" wire:key="story-{{ $s->public_id }}">
                        <div class="ss-shelf__cover" @if($covers[$s->id]) style="background-image:url('{{ $covers[$s->id] }}')" @endif></div>
                        <div>
                            <b class="ss-ellipsis" style="display:block">{{ $s->title }}</b>
                            <span class="ss-muted ss-ellipsis" style="display:block">{{ $s->authors ?: '—' }}</span>
                        </div>
                        <div class="ss-mono ss-faint" style="font-size:11px">
                            {{ (int) ($c->c ?? 0) }} стр.
                            @if(($c->d ?? 0) > 0) · {{ (int) $c->d }} черновиков @endif
                            · {{ $s->direction === 'rtl' ? 'RTL' : 'LTR' }}
                            · {{ $s->published_at ? 'опубликовано' : 'не опубликовано' }}
                        </div>
                    </a>
                @endforeach
            </div>
        @endif
    </div>

    @if($preview)
        <div class="ss-modal" wire:key="import-preview">
            <div class="ss-dialog ss-dialog--sm">
                <div class="ss-dialog__head"><div class="ss-grow"><div class="ss-dialog__title">Импорт .story.zip</div>
                    <div class="ss-dialog__sub">{{ $preview['summary']['title'] ?? '' }}</div></div></div>
                <div class="ss-dialog__body">
                    @if($preview['summary'])
                        <div class="ss-mono ss-muted" style="font-size:12px">{{ $preview['summary']['pages'] }} стр. · {{ $preview['summary']['chapters'] }} глав · {{ $preview['summary']['interactions'] }} интерактивных мест · {{ $preview['summary']['cards'] }} карточек</div>
                    @endif
                    @if($preview['existing'])
                        <div class="ss-confirm__text">Этот пакет уже импортирован — откроется то же издание, дубля не будет.</div>
                    @endif
                    @if(! $preview['ok'])
                        <div class="ss-confirm__text">Пакет не будет импортирован: ничего не записано, исходный файл сохранён.</div>
                        <ul style="margin:0;padding-left:18px;font-size:12px" class="ss-muted">@foreach($preview['errors'] as $e)<li>{{ $e }}</li>@endforeach</ul>
                    @else
                        <div class="ss-muted" style="font-size:13px">Издание появится в Studio приватным — опубликовать его можно потом, как любое другое.</div>
                    @endif
                </div>
                <div class="ss-dialog__foot" style="justify-content:flex-end">
                    <button type="button" class="ss-btn" wire:click="cancelImport">Отмена</button>
                    @if($preview['ok'])
                        <button type="button" class="ss-btn ss-btn--primary" wire:click="importPackage">{{ $preview['existing'] ? 'Открыть' : 'Импортировать' }}</button>
                    @endif
                </div>
            </div>
        </div>
    @endif

    @if($creating)
        <div class="ss-modal" wire:key="create">
            <form class="ss-dialog ss-dialog--sm" wire:submit="create">
                <div class="ss-dialog__head"><div class="ss-grow"><div class="ss-dialog__title">Новое издание</div><div class="ss-dialog__sub">страницы загрузите на следующем экране</div></div>
                    <button type="button" class="ss-btn ss-btn--icon ss-btn--ghost" wire:click="$set('creating', false)">✕</button></div>
                <div class="ss-dialog__body">
                    <label class="ss-field"><span class="ss-label">Название</span><input class="ss-input" wire:model="title" required autofocus>@error('title')<span class="ss-label--accent">{{ $message }}</span>@enderror</label>
                    <label class="ss-field"><span class="ss-label">Авторы</span><input class="ss-input" wire:model="authors"></label>
                    <div class="ss-field"><span class="ss-label">Направление чтения</span>
                        <div class="ss-seg">
                            <button type="button" wire:click="$set('direction', 'ltr')" aria-pressed="{{ $direction === 'ltr' ? 'true' : 'false' }}">Слева → направо</button>
                            <button type="button" wire:click="$set('direction', 'rtl')" aria-pressed="{{ $direction === 'rtl' ? 'true' : 'false' }}">Справа ← налево</button>
                        </div>
                    </div>
                </div>
                <div class="ss-dialog__foot" style="justify-content:flex-end">
                    <button type="button" class="ss-btn" wire:click="$set('creating', false)">Отмена</button>
                    <button type="submit" class="ss-btn ss-btn--primary">Создать</button>
                </div>
            </form>
        </div>
    @endif
</div>
