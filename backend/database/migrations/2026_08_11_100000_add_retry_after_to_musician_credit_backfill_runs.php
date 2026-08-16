<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('musician_credit_backfill_runs', function (Blueprint $table): void {
            $table->timestampTz('retry_after')->nullable()->after('last_error');
        });
    }

    public function down(): void
    {
        Schema::table('musician_credit_backfill_runs', function (Blueprint $table): void {
            $table->dropColumn('retry_after');
        });
    }
};
