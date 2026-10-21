<?php

use App\Enums\MetadataDelivery;
use App\Enums\UploadProtocol;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplaces', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('logo')->nullable();
            $table->boolean('is_active')->default(true);
            $table->jsonb('supported_types')->default('[]');
            $table->enum('upload_protocol', array_column(UploadProtocol::cases(), 'value'));
            $table->jsonb('upload_defaults')->default('{}');
            $table->enum('metadata_delivery', array_column(MetadataDelivery::cases(), 'value'))->nullable();
            $table->jsonb('metadata_rules')->default('{}');
            $table->jsonb('file_rules')->default('{}');
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplaces');
    }
};
