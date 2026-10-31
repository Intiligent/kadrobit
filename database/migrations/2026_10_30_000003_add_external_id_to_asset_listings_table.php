<?php

use App\Enums\ListingStatus;
use App\Support\Database\TableRebuilder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds external_id after marketplace_account_id to a table that already holds data: PostgreSQL would append it
     * at the end, so the table is rebuilt (TableRebuilder). A database created after this change gets the column from
     * the create migration, and nothing is done here.
     */
    public function up(): void
    {
        if (Schema::hasColumn('asset_listings', 'external_id')) {
            return;
        }

        // The whole table as in 2026_10_25_000003_create_asset_listings_table.php.
        app(TableRebuilder::class)->rebuild('asset_listings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->foreignId('marketplace_account_id')->constrained()->cascadeOnDelete();
            $table->string('external_id', 64)->nullable();
            $table->enum('status', array_column(ListingStatus::cases(), 'value'))
                ->default(ListingStatus::Queued->value);

            $table->jsonb('warnings')->default('[]');

            $table->string('remote_filename')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->string('error', 32)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('sent_at')->nullable();

            $table->timestampsTz();

            $table->unique(['asset_id', 'marketplace_account_id']);
            $table->index(['marketplace_account_id', 'status']);
        });
    }

    /**
     * Nothing: the column now belongs to the create migration, which drops it along with the table.
     */
    public function down(): void {}
};
