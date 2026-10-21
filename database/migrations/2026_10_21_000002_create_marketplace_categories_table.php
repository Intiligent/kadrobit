<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('marketplace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('marketplace_categories')->nullOnDelete();
            $table->string('external_id');
            $table->string('name');
            $table->jsonb('media_types')->default('[]');
            $table->timestampsTz();

            $table->unique(['marketplace_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_categories');
    }
};
