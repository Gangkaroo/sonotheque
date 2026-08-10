<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('playlist_order_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('playlist_id')->constrained()->cascadeOnDelete();
            $table->jsonb('item_ids');
            $table->string('source', 50);
            $table->timestampTz('restored_at')->nullable();
            $table->timestampsTz();

            $table->index(['playlist_id', 'restored_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('playlist_order_snapshots');
    }
};
