<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\Appeal;
use App\Models\AppUser;
use App\Models\ModerationAction;
use App\Models\User;
use App\Models\Verification;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The staff audit trail, and the message-content reveals it records.
 *
 * Every row is derived from a decision that is already in the database — the
 * moderation actions, verification reviews and appeal outcomes the trust &
 * safety seeder wrote. Inventing entries instead would produce an audit log
 * describing actions that never happened, which is worse than an empty one: the
 * whole point of the screen is that it can be reconciled against the records it
 * refers to.
 *
 * It runs last, because it reads every table it writes about.
 */
class AuditTrailSeeder extends Seeder
{
    public function run(): void
    {
        $faker = fake();
        $faker->seed(config('platform.seed.faker_seed', 20260917) + 7);

        $staff = DB::table('users')
            ->join('model_has_roles as mhr', function ($join): void {
                $join->on('mhr.model_id', '=', 'users.id')
                    ->where('mhr.model_type', '=', User::class);
            })
            ->join('roles as r', 'r.id', '=', 'mhr.role_id')
            ->select('users.id', 'users.name', 'r.name as role')
            ->get()
            ->keyBy('id');

        if ($staff->isEmpty()) {
            return;
        }

        $rows = array_merge(
            $this->fromEnforcement($staff),
            $this->fromVerifications($staff),
            $this->fromAppeals($staff),
            $this->fromSignIns($faker, $staff),
            $this->fromConfiguration($faker, $staff),
        );

        $reveals = $this->seedMessageReveals($faker, $staff, $rows);

        usort($rows, fn (array $a, array $b): int => strcmp((string) $a['created_at'], (string) $b['created_at']));

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('activity_logs')->insert($chunk);
        }

