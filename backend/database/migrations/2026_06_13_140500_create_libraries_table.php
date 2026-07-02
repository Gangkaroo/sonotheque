<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('libraries', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestampsTz();
        });

        Schema::table('library_roots', function (Blueprint $table) {
            $table->foreignId('library_id')
                ->nullable()
                ->after('id')
                ->constrained()
                ->cascadeOnDelete();
        });

        if (DB::table('library_roots')->exists()) {
            $libraryId = DB::table('libraries')->insertGetId([
                'name' => 'Default Library',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('library_roots')->update(['library_id' => $libraryId]);
        }

        Schema::table('library_roots', function (Blueprint $table) {
            $table->foreignId('library_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('library_roots', function (Blueprint $table) {
            $table->dropConstrainedForeignId('library_id');
        });

        Schema::dropIfExists('libraries');
    }
};
