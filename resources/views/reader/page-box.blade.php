{{--
    One page on the stage, in every view: its art and, over full-frame art, its interaction layer on the
    same 0…1000 plane (the layer is a child of the page box, so crop, scroll and resize move both
    together). Alpine scope: `s` = { k, id, p, ar, n }.
--}}
<div class="ss-reader__page" :style="'--ar:' + s.ar" :data-ss-page="s.id" :data-zoomed="ixZoom && ixZoom.page === s.id ? '' : null">
  <div class="ss-reader__clip"><div class="ss-reader__zoom" :style="ixZoomStyle(s.id)">
    <div class="ss-art">
        <template x-if="!s.p"><div class="ss-reader__loading" x-text="t('loading')"></div></template>
        <template x-if="s.p && s.p.image && s.p.type !== 'mixed' && s.p.type !== 'text'">
            <img :src="s.p.image.src" :alt="tx(s.p, 'alt') || tx(s.p, 'caption') || ''" draggable="false" decoding="async">
        </template>
        <template x-if="s.p && s.p.missing">
            <div class="ss-art__missing"><span x-text="t('missing')"></span><small x-text="t('missing_hint', { n: s.p.n })"></small></div>
        </template>
        <template x-if="s.p && s.p.type === 'text'">
            <div class="ss-art__text" :lang="pageLang(s.p)">
                <h3 x-text="tx(s.p, 'caption')"></h3>
                <template x-for="(para, i) in tx(s.p, 'paragraphs')" :key="i"><p x-text="para"></p></template>
            </div>
        </template>
        <template x-if="s.p && s.p.type === 'mixed'">
            <div class="ss-reader__mixed">
                <template x-if="s.p.image"><img :src="s.p.image.src" :alt="tx(s.p, 'alt') || ''"></template>
                <div class="ss-art__text" :lang="pageLang(s.p)"><template x-for="(para, i) in tx(s.p, 'paragraphs')" :key="i"><p x-text="para"></p></template></div>
            </div>
        </template>
        <template x-if="s.p && s.p.draft"><div class="ss-art__draft" x-text="t('draft')"></div></template>
    </div>
    <template x-if="ixArtLive(s.id) && s.p && s.p.image && ['image', 'spread', 'panorama'].includes(s.p.type)">
        <div class="ss-ix" :data-ss-layer="s.id" @pointerleave="ixLeaveLayer()" @contextmenu.prevent>
            <div class="ss-ix__svg" x-html="ixSvg(s.id)"></div>
        </div>
    </template>
  </div></div>
    <template x-if="ixHover && !ixZoom && !ixSel && !ixChooser && ixPageOf(ixHover) === s.id && ixItem(ixHover) && ixItem(ixHover).anchor.type === 'region'">
        <div class="ss-ix__tip" :style="ixPreviewStyle(ixHover)" role="tooltip" x-text="ixText(ixItem(ixHover).label)"></div>
    </template>
    <template x-if="ixSel && !ixDocked() && ixPageOf(ixSel) === s.id && ixItem(ixSel)">
        @include('story-studio::reader.preview', ['anchored' => true])
    </template>
</div>
