<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Demo staff, one per role plus enough moderators to make the performance
 * scorecard and the appeal-routing rule meaningful.
 */
class StaffSeeder extends Seeder
{
    /** @return array<int, array{0: string, 1: string, 2: string, 3: string}> */
    public static function staff(): array
    {
        return [
            ['Ada Okonjo', 'admin@demo.test', Role::SUPER_ADMIN, 'Head of Platform'],
            ['Marcus Reid', 'ops@demo.test', Role::ADMIN, 'Operations Manager'],
            ['Priya Raman', 'lead@demo.test', Role::TS_LEAD, 'Trust & Safety Lead'],
            ['Tomas Alvarez', 'senior1@demo.test', Role::SENIOR_MODERATOR, 'Senior Moderator'],
            ['Hana Kobayashi', 'senior2@demo.test', Role::SENIOR_MODERATOR, 'Senior Moderator'],
            ['Ines Dubois', 'mod1@demo.test', Role::MODERATOR, 'Moderator'],
            ['Kwame Mensah', 'mod2@demo.test', Role::MODERATOR, 'Moderator'],
            ['Lena Fischer', 'mod3@demo.test', Role::MODERATOR, 'Moderator'],
            ['Diego Santos', 'mod4@demo.test', Role::MODERATOR, 'Moderator'],
            ['Amara Nwosu', 'mod5@demo.test', Role::MODERATOR, 'Moderator'],
            ['Ravi Shah', 'support1@demo.test', Role::SUPPORT, 'Support Specialist'],
            ['Chloe Martin', 'support2@demo.test', Role::SUPPORT, 'Support Specialist'],
            ['Jonas Berg', 'analyst1@demo.test', Role::ANALYST, 'Product Analyst'],
            ['Sofia Rossi', 'analyst2@demo.test', Role::ANALYST, 'Data Analyst'],
        ];
    }

    public function run(): void
    {
        $password = Hash::make('password');

        foreach (self::staff() as $index => [$name, $email, $roleName, $jobTitle]) {
            $user = User::query()->updateOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'username' => str($name)->lower()->replace(' ', '.')->toString(),
                    'password' => $password,
                    'job_title' => $jobTitle,
                    'status' => 'active',
                    'email_verified_at' => now(),
                    // Spread so the staff list does not look freshly minted.
                    'last_login_at' => now()->subHours(($index * 7) % 336),
                    'last_login_ip' => '127.0.0.1',
                ],
            );

            $user->syncRoles([$roleName]);
        }

        $this->command?->info('Seeded '.count(self::staff()).' staff accounts.');
    }
}
