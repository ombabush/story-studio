<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| On a receiving host (Cloud): the sha256 of the exact snapshot bytes last applied. An interaction
| profile is accepted only for that revision, so content and contours always belong to one version
| (docs/FORMAT.md, «revision»). Null on an authoring host.
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
            $table->string('snapshot_revision', 71)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table($this->t(), function (Blueprint $table) {
            $table->dropColumn('snapshot_revision');
        });
    }
};
