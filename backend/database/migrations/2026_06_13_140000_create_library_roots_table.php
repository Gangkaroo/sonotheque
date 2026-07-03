<?php

use App\Enums\ScanStatus;
use App\Enums\ScanTrigger;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('library_roots', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('path');
            $table->char('path_hash', 64)->unique();
            $table->string('cover_image_path', 1024)->default('cover.jpg');
            $table->boolean('enabled')->default(true)->index();
            $table->jsonb('include_patterns')->nullable();
            $table->jsonb('exclude_patterns')->nullable();
            $table->timestampTz('last_scanned_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('scan_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('library_root_id')->constrained()->cascadeOnDelete();
            $table->string('status', 32)->default(ScanStatus::Pending->value);
            $table->string('trigger', 32)->default(ScanTrigger::Manual->value);
            $table->unsignedBigInteger('files_discovered')->default(0);
            $table->unsignedBigInteger('files_processed')->default(0);
            $table->unsignedBigInteger('files_added')->default(0);
            $table->unsignedBigInteger('files_updated')->default(0);
            $table->unsignedBigInteger('files_missing')->default(0);
            $table->unsignedInteger('warning_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampTz('cancel_requested_at')->nullable();
            $table->jsonb('summary')->nullable();
            $table->timestampsTz();

            $table->index(['library_root_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scan_runs');
        Schema::dropIfExists('library_roots');
    }
};
