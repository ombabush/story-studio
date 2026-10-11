{{--
    A page's picture at card or inspector size — the same .ss-art the Reader draws, so the grid shows what
    a reader will see: the image, a grey stand-in for a missing one, or the text page set as a book page.
    $p: type, thumb, missing, caption, n, status.
--}}
<div class="ss-art">
    @if($p['type'] === 'text')
        <div class="ss-art__text"><h3>{{ $p['caption'] ?: 'Текстовая страница' }}</h3></div>
    @elseif(! empty($p['missing']))
        <div class="ss-art__missing"><span>нет картинки</span><small>стр. {{ $p['n'] }}</small></div>
    @elseif(! empty($p['thumb']))
        <img src="{{ $p['thumb'] }}" alt="" loading="lazy" decoding="async" draggable="false">
    @endif
    @if(($p['status'] ?? null) === 'draft')
        <div class="ss-art__draft">черновик</div>
    @endif
</div>
