<?php

declare(strict_types=1);

namespace Tests\Uat;

use App\Enums\AccountStatus;
use App\Livewire\Member\Account;
use App\Livewire\Member\Auth\Login;
use App\Livewire\Member\Auth\Register;
use App\Livewire\Member\Discover;
use App\Livewire\Member\Matches;
use App\Livewire\Member\Messages;
use App\Livewire\Member\Profile;
use App\Livewire\Member\Verification;
use App\Models\AppUser;
use App\Models\Block;
use App\Models\City;
use App\Models\Conversation;
use App\Models\MatchRecord;
use App\Models\Photo;
use App\Models\Report;
use App\Models\Setting;
use App\Models\Swipe;
use App\Support\Branding;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * UAT — public website, member app and mobile API.
 */
class UatMemberTest extends UatTestCase
{
    private function activeMember(): AppUser
    {
        return AppUser::query()->where('account_status', 'active')->whereHas('preferences')->firstOrFail();
    }

    /** Two active members with an open conversation. */
    private function pair(): array
    {
        $a = $this->activeMember();
        $b = AppUser::query()->where('account_status', 'active')->where('id', '!=', $a->id)->firstOrFail();
        MatchRecord::query()->involving($a)->involving($b)->delete();

        $match = MatchRecord::query()->create(['app_user_one_id' => $a->id, 'app_user_two_id' => $b->id, 'matched_at' => now(), 'status' => 'active']);
        $conversation = Conversation::query()->create(['match_id' => $match->id, 'status' => 'open']);
        $conversation->participants()->attach([$a->id, $b->id]);

        return [$a, $b, $conversation->fresh(), $match];
    }

    private function registerForm(array $overrides = [])
    {
        $data = $overrides + [
            'display_name' => 'Robin', 'email' => 'robin@example.test', 'password' => 'secret123',
            'birthdate' => now()->subYears(30)->toDateString(),
        ];

        $c = Livewire::test(Register::class);
        foreach ($data as $k => $v) {
            $c->set($k, $v);
        }

        return $c;
    }

    // ================================================================ P. public site

    public function test_p01_public_pages_render(): void
    {
        foreach (['/', '/safety', '/terms', '/privacy', '/login', '/join'] as $path) {
            $this->assertSame(200, $this->get($path)->status(), "EXPECTED {$path} to open");
        }
    }

    public function test_p02_no_developer_text_on_public_pages(): void
    {
        foreach (['/', '/login', '/join', '/safety', '/terms', '/privacy'] as $path) {
            $text = strip_tags($this->get($path)->getContent());
            foreach (['Local demo', 'lorem', 'Lorem', 'placeholder', 'TODO', 'password</span>', 'Template text'] as $needle) {
                $this->assertStringNotContainsString($needle, $text, "EXPECTED no developer/demo text \"{$needle}\" on {$path}");
            }
        }
    }

    public function test_p03_unknown_url_shows_a_branded_404(): void
    {
        $r = $this->get('/this-page-does-not-exist');
        $r->assertNotFound();
        $this->assertStringContainsString(Branding::name(), $r->getContent(), 'EXPECTED the 404 page to carry the brand and a way home.');
    }

    public function test_p04_member_password_reset_exists(): void
    {
        $this->get('/login')->assertSee('Forgot', false);
    }

    // ================================================================ R. registration

    public function test_r01_duplicate_email_is_refused(): void
    {
        $this->registerForm(['email' => $this->activeMember()->email])->call('next')->assertHasErrors('email');
    }

    public function test_r02_duplicate_email_is_case_insensitive(): void
    {
        $this->registerForm(['email' => strtoupper($this->activeMember()->email)])->call('next')->assertHasErrors('email');
    }

    public function test_r03_invalid_inputs_are_refused(): void
    {
        $this->registerForm(['email' => 'not-an-email'])->call('next')->assertHasErrors('email');
        $this->registerForm(['password' => 'short'])->call('next')->assertHasErrors('password');
        $this->registerForm(['password' => 'onlyletters'])->call('next')->assertHasErrors('password');
        $this->registerForm(['display_name' => 'A'])->call('next')->assertHasErrors('display_name');
        $this->registerForm(['display_name' => str_repeat('x', 61)])->call('next')->assertHasErrors('display_name');
        $this->registerForm(['birthdate' => now()->addDay()->toDateString()])->call('next')->assertHasErrors('birthdate');
        $this->registerForm(['birthdate' => now()->subYears(17)->toDateString()])->call('next')->assertHasErrors('birthdate');
    }

