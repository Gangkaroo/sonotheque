<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('library_roots', function (Blueprint $table) {
            $table->boolean('watch_enabled')->default(false)->index();
            $table->unsignedSmallInteger('watch_poll_interval_minutes')->default(5);
            $table->unsignedInteger('watch_reconcile_interval_minutes')->default(1440);
            $table->string('watch_status', 32)->default('disabled');
            $table->timestampTz('watch_checked_at')->nullable();
            $table->timestampTz('watch_last_event_at')->nullable();
            $table->timestampTz('watch_last_scan_at')->nullable();
            $table->text('watch_last_path')->nullable();
            $table->text('watch_error')->nullable();
        });

        Schema::create('library_watch_directories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('library_root_id')->constrained()->cascadeOnDelete();
            $table->text('relative_path');
            $table->char('relative_path_hash', 64);
            $table->char('signature', 64);
            $table->char('artwork_signature', 64);
            $table->timestampsTz();

            $table->unique(
                ['library_root_id', 'relative_path_hash'],
                'library_watch_directories_root_path_unique',
            );
        });

        Schema::create('library_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('library_root_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('scan_run_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source', 32);
            $table->string('severity', 16);
            $table->string('code', 64);
            $table->text('message');
            $table->text('path')->nullable();
            $table->unsignedInteger('occurrence_count')->default(1);
            $table->jsonb('context')->nullable();
            $table->timestampsTz();

            $table->index(['created_at', 'id']);
            $table->index(
                ['library_root_id', 'created_at'],
                'library_activity_logs_root_created_index',
            );
            $table->index(['severity', 'created_at']);
            $table->index(['source', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('library_activity_logs');
        Schema::dropIfExists('library_watch_directories');

        Schema::table('library_roots', function (Blueprint $table) {
            $table->dropColumn([
                'watch_enabled',
                'watch_poll_interval_minutes',
                'watch_reconcile_interval_minutes',
                'watch_status',
                'watch_checked_at',
                'watch_last_event_at',
                'watch_last_scan_at',
                'watch_last_path',
                'watch_error',
            ]);
        });
    }
};
