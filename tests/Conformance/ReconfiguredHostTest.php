<?php

namespace Ombabush\StoryStudio\Tests\Conformance;

use Illuminate\Support\Facades\Route;
use Ombabush\StoryStudio\Tests\TestCase;

/**
 * A host that moves the Reader, renames its routes and has no Studio at all (how Cloud runs it).
 * Nothing in the package may still point at the default addresses.
 */
class ReconfiguredHostTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('story-studio.routes.reader.prefix', 'comics');
        $app['config']->set('story-studio.routes.reader.name', 'comics.');
        $app['config']->set('story-studio.routes.studio.enabled', false);
        $app['config']->set('story-studio.routes.assets.prefix', 'vendor-assets/ss');
    }

    public function test_the_reader_lives_where_the_host_put_it_and_links_only_there(): void
    {
        [$story, , , $pages] = $this->edition();

        $this->assertFalse(Route::has('story-studio.studio.index'));
        $this->assertFalse(Route::has('story-studio.reader.show'));
        $this->get('/stories/'.$story->public_id)->assertNotFound();

        $html = $this->get('/comics/'.$story->public_id)->assertOk()->getContent();
        $this->assertStringContainsString('/vendor-assets/ss/story-studio.js', $html);
        $this->assertStringContainsString('\/comics\/'.$story->public_id.'\/pages', $html);
        $this->assertStringNotContainsString('\/stories\/', $html);
        $this->assertStringNotContainsString('studio\/stories', $html);

        $this->get('/comics/'.$story->public_id.'/media/'.$pages['a1']->currentImage->uuid.'/original')->assertOk();
    }
}
