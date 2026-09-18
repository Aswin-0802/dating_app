<?php

declare(strict_types=1);

namespace Database\Seeders;

use Database\Seeders\Demo\AppUserSeeder;
use Database\Seeders\Demo\AuditTrailSeeder;
use Database\Seeders\Demo\BehaviourSeeder;
use Database\Seeders\Demo\NotificationSeeder;
use Database\Seeders\Demo\PhotoSeeder;
use Database\Seeders\Demo\RiskSeeder;
use Database\Seeders\Demo\SystemLogSeeder;
use Database\Seeders\Demo\TrustAndSafetySeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The demo dataset.
 *
 * Split from DatabaseSeeder so reference data (permissions, roles, settings)
 * can be reseeded without regenerating 12,000 members and 38,000 images.
 *
 * Scale is set by VEYRA_SEED_SCALE. `tiny` is the default: a 50-member
 * population, which is what you want when the dataset is there to exercise the
 * UI rather than to demo it.
 *
 * Volume that is not derived from the member count — delivery logs, sign-ins,
 * reports — carries its own floor, so the screens that read those tables still
 * open with rows in them at 50 members. Below roughly that point the matching
 * graph is the binding constraint: mutual likes need a pool to draw from.
 */
class DemoDataSeeder extends Seeder
{
    /**
     * Member count per scale.
     *
     * Declared as a population rather than a multiplier because that is the
     * number anybody actually reasons about, and because `tiny` is far enough
     * below `demo` that a fraction of it reads as a rounding error.
     */
    private const SCALES = [
        'tiny' => 50,
        'small' => 1_200,
        'demo' => 12_000,
        'large' => 48_000,
    ];

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->warn('Refusing to seed demo data in production.');

            return;
        }

        $members = self::SCALES[config('veyra.seed.scale', 'tiny')] ?? self::SCALES['demo'];

        // Everything downstream sizes itself relative to the reference dataset,
        // so the population stays the only number that has to be set.
        $scale = $members / self::SCALES['demo'];

        $this->command?->info("Seeding demo data: {$members} members.");

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
         * graph, risk reads from every table above it, and the audit trail is
         * derived from decisions all of them have already written — so it runs
         * last of all.
         */
        $this->runSeeder(new AppUserSeeder($members));
        $this->runSeeder(new PhotoSeeder);
        $this->runSeeder(new BehaviourSeeder($scale));
        $this->runSeeder(new TrustAndSafetySeeder($scale));
        $this->runSeeder(new RiskSeeder);
        $this->runSeeder(new NotificationSeeder($scale));
        $this->runSeeder(new SystemLogSeeder($scale));
        $this->runSeeder(new AuditTrailSeeder);

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
