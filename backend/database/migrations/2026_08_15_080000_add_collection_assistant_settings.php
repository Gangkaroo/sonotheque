<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('application_settings', function (Blueprint $table): void {
            $table->boolean('collection_assistant_enabled')
                ->default(false)
                ->after('audio_similarity_personalization_enabled');
            $table->string('collection_assistant_model')
                ->nullable()
                ->after('collection_assistant_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('application_settings', function (Blueprint $table): void {
            $table->dropColumn([
                'collection_assistant_enabled',
                'collection_assistant_model',
            ]);
        });
    }
};
