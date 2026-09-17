<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Enums\AccountStatus;
use App\Enums\Gender;
use App\Enums\VerificationStatus;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Dating-specific analytics.
 *
 * The metrics here are deliberately not generic SaaS metrics. DAU and signups
 * tell you nothing about whether a dating marketplace is working; what matters
 * is whether people match, whether those matches produce conversations, whether
 * attention is distributed or hoarded, and whether the gender balance in a given
 * city makes matching possible at all.
 *
 * Results are cached because several of these aggregate hundreds of thousands
 * of rows, and none of them changes meaningfully minute to minute.
 */
final class MetricRollup
{
    private const TTL = 300;

    /**
     * Headline numbers for the overview.
     *
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        return Cache::remember('veyra.metrics.overview', self::TTL, function (): array {
            $members = DB::table('app_users')->whereNull('deleted_at');

            $active = (clone $members)->where('account_status', AccountStatus::Active->value)->count();
            $verified = (clone $members)->where('verification_status', VerificationStatus::Approved->value)->count();
            $total = (clone $members)->count();

            $matches = DB::table('matches')->count();
            $messaged = DB::table('matches')->whereNotNull('first_message_at')->count();
            $replied = DB::table('matches')->whereNotNull('first_reply_at')->count();

            $reports = DB::table('reports')->count();

            return [
                'total_members' => $total,
                'active_members' => $active,
                'verified_members' => $verified,
                'verification_coverage' => $total > 0 ? round($verified / $total * 100, 1) : 0.0,

                'matches' => $matches,
                'match_to_message' => $matches > 0 ? round($messaged / $matches * 100, 1) : 0.0,
                'message_to_reply' => $messaged > 0 ? round($replied / $messaged * 100, 1) : 0.0,

                // The earliest quality alarm there is. A rising rate means the
                // experience is degrading before churn shows it.
                'report_rate_per_1k_matches' => $matches > 0 ? round($reports / $matches * 1000, 1) : 0.0,

                'dau' => (clone $members)->where('last_active_at', '>=', now()->subDay())->count(),
                'mau' => (clone $members)->where('last_active_at', '>=', now()->subDays(30))->count(),

                'open_cases' => DB::table('report_cases')->whereIn('status', ['new', 'claimed', 'in_review'])->count(),
                'open_verifications' => DB::table('verifications')->whereIn('status', ['pending', 'in_review', 'escalated'])->count(),
                'active_bans' => DB::table('bans')
                    ->whereNull('lifted_at')
                    ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                    ->count(),
            ];
        });
    }

    /**
     * Signup → profile → verified → swipe → match → message → reply.
     *
     * Read from the milestone columns on app_users rather than derived at query
     * time; deriving it from swipes and messages would make this screen unusable.
     *
     * @return array<int, array{step: string, count: int, rate: float}>
     */
    public function funnel(): array
    {
        return Cache::remember('veyra.metrics.funnel', self::TTL, function (): array {
            $row = DB::table('app_users')->whereNull('deleted_at')->selectRaw('
                COUNT(*) as signed_up,
                SUM(profile_completed_at IS NOT NULL) as profile_complete,
                SUM(verified_at IS NOT NULL) as verified,
                SUM(first_swipe_at IS NOT NULL) as swiped,
                SUM(first_match_at IS NOT NULL) as matched,
                SUM(first_message_at IS NOT NULL) as messaged,
                SUM(first_reply_at IS NOT NULL) as replied
            ')->first();

            /*
             * Verification is deliberately NOT a step here.
             *
             * It is optional, so more people swipe than verify — putting it in
             * the chain makes the funnel appear to go back up and reads as a
             * bug. Verification coverage is reported separately, and its real
             * effect is visible in retentionByVerification().
             */
            $steps = [
                'Signed up' => (int) $row->signed_up,
                'Completed profile' => (int) $row->profile_complete,
                'First swipe' => (int) $row->swiped,
                'First match' => (int) $row->matched,
                'First message' => (int) $row->messaged,
                'Got a reply' => (int) $row->replied,
            ];

            $first = max(1, (int) $row->signed_up);
            $out = [];
            $previous = null;

            foreach ($steps as $label => $count) {
                $out[] = [
                    'step' => $label,
                    'count' => $count,
                    'rate' => round($count / $first * 100, 1),
                    // Step-to-step conversion matters more than the overall rate:
                    // it shows WHERE people fall out, not just how many.
                    'step_rate' => $previous === null ? 100.0 : round($count / max(1, $previous) * 100, 1),
                ];

                $previous = $count;
            }

            return $out;
        });
    }

    /**
     * Gender balance per city — the structural health metric.
     *
     * A city at 70/30 cannot produce matches for the majority side no matter how
     * good the product is. This is the number that decides where to spend
     * acquisition budget.
     *
     * @return Collection<int, object>
     */
    public function cityBalance(int $limit = 15): Collection
    {
        return Cache::remember("veyra.metrics.city-balance.{$limit}", self::TTL, function () use ($limit): Collection {
            return collect(DB::select(sprintf('
                SELECT c.name AS city,
                       co.iso2 AS country,
                       COUNT(*) AS members,
                       SUM(au.gender = \'%s\') AS men,
                       SUM(au.gender = \'%s\') AS women,
                       SUM(au.gender NOT IN (\'%s\', \'%s\')) AS other
                FROM app_users au
                JOIN cities c ON c.id = au.city_id
                JOIN countries co ON co.id = c.country_id
                WHERE au.deleted_at IS NULL
                GROUP BY c.id, c.name, co.iso2
                HAVING members >= 20
                ORDER BY members DESC
                LIMIT %d
            ',
                Gender::Man->value,
                Gender::Woman->value,
                Gender::Man->value,
                Gender::Woman->value,
                $limit,
            )))->map(function (object $row): object {
                $gendered = max(1, (int) $row->men + (int) $row->women);
                $row->man_share = round((int) $row->men / $gendered * 100, 1);
                $row->skew = abs($row->man_share - 50);

                // Anything past 60/40 starts to bite; past 65/35 the minority
                // side is drowning in attention and the majority sees nobody.
                $row->health = match (true) {
                    $row->skew >= 15 => 'critical',
                    $row->skew >= 8 => 'warning',
                    default => 'healthy',
                };

                return $row;
            });
        });
    }

    /**
     * How concentrated attention is.
     *
     * Under a uniform model the top decile would receive 10% of likes. The real
     * figure is always far higher, and the gap is what predicts churn among
     * everyone else.
     *
     * @return array<string, float|int>
     */
    public function attentionConcentration(): array
    {
        return Cache::remember('veyra.metrics.concentration', self::TTL, function (): array {
            $counts = DB::table('swipes')
                ->whereIn('action', ['like', 'superlike'])
                ->selectRaw('target_app_user_id, COUNT(*) as c')
                ->groupBy('target_app_user_id')
                ->orderByDesc('c')
                ->pluck('c')
                ->all();

            $total = array_sum($counts);
            $population = count($counts);

            if ($total === 0 || $population === 0) {
                return ['top_decile_share' => 0.0, 'top_quartile_share' => 0.0, 'median_likes' => 0];
            }

            $decile = (int) ceil($population * 0.1);
            $quartile = (int) ceil($population * 0.25);

            return [
                'top_decile_share' => round(array_sum(array_slice($counts, 0, $decile)) / $total * 100, 1),
                'top_quartile_share' => round(array_sum(array_slice($counts, 0, $quartile)) / $total * 100, 1),
                'median_likes' => $counts[(int) floor($population / 2)] ?? 0,
                'population' => $population,
            ];
        });
    }

    /**
     * Retention split by verification status.
     *
     * The business case for stricter verification lives or dies on this chart,
     * which is why the two series are computed the same way over the same cohort.
     *
     * Members are classified by their CURRENT verification status, not their
     * status at signup — almost nobody is verified on day one, so the alternative
     * would leave the verified series empty.
     *
     * @return array<string, array<string, float>>
     */
    public function retentionByVerification(): array
    {
        return Cache::remember('veyra.metrics.retention', self::TTL, function (): array {
            $out = [];

            foreach (['verified', 'unverified'] as $segment) {
                $base = DB::table('app_users')
                    ->whereNull('deleted_at')
                    ->where('created_at', '<=', now()->subDays(30));

                $segment === 'verified'
                    ? $base->where('verification_status', VerificationStatus::Approved->value)
                    : $base->where('verification_status', '!=', VerificationStatus::Approved->value);

                $cohort = (clone $base)->count();

                if ($cohort === 0) {
                    $out[$segment] = ['d1' => 0.0, 'd7' => 0.0, 'd30' => 0.0, 'cohort' => 0];

                    continue;
                }

                $out[$segment] = [
                    'cohort' => $cohort,
                    'd1' => $this->retainedAfter($base, 1, $cohort),
                    'd7' => $this->retainedAfter($base, 7, $cohort),
                    'd30' => $this->retainedAfter($base, 30, $cohort),
                ];
            }

            return $out;
        });
    }

    private function retainedAfter($base, int $days, int $cohort): float
    {
        $retained = (clone $base)
            ->whereRaw('last_active_at >= DATE_ADD(created_at, INTERVAL ? DAY)', [$days])
            ->count();

        return round($retained / max(1, $cohort) * 100, 1);
    }

    /**
     * Safety load over time: reports filed and enforcement issued per day.
     *
     * @return array<string, array<int, array{date: string, value: int}>>
     */
    public function safetyTrend(int $days = 60): array
    {
        return Cache::remember("veyra.metrics.safety.{$days}", self::TTL, function () use ($days): array {
            $since = now()->subDays($days)->startOfDay();

            $series = fn (string $table, string $column) => DB::table($table)
                ->where($column, '>=', $since)
                ->selectRaw("DATE({$column}) as d, COUNT(*) as c")
                ->groupBy('d')
                ->pluck('c', 'd')
                ->all();

            $reports = $series('reports', 'created_at');
            $actions = $series('moderation_actions', 'created_at');

            $dates = [];
            for ($i = $days; $i >= 0; $i--) {
                $dates[] = now()->subDays($i)->toDateString();
            }

            return [
                'dates' => $dates,
                'reports' => array_map(fn (string $d): int => (int) ($reports[$d] ?? 0), $dates),
                'actions' => array_map(fn (string $d): int => (int) ($actions[$d] ?? 0), $dates),
            ];
        });
    }

    /**
     * Signups per day, for the growth chart.
     *
     * @return array<string, array<int, mixed>>
     */
    public function signupTrend(int $days = 90): array
    {
        return Cache::remember("veyra.metrics.signups.{$days}", self::TTL, function () use ($days): array {
            $since = now()->subDays($days)->startOfDay();

            $rows = DB::table('app_users')
                ->where('created_at', '>=', $since)
                ->whereNull('deleted_at')
                ->selectRaw('DATE(created_at) as d, COUNT(*) as c')
                ->groupBy('d')
                ->pluck('c', 'd')
                ->all();

            $dates = [];
            for ($i = $days; $i >= 0; $i--) {
                $dates[] = now()->subDays($i)->toDateString();
            }

            return [
                'dates' => $dates,
                'signups' => array_map(fn (string $d): int => (int) ($rows[$d] ?? 0), $dates),
            ];
        });
    }

    /**
     * Whether new members get anywhere in their first 48 hours.
     *
     * Cold start is where dating apps lose people: somebody who gets no match in
     * two days rarely comes back, and no amount of later polish recovers them.
     */
    public function coldStart(): array
    {
        return Cache::remember('veyra.metrics.cold-start', self::TTL, function (): array {
            $recent = DB::table('app_users')
                ->whereNull('deleted_at')
                ->where('created_at', '>=', now()->subDays(60));

            $total = (clone $recent)->count();

            $matchedFast = (clone $recent)
                ->whereNotNull('first_match_at')
                ->whereRaw('first_match_at <= DATE_ADD(created_at, INTERVAL 48 HOUR)')
                ->count();

            return [
                'cohort' => $total,
                'matched_in_48h' => $matchedFast,
                'rate' => $total > 0 ? round($matchedFast / $total * 100, 1) : 0.0,
            ];
        });
    }

    public function flush(): void
    {
        foreach ([
            'overview', 'funnel', 'concentration', 'retention', 'cold-start',
        ] as $key) {
            Cache::forget("veyra.metrics.{$key}");
        }
    }
}
