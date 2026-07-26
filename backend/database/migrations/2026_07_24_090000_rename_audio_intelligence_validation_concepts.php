<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('application_settings', function (Blueprint $table): void {
            $table->renameColumn(
                'audio_intelligence_sample_size',
                'audio_intelligence_validation_sample_size',
            );
        });

        DB::table('audio_analysis_runs')
            ->where('kind', 'pilot')
            ->update(['kind' => 'validation']);
        DB::statement(
            "ALTER TABLE audio_analysis_runs ALTER COLUMN kind SET DEFAULT 'validation'",
        );
    }

    public function down(): void
    {
        DB::statement(
            "ALTER TABLE audio_analysis_runs ALTER COLUMN kind SET DEFAULT 'pilot'",
        );
        DB::table('audio_analysis_runs')
            ->where('kind', 'validation')
            ->update(['kind' => 'pilot']);

        Schema::table('application_settings', function (Blueprint $table): void {
            $table->renameColumn(
                'audio_intelligence_validation_sample_size',
                'audio_intelligence_sample_size',
            );
        });
    }
};
