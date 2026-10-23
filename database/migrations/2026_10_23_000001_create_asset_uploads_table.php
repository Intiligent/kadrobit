<?php

use App\Enums\AssetUploadStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One uploaded file on its way to becoming a work (ProcessAssetUpload). The work itself
        // is created only when processing succeeds; until then, and on failure, only this row exists.
        Schema::create('asset_uploads', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('batch_id')->nullable(); // job_batches.id: the files sent together
            $table->foreignId('folder_id')->nullable()->constrained()->nullOnDelete(); // where the work goes

            // The work: its ulid is reserved up front so the file is stored once, at its final path.
            $table->ulid('asset_ulid')->unique();
            $table->foreignId('asset_id')->nullable()->constrained()->nullOnDelete();

            // The file as received.
            $table->string('filename');
            $table->string('mime');
            $table->unsignedBigInteger('size_bytes');
            $table->char('checksum', 64); // SHA-256, hex

            $table->enum('status', array_column(AssetUploadStatus::cases(), 'value'))
                ->default(AssetUploadStatus::Processing->value);
            $table->string('error')->nullable();

            $table->timestampsTz();

            // Batch polling: what changed since the last poll.
            $table->index(['batch_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_uploads');
    }
};
