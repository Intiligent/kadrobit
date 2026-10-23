<?php

namespace Tests\Feature\Models;

use App\Enums\AssetFileRole;
use App\Models\Asset;
use App\Models\AssetFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AssetFileTest extends TestCase
{
    use RefreshDatabase;

    protected Asset $asset;

    protected function setUp(): void
    {
        parent::setUp();

        $this->asset = User::factory()->create()->assets()->create([
            'type' => 'photo', 'original_filename' => 'IMG_1.JPG', 'checksum' => hash('sha256', '1'),
        ]);
    }

    public function test_users_get_a_ulid(): void
    {
        $this->assertSame(26, strlen($this->asset->user->ulid));
    }

    public function test_path_is_built_from_user_and_asset_ulids_and_the_role(): void
    {
        $user = $this->asset->user->ulid;
        $base = "assets/{$user}/{$this->asset->ulid}";

        $this->assertSame("{$base}/original.jpg", AssetFile::pathFor($user, $this->asset->ulid, AssetFileRole::Original, 'JPG'));
        $this->assertSame(
            "{$base}/thumbnail.3f9a1c2b.jpg",
            AssetFile::pathFor($user, $this->asset->ulid, AssetFileRole::Thumbnail, 'jpg', '3f9a1c2b7d0e'),
        );
    }

    public function test_originals_go_to_the_private_disk_and_derived_files_to_the_public_one(): void
    {
        $this->assertSame('r2-private', AssetFileRole::Original->disk());
        $this->assertSame('r2-private', AssetFileRole::RasterCompanion->disk());
        $this->assertSame('r2-public', AssetFileRole::Thumbnail->disk());
        $this->assertSame('r2-public', AssetFileRole::Preview->disk());
        $this->assertSame('r2-public', AssetFileRole::VideoPreview->disk());
    }

    public function test_derived_files_have_a_public_url_and_originals_a_signed_one(): void
    {
        config(['filesystems.disks.r2-public.url' => 'https://media.example.test']);
        Storage::fake('r2-private');

        $thumbnail = $this->asset->files()->create([
            'role' => AssetFileRole::Thumbnail, 'disk' => 'r2-public', 'path' => 'assets/u/a/thumbnail.1.jpg',
            'mime' => 'image/jpeg', 'size_bytes' => 1,
        ]);
        $original = $this->asset->files()->create([
            'role' => AssetFileRole::Original, 'disk' => 'r2-private', 'path' => 'assets/u/a/original.jpg',
            'mime' => 'image/jpeg', 'size_bytes' => 1,
        ]);

        $this->assertSame('https://media.example.test/assets/u/a/thumbnail.1.jpg', $thumbnail->url());
        $this->assertStringContainsString('expiration=', $original->url());
    }
}
