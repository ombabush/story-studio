<?php

namespace Ombabush\StoryStudio\Http\Controllers;

use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** The package ships its built CSS/JS; they are served from here so a host has nothing to publish. */
class AssetController
{
    public const FILES = ['story-studio.css' => 'text/css', 'story-studio.js' => 'application/javascript'];

    public function __invoke(string $file): BinaryFileResponse
    {
        abort_unless(isset(self::FILES[$file]), 404);
        $path = dirname(__DIR__, 3).'/resources/dist/'.$file;

        return response()->file($path, [
            'Content-Type' => self::FILES[$file].'; charset=utf-8',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }

    public static function url(string $file): string
    {
        $path = dirname(__DIR__, 3).'/resources/dist/'.$file;

        return route('story-studio.asset', ['file' => $file, 'v' => is_file($path) ? substr(md5_file($path), 0, 10) : '0']);
    }
}
