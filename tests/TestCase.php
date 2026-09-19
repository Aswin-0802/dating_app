<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Refuses to boot a test against anything but the test database.
     *
     * The suite uses RefreshDatabase, which drops and re-creates every table.
     * When the configuration is cached (`php artisan optimize` or
     * `config:cache`), Laravel ignores the overrides in phpunit.xml, so the
     * tests silently run against the development database and wipe it. This
     * stops them before the first query instead.
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        $config = $app['config'];
        $database = (string) $config->get('database.connections.'.$config->get('database.default').'.database');

        if ($app->environment() !== 'testing' || ! str_ends_with($database, '_testing')) {
            throw new RuntimeException(sprintf(
                'Refusing to run tests against database [%s] in environment [%s]. '
                .'The configuration is probably cached: run `php artisan config:clear` and try again.',
                $database,
                $app->environment(),
            ));
        }

        return $app;
    }
}
