<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('album_musician_credits', function (Blueprint $table): void {
            $table->char('source_credit_key', 64)->nullable()->after('provider');
        });

        DB::table('album_musician_credits as credits')
            ->join('musicians', 'musicians.id', '=', 'credits.musician_id')
            ->select([
                'credits.id as id',
                'credits.provider',
                'credits.source_entity_type',
                'credits.source_entity_reference',
                'credits.relationship_type',
                'credits.role',
                'credits.credited_as',
                'credits.is_guest',
                'credits.is_additional',
                'musicians.provider as musician_provider',
                'musicians.provider_reference as musician_reference',
            ])
            ->orderBy('credits.id')
            ->chunkById(500, function ($credits): void {
                foreach ($credits as $credit) {
                    $sourceKey = hash('sha256', implode('|', [
                        $credit->provider,
                        $credit->musician_provider,
                        $credit->musician_reference,
                        $credit->source_entity_type,
                        $credit->source_entity_reference,
                        $credit->relationship_type,
                        $credit->role,
                        $credit->credited_as ?? '',
                        $credit->is_guest ? 'guest' : '',
                        $credit->is_additional ? 'additional' : '',
                    ]));

                    DB::table('album_musician_credits')
                        ->where('id', $credit->id)
                        ->update(['source_credit_key' => $sourceKey]);
                }
            }, 'credits.id', 'id');

        Schema::table('album_musician_credits', function (Blueprint $table): void {
            $table->char('source_credit_key', 64)->nullable(false)->change();
            $table->index(
                ['album_id', 'provider', 'source_credit_key'],
                'album_musician_credits_source_key_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('album_musician_credits', function (Blueprint $table): void {
            $table->dropIndex('album_musician_credits_source_key_index');
            $table->dropColumn('source_credit_key');
        });
    }
};
