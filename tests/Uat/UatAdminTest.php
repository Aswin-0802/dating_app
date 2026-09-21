<?php

declare(strict_types=1);

namespace Tests\Uat;

use App\Enums\AccountStatus;
use App\Enums\CaseStatus;
use App\Livewire\Appeals\Show as AppealShow;
use App\Livewire\Cases\Show as CaseShow;
use App\Livewire\Conversations\Show as ConversationShow;
use App\Livewire\Enforcement\Bans;
use App\Livewire\Enforcement\ShadowBanReviews;
use App\Livewire\Notifications\Campaigns;
use App\Livewire\Roles\PermissionMatrix;
use App\Livewire\Settings\Index as SettingsIndex;
use App\Livewire\Shell\CommandPalette;
use App\Livewire\Staff\Index as StaffIndex;
use App\Livewire\System\Backup;
use App\Livewire\System\Mail;
use App\Livewire\Users\Index;
use App\Livewire\Users\Show;
use App\Livewire\Verifications\Review;
use App\Models\ActivityLog;
use App\Models\Appeal;
use App\Models\AppUser;
use App\Models\Ban;
use App\Models\Conversation;
use App\Models\MatchRecord;
use App\Models\MessageAccessLog;
use App\Models\ModerationAction;
use App\Models\PushCampaign;
use App\Models\ReportCase;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Models\Verification;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

/**
 * UAT — staff console. Runs against the full seeded demo dataset.
 * Each failure message states EXPECTED so a failing case reads as a defect.
 */
class UatAdminTest extends UatTestCase
{
    private function staff(string $email): User
    {
        return User::query()->where('email', $email)->firstOrFail();
    }

    // ================================================================ A. staff sign-in

    public function test_a01_valid_staff_login_lands_on_dashboard(): void
    {
        $this->post('/admin/login', ['email' => 'admin@demo.test', 'password' => 'password'])
            ->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticated('web');
    }

    public function test_a02_wrong_password_is_refused_with_a_message(): void
    {
        $this->from('/admin/login')->post('/admin/login', ['email' => 'admin@demo.test', 'password' => 'nope'])
            ->assertRedirect('/admin/login')->assertSessionHasErrors('email');
        $this->assertGuest('web');
    }

    public function test_a03_empty_form_is_validated(): void
    {
        $this->from('/admin/login')->post('/admin/login', [])->assertSessionHasErrors(['email', 'password']);
    }

