<?php

declare(strict_types=1);

namespace Database\Seeders;

use Database\Seeders\India\IndiaDemoSeeder;
use Database\Seeders\India\IndiaGeographySeeder;
use Illuminate\Database\Seeder;

/**
 * What `composer setup` and `migrate:fresh --seed` produce: the India install.
 *
 * Reference data, the India geography (Tamil Nadu shown at sign-up), and a
 * small Tamil Nadu demo population. `php artisan platform:seed-country india
 * --fresh` runs the same thing and is the command the README points at; the
 * worldwide GeographySeeder/StateSeeder and DemoDataSeeder's scale knobs are
 * kept for the test suite and for a demo that needs a large population.
 */
class DatabaseSeeder extends Seeder
{
    /** Reference data: everything else depends on it, and it is the only part also seeded in production. */
    public const REFERENCE = [
        PermissionSeeder::class,
        RoleSeeder::class,
        StaffSeeder::class,
        SettingSeeder::class,
        InterestSeeder::class,
        MasterSeeder::class,
        RiskFactorDefinitionSeeder::class,
        NotificationTemplateSeeder::class,
        SystemSeeder::class,
    ];

    public function run(): void
    {
        $this->call(self::REFERENCE);

        $this->call(IndiaGeographySeeder::class);

        // The demo population, so the console opens with something in it.
        // IndiaDemoSeeder refuses to run in production.
        $this->call(IndiaDemoSeeder::class);
    }
}
