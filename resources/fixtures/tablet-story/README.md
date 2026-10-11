# Tablet story — a conformance fixture

Schematic artwork made for this fixture, not an art sample: two 800×1100 pages, shapes given in the 0…1000
plane of the profile ([../../../docs/FORMAT.md](../../../docs/FORMAT.md)).

- `story.json` — the edition: two pages, their captions and text.
- `interaction.json` — the full profile: a character region with a hole, an essential two-way menu, a small
  shell, a footnote on a line of dialogue and a text link. Bound to the exact bytes of `story.json`.
- `minimal.json` — the smallest valid profile: one rectangle hit with a separate exact outline.
- `assets/` — the page pictures.

The shell overlaps part of the figure on purpose: the right result is a chooser between the two, never
silently dropping the smaller region. The shell's soft-focus hint may fall back to an outline without
changing its action.

## Check

Importing validates the profile against `resources/schema/interaction.schema.json` and the semantic rules,
and refuses a profile bound to other `story.json` bytes:

```bash
php artisan story-studio:fixture vendor/ombabush/story-studio/resources/fixtures/tablet-story
```

A plain CBZ of pictures without `interaction.json` stays an ordinary book.
