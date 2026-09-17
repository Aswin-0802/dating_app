<?php

declare(strict_types=1);

namespace App\Services\Risk;

use App\Enums\AccountStatus;
use App\Enums\RiskBand;
use App\Enums\VerificationStatus;
use App\Models\AppUser;
use App\Models\RiskFactorDefinition;
use App\Models\RiskScore;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Computes a member's 0-100 risk score.
 *
 * The contract that makes this defensible: every contributing factor is STORED
 * alongside the score. The UI renders those stored rows, so the breakdown always
 * sums to the number on the badge — including for a score computed months ago
 * under weights that have since been retuned. A score a moderator cannot explain
 * is a score they will ignore, and one that cannot be justified in an appeal.
 */
final class RiskEngine
{
    /** @var Collection<string, RiskFactorDefinition>|null */
    private ?Collection $definitions = null;

    /**
     * Score one member and persist the result.
     *
     * @param  array<string, mixed>  $context  pre-computed aggregates, so a bulk
     *                                         run can avoid per-member queries
     */
    public function score(AppUser $appUser, array $context = []): RiskScore
    {
        $factors = $this->evaluate($appUser, $context);

        // Clamped: the factors are additive and unbounded, but the band
        // thresholds and the badge both assume 0-100.
        $total = max(0, min(100, (int) round(array_sum(array_column($factors, 'points')))));
        $band = RiskBand::fromScore($total);

        return DB::transaction(function () use ($appUser, $factors, $total, $band): RiskScore {
            // History is kept; only one row is current.
            RiskScore::query()
                ->where('app_user_id', $appUser->id)
                ->where('is_current', true)
                ->update(['is_current' => false]);

            $score = RiskScore::query()->create([
                'app_user_id' => $appUser->id,
                'score' => $total,
                'band' => $band,
                'computed_at' => now(),
                'is_current' => true,
            ]);

            if ($factors !== []) {
                $score->factors()->createMany($factors);
            }

            // Mirror onto app_users for the list screens and the queue sort,
            // which cannot afford a join per row.
            $appUser->forceFill([
                'risk_score' => $total,
                'risk_band' => $band,
                'risk_calculated_at' => now(),
            ])->saveQuietly();

            return $score;
        });
    }