    public function test_r04_display_name_rejects_markup_and_blank(): void
    {
        $this->registerForm(['display_name' => '   '])->call('next')->assertHasErrors('display_name');
        $this->registerForm(['display_name' => '<b>Robin</b>'])->call('next')->assertHasErrors('display_name');
    }

    public function test_r05_terms_and_step_two_are_required(): void
    {
        $this->registerForm()->call('next')->assertHasNoErrors()
            ->set('gender', '')->set('interested_in', [])->set('city_id', null)->set('terms', false)
            ->call('register')->assertHasErrors(['gender', 'interested_in', 'city_id', 'terms']);
    }

    public function test_r06_tampered_gender_is_refused(): void
    {
        $this->registerForm()->call('next')->set('gender', 'robot')->set('interested_in', ['woman'])
            ->set('city_id', City::query()->value('id'))->set('terms', true)->call('register')->assertHasErrors('gender');
    }

    // ================================================================ L. sign-in

    public function test_l01_wrong_password_and_unknown_email_give_the_same_message(): void
    {
        $a = Livewire::test(Login::class)->set('email', $this->activeMember()->email)->set('password', 'bad')->call('login')->errors()->first('email');
        $b = Livewire::test(Login::class)->set('email', 'nobody@nowhere.test')->set('password', 'bad')->call('login')->errors()->first('email');
        $this->assertSame($a, $b, 'EXPECTED no account enumeration.');
    }

    public function test_l02_sign_in_is_throttled(): void
    {
        $email = $this->activeMember()->email;
        for ($i = 0; $i < 5; $i++) {
            Livewire::test(Login::class)->set('email', $email)->set('password', 'bad'.$i)->call('login');
        }
        $msg = Livewire::test(Login::class)->set('email', $email)->set('password', 'password')->call('login')->errors()->first('email');
        $this->assertStringContainsString('Too many', (string) $msg);
    }

    public function test_l03_deactivated_and_banned_members(): void
    {
        $m = $this->activeMember();
        $m->forceFill(['account_status' => AccountStatus::Deactivated])->save();
        $this->actingAs($m, 'member')->get('/app/discover')->assertRedirect(route('member.login'));

        $m->forceFill(['account_status' => AccountStatus::Banned])->save();
        $this->actingAs($m->fresh(), 'member')->get('/app/discover')->assertRedirect(route('member.restricted'));
    }

    public function test_l04_guest_is_sent_to_sign_in(): void
    {
        foreach (['discover', 'matches', 'messages', 'profile', 'account'] as $p) {
            $this->get("/app/{$p}")->assertRedirect('/login');
        }
    }

    // ================================================================ S. discover & matching

    public function test_s01_pending_member_cannot_swipe(): void
    {
        $m = $this->activeMember();
        $m->forceFill(['account_status' => AccountStatus::Pending])->save();
        $this->actingAs($m->fresh(), 'member');
        Livewire::test(Discover::class)->assertSee('Finish your profile');
    }

    public function test_s02_daily_like_limit_is_enforced_with_a_message(): void
    {
        Setting::put('matching.daily_like_limit_free', 0);
        $m = $this->activeMember();
        $m->forceFill(['is_premium' => false])->save();
        $m->preferences->update(['global_mode' => true, 'interested_in' => ['woman', 'man', 'non_binary', 'other'], 'age_min' => 18, 'age_max' => 99]);
        $this->actingAs($m->fresh(), 'member');

        Livewire::test(Discover::class)->call('swipe', 'like')->assertSet('limitMessage', fn ($v) => is_string($v) && $v !== '');
    }

    public function test_s03_person_page_rules(): void
    {
        [$a, $b] = $this->pair();
        $this->actingAs($a, 'member');
        $this->get(route('member.person', $b))->assertOk();
        $this->get('/app/people/00000000-0000-0000-0000-000000000000')->assertNotFound();
        $this->get(route('member.person', $a))->assertNotFound();

        Block::query()->create(['app_user_id' => $b->id, 'blocked_app_user_id' => $a->id, 'created_at' => now()]);
        $this->get(route('member.person', $b))->assertNotFound();
    }

