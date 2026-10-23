<?php

use App\Enums\AssetFileRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->enum('role', array_column(AssetFileRole::cases(), 'value'));
            $table->string('disk');
            $table->string('path');
            $table->string('mime');
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->char('checksum', 64)->nullable(); // SHA-256, hex; required for original files
            $table->timestampsTz();

            $table->unique(['asset_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_files');
    }
};
