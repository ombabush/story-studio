/*
 * Story Studio — browser side. Registers Alpine components into the Alpine the host already runs
 * (Livewire 3 ships one); it never starts a second copy. Loaded once per page via @storyStudioAssets.
 *
 *   ssReader  — the Reader: page turns, swipes, keys, fullscreen and order switching all happen here
 *               without a server round trip. The server is asked only for page chunks it has not sent
 *               yet and, debounced, to remember the reading position.
 *   ssEditor  — Studio grid helpers: selection (click / ⌘-click / ⇧-click), drag with an insertion bar,
 *               the Move-to dialog and the shared confirm dialog. Every change still goes through Livewire.
 */
(function () {
  if (window.__storyStudio) return;
  window.__storyStudio = true;

  function csrf() {
    var m = document.querySelector('meta[name="csrf-token"]');
    if (m) return { 'X-CSRF-TOKEN': m.getAttribute('content') };
    var c = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);
    return c ? { 'X-XSRF-TOKEN': decodeURIComponent(c[1]) } : {};
  }

  function reader(cfg) {
    return Object.assign({
      cfg: cfg,
      manifest: cfg.manifest || [],
      cache: {},
      order: 'told',
      seq: [],
      pos: 0,
      chrome: true,
      full: false,
      hint: '',
      gotoOpen: false,
      gotoValue: '',
      loading: {},
      _hideTimer: null,
      _saveTimer: null,
      _hintTimer: null,
      // View: a name from the catalog (docs/DATA-MODEL.md §3) — layout × fit × text.
      // The reader knows layouts, never view names. Remembered per edition; content never changes with it.
      view: cfg.edition.defaultView,
      _lastPaged: cfg.edition.defaultView,
      // Story Reader v2 chrome: 'edges' — only the content, service panels come from the edges;
      // 'bars' — classic bars. peek: which edge is open; pin: opened by a click, not by a passing mouse.
      ui: 'edges',
      peek: null,
      pin: false,
      tocOpen: false,
      tocPinned: false,
      viewMenu: false,
      marks: cfg.marks || [],
      noteOpen: false,
      noteText: '',
      _fromScroll: false,
      // The reader's own settings (ss_reader_prefs): server first, the local copy only covers a lost request.
      prefs: {},
      theme: cfg.theme || 'dark',
      textSize: 100,
      langOpen: false,
      musicOpen: false,
      _prefQueue: {},
      _prefTimer: {},

      init() {
        var self = this;
        (cfg.pages || []).forEach(function (p) { self.cache[p.id] = p; });
        this.loadPrefs();
        var P = this.prefs;
        if (P.order === 'happened' && cfg.timeline) this.order = 'happened';
        this.rebuild();
        var at = this.seq.indexOf(this.indexOf(cfg.start));
        this.pos = at < 0 ? 0 : at;
        this.ensure();
        if (cfg.catalog.uiModes.indexOf(P.ui) >= 0) this.ui = P.ui;
        if (this.allowed(P.view)) this.view = P.view;
        if (this.paged()) this._lastPaged = this.view;
        if (cfg.catalog.themes.indexOf(P.theme) >= 0) this.theme = P.theme;
        if (P.textSize) this.textSize = P.textSize;
        this.$watch('pos', function () { self.ensure(); self.remember(); self.syncUrl(); if (self.flowing() && !self._fromScroll) self.scrollToPos(); self._fromScroll = false; });
        // A rotation or a resize re-lays the pages out; a gesture begun on the old layout is void (C-19).
        window.addEventListener('resize', function () { self.ixCancel(); });
        document.addEventListener('fullscreenchange', function () { self.full = document.fullscreenElement === self.$root; self.wake(); });
        this.ixInit();
      },

      // ── interface strings (catalog.strings, lang/*/reader.php) ──
      t(key, vars) {
        var v = key.split('.').reduce(function (o, k) { return o && o[k]; }, cfg.catalog.strings);
        if (typeof v !== 'string') return key;
        if (vars) Object.keys(vars).sort(function (a, b) { return b.length - a.length; }).forEach(function (k) { v = v.split(':' + k).join(vars[k]); });
        return v;
      },

      // ── the reader's own settings: general or for this edition, saved debounced ──
      loadPrefs() {
        var local = {};
        try { Object.assign(local, JSON.parse(localStorage.getItem('ss-prefs') || '{}'), JSON.parse(localStorage.getItem('ss-prefs:' + cfg.story.id) || '{}')); } catch (e) {}
        this.prefs = Object.assign(local, cfg.prefs || {});
      },
      savePref(scope, patch, now) {
        var self = this, key = scope === 'story' ? 'ss-prefs:' + cfg.story.id : 'ss-prefs';
        Object.assign(this.prefs, patch);
        try { var c = JSON.parse(localStorage.getItem(key) || '{}'); localStorage.setItem(key, JSON.stringify(Object.assign(c, patch))); } catch (e) {}
        this._prefQueue[scope] = Object.assign(this._prefQueue[scope] || {}, patch);
        clearTimeout(this._prefTimer[scope]);
        var send = function () {
          var body = self._prefQueue[scope]; self._prefQueue[scope] = {};
          return fetch(cfg.endpoints.prefs, {
            method: 'POST', credentials: 'same-origin',
            headers: Object.assign({ 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, csrf()),
            body: JSON.stringify({ scope: scope, prefs: body }),
          }).catch(function () {});
        };
        if (now) return send();
        this._prefTimer[scope] = setTimeout(send, 800);
      },
      setTheme(t) { this.theme = t; this.savePref('global', { theme: t }); },
      setTextSize(d) { this.textSize = Math.max(80, Math.min(160, this.textSize + d)); this.savePref('global', { textSize: this.textSize }); },
      setUiLang(l) { if (l === cfg.catalog.uiLang) return; this.savePref('global', { uiLang: l }, true).then(function () { location.reload(); }); },

      // ── languages of the text: a chain primary → fallback → original (edition.js v2 `chain`) ──
      langName(code) {
        try { var n = new Intl.DisplayNames([cfg.catalog.uiLang], { type: 'language' }).of(code); return n ? n.charAt(0).toUpperCase() + n.slice(1) : code; } catch (e) { return code; }
      },
      langChain() {
        var P = this.prefs.lang || {}, ok = cfg.edition.languages, out = [];
        [P.uiFirst ? cfg.catalog.uiLang : null, P.primary, P.fallback, cfg.edition.original].forEach(function (c) {
          if (c && ok.indexOf(c) >= 0 && out.indexOf(c) < 0) out.push(c);
        });
        return out;
      },
      langWanted() { return this.langChain()[0]; },
      pageLang(p) {
        var chain = this.langChain();
        for (var i = 0; i < chain.length; i++) {
          if (chain[i] === cfg.edition.original) return chain[i];
          if (p && p.tr && p.tr[chain[i]] && Object.keys(p.tr[chain[i]]).length) return chain[i];
        }
        return cfg.edition.original;
      },
      // One field of a page in its language; a field the translation lacks stays original.
      tx(p, field) {
        if (!p) return field === 'paragraphs' ? [] : '';
        var l = this.pageLang(p), t = l !== cfg.edition.original && p.tr ? p.tr[l] : null;
        if (field === 'time') return Object.assign({}, p.time || {}, (t && t.time) || {});
        if (field === 'dialogue') return (t && t.dialogue) || (p.internal ? p.internal.dialogue : []);
        return t && t[field] != null ? t[field] : p[field];
      },
      trOf(map, fallback) {
        var chain = this.langChain();
        for (var i = 0; i < chain.length; i++) if (map && map[chain[i]]) return map[chain[i]];
        return fallback;
      },
      chTitle(c) { return c ? this.trOf(c.tr, c.title) : ''; },
      volTitle(c) { return c && c.volume ? this.trOf(c.volumeTr, c.volume) : ''; },
      storyTitle() { return this.trOf(cfg.story.tr && Object.keys(cfg.story.tr).reduce(function (o, l) { if (cfg.story.tr[l].title) o[l] = cfg.story.tr[l].title; return o; }, {}), cfg.story.title); },
      // The page is not in the language asked for: say which one is shown instead.
      langNote(p) { var l = this.pageLang(p); return p && l !== this.langWanted() ? this.t('language_missing', { name: this.langName(l) }) : ''; },
      setLang(primary, fallback) {
        var cur = Object.assign({}, this.prefs.lang || {});
        if (primary !== undefined) cur.primary = primary;
        if (fallback !== undefined) cur.fallback = fallback;
        this.savePref('global', { lang: cur });
        this.flash(this.t('language_flash', { name: this.langName(this.langWanted()) }));
      },
      cycleLang() {
        var all = cfg.edition.languages; if (all.length < 2) return;
        this.setLang(all[(all.indexOf(this.langWanted()) + 1) % all.length]);
      },

      // ── music: what plays here (page → chapter → edition), the playlist, listen elsewhere ──
      music() { return cfg.music || { story: [], links: [], node: {}, page: {} }; },
      hasMusic() { var m = this.music(); return !!(m.story.length || m.links.length || Object.keys(m.node).length || Object.keys(m.page).length); },
      musicHere() {
        var e = this.current(), m = this.music();
        if (e && m.page[e.id]) return { scope: 'page', tracks: m.page[e.id] };
        if (e && e.ch && m.node[e.ch]) return { scope: 'chapter', tracks: m.node[e.ch] };
        return { scope: m.story.length ? 'story' : null, tracks: m.story };
      },
      // Places with their own music, in reading order: a chapter's first page, or the page itself.
      musicPlaces() {
        var self = this, m = this.music(), out = [], seen = {};
        this.seq.forEach(function (i, k) {
          var e = self.manifest[i];
          if (e.ch && m.node[e.ch] && !seen[e.ch]) { seen[e.ch] = true; out.push({ key: 'c' + e.ch, k: k, label: self.chTitle(self.chapterOf(e)), tracks: m.node[e.ch] }); }
          if (m.page[e.id]) out.push({ key: 'p' + e.id, k: k, label: self.t('page_n', { n: e.n }), tracks: m.page[e.id] });
        });
        return out;
      },
      host(url) { try { return new URL(url).host; } catch (e) { return ''; } },
      listen(url) { var w = window.open(url, '_blank', 'noopener,noreferrer'); if (w) w.opener = null; },
      duration(s) { return s ? Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0') : ''; },

      // ── order ─────────────────────────────────────────
      indexOf(id) { for (var i = 0; i < this.manifest.length; i++) if (this.manifest[i].id === id) return i; return -1; },
      rebuild() {
        var idx = this.manifest.map(function (_, i) { return i; });
        if (this.order === 'happened') {
          var m = this.manifest;
          // Stable: pages without a story time keep their told position relative to each other.
          idx.sort(function (a, b) {
            var ra = m[a].rank, rb = m[b].rank;
            if (ra == null && rb == null) return a - b;
            if (ra == null) return 1; if (rb == null) return -1;
            return ra - rb || a - b;
          });
        }
        this.seq = idx;
      },
      setOrder(o) {
        if (o === this.order) return;
        var id = this.current() ? this.current().id : null;
        this.order = o; this.rebuild();
        var at = this.seq.indexOf(this.indexOf(id)); this.pos = at < 0 ? 0 : at;
        this.savePref('story', { order: o });
        this.flash(o === 'happened' ? this.t('happened_flash') : this.t('told'));
      },

      // ── current page ──────────────────────────────────
      entry(k) { var i = this.seq[k]; return i == null ? null : this.manifest[i]; },
      current() { return this.entry(this.pos); },
      page() { var e = this.current(); return e ? this.cache[e.id] || null : null; },
      total() { return this.seq.length; },
      ar() { var e = this.current(); return e ? e.ar : 0.7; },
      chapterOf(e) { if (!e || !e.ch) return null; for (var i = 0; i < cfg.chapters.length; i++) if (cfg.chapters[i].id === e.ch) return cfg.chapters[i]; return null; },
      where() {
        var p = this.page(), c = this.chapterOf(this.current());
        var parts = [];
        if (c) parts.push((c.volume ? this.volTitle(c) + ' · ' : '') + this.t('chapter_n', { no: c.no }) + ' · ' + this.chTitle(c));
        var time = this.tx(p, 'time');
        if (p && time.label) parts.push(time.label + (time.place ? ' · ' + time.place : ''));
        if (p && this.langNote(p)) parts.push(this.langNote(p));
        return parts.join(' — ');
      },

      // ── chrome: edges / bars, contents, rail ─────────
      setUi(u) { this.ui = u; this.peek = null; this.pin = false; this.chrome = true; this.savePref('global', { ui: u }); this.flash(u === 'edges' ? this.t('ui_edges_flash') : this.t('ui_bars_flash')); },
      topShown() { return this.ui === 'bars' ? this.chrome : (this.peek === 't' || this.peek === 'tb'); },
      bottomShown() { return this.ui === 'bars' ? this.chrome : (this.peek === 'b' || this.peek === 'tb'); },
      // A mouse near an edge opens that edge's panel; leaving closes it unless a click pinned it.
      edgeMove(ev) {
        if (this.ui !== 'edges' || (ev.pointerType && ev.pointerType !== 'mouse')) return;
        var r = this.$root.getBoundingClientRect(), x = ev.clientX - r.left, y = ev.clientY - r.top, E = 16, want = null;
        if (y < E) want = 't'; else if (y > r.height - E) want = 'b'; else if (x < E) want = 'l';
        if (want === 'l') { this.tocOpen = true; return; }
        if (want && !this.pin) this.peek = want;
      },
      leavePanel(edge) { if (this.pin) return; if (edge === 'l') this.tocOpen = this.tocPinned || false; else if (this.peek === edge) this.peek = null; },
      // A tap in the middle on a touch screen opens top and bottom together, pinned until the next tap.
      centerTap() {
        if (this.ui === 'bars') { this.chrome = !this.chrome; return; }
        if (this.peek === 'tb') { this.peek = null; this.pin = false; } else { this.peek = 'tb'; this.pin = true; }
      },
      toggleToc() { this.tocPinned = !this.tocPinned; this.tocOpen = this.tocPinned; },
      progressPct() { return this.seq.length ? Math.round((this.pos + 1) / this.seq.length * 100) : 0; },
      chapterMap() {
        var self = this;
        return (cfg.chapters.length ? cfg.chapters : [{ id: null, no: '', title: this.storyTitle() }]).map(function (c) {
          var pages = [];
          self.seq.forEach(function (i, k) { var e = self.manifest[i]; if ((e.ch || null) === c.id) pages.push({ k: k, id: e.id, n: e.n }); });
          return { id: c.id, no: c.no, title: c.title, volume: c.volume, pages: pages, current: pages.some(function (p) { return p.k === self.pos; }) };
        }).filter(function (c) { return c.pages.length; });
      },
      pageState(p) {
        return (p.k === this.pos ? 'current' : (p.k < this.pos ? 'read' : '')) + (this.isBookmarked(p.id) ? ' bookmark' : '') + (this.noteOf(p.id) ? ' note' : '');
      },

      // ── the reader's own marks (private, server-side per reader) ──
      isBookmarked(id) { return this.marks.some(function (m) { return m.page === id && m.kind === 'bookmark'; }); },
      noteOf(id) { var m = this.marks.filter(function (x) { return x.page === id && x.kind === 'note'; })[0]; return m ? m.text : ''; },
      markList(kind) { var self = this; return this.marks.filter(function (m) { return m.kind === kind && self.indexOf(m.page) >= 0; })
        .map(function (m) { return { page: m.page, text: m.text, n: self.manifest[self.indexOf(m.page)].n }; }).sort(function (a, b) { return a.n - b.n; }); },
      saveMark(kind, on, text) {
        var self = this, e = this.current(); if (!e) return;
        fetch(cfg.endpoints.marks, {
          method: 'POST', credentials: 'same-origin',
          headers: Object.assign({ 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, csrf()),
          body: JSON.stringify({ page: e.id, kind: kind, on: on, text: text }),
        }).then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
          .then(function (d) { self.marks = d.marks || []; })
          .catch(function () { self.flash(self.t('offline')); });
      },
      toggleBookmark() { var e = this.current(); if (!e) return; var on = !this.isBookmarked(e.id); this.saveMark('bookmark', on); this.flash(on ? this.t('bookmark_on') : this.t('bookmark_off')); },
      openNote() { var e = this.current(); if (!e) return; this.noteText = this.noteOf(e.id); this.noteOpen = true; var self = this; this.$nextTick(function () { var t = self.$root.querySelector('[data-ss-note]'); t && t.focus(); }); },
      saveNote() { this.saveMark('note', true, this.noteText); this.noteOpen = false; this.flash(this.noteText.trim() ? this.t('note_saved') : this.t('note_removed')); },

      // ── views ─────────────────────────────────────────
      // A view's definition; `layout` decides the stage, `fit` the page size, `text` where the words go.
      vw(v) { return cfg.catalog.views[v || this.view] || { layout: 'page', fit: 'page', text: 'below', cells: 4 }; },
      // Offered by the edition — or editor-only (Сценарий) and this reader is an editor.
      allowed(v) { var d = v && cfg.catalog.views[v]; return !!d && (d.editor ? !!cfg.editor : cfg.edition.views.indexOf(v) >= 0); },
      paged() { var l = this.vw().layout; return l === 'page' || l === 'pair'; },
      flowing() { var l = this.vw().layout; return l === 'scroll' || l === 'story'; },
      gridded() { return this.vw().layout === 'grid'; },
      // The first allowed view with this layout, preferring `prefer` — e.g. where a jump to a detail lands.
      viewWith(layout, prefer) {
        if (this.allowed(prefer) && this.vw(prefer).layout === layout) return prefer;
        var self = this, k = Object.keys(cfg.catalog.views).filter(function (v) { return self.allowed(v) && self.vw(v).layout === layout; })[0];
        return k || cfg.edition.defaultView;
      },
      setView(v) {
        if (!this.allowed(v) || v === this.view) return;
        if (this.paged()) this._lastPaged = this.view;
        this.view = v; this.ixSel = null; this.ixChooser = null; this.ixHover = null;
        this.savePref('story', { view: v });
        var self = this;
        this.ixZoom = null;
        if (this.flowing()) this.$nextTick(function () { self.scrollToPos(); });
        this.flash(this.t('views.' + v));
      },
      viewList() {
        var self = this;
        return Object.keys(cfg.catalog.views).filter(function (k) { return self.allowed(k); })
          .map(function (k) { return { key: k, label: self.t('views.' + k), hotkey: cfg.catalog.views[k].key }; });
      },
      viewByKey(key) { var self = this; return Object.keys(cfg.catalog.views).filter(function (v) { return cfg.catalog.views[v].key === key && self.allowed(v); })[0] || null; },
      slot(k) { var e = this.entry(k); return e ? { k: k, id: e.id, p: this.cache[e.id] || null, ar: e.ar, n: e.n } : null; },
      isSpread(k) { var e = this.entry(k); return !!e && (e.type === 'spread' || e.type === 'panorama' || e.ar > 1 || e.pair === 'alone'); },
      // Pages k and k+1 can share a pair: neither stands alone, and k+1 does not begin a pair itself.
      canPair(k) { var b = this.entry(k + 1); return !!b && !this.isSpread(k) && !this.isSpread(k + 1) && b.pair !== 'start'; },
      // What the stage shows now: one page, a pair (a spread alone), four thumbnails, or every page.
      slots() {
        var out = [], k;
        if (this.gridded()) { var n = this.vw().cells, start = this.pos - (this.pos % n); for (k = start; k < start + n; k++) { var g = this.slot(k); if (g) out.push(g); } return out; }
        if (this.flowing()) return this.seq.map(function (_, i) { return this.slot(i); }, this);
        out.push(this.slot(this.pos));
        if (this.vw().layout === 'pair' && this.canPair(this.pos)) out.push(this.slot(this.pos + 1));
        return out.filter(Boolean);
      },
      visibleIds() {
        if (this.flowing()) { var ids = []; for (var k = Math.max(0, this.pos - 1); k <= Math.min(this.seq.length - 1, this.pos + 1); k++) ids.push(this.entry(k).id); return ids; }
        return this.slots().map(function (s) { return s.id; });
      },
      step() { return this.gridded() ? this.vw().cells : (this.vw().layout === 'pair' ? this.slots().length : 1); },
      // Lenta mounts only pages near the reading point: no hundreds of images, hit layers or tab stops (C-23).
      mounted(k) { return Math.abs(k - this.pos) <= 3; },
      scrollToPos() {
        var el = this.$root.querySelector('[data-ss-scroll-k="' + this.pos + '"]');
        if (el) el.scrollIntoView({ block: 'start' });
      },
      onScroll() {
        var box = this.$refs.scroller; if (!box) return;
        var mid = box.getBoundingClientRect().top + box.clientHeight / 3, best = this.pos, bestD = 1e9;
        box.querySelectorAll('[data-ss-scroll-k]').forEach(function (el) {
          var r = el.getBoundingClientRect(), d = r.top <= mid && r.bottom >= mid ? 0 : Math.min(Math.abs(r.top - mid), Math.abs(r.bottom - mid));
          if (d < bestD) { bestD = d; best = parseInt(el.getAttribute('data-ss-scroll-k'), 10); }
        });
        if (best !== this.pos) { this._fromScroll = true; this.pos = best; }
      },
      openFromGrid(k) { var v = this.allowed(this._lastPaged) ? this._lastPaged : this.viewWith('page'); this.view = v; this.go(k); this.savePref('story', { view: v }); },

      // ── navigation ────────────────────────────────────
      go(k) { if (k < 0 || k >= this.seq.length) return; this.pos = k; this.wake(); },
      next() { var to = this.pos + this.step(); if (to < this.seq.length) this.go(to); else this.flash(this.t('end')); },
      prev() {
        if (this.pos <= 0) return;
        if (this.gridded()) { var n = this.vw().cells; return this.go(Math.max(0, this.pos - (this.pos % n) - n)); }
        // A pair steps back by two unless the page before stands alone or cannot be a partner.
        var back = this.vw().layout === 'pair' && this.pos > 1 && this.canPair(this.pos - 2) ? 2 : 1;
        this.go(this.pos - back);
      },
      forward() { cfg.story.direction === 'rtl' ? this.prev() : this.next(); },
      backward() { cfg.story.direction === 'rtl' ? this.next() : this.prev(); },
      chapterStep(d) {
        var cur = this.current(); if (!cur) return;
        for (var k = this.pos + d; k >= 0 && k < this.seq.length; k += d) {
          var e = this.entry(k);
          if (e.ch !== cur.ch) {
            if (d < 0) { while (k > 0 && this.entry(k - 1).ch === e.ch) k--; }
            return this.go(k);
          }
        }
      },
      goById(id) { var k = this.seq.indexOf(this.indexOf(id)); if (k >= 0) this.go(k); },
      submitGoto() {
        var n = parseInt(this.gotoValue, 10);
        if (n >= 1 && n <= this.manifest.length) { var k = this.seq.indexOf(n - 1); if (k >= 0) this.go(k); }
        this.gotoOpen = false; this.gotoValue = '';
      },

      // ── loading: chunks around the position, images a few pages ahead ──
      ensure() {
        var chunk = cfg.tuning.chunk || 12, ahead = cfg.tuning.ahead || 3, need = [];
        for (var k = Math.max(0, this.pos - 2); k < Math.min(this.seq.length, this.pos + chunk); k++) {
          var e = this.entry(k);
          if (!this.cache[e.id] && !this.loading[e.id]) need.push(e.id);
        }
        if (need.length) this.fetchPages(need.slice(0, chunk));
        for (var j = 1; j <= ahead; j++) {
          var p = this.entry(this.pos + j); p = p && this.cache[p.id];
          if (p && p.image && !p._pre) { p._pre = true; var im = new Image(); im.decoding = 'async'; im.src = p.image.src; }
        }
      },
      fetchPages(ids) {
        var self = this;
        ids.forEach(function (id) { self.loading[id] = true; });
        var url = cfg.endpoints.pages + (cfg.endpoints.pages.indexOf('?') < 0 ? '?' : '&') + 'ids=' + encodeURIComponent(ids.join(','));
        fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
          .then(function (r) { return r.ok ? r.json() : { pages: [] }; })
          .then(function (d) { (d.pages || []).forEach(function (p) { self.cache[p.id] = p; }); })
          .catch(function () {})
          .finally(function () { ids.forEach(function (id) { delete self.loading[id]; }); });
      },

      // ── progress: by page id, debounced — never a request per page turn ──
      remember() {
        var self = this, e = this.current();
        if (!e) return;
        clearTimeout(this._saveTimer);
        this._saveTimer = setTimeout(function () {
          fetch(cfg.endpoints.progress, {
            method: 'POST', credentials: 'same-origin',
            headers: Object.assign({ 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, csrf()),
            body: JSON.stringify({ page: e.id }),
          }).catch(function () {});
        }, 1200);
      },
      syncUrl() {
        if (!cfg.syncUrl) return;
        var e = this.current(); if (!e) return;
        try { var u = new URL(location.href); u.searchParams.set('page', e.id); history.replaceState(history.state, '', u); } catch (x) {}
      },

      // ── chrome, fullscreen, hints ─────────────────────
      wake() {
        var self = this;
        if (this.ui === 'edges') return;
        this.chrome = true;
        clearTimeout(this._hideTimer);
        if (this.full) this._hideTimer = setTimeout(function () { self.chrome = false; }, 2600);
      },
      toggleFull() {
        var el = this.$root;
        if (document.fullscreenElement) document.exitFullscreen && document.exitFullscreen();
        else if (el.requestFullscreen) el.requestFullscreen().catch(function () {});
      },
      flash(t) { var self = this; this.hint = t; clearTimeout(this._hintTimer); this._hintTimer = setTimeout(function () { self.hint = ''; }, 1600); },

      // ── input ─────────────────────────────────────────
      key(ev) {
        if (ev.target && /^(INPUT|TEXTAREA|SELECT)$/.test(ev.target.tagName)) return;
        if (ev.metaKey || ev.ctrlKey || ev.altKey) return;
        // A focused control keeps its own Enter/Space: activating a list item must not turn the page.
        if ((ev.key === ' ' || ev.key === 'Enter') && ev.target && /^(BUTTON|A)$/.test(ev.target.tagName)) return;
        var k = ev.key, handled = true;
        if (k === 'ArrowRight' || k === 'PageDown' || k === ' ') this.forward();
        else if (k === 'ArrowLeft' || k === 'PageUp') this.backward();
        else if (k === ']') this.chapterStep(1);
        else if (k === '[') this.chapterStep(-1);
        else if (k === 'Home') this.go(0);
        else if (k === 'End') this.go(this.seq.length - 1);
        else if (k === 'f' || k === 'F' || k === 'а' || k === 'А') this.toggleFull();
        else if ((k === 'i' || k === 'I' || k === 'ш' || k === 'Ш') && this.ixPanelItems().length) this.ixListOpen = !this.ixListOpen;
        else if (this.viewByKey(k)) this.setView(this.viewByKey(k));
        else if (k === 'l' || k === 'L' || k === 'д' || k === 'Д') this.cycleLang();
        else if ((k === 'm' || k === 'M' || k === 'ь' || k === 'Ь') && this.hasMusic()) this.musicOpen = !this.musicOpen;
        else if (k === 'u' || k === 'U' || k === 'г' || k === 'Г') this.setUi(this.ui === 'edges' ? 'bars' : 'edges');
        else if (k === 'c' || k === 'C' || k === 'с' || k === 'С') this.toggleToc();
        else if (k === 'b' || k === 'B' || k === 'и' || k === 'И') this.toggleBookmark();
        else if (k === 'n' || k === 'N' || k === 'т' || k === 'Т') this.openNote();
        else if (k === 'g' || k === 'G' || k === 'п' || k === 'П') { this.gotoOpen = true; var self = this; this.$nextTick(function () { var i = self.$root.querySelector('[data-ss-goto]'); i && i.focus(); }); }
        else if (k === 'Escape') { if (this.noteOpen) this.noteOpen = false; else if (this.ixEscape()) {} else if (this.tocOpen || this.peek || this.viewMenu || this.langOpen || this.musicOpen) { this.tocOpen = this.tocPinned = false; this.peek = null; this.pin = false; this.viewMenu = false; this.langOpen = false; this.musicOpen = false; } else if (this.gotoOpen) this.gotoOpen = false; else if (cfg.back && !document.fullscreenElement) location.href = cfg.back; else handled = false; }
        else handled = false;
        if (handled) ev.preventDefault();
      },

      // ── timeline: chapters as segments, pages as dots, in the active order ──
      segments() {
        var out = [], self = this, last = null;
        this.seq.forEach(function (i, k) {
          var e = self.manifest[i], key = self.order === 'happened' ? ('t' + (e.rank == null ? '?' : e.rank)) : (e.ch || 'root');
          if (!last || last.key !== key) { last = { key: key, ch: e.ch, from: k, dots: [] }; out.push(last); }
          last.dots.push(k);
        });
        // Too many dots to be useful: collapse to chapter segments with a single proportional track.
        return out.map(function (s) {
          var c = self.chapterOf(self.manifest[self.seq[s.from]]);
          var p = self.cache[self.manifest[self.seq[s.from]].id];
          var label = self.order === 'happened' ? ((p && self.tx(p, 'time').label) || (c ? self.chTitle(c) : '')) : (c ? c.no + ' · ' + self.chTitle(c) : self.storyTitle());
          return { key: s.key, from: s.from, to: s.from + s.dots.length - 1, dots: s.dots, label: label, on: self.pos >= s.from && self.pos <= s.from + s.dots.length - 1 };
        });
      },
      dotTitle(k) { var e = this.entry(k), c = this.chapterOf(e); return this.t('page_n', { n: e.n }) + (c ? ' · ' + this.t('chapter_short', { no: c.no }) : ''); },
    }, interaction(cfg));
  }

  // ── Story Reader interaction geometry (docs/FORMAT.md) ───────────────────────────
  // Shapes live on a 0…1000 plane over the artwork. Paths are the validated M/L/Q/C/Z subset; curves
  // are flattened into polylines so hit-testing is plain arithmetic, the same on every engine.
  var IXGeo = {
    flatten: function (d) {
      var t = d.split(' '), rings = [], ring = null, cx = 0, cy = 0, i = 0, n, steps = 16;
      function num() { return parseFloat(t[i++]); }
      while (i < t.length) {
        var c = t[i++];
        if (c === 'M') { ring = []; rings.push(ring); cx = num(); cy = num(); ring.push([cx, cy]); }
        else if (c === 'L') { cx = num(); cy = num(); ring.push([cx, cy]); }
        else if (c === 'Q') {
          var qx = num(), qy = num(), ex = num(), ey = num();
          for (n = 1; n <= steps; n++) { var u = n / steps, a = (1 - u) * (1 - u), b = 2 * (1 - u) * u, e = u * u;
            ring.push([a * cx + b * qx + e * ex, a * cy + b * qy + e * ey]); }
          cx = ex; cy = ey;
        } else if (c === 'C') {
          var x1 = num(), y1 = num(), x2 = num(), y2 = num(), x3 = num(), y3 = num();
          for (n = 1; n <= steps; n++) { var v = n / steps, w = 1 - v;
            ring.push([w * w * w * cx + 3 * w * w * v * x1 + 3 * w * v * v * x2 + v * v * v * x3,
                       w * w * w * cy + 3 * w * w * v * y1 + 3 * w * v * v * y2 + v * v * v * y3]); }
          cx = x3; cy = y3;
        }
      }
      return rings;
    },
    rings: function (s) {
      if (s.type === 'rect') return [[[s.x, s.y], [s.x + s.width, s.y], [s.x + s.width, s.y + s.height], [s.x, s.y + s.height]]];
      if (s.type === 'polygon') return [s.points];
      if (s.type === 'path') return IXGeo.flatten(s.d);
      var r = [], k; for (k = 0; k < 48; k++) { var a = k / 48 * Math.PI * 2; r.push([s.cx + s.rx * Math.cos(a), s.cy + s.ry * Math.sin(a)]); }
      return [r];
    },
    // Winding number per ring; evenodd counts crossings, nonzero sums directions.
    contains: function (s, x, y) {
      if (s.type === 'rect') return x >= s.x && x <= s.x + s.width && y >= s.y && y <= s.y + s.height;
      if (s.type === 'ellipse') { var dx = (x - s.cx) / s.rx, dy = (y - s.cy) / s.ry; return dx * dx + dy * dy <= 1; }
      var rings = IXGeo.rings(s), wind = 0, cross = 0;
      rings.forEach(function (r) {
        for (var i = 0, j = r.length - 1; i < r.length; j = i++) {
          var a = r[j], b = r[i];
          if ((a[1] > y) !== (b[1] > y) && x < (b[0] - a[0]) * (y - a[1]) / (b[1] - a[1]) + a[0]) { cross++; wind += b[1] > a[1] ? 1 : -1; }
        }
      });
      return s.fillRule === 'nonzero' ? wind !== 0 : cross % 2 === 1;
    },
    box: function (s) {
      var x0 = 1e9, y0 = 1e9, x1 = -1e9, y1 = -1e9;
      IXGeo.rings(s).forEach(function (r) { r.forEach(function (p) { x0 = Math.min(x0, p[0]); y0 = Math.min(y0, p[1]); x1 = Math.max(x1, p[0]); y1 = Math.max(y1, p[1]); }); });
      return { x: x0, y: y0, w: x1 - x0, h: y1 - y0 };
    },
    // SVG markup from validated numbers only — never from author text.
    svg: function (s) {
      var f = function (v) { return Number(v).toFixed(2); };
      if (s.type === 'rect') return '<rect x="' + f(s.x) + '" y="' + f(s.y) + '" width="' + f(s.width) + '" height="' + f(s.height) + '"/>';
      if (s.type === 'ellipse') return '<ellipse cx="' + f(s.cx) + '" cy="' + f(s.cy) + '" rx="' + f(s.rx) + '" ry="' + f(s.ry) + '"/>';
      if (s.type === 'polygon') return '<polygon points="' + s.points.map(function (p) { return f(p[0]) + ',' + f(p[1]); }).join(' ') + '"/>';
      return /^[MLQCZ0-9. ]+$/.test(s.d) ? '<path d="' + s.d + '" fill-rule="' + (s.fillRule === 'nonzero' ? 'nonzero' : 'evenodd') + '"/>' : '';
    },
  };
  window.__ssIXGeo = IXGeo;

  // ── Story Reader interactions: one controller, one activation authority (INPUT-AND-STATE.md) ──
  //  inspect (mouse / hovering pen) → label only, never an action or a request;
  //  tap with a finger or a contact pen → select + preview with an explicit CTA (two deliberate steps);
  //  primary click with a mouse → the action at once; keyboard → the list of proxies, Enter acts.
  //  A stream that moved, met a second contact, was cancelled or lost focus is never a tap.
  // Gesture thresholds are physics, not catalog: the same on every host.
  var IX_SLOP = 10, IX_LONG = 450, IX_FINGER = 44, IX_SWIPE = 50;

  function interaction(cfg) {
    // Modes and effects this renderer draws come from the catalog; an author's other wishes fall back to
    // the outline (EFFECTS.md).
    var IX_MODES = cfg.catalog.ixModes, IX_FX = cfg.catalog.effects;
    return {
      ixMode: 'on',
      ixFx: 'author',          // reader's global effect choice
      ixKindFx: {},            // per-kind overrides: { character: 'ink', … }
      ixReveal: false,
      ixHover: null,
      ixSel: null,
      ixSelVia: null,          // 'touch' | 'pointer' | 'focus' — where a preview sits depends on it
      ixChooser: null,
      ixCard: null,
      ixListOpen: false,
      ixBack: [],
      ixNotice: '',
      ixZoom: null,            // { page, anchor } — a jump landed on a detail of the page
      _ixStream: null,
      _ixActive: {},
      _ixInvocation: 0,
      _ixLeave: null,
      _ixTrigger: null,

      ixInit() {
        var self = this;
        var ix = this.prefs.ix || {};
        if (IX_MODES.indexOf(ix.mode) >= 0) this.ixMode = ix.mode;
        if (ix.fx === 'author' || IX_FX.indexOf(ix.fx) >= 0) this.ixFx = ix.fx;
        if (ix.kinds) this.ixKindFx = ix.kinds;
        window.addEventListener('blur', function () { self.ixCancel(); });
        this.$watch('pos', function () { self.ixHover = null; self.ixSel = null; self.ixChooser = null; self.ixReveal = false;
          if (self.ixZoom && self.ixZoom.page !== (self.current() || {}).id) self.ixZoom = null; });
      },

      // ── data, per page (several pages can be on the stage) ──
      ixData(id) { var p = id ? this.cache[id] : this.page(); return cfg.interaction && p && p.interaction ? p.interaction : null; },
      ixItems(id) { var d = this.ixData(id); return d ? d.items : []; },
      ixRegions(id) { return this.ixItems(id).filter(function (it) { return it.anchor.type === 'region'; }); },
      ixEssential(id) { return this.ixItems(id).filter(function (it) { return it.essential; }); },
      ixPanelItems() { var self = this, out = []; this.visibleIds().forEach(function (id) { out = out.concat(self.ixItems(id)); }); return out; },
      ixPageOf(itemId) { var ids = this.visibleIds(); for (var i = 0; i < ids.length; i++) if (this.ixItems(ids[i]).some(function (it) { return it.id === itemId; })) return ids[i]; return null; },
      ixItem(itemId) { var pid = this.ixPageOf(itemId); if (!pid) return null; return this.ixItems(pid).filter(function (it) { return it.id === itemId; })[0] || null; },
      // Labels follow the reader's language chain, then the profile's own language.
      ixText(map) {
        if (!map) return '';
        var chain = this.langChain();
        for (var i = 0; i < chain.length; i++) if (map[chain[i]]) return map[chain[i]];
        var d = this.ixData(this.ixPageOf(this.ixSel || this.ixHover) || null);
        return (d && map[d.language]) || map[Object.keys(map)[0]] || '';
      },
      ixKind(it) { return it && it.kind ? this.t('kinds.' + it.kind) : ''; },
      ixCardKind(c) { return this.ixKind(c); },
      // Text links live in the original's runs; a translated caption is shown whole, without them.
      ixBlockShown(target, id) { return this.pageLang(this.cache[id]) === cfg.edition.original ? this.ixBlock(target, id) : null; },
      ixBlock(target, id) { var d = this.ixData(id); if (!d) return null; for (var i = 0; i < d.blocks.length; i++) if (d.blocks[i].target === target) return d.blocks[i]; return null; },
      ixRunItem(block, r, id) { var all = this.ixItems(id); for (var i = 0; i < all.length; i++) { var a = all[i].anchor; if (a.type === 'text' && a.blockId === block.id && a.runId === r.id) return all[i]; } return null; },
      ixCardOf(it) { var d = this.ixData(this.ixPageOf(it.id)); if (!d || it.action.type !== 'open-card') return null;
        for (var i = 0; i < d.cards.length; i++) if (d.cards[i].id === it.action.cardId) return d.cards[i]; return null; },
      // Overview opens pages, not tiny hotspots (C-22); off / on-demand switch art capture off (C-24).
      ixArtLive(id) { return !this.gridded() && this.ixRegions(id).length > 0 && (this.ixMode === 'on' || (this.ixMode === 'on-demand' && this.ixReveal)); },
      setIxMode(m) { this.ixMode = m; this.ixReveal = false; this.ixSel = null; this.ixSavePrefs(); },
      ixSavePrefs() { this.savePref('global', { ix: { mode: this.ixMode, fx: this.ixFx, kinds: this.ixKindFx } }); },
      setIxFx(all, kind, value) {
        if (kind) { var k = Object.assign({}, this.ixKindFx); if (value) k[kind] = value; else delete k[kind]; this.ixKindFx = k; }
        else this.ixFx = all;
        this.ixSavePrefs();
      },
      // Priority (CANON): reader's setting for the kind → reader's general setting → author → default.
      ixEffectOf(it) {
        var k = this.ixKindFx[it.kind]; if (IX_FX.indexOf(k) >= 0) return k;
        if (this.ixFx !== 'author') return this.ixFx;
        if (IX_FX.indexOf(it.effect) >= 0) return it.effect;
        return it.kind === 'nav' ? 'brackets' : 'outline';
      },
      ixFxList() { var self = this; return ['author'].concat(IX_FX).map(function (k) { return { key: k, label: self.t('effects.' + k) }; }); },
      ixKindsList() { return cfg.catalog.kinds.filter(function (k) { return k !== 'media' && k !== 'reference'; }); },

      // ── drawing ──
      ixShown(it) { return this.ixReveal || this.ixHover === it.id || this.ixSel === it.id || (this.ixChooser || []).indexOf(it.id) >= 0; },
      ixSvg(id) {
        var self = this, out = '', hatch = 'ss-hatch-' + (cfg.story.id || 'x');
        this.ixRegions(id).forEach(function (it) {
          if (!self.ixShown(it)) return;
          var state = self.ixSel === it.id ? 'selected' : ((self.ixChooser || []).indexOf(it.id) >= 0 ? 'selected' : (self.ixReveal && self.ixHover !== it.id ? 'revealed' : 'inspected'));
          var fx = self.ixEffectOf(it), shape = it.anchor.outline || it.anchor.shape;
          // Quiet shows nothing on inspection; selection and reveal still get a clear cue (EFFECTS states).
          if (fx === 'quiet' && state === 'inspected') return;
          if (fx === 'quiet') fx = 'outline';
          if (fx === 'brackets') {
            var b = IXGeo.box(it.anchor.shape), L = Math.max(12, Math.min(b.w, b.h) * 0.22), x0 = b.x, y0 = b.y, x1 = b.x + b.w, y1 = b.y + b.h, f = function (v) { return v.toFixed(1); };
            var d = 'M ' + f(x0) + ' ' + f(y0 + L) + ' L ' + f(x0) + ' ' + f(y0) + ' L ' + f(x0 + L) + ' ' + f(y0) +
              ' M ' + f(x1 - L) + ' ' + f(y0) + ' L ' + f(x1) + ' ' + f(y0) + ' L ' + f(x1) + ' ' + f(y0 + L) +
              ' M ' + f(x1) + ' ' + f(y1 - L) + ' L ' + f(x1) + ' ' + f(y1) + ' L ' + f(x1 - L) + ' ' + f(y1) +
              ' M ' + f(x0 + L) + ' ' + f(y1) + ' L ' + f(x0) + ' ' + f(y1) + ' L ' + f(x0) + ' ' + f(y1 - L);
            out += '<g class="ss-ix__shape ss-ix__fx-brackets" data-state="' + state + '"><path d="' + d + '"/></g>';
            return;
          }
          out += '<g class="ss-ix__shape ss-ix__fx-' + fx + '" data-state="' + state + '"' + (fx === 'ink' ? ' style="--ss-ix-hatch:url(#' + hatch + ')"' : '') + '>' + IXGeo.svg(shape) + '</g>';
        });
        var defs = '<defs><pattern id="' + hatch + '" width="10" height="10" patternUnits="userSpaceOnUse" patternTransform="rotate(45)"><rect width="3" height="10" class="ss-ix__hatch"/></pattern></defs>';
        return '<svg viewBox="0 0 1000 1000" preserveAspectRatio="none" aria-hidden="true" focusable="false">' + defs + out + '</svg>';
      },

      // ── hit testing in one page's artwork plane ──
      ixPoint(ev, layer) {
        var r = layer.getBoundingClientRect();
        return { x: (ev.clientX - r.left) / r.width * 1000, y: (ev.clientY - r.top) / r.height * 1000, sx: r.width / 1000, sy: r.height / 1000 };
      },
      ixHits(pt, finger, id) {
        var exact = [], near = [];
        this.ixRegions(id).forEach(function (it) {
          var s = it.anchor.shape;
          if (IXGeo.contains(s, pt.x, pt.y)) { exact.push(it.id); return; }
          if (!finger) return;
          var b = IXGeo.box(s), padX = Math.max(0, (IX_FINGER / pt.sx - b.w) / 2), padY = Math.max(0, (IX_FINGER / pt.sy - b.h) / 2);
          if ((padX || padY) && pt.x >= b.x - padX && pt.x <= b.x + b.w + padX && pt.y >= b.y - padY && pt.y <= b.y + b.h + padY) near.push(it.id);
        });
        return exact.length ? exact : near;
      },
      ixLayerAt(target) { var l = target && target.closest ? target.closest('.ss-ix') : null; return l && this.$root.contains(l) ? l : null; },

      // ── the one recognizer, on the stage: taps, swipes, long press, cancellation ──
      pDown(ev) {
        this._ixActive[ev.pointerId] = true;
        if (Object.keys(this._ixActive).length > 1) { this._ixStream = null; return; }   // a second contact voids the tap
        if (ev.button > 0) return;
        this._ixStream = { id: ev.pointerId, type: ev.pointerType || 'mouse', x: ev.clientX, y: ev.clientY, at: Date.now(), moved: false, target: ev.target };
      },
      pMove(ev) {
        var st = this._ixStream;
        if (st && st.id === ev.pointerId && (Math.abs(ev.clientX - st.x) > IX_SLOP || Math.abs(ev.clientY - st.y) > IX_SLOP)) st.moved = true;
        // Hover exists only for a pointer that moves without contact: a mouse, or a pen that reports it.
        if (ev.pointerType === 'touch' || ev.buttons) return;
        var layer = this.ixLayerAt(ev.target);
        if (!layer) { if (this.ixHover && !this._ixFocusHover) this.ixLeaveLayer(); return; }
        clearTimeout(this._ixLeave);
        var id = layer.getAttribute('data-ss-layer'), hits = this.ixHits(this.ixPoint(ev, layer), false, id);
        this.ixHover = hits.length ? hits[0] : null;
        if (ev.pointerType === 'pen') this._ixPenHover = true;
      },
      ixLeaveLayer() { var self = this; clearTimeout(this._ixLeave); this._ixLeave = setTimeout(function () { self.ixHover = null; }, 150); },
      pUp(ev) {
        delete this._ixActive[ev.pointerId];
        var st = this._ixStream; this._ixStream = null;
        if (!st || st.id !== ev.pointerId) return;
        var dx = ev.clientX - st.x, dy = ev.clientY - st.y;
        if (st.moved) {
          // A horizontal swipe turns pages in paged views; anything else that moved is reading, not an action.
          if (this.paged() && st.type !== 'mouse' && Math.abs(dx) > IX_SWIPE && Math.abs(dx) > Math.abs(dy) * 1.5) dx < 0 ? this.forward() : this.backward();
          return;
        }
        // Controls handle their own click; previews and cards are not the page.
        if (st.target.closest && st.target.closest('button, a, input, select, textarea, .ss-ix__preview, .ss-ix__card, .ss-ix__panel')) return;
        var layer = this.ixLayerAt(st.target);
        if (layer && Date.now() - st.at >= IX_LONG) { this.ixRevealAll(true); return; }   // long press reveals, never acts
        this.ixTap(ev, st.type, layer);
      },
      ixCancel(ev) { if (ev && ev.pointerId != null) delete this._ixActive[ev.pointerId]; else this._ixActive = {}; this._ixStream = null; },

      ixTap(ev, type, layer) {
        var direct = type === 'touch' || (type === 'pen' && !this._ixPenHover), id = layer ? layer.getAttribute('data-ss-layer') : null;
        var hits = layer && this.ixArtLive(id) ? this.ixHits(this.ixPoint(ev, layer), direct, id) : [];
        // With a preview or chooser open, a tap selects another object or only closes — never turns the page.
        if (this.ixSel || this.ixChooser) {
          this.ixChooser = null;
          this.ixSel = hits.length === 1 && direct ? hits[0] : null;
          if (hits.length > 1) this.ixChooser = hits;
          return;
        }
        if (hits.length > 1) { this.ixChooser = hits; this.ixSelVia = type; return; }
        if (hits.length === 1) { if (direct) { this.ixSel = hits[0]; this.ixSelVia = 'touch'; } else this.ixAct(hits[0], 'pointer'); return; }
        this.ixBackground(ev);
      },
      // A clean tap with nothing there reads like the old zones: sides turn, the middle wakes chrome.
      ixBackground(ev) {
        if (!this.paged() || this.ixZoom) { this.centerTap(); return; }
        var stage = this.$root.querySelector('.ss-reader__stage').getBoundingClientRect(), rel = (ev.clientX - stage.left) / stage.width;
        if (rel < 0.18) this.backward(); else if (rel > 0.82) this.forward(); else this.centerTap();
      },
      ixRevealAll(on) { this.ixReveal = on; this.ixHover = null; if (on) this.ixListOpen = true; },
      // On a finger or a narrow screen the preview goes to the bottom dock, not under the hand (INPUT «Preview»).
      ixDocked() { return this.ixSelVia === 'touch' || this.$root.clientWidth < 720 || this.flowing() || !!this.ixZoom; },
      // On a narrow window the card is a modal sheet: focus stays inside until it closes (C-29).
      ixCardModal() { return this.$root.clientWidth < 720; },
      ixTrap(ev) {
        if (ev.key !== 'Tab' || !this.ixCardModal()) return;
        var f = Array.prototype.slice.call(ev.currentTarget.querySelectorAll('button, a[href], [tabindex]:not([tabindex="-1"])'));
        if (!f.length) return;
        var first = f[0], last = f[f.length - 1];
        if (ev.shiftKey && document.activeElement === first) { ev.preventDefault(); last.focus(); }
        else if (!ev.shiftKey && document.activeElement === last) { ev.preventDefault(); first.focus(); }
      },

      // ── actions: one invocation per deliberate confirmation ──
      ixAct(id, via) {
        var it = this.ixItem(id); if (!it) return;
        var n = ++this._ixInvocation, self = this;
        if (this._ixRunning) return;          // a second confirmation while one runs is the same intent
        this._ixRunning = n;
        setTimeout(function () { if (self._ixRunning === n) self._ixRunning = 0; }, 350);
        this.ixSel = null; this.ixChooser = null; this.ixHover = null;
        this._ixTrigger = document.activeElement;
        var a = it.action;
        if (a.type === 'open-card') { this.ixCard = this.ixCardOf(it); this.$nextTick(function () { var c = self.$root.querySelector('[data-ss-ix-card-close]'); c && c.focus(); }); }
        else if (a.type === 'jump') this.ixJump(a.pageId, it.id, a.anchorId);
        else if (a.type === 'open-url') { var w = window.open(a.url, '_blank', 'noopener,noreferrer'); if (w) w.opener = null; }
        else this.ixFlash(this.t('ix_media_unsupported'));
      },
      ixVerb(it) {
        var a = it.action;
        if (a.type === 'jump') { var i = this.indexOf(a.pageId), here = a.pageId === (this.current() || {}).id, n = i >= 0 ? this.manifest[i].n : null;
          if (a.anchorId) return here ? this.t('ix_show') : (n ? this.t('ix_show_page', { n: n }) : this.t('ix_show'));
          return here ? this.t('ix_stay') : (n ? this.t('ix_go_page', { n: n }) : this.t('ix_go')); }
        if (a.type === 'open-card') return this.t('ix_open');
        if (a.type === 'open-url') return this.t('ix_open_url', { host: a.host });
        return this.t('ix_media_none');
      },
      ixJump(pageId, fromId, anchorId) {
        var cur = this.current(); if (!cur) return;
        if (cur.id !== pageId || anchorId) this.ixBack.push({ page: cur.id, order: this.order, view: this.view, focus: fromId, zoom: this.ixZoom });
        if (this.indexOf(pageId) < 0) { this.ixFlash(this.t('ix_unavailable')); return; }
        // A detail is shown in a single-page view; a pair, a strip or the overview open one page first.
        if (anchorId && this.vw().layout !== 'page') this.setView(this.viewWith('page', this._lastPaged));
        this.goById(pageId);
        this.ixZoom = anchorId ? { page: pageId, anchor: anchorId } : null;
      },
      // The zoom is a transform of the page's inner layer: art and contours move together (SR-07).
      ixZoomStyle(id) {
        var z = this.ixZoom; if (!z || z.page !== id) return '';
        var d = this.ixData(id), a = d && (d.anchors || []).filter(function (x) { return x.id === z.anchor; })[0];
        if (!a) return '';
        var b = IXGeo.box(a.shape), k = Math.min(4, Math.max(1, 0.9 * Math.min(1000 / b.w, 1000 / b.h)));
        var cx = (b.x + b.w / 2) / 1000, cy = (b.y + b.h / 2) / 1000;
        var tx = Math.min(0, Math.max(1 - k, 0.5 - cx * k)), ty = Math.min(0, Math.max(1 - k, 0.5 - cy * k));
        return 'transform-origin:0 0;transform:translate(' + (tx * 100).toFixed(3) + '%,' + (ty * 100).toFixed(3) + '%) scale(' + k.toFixed(4) + ')';
      },
      // Return restores page, order, view and focus (SR-11, C-15).
      ixReturn() {
        var r = this.ixBack.pop(); if (!r) return;
        if (r.order !== this.order) this.setOrder(r.order);
        if (r.view && r.view !== this.view) this.setView(r.view);
        this.goById(r.page);
        this.ixZoom = r.zoom || null;
        this.ixFocusProxy(r.focus);
      },
      ixFocusProxy(id, tries) {
        var self = this, els = this.$root.querySelectorAll('[data-ss-ix-proxy="' + id + '"]');
        for (var i = 0; i < els.length; i++) if (els[i].offsetParent !== null) { els[i].focus(); return; }
        if ((tries || 0) < 10) requestAnimationFrame(function () { self.ixFocusProxy(id, (tries || 0) + 1); });
      },
      ixCloseCard() { this.ixCard = null; var t = this._ixTrigger; this._ixTrigger = null; if (t && t.focus && document.contains(t)) t.focus(); },
      ixFlash(t) { var self = this; this.ixNotice = t; clearTimeout(this._ixNoticeT); this._ixNoticeT = setTimeout(function () { self.ixNotice = ''; }, 4000); },
      ixEscape() {
        if (this.ixCard) { this.ixCloseCard(); return true; }
        if (this.ixZoom) { this.ixZoom = null; return true; }
        if (this.ixChooser || this.ixSel) { this.ixChooser = null; this.ixSel = null; return true; }
        if (this.ixListOpen || this.ixReveal) { this.ixListOpen = false; this.ixReveal = false; return true; }
        return false;
      },
      ixFocusItem(id) { this._ixFocusHover = true; this.ixHover = id; },
      ixBlurItem() { this._ixFocusHover = false; this.ixHover = null; },

      // ── preview placement inside its page: outside the contour if there is room ──
      ixPreviewStyle(itemId) {
        var it = this.ixItem(itemId); if (!it || it.anchor.type !== 'region') return '';
        var b = IXGeo.box(it.anchor.shape), right = 1000 - (b.x + b.w);
        if (right >= 260) return 'left:' + ((b.x + b.w) / 10) + '%;top:' + Math.max(0, b.y / 10) + '%';
        if (b.x >= 260) return 'right:' + ((1000 - b.x) / 10) + '%;top:' + Math.max(0, b.y / 10) + '%';
        return 'left:0;right:0;bottom:0';
      },
    };
  }

  function editor() {
    return {
      selected: [],
      anchor: null,
      dragIds: null,
      dropAt: null,      // { id, side: 'l'|'r' } — the insertion bar
      dropNode: null,    // tree chapter under the cursor
      fileOver: false,
      thumb: 150,
      move: null,        // Move-to dialog state
      confirm: null,     // shared confirm dialog
      treeOpen: false,
      inspOpen: false,

      theme: 'dark',

      init() {
        try { var t = parseInt(localStorage.getItem('ss-thumb'), 10); if (t) this.thumb = t; } catch (e) {}
        try { var th = localStorage.getItem('ss-theme'); if (th === 'light' || th === 'dark') this.theme = th; } catch (e) {}
      },
      toggleTheme() { this.theme = this.theme === 'dark' ? 'light' : 'dark'; try { localStorage.setItem('ss-theme', this.theme); } catch (e) {} },
      setThumb(v) { this.thumb = v; try { localStorage.setItem('ss-thumb', v); } catch (e) {} },

      ids() { return Array.prototype.map.call(this.$root.querySelectorAll('[data-ss-card]'), function (el) { return el.dataset.ssCard; }); },
      isSel(id) { return this.selected.indexOf(id) >= 0; },
      click(ev, id) {
        if (ev.shiftKey && this.anchor) {
          var all = this.ids(), a = all.indexOf(this.anchor), b = all.indexOf(id);
          if (a >= 0 && b >= 0) { var lo = Math.min(a, b), hi = Math.max(a, b); this.selected = all.slice(lo, hi + 1); return; }
        }
        if (ev.metaKey || ev.ctrlKey) { this.toggle(id); this.anchor = id; return; }
        this.selected = []; this.anchor = id;
        this.$wire.selectPage(id); this.inspOpen = true;
      },
      toggle(id) { var i = this.selected.indexOf(id); if (i >= 0) this.selected.splice(i, 1); else this.selected.push(id); this.anchor = id; },
      clear() { this.selected = []; },
      targets() { return this.selected.length ? this.selected.slice() : (this.$wire.selected ? [this.$wire.selected] : []); },

      // drag inside the grid: the bar shows where the pages will land
      dragStart(ev, id) {
        if (!this.isSel(id)) this.selected = [id];
        this.dragIds = this.selected.slice();
        ev.dataTransfer.effectAllowed = 'move';
        ev.dataTransfer.setData('text/x-ss-pages', this.dragIds.join(','));
      },
      dragOverCard(ev, id) {
        if (!this.dragIds) return;
        ev.preventDefault();
        var r = ev.currentTarget.getBoundingClientRect();
        this.dropAt = { id: id, side: ev.clientX < r.left + r.width / 2 ? 'l' : 'r' };
      },
      dropOnCard(ev, section) {
        if (!this.dragIds || !this.dropAt) return;
        ev.preventDefault();
        var all = this.ids(), at = all.indexOf(this.dropAt.id), before = this.dropAt.side === 'l' ? this.dropAt.id : null;
        if (!before) {
          // "after X" = before the next card of the same section, or the end of that section
          var cards = Array.prototype.slice.call(ev.currentTarget.parentNode.querySelectorAll('[data-ss-card]')).map(function (el) { return el.dataset.ssCard; });
          var j = cards.indexOf(this.dropAt.id); before = cards[j + 1] || null;
        }
        this.$wire.movePages(this.dragIds, section, before);
        this.endDrag();
      },
      dropOnNode(ev, node) {
        if (this.dragIds) { ev.preventDefault(); this.$wire.movePages(this.dragIds, node, null); this.endDrag(); }
      },
      endDrag() { this.dragIds = null; this.dropAt = null; this.dropNode = null; this.selected = []; },

      // files dropped onto the grid → Livewire upload with relative paths (folders become chapters)
      gridDragOver(ev) { if (!this.dragIds && ev.dataTransfer && Array.prototype.indexOf.call(ev.dataTransfer.types, 'Files') >= 0) { ev.preventDefault(); this.fileOver = true; } },
      gridDrop(ev) {
        if (this.dragIds) return;
        ev.preventDefault(); this.fileOver = false;
        var self = this;
        collect(ev.dataTransfer).then(function (items) { self.upload(items); });
      },
      pick(ev) {
        var files = Array.prototype.slice.call(ev.target.files || []);
        this.upload(files.map(function (f) { return { file: f, path: f.webkitRelativePath || f.name }; }));
        ev.target.value = '';
      },
      upload(items) {
        if (!items.length) return;
        var self = this;
        this.$wire.set('uploadPaths', items.map(function (i) { return i.path; }), false);
        this.$wire.uploadMultiple('uploads', items.map(function (i) { return i.file; }),
          function () { self.$dispatch('ss-upload-progress', { pct: 100 }); },
          function () { self.$dispatch('ss-upload-progress', { pct: null, error: true }); },
          function (e) { self.$dispatch('ss-upload-progress', { pct: e.detail.progress }); });
      },

      // the Move-to dialog (M)
      openMove(targets) {
        var ids = this.targets(); if (!ids.length) return;
        this.move = { ids: ids, to: targets.length ? targets[0].id : null, mode: 'end', before: null };
      },
      // Pages of the target chapter as currently shown — only when that chapter is on screen.
      beforeOptions() {
        if (!this.move || !this.move.to) return [];
        var sec = this.$root.querySelector('[data-ss-section="' + this.move.to + '"]');
        if (!sec) return [];
        var moving = this.move.ids;
        return Array.prototype.filter.call(sec.querySelectorAll('[data-ss-card]'), function (el) { return moving.indexOf(el.dataset.ssCard) < 0; })
          .map(function (el) { var b = el.querySelector('.ss-card__meta b'); return { id: el.dataset.ssCard, label: 'стр. ' + (b ? b.textContent : '') }; });
      },
      doMove() {
        if (!this.move || !this.move.to) return;
        this.$wire.movePages(this.move.ids, this.move.to, this.move.mode === 'before' ? this.move.before : null);
        this.move = null; this.selected = [];
      },

      // the one confirm dialog: title = question, text = consequence, record = what exactly
      ask(opts) { this.confirm = opts; },
      confirmYes() {
        var c = this.confirm; this.confirm = null;
        if (c && c.action) this.$wire[c.action].apply(this.$wire, c.args || []);
        if (c && c.clear) this.selected = [];
      },

      key(ev) {
        var t = ev.target && ev.target.tagName;
        if (this.confirm) { if (ev.key === 'Escape') this.confirm = null; if (ev.key === 'Enter') { ev.preventDefault(); this.confirmYes(); } return; }
        if (this.move) { if (ev.key === 'Escape') this.move = null; if (ev.key === 'Enter' && t !== 'SELECT') { ev.preventDefault(); this.doMove(); } return; }
        if (ev.shiftKey && (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') && (t === 'INPUT' || t === 'TEXTAREA') && ev.target.closest('.ss-insp')) {
          ev.preventDefault(); ev.target.blur(); this.$wire.stepPage(ev.key === 'ArrowDown' ? 1 : -1); return;
        }
        if (/^(INPUT|TEXTAREA|SELECT)$/.test(t || '')) return;
        if (ev.key === 'Escape') { if (this.dragIds) this.endDrag(); else this.clear(); }
        else if ((ev.key === 'm' || ev.key === 'M' || ev.key === 'ь' || ev.key === 'Ь') && !ev.metaKey && !ev.ctrlKey) { ev.preventDefault(); this.$dispatch('ss-open-move'); }
      },
    };
  }

  /** Walks dropped folders (webkitGetAsEntry) so subfolders arrive with their path — they become chapters. */
  function collect(dt) {
    var entries = Array.prototype.map.call(dt.items || [], function (i) { return i.webkitGetAsEntry ? i.webkitGetAsEntry() : null; }).filter(Boolean);
    if (!entries.length) return Promise.resolve(Array.prototype.map.call(dt.files || [], function (f) { return { file: f, path: f.name }; }));
    var out = [];
    function walk(entry, prefix) {
      if (entry.isFile) return new Promise(function (res) { entry.file(function (f) { out.push({ file: f, path: prefix + f.name }); res(); }, res); });
      if (!entry.isDirectory) return Promise.resolve();
      var r = entry.createReader(), all = [];
      return new Promise(function (res) {
        (function read() {
          r.readEntries(function (batch) {
            if (!batch.length) return Promise.all(all.map(function (e) { return walk(e, prefix + entry.name + '/'); })).then(res);
            all = all.concat(Array.prototype.slice.call(batch)); read();
          }, res);
        })();
      });
    }
    return Promise.all(entries.map(function (e) { return walk(e, ''); })).then(function () { return out; });
  }

  // ── Studio: the Interaction layer canvas (SR-02) ─────────────────────────────────────────────
  // Draws on the 0…1000 plane over the page picture; every finished shape goes to the server as plain
  // numbers (or a canonical path), which validates it. Nothing here edits the draft directly.
  var IX_TOOL = 'select';

  // SVG path (absolute or relative, M L H V C S Q T Z) → the profile's canonical subset, scaled from a
  // source viewBox to the plane. Arcs are refused rather than approximated behind the author's back.
  function canonicalPath(d, w, h) {
    var re = /([MmLlHhVvCcSsQqTtZzAa])|(-?(?:\d+\.?\d*|\.\d+)(?:e[-+]?\d+)?)/g, toks = [], m;
    while ((m = re.exec(d))) toks.push(m[1] || parseFloat(m[2]));
    var sx = 1000 / w, sy = 1000 / h, out = [], i = 0, cmd = null, cx = 0, cy = 0, sx0 = 0, sy0 = 0, lc = null, lq = null, segs = 0;
    function n() { var v = toks[i++]; if (typeof v !== 'number') throw new Error('Не хватает чисел в пути'); return v; }
    function f(v, s) { var x = Math.min(1000, Math.max(0, v * s)); return String(Math.round(x * 1000) / 1000); }
    function P(x, y) { return f(x, sx) + ' ' + f(y, sy); }
    while (i < toks.length) {
      if (typeof toks[i] === 'string') cmd = toks[i++]; else if (!cmd) throw new Error('Путь должен начинаться с M');
      var rel = cmd === cmd.toLowerCase(), C = cmd.toUpperCase(), x, y, x1, y1, x2, y2;
      if (C === 'A') throw new Error('Дуги (A) не поддерживаются — переведите путь в кривые');
      if (C === 'Z') { if (segs < 2) throw new Error('Контур слишком короткий'); out.push('Z'); cx = sx0; cy = sy0; segs = 0; cmd = null; continue; }
      if (C === 'M') { x = n(); y = n(); if (rel) { x += cx; y += cy; } if (out.length && out[out.length - 1] !== 'Z') { if (segs < 2) throw new Error('Контур слишком короткий'); out.push('Z'); }
        out.push('M ' + P(x, y)); cx = sx0 = x; cy = sy0 = y; segs = 0; cmd = rel ? 'l' : 'L'; lc = lq = null; continue; }
      if (C === 'L' || C === 'H' || C === 'V') {
        if (C === 'H') { x = n() + (rel ? cx : 0); y = cy; }
        else if (C === 'V') { x = cx; y = n() + (rel ? cy : 0); }
        else { x = n(); y = n(); if (rel) { x += cx; y += cy; } }
        out.push('L ' + P(x, y)); cx = x; cy = y; segs++; lc = lq = null; continue; }
      if (C === 'C' || C === 'S') {
        if (C === 'C') { x1 = n(); y1 = n(); if (rel) { x1 += cx; y1 += cy; } } else { x1 = lc ? 2 * cx - lc[0] : cx; y1 = lc ? 2 * cy - lc[1] : cy; }
        x2 = n(); y2 = n(); x = n(); y = n(); if (rel) { x2 += cx; y2 += cy; x += cx; y += cy; }
        out.push('C ' + P(x1, y1) + ' ' + P(x2, y2) + ' ' + P(x, y)); lc = [x2, y2]; lq = null; cx = x; cy = y; segs++; continue; }
      if (C === 'Q' || C === 'T') {
        if (C === 'Q') { x1 = n(); y1 = n(); if (rel) { x1 += cx; y1 += cy; } } else { x1 = lq ? 2 * cx - lq[0] : cx; y1 = lq ? 2 * cy - lq[1] : cy; }
        x = n(); y = n(); if (rel) { x += cx; y += cy; }
        out.push('Q ' + P(x1, y1) + ' ' + P(x, y)); lq = [x1, y1]; lc = null; cx = x; cy = y; segs++; continue; }
    }
    if (out.length && out[out.length - 1] !== 'Z') { if (segs < 2) throw new Error('Контур слишком короткий'); out.push('Z'); }
    if (!out.length) throw new Error('Пустой путь');
    return out.join(' ');
  }
  window.__ssCanonicalPath = canonicalPath;

  // Ramer–Douglas–Peucker: a hand-drawn line keeps its form with a few dozen points instead of hundreds.
  function simplify(pts, eps) {
    if (pts.length < 3) return pts;
    var a = pts[0], b = pts[pts.length - 1], max = 0, at = 0;
    for (var i = 1; i < pts.length - 1; i++) {
      var p = pts[i], dx = b[0] - a[0], dy = b[1] - a[1], len = Math.hypot(dx, dy) || 1;
      var dist = Math.abs(dy * p[0] - dx * p[1] + b[0] * a[1] - b[1] * a[0]) / len;
      if (dist > max) { max = dist; at = i; }
    }
    if (max <= eps) return [a, b];
    return simplify(pts.slice(0, at + 1), eps).slice(0, -1).concat(simplify(pts.slice(at), eps));
  }

  function ixCanvas() {
    return {
      data: { items: [], protected: [], selected: null },
      mode: IX_TOOL,
      target: 'new',
      draft: null,      // the shape being drawn
      poly: [],
      drag: null,
      importD: '', importW: 1000, importH: 1000, importError: '',

      init() {
        var self = this;
        this.load();
        var img = this.$root.querySelector('.ss-ixe__art img');
        if (img) img.addEventListener('load', function () { self.importW = img.naturalWidth; self.importH = img.naturalHeight; });
        if (img && img.complete && img.naturalWidth) { this.importW = img.naturalWidth; this.importH = img.naturalHeight; }
        if (window.Livewire && Livewire.hook) Livewire.hook('morphed', function (o) { if (o && o.component && o.component.id === self.$wire.$id) self.load(); });
      },
      load() { var el = this.$root.querySelector('[data-ss-ixe-data]'); if (el) this.data = JSON.parse(el.textContent); if (!this.data.selected && this.target !== 'new') this.target = 'new'; },
      setMode(m) { this.mode = IX_TOOL = m; this.poly = []; this.draft = null; },
      hint() {
        return ({ select: 'Клик по области — выбрать; перетаскивание — сдвинуть.', rect: 'Протяните рамку.', ellipse: 'Протяните овал.',
          polygon: 'Кликайте по углам; двойной клик или клик в первую точку — замкнуть; Esc — отмена.', pen: 'Обведите фигуру, не отпуская кнопку.',
          protect: 'Протяните рамку вокруг реплики или важного текста.' })[this.mode] || '';
      },
      pt(ev) { var r = this.$refs.art.getBoundingClientRect(); return [Math.min(1000, Math.max(0, (ev.clientX - r.left) / r.width * 1000)), Math.min(1000, Math.max(0, (ev.clientY - r.top) / r.height * 1000))]; },
      hit(p) {
        var items = this.data.items;
        for (var i = items.length - 1; i >= 0; i--) if (IXGeo.contains(items[i].shape, p[0], p[1])) return items[i];
        return null;
      },

      down(ev) {
        if (ev.button > 0) return;
        var p = this.pt(ev);
        if (this.mode === 'select') {
          var it = this.hit(p);
          if (it) { this.$wire.select(it.id); this.drag = { id: it.id, from: p, dx: 0, dy: 0, shape: it.shape }; this.$refs.art.setPointerCapture(ev.pointerId); }
          return;
        }
        if (this.mode === 'polygon') {
          if (this.poly.length > 2 && Math.hypot(p[0] - this.poly[0][0], p[1] - this.poly[0][1]) < 12) return this.closePolygon();
          this.poly.push(p); return;
        }
        this.draft = { from: p, to: p, pts: [p] };
        this.$refs.art.setPointerCapture(ev.pointerId);
      },
      moveAt(ev) {
        var p = this.pt(ev);
        if (this.drag) { this.drag.dx = p[0] - this.drag.from[0]; this.drag.dy = p[1] - this.drag.from[1]; return; }
        if (this.draft) { this.draft.to = p; if (this.mode === 'pen') this.draft.pts.push(p); }
      },
      up(ev) {
        if (this.drag) {
          var d = this.drag; this.drag = null;
          if (Math.abs(d.dx) + Math.abs(d.dy) > 2) this.$wire.setShape(d.id, this.moved(d.shape, d.dx, d.dy), 'shape');
          return;
        }
        if (!this.draft) return;
        var dr = this.draft; this.draft = null;
        var shape = this.shapeOf(dr);
        if (shape) this.commit(shape);
      },
      cancel() { this.draft = null; this.drag = null; },
      closePolygon() {
        if (this.mode !== 'polygon' || this.poly.length < 3) return;
        var pts = this.poly.map(function (p) { return [p[0], p[1]]; }); this.poly = [];
        this.commit({ type: 'polygon', points: pts });
      },
      shapeOf(dr) {
        var x = Math.min(dr.from[0], dr.to[0]), y = Math.min(dr.from[1], dr.to[1]), w = Math.abs(dr.to[0] - dr.from[0]), h = Math.abs(dr.to[1] - dr.from[1]);
        if (this.mode === 'pen') {
          var pts = simplify(dr.pts, 2.5).slice(0, 400);
          if (pts.length < 3) return null;
          return { type: 'path', fillRule: 'evenodd', d: pts.map(function (p, i) { return (i ? 'L ' : 'M ') + (Math.round(p[0] * 10) / 10) + ' ' + (Math.round(p[1] * 10) / 10); }).join(' ') + ' Z' };
        }
        if (w < 4 || h < 4) return null;
        if (this.mode === 'ellipse') return { type: 'ellipse', cx: x + w / 2, cy: y + h / 2, rx: w / 2, ry: h / 2 };
        return { type: 'rect', x: x, y: y, width: w, height: h };
      },
      commit(shape) {
        if (this.mode === 'protect') return this.$wire.protect(shape);
        if (this.target !== 'new' && this.data.selected) return this.$wire.setShape(this.data.selected, shape, this.target);
        this.$wire.addRegion(shape);
      },
      moved(s, dx, dy) {
        var c = function (v) { return Math.min(1000, Math.max(0, v)); };
        if (s.type === 'rect') return { type: 'rect', x: c(s.x + dx), y: c(s.y + dy), width: s.width, height: s.height };
        if (s.type === 'ellipse') return { type: 'ellipse', cx: c(s.cx + dx), cy: c(s.cy + dy), rx: s.rx, ry: s.ry };
        if (s.type === 'polygon') return { type: 'polygon', points: s.points.map(function (p) { return [c(p[0] + dx), c(p[1] + dy)]; }) };
        var t = s.d.split(' '), out = [], axis = 0;
        t.forEach(function (tok) { if (/^[MLQCZ]$/.test(tok)) { out.push(tok); axis = 0; } else { out.push(String(Math.round(c(parseFloat(tok) + (axis++ % 2 ? dy : dx)) * 1000) / 1000)); } });
        return { type: 'path', d: out.join(' '), fillRule: s.fillRule };
      },
      importPath() {
        this.importError = '';
        try {
          var d = canonicalPath(this.importD, this.importW || 1000, this.importH || 1000);
          this.commit({ type: 'path', d: d, fillRule: 'evenodd' }); this.importD = '';
        } catch (e) { this.importError = e.message; }
      },
      // Offsets of the selection inside one published text: those are the run boundaries the server cuts.
      linkSelection() {
        this.importError = '';
        var sel = window.getSelection(); if (!sel || sel.isCollapsed) { this.importError = 'Сначала выделите слово в тексте.'; return; }
        var el = sel.anchorNode && (sel.anchorNode.nodeType === 3 ? sel.anchorNode.parentElement : sel.anchorNode);
        var box = el && el.closest('[data-ss-text-target]');
        if (!box || !box.contains(sel.focusNode)) { this.importError = 'Выделение должно быть внутри одного текста.'; return; }
        var r = sel.getRangeAt(0), pre = document.createRange(); pre.selectNodeContents(box); pre.setEnd(r.startContainer, r.startOffset);
        var start = Array.from(pre.toString()).length, len = Array.from(r.toString()).length;
        this.$wire.textLink(box.getAttribute('data-ss-text-target'), start, start + len);
      },
      key(ev) {
        if (ev.target && /^(INPUT|TEXTAREA|SELECT)$/.test(ev.target.tagName)) return;
        if ((ev.metaKey || ev.ctrlKey) && (ev.key === 'z' || ev.key === 'Z' || ev.key === 'я')) { ev.preventDefault(); ev.shiftKey ? this.$wire.redo() : this.$wire.undo(); return; }
        if (ev.key === 'Escape' && (this.poly.length || this.draft)) { ev.stopPropagation(); this.poly = []; this.draft = null; }
      },

      svg() {
        var self = this, out = '', sel = this.data.selected;
        (this.data.protected || []).forEach(function (s) { out += '<g class="ss-ixe__prot">' + IXGeo.svg(s) + '</g>'; });
        this.data.items.forEach(function (it) {
          var shape = self.drag && self.drag.id === it.id ? self.moved(it.shape, self.drag.dx, self.drag.dy) : it.shape;
          out += '<g class="ss-ixe__shape" data-sel="' + (it.id === sel ? 1 : 0) + '" data-bad="' + (it.invalid ? 1 : 0) + '">' + IXGeo.svg(shape) + '</g>';
          if (it.outline) out += '<g class="ss-ixe__outline" data-sel="' + (it.id === sel ? 1 : 0) + '">' + IXGeo.svg(it.outline) + '</g>';
        });
        if (this.draft) { var sh = this.shapeOf(this.draft); if (this.mode === 'pen') out += '<polyline class="ss-ixe__ink" points="' + this.draft.pts.map(function (p) { return p[0].toFixed(1) + ',' + p[1].toFixed(1); }).join(' ') + '"/>'; else if (sh) out += '<g class="ss-ixe__new">' + IXGeo.svg(sh) + '</g>'; }
        if (this.poly.length) out += '<polyline class="ss-ixe__ink" points="' + this.poly.map(function (p) { return p[0].toFixed(1) + ',' + p[1].toFixed(1); }).join(' ') + '"/>';
        return '<svg viewBox="0 0 1000 1000" preserveAspectRatio="none" aria-hidden="true">' + out + '</svg>';
      },
    };
  }

  function register(Alpine) {
    Alpine.data('ssReader', reader);
    Alpine.data('ssEditor', editor);
    Alpine.data('ssIxCanvas', ixCanvas);
  }

  if (window.Alpine && window.Alpine.version) {
    // Alpine is already running (assets printed late): register, then initialise roots it skipped.
    register(window.Alpine);
    document.querySelectorAll('[data-ss-root]:not([data-ss-booted])').forEach(function (el) { el.setAttribute('data-ss-booted', ''); window.Alpine.initTree(el); });
  } else {
    document.addEventListener('alpine:init', function () { register(window.Alpine); });
  }
})();
