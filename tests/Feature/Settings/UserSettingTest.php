<?php

namespace Tests\Feature\Settings;

use App\Enums\UserSettingKey;
use App\Models\User;
use App\Models\UserSetting;
use Database\Seeders\OauthSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Passport\Passport;
use Tests\TestCase;

class UserSettingTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(OauthSeeder::class);
        $this->user = User::factory()->create();
    }

    public function test_a_setting_without_a_row_has_its_default(): void
    {
        $this->assertTrue($this->user->setting(UserSettingKey::MetadataAutofillOnUpload));
        $this->assertSame(0, UserSetting::count());
    }

    public function test_a_changed_setting_keeps_its_type(): void
    {
        $this->user->setSetting(UserSettingKey::MetadataAutofillOnUpload, false);

        $this->assertFalse($this->user->fresh()->setting(UserSettingKey::MetadataAutofillOnUpload));
        $this->assertSame('false', UserSetting::sole()->getRawOriginal('value'));
    }

    public function test_writing_again_updates_the_same_row(): void
    {
        $this->user->setSetting(UserSettingKey::MetadataAutofillOnUpload, false);
        $this->user->setSetting(UserSettingKey::MetadataAutofillOnUpload, 'stored as is');

        $this->assertSame(1, UserSetting::count());
        $this->assertSame('stored as is', $this->user->setting(UserSettingKey::MetadataAutofillOnUpload));
    }

    public function test_null_or_the_default_removes_the_row(): void
    {
        $this->user->setSetting(UserSettingKey::MetadataAutofillOnUpload, false);
        $this->user->setSetting(UserSettingKey::MetadataAutofillOnUpload, true);

        $this->assertSame(0, UserSetting::count());

        $this->user->setSetting(UserSettingKey::MetadataAutofillOnUpload, false);
        $this->user->setSetting(UserSettingKey::MetadataAutofillOnUpload, null);

        $this->assertSame(0, UserSetting::count());
        $this->assertTrue($this->user->setting(UserSettingKey::MetadataAutofillOnUpload));
    }

    public function test_settings_of_one_user_do_not_reach_another(): void
    {
        $other = User::factory()->create();
        $this->user->setSetting(UserSettingKey::MetadataAutofillOnUpload, false);

        $this->assertTrue($other->setting(UserSettingKey::MetadataAutofillOnUpload));
    }

    public function test_settings_are_deleted_with_the_user(): void
    {
        $this->user->setSetting(UserSettingKey::MetadataAutofillOnUpload, false);
        $this->user->delete();

        $this->assertSame(0, UserSetting::count());
    }

    public function test_one_query_serves_every_read(): void
    {
        $user = User::find($this->user->id);
        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $user->setting(UserSettingKey::MetadataAutofillOnUpload);
        $user->setting(UserSettingKey::MetadataAutofillOnUpload);
        $user->settingValues();

        $this->assertSame(1, $queries);
    }

    public function test_api_returns_settings_nested_with_defaults(): void
    {
        Passport::actingAs($this->user);

        $this->getJson('/api/settings')
            ->assertOk()
            ->assertExactJson(['data' => ['metadata' => ['autofill_on_upload' => true]]]);
    }

    public function test_api_changes_a_setting(): void
    {
        Passport::actingAs($this->user);

        $this->patchJson('/api/settings', ['metadata' => ['autofill_on_upload' => false]])
            ->assertOk()
            ->assertJsonPath('data.metadata.autofill_on_upload', false);

        $this->assertFalse($this->user->fresh()->setting(UserSettingKey::MetadataAutofillOnUpload));
    }

    public function test_api_casts_form_values_and_resets_with_null(): void
    {
        Passport::actingAs($this->user);

        $this->patchJson('/api/settings', ['metadata' => ['autofill_on_upload' => '0']])
            ->assertJsonPath('data.metadata.autofill_on_upload', false);

        $this->patchJson('/api/settings', ['metadata' => ['autofill_on_upload' => null]])
            ->assertJsonPath('data.metadata.autofill_on_upload', true);

        $this->assertSame(0, UserSetting::count());
    }

    public function test_api_leaves_settings_that_are_not_sent(): void
    {
        Passport::actingAs($this->user);
        $this->user->setSetting(UserSettingKey::MetadataAutofillOnUpload, false);

        $this->patchJson('/api/settings', [])->assertOk()->assertJsonPath('data.metadata.autofill_on_upload', false);
    }

    public function test_api_rejects_invalid_and_unknown_settings(): void
    {
        Passport::actingAs($this->user);

        $this->patchJson('/api/settings', ['metadata' => ['autofill_on_upload' => 'maybe']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('metadata.autofill_on_upload');

        $this->patchJson('/api/settings', ['metadata' => ['autofill_on_upload' => false, 'unknown' => 1], 'theme' => 'dark'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['metadata.unknown', 'theme']);

        $this->assertSame(0, UserSetting::count());
    }

    public function test_api_needs_a_logged_in_user(): void
    {
        $this->getJson('/api/settings')->assertUnauthorized();
        $this->patchJson('/api/settings', ['metadata' => ['autofill_on_upload' => false]])->assertUnauthorized();
    }

    public function test_metadata_page_gets_settings_from_the_shared_prop(): void
    {
        $this->user->setSetting(UserSettingKey::MetadataAutofillOnUpload, false);

        $this->actingAs($this->user, 'web')->get('/settings/metadata')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/metadata/index')
                ->where('settings.metadata.autofill_on_upload', false));
    }

    public function test_guests_get_no_settings_prop(): void
    {
        $this->get('/login')->assertInertia(fn (Assert $page) => $page->where('settings', null));
    }
}
