<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('audio_analyzer_benchmarks', function (Blueprint $table): void {
            $table->id();
            $table->string('status', 24)->default('queued')->index();
            $table->unsignedSmallInteger('sample_size')->default(15);
            $table->jsonb('sample_track_ids')->nullable();
            $table->jsonb('results')->default('[]');
            $table->jsonb('recommendation')->nullable();
            $table->unsignedSmallInteger('completed_configuration_count')->default(0);
            $table->unsignedSmallInteger('total_configuration_count')->default(6);
            $table->text('error')->nullable();
            $table->timestampTz('cancel_requested_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audio_analyzer_benchmarks');
    }
};
