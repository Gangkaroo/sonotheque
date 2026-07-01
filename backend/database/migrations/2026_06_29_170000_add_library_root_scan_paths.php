<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('library_roots', function (Blueprint $table) {
            $table->jsonb('cover_image_paths')->nullable();
            $table->jsonb('excluded_directories')->nullable();
        });

        DB::statement('UPDATE library_roots SET cover_image_paths = jsonb_build_array(cover_image_path)');
    }

    public function down(): void
    {
        Schema::table('library_roots', function (Blueprint $table) {
            $table->dropColumn(['cover_image_paths', 'excluded_directories']);
        });
    }
};
