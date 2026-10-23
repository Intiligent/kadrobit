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
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AssetControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // A web login issues the API token cookie, which needs the personal access client.
        $this->seed(OauthSeeder::class);
    }

    public function test_guest_is_sent_to_login(): void
    {
        $this->get('/library')->assertRedirect('/login');
    }

    public function test_home_sends_logged_in_users_to_the_library(): void
    {
        $this->actingAs(User::factory()->create())->get('/')->assertRedirect('/library');
    }

    public function test_home_stays_open_to_guests(): void
    {
        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page->component('home/index'));
    }

    public function test_library_lists_only_own_assets(): void
    {
        $user = User::factory()->create();
        $mine = $this->asset($user, ['title' => 'Glacier lagoon at sunrise']);
        $this->asset(User::factory()->create());

        $this->actingAs($user)
            ->get('/library')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('library/index')
                ->has('assets', 1)
                ->where('assets.0.id', $mine->ulid)
                ->where('assets.0.title', 'Glacier lagoon at sunrise')
                ->where('assets.0.status', 'draft')
                ->where('assets.0.folder_id', null)
                ->has('folders', 0)
                ->has('accounts', 0)
                ->where('categories', []));
    }

    public function test_library_lists_own_folders_with_parents_by_ulid(): void
    {
        $user = User::factory()->create();
        $travel = $user->folders()->create(['name' => 'Travel 2026']);
        $iceland = $user->folders()->create(['name' => 'Iceland', 'parent_id' => $travel->id]);
        $this->asset($user, ['folder_id' => $iceland->id]);
        User::factory()->create()->folders()->create(['name' => 'Not mine']);

        $this->actingAs($user)
            ->get('/library')
            ->assertInertia(fn (Assert $page) => $page
                ->has('folders', 2)
                ->where('folders.0', ['id' => $iceland->ulid, 'parent_id' => $travel->ulid, 'name' => 'Iceland'])
                ->where('folders.1', ['id' => $travel->ulid, 'parent_id' => null, 'name' => 'Travel 2026'])
                ->where('assets.0.folder_id', $iceland->ulid));
    }

    public function test_deleting_a_folder_leaves_its_works_unsorted(): void
    {
        $user = User::factory()->create();
        $folder = $user->folders()->create(['name' => 'Food']);
        $asset = $this->asset($user, ['folder_id' => $folder->id]);

        $folder->delete();

        $this->assertNull($asset->fresh()->folder_id);
    }

    public function test_library_opens_for_an_unverified_user(): void
    {
        $this->actingAs(User::factory()->unverified()->create())->get('/library')->assertOk();
    }

    public function test_connected_accounts_come_without_credentials(): void
    {
        $this->seed(MarketplaceSeeder::class);
        $user = User::factory()->create();
        $marketplace = Marketplace::where('code', 'adobe_stock')->sole();
        $user->marketplaceAccounts()->create([
            'marketplace_id' => $marketplace->id,
            'label' => 'Main',
            'credentials' => ['password' => 'secret-ftp-password'],
        ]);

        $response = $this->actingAs($user)->get('/library');

        $response->assertInertia(fn (Assert $page) => $page
            ->has('accounts', 1)
            ->where('accounts.0.label', 'Main')
            ->where('accounts.0.status', 'active')
            ->where('accounts.0.marketplace.code', 'adobe_stock')
            ->missing('accounts.0.credentials'));
        $this->assertStringNotContainsString('secret-ftp-password', $response->getContent());
    }

    public function test_library_sends_category_lists_of_connected_marketplaces_only(): void
    {
        $this->seed([MarketplaceSeeder::class, MarketplaceCategorySeeder::class]);
        $user = User::factory()->create();
        $user->marketplaceAccounts()->create([
            'marketplace_id' => Marketplace::where('code', 'shutterstock')->sole()->id,
            'label' => 'Main',
        ]);

        $this->actingAs($user)
            ->get('/library')
            ->assertInertia(fn (Assert $page) => $page
                ->has('categories', 1)
                ->has('categories.shutterstock', 26)
                ->where('categories.shutterstock.0', ['id' => 'Abstract', 'name' => 'Abstract', 'media_types' => ['photo', 'vector', 'illustration']]));
    }

    protected function asset(User $user, array $attributes = []): Asset
    {
        return $user->assets()->create([
            'type' => MediaType::Photo,
            'status' => AssetStatus::Draft,
            'original_filename' => 'IMG_'.Str::random(4).'.jpg',
            'checksum' => hash('sha256', Str::random()),
            ...$attributes,
        ]);
    }
}
