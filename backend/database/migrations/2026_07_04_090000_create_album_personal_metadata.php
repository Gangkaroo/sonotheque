<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('album_personal_metadata', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('album_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('purchase_source')->nullable();
            $table->date('purchase_date')->nullable();
            $table->boolean('has_physical_copy')->default(false);
            $table->string('physical_format', 32)->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();

            $table->index('has_physical_copy');
            $table->index('physical_format');
            $table->index('purchase_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('album_personal_metadata');
    }
};
