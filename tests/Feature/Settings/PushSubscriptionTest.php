<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use App\Notifications\Push\ReviewSubmittedNotification;
use Database\Seeders\OauthSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Passport\Passport;
use NotificationChannels\WebPush\PushSubscription;
use Tests\TestCase;

class PushSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected const ENDPOINT = 'https://fcm.googleapis.com/fcm/send/abc';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(OauthSeeder::class);
        $this->user = User::factory()->create();
        Passport::actingAs($this->user);
    }

    protected function subscribe(string $auth = 'AuthSecret'): TestResponse
    {
        return $this->postJson('/api/push-subscriptions', [
            'endpoint' => self::ENDPOINT,
            'keys' => ['p256dh' => 'BPublicKey', 'auth' => $auth],
            'content_encoding' => 'aes128gcm',
        ]);
    }

    public function test_a_browser_turns_notifications_on(): void
    {
        $this->subscribe()->assertNoContent();

        $subscription = $this->user->pushSubscriptions()->sole();
        $this->assertSame(self::ENDPOINT, $subscription->endpoint);
        $this->assertSame('BPublicKey', $subscription->public_key);
        $this->assertSame('AuthSecret', $subscription->auth_token);
    }

    public function test_the_same_browser_signed_in_as_another_user_moves_to_them(): void
    {
        $this->subscribe();
        $other = User::factory()->create();
        Passport::actingAs($other);

        $this->subscribe()->assertNoContent();

        $this->assertSame(1, PushSubscription::count());
        $this->assertTrue($other->pushSubscriptions()->exists());
    }

    public function test_an_endpoint_alone_does_not_take_another_users_subscription(): void
    {
        $this->subscribe();
        Passport::actingAs(User::factory()->create());

        $this->subscribe('GuessedSecret')->assertForbidden();

        $this->assertTrue($this->user->pushSubscriptions()->exists());
    }

    public function test_a_browser_turns_them_off(): void
    {
        $this->subscribe();

        $this->deleteJson('/api/push-subscriptions', ['endpoint' => self::ENDPOINT])->assertNoContent();

        $this->assertSame(0, PushSubscription::count());
    }

    public function test_a_subscription_needs_an_https_endpoint_and_keys(): void
    {
        $this->postJson('/api/push-subscriptions', ['endpoint' => 'http://push.example.com/1', 'keys' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['endpoint', 'keys.p256dh', 'keys.auth']);
    }

    public function test_an_example_is_the_events_own_notification(): void
    {
        Notification::fake();

        $this->postJson('/api/push-subscriptions/example', ['event' => 'review_submitted'])->assertNoContent();
        $this->postJson('/api/push-subscriptions/example', ['event' => 'unknown'])->assertUnprocessable();

        Notification::assertSentToTimes($this->user, ReviewSubmittedNotification::class, 1);
    }

    public function test_the_page_has_the_vapid_public_key_the_users_browsers_and_the_event_switches(): void
    {
        config(['webpush.vapid.public_key' => 'BPublic']);
        $this->subscribe();

        $this->actingAs($this->user, 'web')->get('/settings/notifications')->assertInertia(fn (Assert $page) => $page
            ->component('settings/notifications/index')
            ->where('vapidPublicKey', 'BPublic')
            ->where('endpoints', [self::ENDPOINT])
            ->where('settings.notifications.browser.review_submitted', true));
    }

    public function test_the_event_switch_is_a_user_setting(): void
    {
        $this->patchJson('/api/settings', ['notifications' => ['browser' => ['review_submitted' => false]]])
            ->assertOk()
            ->assertJsonPath('data.notifications.browser.review_submitted', false);
    }
}
