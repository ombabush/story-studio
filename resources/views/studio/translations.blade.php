{{-- Translations of one subject (Studio\Translations). The original sits above each field as the reference. --}}
@php
    $names = ['title' => 'Название', 'description' => 'Описание', 'caption' => 'Подпись', 'body' => 'Текст', 'alt' => 'Alt',
        'time_label' => 'Когда', 'time_place' => 'Где', 'dialogue' => 'Реплики · служебное'];
    $long = ['description', 'body', 'dialogue'];
@endphp
<div class="ss-tr">
    @if(! $languages)
        <p class="ss-muted" style="margin:0;font-size:12.5px">Переводов нет: языки издания задаются в ⚙ «Настройки издания». Оригинал — {{ $originalLang }}.</p>
    @else
        <div class="ss-seg ss-seg--mono" role="group" aria-label="Язык перевода">
            @foreach($languages as $l)
                <button type="button" wire:click="$set('lang', '{{ $l }}')" aria-pressed="{{ $lang === $l ? 'true' : 'false' }}">{{ strtoupper($l) }}@if($done[$l] ?? 0) <span class="ss-faint">·{{ $done[$l] }}</span>@endif</button>
            @endforeach
        </div>
        @foreach($fields as $f => $value)
            @if(trim($original[$f] ?? '') !== '' || trim($value) !== '')
                <label class="ss-field" wire:key="tr-{{ $lang }}-{{ $f }}">
                    <span class="ss-label">{{ $names[$f] ?? $f }} · {{ $lang }}</span>
                    <span class="ss-tr__orig" title="Оригинал · {{ $originalLang }}">{{ \Illuminate\Support\Str::limit($original[$f] ?? '', 220) }}</span>
                    @if(in_array($f, $long, true))
                        <textarea class="ss-textarea {{ $f === 'dialogue' ? 'ss-textarea--mono' : 'ss-textarea--serif' }}" rows="{{ $f === 'body' ? 5 : 2 }}" wire:model.blur="fields.{{ $f }}" placeholder="перевод — пусто значит оригинал"></textarea>
                    @else
                        <input class="ss-input" wire:model.blur="fields.{{ $f }}" placeholder="перевод — пусто значит оригинал">
                    @endif
                </label>
            @endif
        @endforeach
        @if($error)<span class="ss-label--accent" style="font-size:12px">{{ $error }}</span>@endif
    @endif
</div>
