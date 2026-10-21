<?php

use App\Enums\MarketplaceAccountStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('marketplace_id')->constrained()->restrictOnDelete();
            $table->string('contributor_id')->nullable();
            $table->string('label');
            $table->text('credentials')->nullable();
            $table->jsonb('settings')->default('{}');
            $table->enum('status', array_column(MarketplaceAccountStatus::cases(), 'value'))
                ->default(MarketplaceAccountStatus::Active->value);
            $table->timestampTz('last_verified_at')->nullable();
            $table->timestampsTz();

            $table->unique(['user_id', 'marketplace_id', 'label']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_accounts');
    }
};
