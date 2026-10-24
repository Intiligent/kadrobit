<?php

namespace Tests\Feature\Settings;

use App\Enums\MarketplaceAccountStatus;
use App\Models\Marketplace;
use App\Models\MarketplaceAccount;
use App\Models\User;
use App\Services\Marketplaces\ConnectionResult;
use App\Services\Marketplaces\ConnectionTester;
use Database\Seeders\MarketplaceSeeder;
use Database\Seeders\OauthSeeder;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Passport\Passport;
use Mockery\MockInterface;
use Tests\TestCase;

class MarketplaceAccountTest extends TestCase
{
    use RefreshDatabase;

    protected const PASSWORD = 'secret-ftp-password';

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([OauthSeeder::class, MarketplaceSeeder::class]);
        $this->user = User::factory()->create();
        Passport::actingAs($this->user);
    }

    protected function tester(ConnectionResult ...$results): MockInterface
    {
        return $this->mock(ConnectionTester::class, function (MockInterface $mock) use ($results) {
            $mock->shouldReceive('test')->andReturn(...($results ?: [ConnectionResult::ok()]));
        });
    }

    protected function account(string $code = 'shutterstock', array $attributes = [], ?User $user = null): MarketplaceAccount
    {
        $marketplace = Marketplace::where('code', $code)->sole();

        return ($user ?? $this->user)->marketplaceAccounts()->create([
            'marketplace_id' => $marketplace->id,
            'label' => $marketplace->name,
            'host' => $marketplace->upload_defaults['host'] ?? 'ftp.example.com',
            'login' => 'akadrov',
            'credentials' => ['password' => self::PASSWORD],
            'status' => MarketplaceAccountStatus::Active,
            ...$attributes,
        ]);
    }

    public function test_settings_open_on_marketplaces(): void
    {
        $this->actingAs($this->user, 'web')->get('/settings')->assertRedirect('/settings/marketplaces');
    }

    public function test_marketplaces_page_lists_accounts_and_catalog_without_passwords(): void
    {
        $this->account();

        $response = $this->actingAs($this->user, 'web')->get('/settings/marketplaces')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/marketplaces/index')
                ->has('accounts', 1)
                ->where('accounts.0.login', 'akadrov')
                ->where('accounts.0.has_password', true)
                ->missing('accounts.0.credentials')
                ->where('catalog', fn ($catalog) => collect($catalog)->firstWhere('code', 'istock')['connectable'] === false));

        $this->assertStringNotContainsString(self::PASSWORD, $response->getContent());
    }

    public function test_marketplace_is_connected_after_a_successful_sign_in(): void
    {
        // No server sent: the marketplace default is used.
        $this->mock(ConnectionTester::class, fn (MockInterface $mock) => $mock->shouldReceive('test')
            ->once()
            ->withArgs(fn ($marketplace, $connection) => $connection['host'] === 'ftps.shutterstock.com'
                && $connection['password'] === self::PASSWORD)
            ->andReturn(ConnectionResult::ok()));

        $this->postJson('/api/marketplace-accounts', [
            'marketplace' => 'shutterstock',
            'label' => 'Shutterstock',
            'login' => 'akadrov',
            'password' => self::PASSWORD,
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.host', 'ftps.shutterstock.com')
            ->assertJsonMissingPath('data.password')
            ->assertDontSee(self::PASSWORD);

        $this->assertSame(self::PASSWORD, $this->user->marketplaceAccounts()->sole()->credentials['password']);
    }

    public function test_account_is_saved_off_when_the_first_sign_in_fails(): void
    {
        $this->tester(ConnectionResult::failed(ConnectionResult::AUTH));

        $this->postJson('/api/marketplace-accounts', [
            'marketplace' => 'shutterstock',
            'label' => 'Shutterstock',
            'login' => 'akadrov',
            'password' => 'wrong',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'invalid_credentials')
            ->assertJsonPath('data.last_error', 'auth')
            ->assertJsonPath('message', __('settings.marketplaces.check.auth', ['name' => 'Shutterstock', 'host' => 'ftps.shutterstock.com']))
            ->assertDontSee('wrong');

        $account = $this->user->marketplaceAccounts()->sole();
        $this->assertSame(MarketplaceAccountStatus::InvalidCredentials, $account->status);
        $this->assertSame('wrong', $account->credentials['password']);
    }

    public function test_account_is_saved_off_when_the_server_is_unreachable(): void
    {
        $this->tester(ConnectionResult::failed(ConnectionResult::CONNECT));

        $this->postJson('/api/marketplace-accounts', [
            'marketplace' => 'shutterstock',
            'label' => 'Shutterstock',
            'login' => 'akadrov',
            'password' => self::PASSWORD,
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'invalid_credentials')
            ->assertJsonPath('data.last_error', 'connect');
    }

    public function test_account_saved_off_is_turned_on_by_a_successful_check(): void
    {
        $this->tester(ConnectionResult::failed(ConnectionResult::CONNECT), ConnectionResult::ok());

        $id = $this->postJson('/api/marketplace-accounts', [
            'marketplace' => 'shutterstock',
            'label' => 'Shutterstock',
            'login' => 'akadrov',
            'password' => self::PASSWORD,
        ])->json('data.id');

        $this->postJson("/api/marketplace-accounts/{$id}/check")
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.last_error', null);
    }

    public function test_contributor_id_is_the_login_where_the_marketplace_uses_it_so(): void
    {
        $this->mock(ConnectionTester::class, fn (MockInterface $mock) => $mock->shouldReceive('test')
            ->once()
            ->withArgs(fn ($marketplace, $connection) => $connection['host'] === 'sftp.contributor.adobestock.com'
                && $connection['port'] === 22
                && $connection['login'] === '489119315')
            ->andReturn(ConnectionResult::ok()));

        // A login sent anyway is ignored: the contributor ID is what Adobe Stock signs in with.
        $this->postJson('/api/marketplace-accounts', [
            'marketplace' => 'adobe_stock',
            'label' => 'Adobe Stock',
            'contributor_id' => '489119315',
            'login' => 'someone@example.com',
            'password' => self::PASSWORD,
        ])
            ->assertCreated()
            ->assertJsonPath('data.login', '489119315')
            ->assertJsonPath('data.contributor_id', '489119315');
    }

    public function test_contributor_id_is_required_where_it_is_the_login(): void
    {
        $this->mock(ConnectionTester::class)->shouldNotReceive('test');

        $this->postJson('/api/marketplace-accounts', [
            'marketplace' => 'adobe_stock',
            'label' => 'Adobe Stock',
            'password' => self::PASSWORD,
        ])
            ->assertJsonValidationErrors('contributor_id')
            ->assertJsonMissingValidationErrors('login');
    }

    public function test_changing_the_contributor_id_signs_in_again_where_it_is_the_login(): void
    {
        $this->mock(ConnectionTester::class, fn (MockInterface $mock) => $mock->shouldReceive('test')
            ->once()
            ->withArgs(fn ($marketplace, $connection) => $connection['login'] === '500000001'
                && $connection['password'] === self::PASSWORD)
            ->andReturn(ConnectionResult::ok()));
        $account = $this->account('adobe_stock', ['contributor_id' => '489119315', 'login' => '489119315']);

        $this->patchJson("/api/marketplace-accounts/{$account->id}", ['contributor_id' => '500000001'])
            ->assertOk()
            ->assertJsonPath('data.login', '500000001');

        $this->patchJson("/api/marketplace-accounts/{$account->id}", ['login' => 'other'])->assertJsonValidationErrors('login');
        $this->patchJson("/api/marketplace-accounts/{$account->id}", ['contributor_id' => null])->assertJsonValidationErrors('contributor_id');
    }

    public function test_changing_the_contributor_id_elsewhere_does_not_sign_in(): void
    {
        $this->mock(ConnectionTester::class)->shouldNotReceive('test');
        $account = $this->account();

        $this->patchJson("/api/marketplace-accounts/{$account->id}", ['contributor_id' => '123'])
            ->assertOk()
            ->assertJsonPath('data.login', 'akadrov');
    }

    public function test_server_is_required_when_the_marketplace_has_no_default(): void
    {
        $this->tester();

        $this->postJson('/api/marketplace-accounts', [
            'marketplace' => 'depositphotos',
            'label' => 'Depositphotos',
            'login' => 'akadrov',
            'password' => self::PASSWORD,
        ])->assertJsonValidationErrors('host');
    }

    public function test_server_in_a_private_network_is_rejected_without_signing_in(): void
    {
        $this->mock(ConnectionTester::class)->shouldNotReceive('test');

        foreach (['127.0.0.1', '10.0.0.5', '169.254.169.254', '::1', 'localhost', 'ftp.example.com/upload'] as $host) {
            $this->postJson('/api/marketplace-accounts', [
                'marketplace' => 'depositphotos',
                'label' => 'Depositphotos',
                'host' => $host,
                'login' => 'akadrov',
                'password' => self::PASSWORD,
            ])->assertJsonValidationErrors('host');
        }
    }

    public function test_custom_server_is_saved(): void
    {
        $this->tester();

        foreach (['8.8.8.8', '2001:4860:4860::8888'] as $i => $host) {
            $this->postJson('/api/marketplace-accounts', [
                'marketplace' => 'depositphotos',
                'label' => "Depositphotos {$i}",
                'host' => $host,
                'login' => 'akadrov',
                'password' => self::PASSWORD,
            ])
                ->assertCreated()
                ->assertJsonPath('data.host', $host);
        }
    }

    public function test_web_only_marketplaces_cannot_be_connected(): void
    {
        $this->tester();

        $this->postJson('/api/marketplace-accounts', [
            'marketplace' => 'istock',
            'label' => 'iStock',
            'host' => '8.8.8.8',
            'login' => 'akadrov',
            'password' => self::PASSWORD,
        ])->assertJsonValidationErrors('marketplace');
    }

    public function test_account_names_are_unique_per_marketplace(): void
    {
        $this->tester();
        $this->account();

        $this->postJson('/api/marketplace-accounts', [
            'marketplace' => 'shutterstock',
            'label' => 'Shutterstock',
            'login' => 'second',
            'password' => self::PASSWORD,
        ])->assertJsonValidationErrors('label');
    }

    public function test_password_is_encrypted_with_the_credentials_key_not_the_app_key(): void
    {
        $account = $this->account();
        $raw = DB::table('marketplace_accounts')->where('id', $account->id)->value('credentials');

        $this->assertStringNotContainsString(self::PASSWORD, $raw);
        $this->assertSame(self::PASSWORD, json_decode(app('credentials.encrypter')->decryptString($raw), true)['password']);

        $this->expectException(DecryptException::class);
        Crypt::decryptString($raw);
    }

    public function test_renaming_does_not_sign_in_again(): void
    {
        $this->mock(ConnectionTester::class)->shouldNotReceive('test');
        $account = $this->account();

        $this->patchJson("/api/marketplace-accounts/{$account->id}", ['label' => 'Main', 'status' => 'disabled'])
            ->assertOk()
            ->assertJsonPath('data.label', 'Main')
            ->assertJsonPath('data.status', 'disabled');
    }

    public function test_new_password_is_checked_and_lifts_a_failed_state(): void
    {
        $this->tester();
        $account = $this->account('dreamstime', ['status' => MarketplaceAccountStatus::InvalidCredentials, 'last_error' => 'auth']);

        $this->patchJson("/api/marketplace-accounts/{$account->id}", ['password' => 'new-ftp-password'])
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.last_error', null);

        $this->assertSame('new-ftp-password', $account->fresh()->credentials['password']);
    }

    public function test_wrong_new_password_keeps_the_saved_one(): void
    {
        $this->tester(ConnectionResult::failed(ConnectionResult::AUTH));
        $account = $this->account();

        $this->patchJson("/api/marketplace-accounts/{$account->id}", ['password' => 'wrong'])
            ->assertJsonValidationErrors('connection');

        $this->assertSame(self::PASSWORD, $account->fresh()->credentials['password']);
    }

    public function test_check_marks_rejected_credentials(): void
    {
        $this->tester(ConnectionResult::failed(ConnectionResult::AUTH));
        $account = $this->account();

        $this->postJson("/api/marketplace-accounts/{$account->id}/check")
            ->assertOk()
            ->assertJsonPath('data.status', 'invalid_credentials')
            ->assertJsonPath('data.last_error', 'auth');
    }

    public function test_unreachable_server_does_not_mark_the_password_wrong(): void
    {
        $this->tester(ConnectionResult::failed(ConnectionResult::CONNECT));
        $account = $this->account();

        $this->postJson("/api/marketplace-accounts/{$account->id}/check")
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.last_error', 'connect');
    }

    public function test_turning_on_does_not_lift_a_rejected_sign_in(): void
    {
        $this->mock(ConnectionTester::class)->shouldNotReceive('test');
        $account = $this->account(attributes: ['status' => MarketplaceAccountStatus::Disabled, 'last_error' => 'auth']);

        $this->patchJson("/api/marketplace-accounts/{$account->id}", ['status' => 'active'])
            ->assertOk()
            ->assertJsonPath('data.status', 'invalid_credentials');
    }

    public function test_unreachable_server_keeps_a_rejected_password_on_record(): void
    {
        $this->tester(ConnectionResult::failed(ConnectionResult::CONNECT));
        $account = $this->account(attributes: ['status' => MarketplaceAccountStatus::InvalidCredentials, 'last_error' => 'auth']);

        $this->postJson("/api/marketplace-accounts/{$account->id}/check")
            ->assertOk()
            ->assertJsonPath('data.status', 'invalid_credentials')
            ->assertJsonPath('data.last_error', 'auth');

        // Off and on again: still rejected, since no sign-in has succeeded.
        $this->patchJson("/api/marketplace-accounts/{$account->id}", ['status' => 'disabled'])->assertJsonPath('data.status', 'disabled');
        $this->patchJson("/api/marketplace-accounts/{$account->id}", ['status' => 'active'])->assertJsonPath('data.status', 'invalid_credentials');
    }

    public function test_turning_on_a_rejected_account_keeps_it_rejected(): void
    {
        $account = $this->account(attributes: ['status' => MarketplaceAccountStatus::InvalidCredentials, 'last_error' => 'connect']);

        $this->patchJson("/api/marketplace-accounts/{$account->id}", ['status' => 'active'])
            ->assertOk()
            ->assertJsonPath('data.status', 'invalid_credentials');
    }

    public function test_successful_check_clears_a_rejected_password(): void
    {
        $this->tester();
        $account = $this->account(attributes: ['status' => MarketplaceAccountStatus::InvalidCredentials, 'last_error' => 'auth']);

        $this->postJson("/api/marketplace-accounts/{$account->id}/check")
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.last_error', null);
    }

    public function test_sign_ins_are_limited_per_user_whatever_the_action(): void
    {
        $this->tester();
        $account = $this->account();

        foreach (range(1, 9) as $i) {
            $this->postJson("/api/marketplace-accounts/{$account->id}/check")->assertOk();
        }

        $this->patchJson("/api/marketplace-accounts/{$account->id}", ['password' => 'new-ftp-password'])->assertOk();
        $this->postJson("/api/marketplace-accounts/{$account->id}/check")->assertTooManyRequests()->assertHeader('Retry-After');
        $this->patchJson("/api/marketplace-accounts/{$account->id}", ['login' => 'other'])->assertTooManyRequests();
    }

    public function test_renaming_and_turning_off_do_not_count_as_sign_ins(): void
    {
        $this->mock(ConnectionTester::class)->shouldNotReceive('test');
        $account = $this->account();

        foreach (range(1, 12) as $i) {
            $this->patchJson("/api/marketplace-accounts/{$account->id}", ['label' => "Main {$i}", 'status' => $i % 2 ? 'disabled' : 'active'])->assertOk();
        }
    }

    public function test_check_keeps_a_turned_off_account_off(): void
    {
        $this->tester(ConnectionResult::failed(ConnectionResult::AUTH));
        $account = $this->account(attributes: ['status' => MarketplaceAccountStatus::Disabled]);

        $this->postJson("/api/marketplace-accounts/{$account->id}/check")
            ->assertOk()
            ->assertJsonPath('data.status', 'disabled')
            ->assertJsonPath('data.last_error', 'auth');
    }

    public function test_removing_deletes_the_account_and_its_credentials(): void
    {
        $account = $this->account();

        $this->deleteJson("/api/marketplace-accounts/{$account->id}")->assertNoContent();
        $this->assertDatabaseMissing('marketplace_accounts', ['id' => $account->id]);
    }

    public function test_someone_elses_account_is_not_found(): void
    {
        $this->tester();
        $account = $this->account(user: User::factory()->create());

        $this->patchJson("/api/marketplace-accounts/{$account->id}", ['label' => 'Mine'])->assertNotFound();
        $this->postJson("/api/marketplace-accounts/{$account->id}/check")->assertNotFound();
        $this->deleteJson("/api/marketplace-accounts/{$account->id}")->assertNotFound();
        $this->assertDatabaseHas('marketplace_accounts', ['id' => $account->id]);
    }
}
