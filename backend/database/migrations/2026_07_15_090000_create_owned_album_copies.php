<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('owned_album_copies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('album_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_physical')->default(true);
            $table->string('physical_format', 32)->nullable();
            $table->string('purchase_source')->nullable();
            $table->date('purchase_date')->nullable();
            $table->decimal('purchase_price_amount', 12, 2)->nullable();
            $table->char('purchase_price_currency', 3)->nullable();
            $table->string('media_condition', 32)->nullable();
            $table->string('sleeve_condition', 32)->nullable();
            $table->text('notes')->nullable();
            $table->string('provider', 32)->nullable();
            $table->bigInteger('external_release_id')->nullable();
            $table->bigInteger('external_master_id')->nullable();
            $table->bigInteger('external_collection_instance_id')->nullable();
            $table->bigInteger('external_folder_id')->nullable();
            $table->timestampTz('provider_synced_at')->nullable();
            $table->timestampsTz();

            $table->index(['album_id', 'is_physical']);
            $table->index('physical_format');
            $table->index('purchase_date');
            $table->index(['provider', 'external_release_id']);
            $table->unique(['provider', 'external_collection_instance_id']);
        });

        $now = now();
        $personalMetadata = DB::table('album_personal_metadata')
            ->where(function ($query): void {
                $query->where('has_physical_copy', true)
                    ->orWhereNotNull('purchase_source')
                    ->orWhereNotNull('purchase_date');
            })
            ->orderBy('id')
            ->get();

        foreach ($personalMetadata as $metadata) {
            DB::table('owned_album_copies')->insert([
                'album_id' => $metadata->album_id,
                'is_physical' => $metadata->has_physical_copy,
                'physical_format' => $metadata->has_physical_copy ? $metadata->physical_format : null,
                'purchase_source' => $metadata->purchase_source,
                'purchase_date' => $metadata->purchase_date,
                'created_at' => $metadata->created_at ?? $now,
                'updated_at' => $metadata->updated_at ?? $now,
            ]);
        }

        Schema::table('album_personal_metadata', function (Blueprint $table): void {
            $table->dropColumn([
                'purchase_source',
                'purchase_date',
                'has_physical_copy',
                'physical_format',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('album_personal_metadata', function (Blueprint $table): void {
            $table->string('purchase_source')->nullable();
            $table->date('purchase_date')->nullable();
            $table->boolean('has_physical_copy')->default(false);
            $table->string('physical_format', 32)->nullable();
        });

        $copiesByAlbum = DB::table('owned_album_copies')
            ->orderByDesc('is_physical')
            ->orderBy('id')
            ->get()
            ->groupBy('album_id');

        foreach ($copiesByAlbum as $albumId => $copies) {
            $firstCopy = $copies->first();
            $physicalCopy = $copies->firstWhere('is_physical', true);

            DB::table('album_personal_metadata')->updateOrInsert(
                ['album_id' => $albumId],
                [
                    'purchase_source' => $firstCopy->purchase_source,
                    'purchase_date' => $firstCopy->purchase_date,
                    'has_physical_copy' => $physicalCopy !== null,
                    'physical_format' => $physicalCopy?->physical_format,
                    'created_at' => $firstCopy->created_at,
                    'updated_at' => $firstCopy->updated_at,
                ],
            );
        }

        Schema::table('album_personal_metadata', function (Blueprint $table): void {
            $table->index('has_physical_copy');
            $table->index('physical_format');
            $table->index('purchase_date');
        });

        Schema::dropIfExists('owned_album_copies');
    }
};
