<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| The whole Story Studio schema. Order is a position inside the parent (volume → chapter → page), never
| a date or a file name; links and reading progress point at public_id, which survives every reorder.
| Files are not here: page pictures live in the host's Spatie MediaLibrary (`media` table).
*/
return new class extends Migration
{
    private function t(string $name): string
    {
        return config('story-studio.table_prefix', 'ss_').$name;
    }

    public function up(): void
    {
        Schema::create($this->t('stories'), function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->unsignedBigInteger('owner_id')->nullable()->index();
            $table->string('title');
            $table->string('authors')->nullable();
            $table->text('description')->nullable();
            $table->string('direction', 3)->default('ltr');
            $table->json('settings')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        Schema::create($this->t('nodes'), function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('story_id')->constrained($this->t('stories'))->cascadeOnDelete();
            // The table of contents is a kalnoy/nestedset tree scoped by story (as DictItem is by dict):
            // order and nesting live in _lft/_rgt, kept by the trait — never written by hand.
            $table->unsignedInteger('_lft')->default(0);
            $table->unsignedInteger('_rgt')->default(0);
            $table->unsignedBigInteger('parent_id')->nullable()->index();
            $table->string('kind', 16);
            $table->string('title');
            $table->timestamps();
            $table->index(['story_id', '_lft', '_rgt']);
        });

        Schema::create($this->t('pages'), function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('story_id')->constrained($this->t('stories'))->cascadeOnDelete();
            $table->foreignId('node_id')->nullable()->constrained($this->t('nodes'))->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->string('type', 16)->default('image');
            // Readiness and visibility are two things: a finished page can be hidden, a draft can be shown
            // to the author's preview. Readers see only ready + not hidden.
            $table->string('status', 16)->default('draft');
            $table->boolean('hidden')->default(false);
            // The picture is a version in the host's Spatie media table (collection 'versions' on the page);
            // this points at the current one. Replacing adds a version, nothing is overwritten.
            $table->unsignedBigInteger('current_media_id')->nullable();
            $table->string('caption', 500)->nullable();
            $table->text('body')->nullable();
            // Internal layer — never in a public response.
            $table->text('note')->nullable();
            $table->text('dialogue')->nullable();
            $table->string('alt', 1000)->nullable();
            $table->string('time_label')->nullable();
            $table->integer('time_rank')->nullable();
            $table->string('time_place')->nullable();
            $table->string('time_who')->nullable();
            $table->json('hints')->nullable();
            $table->json('regions')->nullable();
            $table->timestamps();
            $table->index(['story_id', 'node_id', 'position']);
        });

        Schema::create($this->t('progress'), function (Blueprint $table) {
            $table->id();
            $table->foreignId('story_id')->constrained($this->t('stories'))->cascadeOnDelete();
            // 'u:<user id>' or 'g:<guest token>' — one row per reader per story.
            $table->string('reader_key', 80);
            $table->foreignId('page_id')->nullable()->constrained($this->t('pages'))->nullOnDelete();
            $table->timestamps();
            $table->unique(['story_id', 'reader_key']);
        });
    }

    public function down(): void
    {
        foreach (['progress', 'pages', 'nodes', 'stories'] as $name) {
            Schema::dropIfExists($this->t($name));
        }
    }
};
