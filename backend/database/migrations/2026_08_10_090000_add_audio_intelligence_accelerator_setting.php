<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('application_settings', function (Blueprint $table): void {
            $table->string('audio_intelligence_accelerator', 16)
                ->default('cpu')
                ->after('audio_intelligence_validation_sample_size');
        });

        $configured = strtolower((string) config('sonotheque.audio_intelligence.accelerator', 'cpu'));
        DB::table('application_settings')->update([
            'audio_intelligence_accelerator' => in_array($configured, ['cpu', 'cuda'], true)
                ? $configured
                : 'cpu',
        ]);
    }

    public function down(): void
    {
        Schema::table('application_settings', function (Blueprint $table): void {
            $table->dropColumn('audio_intelligence_accelerator');
        });
    }
};