    public function test_a04_brute_force_is_throttled(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->post('/admin/login', ['email' => 'admin@demo.test', 'password' => 'wrong'.$i]);
        }
        $this->post('/admin/login', ['email' => 'admin@demo.test', 'password' => 'wrong'])
            ->assertStatus(429);
    }

    public function test_a05_suspended_staff_cannot_sign_in(): void
    {
        $this->staff('mod1@demo.test')->forceFill(['status' => 'suspended'])->save();
        $this->post('/admin/login', ['email' => 'mod1@demo.test', 'password' => 'password']);
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_a06_logout_ends_the_session(): void
    {
        $this->actingAs($this->staff('admin@demo.test'))->post('/admin/logout')->assertRedirect();
        $this->assertGuest('web');
    }

    public function test_a07_member_credentials_do_not_open_the_console(): void
    {
        $member = AppUser::query()->where('account_status', 'active')->firstOrFail();
        $this->from('/admin/login')->post('/admin/login', ['email' => $member->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');
        $this->assertGuest('web');
    }

    public function test_a08_staff_password_reset_exists(): void
    {
        $this->get('/admin/login')->assertSee('Forgot', false, 'EXPECTED a "Forgot password" link on staff sign-in (reference admin has password/reset).');
    }

    public function test_a09_staff_can_change_own_password(): void
    {
        $html = $this->actingAs($this->staff('admin@demo.test'))->get('/admin/profile')->assertOk()->getContent();
        $this->assertStringContainsString('type="password"', $html,
            'EXPECTED a change-password form on /admin/profile (reference admin has change-password).');
    }

    // ================================================================ B. roles & permissions

    /** @return array<string, array{0: string, 1: array<int, string>, 2: array<int, string>}> */
    public static function roleMatrix(): array
    {
        return [
            'Moderator' => ['mod1@demo.test', ['/admin', '/admin/cases', '/admin/verifications'], ['/admin/roles', '/admin/settings/mail', '/admin/staff', '/admin/settings/branding']],
            'Support' => ['support1@demo.test', ['/admin', '/admin/users'], ['/admin/roles', '/admin/system/backup', '/admin/audit/message-access']],
            'Analyst' => ['analyst1@demo.test', ['/admin', '/admin/analytics/funnel'], ['/admin/roles', '/admin/staff', '/admin/settings/mail', '/admin/conversations']],
            'Admin' => ['ops@demo.test', ['/admin', '/admin/staff', '/admin/settings/mail', '/admin/settings/branding'], ['/admin/verifications/restricted']],
        ];
    }

    /** @dataProvider roleMatrix */
    public function test_b01_role_access_matches_permissions(string $email, array $allowed, array $denied): void
    {
        $this->actingAs($this->staff($email));

        foreach ($allowed as $path) {
            $this->assertSame(200, $this->get($path)->status(), "EXPECTED {$email} to open {$path}");
        }
        foreach ($denied as $path) {
            $this->assertContains($this->get($path)->status(), [403, 404], "EXPECTED {$email} to be refused {$path}");
        }
    }

    public function test_b02_every_sidebar_link_opens_for_every_role(): void
    {
        foreach (User::query()->with('roles')->get() as $user) {
            $this->actingAs($user);
            $html = $this->get('/admin')->getContent();
            preg_match_all('#href="(http://[^"]+/admin[^"]*)"#', $html, $m);

            foreach (array_unique($m[1]) as $url) {
                if (str_contains($url, '_kitchen') || str_contains($url, 'logout')) {
                    continue;
                }
                $status = $this->get($url)->status();
                $this->assertSame(200, $status, "EXPECTED sidebar link {$url} to open for {$user->email} ({$user->roles->first()?->name}), got {$status}");
            }
        }
    }

    public function test_b03_role_permission_change_saves_and_is_audited(): void
    {
        $admin = $this->staff('admin@demo.test');
        $role = Role::findByName('Support', 'web');
        $permission = Permission::findByName('export_users', 'web');
        $had = $role->hasPermissionTo($permission);

        $component = Livewire::actingAs($admin)->test(PermissionMatrix::class, ['role' => $role]);
        $selected = $component->get('selected');
        $selected = $had ? array_values(array_diff($selected, [$permission->name])) : [...$selected, $permission->name];
        $component->set('selected', $selected)->call('save');

        $this->assertNotSame($had, $role->fresh()->hasPermissionTo('export_users'), 'EXPECTED the permission toggle to persist.');
        $this->assertTrue(ActivityLog::query()->where('module', 'roles')->exists(), 'EXPECTED an audit row for the permission change.');
    }

    public function test_b04_new_role_can_be_created(): void
    {
        $html = $this->actingAs($this->staff('admin@demo.test'))->get('/admin/roles')->getContent();
        $this->assertTrue(str_contains($html, 'New role') || str_contains($html, 'Add role') || str_contains($html, 'Create role'),
            'EXPECTED a way to create a role (reference admin: roles/add-role).');
    }

    public function test_b05_staff_can_be_added_and_edited(): void
    {
        $html = $this->actingAs($this->staff('admin@demo.test'))->get('/admin/staff')->getContent();
        $this->assertTrue(str_contains($html, 'Add staff') || str_contains($html, 'Invite staff') || str_contains($html, 'New staff'),
            'EXPECTED a way to add staff and change their role (reference admin: users/create, users/edit).');
    }

    public function test_b06_staff_status_toggle_and_self_protection(): void
    {
        $admin = $this->staff('admin@demo.test');
        $mod = $this->staff('mod1@demo.test');

        Livewire::actingAs($admin)->test(StaffIndex::class)->call('toggleStatus', $mod->id);
        $this->assertSame('suspended', $mod->fresh()->status, 'EXPECTED the moderator to be suspended.');

        Livewire::actingAs($admin)->test(StaffIndex::class)->call('toggleStatus', $admin->id);
        $this->assertSame('active', $admin->fresh()->status, 'EXPECTED staff to be unable to suspend themselves.');
    }

    // ================================================================ C. verification

    public function test_c01_approve_marks_the_member_verified(): void
    {
        $v = Verification::query()->where('queue', 'standard')->whereIn('status', ['pending', 'in_review'])->where('minor_suspected', false)->firstOrFail();

        Livewire::actingAs($this->staff('mod1@demo.test'))->test(Review::class, ['verification' => $v])->call('approve')->assertHasNoErrors();

        $this->assertSame('approved', $v->fresh()->status->value);
        $this->assertSame('approved', $v->appUser->fresh()->verification_status->value, 'EXPECTED member verification_status = approved.');
    }

    public function test_c02_reject_needs_a_reason(): void
    {
        $v = Verification::query()->where('queue', 'standard')->whereIn('status', ['pending', 'in_review'])->firstOrFail();
        Livewire::actingAs($this->staff('mod1@demo.test'))->test(Review::class, ['verification' => $v])
            ->set('reasonCode', '')->call('reject')->assertHasErrors('reasonCode');
        $this->assertNotSame('rejected', $v->fresh()->status->value);
    }

    public function test_c03_minor_suspected_cannot_be_approved(): void
    {
        $v = Verification::query()->where('minor_suspected', true)->firstOrFail();
        $v->forceFill(['status' => 'pending', 'reviewed_by' => null, 'reviewed_at' => null])->save();
        Livewire::actingAs($this->staff('lead@demo.test'))->test(Review::class, ['verification' => $v])->call('approve');
        $this->assertNotSame('approved', $v->fresh()->status->value, 'EXPECTED approval to be refused for a minor-safety flag.');
    }

    public function test_c04_restricted_queue_is_invisible_to_moderators(): void
    {
        $v = Verification::query()->where('queue', 'restricted_minor')->firstOrFail();
        $this->actingAs($this->staff('mod1@demo.test'));
        $this->get('/admin/verifications/restricted')->assertNotFound();
        $this->get(route('admin.verifications.review', $v))->assertNotFound();
    }

    // ================================================================ D. cases & enforcement

    /**
     * An open case to work with.
     *
     * `$unclaimed` matters: claiming is a no-op on a case somebody already
     * holds, so a test about claiming has to start from one nobody has.
     */
    private function openCase(bool $unclaimed = false): ReportCase
    {
        return ReportCase::query()
            ->whereIn('status', ['new', 'claimed', 'in_review'])
            ->when($unclaimed, fn ($q) => $q->whereNull('claimed_by'))
            ->firstOrFail();
    }

    public function test_d01_claim_assigns_the_case(): void
    {
        $case = $this->openCase(unclaimed: true);
        $mod = $this->staff('mod1@demo.test');
        Livewire::actingAs($mod)->test(CaseShow::class, ['reportCase' => $case])->call('claim');
        $this->assertSame($mod->id, $case->fresh()->claimed_by);
    }

    public function test_d02_suspension_needs_a_note_then_applies(): void
    {
        $case = $this->openCase();
        $mod = $this->staff('mod1@demo.test');

        Livewire::actingAs($mod)->test(CaseShow::class, ['reportCase' => $case])
            ->call('openStep', 'suspend')->set('reasonCode', 'harassment_confirmed')->set('note', '')
            ->call('confirmStep')->assertHasErrors('note');

        Livewire::actingAs($mod)->test(CaseShow::class, ['reportCase' => $case])
            ->call('openStep', 'suspend')->set('reasonCode', 'harassment_confirmed')->set('note', 'Repeated abusive messages to three members.')
            ->call('confirmStep')->assertHasNoErrors();

        $subject = $case->subject->fresh();
        $this->assertSame(AccountStatus::Suspended, $subject->account_status, 'EXPECTED the subject to be suspended.');
        $this->assertSame(CaseStatus::Actioned, $case->fresh()->status, 'EXPECTED the case to be actioned.');
    }

    public function test_d03_moderator_cannot_permanently_ban(): void
    {
        Livewire::actingAs($this->staff('mod1@demo.test'))->test(CaseShow::class, ['reportCase' => $this->openCase()])
            ->call('openStep', 'permanent_ban')->set('reasonCode', 'harassment_confirmed')->set('note', 'x')
            ->call('confirmStep')->assertForbidden();
    }

    public function test_d04_lifting_a_ban_restores_the_account(): void
    {
        $ban = Ban::query()->active()->whereIn('type', ['suspension', 'feature_limit', 'permanent_ban'])->firstOrFail();
        Livewire::actingAs($this->staff('senior1@demo.test'))->test(Bans::class)->call('lift', $ban->id);
        $this->assertNotNull($ban->fresh()->lifted_at);
        $this->assertSame(AccountStatus::Active, $ban->appUser->fresh()->account_status, 'EXPECTED account active after lift.');
    }

    public function test_d05_shadow_review_extension_requires_a_date(): void
    {
        $ban = Ban::query()->active()->where('type', 'shadow_ban')->firstOrFail();
        Livewire::actingAs($this->staff('lead@demo.test'))->test(ShadowBanReviews::class)
            ->call('startExtend', $ban->id)->set('newReviewDate', null)->call('extend')->assertHasErrors();
    }

    public function test_d06_member_page_actions_are_wired(): void
    {
        $member = AppUser::query()->where('account_status', 'active')->firstOrFail();
        $html = $this->actingAs($this->staff('admin@demo.test'))->get(route('admin.users.show', $member))->getContent();

        preg_match('#Actions.*?Ban permanently#s', $html, $m);
        $this->assertStringContainsString('wire:click', $m[0] ?? '',
            'EXPECTED Warn / Shadow ban / Suspend / Ban on the member page to perform an action; they have no handler.');
    }

    public function test_d07_members_export_works(): void
    {
        $html = $this->actingAs($this->staff('admin@demo.test'))->get('/admin/users')->getContent();
        $this->assertStringContainsString('wire:click="export"', $html, 'EXPECTED the Export button to download a CSV; it has no handler.');

        Livewire::actingAs($this->staff('admin@demo.test'))->test(Index::class)
            ->call('export')
            ->assertFileDownloaded();
    }

    public function test_d08_warning_from_the_member_page_is_applied(): void
    {
        $member = AppUser::query()->where('account_status', 'active')->firstOrFail();
        $before = ModerationAction::query()->where('subject_app_user_id', $member->id)->count();

        Livewire::actingAs($this->staff('mod1@demo.test'))->test(Show::class, ['appUser' => $member])
            ->call('openStep', 'warn')
            ->call('confirmStep')->assertHasErrors('reasonCode')
            ->set('reasonCode', 'harassment_confirmed')
            ->call('confirmStep')->assertHasNoErrors();

        $this->assertSame($before + 1, ModerationAction::query()->where('subject_app_user_id', $member->id)->count());
    }

    public function test_d09_moderator_cannot_open_a_permanent_ban_from_the_member_page(): void
    {
        $member = AppUser::query()->where('account_status', 'active')->firstOrFail();

        Livewire::actingAs($this->staff('mod1@demo.test'))->test(Show::class, ['appUser' => $member])
            ->call('openStep', 'permanent_ban')->assertForbidden();
    }

    public function test_d10_every_member_tab_renders(): void
    {
        $member = AppUser::query()->findOrFail(MatchRecord::query()->value('app_user_one_id'));

        foreach (['profile', 'photos', 'matches', 'reports', 'enforcement', 'devices', 'timeline'] as $tab) {
            Livewire::actingAs($this->staff('admin@demo.test'))->test(Show::class, ['appUser' => $member])
                ->call('setTab', $tab)
                ->assertDontSee('not built yet');
        }
    }

    // ================================================================ E. appeals

    public function test_e01_original_decider_cannot_take_the_appeal(): void
    {
        $appeal = Appeal::query()->whereNotNull('original_decider_id')->whereIn('status', ['new', 'assigned', 'in_review'])->firstOrFail();

        Livewire::actingAs($this->staff('admin@demo.test'))->test(AppealShow::class, ['appeal' => $appeal])
            ->set('assignTo', $appeal->original_decider_id)->call('assign')->assertHasErrors('assignTo');
    }

    public function test_e02_decision_needs_a_real_explanation(): void
    {
        $appeal = Appeal::query()->whereIn('status', ['new', 'assigned', 'in_review'])->firstOrFail();
        $reviewer = User::query()->permission('decide_appeals')->where('id', '!=', $appeal->original_decider_id)->where('status', 'active')->firstOrFail();

        Livewire::actingAs($reviewer)->test(AppealShow::class, ['appeal' => $appeal])
            ->set('decision', 'upheld')->set('decisionNote', 'no')->call('decide')->assertHasErrors('decisionNote');
    }

    // ================================================================ F. message privacy

    public function test_f01_revealing_messages_needs_permission_and_justification_and_is_logged(): void
    {
        $conversation = Conversation::query()->where('messages_count', '>', 0)->firstOrFail();
        $baseline = MessageAccessLog::query()->count();

        Livewire::actingAs($this->staff('support1@demo.test'))->test(ConversationShow::class, ['conversation' => $conversation])
            ->set('reasonCode', 'case_review')->set('justification', 'Checking a report in detail')->call('reveal');
        $this->assertSame($baseline, MessageAccessLog::query()->count(), 'EXPECTED Support (no view_message_content) to be refused.');

        Livewire::actingAs($this->staff('lead@demo.test'))->test(ConversationShow::class, ['conversation' => $conversation])
            ->set('reasonCode', 'case_review')->set('justification', 'short')->call('reveal')->assertHasErrors('justification');

        Livewire::actingAs($this->staff('lead@demo.test'))->test(ConversationShow::class, ['conversation' => $conversation])
            ->set('reasonCode', 'case_review')->set('justification', 'Reviewing reported harassment in this thread.')->call('reveal')->assertHasNoErrors();
        $this->assertSame($baseline + 1, MessageAccessLog::query()->count(), 'EXPECTED exactly one access-log row.');
    }

    public function test_f02_conversation_index_never_contains_message_bodies(): void
    {
        $body = (string) \DB::table('messages')->whereNotNull('body')->where('body', '!=', '')->value('body');
        $this->actingAs($this->staff('admin@demo.test'))->get('/admin/conversations')->assertDontSee($body);
    }

    // ================================================================ G. notifications

    public function test_g01_campaign_cannot_be_approved_by_its_author(): void
    {
        $campaign = PushCampaign::query()->whereNull('approved_at')->whereNotNull('created_by')->first();
        if ($campaign === null) {
            $this->markTestSkipped('No unapproved campaign in seed.');
        }
        $author = User::query()->findOrFail($campaign->created_by);
        $author->givePermissionTo('approve_campaigns');

        Livewire::actingAs($author)->test(Campaigns::class)->call('approve', $campaign->id);
        $this->assertNull($campaign->fresh()->approved_at);
    }

    public function test_g02_a_cancelled_campaign_cannot_be_approved(): void
    {
        $campaign = PushCampaign::query()->whereNotNull('created_by')->firstOrFail();
        $campaign->forceFill(['status' => 'cancelled', 'approved_at' => null, 'approved_by' => null])->save();
        $approver = User::query()->permission('approve_campaigns')->where('id', '!=', $campaign->created_by)->firstOrFail();

        Livewire::actingAs($approver)->test(Campaigns::class)->call('approve', $campaign->id);
        $this->assertNull($campaign->fresh()->approved_at, 'EXPECTED a cancelled campaign to be un-approvable.');
    }

    public function test_g03_a_sent_campaign_cannot_be_cancelled(): void
    {
        $campaign = PushCampaign::query()->firstOrFail();
        $campaign->forceFill(['status' => 'sent'])->save();

        Livewire::actingAs($this->staff('admin@demo.test'))->test(Campaigns::class)->call('cancel', $campaign->id);
        $this->assertSame('sent', $campaign->fresh()->status, 'EXPECTED a sent campaign to stay "sent".');
    }

    public function test_g04_a_new_campaign_can_be_created(): void
    {
        $html = $this->actingAs($this->staff('admin@demo.test'))->get('/admin/notifications')->getContent();
        $this->assertTrue(str_contains($html, 'New campaign') || str_contains($html, 'Create campaign'),
            'EXPECTED a way to create a push campaign; only seeded campaigns can be approved or cancelled.');
    }

    // ================================================================ H. settings & system

    public function test_h01_settings_save_and_take_effect(): void
    {
        $setting = Setting::query()->where('key', 'matching.daily_like_limit_free')->firstOrFail();
        Livewire::actingAs($this->staff('admin@demo.test'))->test(SettingsIndex::class, ['group' => 'matching'])
            ->set("values.{$setting->id}", 42)->call('save');
        $this->assertSame(42, platform_setting('matching.daily_like_limit_free'));
    }

    public function test_h02_negative_limits_are_rejected(): void
    {
        $setting = Setting::query()->where('key', 'matching.daily_like_limit_free')->firstOrFail();
        Livewire::actingAs($this->staff('admin@demo.test'))->test(SettingsIndex::class, ['group' => 'matching'])
            ->set("values.{$setting->id}", -5)->call('save');
        $this->assertGreaterThanOrEqual(0, (int) $setting->fresh()->value, 'EXPECTED a negative like limit to be refused by validation.');
    }

    public function test_h03_maintenance_mode_setting_has_an_effect(): void
    {
        Setting::put('general.maintenance_mode', true);
        $status = $this->get('/')->status();
        $this->assertSame(503, $status, 'EXPECTED "Maintenance mode" to take the website/app offline; it is not read anywhere.');
    }

    public function test_h04_mail_settings_validate(): void
    {
        $mail = Livewire::actingAs($this->staff('admin@demo.test'))->test(Mail::class);
        $mail->set('values.mail.port', 99999)->call('save')->assertHasErrors('values.mail.port');
    }

    public function test_h05_backup_delete_cannot_escape_the_backup_folder(): void
    {
        \Storage::disk('local')->put('keep.sql', 'x');
        Livewire::actingAs($this->staff('admin@demo.test'))->test(Backup::class)->call('delete', '../keep.sql');
        \Storage::disk('local')->assertExists('keep.sql');
        \Storage::disk('local')->delete('keep.sql');
    }

    public function test_h06_geography_masters_can_be_managed(): void
    {
        $this->actingAs($this->staff('admin@demo.test'))->get(route('admin.masters.locations'))->assertOk();
        $this->assertTrue(\Route::has('admin.masters.locations'),
            'EXPECTED country/state/city management (reference admin: masters/country|state|city).');
    }

    // ================================================================ I. audit & errors

    public function test_i01_audit_log_is_immutable(): void
    {
        $log = ActivityLog::query()->firstOrFail();
        $this->expectException(\RuntimeException::class);
        $log->update(['description' => 'tampered']);
    }

    public function test_i02_unknown_records_are_404_not_500(): void
    {
        $this->actingAs($this->staff('admin@demo.test'));
        foreach (['/admin/users/00000000-0000-0000-0000-000000000000', '/admin/cases/VEY-0000-000000', '/admin/appeals/nope', '/admin/conversations/nope', '/admin/verifications/999999', '/admin/roles/9999/permissions'] as $path) {
            $this->assertSame(404, $this->get($path)->status(), "EXPECTED 404 for {$path}");
        }
    }

    public function test_i03_command_palette_search_finds_members(): void
    {
        $member = AppUser::query()->firstOrFail();
        Livewire::actingAs($this->staff('admin@demo.test'))->test(CommandPalette::class)
            ->set('query', substr($member->display_name, 0, 4))->assertSee($member->display_name);
    }
}
