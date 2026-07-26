<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('audio_analysis_runs', function (Blueprint $table): void {
            $table->string('kind', 32)->default('pilot')->after('phase');
            $table->foreignId('library_root_id')
                ->nullable()
                ->after('audio_analysis_profile_id')
                ->constrained()
                ->nullOnDelete();
            $table->timestampTz('pause_requested_at')
                ->nullable()
                ->after('cancel_requested_at');
            $table->unsignedInteger('requested_track_count')->change();
            $table->unsignedInteger('selected_track_count')->change();
            $table->index(
                ['kind', 'library_root_id', 'status'],
                'audio_analysis_run_scope_status_index',
            );
        });

        Schema::table('audio_analysis_run_items', function (Blueprint $table): void {
            $table->unsignedInteger('position')->change();
        });
    }

    public function down(): void
    {
        Schema::table('audio_analysis_run_items', function (Blueprint $table): void {
            $table->unsignedSmallInteger('position')->change();
        });

        Schema::table('audio_analysis_runs', function (Blueprint $table): void {
            $table->dropIndex('audio_analysis_run_scope_status_index');
            $table->dropConstrainedForeignId('library_root_id');
            $table->dropColumn(['kind', 'pause_requested_at']);
            $table->unsignedSmallInteger('requested_track_count')->change();
            $table->unsignedSmallInteger('selected_track_count')->change();
        });
    }
};
