<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('application_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('import_play_statistics_from_tags')->default(false);
            $table->boolean('export_play_statistics_to_tags')->default(false);
            $table->timestampsTz();
        });

        DB::table('application_settings')->insert([
            'id' => 1,
            'import_play_statistics_from_tags' => false,
            'export_play_statistics_to_tags' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('application_settings');
    }
};
