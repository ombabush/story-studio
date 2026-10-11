{{--
    One Reader. Data arrives as JSON in a <script type="application/json"> block, never inside an
    attribute (an apostrophe in a caption would end the attribute). All text is rendered with x-text,
    so story content is never interpreted as HTML.

    Nothing here knows a view, an effect or a string by name: views are layout × fit × text from the
    catalog (docs/DATA-MODEL.md §3), every interface string is t('key') from lang/*/reader.php.
--}}
@php($cfg = $payload + ['syncUrl' => $fullscreen, 'theme' => $theme])
<script type="application/json" id="{{ $uid }}-data">@json($cfg)</script>
<div class="ss-root ss-reader {{ $fullscreen ? 'ss-reader--fixed' : '' }}" data-ss-root data-ss-theme="{{ $theme }}"
     x-data="ssReader(JSON.parse(document.getElementById('{{ $uid }}-data').textContent))"
     @if($fullscreen) @keydown.window="key($event)" @else tabindex="0" @keydown="key($event)" @endif
     @mousemove="wake()" @pointermove="edgeMove($event)"
     :data-ss-theme="theme" :style="'--ss-text-scale:' + (textSize / 100)"
     :data-chrome="chrome ? 'shown' : 'hidden'" :data-full="full ? '' : null"
     :data-ui="ui" :data-top="topShown() ? '' : null" :data-bottom="bottomShown() ? '' : null"
     dir="ltr" lang="{{ $payload['catalog']['uiLang'] }}">

    {{-- Edges mode: at rest only quiet indicators; the panels open from their edge (Story Reader v2 HANDOFF). --}}
    <template x-if="ui === 'edges'">
        <div class="ss-reader__marks-ind" aria-hidden="true">
            <i class="ss-reader__ind-top"></i><i class="ss-reader__ind-left"></i>
            <i class="ss-reader__ind-progress" :style="'width:' + progressPct() + '%'"></i>
        </div>
    </template>

    <header class="ss-reader__bar" @mouseleave="leavePanel('t')">
        @if($payload['back'])
            <a href="{{ $payload['back'] }}" class="ss-btn ss-btn--icon" :title="t({{ $payload['editor'] ? "'back_studio'" : "'back'" }})">←</a>
        @endif
        <div class="ss-reader__title ss-grow">
            <b class="ss-ellipsis" x-text="storyTitle()">{{ $story->title }}</b>
            <span class="ss-ellipsis" x-text="where()"></span>
        </div>
        @if($payload['preview'])
            <span class="ss-studio__pub ss-studio__pub--on" :title="t('preview_hint')" x-text="t('preview')"></span>
        @endif
        <template x-if="ixBack.length">
            <button type="button" class="ss-btn" @click="ixReturn()" :title="t('return_hint')"><span x-text="t('return')"></span><span class="ss-faint" x-text="' · ' + t('page_n', { n: (manifest[indexOf(ixBack[ixBack.length - 1].page)] || {}).n })"></span></button>
        </template>
        <div class="ss-seg ss-seg--mono ss-only-wide" role="group" :aria-label="t('view')">
            <template x-for="v in viewList()" :key="v.key">
                <button type="button" :aria-pressed="view === v.key" @click="setView(v.key)" :title="v.label + (v.hotkey ? ' · ' + v.hotkey : '')" x-text="v.label"></button>
            </template>
        </div>
        <template x-if="cfg.edition.languages.length > 1">
            <button type="button" class="ss-btn ss-btn--sm ss-mono" @click="langOpen = !langOpen" :aria-expanded="langOpen" :title="t('language_hint')" x-text="pageLang(page()).toUpperCase()"></button>
        </template>
        <template x-if="ixPanelItems().length || (ixData() && ixData().stale)">
            <button type="button" class="ss-btn" @click="ixListOpen = !ixListOpen" :aria-expanded="ixListOpen" :title="t('ix_hint')">◇ <span x-text="ixPanelItems().length"></span></button>
        </template>
        <div class="ss-reader__counter">
            <button type="button" @click="gotoOpen = !gotoOpen" :title="t('goto_hint')">
                <span x-text="current() ? current().n : 0"></span> / <span x-text="manifest.length"></span>
            </button>
        </div>
        <button type="button" class="ss-btn ss-btn--icon" @click="toggleFull()" :title="t('fullscreen')">⛶</button>
    </header>

    <div class="ss-reader__goto" x-show="gotoOpen" x-cloak @click.outside="gotoOpen = false">
        <span class="ss-label" x-text="t('goto')"></span>
        <form @submit.prevent="submitGoto()" style="display:flex;gap:6px">
            <input data-ss-goto class="ss-input" type="number" min="1" :max="manifest.length" x-model="gotoValue" :placeholder="t('goto_ph')">
            <button class="ss-btn ss-btn--primary" type="submit">→</button>
        </form>
    </div>

    <main class="ss-reader__stage" :data-view="view" :data-layout="vw().layout" :data-fit="vw().fit" :data-text="vw().text"
          @pointerdown="pDown($event)" @pointermove="pMove($event)" @pointerup="pUp($event)" @pointercancel="ixCancel($event)">
        <template x-if="!manifest.length">
            <div class="ss-reader__end"><b x-text="t('empty_title')"></b><span class="ss-muted" x-text="t('empty_text')"></span></div>
        </template>

        {{-- Page layouts: one page, or a pair (RTL reverses the order, never mirrors the art). --}}
        <template x-if="manifest.length && paged()">
            <div class="ss-reader__frame" :data-layout="vw().layout" :data-fit="vw().fit" :data-text="vw().text"
                 :style="vw().layout === 'pair' && cfg.story.direction === 'rtl' ? 'flex-direction:row-reverse' : ''">
                <template x-for="s in slots()" :key="s.id">
                    <div class="ss-reader__slot" :data-count="slots().length">
                        @include('story-studio::reader.page-box')
                        <template x-if="vw().text === 'beside'"><div class="ss-reader__words">@include('story-studio::reader.text')</div></template>
                    </div>
                </template>
                {{-- text = script (Сценарий): the author's working view — the page and, beside it, text, balloon lines and notes. --}}
                <template x-if="vw().text === 'script' && slots()[0]">
                    <aside class="ss-reader__script" x-data="{ get s() { return slots()[0] } }">
                        <template x-if="s.p">
                            <div style="display:flex;flex-direction:column;gap:12px">
                                <div class="ss-label" x-text="t('page_n', { n: s.n }) + (s.p.chapter ? ' · ' + t('chapter_short', { no: s.p.chapter.no }) + ' · ' + chTitle(chapterOf(entry(s.k))) : '')"></div>
                                <b class="ss-reader__script-cap" x-text="tx(s.p, 'caption') || '—'"></b>
                                <template x-if="s.p.internal && tx(s.p, 'dialogue').length">
                                    <div><span class="ss-label" x-text="t('script_lines')"></span><template x-for="(l, i) in tx(s.p, 'dialogue')" :key="i"><p class="ss-mono" style="font-size:13px" x-text="l"></p></template></div>
                                </template>
                                <template x-if="s.p.internal && s.p.internal.note">
                                    <div><span class="ss-label ss-label--accent" x-text="t('script_note')"></span><p x-text="s.p.internal.note"></p></div>
                                </template>
                                @include('story-studio::reader.text')
                            </div>
                        </template>
                    </aside>
                </template>
            </div>
        </template>
        <template x-if="manifest.length && paged() && vw().text === 'below'">
            <div class="ss-reader__words" x-data="{ get s() { return slots()[0] } }">@include('story-studio::reader.text')</div>
        </template>
        <template x-if="manifest.length && paged() && vw().text === 'subtitle' && page()">
            <div class="ss-reader__subtitle" x-data="{ get s() { return slots()[0] } }">@include('story-studio::reader.text')</div>
        </template>

        {{-- Grid (Обзор): the first action opens a page, never a tiny hotspot (C-22). --}}
        <template x-if="manifest.length && gridded()">
            <div class="ss-reader__grid" :style="'--cells:' + Math.ceil(Math.sqrt(vw().cells))">
                <template x-for="s in slots()" :key="s.id">
                    <button type="button" class="ss-reader__cell" @click="openFromGrid(s.k)" :aria-label="t('open_page', { n: s.n })" :aria-current="s.k === pos ? 'true' : 'false'">
                        <span class="ss-reader__cell-art" :style="'--ar:' + s.ar">
                            <template x-if="s.p && s.p.image"><img :src="s.p.image.thumb" alt="" loading="lazy"></template>
                            <template x-if="s.p && !s.p.image"><span class="ss-reader__cell-text" :lang="pageLang(s.p)" x-text="tx(s.p, 'caption') || '…'"></span></template>
                        </span>
                        <span class="ss-mono ss-faint" x-text="t('page_n', { n: s.n }) + (ixItems(s.id).length ? ' · ◇ ' + ixItems(s.id).length : '')"></span>
                    </button>
                </template>
            </div>
        </template>

        {{-- Story layout (История): the text leads; each page's picture stays beside its paragraphs while they are read. --}}
        <template x-if="manifest.length && vw().layout === 'story'">
            <div class="ss-reader__scroll ss-reader__story" x-ref="scroller" @scroll.passive="onScroll()">
                <template x-for="s in slots()" :key="s.id">
                    <section class="ss-reader__story-item" :data-ss-scroll-k="s.k" :data-text="s.p && s.p.type === 'text' ? '' : null">
                        <template x-if="mounted(s.k)">
                            <div class="ss-reader__story-row">
                                <div class="ss-reader__story-text" :lang="pageLang(s.p)">
                                    <template x-if="s.p && tx(s.p, 'caption')"><h3 x-text="tx(s.p, 'caption')"></h3></template>
                                    <template x-for="(para, i) in tx(s.p, 'paragraphs')" :key="i"><p x-text="para"></p></template>
                                    <template x-if="ixEssential(s.id).length">
                                        <nav class="ss-ix__continue" style="justify-content:flex-start">
                                            <template x-for="it in ixEssential(s.id)" :key="it.id"><button type="button" class="ss-btn" :data-ss-ix-proxy="it.id" @click="ixAct(it.id, 'list')" x-text="ixText(it.label)"></button></template>
                                        </nav>
                                    </template>
                                </div>
                                <template x-if="!s.p || s.p.type !== 'text'"><div class="ss-reader__story-art">@include('story-studio::reader.page-box')</div></template>
                            </div>
                        </template>
                        <template x-if="!mounted(s.k)"><div class="ss-reader__ghost" style="height:60vh"></div></template>
                    </section>
                </template>
            </div>
        </template>

        {{-- Scroll layout (Лента): one column, native scroll; only pages near the reading point are mounted (C-23). --}}
        <template x-if="manifest.length && vw().layout === 'scroll'">
            <div class="ss-reader__scroll" x-ref="scroller" @scroll.passive="onScroll()">
                <template x-for="s in slots()" :key="s.id">
                    <section class="ss-reader__scroll-item" :data-ss-scroll-k="s.k">
                        <template x-if="mounted(s.k)"><div>@include('story-studio::reader.page-box')<template x-if="vw().text !== 'none'"><div class="ss-reader__words">@include('story-studio::reader.text')</div></template></div></template>
                        <template x-if="!mounted(s.k)"><div class="ss-reader__page ss-reader__ghost" :style="'--ar:' + s.ar"></div></template>
                    </section>
                </template>
            </div>
        </template>

        <template x-if="ixZoom">
            <button type="button" class="ss-btn ss-reader__unzoom" @click="ixZoom = null" title="Esc" x-text="t('ix_unzoom')"></button>
        </template>
        <template x-if="ixSel && ixDocked() && ixItem(ixSel)">
            @include('story-studio::reader.preview', ['anchored' => false])
        </template>
        <template x-if="ixChooser">
            <div class="ss-ix__preview ss-ix__preview--dock ss-ix__chooser" role="dialog" :aria-label="t('ix_several')">
                <span class="ss-label" x-text="t('ix_several_ask')"></span>
                <template x-for="id in ixChooser" :key="id">
                    <button type="button" class="ss-btn" @click="ixAct(id, 'chooser')"><span x-text="ixText(ixItem(id).label)"></span><span class="ss-faint" x-text="'· ' + ixKind(ixItem(id))"></span></button>
                </template>
                <button type="button" class="ss-btn ss-btn--ghost" @click="ixChooser = null" x-text="t('cancel')"></button>
            </div>
        </template>

        <template x-if="paged()">
            <div>
                <button type="button" class="ss-reader__zone ss-reader__zone--prev" @click="backward()" :title="t('prev')" :aria-label="t('prev')">‹</button>
                <button type="button" class="ss-reader__zone ss-reader__zone--next" @click="forward()" :title="t('next')" :aria-label="t('next')">›</button>
            </div>
        </template>
    </main>

    <footer class="ss-reader__foot" @mouseleave="leavePanel('b')">
        <template x-if="cfg.timeline">
            <div class="ss-seg ss-seg--surface" style="flex:none">
                <button type="button" :aria-pressed="order === 'told'" @click="setOrder('told')" :title="t('told_hint')" x-text="t('told')"></button>
                <button type="button" :aria-pressed="order === 'happened'" @click="setOrder('happened')" :title="t('happened_hint')" x-text="t('happened')"></button>
            </div>
        </template>
        <div class="ss-reader__timeline" :style="cfg.story.direction === 'rtl' ? 'flex-direction:row-reverse' : ''">
            <template x-for="s in segments()" :key="s.key + ':' + s.from">
                <div class="ss-reader__seg" :class="s.on && 'ss-reader__seg--on'" :style="'flex:' + s.dots.length">
                    <div class="ss-reader__seg-label" x-text="s.label"></div>
                    <div class="ss-reader__track" :style="cfg.story.direction === 'rtl' ? 'flex-direction:row-reverse' : ''">
                        <template x-for="k in s.dots" :key="k">
                            <button type="button" class="ss-reader__dot" @click="go(k)" :title="dotTitle(k)" :aria-current="k === pos ? 'true' : 'false'" :data-read="k < pos ? '' : null"></button>
                        </template>
                    </div>
                </div>
            </template>
        </div>
        <div class="ss-reader__now" x-text="page() && tx(page(), 'time').label ? tx(page(), 'time').label : ''"></div>
    </footer>

    <div class="ss-reader__hint" x-show="hint" x-cloak x-text="hint"></div>

    {{-- Содержание: position, the reader's marks, chapters with a map of pages. --}}
    <aside class="ss-reader__toc" x-show="tocOpen" x-cloak :aria-label="t('contents')" @mouseleave="leavePanel('l')">
        <div style="display:flex;align-items:center;gap:8px">
            <div class="ss-grow"><b x-text="storyTitle()"></b><div class="ss-mono ss-faint" style="font-size:11px" x-text="t('page_of', { n: current() ? current().n : 0, total: manifest.length }) + ' · ' + progressPct() + '%'"></div></div>
            <button type="button" class="ss-btn ss-btn--icon ss-btn--ghost" @click="tocOpen = false; tocPinned = false" :aria-label="t('contents_close')">✕</button>
        </div>
        <div class="ss-reader__tocbar"><i :style="'width:' + progressPct() + '%'"></i></div>
        <template x-if="markList('bookmark').length || markList('note').length">
            <div class="ss-reader__toc-marks">
                <template x-for="m in markList('bookmark')" :key="'b' + m.page"><button type="button" class="ss-reader__toc-row" @click="goById(m.page)"><span class="ss-reader__toc-ico">◆</span><span class="ss-grow" x-text="t('page_n', { n: m.n })"></span></button></template>
                <template x-for="m in markList('note')" :key="'n' + m.page"><button type="button" class="ss-reader__toc-row" @click="goById(m.page)"><span class="ss-reader__toc-ico">●</span><span class="ss-grow ss-ellipsis" x-text="t('page_n', { n: m.n }) + ' · ' + m.text"></span></button></template>
            </div>
        </template>
        <template x-for="c in chapterMap()" :key="c.id || 'root'">
            <div class="ss-reader__toc-ch" :data-current="c.current ? '' : null">
                <div class="ss-reader__toc-title"><span class="ss-mono ss-faint" x-text="c.no"></span> <span x-text="c.id ? chTitle(c) : c.title"></span></div>
                <div class="ss-reader__toc-map">
                    <template x-for="p in c.pages" :key="p.id"><button type="button" :class="'ss-reader__toc-pg ' + pageState(p)" @click="go(p.k)" :title="t('page_n', { n: p.n })" :aria-label="t('page_n', { n: p.n })" :aria-current="p.k === pos ? 'page' : null"></button></template>
                </div>
            </div>
        </template>
    </aside>

    {{-- The rail: every service control as a visible 44-px button (INPUT «Края экрана»). --}}
    <nav class="ss-reader__rail" :aria-label="t('rail')">
        <button type="button" @click="toggleToc()" :aria-pressed="tocOpen" :title="t('contents_hint')"><span>☰</span><em x-text="t('contents')"></em></button>
        <button type="button" @click="viewMenu = !viewMenu" :aria-expanded="viewMenu" :title="t('view')"><span>▦</span><em x-text="t('view')"></em></button>
        <template x-if="cfg.edition.languages.length > 1">
            <button type="button" @click="langOpen = !langOpen" :aria-pressed="langOpen" :title="t('language_hint')"><span class="ss-mono" style="font-size:12px" x-text="pageLang(page()).toUpperCase()"></span><em x-text="t('language')"></em></button>
        </template>
        <template x-if="hasMusic()">
            <button type="button" @click="musicOpen = !musicOpen" :aria-pressed="musicOpen" :title="t('music_hint')"><span>♪</span><em x-text="t('music')"></em></button>
        </template>
        <template x-if="ixPanelItems().length || (ixData() && ixData().stale)">
            <button type="button" @click="ixListOpen = !ixListOpen" :aria-pressed="ixListOpen" :title="t('ix_hint')"><span>◇</span><em x-text="t('ix') + ' · ' + ixPanelItems().length"></em></button>
        </template>
        <button type="button" @click="toggleBookmark()" :aria-pressed="current() && isBookmarked(current().id)" :title="t('bookmark_hint')"><span x-text="current() && isBookmarked(current().id) ? '◆' : '◇'"></span><em x-text="t('bookmark')"></em></button>
        <button type="button" @click="openNote()" :aria-pressed="!!(current() && noteOf(current().id))" :title="t('note_hint')"><span>✎</span><em x-text="t('note')"></em></button>
        <button type="button" @click="toggleFull()" :title="t('fullscreen')"><span>⛶</span><em x-text="t('screen')"></em></button>
    </nav>
    <div class="ss-reader__viewmenu" x-show="viewMenu" x-cloak @click.outside="viewMenu = false">
        <span class="ss-label" x-text="t('view')"></span>
        <template x-for="v in viewList()" :key="v.key"><button type="button" class="ss-btn" :aria-pressed="view === v.key" @click="setView(v.key); viewMenu = false" x-text="v.label"></button></template>
        <span class="ss-label" x-text="t('interface')"></span>
        <div class="ss-seg"><template x-for="u in cfg.catalog.uiModes" :key="u"><button type="button" :aria-pressed="ui === u" @click="setUi(u)" x-text="t('uiModes.' + u)"></button></template></div>
        <template x-if="cfg.catalog.themes.length > 1">
            <div style="display:contents">
                <span class="ss-label" x-text="t('theme')"></span>
                <div class="ss-seg"><template x-for="th in cfg.catalog.themes" :key="th"><button type="button" :aria-pressed="theme === th" @click="setTheme(th)" x-text="t('themes.' + th)"></button></template></div>
            </div>
        </template>
        <span class="ss-label" x-text="t('text_size')"></span>
        <div class="ss-seg"><button type="button" @click="setTextSize(-10)" aria-label="−">A−</button><button type="button" disabled x-text="textSize + '%'"></button><button type="button" @click="setTextSize(10)" aria-label="+">A+</button></div>
        <template x-if="cfg.catalog.uiLanguages.length > 1">
            <div style="display:contents">
                <span class="ss-label" x-text="t('ui_language')"></span>
                <div class="ss-seg"><template x-for="l in cfg.catalog.uiLanguages" :key="l.code"><button type="button" :aria-pressed="cfg.catalog.uiLang === l.code" @click="setUiLang(l.code)" x-text="l.name"></button></template></div>
            </div>
        </template>
    </div>

    {{-- Language of the text: the chain primary → fallback → original (DATA-MODEL §2 `lang`). --}}
    <aside class="ss-ix__panel" x-show="langOpen" x-cloak :aria-label="t('language')">
        <div style="display:flex;align-items:center;gap:8px"><b class="ss-grow" x-text="t('language')"></b>
            <button type="button" class="ss-btn ss-btn--icon ss-btn--ghost" @click="langOpen = false" :aria-label="t('close')">✕</button></div>
        <ul class="ss-ix__list">
            <template x-for="l in cfg.edition.languages" :key="l">
                <li><button type="button" class="ss-ix__item" :aria-pressed="langWanted() === l" @click="setLang(l)">
                    <span x-text="langName(l)"></span><span class="ss-faint" x-text="l === cfg.edition.original ? t('language_original') : l.toUpperCase()"></span>
                </button></li>
            </template>
        </ul>
        <template x-if="cfg.edition.languages.length > 2">
            <label class="ss-field"><span class="ss-label" x-text="t('language_fallback')"></span>
                <select class="ss-input" style="height:36px" @change="setLang(undefined, $event.target.value || null)">
                    <option value="" :selected="!(prefs.lang || {}).fallback" x-text="langName(cfg.edition.original) + ' · ' + t('language_original')"></option>
                    <template x-for="l in cfg.edition.languages.filter(x => x !== cfg.edition.original)" :key="l"><option :value="l" :selected="(prefs.lang || {}).fallback === l" x-text="langName(l)"></option></template>
                </select></label>
        </template>
        <template x-if="page() && langNote(page())"><p class="ss-muted" style="margin:0;font-size:13px" x-text="langNote(page())"></p></template>
    </aside>

    {{-- Music: what plays here (page → chapter → edition) and where else to listen. Nothing plays by itself. --}}
    <aside class="ss-ix__panel" x-show="musicOpen" x-cloak :aria-label="t('music')">
        <div style="display:flex;align-items:center;gap:8px"><b class="ss-grow" x-text="t('music')"></b>
            <button type="button" class="ss-btn ss-btn--icon ss-btn--ghost" @click="musicOpen = false" :aria-label="t('close')">✕</button></div>
        <span class="ss-label" x-text="t('music_now') + (musicHere().scope ? ' · ' + t('music_' + musicHere().scope) : '')"></span>
        <template x-if="!musicHere().tracks.length"><p class="ss-muted" style="margin:0;font-size:13px" x-text="t('music_none')"></p></template>
        <ul class="ss-ix__list">
            <template x-for="tr in musicHere().tracks" :key="tr.id">
                <li><button type="button" class="ss-ix__item" :disabled="!tr.url" @click="tr.url && listen(tr.url)" :title="tr.url ? t('music_external', { host: host(tr.url) }) : ''">
                    <span x-text="tr.title"></span><span class="ss-faint" x-text="[tr.artist, duration(tr.seconds), tr.url ? host(tr.url) + ' ↗' : ''].filter(Boolean).join(' · ')"></span>
                </button></li>
            </template>
        </ul>
        <template x-if="musicPlaces().length">
            <div style="display:flex;flex-direction:column;gap:4px">
                <template x-for="pl in musicPlaces()" :key="pl.key">
                    <button type="button" class="ss-ix__item" @click="go(pl.k)" :title="t('music_go')">
                        <span x-text="pl.label"></span><span class="ss-faint" x-text="pl.tracks.map(x => x.title).join(' · ') + ' →'"></span>
                    </button>
                </template>
            </div>
        </template>
        <template x-if="musicHere().scope !== 'story' && music().story.length">
            <div><span class="ss-label" x-text="t('music_story')"></span>
                <ul class="ss-ix__list"><template x-for="tr in music().story" :key="tr.id"><li><button type="button" class="ss-ix__item" :disabled="!tr.url" @click="tr.url && listen(tr.url)"><span x-text="tr.title"></span><span class="ss-faint" x-text="[tr.artist, duration(tr.seconds)].filter(Boolean).join(' · ')"></span></button></li></template></ul></div>
        </template>
        <template x-if="music().links.length">
            <div style="display:flex;flex-wrap:wrap;gap:6px"><template x-for="l in music().links" :key="l.id"><button type="button" class="ss-btn ss-btn--sm" @click="listen(l.url)" :title="t('music_external', { host: host(l.url) })" x-text="l.label + ' ↗'"></button></template></div>
        </template>
    </aside>

    <template x-if="noteOpen">
        <div class="ss-reader__note" role="dialog" :aria-label="t('note')">
            <span class="ss-label" x-text="t('note_title', { n: current() ? current().n : '' })"></span>
            <textarea class="ss-textarea" rows="5" data-ss-note x-model="noteText" @keydown.meta.enter="saveNote()" @keydown.ctrl.enter="saveNote()"></textarea>
            <div style="display:flex;gap:6px;justify-content:flex-end">
                <button type="button" class="ss-btn" @click="noteOpen = false" x-text="t('cancel')"></button>
                <button type="button" class="ss-btn ss-btn--primary" @click="saveNote()" x-text="t('save')"></button>
            </div>
        </div>
    </template>

    <aside class="ss-ix__panel" x-show="ixListOpen" x-cloak :aria-label="t('ix_places')">
        <div style="display:flex;align-items:center;gap:8px"><b class="ss-grow" x-text="t('ix_on_page', { n: ixPanelItems().length })"></b>
            <button type="button" class="ss-btn ss-btn--icon ss-btn--ghost" @click="ixListOpen = false; ixReveal = false" :aria-label="t('close')">✕</button></div>
        <template x-if="ixData() && ixData().stale">
            <p class="ss-muted" style="margin:0" x-text="t('ix_stale')"></p>
        </template>
        <div class="ss-seg ss-seg--mono" role="group" :aria-label="t('ix')">
            <template x-for="m in cfg.catalog.ixModes" :key="m"><button type="button" :aria-pressed="ixMode === m" @click="setIxMode(m)" x-text="t('ixModes.' + m)"></button></template>
        </div>
        <label class="ss-field"><span class="ss-label" x-text="t('ix_highlight')"></span>
            <select class="ss-input" style="height:36px" @change="setIxFx($event.target.value)">
                <template x-for="f in ixFxList()" :key="f.key"><option :value="f.key" :selected="ixFx === f.key" x-text="f.label"></option></template>
            </select></label>
        <details><summary class="ss-muted" style="font-size:12px;cursor:pointer" x-text="t('ix_by_kind')"></summary>
            <template x-for="k in ixKindsList()" :key="k">
                <label style="display:flex;align-items:center;gap:8px;margin-top:6px;font-size:13px"><span class="ss-grow" x-text="ixKind({ kind: k })"></span>
                    <select class="ss-input" style="height:32px;width:auto" @change="setIxFx(null, k, $event.target.value)">
                        <option value="" :selected="!ixKindFx[k]" x-text="t('ix_as_general')"></option>
                        <template x-for="f in ixFxList().filter(f => f.key !== 'author')" :key="f.key"><option :value="f.key" :selected="ixKindFx[k] === f.key" x-text="f.label"></option></template>
                    </select></label>
            </template>
        </details>
        <template x-if="ixPanelItems().some(i => i.anchor.type === 'region') && ixMode !== 'off'">
            <button type="button" class="ss-btn" :aria-pressed="ixReveal" @click="ixRevealAll(!ixReveal)" x-text="ixReveal ? t('ix_hide') : t('ix_reveal')"></button>
        </template>
        <ul class="ss-ix__list">
            <template x-for="it in ixPanelItems().filter(i => ixMode !== 'off' || i.essential || i.anchor.type === 'text')" :key="it.id">
                <li><button type="button" class="ss-ix__item" :data-ss-ix-proxy="it.id" @click="ixAct(it.id, 'list')" @focus="ixFocusItem(it.id)" @blur="ixBlurItem()" @mouseenter="ixFocusItem(it.id)" @mouseleave="ixBlurItem()">
                    <span x-text="ixText(it.label)"></span><span class="ss-faint" x-text="ixKind(it) + ' · ' + ixVerb(it)"></span>
                </button></li>
            </template>
        </ul>
    </aside>

    <template x-if="ixCard">
        <aside class="ss-ix__card" role="dialog" :aria-modal="ixCardModal() ? 'true' : null" :aria-label="ixText(ixCard.title)" @keydown.escape.stop="ixCloseCard()" @keydown="ixTrap($event)">
            <div style="display:flex;align-items:flex-start;gap:8px">
                <div class="ss-grow"><span class="ss-label" x-text="ixCardKind(ixCard)"></span><h2 x-text="ixText(ixCard.title)"></h2></div>
                <button type="button" class="ss-btn ss-btn--icon" data-ss-ix-card-close @click="ixCloseCard()" :aria-label="t('ix_card_close')">✕</button>
            </div>
            <p class="ss-ix__body" x-text="ixText(ixCard.body)"></p>
        </aside>
    </template>

    <div class="ss-reader__hint" x-show="ixNotice" x-cloak x-text="ixNotice" role="status"></div>
</div>
