{{--
    A page's words under it, in the reader's language: caption (with its text links while the original
    is shown), the essential branches as buttons in every view (CANON SR-04/SR-06), then the body.
    Alpine scope: `s` = the page slot.
--}}
<template x-if="s.p && s.p.type !== 'text' && !ixBlockShown('caption', s.id)">
    <div class="ss-reader__caption" :lang="pageLang(s.p)" x-text="tx(s.p, 'caption') || ''"></div>
</template>
<template x-if="s.p && s.p.type !== 'text' && ixBlockShown('caption', s.id)">
    <div class="ss-reader__caption" :lang="pageLang(s.p)"><template x-for="r in ixBlock('caption', s.id).runs" :key="r.id"><span><template x-if="ixRunItem(ixBlock('caption', s.id), r, s.id)"><button type="button" class="ss-ix__link" :data-ss-ix-proxy="ixRunItem(ixBlock('caption', s.id), r, s.id).id" @click="ixAct(ixRunItem(ixBlock('caption', s.id), r, s.id).id, 'text')" x-text="r.text"></button></template><template x-if="!ixRunItem(ixBlock('caption', s.id), r, s.id)"><span x-text="r.text"></span></template></span></template></div>
</template>
<template x-if="ixEssential(s.id).length">
    <nav class="ss-ix__continue" :aria-label="ixEssential(s.id)[0].group ? ixText(ixEssential(s.id)[0].group.label) : t('ix_continue')">
        <span class="ss-label" x-text="ixEssential(s.id)[0].group ? ixText(ixEssential(s.id)[0].group.label) : t('ix_continue')"></span>
        <template x-for="it in ixEssential(s.id)" :key="it.id">
            <button type="button" class="ss-btn" :data-ss-ix-proxy="it.id" @click="ixAct(it.id, 'list')" @focus="ixFocusItem(it.id)" @blur="ixBlurItem()" x-text="ixText(it.label)"></button>
        </template>
    </nav>
</template>
<template x-if="s.p && s.p.type !== 'text' && s.p.type !== 'mixed' && tx(s.p, 'paragraphs').length">
    <div class="ss-reader__body" :lang="pageLang(s.p)"><template x-for="(para, i) in tx(s.p, 'paragraphs')" :key="i"><p x-text="para"></p></template></div>
</template>
