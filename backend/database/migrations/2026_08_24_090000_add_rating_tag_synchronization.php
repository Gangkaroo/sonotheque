<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('application_settings', function (Blueprint $table): void {
            $table->boolean('synchronize_ratings_with_tags')->default(false);
        });
        Schema::table('media_files', function (Blueprint $table): void {
            $table->unsignedSmallInteger('rating_tags_import_version')->nullable();
            $table->index(
                ['library_root_id', 'rating_tags_import_version', 'id'],
                'media_files_rating_tags_import_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('media_files', function (Blueprint $table): void {
            $table->dropIndex('media_files_rating_tags_import_index');
            $table->dropColumn('rating_tags_import_version');
        });
        Schema::table('application_settings', function (Blueprint $table): void {
            $table->dropColumn('synchronize_ratings_with_tags');
        });
    }
};
