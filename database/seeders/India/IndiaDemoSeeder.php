<?php

declare(strict_types=1);

namespace Database\Seeders\India;

use App\Models\Plan;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Database\Seeder;

/**
 * A small, realistic Tamil Nadu member base on top of the India geography:
 * about 80 members in Chennai and the other focus cities, Tamil names, +91
 * numbers, rupee prices, Indian staff names — and everything the console
 * needs to open with something in it (matches, conversations, reports,
 * cases, verifications, subscriptions), produced by the same demo seeders
 * the worldwide dataset uses.
 *
 *   php artisan platform:seed-country india --fresh
 *
 * Every demo login uses the password "password". Not for production.
 */
class IndiaDemoSeeder extends Seeder
{
    public const MEMBERS = 80;

    /** Rupee prices for the two seeded plans: monthly, yearly. */
    private const PRICES = [
        'plus' => [299, 2499],
        'gold' => [599, 4999],
    ];

    /** Demo staff keep their emails (the manuals and tests use them) and get Indian names. */
    private const STAFF_NAMES = [
        'admin@demo.test' => 'Ananya Krishnan',
        'ops@demo.test' => 'Vikram Natarajan',
        'lead@demo.test' => 'Priya Raman',
        'senior1@demo.test' => 'Arun Sundaram',
        'senior2@demo.test' => 'Meera Iyer',
        'mod1@demo.test' => 'Karthik Selvaraj',
        'mod2@demo.test' => 'Divya Venkatesan',
        'mod3@demo.test' => 'Suresh Pillai',
        'mod4@demo.test' => 'Lakshmi Ganesan',
        'mod5@demo.test' => 'Rahul Sekar',
        'support1@demo.test' => 'Ravi Shah',
        'support2@demo.test' => 'Nithya Balaji',
        'analyst1@demo.test' => 'Sanjay Srinivasan',
        'analyst2@demo.test' => 'Kavitha Rajan',
    ];

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->warn('Refusing to seed demo data in production.');

            return;
        }

        // Rupees everywhere, before any order or subscription is written.
        Setting::put('billing.currency', 'INR');
        Setting::flush();

        foreach (self::PRICES as $slug => [$monthly, $yearly]) {
            Plan::query()->where('slug', $slug)->update(['monthly_price' => $monthly, 'yearly_price' => $yearly]);
        }

        foreach (self::STAFF_NAMES as $email => $name) {
            User::query()->where('email', $email)->update(['name' => $name]);
        }

        // The generic demo seeders, told to draw members from Tamil Nadu.
        config([
            'platform.seed.profile' => IndiaProfile::class,
            'platform.seed.members' => self::MEMBERS,
        ]);

        $this->call(DemoDataSeeder::class);

        $this->command?->info('India demo data complete: '.self::MEMBERS.' members in Tamil Nadu, prices in INR, every demo password is "password".');
    }
}
