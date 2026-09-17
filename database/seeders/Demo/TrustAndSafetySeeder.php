<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Enums\LadderStep;
use App\Enums\ReasonCode;
use App\Enums\ReportCategory;
use App\Enums\Severity;
use App\Services\Media\PlaceholderPhotoGenerator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Verifications, reports, cases, enforcement and appeals.
 *
 * Several distributions here exist purely so the console opens with something
 * to act on rather than a wall of green:
 *
 *  - ~14% of pending verifications and ~9% of open cases are seeded past SLA,
 *    so the red pills are visible on first load rather than only in theory;
 *  - around a third of shadow bans are overdue for review, which is the entire
 *    point of that queue existing;
 *  - open items are dated relative to their own SLA window, so a critical case
 *    with a one-hour window is not automatically breaching.
 */
class TrustAndSafetySeeder extends Seeder
{
    public function __construct(private readonly float $scale = 1.0) {}

    public function run(): void
    {
        $faker = fake();
        $faker->seed(config('veyra.seed.faker_seed', 20260917) + 3);

        $staff = DB::table('users')->pluck('id')->all();
        $moderators = array_slice($staff, 3);

        $verifications = $this->seedVerifications($faker, $staff);
        $this->command?->info("Seeded {$verifications} verifications.");

        $reports = $this->seedReports($faker);
        $this->command?->info("Seeded {$reports} reports.");

        $cases = $this->aggregateCases();
        $this->command?->info("Aggregated {$cases} cases from those reports.");

        $actions = $this->seedEnforcement($faker, $moderators);
        $this->command?->info("Seeded {$actions} moderation actions and their bans.");

        $appeals = $this->seedAppeals($faker, $staff);
        $this->command?->info("Seeded {$appeals} appeals.");
    }

    // ---------------------------------------------------------------- verification

