<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Reference data first: everything below depends on it.
        $this->call([
            PermissionSeeder::class,
            RoleSeeder::class,
            StaffSeeder::class,
            SettingSeeder::class,
            GeographySeeder::class,
        ]);
    }
}
