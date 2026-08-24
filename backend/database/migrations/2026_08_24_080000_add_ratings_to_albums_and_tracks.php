<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('albums', function (Blueprint $table) {
            $table->unsignedTinyInteger('rating_half_steps')->nullable()->after('disc_total');
        });
        Schema::table('tracks', function (Blueprint $table) {
            $table->unsignedTinyInteger('rating_half_steps')->nullable()->after('year');
        });

        DB::statement(
            'ALTER TABLE albums ADD CONSTRAINT albums_rating_half_steps_check '
            .'CHECK (rating_half_steps BETWEEN 1 AND 10)',
        );
        DB::statement(
            'ALTER TABLE tracks ADD CONSTRAINT tracks_rating_half_steps_check '
            .'CHECK (rating_half_steps BETWEEN 1 AND 10)',
        );
    }

    public function down(): void
    {
        Schema::table('tracks', function (Blueprint $table) {
            $table->dropColumn('rating_half_steps');
        });
        Schema::table('albums', function (Blueprint $table) {
            $table->dropColumn('rating_half_steps');
        });
    }
};
