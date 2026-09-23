<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Actions\Moderation\ApplyEnforcement;
use App\Enums\AccountStatus;
use App\Enums\LadderStep;
use App\Enums\ReasonCode;
use App\Jobs\SendMemberPush;
use App\Models\AppUser;
use App\Models\Conversation;
use App\Models\MatchRecord;
use App\Models\Message;
use App\Models\Preference;
use App\Models\Profile;
use App\Models\Report;
use App\Models\ReportCase;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Swipe;
use App\Models\User;
use App\Services\Members\MessageSender;
use Database\Seeders\InterestSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MobileFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            PermissionSeeder::class,
            RoleSeeder::class,
            InterestSeeder::class,
            // Operator-tunable limits live in settings, so the API cannot be
            // exercised faithfully without them.
            SettingSeeder::class,
        ]);
    }

    private function member(array $attributes = []): AppUser
    {
        $member = AppUser::factory()->create($attributes);

        Profile::query()->create(['app_user_id' => $member->id]);
        Preference::query()->create([
            'app_user_id' => $member->id,
            'interested_in' => ['woman', 'man', 'non_binary', 'other'],
            'age_min' => 18,
            'age_max' => 99,
        ]);

        return $member->fresh();
    }

    // ---------------------------------------------------------------- auth

    public function test_a_member_can_register_and_receives_a_token(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'display_name' => 'Ada Test',
            'email' => 'ada@example.test',
            'password' => 'correct-horse-9',
            'birthdate' => now()->subYears(28)->toDateString(),
            'gender' => 'woman',
            'interested_in' => ['man'],
        ]);

        $response->assertCreated()
            ->assertJsonStructure(['token', 'user' => ['id', 'display_name', 'account_status']]);

        $this->assertDatabaseHas('app_users', ['email' => 'ada@example.test']);
    }

    public function test_registration_refuses_anybody_under_eighteen(): void
    {
        // An under-18 account is a safety incident, not a validation nicety.
        $this->postJson('/api/v1/auth/register', [
            'display_name' => 'Too Young',
            'email' => 'young@example.test',
            'password' => 'correct-horse-9',
            'birthdate' => now()->subYears(16)->toDateString(),
            'gender' => 'woman',
            'interested_in' => ['man'],
        ])->assertStatus(422)->assertJsonPath('code', 'validation_failed');
    }

    // ---------------------------------------------------------------- privacy

    public function test_another_members_profile_never_carries_private_fields(): void
    {
        $viewer = $this->member();
        $other = $this->member(['email' => 'other@example.test', 'phone' => '+447700900123']);
        $other->update(['risk_score' => 88, 'risk_band' => 'critical']);

        Sanctum::actingAs($viewer, ['*']);

        $json = $this->getJson('/api/v1/deck')->assertOk()->content();

        // Asserted against the raw payload, so a field nested anywhere in the
        // response is still caught.
        foreach (['other@example.test', '+447700900123', 'risk_score', 'risk_band',
            'last_latitude', 'last_longitude', 'internal_note', 'shadow_banned_until',
            'account_status', 'birthdate'] as $leak) {
            $this->assertStringNotContainsString($leak, $json, "Deck payload leaked: {$leak}");
        }
    }

    public function test_a_shadow_ban_is_invisible_to_the_member_it_is_applied_to(): void
    {
        $staff = User::factory()->create(['status' => 'active']);
        $staff->syncRoles([Role::TS_LEAD]);

        $member = $this->member();

        app(ApplyEnforcement::class)->apply(
            subject: $member,
            step: LadderStep::ShadowBan,
            reason: ReasonCode::SpamOrAdvertising,
            actor: $staff,
            durationHours: 168,
            reviewDueAt: now()->addWeek(),
        );

        $member->refresh();
        $this->assertSame(AccountStatus::ShadowBanned, $member->account_status);

        Sanctum::actingAs($member, ['*']);

        /*
         * The whole point of a shadow ban is that the member cannot tell. The
         * API must answer normally, and must report the account as active — a
         * shadow ban a member can detect is just a ban with extra steps.
         */
        $response = $this->getJson('/api/v1/me')->assertOk();

        $this->assertSame('active', $response->json('data.account_status'));
        $this->assertStringNotContainsString('shadow', strtolower($response->content()));

        // And the deck still works for them.
        $this->getJson('/api/v1/deck')->assertOk();
    }

    public function test_a_banned_member_is_refused_with_a_statement_of_reasons(): void
    {
        $staff = User::factory()->create(['status' => 'active']);
        $staff->syncRoles([Role::TS_LEAD]);

        $member = $this->member();

        app(ApplyEnforcement::class)->apply(
            subject: $member,
            step: LadderStep::PermanentBan,
            reason: ReasonCode::RomanceScam,
            actor: $staff,
            note: 'Confirmed advance-fee pattern.',
        );

        Sanctum::actingAs($member->fresh(), ['*']);

        $response = $this->getJson('/api/v1/me')->assertForbidden();

        $response->assertJsonPath('code', 'account_restricted');
        // DSA Art. 17: the specific clause, not a vague category.
        $this->assertNotNull($response->json('restriction.policy_clause'));
        $this->assertTrue($response->json('restriction.appealable'));
    }

    // ---------------------------------------------------------------- matching

    public function test_a_mutual_like_produces_a_match_and_a_conversation(): void
    {
        $alice = $this->member();
        $bob = $this->member(['email' => 'bob@example.test']);

        // Bob likes Alice first.
        Sanctum::actingAs($bob, ['*']);
        $this->postJson('/api/v1/swipes', [
            'target_id' => $alice->uuid,
            'action' => 'like',
        ])->assertCreated()->assertJsonPath('is_match', false);

        // Alice likes back, which is what creates the match.
        Sanctum::actingAs($alice, ['*']);
        $response = $this->postJson('/api/v1/swipes', [
            'target_id' => $bob->uuid,
            'action' => 'like',
        ])->assertCreated();

        $response->assertJsonPath('is_match', true);

        $this->assertDatabaseCount('matches', 1);
        $this->assertDatabaseCount('conversations', 1);

        // The canonical ordering invariant holds, so the pair cannot match twice.
        $this->assertSame(0, MatchRecord::query()
            ->whereColumn('app_user_one_id', '>=', 'app_user_two_id')
            ->count());
    }

    public function test_a_member_cannot_read_a_conversation_they_are_not_in(): void
    {
        $alice = $this->member();
        $bob = $this->member(['email' => 'bob2@example.test']);
        $stranger = $this->member(['email' => 'stranger@example.test']);

        Sanctum::actingAs($bob, ['*']);
        $this->postJson('/api/v1/swipes', ['target_id' => $alice->uuid, 'action' => 'like']);

        Sanctum::actingAs($alice, ['*']);
        $this->postJson('/api/v1/swipes', ['target_id' => $bob->uuid, 'action' => 'like']);

        $conversation = Conversation::query()->firstOrFail();

        Sanctum::actingAs($stranger, ['*']);

        // Ownership, not a permission: no role grants access to strangers' threads.
        $this->getJson("/api/v1/conversations/{$conversation->uuid}/messages")->assertForbidden();
        $this->postJson("/api/v1/conversations/{$conversation->uuid}/messages", [
            'body' => 'hello',
        ])->assertForbidden();
    }

    public function test_sending_a_message_flags_contact_details_for_the_risk_engine(): void
    {
        $alice = $this->member();
        $bob = $this->member(['email' => 'bob3@example.test']);

        Sanctum::actingAs($bob, ['*']);
        $this->postJson('/api/v1/swipes', ['target_id' => $alice->uuid, 'action' => 'like']);

        Sanctum::actingAs($alice, ['*']);
        $this->postJson('/api/v1/swipes', ['target_id' => $bob->uuid, 'action' => 'like']);

        $conversation = Conversation::query()->firstOrFail();

        $this->postJson("/api/v1/conversations/{$conversation->uuid}/messages", [
            'body' => 'hey, add me on whatsapp 0771 234 5678',
        ])->assertCreated();

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'contains_contact_info' => true,
        ]);
    }

    // ---------------------------------------------------------------- safety

    public function test_a_report_becomes_a_case_in_the_moderation_queue(): void
    {
        $reporter = $this->member();
        $subject = $this->member(['email' => 'subject@example.test']);

        Sanctum::actingAs($reporter, ['*']);

        $this->postJson('/api/v1/reports', [
            'reported_id' => $subject->uuid,
            'category' => 'harassment',
            'description' => 'Sent me abusive messages after I stopped replying.',
        ])->assertCreated();

        // The admin queue and the API write to the same objects, with no glue.
        $case = ReportCase::query()->where('subject_app_user_id', $subject->id)->firstOrFail();

        $this->assertSame(1, $case->reports_count);
        $this->assertSame(1, $case->distinct_reporters_count);
        $this->assertStringStartsWith('VEY-', $case->case_number);
    }

    public function test_several_reports_against_one_member_fold_into_a_single_case(): void
    {
        $subject = $this->member(['email' => 'repeat@example.test']);

        foreach (['a', 'b', 'c'] as $index => $letter) {
            $reporter = $this->member(['email' => "reporter-{$letter}@example.test"]);

            Sanctum::actingAs($reporter, ['*']);

            $this->postJson('/api/v1/reports', [
                'reported_id' => $subject->uuid,
                'category' => $index === 0 ? 'spam_promotion' : 'harassment',
            ])->assertCreated();
        }

        // One case, not three. Otherwise three moderators review the same
        // account and none of them sees that there were three reports.
        $this->assertDatabaseCount('report_cases', 1);

        $case = ReportCase::query()->firstOrFail();
        $this->assertSame(3, $case->reports_count);
        $this->assertSame(3, $case->distinct_reporters_count);

        // Severity rises to the worst report and never falls back.
        $this->assertSame('medium', $case->severity->value);
    }

    public function test_blocking_also_unmatches(): void
    {
        $alice = $this->member();
        $bob = $this->member(['email' => 'bob4@example.test']);

        Sanctum::actingAs($bob, ['*']);
        $this->postJson('/api/v1/swipes', ['target_id' => $alice->uuid, 'action' => 'like']);

        Sanctum::actingAs($alice, ['*']);
        $this->postJson('/api/v1/swipes', ['target_id' => $bob->uuid, 'action' => 'like']);

        $conversation = Conversation::query()->firstOrFail();

        $this->postJson('/api/v1/blocks', ['blocked_id' => $bob->uuid])->assertCreated();

        // Leaving the match in place would keep the blocked person in the
        // blocker's list, which defeats the point.
        $this->assertDatabaseHas('matches', ['status' => 'blocked']);

        // And the block has to be something a member can feel. Asserting only
        // the status column is what let a blocked member carry on messaging.
        $this->assertSame('closed', $conversation->fresh()->status);

        Sanctum::actingAs($bob, ['*']);

        $this->postJson("/api/v1/conversations/{$conversation->uuid}/messages", [
            'body' => 'are you still there?',
        ])->assertForbidden();

        $this->getJson("/api/v1/conversations/{$conversation->uuid}/messages")->assertForbidden();

        $this->assertSame([], $this->getJson('/api/v1/conversations')->assertOk()->json('data'));
    }

    public function test_a_closed_conversation_refuses_reads_and_writes_through_the_api(): void
    {
        $alice = $this->member();
        $bob = $this->member(['email' => 'bob6@example.test']);

        Sanctum::actingAs($bob, ['*']);
        $this->postJson('/api/v1/swipes', ['target_id' => $alice->uuid, 'action' => 'like']);

        Sanctum::actingAs($alice, ['*']);
        $this->postJson('/api/v1/swipes', ['target_id' => $bob->uuid, 'action' => 'like']);

        $conversation = Conversation::query()->firstOrFail();
        $conversation->forceFill(['status' => 'closed'])->save();

        // The website has always refused this. The API used to accept it,
        // because the rule lived in a Livewire component.
        $this->postJson("/api/v1/conversations/{$conversation->uuid}/messages", [
            'body' => 'hello?',
        ])->assertForbidden();

        $this->getJson("/api/v1/conversations/{$conversation->uuid}/messages")->assertForbidden();
    }

    public function test_unmatching_through_the_api_closes_the_conversation(): void
    {
        $alice = $this->member();
        $bob = $this->member(['email' => 'bob7@example.test']);

        Sanctum::actingAs($bob, ['*']);
        $this->postJson('/api/v1/swipes', ['target_id' => $alice->uuid, 'action' => 'like']);

        Sanctum::actingAs($alice, ['*']);
        $this->postJson('/api/v1/swipes', ['target_id' => $bob->uuid, 'action' => 'like']);

        $match = MatchRecord::query()->firstOrFail();
        $conversation = Conversation::query()->firstOrFail();

        $this->deleteJson("/api/v1/matches/{$match->uuid}")->assertOk();

        $this->assertSame('closed', $conversation->fresh()->status);

        // The person who was unmatched must not be able to keep writing into
        // a match that no longer exists.
        Sanctum::actingAs($bob, ['*']);
        $this->postJson("/api/v1/conversations/{$conversation->uuid}/messages", [
            'body' => 'why did you go quiet?',
        ])->assertForbidden();
    }

    public function test_report_evidence_must_belong_to_the_reported_member(): void
    {
        $alice = $this->member();
        $bob = $this->member(['email' => 'bob8@example.test']);
        $carol = $this->member(['email' => 'carol@example.test']);

        Sanctum::actingAs($bob, ['*']);
        $this->postJson('/api/v1/swipes', ['target_id' => $alice->uuid, 'action' => 'like']);

        Sanctum::actingAs($alice, ['*']);
        $this->postJson('/api/v1/swipes', ['target_id' => $bob->uuid, 'action' => 'like']);

        $conversation = Conversation::query()->firstOrFail();

        Sanctum::actingAs($bob, ['*']);
        $this->postJson("/api/v1/conversations/{$conversation->uuid}/messages", ['body' => 'something bob said']);

        $bobsMessage = Message::query()->where('sender_app_user_id', $bob->id)->firstOrFail();

        // Alice is genuinely in this conversation, so the id is one she can
        // legitimately hold — she just points it at somebody it is not about.
        Sanctum::actingAs($alice, ['*']);
        $this->postJson('/api/v1/reports', [
            'reported_id' => $carol->uuid,
            'category' => 'harassment',
            'message_id' => $bobsMessage->uuid,
        ])->assertCreated();

        $againstCarol = Report::query()->where('reported_app_user_id', $carol->id)->firstOrFail();

        // The report stands — it may be genuine — but it carries no evidence
        // from a conversation Carol was never part of. Otherwise a moderator
        // opens a case against her and reveals somebody else's private message.
        $this->assertNull($againstCarol->message_id);
        $this->assertNull($againstCarol->conversation_id);

        // The honest version of the same report still anchors its evidence.
        $this->postJson('/api/v1/reports', [
            'reported_id' => $bob->uuid,
            'category' => 'harassment',
            'message_id' => $bobsMessage->uuid,
        ])->assertCreated();

        $againstBob = Report::query()->where('reported_app_user_id', $bob->id)->firstOrFail();

        $this->assertSame($bobsMessage->id, $againstBob->message_id);
    }

    public function test_a_one_sided_age_update_cannot_create_an_impossible_range(): void
    {
        $member = $this->member();
        $member->preferences->update(['age_min' => 22, 'age_max' => 28]);

        Sanctum::actingAs($member, ['*']);

        // Already refused, because the cross-field rule sits on age_max.
        $this->patchJson('/api/v1/me/preferences', ['age_max' => 20])->assertStatus(422);

        // The mirror image used to pass, leaving 60..28 stored and a deck that
        // was empty for ever with nothing on screen to explain it.
        $this->patchJson('/api/v1/me/preferences', ['age_min' => 60])->assertStatus(422);

        $member->refresh()->load('preferences');
        $this->assertSame(22, $member->preferences->age_min);
        $this->assertSame(28, $member->preferences->age_max);

        // Moving the whole range in one request is still fine.
        $this->patchJson('/api/v1/me/preferences', ['age_min' => 40, 'age_max' => 55])->assertOk();
    }

    public function test_the_verification_gesture_code_must_be_the_one_the_server_issued(): void
    {
        Storage::fake('verifications');

        $member = $this->member();
        Sanctum::actingAs($member, ['*']);

        // A code the client chose. The whole anti-replay property rests on the
        // server having issued it, so this must be refused.
        $this->postJson('/api/v1/verification', [
            'selfie' => UploadedFile::fake()->image('selfie.jpg'),
            'gesture_code' => 'ZZ99',
        ])->assertStatus(422);

        $this->assertDatabaseCount('verifications', 0);

        $issued = $this->getJson('/api/v1/verification/gesture')->assertOk()->json('gesture_code');

        $this->postJson('/api/v1/verification', [
            'selfie' => UploadedFile::fake()->image('selfie.jpg'),
            'gesture_code' => $issued,
        ])->assertStatus(202);

        $this->assertDatabaseHas('verifications', ['app_user_id' => $member->id, 'gesture_code' => $issued]);

        // Single use: the same code cannot anchor a second, different image.
        $this->postJson('/api/v1/verification', [
            'selfie' => UploadedFile::fake()->image('another.jpg'),
            'gesture_code' => $issued,
        ])->assertStatus(422);

        $this->assertDatabaseCount('verifications', 1);
    }

    /**
     * "It's a match" and "you have a new message" are the loop a dating app
     * runs on. Both templates were editable in the console from the start and
     * referenced by nothing, so neither was ever delivered — push_logs held 41
     * campaign rows and not one event-driven send.
     */
    public function test_a_new_match_notifies_both_people(): void
    {
        Queue::fake();

        $alice = $this->member();
        $bob = $this->member(['email' => 'bobmatch@example.test']);

        Sanctum::actingAs($bob, ['*']);
        $this->postJson('/api/v1/swipes', ['target_id' => $alice->uuid, 'action' => 'like']);

        // A one-sided like is not a match, and must not notify anybody.
        Queue::assertNotPushed(SendMemberPush::class);

        Sanctum::actingAs($alice, ['*']);
        $this->postJson('/api/v1/swipes', ['target_id' => $bob->uuid, 'action' => 'like'])->assertCreated();

        Queue::assertPushed(SendMemberPush::class, 2);
    }

    public function test_a_new_message_notifies_the_recipient_only(): void
    {
        $alice = $this->member();
        $bob = $this->member(['email' => 'bobmsg@example.test']);

        Sanctum::actingAs($bob, ['*']);
        $this->postJson('/api/v1/swipes', ['target_id' => $alice->uuid, 'action' => 'like']);

        Sanctum::actingAs($alice, ['*']);
        $this->postJson('/api/v1/swipes', ['target_id' => $bob->uuid, 'action' => 'like']);

        $conversation = Conversation::query()->firstOrFail();

        // Faked only now, so the match notifications above are not counted.
        Queue::fake();

        $this->postJson("/api/v1/conversations/{$conversation->uuid}/messages", [
            'body' => 'hello there',
        ])->assertCreated();

        Queue::assertPushed(SendMemberPush::class, 1);
    }

    public function test_the_message_counters_are_incremented_in_sql(): void
    {
        $alice = $this->member();
        $bob = $this->member(['email' => 'bob9@example.test']);

        Sanctum::actingAs($bob, ['*']);
        $this->postJson('/api/v1/swipes', ['target_id' => $alice->uuid, 'action' => 'like']);

        Sanctum::actingAs($alice, ['*']);
        $this->postJson('/api/v1/swipes', ['target_id' => $bob->uuid, 'action' => 'like']);

        $conversation = Conversation::query()->firstOrFail();
        $match = MatchRecord::query()->firstOrFail();

        /*
         * A stale in-memory model is what a concurrent request looks like from
         * the database's point of view: it holds a count read before the other
         * write landed. Read-then-write loses one of the two; an SQL increment
         * does not.
         */
        $stale = Conversation::query()->whereKey($conversation->id)->firstOrFail();
        $conversation->forceFill(['messages_count' => 5])->save();
        $match->forceFill(['messages_count' => 5])->save();

        app(MessageSender::class)->send($stale, $alice, 'counting');

        $this->assertSame(6, $conversation->fresh()->messages_count);
        $this->assertSame(6, $match->fresh()->messages_count);
    }

    public function test_the_daily_like_limit_cannot_be_walked_past(): void
    {
        Setting::query()->updateOrCreate(['key' => 'matching.daily_like_limit_free'], [
            'value' => 2, 'type' => 'number', 'group' => 'matching',
        ]);
        Setting::flush();

        $member = $this->member();
        Sanctum::actingAs($member, ['*']);

        foreach (range(1, 2) as $i) {
            $target = $this->member(['email' => "target{$i}@example.test"]);
            $this->postJson('/api/v1/swipes', ['target_id' => $target->uuid, 'action' => 'like'])->assertCreated();
        }

        $third = $this->member(['email' => 'target3@example.test']);
        $this->postJson('/api/v1/swipes', ['target_id' => $third->uuid, 'action' => 'like'])->assertStatus(422);

        // The refused swipe must leave nothing behind: the count it is measured
        // against is the swipes table itself.
        $this->assertSame(2, Swipe::query()->where('app_user_id', $member->id)->count());
    }

    /**
     * The race itself cannot be reproduced in a single-threaded test, so this
     * asserts the mechanism instead: the count must be taken behind a lock on
     * the member's row, and that lock must be held before the swipe is written.
     * Counting outside the transaction is what let parallel requests all read
     * the same total and every one of them pass the cap.
     */
    public function test_the_like_limit_is_counted_behind_a_lock(): void
    {
        $member = $this->member();
        $target = $this->member(['email' => 'locktarget@example.test']);

        Sanctum::actingAs($member, ['*']);

        $statements = [];
        DB::listen(function ($query) use (&$statements): void {
            $statements[] = strtolower($query->sql);
        });

        $this->postJson('/api/v1/swipes', ['target_id' => $target->uuid, 'action' => 'like'])->assertCreated();

        $lockedAt = $this->firstIndexMatching($statements, fn (string $sql): bool => str_contains($sql, 'from `app_users`') && str_contains($sql, 'for update'));

        $countedAt = $this->firstIndexMatching($statements, fn (string $sql): bool => str_contains($sql, 'from `swipes`') && str_contains($sql, 'count('));

        $insertedAt = $this->firstIndexMatching($statements, fn (string $sql): bool => str_starts_with($sql, 'insert into `swipes`'));

        $this->assertNotNull($lockedAt, 'EXPECTED the member row to be locked for update.');
        $this->assertNotNull($countedAt, 'EXPECTED the daily likes to be counted.');
        $this->assertNotNull($insertedAt, 'EXPECTED the swipe to be written.');

        $this->assertLessThan($countedAt, $lockedAt, 'EXPECTED the lock to be taken before the count.');
        $this->assertLessThan($insertedAt, $countedAt, 'EXPECTED the count to happen before the insert.');
    }

    /** @param  array<int, string>  $statements */
    private function firstIndexMatching(array $statements, callable $matches): ?int
    {
        foreach ($statements as $index => $sql) {
            if ($matches($sql)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * A swipe decision is final.
     *
     * updateOrCreate let a second swipe rewrite the first, so a pass could be
     * turned into a like over the API — a free undo, and a way to reverse a
     * pass and collect a match from somebody who had already liked you.
     */
    /**
     * Re-swiping the same person replaces the earlier decision.
     *
     * Free rewind is intended, so a second swipe updates the row rather than
     * being refused, and a pass may become a like. The unique key on
     * (app_user_id, target_app_user_id) is what keeps that an update instead
     * of a second row, so the swipe history stays one decision per pair.
     */
    public function test_re_swiping_the_same_person_replaces_the_earlier_decision(): void
    {
        $member = $this->member();
        $target = $this->member(['email' => 'reswipe@example.test']);

        // The target liked first, so flipping the pass completes a match.
        Sanctum::actingAs($target, ['*']);
        $this->postJson('/api/v1/swipes', ['target_id' => $member->uuid, 'action' => 'like']);

        Sanctum::actingAs($member, ['*']);
        $this->postJson('/api/v1/swipes', ['target_id' => $target->uuid, 'action' => 'pass'])->assertCreated();

        $this->postJson('/api/v1/swipes', ['target_id' => $target->uuid, 'action' => 'like'])->assertCreated();

        $swipes = Swipe::query()
            ->where('app_user_id', $member->id)
            ->where('target_app_user_id', $target->id)
            ->get();

        $this->assertCount(1, $swipes, 'EXPECTED one row per pair, updated rather than added to.');
        $this->assertSame('like', $swipes->first()->action);
    }

    public function test_a_blocked_member_disappears_from_the_deck(): void
    {
        $alice = $this->member();
        $bob = $this->member(['email' => 'bob5@example.test']);

        Sanctum::actingAs($alice, ['*']);
        $this->postJson('/api/v1/blocks', ['blocked_id' => $bob->uuid])->assertCreated();

        $deck = $this->getJson('/api/v1/deck')->assertOk()->json('data');

        $this->assertNotContains($bob->uuid, array_column($deck, 'id'));
    }

    // ---------------------------------------------------------------- limits

    /**
     * max_distance_km was collected on both clients, validated, stored and
     * advertised by /config — and the deck filtered by city_id alone, so a
     * member who set 5 km and one who set 500 km got identical decks.
     */
    public function test_the_deck_honours_the_members_distance_preference(): void
    {
        // Paris, and three candidates placed relative to it. A degree of
        // latitude is ~111 km anywhere, so these distances are exact enough
        // to sit either side of a 50 km radius without ambiguity.
        $me = $this->memberAt('me@example.test', 48.8566, 2.3522);
        $near = $this->memberAt('near@example.test', 48.9000, 2.3522);      // ~5 km
        $far = $this->memberAt('far@example.test', 50.6566, 2.3522);        // ~200 km
        $nowhere = $this->memberAt('nowhere@example.test', null, null);

        $me->preferences->update(['max_distance_km' => 50, 'global_mode' => false]);

        Sanctum::actingAs($me->fresh()->load('preferences'), ['*']);

        $ids = array_column($this->getJson('/api/v1/deck')->assertOk()->json('data'), 'id');

        $this->assertContains($near->uuid, $ids, 'EXPECTED somebody 5 km away inside a 50 km radius.');
        $this->assertNotContains($far->uuid, $ids, 'EXPECTED somebody 200 km away to be outside a 50 km radius.');

        // An unknown location cannot be shown to satisfy a radius.
        $this->assertNotContains($nowhere->uuid, $ids);

        // Global mode still means everywhere, coordinates or not.
        $me->preferences->update(['global_mode' => true]);
        Sanctum::actingAs($me->fresh()->load('preferences'), ['*']);

        $globalIds = array_column($this->getJson('/api/v1/deck')->assertOk()->json('data'), 'id');

        $this->assertContains($far->uuid, $globalIds);
        $this->assertContains($nowhere->uuid, $globalIds);
    }

    public function test_the_deck_is_not_ordered_by_rand(): void
    {
        $me = $this->memberAt('sorter@example.test', 48.8566, 2.3522);
        $this->memberAt('candidate@example.test', 48.9000, 2.3522);

        $me->preferences->update(['max_distance_km' => 50, 'global_mode' => false]);

        Sanctum::actingAs($me->fresh()->load('preferences'), ['*']);

        $statements = [];
        DB::listen(function ($query) use (&$statements): void {
            $statements[] = strtolower($query->sql);
        });

        $this->getJson('/api/v1/deck')->assertOk();

        $deckQueries = array_filter($statements, fn (string $sql): bool => str_contains($sql, 'from `app_users`'));

        $this->assertNotEmpty($deckQueries);

        foreach ($deckQueries as $sql) {
            // ORDER BY RAND() materialises and sorts every candidate on the
            // hottest query in the product.
            $this->assertStringNotContainsString('rand()', $sql);
        }
    }

    private function memberAt(string $email, ?float $latitude, ?float $longitude): AppUser
    {
        $member = $this->member(['email' => $email]);

        $member->forceFill([
            'last_latitude' => $latitude,
            'last_longitude' => $longitude,
            'city_id' => null,
        ])->save();

        return $member->fresh()->load('preferences');
    }

    public function test_the_daily_like_limit_applies_to_free_accounts(): void
    {
        // Through the model, so the settings cache is invalidated the same way
        // it would be when an operator saves the value in the console.
        Setting::put('matching.daily_like_limit_free', '2');

        $member = $this->member();
        Sanctum::actingAs($member, ['*']);

        foreach (range(1, 2) as $i) {
            $target = $this->member(['email' => "target-{$i}@example.test"]);
            $this->postJson('/api/v1/swipes', ['target_id' => $target->uuid, 'action' => 'like'])
                ->assertCreated();
        }

        $third = $this->member(['email' => 'target-3@example.test']);

        $this->postJson('/api/v1/swipes', ['target_id' => $third->uuid, 'action' => 'like'])
            ->assertStatus(422);

        // A pass is not a like, so it is still allowed.
        $fourth = $this->member(['email' => 'target-4@example.test']);
        $this->postJson('/api/v1/swipes', ['target_id' => $fourth->uuid, 'action' => 'pass'])
            ->assertCreated();
    }

    public function test_the_public_config_endpoint_exposes_only_public_settings(): void
    {
        $response = $this->getJson('/api/v1/config')->assertOk();

        $body = $response->content();

        // Internal thresholds must never reach a client: publishing them tells
        // anybody gaming the system exactly where the lines are.
        foreach (['auto_limit_at', 'auto_queue_at', 'approve_threshold', 'shadow_ban'] as $internal) {
            $this->assertStringNotContainsString($internal, $body);
        }
    }

    public function test_interests_are_available_without_authentication(): void
    {
        $this->getJson('/api/v1/interests')
            ->assertOk()
            ->assertJsonStructure(['data' => [['slug', 'name', 'category']]]);
    }
}