    /**
     * Which factors apply, and what each contributes.
     *
     * @param  array<string, mixed>  $context
     * @return array<int, array{definition_key: string, label: string, points: int, evidence: array<string, mixed>|null}>
     */
    public function evaluate(AppUser $appUser, array $context = []): array
    {
        $definitions = $this->definitions();
        $factors = [];

        $add = function (string $key, ?array $evidence = null) use (&$factors, $definitions): void {
            $definition = $definitions->get($key);

            if ($definition === null || ! $definition->is_active) {
                return;
            }

            $factors[] = [
                'definition_key' => $definition->key,
                'label' => $definition->label,
                'points' => $definition->points,
                'evidence' => $evidence,
            ];
        };

        $ageHours = $appUser->created_at?->diffInHours(now()) ?? 0;
        $ageDays = $ageHours / 24;

        // ---- tenure ----
        if ($ageHours < 24) {
            $add('new_account_24h', ['age_hours' => round($ageHours, 1)]);
        } elseif ($ageDays < 7) {
            $add('new_account_7d', ['age_days' => round($ageDays, 1)]);
        }

        // ---- identity ----
        $duplicateFaces = $context['duplicate_face_accounts'] ?? 0;
        if ($duplicateFaces > 0) {
            $add('duplicate_face_detected', ['other_accounts' => $duplicateFaces]);
        }

        $duplicatePhotos = $context['duplicate_photo_accounts'] ?? 0;
        if ($duplicatePhotos > 0) {
            $add('duplicate_photo_hash', ['other_accounts' => $duplicatePhotos]);
        }

        $sharedDevices = $context['shared_device_accounts'] ?? 0;
        if ($sharedDevices > 0) {
            $add('device_shared_with_banned_account', ['other_accounts' => $sharedDevices]);
        }

        if ($this->hasDisposableEmail($appUser->email)) {
            $add('disposable_email_domain', ['domain' => substr(strrchr($appUser->email, '@') ?: '', 1)]);
        }

        // ---- reports ----
        $recentReports = $context['reports_7d'] ?? 0;
        if ($recentReports >= 2) {
            $add('multiple_reports_7d', ['count' => $recentReports]);
        }

        if (($context['credible_reporter'] ?? false) === true) {
            $add('report_by_high_credibility_reporter');
        }

        // ---- behaviour ----
        $blockedBy = $context['blocked_by'] ?? 0;
        if ($blockedBy >= 5) {
            $add('blocked_by_many', ['blocked_by' => $blockedBy]);
        }

        if (($context['off_platform_opener'] ?? 0) > 0) {
            $add('off_platform_link_first_message', ['messages' => $context['off_platform_opener']]);
        }

        if (($context['bio_contains_contact'] ?? false) === true) {
            $add('contact_info_in_bio');
        }

        $swipes = $context['swipes'] ?? 0;
        if ($swipes > 400) {
            $add('swipe_velocity_outlier', ['swipes' => $swipes]);
        }

        $messages = $context['messages'] ?? 0;
        if ($messages > 300) {
            $add('message_velocity_outlier', ['messages' => $messages]);
        }

        // ---- content ----
        if (($context['nudity_flagged_photos'] ?? 0) > 0) {
            $add('nudity_label_high', ['photos' => $context['nudity_flagged_photos']]);
        }

        // ---- history ----
        if (($context['prior_enforcements'] ?? 0) > 0) {
            $add('prior_enforcement', ['count' => $context['prior_enforcements']]);
        }

        // ---- profile ----
        $photoCount = $context['photos'] ?? 0;
        if ($photoCount === 0) {
            $add('no_photos');
        }

        if ($appUser->profile_completion < 40) {
            $add('profile_incomplete', ['completion' => $appUser->profile_completion]);
        }

        $isVerified = $appUser->verification_status === VerificationStatus::Approved;

        if (! $isVerified && $ageDays > 30) {
            $add('unverified_after_30d', ['age_days' => (int) $ageDays]);
        }

        // ---- mitigating ----
        if ($isVerified) {
            $add('photo_verified');
        }

        if ($ageDays > 180) {
            $add('account_age_over_180d', ['age_days' => (int) $ageDays]);
        }

        if (($context['reply_rate'] ?? 0) > 0.6) {
            $add('high_reply_rate', ['reply_rate' => round((float) $context['reply_rate'], 2)]);
        }

        if ($appUser->is_premium) {
            $add('premium_subscriber');
        }

        return $factors;
    }

    /**
     * Whether the score justifies automatic action, and which.
     *
     * Automation never bans outright: the strongest automatic outcome is a
     * feature limit, and everything beyond that needs a human to look.
     */
    public function recommendedAction(int $score): ?string
    {
        $limitAt = (int) veyra_setting('risk.auto_limit_at', config('veyra.risk.auto_limit_at', 75));
        $queueAt = (int) veyra_setting('risk.auto_queue_at', config('veyra.risk.auto_queue_at', 50));

        return match (true) {
            $score >= $limitAt => 'feature_limit',
            $score >= $queueAt => 'queue_for_review',
            default => null,
        };
    }

    /** @return Collection<string, RiskFactorDefinition> */
    private function definitions(): Collection
    {
        return $this->definitions ??= RiskFactorDefinition::query()->get()->keyBy('key');
    }

    private function hasDisposableEmail(string $email): bool
    {
        static $domains = [
            'mailinator.com', 'guerrillamail.com', '10minutemail.com', 'tempmail.com',
            'throwaway.email', 'yopmail.com', 'trashmail.com', 'sharklasers.com',
        ];

        $domain = strtolower(substr(strrchr($email, '@') ?: '', 1));

        return in_array($domain, $domains, true);
    }

