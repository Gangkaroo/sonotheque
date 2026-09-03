<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('record_labels', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('normalized_name')->unique();
            $table->timestampsTz();
        });

        Schema::create('album_record_labels', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('album_id')->constrained()->cascadeOnDelete();
            $table->foreignId('record_label_id')->constrained()->cascadeOnDelete();
            $table->string('catalog_number', 128)->nullable();
            $table->char('catalog_number_hash', 64);
            $table->string('source', 32);
            $table->string('source_reference')->nullable();
            $table->timestampsTz();

            $table->unique(
                ['album_id', 'record_label_id', 'source', 'catalog_number_hash'],
                'album_record_labels_identity_unique',
            );
            $table->index(['album_id', 'source']);
            $table->index(['record_label_id', 'album_id']);
        });

        Schema::table('media_files', function (Blueprint $table): void {
            $table->unsignedSmallInteger('record_label_tags_import_version')->nullable();
            $table->index(
                ['library_root_id', 'record_label_tags_import_version', 'id'],
                'media_files_record_label_import_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('media_files', function (Blueprint $table): void {
            $table->dropIndex('media_files_record_label_import_index');
            $table->dropColumn('record_label_tags_import_version');
        });

        Schema::dropIfExists('album_record_labels');
        Schema::dropIfExists('record_labels');
    }
};
