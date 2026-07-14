<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('scan_runs', function (Blueprint $table): void {
            $table->text('subtree_path')->nullable()->after('trigger');
        });
    }

    public function down(): void
    {
        Schema::table('scan_runs', function (Blueprint $table): void {
            $table->dropColumn('subtree_path');
        });
    }
};
