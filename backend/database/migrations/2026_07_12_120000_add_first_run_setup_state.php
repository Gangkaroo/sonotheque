<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('application_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('setup_step')->default(1);
            $table->boolean('setup_completed')->default(false);
        });

        if (DB::table('library_roots')->exists()) {
            DB::table('application_settings')->update([
                'setup_step' => 5,
                'setup_completed' => true,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('application_settings', function (Blueprint $table) {
            $table->dropColumn(['setup_step', 'setup_completed']);
        });
    }
};
