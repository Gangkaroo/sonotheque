<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('online_content_cache', function (Blueprint $table) {
            $table->unsignedInteger('failure_count')->default(0);
            $table->string('last_error_code', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('online_content_cache', function (Blueprint $table) {
            $table->dropColumn(['failure_count', 'last_error_code']);
        });
    }
};
