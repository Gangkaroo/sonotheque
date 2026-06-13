<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE UNIQUE INDEX genres_name_ci_unique ON genres (lower(name))');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS genres_name_ci_unique');
    }
};