    private function seedVerifications($faker, array $staff): int
    {
        $members = DB::table('app_users')
            ->select('id', 'verification_status', 'birthdate', 'created_at')
            ->whereIn('verification_status', ['approved', 'pending', 'rejected', 'expired'])
            ->get();

        // Faces already shared across accounts, from the planted photo rings.
        $duplicateFaceCounts = DB::table('photos as p')
            ->join(DB::raw('(
                SELECT face_signature, COUNT(DISTINCT app_user_id) AS accounts
                FROM photos WHERE face_signature IS NOT NULL GROUP BY face_signature
                HAVING accounts > 1
            ) dup'), 'dup.face_signature', '=', 'p.face_signature')
            ->select('p.app_user_id', DB::raw('MAX(dup.accounts) - 1 as c'))
            ->groupBy('p.app_user_id')
            ->pluck('c', 'app_user_id')
            ->all();

        $slaHours = (int) config('veyra.sla.verification_hours', 24);
        $rows = [];

        $total = 0;

        foreach ($members as $member) {
            $attempts = $faker->boolean(14) ? ($faker->boolean(20) ? 3 : 2) : 1;

            for ($attempt = 1; $attempt <= $attempts; $attempt++) {
                $isFinal = $attempt === $attempts;

                $status = $isFinal
                    ? $this->statusFor($member->verification_status, $faker)
                    : 'rejected';

                $trueAge = Carbon::parse($member->birthdate)->diffInYears(now());
                $duplicates = (int) ($duplicateFaceCounts[$member->id] ?? 0);

                // 4% get a conflicting age estimate. Those become the restricted
                // minor-safety queue, which Approve is disabled for entirely.
                $conflictingAge = $faker->boolean(4);
                $minorSuspected = $conflictingAge && $trueAge < 30;

                $approved = $status === 'approved';
                $matchScore = $approved
                    ? min(1, max(0, $faker->randomFloat(3, 0.88, 0.99)))
                    : min(1, max(0, $faker->randomFloat(3, 0.20, 0.62)));

                $submittedAt = Carbon::parse($member->created_at)
                    ->addHours($faker->numberBetween(1, 240));

                $isOpen = in_array($status, ['pending', 'in_review', 'escalated'], true);
                $windowHours = $minorSuspected
                    ? (int) config('veyra.sla.restricted_verification_hours', 4)
                    : $slaHours;

                /*
                 * Anything still in a queue is recent, and its age is a fraction
                 * of its OWN SLA window.
                 *
                 * Two bugs this avoids: escalations inheriting a signup-era date
                 * and showing as "471 days overdue", and ageing everything by a
                 * flat number of hours so that most of the queue breaches a
                 * shorter window automatically.
                 */
                if ($isOpen) {
                    $submittedAt = $faker->boolean(14)
                        ? now()->subMinutes((int) ($windowHours * 60 * $faker->randomFloat(2, 1.1, 2.5)))
                        : now()->subMinutes((int) ($windowHours * 60 * $faker->randomFloat(2, 0.05, 0.9)));
                }

                $rows[] = [
                    'uuid' => (string) Str::uuid(),
                    'app_user_id' => $member->id,
                    'attempt_no' => $attempt,
                    'type' => 'selfie',
                    'status' => $status,
                    'queue' => $minorSuspected ? 'restricted_minor' : 'standard',
                    'selfie_disk' => 'verifications',
                    'selfie_path' => null,
                    'gesture_code' => strtoupper($faker->bothify('?#?#')),
                    'face_match_score' => $matchScore,
                    'liveness_passed' => $approved ? $faker->boolean(97) : $faker->boolean(31),
                    'liveness_score' => $faker->randomFloat(3, $approved ? 0.8 : 0.2, $approved ? 0.99 : 0.75),
                    'duplicate_face_account_count' => $duplicates,
                    'device_reuse_count' => $faker->boolean(12) ? $faker->numberBetween(1, 4) : 0,
                    'estimated_age_min' => $conflictingAge ? 16 : max(18, $trueAge - 3),
                    'estimated_age_max' => $conflictingAge ? 19 : $trueAge + 3,
                    'minor_suspected' => $minorSuspected,
                    'claimed_by' => null,
                    'claimed_at' => null,
                    'reviewed_by' => in_array($status, ['approved', 'rejected'], true)
                        ? $faker->randomElement($staff) : null,
                    'reviewed_at' => in_array($status, ['approved', 'rejected'], true)
                        ? $submittedAt->copy()->addHours($faker->numberBetween(1, 40)) : null,
                    'rejection_reason_code' => $status === 'rejected'
                        ? $faker->randomElement(ReasonCode::forVerificationRejection())->value : null,
                    'internal_note' => null,
                    'submitted_at' => $submittedAt,
                    // The deadline always follows from the submission, so which
                    // items breach is decided by the age drawn above rather than
                    // by a second independent roll.
                    'sla_due_at' => $submittedAt->copy()->addHours($windowHours),
                    'created_at' => $submittedAt,
                    'updated_at' => $submittedAt,
                ];

                $total++;
            }

            if (count($rows) >= 500) {
                DB::table('verifications')->insert($rows);
                $rows = [];
            }
        }

        if ($rows !== []) {
            DB::table('verifications')->insert($rows);
        }

        $this->seedSignals();
        $this->generateOpenSelfies();

        return $total;
    }

    /**
     * Render selfies for open submissions only.
     *
     * This mirrors real practice as much as it saves time: platforms delete the
     * verification capture shortly after review and keep only a derived face
     * signature. A decided submission having no image is correct behaviour, not
     * missing data, and the review screen says so.
     */
    private function generateOpenSelfies(): void
    {
        if (config('veyra.seed.photos') === 'none') {
            return;
        }

        $open = DB::table('verifications')
            ->whereIn('status', ['pending', 'in_review', 'escalated'])
            ->get(['id', 'app_user_id', 'gesture_code']);

        if ($open->isEmpty()) {
            return;
        }

        $generator = new PlaceholderPhotoGenerator;

        foreach ($open as $verification) {
            $file = $generator->selfie(
                (int) $verification->app_user_id,
                (string) $verification->gesture_code,
            );

            DB::table('verifications')->where('id', $verification->id)->update([
                'selfie_disk' => $file['disk'],
                'selfie_path' => $file['path'],
            ]);
        }

        $this->command?->info("Rendered {$open->count()} verification selfies.");
    }

    private function statusFor(string $memberStatus, $faker): string
    {
        return match ($memberStatus) {
            'approved' => 'approved',
            'rejected' => 'rejected',
            'expired' => 'expired',
            // Escalation is rare by design — it is the minor-safety path, not a
            // routine outcome, and a queue full of it would be meaningless.
            default => $faker->randomElement(array_merge(
                array_fill(0, 7, 'pending'),
                array_fill(0, 4, 'in_review'),
                ['escalated'],
            )),
        };
    }

    /**
     * Signals are derived from the parent row's own scores, so a chip on the
     * review screen can never contradict the column it came from.
     */
    private function seedSignals(): void
    {
        DB::statement("
            INSERT INTO verification_signals (verification_id, `key`, label, value, severity, passed)
            SELECT id, 'face_match', 'Face match',
                   CONCAT(ROUND(face_match_score * 100), '%'),
                   CASE WHEN face_match_score >= 0.8 THEN 'info' ELSE 'critical' END,
                   face_match_score >= 0.8
            FROM verifications
        ");

        DB::statement("
            INSERT INTO verification_signals (verification_id, `key`, label, value, severity, passed)
            SELECT id, 'liveness', 'Liveness check',
                   CASE WHEN liveness_passed THEN 'Passed' ELSE 'Failed' END,
                   CASE WHEN liveness_passed THEN 'info' ELSE 'critical' END,
                   liveness_passed
            FROM verifications
        ");

        DB::statement("
            INSERT INTO verification_signals (verification_id, `key`, label, value, severity, passed)
            SELECT id, 'duplicate_face', 'Same face on other accounts',
                   duplicate_face_account_count,
                   CASE WHEN duplicate_face_account_count >= 2 THEN 'critical'
                        WHEN duplicate_face_account_count = 1 THEN 'warning'
                        ELSE 'info' END,
                   duplicate_face_account_count = 0
            FROM verifications
        ");

        DB::statement("
            INSERT INTO verification_signals (verification_id, `key`, label, value, severity, passed)
            SELECT id, 'device_reuse', 'Device seen on other accounts',
                   device_reuse_count,
                   CASE WHEN device_reuse_count >= 3 THEN 'critical'
                        WHEN device_reuse_count >= 1 THEN 'warning'
                        ELSE 'info' END,
                   device_reuse_count = 0
            FROM verifications
        ");

        DB::statement("
            INSERT INTO verification_signals (verification_id, `key`, label, value, severity, passed)
            SELECT id, 'age_estimate', 'Estimated age',
                   CONCAT(estimated_age_min, '-', estimated_age_max),
                   CASE WHEN minor_suspected THEN 'critical' ELSE 'info' END,
                   NOT minor_suspected
            FROM verifications
        ");
    }

    // ---------------------------------------------------------------- reports

    private function seedReports($faker): int
    {
        /*
         * Report volume is proportional to matches rather than a fixed number.
         *
         * A fixed count makes "report rate per 1,000 matches" nonsense at any
         * scale but the one it was tuned for — 2,450 reports against 1,100
         * matches reads as 2,200 per thousand, which is not a number any real
         * platform could survive.
         *
         * 8% is above a real platform's rate, which is nearer 1-3%. That is a
         * deliberate trade: at a realistic rate a demo dataset produces a couple
         * of dozen reports and every moderation screen opens empty.
         */
        $matches = DB::table('matches')->count();
        $target = max(150, (int) round($matches * 0.08));

        // Weight report subjects towards accounts that look bad already: heavily
        // blocked members, flagged senders, and restricted accounts. Reports
        // spread uniformly across the base would give the queue no signal.
        $suspects = DB::table('blocks')
            ->select('blocked_app_user_id', DB::raw('count(*) as c'))
            ->groupBy('blocked_app_user_id')
            ->orderByDesc('c')
            ->limit(400)
            ->pluck('blocked_app_user_id')
            ->all();

        $restricted = DB::table('app_users')
            ->whereIn('account_status', ['limited', 'shadow_banned', 'suspended', 'banned'])
            ->pluck('id')
            ->all();

        $everyone = DB::table('app_users')->pluck('id')->all();
        $reporters = DB::table('app_users')->inRandomOrder()->limit(min(2000, count($everyone)))->pluck('id')->all();

        /*
         * Evidence must be the SUBJECT'S OWN message, indexed by sender.
         *
         * Anchoring to any flagged message produces a case whose evidence pane
         * shows a conversation the reported member is not part of — which reads
         * as a bug to any moderator who looks, and would be one in production.
         */
        $messagesBySender = DB::table('messages')
            ->where(function ($q): void {
                $q->where('is_flagged', true)
                    ->orWhere('contains_contact_info', true)
                    ->orWhere('contains_link', true);
            })
            ->select('id', 'conversation_id', 'sender_app_user_id')
            ->get()
            ->groupBy('sender_app_user_id');

        $senders = $messagesBySender->keys()->all();

        $categories = $this->categoryWeights();
        $rows = [];
        $total = 0;

        for ($i = 0; $i < $target; $i++) {
            $roll = $faker->randomFloat(4, 0, 1);

            // Weighted towards members who already look bad: heavily blocked,
            // already restricted, or senders of flagged messages. Reports spread
            // uniformly across the base would give the queue no signal at all.
            $reported = match (true) {
                $roll < 0.30 && $senders !== [] => $faker->randomElement($senders),
                $roll < 0.50 && $suspects !== [] => $faker->randomElement($suspects),
                $roll < 0.62 && $restricted !== [] => $faker->randomElement($restricted),
                default => $faker->randomElement($everyone),
            };

            $category = ReportCategory::from($this->weighted($faker, $categories));
            $severity = $category->defaultSeverity();

            // Anchor to something the reported member actually sent, when they
            // have sent anything worth anchoring to.
            $candidates = $messagesBySender->get($reported);
            $anchor = $candidates !== null && $candidates->isNotEmpty() && $faker->boolean(80)
                ? $candidates->random()
                : null;

            $rows[] = [
                'uuid' => (string) Str::uuid(),
                'reporter_app_user_id' => $faker->randomElement($reporters),
                'reported_app_user_id' => $reported,
                'report_case_id' => null,
                'category' => $category->value,
                'severity' => $severity->value,
                'source' => $this->weighted($faker, ['user' => 82, 'automation' => 17, 'partner' => 1]),
                'message_id' => $anchor?->id,
                'conversation_id' => $anchor?->conversation_id,
                'photo_id' => null,
                'description' => $faker->boolean(55) ? $faker->sentence(12) : null,
                'reporter_credibility_at_time' => $faker->numberBetween(20, 95),
                'created_at' => now()->subDays($faker->numberBetween(0, 180))
                    ->subMinutes($faker->numberBetween(0, 1440)),
            ];

            $total++;

            if (count($rows) >= 1000) {
                DB::table('reports')->insert($rows);
                $rows = [];
            }
        }

        if ($rows !== []) {
            DB::table('reports')->insert($rows);
        }

        return $total;
    }

    /** @return array<string, float> */
    private function categoryWeights(): array
    {
        return [
            'harassment' => 21, 'fake_profile' => 17, 'spam_promotion' => 12,
            'off_platform_solicitation' => 9, 'sexual_content' => 8, 'nudity' => 7,
            'scam_fraud' => 7, 'impersonation' => 5, 'hate_speech' => 4,
            'violence_threats' => 3, 'minor_safety' => 2.5, 'underage_suspected' => 2,
            'self_harm' => 1.2, 'prostitution' => 1, 'other' => 0.3,
        ];
    }

    // ---------------------------------------------------------------- cases

    /**
     * Roll every report against one member into a single case.
     *
     * This is the merge/dedupe the console depends on: without it three
     * moderators independently review the same account on the same afternoon,
     * and a serial offender's history is spread across a dozen rows nobody
     * connects.
     */
    private function aggregateCases(): int
    {
        $groups = DB::table('reports')
            ->select(
                'reported_app_user_id',
                DB::raw('COUNT(*) as reports_count'),
                DB::raw('COUNT(DISTINCT reporter_app_user_id) as distinct_reporters'),
                DB::raw('MIN(created_at) as opened_at'),
                DB::raw("MAX(FIELD(severity, 'low', 'medium', 'high', 'critical')) as severity_rank"),
            )
            ->groupBy('reported_app_user_id')
            ->get();

        $faker = fake();
        $severities = [1 => 'low', 2 => 'medium', 3 => 'high', 4 => 'critical'];
        $sequence = 1;
        $rows = [];

        foreach ($groups as $group) {
            $severity = Severity::from($severities[(int) $group->severity_rank] ?? 'low');
            $openedAt = Carbon::parse($group->opened_at);

            $status = $this->weighted($faker, [
                'actioned' => 46, 'closed' => 16, 'new' => 14,
                'in_review' => 11, 'claimed' => 8, 'appealed' => 5,
            ]);

            $isOpen = in_array($status, ['new', 'claimed', 'in_review'], true);

            /*
             * An open case is a RECENT case. Dating open items from the oldest
             * underlying report would put every one of them months past its SLA
             * and make the breach pill meaningless — a queue where everything is
             * red tells a moderator nothing about what to pick up first.
             *
             * Resolved cases keep their true historical dates.
             */
            if ($isOpen) {
                /*
                 * Age is expressed as a FRACTION of the case's own SLA window,
                 * not as an absolute number of hours. A critical case has a
                 * one-hour window, so ageing everything by "1-96 hours" would
                 * put the entire critical queue past due and make the breach
                 * pill meaningless.
                 */
                $windowMinutes = $severity->slaHours() * 60;

                $openedAt = $faker->boolean(9)
                    // Deliberately breached: opened more than a full window ago.
                    ? now()->subMinutes((int) ($windowMinutes * $faker->randomFloat(2, 1.2, 3.0)))
                    // Still inside its window.
                    : now()->subMinutes((int) ($windowMinutes * $faker->randomFloat(2, 0.05, 0.85)));
            }

            $slaDue = $openedAt->copy()->addHours($severity->slaHours());

            $rows[] = [
                'case_number' => sprintf('VEY-2026-%06d', $sequence++),
                'subject_app_user_id' => $group->reported_app_user_id,
                'status' => $status,
                'severity' => $severity->value,
                'reports_count' => $group->reports_count,
                'distinct_reporters_count' => $group->distinct_reporters,
                'risk_score_at_open' => $faker->numberBetween(0, 100),
                'claimed_by' => null,
                'claimed_at' => null,
                'resolved_by' => null,
                'resolved_at' => null,
                'outcome' => null,
                'sla_due_at' => $slaDue,
                'created_at' => $openedAt,
                'updated_at' => $openedAt,
            ];

            if (count($rows) >= 500) {
                DB::table('report_cases')->insert($rows);
                $rows = [];
            }
        }

        if ($rows !== []) {
            DB::table('report_cases')->insert($rows);
        }

        // Link each report to its case.
        DB::statement('
            UPDATE reports r
            JOIN report_cases c ON c.subject_app_user_id = r.reported_app_user_id
            SET r.report_case_id = c.id
        ');

        // Claimed and in-review cases need a holder, otherwise the queue shows
        // "claimed" rows with nobody's name against them.
        $moderators = DB::table('users')->pluck('id')->all();

        DB::table('report_cases')
            ->whereIn('status', ['claimed', 'in_review'])
            ->get(['id'])
            ->each(function ($case) use ($moderators, $faker): void {
                DB::table('report_cases')->where('id', $case->id)->update([
                    'claimed_by' => $faker->randomElement($moderators),
                    'claimed_at' => now()->subHours($faker->numberBetween(1, 48)),
                ]);
            });

        return $groups->count();
    }

    // ---------------------------------------------------------------- enforcement

    private function seedEnforcement($faker, array $moderators): int
    {
        $cases = DB::table('report_cases')
            ->whereIn('status', ['actioned', 'appealed'])
            ->select('id', 'subject_app_user_id', 'severity', 'created_at')
            ->get();

        $actions = [];
        $bans = [];
        $total = 0;

        foreach ($cases as $case) {
            $step = $this->stepFor(Severity::from($case->severity), $faker);
            $reason = $this->reasonFor($faker);
            $actor = $faker->randomElement($moderators);
            $isAutomation = $faker->boolean(22);

            $durationHours = $step->requiresDuration()
                ? $faker->randomElement([24, 168, 720])
                : null;

            /*
             * Roughly half of enforcement is recent enough to still be running.
             * Dating every action from its case's history would leave every
             * timed ban already expired, and the active-enforcement screens —
             * including the shadow ban review queue — would open empty.
             */
            $decidedAt = $faker->boolean(50)
                ? now()->subHours($faker->numberBetween(1, max(2, (int) (($durationHours ?? 336) * 0.7))))
                : Carbon::parse($case->created_at)->addHours($faker->numberBetween(1, 120));

            $uuid = (string) Str::uuid();

            $actions[] = [
                'uuid' => $uuid,
                'report_case_id' => $case->id,
                'verification_id' => null,
                'subject_app_user_id' => $case->subject_app_user_id,
                'actor_id' => $isAutomation ? null : $actor,
                'actor_type' => $isAutomation ? 'automation' : 'human',
                'automation_rule_id' => null,
                'ladder_step' => $step->value,
                'reason_code' => $reason->value,
                'policy_clause' => $reason->policyClause(),
                'internal_note' => $faker->sentence(10),
                'user_facing_message' => $reason->statement(),
                'duration_hours' => $durationHours,
                'notified_user' => $faker->boolean(85),
                'reversed_by_action_id' => null,
                'created_at' => $decidedAt,
            ];

            $total++;

            if ($step->createsBan()) {
                $banType = $this->banTypeFor($step);
                $isShadow = $banType === 'shadow_ban';

                $bans[] = [
                    'uuid' => (string) Str::uuid(),
                    'app_user_id' => $case->subject_app_user_id,
                    'moderation_action_id' => null,
                    'type' => $banType,
                    'limited_features' => $banType === 'feature_limit'
                        ? json_encode($faker->randomElements(
                            array_keys(config('veyra.enforcement.feature_limit_options')),
                            $faker->numberBetween(1, 3),
                        ))
                        : null,
                    'reason_code' => $reason->value,
                    'internal_note' => $faker->sentence(8),
                    'user_facing_message' => $reason->statement(),
                    'issued_by' => $isAutomation ? null : $actor,
                    'starts_at' => $decidedAt,
                    'expires_at' => $durationHours
                        ? $decidedAt->copy()->addHours($durationHours)
                        : null,
                    /*
                     * Every shadow ban gets a review date, without exception.
                     * A handful are seeded already overdue so the review queue
                     * opens with rows in it — an empty safeguard teaches nobody
                     * to use it.
                     */
                    'review_due_at' => $isShadow
                        ? ($faker->boolean(35)
                            ? now()->subDays($faker->numberBetween(1, 21))
                            : now()->addDays($faker->numberBetween(1, 14)))
                        : null,
                    'lifted_by' => null,
                    'lifted_at' => $faker->boolean(18) ? $decidedAt->copy()->addDays($faker->numberBetween(1, 30)) : null,
                    'lift_reason' => null,
                    'created_at' => $decidedAt,
                    'updated_at' => $decidedAt,
                ];
            }

            if (count($actions) >= 500) {
                DB::table('moderation_actions')->insert($actions);
                $actions = [];
            }
        }

        if ($actions !== []) {
            DB::table('moderation_actions')->insert($actions);
        }

        foreach (array_chunk($bans, 500) as $chunk) {
            DB::table('bans')->insert($chunk);
        }

        $this->syncEnforcementMirror();

        return $total;
    }

    private function stepFor(Severity $severity, $faker): LadderStep
    {
        return match ($severity) {
            Severity::Critical => $faker->randomElement([
                LadderStep::PermanentBan, LadderStep::Suspend, LadderStep::Escalate,
            ]),
            Severity::High => $faker->randomElement([
                LadderStep::Suspend, LadderStep::ShadowBan, LadderStep::PermanentBan, LadderStep::FeatureLimit,
            ]),
            Severity::Medium => $faker->randomElement([
                LadderStep::Warn, LadderStep::FeatureLimit, LadderStep::ShadowBan, LadderStep::Suspend,
            ]),
            Severity::Low => $faker->randomElement([LadderStep::Warn, LadderStep::Warn, LadderStep::FeatureLimit]),
        };
    }

    private function reasonFor($faker): ReasonCode
    {
        return $faker->randomElement([
            ReasonCode::HarassmentConfirmed,
            ReasonCode::FakeProfileConfirmed,
            ReasonCode::RomanceScam,
            ReasonCode::SpamOrAdvertising,
            ReasonCode::UnsolicitedSexualContent,
            ReasonCode::OffPlatformSolicitation,
            ReasonCode::ImpersonationConfirmed,
            ReasonCode::StolenPhotos,
        ]);
    }

    private function banTypeFor(LadderStep $step): string
    {
        return match ($step) {
            LadderStep::FeatureLimit => 'feature_limit',
            LadderStep::ShadowBan => 'shadow_ban',
            LadderStep::Suspend => 'suspension',
            LadderStep::DeviceBan => 'device_ban',
            default => 'permanent_ban',
        };
    }

    /**
     * Bring the app_users enforcement mirror in line with `bans`.
     *
     * The mirror exists for the API hot path and is the single most likely place
     * for the console and the API to disagree, so it is recomputed wholesale
     * rather than patched per row.
     */
    private function syncEnforcementMirror(): void
    {
        DB::statement("
            UPDATE app_users au
            JOIN (
                SELECT b.app_user_id,
                       MAX(b.id) AS ban_id,
                       MAX(CASE WHEN b.type = 'shadow_ban'  THEN b.expires_at END) AS shadow_until,
                       MAX(CASE WHEN b.type = 'suspension'  THEN b.expires_at END) AS suspended_until,
                       MAX(CASE WHEN b.type IN ('permanent_ban','device_ban') THEN b.starts_at END) AS banned_at,
                       MAX(FIELD(b.type,'feature_limit','shadow_ban','suspension','permanent_ban','device_ban')) AS rank_type
                FROM bans b
                WHERE b.lifted_at IS NULL AND (b.expires_at IS NULL OR b.expires_at > NOW())
                GROUP BY b.app_user_id
            ) active ON active.app_user_id = au.id
            SET au.active_ban_id      = active.ban_id,
                au.shadow_banned_until = active.shadow_until,
                au.suspended_until     = active.suspended_until,
                au.banned_at           = active.banned_at,
                au.account_status = CASE active.rank_type
                    WHEN 1 THEN 'limited'
                    WHEN 2 THEN 'shadow_banned'
                    WHEN 3 THEN 'suspended'
                    ELSE 'banned'
                END
        ");
    }

    // ---------------------------------------------------------------- appeals

    private function seedAppeals($faker, array $staff): int
    {
        $appealable = DB::table('bans as b')
            ->join('app_users as au', 'au.id', '=', 'b.app_user_id')
            ->select('b.id as ban_id', 'b.app_user_id', 'b.issued_by', 'b.created_at')
            ->whereIn('b.type', ['feature_limit', 'shadow_ban', 'suspension', 'permanent_ban'])
            ->inRandomOrder()
            ->limit((int) round(246 * $this->scale))
            ->get();

        $rows = [];
        $total = 0;

        foreach ($appealable as $ban) {
            $original = $ban->issued_by ?? $faker->randomElement($staff);

            /*
             * The reviewer is drawn from everyone EXCEPT the original decider.
             * Enforced here as well as in the assign action, because a seeded
             * violation would make the test that guards this rule pass against
             * bad data.
             */
            $candidates = array_values(array_filter($staff, fn ($id) => $id !== $original));
            $assigned = $candidates === [] ? null : $faker->randomElement($candidates);

            $status = $this->weighted($faker, [
                'upheld' => 41, 'new' => 16, 'overturned' => 15, 'assigned' => 12,
                'in_review' => 9, 'partially_overturned' => 5, 'withdrawn' => 2,
            ]);

            $filedAt = Carbon::parse($ban->created_at)->addDays($faker->numberBetween(1, 14));
            $decided = in_array($status, ['upheld', 'overturned', 'partially_overturned'], true);

            $rows[] = [
                'uuid' => (string) Str::uuid(),
                'app_user_id' => $ban->app_user_id,
                'ban_id' => $ban->ban_id,
                'moderation_action_id' => null,
                'original_decider_id' => $original,
                'assigned_to_id' => $status === 'new' ? null : $assigned,
                'status' => $status,
                'user_statement' => $faker->randomElement([
                    'I did not send those messages, my account was accessed by someone else.',
                    'This was a misunderstanding. I was quoting something, not saying it.',
                    'The photos are genuinely mine, I can provide the originals.',
                    'I was reported by someone I stopped speaking to. Nothing I sent broke the rules.',
                    'I did not realise sharing my number was against the rules. It will not happen again.',
                ]),
                'decision_note' => $decided ? $faker->sentence(12) : null,
                'decided_at' => $decided ? $filedAt->copy()->addDays($faker->numberBetween(1, 10)) : null,
                'sla_due_at' => $filedAt->copy()->addHours((int) config('veyra.sla.appeal_hours', 72)),
                'created_at' => $filedAt,
                'updated_at' => $filedAt,
            ];

            $total++;
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('appeals')->insert($chunk);
        }

        return $total;
    }

    /** @param array<string|int, float|int> $weights */
    private function weighted($faker, array $weights): string
    {
        $sum = array_sum($weights);
        $roll = $faker->randomFloat(4, 0, $sum);
        $cumulative = 0.0;

        foreach ($weights as $key => $weight) {
            $cumulative += $weight;

            if ($roll <= $cumulative) {
                return (string) $key;
            }
        }

        return (string) array_key_last($weights);
    }
}
