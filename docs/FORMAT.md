# Story Interaction Profile 1.0-draft.1

Status: draft. This format is not yet a stable public standard; field meanings may still change before `1.0`. The machine-readable structure is [interaction.schema.json](../resources/schema/interaction.schema.json); a worked example lives in [the tablet-story fixture](../resources/fixtures/tablet-story/). The linking, size and coordinate rules below are mandatory in addition to the JSON Schema. For the data model the profile binds to, see [DATA-MODEL.md](DATA-MODEL.md).

## A layer over a published story

The profile describes links inside content that is already published. It does not replace HTML, EPUB or the existing story snapshot (`Snapshot::SCHEMA = 1`). The snapshot stays `story.json`; `interaction.json` is added next to it. A plain CBZ may have no `interaction.json` at all; that is a normal, non-interactive book.

The container for offline exchange is a ZIP of ordinary files. The working extension is `.story.zip`; no new MIME type is registered before a stable release:

```text
story.json
interaction.json
assets/
```

This is a list of paths, not a layout language. The archive carries no reader runtime. The reader's HTML/CSS/JS ships with the host application from a trusted build; an application must never be imported together with a comic.

`story.json` is a public Story Studio schema-1 snapshot, or a public snapshot converted by a host adapter into the same reader DTO. The example uses the existing Story Studio shape: story, nodes, pages. All IDs are preserved. The asset bundle binds a page image to assets through the profile's `surface`; the SHA-1 of the existing transport is kept for now, while validation of the new container uses SHA-256.

The profile's `revision` is `sha256:` followed by the SHA-256 of the **exact UTF-8 bytes of `story.json`**. This is deliberately not a hash of a re-serialised JSON value: the exporter writes the snapshot once and hashes those bytes. Any change or reformatting of the snapshot requires updating the binding. A general importer must verify this binding so that the hotspots never land on a different version of a page. `interaction.json` itself is not part of that hash, so there is no circular hash.

## Minimal authoring record

An author in an editor answers four questions: where is the area, what is it called, what does it open, which effect to suggest. Story ID, revision, assets and the public export are assembled automatically.

```json
{
  "id": "PIER_ILYA_PROFILE",
  "kind": "character",
  "label": { "ru": "Узнать про Илью", "en": "About Ilya" },
  "anchor": {
    "type": "region",
    "shape": { "type": "rect", "x": 360, "y": 140, "width": 270, "height": 670 }
  },
  "action": { "type": "open-card", "cardId": "CHARACTER_ILYA" },
  "presentation": { "preferredEffect": "outline" }
}
```

This is an Interaction inside `pages[].interactions`. An authoring record never contains `onHover`, `style`, an `href` with an internal host route, or device-specific parameters.

## interaction.json root

| Field | Required | Meaning |
|---|---|---|
| `profile` | yes | exactly `story-interaction/1.0-draft.1` |
| `storyId` | yes | public stable story ID |
| `revision` | yes | binding to a specific `story.json` (`sha256:` + 64 lowercase hex digits) |
| `language` | yes | language of the original, BCP 47 |
| `assets` | yes | local resources: id, path, mediaType, sha256 (at most 10 000) |
| `cards` | yes | array of portable public cards; may be empty (at most 2 000) |
| `pages` | yes | interactive page bindings, 1…5 000; pages without interactions may be included |

Do not duplicate the title, authors, main structure, private access policy or user settings; they already live in the story snapshot or the host. A profile may describe a subset of the published pages. A `jump` target may be any page **of the same `story.json`**, even one with no interaction binding.

All core objects have `additionalProperties: false`. An unknown field is an import error, not a new executable hook. The first draft provides no extension mechanism; an experimental field stays in the source editor draft and does not leak into the public contract.

## Assets and cards

Asset: `id`, `path` relative to the container, `mediaType`, `sha256` (64 lowercase hex). Images additionally require integer `width` and `height` (1…40000). In v1 the allowed types are JPEG, PNG, WebP, audio/mpeg, audio/ogg, audio/mp4 and video/mp4. Audio and video are runtime capabilities: a missing codec does not make the action itself unknown. The reader shows "media not supported" plus the metadata; autoplay is forbidden.

