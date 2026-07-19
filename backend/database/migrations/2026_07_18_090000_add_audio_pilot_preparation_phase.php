<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('audio_analysis_runs', function (Blueprint $table): void {
            $table->string('phase', 32)->default('analysis')->after('audio_analysis_profile_id');
            $table->index(['phase', 'status', 'created_at'], 'audio_analysis_run_phase_status_index');
        });

        Schema::table('audio_analysis_run_items', function (Blueprint $table): void {
            $table->char('content_fingerprint', 64)->nullable()->change();
            $table->unsignedSmallInteger('content_fingerprint_version')->nullable()->change();
        });

    }

    public function down(): void
    {
        DB::table('audio_analysis_runs')->where('phase', 'preparation')->delete();

        Schema::table('audio_analysis_run_items', function (Blueprint $table): void {
            $table->char('content_fingerprint', 64)->nullable(false)->change();
            $table->unsignedSmallInteger('content_fingerprint_version')->nullable(false)->change();
        });

        Schema::table('audio_analysis_runs', function (Blueprint $table): void {
            $table->dropIndex('audio_analysis_run_phase_status_index');
            $table->dropColumn('phase');
        });
    }
};
