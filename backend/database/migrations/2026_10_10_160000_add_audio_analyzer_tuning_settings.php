<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('application_settings', function (Blueprint $table): void {
            $table->unsignedSmallInteger('audio_intelligence_preparation_workers')->nullable();
            $table->unsignedSmallInteger('audio_intelligence_chunk_size')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('application_settings', function (Blueprint $table): void {
            $table->dropColumn(['audio_intelligence_preparation_workers', 'audio_intelligence_chunk_size']);
        });
    }
};
