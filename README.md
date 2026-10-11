# Story Studio

A Laravel package for paged stories — comics, manga, illustrated books: a **Reader** for anyone you let in
and a **Studio** editor for the people who make them, on one core. Pictures live in your Spatie MediaLibrary,
the table of contents in a kalnoy/nestedset tree; the package adds no second file store and no second tree.

Status: 0.x — the API and the interaction format (1.0-draft.1) may still change between minor versions. MIT licence.

## Requirements

PHP 8.3+, Laravel 12, Livewire 3.5+, spatie/laravel-medialibrary 11, kalnoy/nestedset 6. `ext-zip` for CBZ/ZIP
uploads, `ext-gd` only for the demo command.

## Install

```bash
composer require ombabush/story-studio
php artisan vendor:publish --tag=medialibrary-migrations   # if your app has no `media` table yet
php artisan migrate
php artisan vendor:publish --tag=story-studio-config        # optional
```

What the host must provide (each of these is checked by `tests/Conformance`):

- an `APP_KEY`, a users table, and `story-studio.user_model` pointing at your user model;
- MediaLibrary's `media` table;
- the `web` middleware around any page that embeds a reader — the reader keeps progress in the session;
- a private disk (`story-studio.storage.disk`, default `local`). Pictures are never on a public disk: every image
  goes through `/stories/{story}/media/{uuid}/{size}`, which checks access on each request and answers 404
  to anyone who may not see it.

## Reader

`/stories/{id}` reads a published edition. To embed one in your own page (several per page are fine):

```blade
<x-story-studio::reader :story="$story" />
```

Assets are served by the package itself (`/story-studio-assets/…`) and printed once per response. The script
registers Alpine components into the Alpine your app already runs (Livewire 3 ships one) and never boots a
second copy.

The package's own pages load their typefaces from Google Fonts; set `STORY_STUDIO_FONTS=false` to use system
fonts and send readers to no third party.

## Studio

`/studio/stories`, behind `web` + `auth`. Who may edit what is decided by one class:

```php
// config/story-studio.php
'authorizer' => App\Stories\TeamAuthorizer::class,

class TeamAuthorizer implements \Ombabush\StoryStudio\Contracts\StoryAuthorizer
{
    public function canUseStudio(?Authenticatable $user): bool { return $user?->can('write-stories') ?? false; }
    public function canManage(?Authenticatable $user, Story $story): bool { return $user && $story->team_id === $user->team_id; }
}
```

The default authorizer lets a user manage the editions they own, and every edition when the Gate ability
`story-studio.manage` is defined and passes. A host that defines no such ability gets owner-only editing.

## Configuration

Route prefixes and names, the table prefix (change it before the first migrate only), storage disk and sizes,
reader views, effects, interface languages and themes — all in `config/story-studio.php`, each commented.
A reader view is a combination of layout × fit × text placement; adding one is a config line and a label.

## Interactions

Regions and text links on pages (a character, a sign, a branch, a footnote) come from an edition's interaction
profile: closed data, no HTML/CSS/JS from authors. Strict schema: `resources/schema/interaction.schema.json`;
a small complete example: `resources/fixtures/tablet-story`. Off by default (`reader.interaction`).

```bash
php artisan story-studio:fixture vendor/ombabush/story-studio/resources/fixtures/tablet-story --publish
```

## Moving editions between hosts

`Support\Snapshot::export()` gives the public layer of an edition (ready, visible pages; no notes, drafts or
old versions) and `Actions\ApplySnapshot` mirrors it on another host under the same public ids. `.story.zip`
carries the same bytes plus pictures: `story-studio:fixture <story-id> --export=file.story.zip`.

## Tests

```bash
composer install && vendor/bin/phpunit        # the package in a bare Laravel 12 host (Orchestra Testbench)
node --test tests/js/geometry.test.mjs        # hit-testing geometry of the reader
```

## Assets

`resources/dist` is the source: hand-written, unminified, no build step. What you read is what runs.
