<?php

namespace Ombabush\StoryStudio\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Kalnoy\Nestedset\NestedSetServiceProvider;
use Livewire\LivewireServiceProvider;
use Ombabush\StoryStudio\Actions\StoryEditor;
use Ombabush\StoryStudio\Models\Node;
use Ombabush\StoryStudio\Models\Page;
use Ombabush\StoryStudio\Models\Story;
use Ombabush\StoryStudio\StoryStudioServiceProvider;
use Ombabush\StoryStudio\Support\ReadingOrder;
use Ombabush\StoryStudio\Tests\Host\User;
use Orchestra\Testbench\TestCase as Testbench;
use Spatie\MediaLibrary\MediaLibraryServiceProvider;

/**
 * A bare Laravel 12 host (PACKAGE-EXTRACTION PK-01): the package's declared dependencies, Laravel's
 * users table, a private disk and nothing else. What passes here passes for any host, not only OmBabush.
 */
abstract class TestCase extends Testbench
{
    use RefreshDatabase;

    protected string $tmp;

    protected function getPackageProviders($app): array
    {
        return [
            LivewireServiceProvider::class,
            MediaLibraryServiceProvider::class,
            NestedSetServiceProvider::class,
            StoryStudioServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('story-studio.user_model', User::class);
        $app['config']->set('media-library.disk_name', 'local');
    }

    protected function defineRoutes($router): void
    {
        // A host page embedding two readers, under 'web' like any real host page.
        $router->middleware('web')->get('/host/two-readers/{a}/{b}', fn (string $a, string $b) => \Illuminate\Support\Facades\Blade::render(
            '@storyStudioAssets <x-story-studio::reader :story="$a" /> <x-story-studio::reader :story="$b" /> @storyStudioAssets',
            ['a' => Story::where('public_id', $a)->firstOrFail(), 'b' => Story::where('public_id', $b)->firstOrFail()],
        ));
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Host/migrations');
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('story-studio.storage.disk'));
        $this->tmp = sys_get_temp_dir().'/story-studio-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->tmp);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    protected function editor(): StoryEditor
    {
        return app(StoryEditor::class);
    }

    protected function user(string $name = 'reader'): User
    {
        return User::forceCreate(['name' => $name, 'email' => $name.'-'.bin2hex(random_bytes(3)).'@example.test', 'password' => 'x']);
    }

    /**
     * A user the host trusts with every edition: the host defines the Gate ability the default
     * authorizer asks (config manage_ability). Defined on first use, so a test that never asks for an
     * admin runs in a host with no such ability at all.
     */
    protected function admin(): User
    {
        $ability = config('story-studio.manage_ability');
        if (! Gate::has($ability)) {
            Gate::define($ability, fn (User $u) => str_starts_with((string) $u->email, 'admin-'));
        }

        return $this->user('admin');
    }

    protected function fixturePath(string $name = 'tablet-story'): string
    {
        return dirname(__DIR__).'/resources/fixtures/'.$name;
    }

    /** A real PNG of the given size; $seed changes the colour, so the sha1 differs per file. */
    protected function png(string $name, int $w = 60, int $h = 90, int $seed = 0, ?string $dir = null): string
    {
        $dir ??= $this->tmp;
        File::ensureDirectoryExists(dirname($dir.'/'.$name));
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, $seed % 256, ($seed * 7) % 256, 90));
        imagepng($img, $dir.'/'.$name);
        imagedestroy($img);

        return $dir.'/'.$name;
    }

    /**
     * «Тест»: chapter A with pages a1 a2 a3, chapter B with b1 b2. All ready unless said otherwise.
     *
     * @return array{0: Story, 1: Node, 2: Node, 3: array<string, Page>}
     */
    protected function edition(bool $published = true, ?User $owner = null): array
    {
        $story = $this->editor()->createStory(['title' => 'Тест'], $owner);
        $a = $this->editor()->addNode($story, Node::CHAPTER, 'A');
        $b = $this->editor()->addNode($story, Node::CHAPTER, 'B');
        $pages = [];
        foreach (['a1', 'a2', 'a3'] as $i => $n) {
            $pages[$n] = $this->editor()->addImagePages($story, $a, [['file' => $this->png("$n.png", seed: $i + 1)]], null, Page::READY)->first();
        }
        foreach (['b1', 'b2'] as $i => $n) {
            $pages[$n] = $this->editor()->addImagePages($story, $b, [['file' => $this->png("$n.png", seed: $i + 10)]], null, Page::READY)->first();
        }
        if ($published) {
            $this->editor()->publish($story);
        }

        return [$story->fresh(), $a, $b, $pages];
    }

    /** Captions in reading order — the readable way to assert an order. */
    protected function order(Story $story): array
    {
        return app(ReadingOrder::class)->build($story)['pages']
            ->map(fn (Page $p) => $p->currentImage?->name ? pathinfo($p->currentImage->name, PATHINFO_FILENAME) : $p->caption)
            ->all();
    }

    protected function readerUrl(string $route, mixed $params): string
    {
        return route(config('story-studio.routes.reader.name').$route, $params);
    }
}
