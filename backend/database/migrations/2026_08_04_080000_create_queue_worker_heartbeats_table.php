<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('queue_worker_heartbeats', function (Blueprint $table): void {
            $table->string('queue', 64)->primary();
            $table->string('host', 255)->nullable();
            $table->unsignedBigInteger('process_id')->nullable();
            $table->timestampTz('last_seen_at');
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('queue_worker_heartbeats');
    }
};
