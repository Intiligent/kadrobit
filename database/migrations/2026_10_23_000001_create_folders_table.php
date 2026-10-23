<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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

        Schema::table('assets', function (Blueprint $table) {
            // Deleting a folder leaves its works in the library, unsorted.
            $table->foreignId('folder_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('folder_id');
        });

        Schema::dropIfExists('folders');
    }
};
