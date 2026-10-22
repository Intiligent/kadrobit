<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_verification_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('email'); // the address the code was sent to
            $table->char('code_hash', 64); // HMAC-SHA256 of the code with the app key, hex
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('expires_at')->index();
            $table->timestampTz('created_at')->useCurrent();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_verification_codes');
    }
};
