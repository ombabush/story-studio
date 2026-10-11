<?php

namespace Ombabush\StoryStudio;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Ombabush\StoryStudio\Contracts\StoryAuthorizer;
use Ombabush\StoryStudio\Http\Controllers\AssetController;
use Ombabush\StoryStudio\Livewire\Studio\Editor;
use Ombabush\StoryStudio\Livewire\Studio\Index;
use Ombabush\StoryStudio\Livewire\Studio\Interactions;

class StoryStudioServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/story-studio.php', 'story-studio');
        $this->app->bind(StoryAuthorizer::class, fn ($app) => $app->make(config('story-studio.authorizer')));
        // One per request or job: Texts memoises an edition's translations while a payload is built.
        $this->app->scoped(Support\Texts::class);
        $this->app->singleton(Support\ReaderCatalog::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/story-studio.php');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'story-studio');
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'story-studio');

        Blade::componentNamespace('Ombabush\\StoryStudio\\View', 'story-studio');

        // One <link> + one <script> per response however many readers the page embeds. The script
        // registers Alpine components and never boots an Alpine of its own.
        Blade::directive('storyStudioAssets', fn () => '<?php echo \\Ombabush\\StoryStudio\\StoryStudioServiceProvider::assets(); ?>');

        Livewire::component('story-studio.studio.index', Index::class);
        Livewire::component('story-studio.studio.editor', Editor::class);
        Livewire::component('story-studio.studio.interactions', Interactions::class);
        Livewire::component('story-studio.studio.translations', \Ombabush\StoryStudio\Livewire\Studio\Translations::class);
        Livewire::component('story-studio.studio.attachments', \Ombabush\StoryStudio\Livewire\Studio\AttachmentList::class);

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/story-studio.php' => config_path('story-studio.php')], 'story-studio-config');
            $this->publishes([__DIR__.'/../resources/views' => resource_path('views/vendor/story-studio')], 'story-studio-views');
            $this->commands([Console\ImportCommand::class, Console\DemoCommand::class, Console\FixtureCommand::class]);
        }
    }

    public static function assets(): string
    {
        // Per request, not per process: a long-lived worker must print them again on the next page.
        $attributes = request()->attributes;
        if ($attributes->get('story-studio.assets')) {
            return '';
        }
        $attributes->set('story-studio.assets', true);

        return '<link rel="stylesheet" href="'.e(AssetController::url('story-studio.css')).'">'
            .'<script src="'.e(AssetController::url('story-studio.js')).'"></script>';
    }
}
