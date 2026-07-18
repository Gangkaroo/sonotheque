<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('media_files', function (Blueprint $table): void {
            $table->char('content_fingerprint', 64)->nullable();
            $table->unsignedSmallInteger('content_fingerprint_version')->nullable();
            $table->index(
                ['library_root_id', 'content_fingerprint'],
                'media_files_root_content_fingerprint_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('media_files', function (Blueprint $table): void {
            $table->dropIndex('media_files_root_content_fingerprint_idx');
            $table->dropColumn(['content_fingerprint', 'content_fingerprint_version']);
        });
    }
};
