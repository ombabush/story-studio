<?php

return [

    /*
    | Every table the package owns starts with this. Change it before the first migrate, never after.
    */
    'table_prefix' => 'ss_',

    /*
    | The user model the host app authenticates with. Only its key is stored (owner_id, progress).
    */
    'user_model' => env('STORY_STUDIO_USER_MODEL', 'App\\Models\\User'),

    /*
    | Who may manage which story. The default authorizer lets a user manage stories they own, and
    | every story when the Gate ability below is defined and passes. Bind your own class implementing
    | Contracts\StoryAuthorizer for anything else (teams, roles, a project's members).
    */
    'authorizer' => Ombabush\StoryStudio\Support\DefaultAuthorizer::class,
    'manage_ability' => 'story-studio.manage',

    'routes' => [
        // The public Reader. Middleware 'web' is required (sessions for progress and preview).
        'reader' => [
            'enabled' => true,
            'prefix' => 'stories',
            'middleware' => ['web'],
            'name' => 'story-studio.reader.',
        ],
        // The Studio editor. Must sit behind authentication; the authorizer is checked on top.
        'studio' => [
            'enabled' => true,
            'prefix' => 'studio/stories',
            'middleware' => ['web', 'auth'],
            'name' => 'story-studio.studio.',
        ],
        // Package assets (CSS/JS) served from the package itself — nothing to publish or build.
        'assets' => [
            'prefix' => 'story-studio-assets',
        ],
    ],

    'storage' => [
        // Pictures are the host's Spatie MediaLibrary media (collection 'versions' on each page), kept on
        // this disk. Never a public one: every image goes out through the media route, which checks
        // access on each request.
        'disk' => env('STORY_STUDIO_DISK', 'local'),
        'max_upload_mb' => 40,
        // Longest side of the 'display' and 'thumb' conversions; originals are kept untouched.
        'display_max' => 2560,
        'thumb_max' => 480,
    ],

    'reader' => [
        // Views (docs/DATA-MODEL.md §3): a view is a layout × fit × text placement. The
        // five layouts are drawn by the package (grid, page, pair, scroll, story); a host adds a view by
        // combining them here and naming it in lang/*/reader.php `views.<name>`. `key` is the hotkey,
        // `editor` marks a view only an editor may open (it shows the internal layer). An edition
        // narrows the reader views further in its own settings.
        'views' => [
            'grid' => ['key' => '1', 'layout' => 'grid', 'fit' => 'page', 'text' => 'none', 'cells' => 4],
            'read' => ['key' => '2', 'layout' => 'page', 'fit' => 'page', 'text' => 'below'],
            'cinema' => ['key' => '3', 'layout' => 'page', 'fit' => 'width', 'text' => 'subtitle'],
            'book' => ['key' => '4', 'layout' => 'pair', 'fit' => 'page', 'text' => 'beside'],
            'script' => ['key' => '5', 'layout' => 'page', 'fit' => 'page', 'text' => 'script', 'editor' => true],
            'scroll' => ['key' => '6', 'layout' => 'scroll', 'fit' => 'width', 'text' => 'below'],
            'story' => ['key' => '7', 'layout' => 'story', 'fit' => 'page', 'text' => 'story'],
        ],
        // Highlight effects this renderer draws; an author's other wishes fall back to the outline.
        'effects' => ['outline', 'brackets', 'ink', 'quiet'],
        // Interface languages: each needs resources/lang/<code>/reader.php (the package ships ru and en).
        // `language` is the interface default until a reader picks one; `original` is the language a
        // new edition is written in until its settings say otherwise. Null = the app locale.
        'languages' => ['ru', 'en'],
        'language' => null,
        'original' => null,
        'themes' => ['dark', 'light', 'paper'],
        // Kinds of a reader's own marks.
        'marks' => ['bookmark', 'note'],
        // Story Reader interactions (docs/FORMAT.md): regions and text links on pages, from an
        // edition's interaction profile. Off by default while SR-01 is proven on real devices.
        'interaction' => false,
        // Pages sent with the first response; the rest arrive in chunks around the reading position.
        'initial_window' => 6,
        'chunk' => 12,
        'preload_ahead' => 3,
    ],

    // The package's own pages load IBM Plex and Literata from Google Fonts. False = system fonts and no
    // request to a third party when a reader opens a page (the CSS falls back by itself).
    'fonts' => env('STORY_STUDIO_FONTS', true),

    // Looks: everything is a CSS variable on .ss-root, so a host can restyle without touching views.
    'theme' => [
        'default' => 'dark',
        'accent' => '#fbbf24',
    ],

    // A host view rendered in the edition header with $story — e.g. where else the edition is
    // published and whether that copy is current. Null renders nothing; the package knows no hosts.
    'studio_header_view' => null,

    // Where the Studio's "back" crumb points (the host app's admin, a dashboard…). Null hides it.
    'host_home' => null,
    'host_label' => null,
];
