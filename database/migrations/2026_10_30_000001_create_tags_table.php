<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The user's own tags for organising the library. They are never sent to marketplaces.
        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 50);
            $table->timestampsTz();
        });

        // One tag per name and user, ignoring case: "Batumi" and "batumi" are the same tag.
        DB::statement('CREATE UNIQUE INDEX tags_user_name_unique ON tags (user_id, lower(name))');
    }

    public function down(): void
    {
        Schema::dropIfExists('tags');
    }
};
