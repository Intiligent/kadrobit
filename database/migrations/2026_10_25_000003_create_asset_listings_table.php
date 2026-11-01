<?php

use App\Enums\ListingStatus;
use App\Enums\ModerationStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A work sent (or on its way) to one marketplace account (KB-30, docs/publishing.md).
        Schema::create('asset_listings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->foreignId('marketplace_account_id')->constrained()->cascadeOnDelete();
            // The work's id on the marketplace, in the marketplace's own format; null until it is known.
            $table->string('external_id', 64)->nullable();
            // The work's page on the marketplace, as the browser extension found it.
            $table->string('external_url', 512)->nullable();
            $table->enum('status', array_column(ListingStatus::cases(), 'value'))
                ->default(ListingStatus::Queued->value);
            // Review on the marketplace, read by the browser extension: null while unknown.
            $table->enum('moderation_status', array_column(ModerationStatus::cases(), 'value'))->nullable();

            // Marketplace checks that did not pass when the work was queued: they do not stop the upload.
            // [{"key": "keywords_min", "params": {"min": 7, "count": 5}}], keys under library.checks.
            $table->jsonb('warnings')->default('[]');

            // Delivery.
            $table->string('remote_filename')->nullable(); // name of the file on the marketplace's server
            // Metadata as sent: a work edited later is shown as changed after sending.
            $table->jsonb('metadata')->nullable();
            // Reason of the last failure: auth | connect | other | account | unsupported | file.
            $table->string('error', 32)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('sent_at')->nullable();
            // When the extension last read the review status on the marketplace's site.
            $table->timestampTz('moderation_checked_at')->nullable();

            $table->timestampsTz();

            // One listing per work and account: a new batch updates it instead of adding another.
            $table->unique(['asset_id', 'marketplace_account_id']);
            $table->index(['marketplace_account_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_listings');
    }
};
