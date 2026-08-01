<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('musicians', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 64);
            $table->string('provider_reference', 128);
            $table->string('name');
            $table->string('sort_name')->nullable();
            $table->string('disambiguation')->nullable();
            $table->string('entity_type', 64)->nullable();
            $table->timestampsTz();

            $table->unique(['provider', 'provider_reference'], 'musicians_provider_reference_unique');
            $table->index('name', 'musicians_name_index');
        });

        Schema::create('album_musician_enrichments', function (Blueprint $table) {
            $table->foreignId('album_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('provider', 64);
            $table->unsignedSmallInteger('lookup_version');
            $table->string('status', 32);
            $table->string('provider_release_id', 128)->nullable();
            $table->text('source_url')->nullable();
            $table->timestampTz('fetched_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('retry_after')->nullable();
            $table->unsignedInteger('failure_count')->default(0);
            $table->string('last_error_code', 64)->nullable();
            $table->timestampsTz();

            $table->index(['status', 'retry_after'], 'album_musician_enrichment_retry_index');
            $table->index(['lookup_version', 'status'], 'album_musician_enrichment_version_index');
        });

        Schema::create('album_musician_credits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('album_id')->constrained()->cascadeOnDelete();
            $table->foreignId('track_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('musician_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 64);
            $table->string('source_entity_type', 32);
            $table->string('source_entity_reference', 128);
            $table->string('relationship_type', 128);
            $table->string('role', 255);
            $table->string('credited_as')->nullable();
            $table->jsonb('attributes');
            $table->boolean('is_guest')->default(false);
            $table->boolean('is_additional')->default(false);
            $table->timestampsTz();

            $table->index(['album_id', 'musician_id'], 'album_musician_credits_album_musician_index');
            $table->index(['musician_id', 'album_id'], 'album_musician_credits_musician_album_index');
            $table->index('track_id', 'album_musician_credits_track_index');
            $table->index('role', 'album_musician_credits_role_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('album_musician_credits');
        Schema::dropIfExists('album_musician_enrichments');
        Schema::dropIfExists('musicians');
    }
};
