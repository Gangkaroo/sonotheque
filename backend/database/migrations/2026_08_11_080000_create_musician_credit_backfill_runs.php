<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('musician_credit_backfill_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('library_root_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('lookup_version');
            $table->string('status', 32)->default('queued');
            $table->unsignedBigInteger('max_album_id')->nullable();
            $table->unsignedBigInteger('last_album_id')->nullable();
            $table->unsignedInteger('total_album_count')->default(0);
            $table->unsignedInteger('processed_album_count')->default(0);
            $table->unsignedInteger('ready_album_count')->default(0);
            $table->unsignedInteger('not_found_album_count')->default(0);
            $table->unsignedInteger('ambiguous_album_count')->default(0);
            $table->unsignedInteger('failed_album_count')->default(0);
            $table->unsignedBigInteger('processing_milliseconds')->default(0);
            $table->text('last_error')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampTz('pause_requested_at')->nullable();
            $table->timestampTz('cancel_requested_at')->nullable();
            $table->timestampTz('heartbeat_at')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'created_at'], 'musician_backfill_status_index');
            $table->index(
                ['library_root_id', 'created_at'],
                'musician_backfill_root_created_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('musician_credit_backfill_runs');
    }
};
