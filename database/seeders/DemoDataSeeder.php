<?php

declare(strict_types=1);

namespace Database\Seeders;

use Database\Seeders\Demo\AppUserSeeder;
use Database\Seeders\Demo\BehaviourSeeder;
use Database\Seeders\Demo\NotificationSeeder;
use Database\Seeders\Demo\PhotoSeeder;
use Database\Seeders\Demo\RiskSeeder;
use Database\Seeders\Demo\TrustAndSafetySeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The demo dataset.
 *
 * Split from DatabaseSeeder so reference data (permissions, roles, settings)
 * can be reseeded without regenerating 12,000 members and 38,000 images.
 *
 * Scale is set by VEYRA_SEED_SCALE so a developer can get a working database in
 * about thirty seconds with `small`.
 */
class DemoDataSeeder extends Seeder
{
    private const SCALES = [
        'small' => 0.1,
        'demo' => 1.0,
        'large' => 4.0,
    ];

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->warn('Refusing to seed demo data in production.');

            return;
        }

        $scale = self::SCALES[config('veyra.seed.scale', 'demo')] ?? 1.0;
        $members = (int) round(12_000 * $scale);

        $this->command?->info("Seeding demo data at scale {$scale} ({$members} members)…");

        // Model events and the query log are pure overhead for bulk inserts, and
        // the observers would fire tens of thousands of times for no benefit.
        DB::disableQueryLog();

        $this->callSilent(InterestSeeder::class);
        $this->callSilent(RiskFactorDefinitionSeeder::class);

        /*
         * Instantiated directly rather than via call(), because these take
         * constructor arguments (the scaled row counts).
         *
         * Order matters: trust & safety anchors its evidence to the behaviour
         * graph, and risk reads from every table above it — so risk runs last
         * and its scores reflect the whole dataset.
         */
        $this->runSeeder(new AppUserSeeder($members));
        $this->runSeeder(new PhotoSeeder);
        $this->runSeeder(new BehaviourSeeder($scale));
        $this->runSeeder(new TrustAndSafetySeeder($scale));
        $this->runSeeder(new RiskSeeder);
        $this->runSeeder(new NotificationSeeder($scale));

        $this->command?->info('Demo data complete.');
    }

    private function runSeeder(Seeder $seeder): void
    {
        $seeder->setContainer($this->container);

        if ($this->command !== null) {
            $seeder->setCommand($this->command);
        }

        $seeder->__invoke();
    }
}
