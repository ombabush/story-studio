<?php

namespace Ombabush\StoryStudio\Tests\Feature;

use Ombabush\StoryStudio\Tests\TestCase;

/**
 * The behaviour suite writes its editions and expects its interface in Russian. It says so through the
 * package's own settings, not through the host's app.locale — the fixtures label in Russian, and a host
 * whose locale differs must not change what these tests mean.
 */
abstract class FeatureTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('story-studio.reader.original', 'ru');
        $app['config']->set('story-studio.reader.language', 'ru');
    }
}
