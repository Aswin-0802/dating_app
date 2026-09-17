<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Appeal;
use App\Models\Ban;
use App\Models\ReportCase;
use App\Models\RiskScore;
use App\Models\Verification;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Checks the invariants the whole console rests on.
 *
 * Not a test — it runs against the real seeded database, which is where a
 * seeder bug would actually show up. `php artisan db:seed --class=VerifyInvariants`
 */
class VerifyInvariants extends Seeder
{
    public function run(): void
    {
        $failures = 0;

        $check = function (string $label, callable $actual, $expected = 0) use (&$failures): void {
            $value = $actual();
            $ok = $value === $expected;

            $this->command?->line(sprintf(
                '  %s  %-58s %s',
                $ok ? '<fg=green>PASS</>' : '<fg=red>FAIL</>',
                $label,
                $ok ? '' : "<fg=red>got {$value}, expected {$expected}</>",
            ));

            if (! $ok) {
                $failures++;
            }
        };

        $this->command?->newLine();
        $this->command?->info('Structural invariants');

        // A pair could otherwise match twice: (A,B) and (B,A) are different rows.
        $check(
            'matches respect the canonical id ordering',
            fn (): int => DB::table('matches')->whereColumn('app_user_one_id', '>=', 'app_user_two_id')->count(),
        );

        // A shadow ban with no review date is a permanent secret punishment.
        $check(
            'every shadow ban carries a review date',
            fn (): int => DB::table('bans')->where('type', 'shadow_ban')->whereNull('review_due_at')->count(),
        );

        // An appeal decided by its own decider is a rubber stamp.
        $check(
            'no appeal is assigned to its original decider',
            fn (): int => DB::table('appeals')->whereColumn('assigned_to_id', 'original_decider_id')->count(),
        );

        // Evidence belonging to somebody else makes a case unreviewable.
        $check(
            'report evidence belongs to the reported member',
            fn (): int => DB::table('reports as r')
                ->join('messages as m', 'm.id', '=', 'r.message_id')
                ->whereColumn('m.sender_app_user_id', '!=', 'r.reported_app_user_id')
                ->count(),
        );

        // The mirror is what the API reads; disagreement means the console says
        // "banned" while the API still lets somebody in.
        $check(
            'the enforcement mirror agrees with the bans table',
            fn (): int => DB::table('app_users as au')
                ->join('bans as b', 'b.id', '=', 'au.active_ban_id')
                ->whereNotNull('b.lifted_at')
                ->count(),
        );

        // A breakdown that does not sum to its score is worse than no breakdown.
        $check('risk factors sum to their stored score', function (): int {
            $mismatched = 0;

            RiskScore::query()
                ->where('is_current', true)
                ->with('factors')
                ->limit(1000)
                ->get()
                ->each(function (RiskScore $score) use (&$mismatched): void {
                    if (max(0, min(100, $score->factors->sum('points'))) !== $score->score) {
                        $mismatched++;
                    }
                });

            return $mismatched;
        });

        $check(
            'every member has a current risk score',
            fn (): int => DB::table('app_users')
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                    ->from('risk_scores')
                    ->whereColumn('risk_scores.app_user_id', 'app_users.id')
                    ->where('is_current', true))
                ->count(),
        );

        $check(
            'every report belongs to a case',
            fn (): int => DB::table('reports')->whereNull('report_case_id')->count(),
        );

        $this->command?->newLine();
        $this->command?->info('Queue health — these should be non-zero, or the console opens empty');

        foreach ([
            'open cases' => fn (): int => ReportCase::query()->open()->count(),
            'cases breaching SLA' => fn (): int => ReportCase::query()->breachingSla()->count(),
            'open verifications' => fn (): int => Verification::query()->open()->count(),
            'restricted-queue verifications' => fn (): int => DB::table('verifications')->where('queue', 'restricted_minor')->count(),
            'shadow bans overdue for review' => fn (): int => Ban::query()->reviewDue()->count(),
            'enforcement in force' => fn (): int => Ban::query()->active()->count(),
            'open appeals' => fn (): int => Appeal::query()->open()->count(),
            'accounts sharing a face' => fn (): int => DB::table('verifications')->where('duplicate_face_account_count', '>', 0)->count(),
        ] as $label => $count) {
            $value = $count();

            $this->command?->line(sprintf(
                '  %s  %-58s %s',
                $value > 0 ? '<fg=green>  OK</>' : '<fg=yellow>EMPTY</>',
                $label,
                number_format($value),
            ));

            if ($value === 0) {
                $failures++;
            }
        }

        $this->command?->newLine();

        $failures === 0
            ? $this->command?->info('All invariants hold.')
            : $this->command?->error("{$failures} checks failed.");
    }
}
