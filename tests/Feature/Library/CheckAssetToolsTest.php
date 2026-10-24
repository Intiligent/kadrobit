<?php

namespace Tests\Feature\Library;

use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class CheckAssetToolsTest extends TestCase
{
    public function test_passes_when_every_tool_answers(): void
    {
        Process::fake([
            '*-encoders*' => Process::result(' V....D libx264 H.264'),
            '*' => Process::result('version 1.0'),
        ]);

        $this->artisan('assets:tools')->assertSuccessful();
    }

    public function test_fails_when_a_configured_tool_is_missing(): void
    {
        config(['assets.ghostscript' => '/nowhere/gs']);
        Process::fake([
            '*/nowhere/gs*' => Process::result(errorOutput: 'not found', exitCode: 127),
            '*-encoders*' => Process::result(' V....D libx264 H.264'),
            '*' => Process::result('version 1.0'),
        ]);

        $this->artisan('assets:tools')->assertFailed();
    }
}
