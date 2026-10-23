<?php

use App\Enums\AssetStatus;
use App\Enums\MediaType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Deleting a folder leaves its works in the library, unsorted.
            $table->foreignId('folder_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('type', array_column(MediaType::cases(), 'value'));
            $table->enum('status', array_column(AssetStatus::cases(), 'value'))
                ->default(AssetStatus::Draft->value);

            // Base metadata, shared by all marketplaces (can be overridden per listing).
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->jsonb('keywords')->default('[]'); // ordered list of strings
            $table->boolean('is_editorial')->default(false);
            $table->boolean('is_ai_generated')->default(false);

            // Data from the original file.
            $table->timestampTz('captured_at')->nullable(); // EXIF DateTimeOriginal / video creation time
            $table->jsonb('location')->nullable(); // country, city, coordinates
            $table->string('original_filename');
            $table->char('checksum', 64); // SHA-256 of the original file, hex
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('duration_ms')->nullable(); // video only
            $table->jsonb('media_info')->default('{}'); // EXIF, ffprobe output, color profile

            // Full-text search over title (A), keywords (B) and description (C), maintained by PostgreSQL.
            $table->rawColumn('search', <<<'SQL'
                tsvector GENERATED ALWAYS AS (
                    setweight(to_tsvector('english', coalesce(title, '')), 'A') ||
                    setweight(jsonb_to_tsvector('english', keywords, '["string"]'), 'B') ||
                    setweight(to_tsvector('english', coalesce(description, '')), 'C')
                ) STORED
                SQL);

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['user_id', 'status']);
        });

        // Duplicate protection: one live asset per file per user.
        // Partial index, so a soft-deleted asset does not block re-uploading the same file.
        DB::statement('CREATE UNIQUE INDEX assets_user_id_checksum_unique ON assets (user_id, checksum) WHERE deleted_at IS NULL');

        // Keyword containment search: keywords @> '["sunset"]'.
        DB::statement('CREATE INDEX assets_keywords_gin ON assets USING gin (keywords jsonb_path_ops)');

        DB::statement('CREATE INDEX assets_search_gin ON assets USING gin (search)');
    }

    public function down(): void
    {
        Schema::dropIfExists('assets');
    }
};
