{{--
    One chapter row of the tree: select, rename on double click, drop pages onto it (= to the end of the
    chapter), merge with the next and delete. $c is a tree entry (ReadingOrder::tree), $loose = not in a volume.
--}}
<div class="ss-tree__ch {{ $loose ? 'ss-tree__ch--loose' : '' }}" role="button" tabindex="0"
     wire:key="ch-{{ $c['id'] }}-{{ md5($c['title']) }}" aria-current="{{ $node === $c['id'] ? 'true' : 'false' }}"
     wire:click="selectNode('{{ $c['id'] }}')" @keydown.enter="$wire.selectNode('{{ $c['id'] }}')"
     @dblclick.stop="renaming = '{{ $c['id'] }}'"
     @dragover.prevent="dropNode = '{{ $c['id'] }}'" @dragleave="dropNode = null"
     @drop="dropOnNode($event, '{{ $c['id'] }}')" :data-drop="dropNode === '{{ $c['id'] }}' ? '' : null">
    <span class="ss-tree__no">{{ $c['no'] }}</span>
    <span class="ss-grow ss-ellipsis" x-show="renaming !== '{{ $c['id'] }}'">{{ $c['title'] }}</span>
    <template x-if="renaming === '{{ $c['id'] }}'">
        <input class="ss-tree__rename" value="{{ $c['title'] }}" x-init="$nextTick(() => $el.select())" @click.stop
               @keydown.enter.stop="$wire.renameNode('{{ $c['id'] }}', $el.value); renaming = null" @keydown.escape.stop="renaming = null"
               @blur="$wire.renameNode('{{ $c['id'] }}', $el.value); renaming = null">
    </template>
    <span class="ss-tree__count">{{ $c['count'] }}</span>
    <button type="button" class="ss-tree__mini" title="Слить со следующей главой…"
            @click.stop="ask({title: @js('Слить «'.$c['title'].'» со следующей главой?'), text: 'Страницы следующей главы встанут в конец этой, следующая глава исчезнет.', record: @js('Гл. '.$c['no'].' · '.$c['title']), verb: 'Слить', action: 'mergeNode', args: [@js($c['id'])]})">⇣</button>
    <button type="button" class="ss-tree__mini" title="Удалить главу…"
            @click.stop="ask({title: @js('Удалить главу «'.$c['title'].'»?'), text: @js('Глава и '.$c['count'].' стр. удалятся вместе с картинками. Отменить нельзя.'), record: @js('Гл. '.$c['no'].' · '.$c['title']), verb: 'Удалить', danger: true, action: 'deleteNode', args: [@js($c['id'])]})">✕</button>
</div>
