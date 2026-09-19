<?php

declare(strict_types=1);

namespace Tests\Uat;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Tests\TestCase;

/**
 * Base for the acceptance suites.
 *
 * They run against the full demo dataset — every role, real queues, real
 * relationships — so the database is rebuilt and seeded once for the whole
 * suite, whatever ran before it (the Feature suite migrates without demo
 * data). Each test then runs inside a transaction that is rolled back, so the
 * cases stay independent.
 */
abstract class UatTestCase extends TestCase
{
    use RefreshDatabase;

    private static bool $seeded = false;

    protected function refreshTestDatabase(): void
    {
        if (! self::$seeded) {
            // Fast and offline: no photo downloads or image rendering.
            config(['veyra.seed.photos' => 'none', 'veyra.seed.scale' => 'tiny']);

            $this->artisan('migrate:fresh', ['--seed' => true]);
            $this->app[Kernel::class]->setArtisan(null);

            self::$seeded = true;
            RefreshDatabaseState::$migrated = true;
        }

        $this->beginDatabaseTransaction();
    }
}
