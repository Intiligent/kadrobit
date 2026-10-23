<?php

namespace App\Models;

use App\Enums\AssetUploadStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * One uploaded file on its way to becoming a work. The file waits on the local staging disk
 * until ProcessAssetUpload has processed it; only then is it stored in R2 and the work created.
 *
 * @property int $id
 * @property string $ulid Public identifier for the API
 * @property int $user_id
 * @property string|null $batch_id job_batches.id of the files sent together
 * @property int|null $folder_id Folder the work goes into
 * @property string $asset_ulid Reserved ulid of the work to be created
 * @property int|null $asset_id The work, once created
 * @property string $filename Name of the file on the user's device
 * @property string $mime
 * @property int $size_bytes
 * @property string $checksum SHA-256, hex
 * @property AssetUploadStatus $status
 * @property string|null $error Why no work was created
 */
class AssetUpload extends Model
{
    use HasUlids, Prunable;

    /** Local disk the file waits on: the web server and the queue worker share the machine. */
    public const STAGING_DISK = 'local';

    /** Days an upload row (and a staged file left by a crash) is kept. */
    public const KEEP_DAYS = 2;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'batch_id',
        'folder_id',
        'asset_ulid',
        'asset_id',
        'filename',
        'mime',
        'size_bytes',
        'checksum',
        'status',
        'error',
    ];

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'processing',
    ];

    protected function casts(): array
    {
        return [
            'status' => AssetUploadStatus::class,
            'size_bytes' => 'integer',
        ];
    }

    /** Generate ULIDs for the upload and the work it reserves; the primary key stays an auto-increment id. */
    public function uniqueIds(): array
    {
        return ['ulid', 'asset_ulid'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(Folder::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /**
     * Where the file waits for processing.
     */
    public function stagingPath(): string
    {
        return "uploads/{$this->ulid}";
    }

    public function deleteStagedFile(): void
    {
        Storage::disk(self::STAGING_DISK)->delete($this->stagingPath());
    }

    /**
     * Upload rows are only needed while the upload list is open.
     */
    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays(self::KEEP_DAYS));
    }

    /**
     * A job cut short (e.g. a restart) may leave its staged file behind.
     */
    protected function pruning(): void
    {
        $this->deleteStagedFile();
    }
}
