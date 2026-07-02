<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('metadata_edit_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('track_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_file_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 32)->default('pending');
            $table->string('fingerprint', 64);
            $table->jsonb('requested_changes');
            $table->jsonb('preview');
            $table->text('error')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();

            $table->index(['track_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metadata_edit_jobs');
    }
};
