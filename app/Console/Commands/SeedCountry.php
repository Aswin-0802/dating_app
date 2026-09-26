<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Database\Seeders\DatabaseSeeder;
use Database\Seeders\India\IndiaDemoSeeder;
use Database\Seeders\India\IndiaGeographySeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Stand the platform up for one country in one command.
 *
 *   php artisan platform:seed-country india --fresh
 *
 * --fresh drops and rebuilds every table (migrate:fresh), then seeds the
 * reference data (permissions, roles, demo staff, settings, interests,
 * masters, templates, gateways), that country's geography, and — unless
 * --no-demo — its demo members. Without --fresh it refuses to run on a
 * database that already has members, so it can never be run into a live
 * installation by accident.
 *
 * Adding a country means adding a folder like database/seeders/India with a
 * geography seeder (and, if wanted, a demo seeder) and listing it in
 * COUNTRIES below; the README and the user manual walk through it.
 */
class SeedCountry extends Command
{
    protected $signature = 'platform:seed-country
        {country : Which country to set up, e.g. india}
        {--fresh : Drop and rebuild every table first}
        {--no-demo : Geography and reference data only, no demo members}';

    protected $description = 'Rebuild the database for one country: reference data, its geography, and its demo members';

    /** country => [geography seeder, demo seeder] */
    private const COUNTRIES = [
        'india' => [IndiaGeographySeeder::class, IndiaDemoSeeder::class],
    ];

    public function handle(): int
    {
        $country = strtolower((string) $this->argument('country'));

        if (! isset(self::COUNTRIES[$country])) {
            $this->error("No seeders for '{$country}'. Known: ".implode(', ', array_keys(self::COUNTRIES)).'.');

            return self::FAILURE;
        }

        if (app()->environment('production') && ! $this->option('no-demo')) {
            $this->error('Demo members are never seeded in production. Run with --no-demo.');

            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            $this->call('migrate:fresh', ['--force' => true]);
        } elseif (DB::table('app_users')->exists()) {
            $this->error('This database already has members. Run with --fresh to rebuild it, or seed the geography alone with db:seed --class.');

            return self::FAILURE;
        }

        [$geography, $demo] = self::COUNTRIES[$country];

        foreach (DatabaseSeeder::REFERENCE as $seeder) {
            $this->call('db:seed', ['--class' => $seeder, '--force' => true]);
        }

        $this->call('db:seed', ['--class' => $geography, '--force' => true]);

        if (! $this->option('no-demo')) {
            $this->call('db:seed', ['--class' => $demo, '--force' => true]);
        }

        $this->info(ucfirst($country).' is set up. Console: admin@demo.test / password.');

        return self::SUCCESS;
    }
}
