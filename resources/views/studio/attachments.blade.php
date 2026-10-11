{{-- Music and links of one scope (Studio\AttachmentList). Removing asks through the editor's one dialog. --}}
@php($kindNames = ['track' => 'Дорожка', 'link' => 'Ссылка'])
<div class="ss-att">
    @foreach($items as $a)
        <div class="ss-att__row" wire:key="att-{{ $a->public_id }}">
            @if($editing === $a->public_id)
                <div class="ss-att__form">
                    @if($a->kind === 'track')
                        <input class="ss-input" wire:model="edit.title" placeholder="Название">
                        <div style="display:flex;gap:6px"><input class="ss-input" wire:model="edit.artist" placeholder="Исполнитель"><input class="ss-input" style="width:84px" wire:model="edit.time" placeholder="3:25"></div>
                        <input class="ss-input" wire:model="edit.url" placeholder="https://… — где слушать (необязательно)">
                    @else
                        <input class="ss-input" wire:model="edit.label" placeholder="Spotify">
                        <input class="ss-input" wire:model="edit.url" placeholder="https://…">
                    @endif
                    <div style="display:flex;gap:6px;justify-content:flex-end">
                        <button type="button" class="ss-btn ss-btn--sm" wire:click="$set('editing', null)">Отмена</button>
                        <button type="button" class="ss-btn ss-btn--sm ss-btn--primary" wire:click="saveEdit">Сохранить</button>
                    </div>
                </div>
            @else
                <span class="ss-att__ico">{{ $a->kind === 'track' ? '♪' : '↗' }}</span>
                <span class="ss-grow ss-ellipsis">
                    @if($a->kind === 'track')
                        <b>{{ $a->data['title'] }}</b>@if(! empty($a->data['artist'])) <span class="ss-muted">· {{ $a->data['artist'] }}</span>@endif
                        @if(! empty($a->data['seconds']))<span class="ss-faint ss-mono"> {{ intdiv($a->data['seconds'], 60) }}:{{ str_pad((string) ($a->data['seconds'] % 60), 2, '0', STR_PAD_LEFT) }}</span>@endif
                        @if(! empty($a->data['url']))<span class="ss-faint"> · {{ parse_url($a->data['url'], PHP_URL_HOST) }} ↗</span>@endif
                    @else
                        <b>{{ $a->data['label'] }}</b> <span class="ss-faint">· {{ parse_url($a->data['url'], PHP_URL_HOST) }}</span>
                    @endif
                </span>
                <button type="button" class="ss-btn ss-btn--sm ss-btn--icon ss-btn--ghost" wire:click="move('{{ $a->public_id }}', -1)" title="Выше">↑</button>
                <button type="button" class="ss-btn ss-btn--sm ss-btn--icon ss-btn--ghost" wire:click="move('{{ $a->public_id }}', 1)" title="Ниже">↓</button>
                <button type="button" class="ss-btn ss-btn--sm ss-btn--icon ss-btn--ghost" wire:click="startEdit('{{ $a->public_id }}')" title="Изменить">✎</button>
                <button type="button" class="ss-btn ss-btn--sm ss-btn--icon ss-btn--ghost ss-att__del" title="Убрать…"
                        @click="ask({title: @js('Убрать «'.($a->data['title'] ?? $a->data['label']).'»?'), text: 'Читатели больше не увидят эту запись здесь; после публикации она уйдёт и из копии в Cloud.', record: @js($kindNames[$a->kind].' · '.($a->data['title'] ?? $a->data['label'])), verb: 'Убрать', danger: true, action: 'removeAttachment', args: [@js($a->public_id)]})">✕</button>
            @endif
        </div>
    @endforeach
    <form class="ss-att__form" wire:submit="add">
        @if(count($kinds) > 1)
            <div class="ss-seg ss-seg--mono">
                @foreach($kinds as $k)<button type="button" wire:click="$set('draft.kind', '{{ $k }}')" aria-pressed="{{ $draft['kind'] === $k ? 'true' : 'false' }}">{{ $kindNames[$k] }}</button>@endforeach
            </div>
        @endif
        @if($draft['kind'] === 'track')
            <div style="display:flex;gap:6px"><input class="ss-input" wire:model="draft.title" placeholder="Название дорожки"><input class="ss-input" style="width:84px" wire:model="draft.time" placeholder="3:25"></div>
            <div style="display:flex;gap:6px"><input class="ss-input" wire:model="draft.artist" placeholder="Исполнитель"><input class="ss-input" wire:model="draft.url" placeholder="https://… (необязательно)"></div>
        @else
            <div style="display:flex;gap:6px"><input class="ss-input" style="width:140px" wire:model="draft.label" placeholder="Spotify"><input class="ss-input" wire:model="draft.url" placeholder="https://…"></div>
        @endif
        <div style="display:flex;gap:6px;align-items:center">
            @if($error)<span class="ss-label--accent ss-grow" style="font-size:12px">{{ $error }}</span>@else<span class="ss-grow"></span>@endif
            <button type="submit" class="ss-btn ss-btn--sm">+ Добавить</button>
        </div>
    </form>
</div>
