# Story Reader data model

This document describes how an edition, a reader's own state and the reader's capabilities are stored and kept apart. The point of the model is that a new capability is **a row of data or a catalog entry**, not a new column and not a new `if (view === 'book')` branch in the reader.

Table names below use the default prefix `ss_` (`story-studio.table_prefix`). The format of the published snapshot and of `.story.zip` is specified in `FORMAT.md`.

## Three layers and one rule

| Layer | Where | Goes into a snapshot or export | Written by |
|---|---|---|---|
| **Edition**: what the author published | `ss_stories`, `ss_nodes`, `ss_pages`, `ss_texts`, `ss_attachments`, the interaction profile | yes, through a whitelist | Studio, import, `ApplySnapshot` |
| **Reader**: the reader's own state | `ss_progress`, `ss_marks`, `ss_reader_prefs` | never | the Reader, keyed by the reader's key |
| **Catalog**: what this Reader can do | `config('story-studio.reader.*')` plus `resources/lang/*/reader.php`, exposed through `ReaderCatalog` | no (it is host code, not data) | the host developer |

The rule: **the edition says "what", the catalog says "how", the reader says "what suits me".** An edition never stores CSS or screen layouts, the catalog never stores content, and one reader's state never reaches another reader.

## 1. Edition

### 1.1 Edition settings: `ss_stories.settings`

A JSON column, typed by `Support\Edition`.

```json
{
  "languages": { "original": "en", "offered": ["en", "de"] },
  "views":     { "offered": ["grid", "read", "cinema", "book", "scroll", "story"], "default": "read" },
  "source":    { "storyId": "…", "revision": "sha256:…", "retained": "…" }
}
```

- `Edition::of($story)` reads the settings, fills in defaults and normalises them. An unknown view is dropped, `default` must be one of `offered`, and the original language is always first in `languages.offered`. Empty settings mean "everything by default": the original is `story-studio.reader.original` (falling back to `config('app.locale')`), and all reader views of the catalog are offered.
- If no default view is chosen, the reader starts on the first single-page view (layout `page`) among the offered ones, not on an overview.
- The legacy key `modes` is read as `views.offered`. `Edition::write()` removes it when settings are saved.
- Language tags are lower-cased BCP-47 style (`en`, `pt-BR` keeps its region part). Anything that does not match is ignored.
- An editor-only view (the `script` view, flagged `'editor' => true` in the catalog) is never part of `views.offered`: it shows the internal layer, and access to that layer is decided by the authorizer (`Access::canManage`), not by an edition preset.
- `source` is a service marker left by import. The package keeps it as it is and does not interpret it.
- Note: when an edition has not named its original language, `Edition::of` takes it from the edition's interaction profile (`interaction.language`) before falling back to the config.

### 1.2 Translations: `ss_texts`

One table for every translatable field of every entity in an edition. The original stays in its own columns (`pages.caption`, `nodes.title`, …); `ss_texts` holds translations only.

| Column | Meaning |
|---|---|
| `story_id` | the edition (cascade delete) |
| `subject` | `story`, `node` or `page` |
| `subject_id` | the `public_id` of the entity (it survives reordering and is the same on every host that holds the edition) |
| `lang` | a BCP-47 tag (`en`, `pt-BR`) |
| `fields` | JSON `{field: text}` |

Unique key: `(story_id, subject, subject_id, lang)`. The allowed fields are `Support\Texts::FIELDS`:

- `story`: `title`, `description`;
- `node`: `title`;
- `page`: `caption`, `body`, `alt`, `time_label`, `time_place` are public; `dialogue` is **internal** and goes only to editors, exactly like the original.

Rules enforced by `Texts::set()`:

- an empty value removes the field, and a row left with no fields is deleted;
- the original language of the edition cannot be stored as a translation;
- unknown fields are ignored; values are trimmed and cut to 5000 characters (50000 for `body`);
- deleting a page or a chapter deletes its translations.

An empty field means "no translation": the reader falls back along its language chain (see `lang` in §2) and ends at the original. A reader receives only the languages the edition offers; an editor receives every stored language, so unfinished ones can be previewed. `Texts::coverage` reports how many visible pages have at least one public field in a given language.

### 1.3 Attachments: `ss_attachments`

Typed items attached to an edition, a chapter or a page. There are two kinds today. A new kind is an entry in `Attachments::KINDS` plus its normaliser, with no migration.

| Column | Meaning |
|---|---|
| `public_id` | ULID, stable across hosts |
| `story_id` | the edition |
| `scope` / `scope_id` | `story` (`scope_id` is null), `node` or `page`, plus the entity's `public_id` |
| `kind` | `track` or `link` |
| `position` | order within the scope |
| `data` | JSON; its shape depends on `kind` |

- `track`: `{title, artist?, seconds?, url?}`. Music for a place in the story. The reader shows what plays here by priority **page, then chapter, then edition**. `title` is required (200 characters), `artist` up to 200, `seconds` a positive integer up to 86400.
- `link`: `{label, url}`. A "listen on Spotify / Apple Music" link of the edition. `label` (120 characters) and a valid `url` are required. Links are read only from the `story` scope.
- Invalid data is refused, never stored half-right.
- URLs must be `https://` (at most 2000 characters), and the reader always shows the host before the visitor leaves the book (the `open-url` action in `FORMAT.md`).
- Not included: the model does not store audio files of its own; playback is an external link. A file source can later become a media-library item of the same record without changing the shape of `data`.
- Deleting a page or a chapter deletes its attachments.

