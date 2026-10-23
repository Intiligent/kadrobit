<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The user's own folder tree in the library. A work sits in at most one folder.
        Schema::create('folders', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('folders')->cascadeOnDelete();
            $table->string('name', 100);
            $table->unsignedInteger('position')->default(0); // order among siblings
            $table->timestampsTz();

            $table->index(['user_id', 'parent_id']);
        });

        // Sibling folders have different names, ignoring case. Top-level folders (parent_id NULL) count as siblings too.
        DB::statement('CREATE UNIQUE INDEX folders_sibling_name_unique ON folders (user_id, COALESCE(parent_id, 0), lower(name))');
    }

    public function down(): void
    {
        Schema::dropIfExists('folders');
    }
};
