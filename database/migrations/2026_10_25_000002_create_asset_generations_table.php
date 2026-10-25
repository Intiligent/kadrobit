<?php

use App\Enums\GenerationTrigger;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Every paid metadata generation (KB-38): the full answer, so a generated text can be restored
        // without paying again, and what it cost. Counts towards the user's monthly limit.
        Schema::create('asset_generations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Kept when the work is deleted: the generation was paid and still counts towards the limit.
            $table->foreignId('asset_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('trigger', array_column(GenerationTrigger::cases(), 'value'));

            // The answer: title, description, keywords, categories, looks_editorial, fixes.
            $table->string('model', 100); // provider:model, e.g. openai:gpt-6-luna
            $table->jsonb('result');

            // Billing.
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('cached_input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('reasoning_tokens')->default(0);
            $table->decimal('cost_usd', 10, 6)->nullable(); // null when the model has no price in config
            $table->unsignedInteger('duration_ms')->default(0);

            $table->timestampsTz();

            $table->index(['asset_id', 'created_at']);
            $table->index(['user_id', 'created_at']); // monthly limit
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_generations');
    }
};