### 1.4 Page layout hints: `ss_pages.hints`

A JSON column typed by `Support\PageHints`:

```json
{ "pair": "alone|start", "pan": { "seconds": 24 } }
```

- `pair`: `auto` means the available width decides whether pages are shown as a spread; `alone` means always a single page (a wide drawing, a cover); `start` means a pair begins on this page (a half-page shift, "start on the right-hand page"). `auto` is the default and is not stored.
- `pan.seconds`: the pace of the auto-scroll of a panorama (Cinema view); accepted range 3 to 600.
- Unknown keys and bad values are dropped on write, so the column only ever holds this shape; an empty result is stored as null.
- `regions` (paragraph to picture area, used by the Story view) is a separate column, not part of the interaction profile.

### 1.5 Snapshot and export

`Snapshot` keeps `SCHEMA = 1`. Two keys were added **additively**: `texts` (public fields only, published subjects only) and `attachments` (attachments of published subjects only). An older receiver does not read them and keeps working; a current `ApplySnapshot` mirrors them exactly. A `.story.zip` carries the same bytes as the snapshot, so translations and music also travel through import, with subject ids mapped to the new ones.

In a snapshot, a `texts` row looks like `{subject, id, lang, fields}` and an `attachments` row like `{id, scope, scopeId, kind, data}`. Page hints travel inside the page (`hints`). Internal fields such as `dialogue` are never exported.

## 2. Reader state: `ss_reader_prefs`

| Column | Meaning |
|---|---|
| `reader_key` | `u:<id>` for a signed-in user, `g:<token>` for a guest; the same key as for progress and marks |
| `story_id` | null means the reader's general settings; otherwise settings for that edition |
| `prefs` | JSON, whitelisted by `Support\ReaderPrefs` |

Unique key: `(reader_key, story_id)`. Keys (unknown keys and invalid values are dropped by the server, never stored):

| Key | Scope | Values |
|---|---|---|
| `ui` | general | `edges`, `bars` |
| `theme` | general | `dark`, `light`, `paper` (limited to the catalog's themes) |
| `textSize` | general | 80 to 160 (percent) |
| `uiLang` | general | an interface language from the catalog |
| `lang` | general, and per edition as an override | `{primary, fallback, uiFirst}`, the chain of text languages |
| `ix` | general | `{mode: on\|on-demand\|off, fx: author\|<effect>, kinds: {kind: effect}}` |
| `view` | per edition | a view key from the edition's `views.offered` |
| `order` | per edition | `told`, `happened` |

The Reader receives the merged settings in its payload (`prefs`: general overlaid with the edition's own) and sends changes in one debounced request, `POST {reader prefix}/{story}/prefs` with `{scope: global|story, prefs}`. A null value removes a key, and a scope left with no settings is deleted. `localStorage` is only a fallback cache for when the network fails, not the source of truth.

Marks (`ss_marks`) take their allowed kinds from the catalog (`story-studio.reader.marks`, by default `bookmark` and `note`). A further kind is a catalog entry, not a schema change.

## 3. Catalog: `Support\ReaderCatalog`

Everything the browser code would otherwise have to know by name arrives in the `catalog` part of the payload. The catalog reads `config('story-studio.reader.*')`; a key the host does not set falls back to the package's default.

```php
'views' => [
    'grid'   => ['key' => '1', 'layout' => 'grid',   'fit' => 'page',  'text' => 'none', 'cells' => 4],
    'read'   => ['key' => '2', 'layout' => 'page',   'fit' => 'page',  'text' => 'below'],
    'cinema' => ['key' => '3', 'layout' => 'page',   'fit' => 'width', 'text' => 'subtitle'],
    'book'   => ['key' => '4', 'layout' => 'pair',   'fit' => 'page',  'text' => 'beside'],
    'script' => ['key' => '5', 'layout' => 'page',   'fit' => 'page',  'text' => 'script', 'editor' => true],
    'scroll' => ['key' => '6', 'layout' => 'scroll', 'fit' => 'width', 'text' => 'below'],
    'story'  => ['key' => '7', 'layout' => 'story',  'fit' => 'page',  'text' => 'story'],
],
```

- **A view is a layout, a fit and a text placement.** There are five layouts (`grid`, `page`, `pair`, `scroll`, `story`), drawn by the package's Blade views; fit is `page` or `width`; text is one of `none`, `below`, `subtitle`, `beside`, `script`, `story`. Views are combinations of these. A host adds a view with one config line and a label in a lang file: for example "Cinema without text" is `page` / `width` / `none`, with its name under `views.<name>` in `resources/lang/<code>/reader.php`. `key` is the hotkey, `cells` (2 to 9, grid layout) is the grid width, and `editor` marks a view only an editor may open.
- `effects` (the highlight effects this renderer draws; `outline` is always present), `kinds` (`nav`, `character`, `object`, `place`, `note`, `media`, `reference`), `marks`, `uiModes`, `themes` and `ixModes` are lists that would otherwise be constants in the JavaScript.
- `uiLanguages` lists the interface languages (each needs a `resources/lang/<code>/reader.php`; the package ships `ru` and `en`). `strings` holds every reader string in the interface language. Blade reads them through `__()`, JavaScript through `t('key', {n: …})`.

## What is deliberately not included

- Audio files of the host's own and a "read aloud" synchronisation: music is an external link for now (see §1.3).
- Editor comments on pages: a possible future mark kind with visibility limited to editors, which needs its own access decision.
- The Script view for readers: it shows the internal layer and is available to editors only.
