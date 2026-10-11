<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| The universal Story Reader data model (docs/DATA-MODEL.md). Additive only:
|  - texts:       translations of any edition field (story, node, page), keyed by public id + language;
|                the original stays in its own column;
|  - attachments: typed items on the edition, a chapter or a page (music tracks, listen-elsewhere links);
|  - reader_prefs: a reader's own settings, general (story_id null) or for one edition. Never published.
*/
return new class extends Migration
{
    private function t(string $n): string
    {
        return config('story-studio.table_prefix', 'ss_').$n;
    }

    public function up(): void
    {
        Schema::create($this->t('texts'), function (Blueprint $table) {
            $table->id();
            $table->foreignId('story_id')->constrained($this->t('stories'))->cascadeOnDelete();
            $table->string('subject', 8);           // story | node | page
            $table->string('subject_id', 26);       // public_id of the subject
            $table->string('lang', 35);
            $table->json('fields');
            $table->timestamps();
            $table->unique(['story_id', 'subject', 'subject_id', 'lang']);
        });

        Schema::create($this->t('attachments'), function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('story_id')->constrained($this->t('stories'))->cascadeOnDelete();
            $table->string('scope', 8);             // story | node | page
            $table->string('scope_id', 26)->nullable();
            $table->string('kind', 16);             // track | link
            $table->unsignedInteger('position')->default(0);
            $table->json('data');
            $table->timestamps();
            $table->index(['story_id', 'scope', 'scope_id', 'position']);
        });

        Schema::create($this->t('reader_prefs'), function (Blueprint $table) {
            $table->id();
            $table->string('reader_key', 80);
            $table->foreignId('story_id')->nullable()->constrained($this->t('stories'))->cascadeOnDelete();
            $table->json('prefs');
            $table->timestamps();
            $table->unique(['reader_key', 'story_id']);
        });
    }

    public function down(): void
    {
        foreach (['reader_prefs', 'attachments', 'texts'] as $n) {
            Schema::dropIfExists($this->t($n));
        }
    }
};
