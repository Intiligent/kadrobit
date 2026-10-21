<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
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
}
