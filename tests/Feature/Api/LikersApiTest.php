<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\AccountStatus;
use App\Models\AppUser;
use App\Models\Block;
use App\Models\Plan;
use App\Models\Preference;
use App\Models\Profile;
use App\Models\Swipe;
use App\Services\Billing\Subscriptions;
use Database\Seeders\InterestSeeder;
use Database\Seeders\MasterSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LikersApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, InterestSeeder::class, SettingSeeder::class, MasterSeeder::class]);
    }

    private function member(array $attributes = []): AppUser
    {
        $member = AppUser::factory()->create($attributes + ['account_status' => AccountStatus::Active]);

        Profile::query()->create(['app_user_id' => $member->id]);
        Preference::query()->create([
            'app_user_id' => $member->id,
            'interested_in' => ['woman', 'man', 'non_binary', 'other'],
            'age_min' => 18,
            'age_max' => 99,
        ]);

        return $member->fresh();
    }

    private function likes(AppUser $from, AppUser $to, bool $isMatch = false): void
    {
        Swipe::query()->create([
            'app_user_id' => $from->id,
            'target_app_user_id' => $to->id,
            'action' => 'like',
            'source' => 'deck',
            'is_match' => $isMatch,
            'created_at' => now(),
        ]);
    }

    private function makePremium(AppUser $member): void
    {
        // 'plus' carries see_likers in the seeded catalogue.
        app(Subscriptions::class)->grant($member, Plan::query()->where('slug', 'plus')->firstOrFail(), now()->addMonth());
    }

    public function test_a_free_member_gets_the_count_but_not_the_people(): void
    {
        $me = $this->member();
        $this->likes($this->member(['email' => 'a@example.test']), $me);
        $this->likes($this->member(['email' => 'b@example.test']), $me);

        Sanctum::actingAs($me, ['*']);

        $response = $this->getJson('/api/v1/me/likers')->assertForbidden()->assertJsonPath('code', 'premium_required');

        $this->assertSame(2, $response->json('count'));

        // The upsell must not leak who: no ids, names or photos in the refusal.
        $this->assertArrayNotHasKey('data', $response->json());
        $this->assertStringNotContainsString('a@example.test', $response->content());
    }

    public function test_a_premium_member_sees_who_liked_them(): void
    {
        $me = $this->member();
        $fan = $this->member(['email' => 'fan@example.test', 'display_name' => 'Fan Person']);
        $this->likes($fan, $me);
        $this->makePremium($me);

        Sanctum::actingAs($me->fresh(), ['*']);

        $response = $this->getJson('/api/v1/me/likers')->assertOk()
            ->assertJsonStructure(['data' => [['id', 'display_name', 'age', 'is_verified', 'photos']], 'meta' => ['next_cursor', 'count']]);

        $this->assertSame([$fan->uuid], array_column($response->json('data'), 'id'));
        $this->assertSame(1, $response->json('meta.count'));

        // Through AppUserResource: nothing private about the liker.
        $this->assertStringNotContainsString('fan@example.test', $response->content());
        $this->assertStringNotContainsString('birthdate', $response->content());
    }

    /**
     * Every exclusion is a safety rule. Anyone who slips through here is
     * somebody the member chose not to see, or somebody who chose not to be
     * seen by them.
     */
    public function test_likers_excludes_the_swiped_the_blocked_the_matched_and_the_inactive(): void
    {
        $me = $this->member();
        $this->makePremium($me);

        $genuine = $this->member(['email' => 'genuine@example.test']);
        $this->likes($genuine, $me);

        // I already passed on them.
        $passed = $this->member(['email' => 'passed@example.test']);
        $this->likes($passed, $me);
        Swipe::query()->create(['app_user_id' => $me->id, 'target_app_user_id' => $passed->id, 'action' => 'pass', 'source' => 'deck', 'created_at' => now()]);

        // I blocked them.
        $iBlocked = $this->member(['email' => 'iblocked@example.test']);
        $this->likes($iBlocked, $me);
        Block::query()->create(['app_user_id' => $me->id, 'blocked_app_user_id' => $iBlocked->id, 'created_at' => now()]);

        // They blocked me (after liking — or a stale like on a block).
        $blockedMe = $this->member(['email' => 'blockedme@example.test']);
        $this->likes($blockedMe, $me);
        Block::query()->create(['app_user_id' => $blockedMe->id, 'blocked_app_user_id' => $me->id, 'created_at' => now()]);

        // Already a match: they belong in Matches, not here.
        $matched = $this->member(['email' => 'matched@example.test']);
        $this->likes($matched, $me, isMatch: true);

        // Not active any more.
        $suspended = $this->member(['email' => 'suspended@example.test']);
        $this->likes($suspended, $me);
        $suspended->forceFill(['account_status' => AccountStatus::Suspended])->save();

        Sanctum::actingAs($me->fresh(), ['*']);

        $ids = array_column($this->getJson('/api/v1/me/likers')->assertOk()->json('data'), 'id');

        $this->assertSame([$genuine->uuid], $ids);
    }
}
