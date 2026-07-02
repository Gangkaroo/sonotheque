<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('metadata_edit_jobs', function (Blueprint $table) {
            $table->foreignId('album_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type', 32)->default('track');
            $table->unsignedInteger('total_items')->default(1);
            $table->unsignedInteger('processed_items')->default(0);
            $table->unsignedInteger('succeeded_items')->default(0);
            $table->unsignedInteger('failed_items')->default(0);
            $table->foreignId('track_id')->nullable()->change();
        });

        Schema::create('metadata_edit_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('metadata_edit_job_id')->constrained()->cascadeOnDelete();
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

            $table->unique(['metadata_edit_job_id', 'track_id']);
            $table->index(['status', 'created_at']);
        });

        DB::table('metadata_edit_jobs')->where('status', 'completed')->update([
            'processed_items' => 1,
            'succeeded_items' => 1,
        ]);
        DB::table('metadata_edit_jobs')->where('status', 'failed')->update([
            'processed_items' => 1,
            'failed_items' => 1,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('metadata_edit_items');
        DB::table('metadata_edit_jobs')->where('type', 'album')->delete();
        Schema::table('metadata_edit_jobs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('album_id');
            $table->dropColumn([
                'type',
                'total_items',
                'processed_items',
                'succeeded_items',
                'failed_items',
            ]);
            $table->foreignId('track_id')->nullable(false)->change();
        });
    }
};
