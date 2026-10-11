<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Story Reader interaction profile (docs/FORMAT.md): one validated profile per edition, its page
| ids rewritten to this host's public ids, plus the picture each page's contours were drawn on. A page
| whose current picture no longer matches turns its overlay off instead of outlining the wrong art.
| Additive and nullable: an edition without a profile reads exactly as before.
*/
return new class extends Migration
{
    private function t(): string
    {
        return config('story-studio.table_prefix', 'ss_').'stories';
    }

    public function up(): void
    {
        Schema::table($this->t(), function (Blueprint $table) {
            $table->json('interaction')->nullable();
            $table->json('interaction_surfaces')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table($this->t(), function (Blueprint $table) {
            $table->dropColumn(['interaction', 'interaction_surfaces']);
        });
    }
};
