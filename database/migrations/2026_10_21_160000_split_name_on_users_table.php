<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('username', 32)->nullable()->unique();
            $table->string('avatar')->nullable();
        });

        // Existing rows get a placeholder handle; users can change it later.
        DB::table('users')->update([
            'first_name' => DB::raw('name'),
            'username' => DB::raw("'user' || id"),
        ]);

        Schema::table('users', function (Blueprint $table) {
            $table->string('first_name')->nullable(false)->change();
            $table->string('username', 32)->nullable(false)->change();
            $table->dropColumn('name');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('name')->nullable();
        });

        DB::table('users')->update([
            'name' => DB::raw("trim(first_name || ' ' || coalesce(last_name, ''))"),
        ]);

        Schema::table('users', function (Blueprint $table) {
            $table->string('name')->nullable(false)->change();
            $table->dropUnique(['username']);
            $table->dropColumn(['first_name', 'last_name', 'username', 'avatar']);
        });
    }
};