`path` starts with `assets/` and contains only ASCII letters, digits, `_`, `-`, `.` and `/`. Segments `.` and `..`, empty segments, absolute paths, `\`, URIs, `%`, query and fragment are forbidden. Paths are case-sensitive, 8…240 characters. An importer checks the real MIME type from the bytes, the size, the hash and the image after decoding, not just the extension.

Card: `id`, `kind` (`character|object|place|note|reference`), `title` per language, optional `body` per language and optional `imageAssetId` (must name an image asset). The body is plain text with line breaks. The format accepts no Markdown, HTML, embeds or routes to private entities. For an MVP this is enough; an extended dossier is opened by an ordinary link through a separate action.

A card with private notes does not become public merely because a hotspot was drawn on it. The exporter must separately approve a specific projection of the card. An offline card contains approved text; there is no automatic remote lookup on hover.

## Page and anchors

Page binding: `id`, `surface` (an asset reference or `null`, always present), `interactions`; optional `anchors`, `textBlocks`, `groups`, `protectedAreas`.

- `surface: {assetId}` binds regions to the **full** published image. Dimensions come from the Asset. Regions cannot be bound to a thumbnail or a cropped display version without explicit normalisation by the exporter.
- `surface: null` means a text page or placeholder; region interactions are forbidden. If artwork fails to load, the runtime disables its region targets and keeps the action list.
- `anchors[]`: `{id, shape}` are jump targets inside a page: a frame, a detail, an area. Their shape defines position and fit, not a separate button.
- `textBlocks[]`: `{id, runs:[{id,text}]}` is a minimal text projection of published captions and paragraphs. Plain-text runs are concatenated into text; arbitrary rich HTML is not allowed. Blocks and runs get stable IDs in the draft and are preserved in the revision. They do not replace the canonical blocks of the host's document model.
- A text interaction is anchored as `{type:"text", blockId, runId}`. The run must exist on that very page. UTF-16 offsets or "word number 18" are not exported: localisation and editing break them. The draft recompiles the projection and the bindings together. If a translation of a text run is missing, the reader shows the original run and states the language clearly, rather than linking to a wrong word.
- `groups[]`: `{id,label}`; an Interaction may reference `groupId`, which must exist in this page's `groups`. A menu group contains flat items, ordered by `order` or by array order. It is not a component in which the editor runs its own code.
- `protectedAreas[]` are shapes of speech lines or important text; they help preview positioning and the choice of decorative effects. If protected regions are absent or unreliable, the reader applies no blur/dim to the content and chooses outline instead. No automatic effect guarantees recognition of text inside a raster.

Empty arrays may be omitted, except for the required `interactions`. The text projection must match the published text; an adapter must not publish draft notes or lines just to support a "script" mode.

Limits per page: 256 interactions, 512 anchors, 512 text blocks, 2 000 runs per block, 256 groups, 256 protected areas; a run's text is 1…20 000 characters.

## Geometry

A page uses a normalised plane **0…1000 on X and 0…1000 on Y**. `(0,0)` is the top-left corner of the source artwork, regardless of LTR/RTL. X is normalised by width, Y by height. For a 1600×2400 image the point `(500,250)` means `(800,600)` source pixels. Viewport percentages and device pixels are never used.

| Shape | Fields | Rules |
|---|---|---|
| rect | x, y, width, height | positive sizes, entirely inside the page |
| ellipse | cx, cy, rx, ry | positive radii, bounding box inside the page |
| polygon | points:[[x,y],…] | 3…512 points, a simple closed area, at least 3 distinct points |
| path | d, fillRule | strict subset of SVG path; fillRule `nonzero` or `evenodd` |

`region.shape` defines the exact hit area. The optional `region.outline` is a more precise Shape for the visible contour or mask, used when the hit region is coarse. Without `outline` the reader uses `shape`. An effect never enlarges the hit area and never changes target coordinates. An invisible enlargement for fingers is computed **by the reader in screen units** and is not exported.

Path uses the existing SVG geometry but not a whole SVG document. The canonical v1 commands are `M x y`, `L x y`, `Q x1 y1 x y`, `C x1 y1 x2 y2 x y` and `Z`. Only uppercase absolute commands; an explicit letter before every set of arguments; numbers 0…1000 with at most three decimal places; single spaces between tokens; no exponents and no commas. Every subpath starts with `M`, has at least two drawing segments and ends with `Z`. All contour and control points lie inside the plane. Limits: 16 384 ASCII bytes of `d`, 1 024 commands per shape, 16 subpaths. An editor-side importer converts A/H/V/S/T, relative commands and transforms into this subset; the runtime does not "fix" an arbitrary `d` by eye.

Several subpaths with `evenodd` allow holes. A self-intersecting polygon is an error; for an intentional compound contour the author uses a path with an explicit `fillRule`. A path must have non-zero area; a semantic importer flattens curves with a maximum error of 0.5 normalised units and checks the resulting areas.

Note: the reference implementation (`ProfileValidator` and `Geometry`) checks the path grammar, subpath/command/byte limits, coordinate range and polygon self-intersection. It does not flatten curves or check path area; those remain a runtime conformance concern, as do pixel checks.

In a double-page spread each artwork has its own transform; a spread with one shared image is a single surface. RTL reorders pages and the paging direction; it **does not mirror the artwork or the contours**. Crop, panorama, panel view, story view and zoom apply the same transform to the artwork and to the geometry. Hit-testing uses the inverse transform and the clip of the specific viewport. Offscreen areas receive no hover or focus proxy over the visible picture.

For `background-size: cover` the scale is max(slotWidth/imageWidth, slotHeight/imageHeight); for contain it is min; then position offsets, the placement of the image slot and the overall zoom/pan are applied. A mixed page may have a slot covering only part of the page: region coordinates refer to the source artwork, not to the outer text or page box. A string of percentages without this transform produces a wrong contour even when the JSON is right.

## The closed Action

| `type` | Parameters | Result |
|---|---|---|
| `jump` | pageId, optional anchorId | go to a page of the same story; keep a return point |
| `open-card` | cardId | open a local/public card over the reading view |
| `open-url` | url | explicitly open an HTTPS link externally |
| `play-media` | assetId | start permitted local audio/video after user activation |

An interaction has exactly one action. The action list is closed: no other types exist, and an unknown action is never executed. A `jump` to a chapter is compiled by the exporter into the stable pageId of the first permitted page of that chapter in this snapshot; there are no runtime queries to a draft tree. `pageId` must be a page of the bound story; `anchorId`, when given, must exist among the `anchors` of the target page's binding.

`open-url` is an absolute HTTPS URL only (9…2048 characters), with no userinfo, control characters or invisible-character tricks. `javascript:`, `data:`, `file:`, relative URLs and custom app schemes are forbidden. Import never follows the link. When opened, the runtime shows the domain; the external navigation goes through the system's safe mechanism, without an opener. Publishing an arbitrary URL does not give the server the right to download it.

`play-media` references an asset with a declared audio/video MIME type; the action cannot open an audio provider through an arbitrary iframe. Any auxiliary music player is a separate media/control source, not a new executable action.

## Interaction and author preference

Required: `id`, `kind`, `label`, `anchor`, `action`. `kind` is one of `nav|character|object|place|note|media|reference`. `importance` defaults to `supplementary`; the only other value is `essential`, which is allowed for `jump` only. `order` is an integer 0…10000 and defaults to the array index. `groupId` must exist in this page's groups.

`presentation` is optional. Fields: `preferredEffect` from the catalogue (`outline`, `brackets`, `ink`, `quiet`, `spotlight`, `soft-focus`, `halo`, `lift`, `duotone`) and `strength` (`subtle` or `normal`). There is no timing, no CSS filter string, no CSS colours, no asset script and no framework-specific methods. The value `soft-focus` is a request to blur the surroundings; it does not require specific blur pixels.

The author may choose an effect for an object. The reader's override resolves in this order: per-kind override, then general override, then author preference, then default. Effects are chosen after accessibility and capability checks. A known effect the runtime cannot render is replaced by outline. The semantic action is preserved.

## Languages, IDs and order

IDs are 1…160 ASCII characters matching `[A-Za-z0-9][A-Za-z0-9._:-]*`. A UUID is acceptable. Human-readable examples use explanatory IDs (`BOOK_01_CHAPTER_01_PIER`), and a UI shows a caption or page number, not a bare machine ID. Uniqueness: assets, cards and pages are unique within their own registry; interactions are unique across the whole profile; anchors are unique within a page; blocks are unique within a page and runs within a block; groups are unique within a page.

Label, title and body maps use language tags and need at least one translation (at most 100). Labels and titles are at most 500 characters, card bodies at most 20 000. Every label and title MUST contain non-empty text in the `language` of the original. Fallback order: the reader's choice, then their fallback languages, then the original; the interface language does not replace the language of the page. Text containing `<` remains plain text and escaping is the renderer's job. Locale-aware bidi isolation is mandatory; the interface language and the comic's direction are independent.

The order of `pages` does not replace the reading order of `story.json`; `interactions.order` is not a z-index. Overlapping targets must not hide each other because of editorial z-order: the runtime offers a chooser.

## Validation and compatibility

Importer order: retain, inventory, ZIP limits, schema, semantic references, assets/decode/hash, access/publication checks, preview, bounded apply, verify/receipt. Import never publishes by itself.

Errors that block apply: wrong profile, unknown action or field, a broken essential target, wrong snapshot hash, geometry outside bounds, a non-existent run/card/asset, an invalid URL or path, an active file, a missing label, a duplicate ID, damaged required assets. A draft UI may save an incorrect region, but export does not release it without an error. An insignificant overlap or a tiny area is a warning with a chooser/list fallback, not a loss of the object.

Default host importer limits for v1: 5 000 pages, 10 000 assets, 2 000 cards, 256 interactions per page, 8 MiB of interaction JSON, 1 GiB of unpacked archive, 40 MiB per asset, 40 megapixels per decoded image. The ZIP must not contain symlinks, absolute or parent paths, duplicate names after normalisation/case policy, or a compression ratio above 100:1. Exceeding a limit is a clear error before decoding; limits are configured by the host, not by the author.

Note: `ProfileValidator` implements the structural and semantic-reference stages (schema, field closure, uniqueness, reference resolution, URL/path/shape rules, original-language labels). Snapshot-hash binding, ZIP inspection, byte-level MIME/hash/decode checks and the size limits above belong to the host importer and are not performed by the validator. Asset-path uniqueness and `sha256` correctness are likewise not checked there.

An older reader without the profile reads the ordinary image/text. For an unknown major version or profile, open an explicit static preview or refuse interactive mode; never claim a full pass through branches. An unknown action is not executed. A known profile whose optional media or effect is unsupported keeps its labels, navigation and alternative actions. With a stale revision the overlay is disabled as a whole, with a clear message.

A stable public `1.0` is released after conformance of two implementations/two host applications and a real tablet. The profile version is independent of the Composer version, any native app version and the existing Snapshot schema. Changes to field meanings require a new version; a compatible importer migrates retained data explicitly, never silently.
