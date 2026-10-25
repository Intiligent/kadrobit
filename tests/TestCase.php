<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * RefreshDatabase wipes the schema, and a cached config overrides phpunit.xml.
     * Refuse to boot against anything that is not a dedicated test database.
     */
    public function createApplication(): Application
    {
        $app = parent::createApplication();

        $config = $app['config'];
        $database = (string) $config->get('database.connections.'.$config->get('database.default').'.database');

        if (! str_ends_with($database, '_testing')) {
            throw new RuntimeException("Tests must run against a *_testing database, got [{$database}]. Run `php artisan config:clear`.");
        }

        return $app;
    }

    /**
     * No real HTTP calls from tests: a paid API (metadata generation) must be faked with Http::fake().
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    /**
     * In the app every request starts with an empty cookie queue; in tests the app, and its cookie jar,
     * is shared between requests, so cookies queued by one request would leak into the next response.
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->app['cookie']->flushQueuedCookies();

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }
}
