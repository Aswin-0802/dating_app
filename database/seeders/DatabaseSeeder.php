<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Reference data. Everything below depends on it, and it is the only
        // part that would also be seeded in production.
        $this->call([
            PermissionSeeder::class,
            RoleSeeder::class,
            StaffSeeder::class,
            SettingSeeder::class,
            GeographySeeder::class,
            InterestSeeder::class,
            RiskFactorDefinitionSeeder::class,
            NotificationTemplateSeeder::class,
        ]);

        /*
         * The demo population.
         *
         * Included here so `composer setup` produces a console with something in
         * it — an admin panel seeded with nothing but an empty schema cannot be
         * evaluated, and every screen would open on its empty state.
         *
         * DemoDataSeeder refuses to run in production, and VEYRA_SEED_SCALE=small
         * cuts it to a tenth for a fast local rebuild.
         */
        $this->call(DemoDataSeeder::class);
    }
}
