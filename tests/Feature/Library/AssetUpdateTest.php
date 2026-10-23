<?php

namespace Tests\Feature\Library;

use App\Enums\AssetStatus;
use App\Enums\MediaType;
use App\Models\Asset;
use App\Models\Marketplace;
use App\Models\User;
use Database\Seeders\MarketplaceCategorySeeder;
use Database\Seeders\MarketplaceSeeder;
use Database\Seeders\OauthSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Tests\TestCase;

class AssetUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([OauthSeeder::class, MarketplaceSeeder::class, MarketplaceCategorySeeder::class]);
        $this->user = User::factory()->create();
        Passport::actingAs($this->user);
    }

    protected function asset(array $attributes = [], ?User $user = null): Asset
    {
        return ($user ?? $this->user)->assets()->create([
            'type' => MediaType::Photo,
            'status' => AssetStatus::Draft,
            'original_filename' => 'IMG_'.Str::random(4).'.jpg',
            'checksum' => hash('sha256', Str::random()),
            ...$attributes,
        ]);
    }

    protected function connect(string ...$codes): void
    {
        foreach ($codes as $code) {
            $this->user->marketplaceAccounts()->create([
                'marketplace_id' => Marketplace::where('code', $code)->sole()->id,
                'label' => $code,
            ]);
        }
    }

    protected function patchAsset(Asset $asset, array $data)
    {
        return $this->patchJson("/api/assets/{$asset->ulid}", $data);
    }

    public function test_only_sent_fields_are_saved(): void
    {
        $asset = $this->asset(['title' => 'Glacier lagoon', 'keywords' => ['ice']]);

        $this->patchAsset($asset, ['description' => 'Icebergs at sunrise', 'is_ai_generated' => true])
            ->assertOk()
            ->assertJsonPath('data.id', $asset->ulid)
            ->assertJsonPath('data.title', 'Glacier lagoon')
            ->assertJsonPath('data.description', 'Icebergs at sunrise')
            ->assertJsonPath('data.is_ai_generated', true);

        $this->assertSame(['ice'], $asset->fresh()->keywords);
    }

    public function test_keywords_are_trimmed_lowercased_and_unique_in_order(): void
    {
        $asset = $this->asset();

        $this->patchAsset($asset, ['keywords' => [' Glacier ', 'ICELAND', 'glacier', '', '  ', 'Ice Lagoon']])
            ->assertOk()
            ->assertJsonPath('data.keywords', ['glacier', 'iceland', 'ice lagoon']);
    }

    public function test_too_many_keywords_are_rejected(): void
    {
        $asset = $this->asset();
        $keywords = array_map(fn (int $i) => "word{$i}", range(1, 51));

        $this->patchAsset($asset, ['keywords' => $keywords])->assertUnprocessable()->assertJsonValidationErrors('keywords');
    }

    public function test_categories_are_saved_by_marketplace_code(): void
    {
        $this->connect('adobe_stock', 'shutterstock');
        $asset = $this->asset();

        $this->patchAsset($asset, ['categories' => [
            'adobe_stock' => ['11'],
            'shutterstock' => ['Nature', 'Parks/Outdoor', 'Nature'],
        ]])->assertOk();

        $this->assertSame(['adobe_stock' => ['11'], 'shutterstock' => ['Nature', 'Parks/Outdoor']], $asset->fresh()->categories);
    }

    public function test_clearing_a_marketplace_drops_its_key(): void
    {
        $this->connect('adobe_stock');
        $asset = $this->asset(['categories' => ['adobe_stock' => ['11']]]);

        $this->patchAsset($asset, ['categories' => ['adobe_stock' => []]])
            ->assertOk();

        $this->assertSame([], $asset->fresh()->categories);
        // An empty object, not a list, so the frontend can index it by code.
        $this->assertStringContainsString('"categories":{}', $this->patchAsset($asset, [])->getContent());
    }

    public function test_categories_need_a_connected_marketplace(): void
    {
        $this->connect('adobe_stock');
        $asset = $this->asset();

        $this->patchAsset($asset, ['categories' => ['shutterstock' => ['Nature']]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('categories.shutterstock');
    }

    public function test_categories_must_exist_on_the_marketplace_list(): void
    {
        $this->connect('adobe_stock');
        $asset = $this->asset();

        $this->patchAsset($asset, ['categories' => ['adobe_stock' => ['99']]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('categories.adobe_stock');
    }

    public function test_categories_must_apply_to_the_type_of_work(): void
    {
        $this->connect('shutterstock');
        $video = $this->asset(['type' => MediaType::Video]);

        // "Parks/Outdoor" is not available for video on Shutterstock.
        $this->patchAsset($video, ['categories' => ['shutterstock' => ['Parks/Outdoor']]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('categories.shutterstock');
    }

    public function test_categories_respect_the_marketplace_limit(): void
    {
        $this->connect('adobe_stock', 'shutterstock');
        $asset = $this->asset();

        $this->patchAsset($asset, ['categories' => ['adobe_stock' => ['11', '12']]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('categories.adobe_stock');

        $this->patchAsset($asset, ['categories' => ['shutterstock' => ['Nature', 'Arts', 'People']]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('categories.shutterstock');
    }

    public function test_work_needs_a_title_and_keywords_to_be_ready(): void
    {
        $asset = $this->asset(['title' => 'Glacier lagoon']);

        $this->patchAsset($asset, ['status' => 'ready'])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertSame(AssetStatus::Draft, $asset->fresh()->status);

        // Keywords sent together with the status count.
        $this->patchAsset($asset, ['keywords' => ['glacier'], 'status' => 'ready'])
            ->assertOk()
            ->assertJsonPath('data.status', 'ready');
    }

    public function test_ready_work_keeps_its_title_and_keywords(): void
    {
        $asset = $this->asset(['status' => AssetStatus::Ready, 'title' => 'Glacier lagoon', 'keywords' => ['glacier']]);

        $this->patchAsset($asset, ['keywords' => []])->assertUnprocessable()->assertJsonValidationErrors('keywords');
        $this->patchAsset($asset, ['title' => ''])->assertUnprocessable()->assertJsonValidationErrors('title');

        // Back to draft first, then the metadata can be cleared.
        $this->patchAsset($asset, ['status' => 'draft', 'keywords' => []])->assertOk();
    }

    public function test_work_can_be_archived_and_restored_without_metadata(): void
    {
        $asset = $this->asset();

        $this->patchAsset($asset, ['status' => 'archived'])->assertOk()->assertJsonPath('data.status', 'archived');
        $this->patchAsset($asset, ['status' => 'draft'])->assertOk()->assertJsonPath('data.status', 'draft');
        $this->patchAsset($asset, ['status' => 'published'])->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    public function test_another_users_work_is_not_found(): void
    {
        $asset = $this->asset(['title' => 'Not mine'], User::factory()->create());

        $this->patchAsset($asset, ['title' => 'Mine now'])->assertNotFound();
        $this->assertSame('Not mine', $asset->fresh()->title);
    }

    public function test_editing_needs_a_confirmed_email(): void
    {
        $user = User::factory()->unverified()->create();
        $asset = $this->asset(['title' => 'Draft'], $user);
        Passport::actingAs($user);

        $this->patchAsset($asset, ['title' => 'Edited'])->assertForbidden();
        $this->assertSame('Draft', $asset->fresh()->title);
    }
}
