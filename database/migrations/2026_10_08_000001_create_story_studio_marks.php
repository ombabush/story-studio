<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| A reader's own bookmarks and notes (Story Reader v2 «◆ закладка», «✎ заметка»). Private reader state
| (CANON «Кто хранит состояние»): keyed by the same reader key as progress, never part of a snapshot,
| an export or another reader's view. One bookmark and one note per page per reader.
*/
return new class extends Migration
{
    private function t(string $n): string
    {
        return config('story-studio.table_prefix', 'ss_').$n;
    }

    public function up(): void
    {
        Schema::create($this->t('marks'), function (Blueprint $table) {
            $table->id();
            $table->foreignId('story_id')->constrained($this->t('stories'))->cascadeOnDelete();
            $table->foreignId('page_id')->constrained($this->t('pages'))->cascadeOnDelete();
            $table->string('reader_key', 80);
            $table->string('kind', 16);   // bookmark | note
            $table->text('text')->nullable();
            $table->timestamps();
            $table->unique(['story_id', 'reader_key', 'page_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->t('marks'));
    }
};
