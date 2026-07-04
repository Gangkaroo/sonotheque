<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('application_settings', function (Blueprint $table) {
            $table->boolean('online_information_enabled')->default(false);
            $table->boolean('online_lyrics_enabled')->default(false);
        });

        Schema::create('online_content_cache', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 64);
            $table->string('resource_type', 32);
            $table->char('lookup_hash', 64);
            $table->jsonb('lookup');
            $table->string('status', 32);
            $table->jsonb('payload')->nullable();
            $table->string('provider_reference')->nullable();
            $table->text('source_url')->nullable();
            $table->timestampTz('fetched_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('stale_until')->nullable();
            $table->timestampTz('retry_after')->nullable();
            $table->timestampsTz();

            $table->unique(
                ['provider', 'resource_type', 'lookup_hash'],
                'online_cache_provider_resource_lookup_unique',
            );
            $table->index(['resource_type', 'expires_at'], 'online_cache_resource_expiry_index');
            $table->index(['status', 'retry_after'], 'online_cache_status_retry_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('online_content_cache');

        Schema::table('application_settings', function (Blueprint $table) {
            $table->dropColumn([
                'online_information_enabled',
                'online_lyrics_enabled',
            ]);
        });
    }
};
