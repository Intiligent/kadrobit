<?php

namespace Tests\Feature\Models;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserTest extends TestCase
{
    use RefreshDatabase;

    public function test_username_is_stored_lowercase_and_trimmed(): void
    {
        $user = User::factory()->create(['username' => '  John.Smith ']);

        $this->assertSame('john.smith', $user->fresh()->username);
    }

    public function test_generate_username_strips_unsafe_characters(): void
    {
        $this->assertSame('john.smith', User::generateUsername('John.Smith!#'));
    }

    public function test_generate_username_pads_short_seeds(): void
    {
        $this->assertSame('userjo', User::generateUsername('jo'));
        $this->assertSame('user', User::generateUsername('#!@'));
    }

    public function test_generate_username_caps_length_and_avoids_taken_handles(): void
    {
        User::factory()->create(['username' => 'abcdefghijklmnopqrst']);

        $username = User::generateUsername('abcdefghijklmnopqrstuvwxyz');

        $this->assertSame('abcdefghijklmnopqrs1', $username);
        $this->assertLessThanOrEqual(20, strlen($username));
    }
}
