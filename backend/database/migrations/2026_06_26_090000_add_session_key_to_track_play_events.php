<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('track_play_events', function (Blueprint $table) {
            $table->string('session_key', 128)->nullable()->after('context')->unique();
        });
    }

    public function down(): void
    {
        Schema::table('track_play_events', function (Blueprint $table) {
            $table->dropUnique(['session_key']);
            $table->dropColumn('session_key');
        });
    }
};
