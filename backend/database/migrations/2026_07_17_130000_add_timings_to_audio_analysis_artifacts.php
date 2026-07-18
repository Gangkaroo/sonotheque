<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('audio_analysis_artifacts', function (Blueprint $table): void {
            $table->jsonb('timings')->nullable()->after('windows_analyzed');
        });
    }

    public function down(): void
    {
        Schema::table('audio_analysis_artifacts', function (Blueprint $table): void {
            $table->dropColumn('timings');
        });
    }
};
