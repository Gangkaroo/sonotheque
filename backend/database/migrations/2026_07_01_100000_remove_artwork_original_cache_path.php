<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('artwork', function (Blueprint $table): void {
            $table->dropColumn('cache_path');
        });
    }

    public function down(): void
    {
        Schema::table('artwork', function (Blueprint $table): void {
            $table->text('cache_path')->nullable();
        });
    }
};
