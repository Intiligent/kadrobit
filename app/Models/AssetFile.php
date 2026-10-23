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

    /**
     * Where a file of this role is stored: assets/{user ulid}/{asset ulid}/{role}[.{hash}].{ext}.
     * Library folders are not part of the path, so moving a work between folders moves no files.
     * Derived files take a short content hash: they are cached by the CDN, so a regenerated
     * preview needs a new URL.
     */
    public static function pathFor(string $userUlid, string $assetUlid, AssetFileRole $role, string $extension, ?string $hash = null): string
    {
        $name = $role->value.($hash ? '.'.substr($hash, 0, 8) : '').'.'.strtolower($extension);

        return "assets/{$userUlid}/{$assetUlid}/{$name}";
    }

    public function storage(): Filesystem
    {
        return Storage::disk($this->disk);
    }

    /**
     * URL for the browser.
     * Derived files (previews, thumbnails) come from the public bucket's domain;
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