    public function test_s04_premium_sees_likers_free_member_does_not(): void
    {
        [$a, $b] = $this->pair();
        MatchRecord::query()->involving($a)->involving($b)->delete();
        Swipe::query()->create(['app_user_id' => $b->id, 'target_app_user_id' => $a->id, 'action' => 'like', 'source' => 'deck', 'created_at' => now()]);

        $a->forceFill(['is_premium' => false])->save();
        $this->actingAs($a->fresh(), 'member');
        Livewire::test(Matches::class)->assertSee('Unlock with Plus')->assertDontSee(route('member.person', $b));

        $a->forceFill(['is_premium' => true, 'premium_tier' => 'plus'])->save();
        $this->actingAs($a->fresh(), 'member');
        Livewire::test(Matches::class)->assertSee(route('member.person', $b));
    }

    // ================================================================ M. messaging

    public function test_m01_empty_and_oversized_messages_are_refused(): void
    {
        [$a, , $c] = $this->pair();
        $this->actingAs($a, 'member');
        Livewire::test(Messages::class, ['conversation' => $c])->set('body', '   ')->call('send')->assertHasErrors('body');
        Livewire::test(Messages::class, ['conversation' => $c])->set('body', str_repeat('a', 2001))->call('send')->assertHasErrors('body');
    }

