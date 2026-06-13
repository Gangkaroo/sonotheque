<?php

use App\Enums\MediaFileStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('library_root_id')->constrained()->cascadeOnDelete();
            $table->foreignId('album_id')->constrained()->cascadeOnDelete();
            $table->text('relative_path');
            $table->char('relative_path_hash', 64);
            $table->unsignedBigInteger('file_size');
            $table->timestampTz('modified_at');
            $table->string('mime_type', 128)->nullable();
            $table->string('container', 64)->nullable();
            $table->string('codec', 64)->nullable();
            $table->unsignedInteger('bitrate')->nullable();
            $table->unsignedInteger('sample_rate')->nullable();
            $table->unsignedSmallInteger('channels')->nullable();
            $table->string('status', 32)->default(MediaFileStatus::Available->value);
            $table->timestampTz('last_seen_at');
            $table->text('scan_error')->nullable();
            $table->jsonb('raw_metadata')->nullable();
            $table->timestampsTz();

            $table->unique(['library_root_id', 'relative_path_hash']);
            $table->index(['library_root_id', 'status']);
            $table->index(['album_id', 'status']);
            $table->index('modified_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_files');
    }
};