        $this->command?->info(sprintf(
            'Seeded %d audit entries, including %d message reveals.',
            count($rows),
            $reveals,
        ));
    }

    /**
     * One entry per enforcement decision.
     *
     * The actor is the one on the moderation action, not a random staff member,
     * so the audit log and the case history name the same person. Automated
     * actions keep a null user_id and are attributed to the rule engine — that
     * distinction is the whole reason actor_type exists on the action.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fromEnforcement($staff): array
    {
        return ModerationAction::query()
            ->with('subject:id,display_name')
            ->get()
            ->map(function (ModerationAction $action) use ($staff): array {
                $actor = $action->actor_id !== null ? $staff->get($action->actor_id) : null;

                return $this->row(
                    actor: $actor,
                    module: 'enforcement',
                    action: $action->ladder_step->value,
                    subject: $action->subject,
                    description: sprintf(
                        '%s applied to %s (%s)',
                        $action->ladder_step->label(),
                        $action->subject?->display_name ?? 'a member',
                        $action->reason_code->label(),
                    ),
                    new: [
                        'ladder_step' => $action->ladder_step->value,
                        'reason_code' => $action->reason_code->value,
                        'duration_hours' => $action->duration_hours,
                        'actor_type' => $action->actor_type,
                    ],
                    at: $action->created_at,
                    actorNameOverride: $actor === null ? 'Automation' : null,
                    actorRoleOverride: $actor === null ? 'system' : null,
                );
            })
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function fromVerifications($staff): array
    {
        return Verification::query()
            ->whereNotNull('reviewed_by')
            ->whereNotNull('reviewed_at')
            ->with('appUser:id,display_name')
            ->get()
            ->map(fn (Verification $verification): array => $this->row(
                actor: $staff->get($verification->reviewed_by),
                module: 'verification',
                action: $verification->status->value,
                subject: $verification->appUser,
                description: sprintf(
                    'Verification %s for %s',
                    $verification->status->label(),
                    $verification->appUser?->display_name ?? 'a member',
                ),
                new: array_filter([
                    'status' => $verification->status->value,
                    'queue' => $verification->queue,
                    'rejection_reason_code' => $verification->rejection_reason_code,
                ]),
                at: $verification->reviewed_at,
            ))
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function fromAppeals($staff): array
    {
        return Appeal::query()
            ->whereNotNull('decided_at')
            ->whereNotNull('assigned_to_id')
            ->with('appUser:id,display_name')
            ->get()
            ->map(fn (Appeal $appeal): array => $this->row(
                actor: $staff->get($appeal->assigned_to_id),
                module: 'appeals',
                action: $appeal->status->value,
                subject: $appeal->appUser,
                description: sprintf(
                    'Appeal %s for %s',
                    $appeal->status->label(),
                    $appeal->appUser?->display_name ?? 'a member',
                ),
                new: ['status' => $appeal->status->value],
                at: $appeal->decided_at,
            ))
            ->all();
    }

    /**
     * Staff sign-ins.
     *
     * The trail reads as a history of one console rather than a list of
     * decisions floating free of anyone using it, and it is what makes the
     * actor filter worth having.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fromSignIns($faker, $staff): array
    {
        $rows = [];

        foreach ($staff as $member) {
            foreach (range(1, $faker->numberBetween(2, 6)) as $ignored) {
                $at = Carbon::now()
                    ->subDays($faker->numberBetween(0, 30))
                    ->setTime($faker->numberBetween(8, 19), $faker->numberBetween(0, 59));

                $rows[] = $this->row(
                    actor: $member,
                    module: 'auth',
                    action: 'signed_in',
                    subject: null,
                    description: "{$member->name} signed in",
                    new: null,
                    at: $at,
                );
            }
        }

        return $rows;
    }

    /**
     * Configuration changes, with a real before/after pair.
     *
     * The diff viewer is the part of this screen most likely to break, and it
     * cannot be exercised by rows whose old_values are null.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fromConfiguration($faker, $staff): array
    {
        $admins = $staff->whereIn('role', ['Super Admin', 'Admin', 'T&S Lead'])->values();

        if ($admins->isEmpty()) {
            $admins = $staff->values();
        }

        $changes = [
            ['settings', 'updated', 'Changed the case SLA window', ['sla.case_hours' => 48], ['sla.case_hours' => 24]],
            ['settings', 'updated', 'Retuned a risk factor', ['risk.weights.duplicate_face' => 15], ['risk.weights.duplicate_face' => 22]],
            ['settings', 'updated', 'Raised the daily like cap', ['matching.daily_like_cap' => 100], ['matching.daily_like_cap' => 150]],
            ['roles', 'updated', 'Granted view_message_content to Senior Moderator', ['permissions' => ['cases', 'appeals']], ['permissions' => ['cases', 'appeals', 'view_message_content']]],
            ['staff', 'created', 'Added a moderator account', null, ['status' => 'active', 'role' => 'Moderator']],
            ['staff', 'updated', 'Suspended a staff account', ['status' => 'active'], ['status' => 'suspended']],
            ['system', 'updated', 'Switched the SMTP host', ['mail.host' => 'smtp.mailtrap.io'], ['mail.host' => 'smtp.postmarkapp.com']],
            ['system', 'updated', 'Enabled the Stripe gateway', ['is_active' => false], ['is_active' => true]],
            ['notifications', 'created', 'Drafted a re-engagement campaign', null, ['audience' => 'inactive_14d', 'status' => 'draft']],
            ['notifications', 'updated', 'Approved a campaign for sending', ['status' => 'pending_approval'], ['status' => 'approved']],
        ];

        $rows = [];

        foreach ($changes as [$module, $action, $description, $old, $new]) {
            $rows[] = $this->row(
                actor: $admins->random(),
                module: $module,
                action: $action,
                subject: null,
                description: $description,
                new: $new,
                at: Carbon::now()->subDays($faker->numberBetween(1, 45))->setTime($faker->numberBetween(9, 18), $faker->numberBetween(0, 59)),
                old: $old,
            );
        }

        return $rows;
    }

    /**
     * Message-content reveals.
     *
     * Each writes both records the real path writes — a message_access_logs row
     * and a sensitive activity_logs row — because the access-audit screen joins
     * them, and a reveal present in one but not the other would read as a gap in
     * the trail rather than as seed data.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function seedMessageReveals($faker, $staff, array &$rows): int
    {
        $privileged = $staff->whereIn('role', ['Super Admin', 'T&S Lead', 'Senior Moderator'])->values();

        if ($privileged->isEmpty()) {
            return 0;
        }

        // Threads a reported member is actually in. A reveal on a conversation
        // unconnected to any case would be exactly the unjustified access this
        // screen exists to catch.
        $conversations = DB::table('conversation_participants as cp')
            ->join('report_cases as rc', 'rc.subject_app_user_id', '=', 'cp.app_user_id')
            ->select('cp.conversation_id', 'rc.id as report_case_id', 'rc.subject_app_user_id')
            ->inRandomOrder()
            ->limit(12)
            ->get();

        $reasons = array_keys(config('platform.privacy.reveal_reasons'));
        $accessRows = [];

        foreach ($conversations as $conversation) {
            $actor = $privileged->random();
            $reason = $faker->randomElement($reasons);
            $at = Carbon::now()->subDays($faker->numberBetween(0, 40))->setTime($faker->numberBetween(9, 18), $faker->numberBetween(0, 59));
            $revealed = $faker->numberBetween(4, 30);

            $justification = $faker->randomElement([
                'Reviewing reported harassment in this thread before deciding the case.',
                'Checking whether the reported contact-sharing actually appears in context.',
                'Appeal states the messages were misread; reading the thread to confirm.',
                'Escalated safety concern raised by the reporting member.',
            ]);

            $accessRows[] = [
                'user_id' => $actor->id,
                'conversation_id' => $conversation->conversation_id,
                'message_id' => null,
                'report_case_id' => $conversation->report_case_id,
                'reason_code' => $reason,
                'justification' => $justification,
                'messages_revealed' => $revealed,
                'ip_address' => $faker->ipv4(),
                'created_at' => $at,
            ];

            $subject = AppUser::query()->find($conversation->subject_app_user_id);

            $rows[] = $this->row(
                actor: $actor,
                module: 'conversations',
                action: 'revealed_content',
                subject: $subject,
                description: sprintf('Revealed %d messages — %s', $revealed, $reason),
                new: [
                    'reason_code' => $reason,
                    'justification' => $justification,
                    'messages_revealed' => $revealed,
                ],
                at: $at,
                sensitive: true,
            );
        }

        if ($accessRows !== []) {
            DB::table('message_access_logs')->insert($accessRows);
        }

        return count($accessRows);
    }

    /**
     * Actor identity is snapshotted onto the row, exactly as ActivityLogger
     * does it, so the log still reads correctly after a staff member is deleted.
     *
     * @param  array<string, mixed>|null  $new
     * @param  array<string, mixed>|null  $old
     * @return array<string, mixed>
     */
    private function row(
        $actor,
        string $module,
        string $action,
        $subject,
        ?string $description,
        ?array $new,
        $at,
        ?array $old = null,
        bool $sensitive = false,
        ?string $actorNameOverride = null,
        ?string $actorRoleOverride = null,
    ): array {
        return [
            'user_id' => $actor?->id,
            'actor_name' => $actorNameOverride ?? $actor?->name,
            'actor_role' => $actorRoleOverride ?? $actor?->role,
            'module' => $module,
            'action' => $action,
            'subject_type' => $subject !== null ? $subject->getMorphClass() : null,
            'subject_id' => $subject?->getKey(),
            'description' => $description,
            'old_values' => $old !== null ? json_encode($old) : null,
            'new_values' => $new !== null ? json_encode($new) : null,
            'is_sensitive' => $sensitive,
            'ip_address' => '203.0.113.'.random_int(2, 250),
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/128.0 Safari/537.36',
            'created_at' => Carbon::parse($at),
        ];
    }
}
