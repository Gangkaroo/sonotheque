<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('scan_runs', function (Blueprint $table): void {
            $table->jsonb('scan_paths')->nullable();
            $table->jsonb('missing_paths')->nullable();
        });

        Schema::table('library_watch_directories', function (Blueprint $table): void {
            $table->char('file_signature', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('library_watch_directories', function (Blueprint $table): void {
            $table->dropColumn('file_signature');
        });

        Schema::table('scan_runs', function (Blueprint $table): void {
            $table->dropColumn(['scan_paths', 'missing_paths']);
        });
    }
};
