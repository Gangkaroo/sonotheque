<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('application_settings', function (Blueprint $table) {
            $table->boolean('metadata_backups_enabled')->default(false);
            $table->text('metadata_backup_path')->nullable();
            $table->unsignedSmallInteger('metadata_backup_retention_days')->default(30);
        });

        DB::table('application_settings')->update([
            'metadata_backups_enabled' => false,
            'metadata_backup_path' => storage_path('app/metadata-backups'),
            'metadata_backup_retention_days' => 30,
        ]);

        Schema::create('metadata_backups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('metadata_edit_job_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('metadata_edit_item_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('media_file_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('library_root_id')->nullable()->constrained()->nullOnDelete();
            $table->text('source_relative_path');
            $table->text('backup_root');
            $table->text('backup_relative_path');
            $table->string('checksum', 64);
            $table->unsignedBigInteger('file_size');
            $table->timestampTz('expires_at');
            $table->timestampTz('restored_at')->nullable();
            $table->timestampTz('deleted_at')->nullable();
            $table->timestampsTz();

            $table->index(['metadata_edit_job_id', 'created_at']);
            $table->index(['expires_at', 'deleted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metadata_backups');

        Schema::table('application_settings', function (Blueprint $table) {
            $table->dropColumn([
                'metadata_backups_enabled',
                'metadata_backup_path',
                'metadata_backup_retention_days',
            ]);
        });
    }
};