    /**
     * Aggregates for a whole population in a handful of queries.
     *
     * Scoring 12,000 members one at a time would be tens of thousands of
     * queries; this makes a full recompute a few seconds.
     *
     * @return array<int, array<string, mixed>> keyed by app_user_id
     */
    public function bulkContext(): array
    {
        $context = [];

        $merge = function (array $rows, string $key) use (&$context): void {
            foreach ($rows as $id => $value) {
                $context[$id][$key] = $value;
            }
        };

        $merge(
            DB::table('photos')->select('app_user_id', DB::raw('count(*) as c'))
                ->whereNull('deleted_at')->groupBy('app_user_id')->pluck('c', 'app_user_id')->all(),
            'photos',
        );

        $merge(
            DB::table('swipes')->select('app_user_id', DB::raw('count(*) as c'))
                ->groupBy('app_user_id')->pluck('c', 'app_user_id')->all(),
            'swipes',
        );

        $merge(
            DB::table('messages')->select('sender_app_user_id', DB::raw('count(*) as c'))
                ->groupBy('sender_app_user_id')->pluck('c', 'sender_app_user_id')->all(),
            'messages',
        );

        $merge(
            DB::table('blocks')->select('blocked_app_user_id', DB::raw('count(*) as c'))
                ->groupBy('blocked_app_user_id')->pluck('c', 'blocked_app_user_id')->all(),
            'blocked_by',
        );

        $merge(
            DB::table('reports')->select('reported_app_user_id', DB::raw('count(*) as c'))
                ->where('created_at', '>=', now()->subDays(7))
                ->groupBy('reported_app_user_id')->pluck('c', 'reported_app_user_id')->all(),
            'reports_7d',
        );

        $merge(
            DB::table('moderation_actions')->select('subject_app_user_id', DB::raw('count(*) as c'))
                ->groupBy('subject_app_user_id')->pluck('c', 'subject_app_user_id')->all(),
            'prior_enforcements',
        );

        $merge(
            DB::table('messages')->select('sender_app_user_id', DB::raw('count(*) as c'))
                ->where(fn ($q) => $q->where('contains_link', true)->orWhere('contains_contact_info', true))
                ->groupBy('sender_app_user_id')->pluck('c', 'sender_app_user_id')->all(),
            'off_platform_opener',
        );

        $merge(
            DB::table('profiles')->where('bio_contains_contact', true)
                ->pluck('bio_contains_contact', 'app_user_id')->all(),
            'bio_contains_contact',
        );

        $merge(
            DB::table('photos')
                ->select('app_user_id', DB::raw('count(*) as c'))
                ->whereRaw("JSON_EXTRACT(moderation_labels, '$.nudity') > 0.7")
                ->groupBy('app_user_id')->pluck('c', 'app_user_id')->all(),
            'nudity_flagged_photos',
        );

        // Faces seen on more than one account. The subquery finds signatures
        // shared by several members; the outer join maps them back per member.
        $merge(
            DB::table('photos as p')
                ->join(DB::raw('(
                    SELECT face_signature, COUNT(DISTINCT app_user_id) AS accounts
                    FROM photos
                    WHERE face_signature IS NOT NULL AND deleted_at IS NULL
                    GROUP BY face_signature
                    HAVING accounts > 1
                ) dup'), 'dup.face_signature', '=', 'p.face_signature')
                ->select('p.app_user_id', DB::raw('MAX(dup.accounts) - 1 as c'))
                ->groupBy('p.app_user_id')
                ->pluck('c', 'app_user_id')->all(),
            'duplicate_face_accounts',
        );

        $merge(
            DB::table('photos as p')
                ->join(DB::raw('(
                    SELECT phash, COUNT(DISTINCT app_user_id) AS accounts
                    FROM photos
                    WHERE phash IS NOT NULL AND deleted_at IS NULL
                    GROUP BY phash
                    HAVING accounts > 1
                ) dup'), 'dup.phash', '=', 'p.phash')
                ->select('p.app_user_id', DB::raw('MAX(dup.accounts) - 1 as c'))
                ->groupBy('p.app_user_id')
                ->pluck('c', 'app_user_id')->all(),
            'duplicate_photo_accounts',
        );

        // Devices shared with an account that is currently banned.
        $merge(
            DB::table('devices as d')
                ->join('devices as other', function ($join): void {
                    $join->on('other.fingerprint_hash', '=', 'd.fingerprint_hash')
                        ->whereColumn('other.app_user_id', '!=', 'd.app_user_id');
                })
                ->join('app_users as au', 'au.id', '=', 'other.app_user_id')
                ->where('au.account_status', AccountStatus::Banned->value)
                ->select('d.app_user_id', DB::raw('COUNT(DISTINCT other.app_user_id) as c'))
                ->groupBy('d.app_user_id')
                ->pluck('c', 'app_user_id')->all(),
            'shared_device_accounts',
        );

        return $context;
    }
}
