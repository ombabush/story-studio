<?php

use Illuminate\Support\Facades\Route;
use Ombabush\StoryStudio\Http\Controllers\AssetController;
use Ombabush\StoryStudio\Http\Controllers\ReaderController;
use Ombabush\StoryStudio\Livewire\Studio\Editor;
use Ombabush\StoryStudio\Livewire\Studio\Index;

$c = config('story-studio.routes');

Route::get(trim($c['assets']['prefix'], '/').'/{file}', AssetController::class)
    ->where('file', '[a-z\-]+\.(css|js)')->name('story-studio.asset');

if ($c['reader']['enabled']) {
    Route::prefix($c['reader']['prefix'])->middleware($c['reader']['middleware'])->name($c['reader']['name'])->group(function () {
        Route::get('{story}', [ReaderController::class, 'show'])->name('show');
        Route::get('{story}/pages', [ReaderController::class, 'pages'])->name('pages');
        Route::post('{story}/progress', [ReaderController::class, 'progress'])->name('progress');
        Route::post('{story}/marks', [ReaderController::class, 'mark'])->name('marks');
        Route::post('{story}/prefs', [ReaderController::class, 'prefs'])->name('prefs');
        Route::get('{story}/media/{image}/{size?}', [ReaderController::class, 'media'])
            ->where('size', 'display|thumb|original')->name('media');
    });
}

if ($c['studio']['enabled']) {
    Route::prefix($c['studio']['prefix'])->middleware($c['studio']['middleware'])->name($c['studio']['name'])->group(function () {
        Route::get('/', Index::class)->name('index');
        Route::get('{story}', Editor::class)->name('edit');
    });
}
