<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Livewire\Member\Auth\Login;
use App\Livewire\Member\Auth\Register;
use App\Livewire\Member\Discover;
use App\Livewire\Member\Messages;
use App\Livewire\Member\Profile as ProfileEditor;
use App\Livewire\Member\Restricted;
use App\Models\Appeal;
use App\Models\AppUser;
use App\Models\AppUserLogin;
use App\Models\Ban;
use App\Models\Block;
use App\Models\City;
use App\Models\Conversation;
use App\Models\MatchRecord;
use App\Models\Photo;
use App\Models\Preference;
use App\Models\Profile;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Swipe;
use App\Models\User;
use App\Services\Members\MessageSender;
use Database\Seeders\GeographySeeder;
use Database\Seeders\InterestSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class MemberWebsiteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, InterestSeeder::class, SettingSeeder::class, GeographySeeder::class]);
    }

    private function member(array $attributes = []): AppUser
    {
        $member = AppUser::factory()->create($attributes + ['city_id' => null]);

        Profile::query()->create(['app_user_id' => $member->id]);
        Preference::query()->create([
            'app_user_id' => $member->id,
            'interested_in' => ['woman', 'man', 'non_binary', 'other'],
            'age_min' => 18,
            'age_max' => 99,
            'global_mode' => true,
        ]);

        return $member->fresh();
    }

    // ---- public website -------------------------------------------------------

    public function test_the_home_page_shows_the_brand(): void
    {
        Setting::put('brand.name', 'Amora');

        $this->get('/')
            ->assertOk()
            ->assertSee('Amora')
            ->assertSee(route('member.register'));
    }

    public function test_switching_the_website_off_sends_visitors_to_sign_in(): void
    {
        Setting::put('website.enabled', false);

        $this->get('/')->assertRedirect(route('member.login'));
    }

    public function test_the_public_pages_render(): void
    {
        foreach (['/safety', '/terms', '/privacy', '/login', '/join'] as $path) {
            $this->get($path)->assertOk();
        }
    }

    // ---- accounts -------------------------------------------------------------

    public function test_a_visitor_can_sign_up(): void
    {
        $city = City::query()->firstOrFail();

        Livewire::test(Register::class)
            ->set('display_name', 'Maya')
            ->set('email', 'maya@example.test')
            ->set('password', 'secret123')
            ->set('birthdate', now()->subYears(29)->toDateString())
            ->call('next')
            ->assertHasNoErrors()
            ->assertSet('step', 2)
            ->set('gender', 'woman')
            ->set('interested_in', ['man'])
            ->set('city_id', $city->id)
            ->set('terms', true)
            ->call('register')
            ->assertHasNoErrors()
            ->assertRedirect(route('member.profile'));

        $member = AppUser::query()->where('email', 'maya@example.test')->firstOrFail();

        $this->assertSame('web', $member->signup_source);
        $this->assertSame(AccountStatus::Pending, $member->account_status);
        $this->assertAuthenticatedAs($member, 'member');
    }

    public function test_an_under_age_visitor_cannot_sign_up(): void
    {
        Livewire::test(Register::class)
            ->set('display_name', 'Kid')
            ->set('email', 'kid@example.test')
            ->set('password', 'secret123')
            ->set('birthdate', now()->subYears(16)->toDateString())
            ->call('next')
            ->assertHasErrors('birthdate');
    }

    public function test_sign_in_records_failures_and_successes(): void
    {
        $member = $this->member(['email' => 'sam@example.test']);

        Livewire::test(Login::class)
            ->set('email', 'sam@example.test')
            ->set('password', 'wrong-password')
            ->call('login')
            ->assertHasErrors('email');

        Livewire::test(Login::class)
            ->set('email', 'sam@example.test')
            ->set('password', 'password')
            ->call('login')
            ->assertRedirect(route('member.discover'));

        $this->assertSame(1, AppUserLogin::query()->where('app_user_id', $member->id)->where('succeeded', false)->count());
        $this->assertSame(1, AppUserLogin::query()->where('app_user_id', $member->id)->where('succeeded', true)->count());
    }

    public function test_staff_and_member_sessions_do_not_grant_each_other_anything(): void
    {
        // Signed in as a member, on a request whose default guard is still
        // `web` — exactly the situation in a real browser.
        $this->actingAs($this->member(), 'member');
        $this->app['auth']->shouldUse('web');

        $this->get('/admin')->assertRedirect('/admin/login');

        $this->app['auth']->forgetGuards();

        $staff = User::factory()->create(['status' => 'active'])->syncRoles([Role::SUPER_ADMIN]);

        $this->actingAs($staff)
            ->get('/app/discover')
            ->assertRedirect('/login');
    }

    // ---- the app --------------------------------------------------------------

    public function test_the_member_pages_render(): void
    {
        $this->actingAs($this->member(), 'member');

        foreach (['discover', 'matches', 'messages', 'profile', 'verification', 'premium', 'account'] as $page) {
            $this->get("/app/{$page}")->assertOk();
        }
    }

    public function test_liking_somebody_who_liked_you_makes_a_match_you_can_message(): void
    {
        $me = $this->member();
        $them = $this->member(['display_name' => 'Jordan']);

        Swipe::query()->create([
            'app_user_id' => $them->id,
            'target_app_user_id' => $me->id,
            'action' => 'like',
            'source' => 'deck',
            'created_at' => now(),
        ]);

        $this->actingAs($me, 'member');

        Livewire::test(Discover::class)
            ->assertSee('Jordan')
            ->call('swipe', 'like')
            ->assertSet('matchedWith', $them->uuid);

        $match = MatchRecord::query()->involving($me)->involving($them)->firstOrFail();
        $conversation = $match->conversation;

        $this->assertTrue($conversation->hasParticipant($me));
        $this->assertTrue($conversation->hasParticipant($them));

        Livewire::test(Messages::class, ['conversation' => $conversation])
            ->set('body', 'Hello Jordan')
            ->call('send')
            ->assertHasNoErrors()
            ->assertSee('Hello Jordan');
    }

    public function test_a_conversation_is_invisible_to_anybody_not_in_it(): void
    {
        $a = $this->member();
        $b = $this->member();
        $stranger = $this->member();

        $match = MatchRecord::query()->create([
            'app_user_one_id' => $a->id,
            'app_user_two_id' => $b->id,
            'matched_at' => now(),
            'status' => 'active',
        ]);
        $conversation = Conversation::query()->create(['match_id' => $match->id, 'status' => 'open']);
        $conversation->participants()->attach([$a->id, $b->id]);
        app(MessageSender::class)->send($conversation, $a, 'A private message');

        $this->actingAs($stranger, 'member')
            ->get(route('member.messages', $conversation))
            ->assertNotFound();

        $this->actingAs($b, 'member')
            ->get(route('member.messages', $conversation))
            ->assertOk()
            ->assertSee('A private message');
    }

    public function test_a_blocked_member_never_appears_in_the_deck(): void
    {
        $me = $this->member();
        $blocked = $this->member(['display_name' => 'Blocked Person']);

        Block::query()->create(['app_user_id' => $me->id, 'blocked_app_user_id' => $blocked->id, 'created_at' => now()]);

        $this->actingAs($me, 'member');

        Livewire::test(Discover::class)->assertDontSee('Blocked Person');
    }

    public function test_the_deck_widens_beyond_an_exhausted_city_and_says_so(): void
    {
        [$home, $elsewhere] = City::query()->limit(2)->get()->all();

        $me = $this->member(['city_id' => $home->id]);
        $me->preferences->update(['global_mode' => false]);
        $this->member(['city_id' => $elsewhere->id, 'display_name' => 'Faraway Friend']);

        $this->actingAs($me->fresh(), 'member');

        Livewire::test(Discover::class)
            ->assertSet('widened', true)
            ->assertSee('Faraway Friend')
            ->assertSee('showing people further away');
    }

    public function test_a_shadow_banned_member_uses_the_website_normally(): void
    {
        // A website that behaves differently for a shadow-banned account makes
        // the restriction detectable.
        $this->actingAs($this->member(['account_status' => AccountStatus::ShadowBanned]), 'member')
            ->get('/app/discover')
            ->assertOk();
    }

    public function test_a_suspended_member_is_told_why_and_can_appeal(): void
    {
        $moderator = User::factory()->create(['status' => 'active'])->syncRoles([Role::MODERATOR]);
        $me = $this->member(['account_status' => AccountStatus::Suspended, 'suspended_until' => now()->addDays(3)]);

        $ban = Ban::query()->create([
            'uuid' => (string) Str::uuid(),
            'app_user_id' => $me->id,
            'type' => 'suspension',
            'reason_code' => 'harassment_confirmed',
            'user_facing_message' => 'Your account is suspended for harassment.',
            'issued_by' => $moderator->id,
            'starts_at' => now(),
            'expires_at' => now()->addDays(3),
        ]);

        $this->actingAs($me, 'member')
            ->get('/app/discover')
            ->assertRedirect(route('member.restricted'));

        $this->get(route('member.restricted'))
            ->assertOk()
            ->assertSee('Your account is suspended for harassment.');

        Livewire::test(Restricted::class)
            ->set('statement', 'I think this was a misunderstanding about a joke we both made.')
            ->call('appeal')
            ->assertHasNoErrors();

        $appeal = Appeal::query()->where('ban_id', $ban->id)->firstOrFail();

        // The appeal carries the original decider, so it can never be routed
        // back to them.
        $this->assertSame($moderator->id, $appeal->original_decider_id);
    }

    public function test_an_uploaded_photo_is_re_encoded_and_waits_for_moderation(): void
    {
        Storage::fake('public');
        $me = $this->member();

        $this->actingAs($me, 'member');

        Livewire::test(ProfileEditor::class)
            ->set('uploads', [UploadedFile::fake()->image('me.jpg', 900, 1200)])
            ->assertHasNoErrors();

        $photo = Photo::query()->where('app_user_id', $me->id)->firstOrFail();

        $this->assertSame('pending', $photo->moderation_status);
        $this->assertTrue($photo->is_primary);
        $this->assertStringEndsWith('.jpg', $photo->path);
        Storage::disk('public')->assertExists([$photo->path, $photo->thumb_path]);
    }
}
