<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DatabaseTest extends TestCase
{
    public function test_connection_uses_the_app_time_zone(): void
    {
        // Otherwise PostgreSQL reads offset-less timestamps in its own zone and shifts timestampTz values.
        $this->assertSame(config('app.timezone'), DB::selectOne('show timezone')->TimeZone);
    }
}
