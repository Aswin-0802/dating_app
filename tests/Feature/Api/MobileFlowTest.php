<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Actions\Moderation\ApplyEnforcement;
use App\Enums\AccountStatus;
use App\Enums\LadderStep;
use App\Enums\ReasonCode;
use App\Models\AppUser;
use App\Models\Conversation;
use App\Models\MatchRecord;
use App\Models\Preference;
use App\Models\Profile;
use App\Models\ReportCase;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\InterestSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        $this->postJson('/api/v1/blocks', ['blocked_id' => $bob->uuid])->assertCreated();

        // Leaving the match in place would keep the blocked person in the
        // blocker's list, which defeats the point.
        $this->assertDatabaseHas('matches', ['status' => 'blocked']);
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
