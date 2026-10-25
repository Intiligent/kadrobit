<?php

namespace Tests\Feature\Realtime;

use App\Events\EntityChanged;
use App\Models\User;
use App\Support\Realtime;
use Database\Seeders\OauthSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

class RealtimeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Web requests of a logged-in user renew the API token cookie, which needs the Passport client.
        $this->seed(OauthSeeder::class);
    }

    protected function usePusher(): void
    {
        config([
            'broadcasting.default' => 'pusher',
            'broadcasting.connections.pusher.key' => 'test-key',
            'broadcasting.connections.pusher.secret' => 'test-secret',
            'broadcasting.connections.pusher.app_id' => '1',
            'broadcasting.connections.pusher.options.cluster' => 'eu',
        ]);

        // Channels are registered on the broadcaster in use at boot: register them on Pusher too.
        require base_path('routes/channels.php');
    }

    public function test_a_user_is_authorized_for_their_own_channel_only(): void
    {
        $this->usePusher();
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($user, 'web')
            ->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => "private-users.{$user->ulid}"])
            ->assertOk()
            ->assertJsonStructure(['auth']);

        $this->actingAs($user, 'web')
            ->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => "private-users.{$other->ulid}"])
            ->assertForbidden();
    }

    public function test_guests_are_not_authorized(): void
    {
        $this->usePusher();
        $user = User::factory()->create();

        $this->postJson('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => "private-users.{$user->ulid}"])
            ->assertUnauthorized();
    }

    public function test_the_event_carries_only_what_changed(): void
    {
        $user = User::factory()->create();
        $event = new EntityChanged($user, 'asset', '01abc', 'metadata');

        $this->assertSame("private-users.{$user->ulid}", $event->broadcastOn()->name);
        $this->assertSame('entity.changed', $event->broadcastAs());
        $this->assertSame(['type' => 'asset', 'id' => '01abc', 'reason' => 'metadata'], $event->broadcastWith());
    }

    public function test_a_failed_notice_never_fails_the_caller(): void
    {
        Log::spy();
        Broadcast::shouldReceive('event')->andThrow(new RuntimeException('Pusher is down'));

        Realtime::changed(User::factory()->create(), 'asset', '01abc', 'metadata');

        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, 'Pusher is down'));
    }

    public function test_the_notice_is_sent_as_an_event(): void
    {
        Event::fake([EntityChanged::class]);
        $user = User::factory()->create();

        Realtime::changed($user, 'asset', '01abc', 'metadata');

        Event::assertDispatched(EntityChanged::class, fn ($event) => $event->user->is($user) && $event->id === '01abc');
    }
}
