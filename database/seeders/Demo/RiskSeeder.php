<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Enums\RiskBand;
use App\Models\AppUser;
use App\Services\Risk\RiskEngine;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Risk scores, produced by actually running the engine.
 *
 * Nothing here invents a number. Assigning random scores would make every
 * breakdown a lie — the factors would not sum to the score, and the first
 * moderator to check the arithmetic would stop trusting the column.
 */
class RiskSeeder extends Seeder
{
    public function run(): void
    {
        $engine = new RiskEngine;

        $this->command?->info('Computing bulk risk context…');
        $context = $engine->bulkContext();

        $total = AppUser::query()->count();
        $bar = $this->command?->getOutput()->createProgressBar($total);
        $bar?->start();

        $scoreRows = [];
        $factorRows = [];
        $mirror = [];
        $nextScoreId = ((int) DB::table('risk_scores')->max('id')) + 1;

        AppUser::query()->chunkById(500, function ($members) use (
            $engine, $context, &$scoreRows, &$factorRows, &$mirror, &$nextScoreId, $bar
        ): void {
            foreach ($members as $member) {
                $factors = $engine->evaluate($member, $context[$member->id] ?? []);
                $points = array_sum(array_column($factors, 'points'));
                $total = max(0, min(100, (int) round($points)));
                $band = RiskBand::fromScore($total);

                $scoreId = $nextScoreId++;

                $scoreRows[] = [
                    'id' => $scoreId,
                    'app_user_id' => $member->id,
                    'score' => $total,
                    'band' => $band->value,
                    'computed_at' => now(),
                    'is_current' => true,
                ];

                foreach ($factors as $factor) {
                    $factorRows[] = [
                        'risk_score_id' => $scoreId,
                        'definition_key' => $factor['definition_key'],
                        'label' => $factor['label'],
                        'points' => $factor['points'],
                        'evidence' => $factor['evidence'] ? json_encode($factor['evidence']) : null,
                    ];
                }

                $mirror[] = ['id' => $member->id, 'score' => $total, 'band' => $band->value];

                $bar?->advance();
            }

            $this->flush($scoreRows, $factorRows, $mirror);
        });

        $this->flush($scoreRows, $factorRows, $mirror, true);

        $bar?->finish();
        $this->command?->newLine(2);

        $distribution = DB::table('app_users')
            ->select('risk_band', DB::raw('count(*) as c'))
            ->groupBy('risk_band')
            ->pluck('c', 'risk_band')
            ->all();

        $this->command?->info('Risk bands: '.json_encode($distribution));
    }

    private function flush(array &$scores, array &$factors, array &$mirror, bool $force = false): void
    {
        if (! $force && count($scores) < 500) {
            return;
        }

        if ($scores !== []) {
            DB::table('risk_scores')->insert($scores);
        }

        foreach (array_chunk($factors, 1000) as $chunk) {
            DB::table('risk_factors')->insert($chunk);
        }

        // One CASE statement rather than a query per member.
        if ($mirror !== []) {
            $ids = array_column($mirror, 'id');
            $scoreCase = 'CASE id';
            $bandCase = 'CASE id';

            foreach ($mirror as $row) {
                $scoreCase .= " WHEN {$row['id']} THEN {$row['score']}";
                $bandCase .= " WHEN {$row['id']} THEN '{$row['band']}'";
            }

            DB::table('app_users')->whereIn('id', $ids)->update([
                'risk_score' => DB::raw($scoreCase.' END'),
                'risk_band' => DB::raw($bandCase.' END'),
                'risk_calculated_at' => now(),
            ]);
        }

        $scores = $factors = $mirror = [];
    }
}
