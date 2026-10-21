<?php

namespace App\Models;

use App\Enums\AssetFileRole;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A physical file of an asset: the original, a vector's JPEG companion, previews.
 *
 * @property int $id
 * @property int $asset_id
 * @property AssetFileRole $role
 * @property string $disk Laravel filesystem disk
 * @property string $path Path on the disk
 * @property string $mime
 * @property int $size_bytes
 * @property int|null $width
 * @property int|null $height
 * @property string|null $checksum SHA-256, hex
 */
class AssetFile extends Model
{
    protected $fillable = [
        'role',
        'disk',
        'path',
        'mime',
        'size_bytes',
        'width',
        'height',
        'checksum',
    ];

    protected function casts(): array
    {
        return [
            'role' => AssetFileRole::class,
            'size_bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function storage(): Filesystem
    {
        return Storage::disk($this->disk);
    }

    /**
     * URL for the browser.
     * Derived files (previews, thumbnails) go through the public CDN when the disk has one;
     * originals are user data and are only served via short-lived signed URLs.
     */
    public function url(int $ttlMinutes = 60): string
    {
        if ($this->role->isDerived() && config("filesystems.disks.{$this->disk}.url")) {
            return $this->storage()->url($this->path);
        }

        return $this->storage()->temporaryUrl($this->path, now()->addMinutes($ttlMinutes));
    }

    public function fileExists(): bool
    {
        return $this->storage()->exists($this->path);
    }
}
