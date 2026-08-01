<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('album_musician_enrichments', function (Blueprint $table): void {
            $table->jsonb('candidate_releases')->nullable();
            $table->string('selected_release_id', 128)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('album_musician_enrichments', function (Blueprint $table): void {
            $table->dropColumn(['candidate_releases', 'selected_release_id']);
        });
    }
};
