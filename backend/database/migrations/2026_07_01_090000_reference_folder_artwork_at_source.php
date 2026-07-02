<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('albums', function (Blueprint $table): void {
            $table->string('artwork_source_type', 32)->nullable()->after('artwork_id');
            $table->text('artwork_source_relative_path')->nullable()->after('artwork_source_type');
        });

        DB::statement(<<<'SQL'
            UPDATE albums
            SET artwork_source_type = artwork.source_type,
                artwork_source_relative_path = artwork.source_relative_path
            FROM artwork
            WHERE albums.artwork_id = artwork.id
            SQL);

        Schema::table('artwork', function (Blueprint $table): void {
            $table->text('cache_path')->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('artwork')->whereNull('cache_path')->update(['cache_path' => '']);

        Schema::table('artwork', function (Blueprint $table): void {
            $table->text('cache_path')->nullable(false)->change();
        });

        Schema::table('albums', function (Blueprint $table): void {
            $table->dropColumn(['artwork_source_type', 'artwork_source_relative_path']);
        });
    }
};