    public function test_m02_message_markup_is_escaped(): void
    {
        [$a, $b, $c] = $this->pair();
        $this->actingAs($a, 'member');
        Livewire::test(Messages::class, ['conversation' => $c])->set('body', '<script>alert(1)</script>')->call('send');
        $html = $this->actingAs($b, 'member')->get(route('member.messages', $c))->getContent();
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_m03_closed_conversation_cannot_receive_messages(): void
    {
        [$a, , $c] = $this->pair();
        $c->forceFill(['status' => 'closed'])->save();
        $this->actingAs($a, 'member');
        Livewire::test(Messages::class, ['conversation' => $c])->set('body', 'hello')->call('send')->assertForbidden();
    }

    public function test_m04_outsider_cannot_open_or_post(): void
    {
        [, , $c] = $this->pair();
        $outsider = AppUser::query()->where('account_status', 'active')->whereNotIn('id', $c->participants->pluck('id'))->firstOrFail();
        $this->actingAs($outsider, 'member')->get(route('member.messages', $c))->assertNotFound();
    }

    public function test_m05_unmatch_closes_the_conversation(): void
    {
        [$a, , $c, $match] = $this->pair();
        $this->actingAs($a, 'member');
        Livewire::test(Matches::class)->call('unmatch', $match->uuid);
        $this->assertSame('closed', $c->fresh()->status);
    }

    public function test_m06_report_needs_a_category_and_creates_a_case(): void
    {
        [$a, $b, $c] = $this->pair();
        $this->actingAs($a, 'member');
        Livewire::test(Messages::class, ['conversation' => $c])->call('openReport', $b->uuid)->set('reportCategory', '')->call('submitReport')->assertHasErrors('reportCategory');

        Livewire::test(Messages::class, ['conversation' => $c])->call('openReport', $b->uuid)->set('reportCategory', 'harassment')->set('alsoBlock', true)->call('submitReport');
        $report = Report::query()->where('reporter_app_user_id', $a->id)->where('reported_app_user_id', $b->id)->latest('id')->firstOrFail();
        $this->assertNotNull($report->report_case_id, 'EXPECTED the report to be folded into a case.');
        $this->assertTrue(Block::query()->where('app_user_id', $a->id)->where('blocked_app_user_id', $b->id)->exists());
    }

    public function test_m07_reporting_the_same_person_twice_is_deduplicated(): void
    {
        [$a, $b, $c] = $this->pair();
        $this->actingAs($a, 'member');
        foreach ([1, 2, 3] as $i) {
            Livewire::test(Messages::class, ['conversation' => $c])->call('openReport', $b->uuid)->set('reportCategory', 'spam_promotion')->set('alsoBlock', false)->call('submitReport');
        }
        $this->assertLessThanOrEqual(1, Report::query()->where('reporter_app_user_id', $a->id)->where('reported_app_user_id', $b->id)->where('created_at', '>=', now()->subMinute())->count(),
            'EXPECTED repeat reports of the same person by the same reporter within a short window to be deduplicated or throttled.');
    }

    // ================================================================ F. profile

    public function test_f01_profile_validation(): void
    {
        $this->actingAs($this->activeMember(), 'member');
        Livewire::test(Profile::class)->set('bio', str_repeat('x', 501))->call('saveAbout')->assertHasErrors('bio');
        Livewire::test(Profile::class)->set('height_cm', 20)->call('saveAbout')->assertHasErrors('height_cm');
        Livewire::test(Profile::class)->set('relationship_goal', 'hacked')->call('saveAbout')->assertHasErrors('relationship_goal');
        Livewire::test(Profile::class)->set('age_min', 50)->set('age_max', 30)->call('savePreferences')->assertHasErrors('age_max');
        Livewire::test(Profile::class)->set('interested_in', [])->call('savePreferences')->assertHasErrors('interested_in');
    }

    public function test_f02_bio_markup_is_escaped_on_the_card(): void
    {
        [$a, $b] = $this->pair();
        $b->profile->update(['bio' => '<img src=x onerror=alert(1)>']);
        $html = $this->actingAs($a, 'member')->get(route('member.person', $b))->getContent();
        $this->assertStringNotContainsString('<img src=x onerror', $html);
    }

    public function test_f03_photo_upload_rules(): void
    {
        Storage::fake('public');
        $m = $this->activeMember();
        $this->actingAs($m, 'member');

        Livewire::test(Profile::class)->set('uploads', [UploadedFile::fake()->create('cv.pdf', 20, 'application/pdf')])->assertHasErrors('uploads.0');

        Photo::query()->where('app_user_id', $m->id)->delete();
        Livewire::test(Profile::class)->set('uploads', array_map(fn ($i) => UploadedFile::fake()->image("p{$i}.jpg"), range(1, 7)))->assertHasErrors();
        $this->assertLessThanOrEqual(6, Photo::query()->where('app_user_id', $m->id)->count(), 'EXPECTED at most 6 photos.');
    }

    public function test_f04_cannot_delete_somebody_elses_photo(): void
    {
        [$a, $b] = $this->pair();
        $photo = Photo::query()->create(['app_user_id' => $b->id, 'disk' => 'public', 'path' => 'x.jpg', 'position' => 1]);
        $this->actingAs($a, 'member');

        try {
            Livewire::test(Profile::class)->call('deletePhoto', $photo->uuid);
            $this->fail('EXPECTED deleting another member\'s photo to be refused.');
        } catch (ModelNotFoundException) {
            // Refused: over HTTP this is a 404.
        }

        $this->assertNotNull($photo->fresh());
    }

    public function test_f05_verification_attempt_limit(): void
    {
        Storage::fake('verifications');
        $m = $this->activeMember();
        $this->actingAs($m, 'member');
        for ($i = 0; $i < 4; $i++) {
            Livewire::test(Verification::class)->set('selfie', UploadedFile::fake()->image('s.jpg'))->call('submit');
        }
        $this->assertLessThanOrEqual((int) config('platform.verification.max_attempts', 3), \App\Models\Verification::query()->where('app_user_id', $m->id)->count());
    }

    // ================================================================ A. account

    public function test_a01_email_and_password_changes_need_the_password(): void
    {
        $m = $this->activeMember();
        $this->actingAs($m, 'member');
        Livewire::test(Account::class)->set('email', 'new@example.test')->set('emailPassword', 'wrong')->call('updateEmail')->assertHasErrors('emailPassword');
        Livewire::test(Account::class)->set('currentPassword', 'wrong')->set('newPassword', 'newpass123')->set('newPassword_confirmation', 'newpass123')->call('updatePassword')->assertHasErrors('currentPassword');
        Livewire::test(Account::class)->set('currentPassword', 'password')->set('newPassword', 'newpass123')->set('newPassword_confirmation', 'different1')->call('updatePassword')->assertHasErrors('newPassword');
    }

    public function test_a02_deactivation(): void
    {
        $m = $this->activeMember();
        $this->actingAs($m, 'member');
        Livewire::test(Account::class)->set('deactivatePassword', 'password')->call('deactivate')->assertRedirect(route('home'));
        $this->assertSame(AccountStatus::Deactivated, $m->fresh()->account_status);
    }

    // ================================================================ API

    public function test_ap_i01_register_login_and_me(): void
    {
        $this->postJson('/api/v1/auth/register', [])->assertStatus(422)->assertJsonStructure(['message', 'errors', 'code']);
        $this->getJson('/api/v1/me')->assertStatus(401)->assertJsonPath('code', 'unauthenticated');

        $token = $this->postJson('/api/v1/auth/login', ['email' => $this->activeMember()->email, 'password' => 'password'])->assertOk()->json('token');
        $this->withToken($token)->getJson('/api/v1/me')->assertOk();
    }

    public function test_ap_i02_web_session_does_not_authenticate_the_api(): void
    {
        $this->actingAs($this->activeMember(), 'member');
        $this->app['auth']->shouldUse('web');
        $this->getJson('/api/v1/me')->assertStatus(401);
    }
}
