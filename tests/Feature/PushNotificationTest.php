<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\System\Push as PushSettingsScreen;
use App\Models\AppUser;
use App\Models\Plan;
use App\Models\PushLog;
use App\Models\PushToken;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\TemplatedMail;
use App\Services\Billing\Subscriptions;
use App\Services\Push\FcmSender;
use App\Services\Push\PushMessage;
use App\Support\PushSettings;
use Database\Seeders\MasterSeeder;
use Database\Seeders\NotificationTemplateSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\SystemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

class PushNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            PermissionSeeder::class, RoleSeeder::class, SettingSeeder::class,
            SystemSeeder::class, MasterSeeder::class, NotificationTemplateSeeder::class,
        ]);
    }

    /** A real (throwaway) service account, so the JWT is genuinely signed. */
    private function fakeServiceAccount(): string
    {
        $privateKey = $this->generatePrivateKey();

        return json_encode([
            'type' => 'service_account',
            'project_id' => 'veyra-test',
            'private_key_id' => 'abc123',
            'private_key' => $privateKey,
            'client_email' => 'push@veyra-test.iam.gserviceaccount.com',
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * A throwaway RSA key, generated per run rather than committed.
     *
     * XAMPP's PHP cannot find openssl.cnf on its own, so the usual Windows
     * locations are tried before giving up.
     */
    private function generatePrivateKey(): string
    {
        $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];

        foreach ([null, 'C:\xampp\apache\conf\openssl.cnf', 'C:\xampp\php\extras\ssl\openssl.cnf'] as $config) {
            $settings = $config === null ? $options : $options + ['config' => $config];
            $key = @openssl_pkey_new($settings);

            if ($key !== false) {
                @openssl_pkey_export($key, $pem, null, $config === null ? [] : ['config' => $config]);

                if (filled($pem)) {
                    return (string) $pem;
                }
            }
        }

        $this->markTestSkipped('This PHP build cannot generate an RSA key (openssl.cnf not found).');
    }

    private function configurePush(bool $web = true): void
    {
        PushSettings::storeServiceAccount($this->fakeServiceAccount());
        Setting::put('push.enabled', true);

        if ($web) {
            foreach (PushSettings::WEB_FIELDS as $field => $sdkKey) {
                Setting::put("push.{$field}", 'test-'.$field);
            }

            Setting::put('push.web_vapid_key', 'BKagOny0KF_2pCJQ3m');
            Setting::put('push.web_enabled', true);
        }

        Setting::flush();
    }

    private function fakeFirebase(array $sendResponse = ['name' => 'projects/veyra-test/messages/1'], int $status = 200): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => Http::response($sendResponse, $status),
        ]);
    }

    // ---- configuration ------------------------------------------------------------

    public function test_push_stays_off_until_a_service_account_is_saved(): void
    {
        $this->assertFalse(PushSettings::enabled());

        Setting::put('push.enabled', true);
        Setting::flush();

        $this->assertFalse(PushSettings::enabled(), 'Push must not report enabled without credentials.');
    }

    public function test_admin_saves_firebase_credentials_and_junk_is_refused(): void
    {
        $admin = User::factory()->create(['status' => 'active'])->syncRoles([Role::ADMIN]);

        Livewire::actingAs($admin)
            ->test(PushSettingsScreen::class)
            ->set('serviceAccountJson', '{"hello":"world"}')
            ->call('save')
            ->assertHasErrors('serviceAccountJson');

        $account = $this->fakeServiceAccount();

        Livewire::actingAs($admin)
            ->test(PushSettingsScreen::class)
            ->set('serviceAccountJson', $account)
            ->set('enabled', true)
            ->call('save')
            ->assertHasNoErrors();

        Setting::flush();
        $this->assertSame('veyra-test', PushSettings::projectId());
        $this->assertTrue(PushSettings::enabled());

        // The private key is never handed back to the browser. A slice of the
        // key body rather than its header, which the form shows as a hint.
        $secret = substr(json_decode($account, true)['private_key'], 40, 60);

        $this->actingAs($admin)->get(route('admin.system.push'))
            ->assertOk()
            ->assertSee('veyra-test')
            ->assertDontSee($secret);

        // Nor is it readable from the settings table without the app key.
        $this->assertStringNotContainsString($secret, (string) Setting::query()->where('key', 'push.service_account')->value('value'));
    }

    public function test_the_service_worker_is_served_from_the_site_root(): void
    {
        $this->configurePush();

        $this->get('/firebase-messaging-sw.js')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/javascript; charset=utf-8')
            ->assertSee('firebase-messaging-compat.js')
            ->assertSee('test-web_api_key');
    }

    // ---- registering devices -------------------------------------------------------

    public function test_a_member_registers_a_browser_for_notifications(): void
    {
        $this->configurePush();
        $member = AppUser::factory()->create();

        $this->actingAs($member, 'member')
            ->postJson(route('member.push.token.store'), ['token' => str_repeat('a', 40)])
            ->assertOk();

        $this->assertDatabaseHas('push_tokens', ['app_user_id' => $member->id, 'platform' => 'web']);
    }

    public function test_browser_registration_is_refused_when_web_push_is_off(): void
    {
        $member = AppUser::factory()->create();

        $this->actingAs($member, 'member')
            ->postJson(route('member.push.token.store'), ['token' => str_repeat('a', 40)])
            ->assertForbidden();
    }

    public function test_the_mobile_app_registers_and_a_shared_token_moves_to_its_new_owner(): void
    {
        $this->configurePush(web: false);
        $first = AppUser::factory()->create();
        $second = AppUser::factory()->create();
        $token = str_repeat('b', 40);

        Sanctum::actingAs($first, ['*']);
        $this->postJson(route('api.v1.devices.push-token.store'), ['token' => $token, 'platform' => 'android'])
            ->assertCreated();

        Sanctum::actingAs($second, ['*']);
        $this->postJson(route('api.v1.devices.push-token.store'), ['token' => $token, 'platform' => 'android'])
            ->assertCreated();

        $this->assertSame(1, PushToken::query()->count());
        $this->assertSame($second->id, PushToken::query()->first()->app_user_id);

        $this->deleteJson(route('api.v1.devices.push-token.destroy'), ['token' => $token])->assertOk();
        $this->assertSame(0, PushToken::query()->count());
    }

    // ---- sending -------------------------------------------------------------------

    public function test_a_notification_is_sent_and_logged(): void
    {
        $this->configurePush();
        $this->fakeFirebase();

        $member = AppUser::factory()->create();
        PushToken::register($member, str_repeat('c', 40), 'web');

        $result = app(FcmSender::class)->sendToMember($member, new PushMessage('Hello', 'A test', '/app/discover'));

        $this->assertSame(1, $result['sent']);
        $this->assertDatabaseHas('push_logs', ['app_user_id' => $member->id, 'status' => 'sent', 'title' => 'Hello']);

        Http::assertSent(function ($request): bool {
            if (! str_contains($request->url(), 'fcm.googleapis.com')) {
                return false;
            }

            $message = $request->data()['message'];

            // Every data value must be a string or Firebase rejects the lot.
            foreach ($message['data'] as $value) {
                if (! is_string($value)) {
                    return false;
                }
            }

            return $message['notification']['title'] === 'Hello'
                && str_contains($request->url(), 'veyra-test');
        });
    }

    public function test_a_dead_token_is_deleted_rather_than_retried_for_ever(): void
    {
        $this->configurePush();
        $this->fakeFirebase([
            'error' => [
                'code' => 404,
                'status' => 'NOT_FOUND',
                'details' => [['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => 'UNREGISTERED']],
            ],
        ], 404);

        $member = AppUser::factory()->create();
        PushToken::register($member, str_repeat('d', 40), 'android');

        $result = app(FcmSender::class)->sendToMember($member, new PushMessage('Hello', 'A test'));

        $this->assertSame(0, $result['sent']);
        $this->assertSame(0, PushToken::query()->count());
        $this->assertDatabaseHas('push_logs', ['app_user_id' => $member->id, 'status' => 'failed']);
    }

    public function test_nothing_is_sent_when_push_is_not_configured(): void
    {
        Http::fake();
        $member = AppUser::factory()->create();
        PushToken::register($member, str_repeat('e', 40), 'web');

        $result = app(FcmSender::class)->sendToMember($member, new PushMessage('Hello', 'A test'));

        $this->assertTrue($result['skipped']);
        $this->assertSame(0, PushLog::query()->count());
        Http::assertNothingSent();
    }

    // ---- renewal reminders ----------------------------------------------------------

    public function test_a_member_is_warned_before_their_plan_ends_and_only_once(): void
    {
        Notification::fake();
        $member = AppUser::factory()->create();

        app(Subscriptions::class)->grant($member, Plan::query()->where('slug', 'plus')->firstOrFail(), now()->addDays(3));

        $this->artisan('veyra:send-renewal-reminders')->assertSuccessful();
        $this->artisan('veyra:send-renewal-reminders')->assertSuccessful();

        // Exactly one warning, however often the task runs. (The other email
        // is the "your plan is active" note sent when the plan was granted.)
        $this->assertSame(1, $this->emailsSent($member, 'billing.renewal_email'));
    }

    public function test_a_plan_further_out_than_the_warning_window_is_left_alone(): void
    {
        Notification::fake();
        $member = AppUser::factory()->create();

        app(Subscriptions::class)->grant($member, Plan::query()->where('slug', 'plus')->firstOrFail(), now()->addMonth());

        $this->artisan('veyra:send-renewal-reminders')->assertSuccessful();

        $this->assertSame(0, $this->emailsSent($member, 'billing.renewal_email'));
    }

    /** How many emails from one template reached this member. */
    private function emailsSent(AppUser $member, string $templateKey): int
    {
        return Notification::sent($member, TemplatedMail::class)
            ->filter(fn (TemplatedMail $mail): bool => $mail->templateKey === $templateKey)
            ->count();
    }

    public function test_push_reminders_go_out_a_week_ahead_when_push_is_on(): void
    {
        $this->configurePush();
        $this->fakeFirebase();

        $member = AppUser::factory()->create();
        PushToken::register($member, str_repeat('f', 40), 'android');
        app(Subscriptions::class)->grant($member, Plan::query()->where('slug', 'gold')->firstOrFail(), now()->addDays(7));

        $this->artisan('veyra:send-renewal-reminders')->assertSuccessful();

        $this->assertDatabaseHas('push_logs', [
            'app_user_id' => $member->id,
            'status' => 'sent',
            'title' => 'Gold ends in 7 days',
        ]);
    }

    public function test_the_member_is_emailed_on_the_day_the_plan_ends(): void
    {
        Notification::fake();
        $member = AppUser::factory()->create();

        app(Subscriptions::class)->grant($member, Plan::query()->where('slug', 'plus')->firstOrFail(), now()->addHours(2));

        $this->travel(3)->hours();
        $this->artisan('veyra:run-due-tasks')->assertSuccessful();

        $this->assertFalse($member->fresh()->is_premium);
        Notification::assertSentTo($member, TemplatedMail::class);
    }

    public function test_the_reminder_schedule_is_editable(): void
    {
        Notification::fake();
        Setting::put('billing.reminder_email_days', '');
        Setting::put('billing.reminder_push_days', '');
        Setting::flush();

        $member = AppUser::factory()->create();
        app(Subscriptions::class)->grant($member, Plan::query()->where('slug', 'plus')->firstOrFail(), now()->addDays(2));

        $this->artisan('veyra:send-renewal-reminders')->assertSuccessful();

        $this->assertSame(0, $this->emailsSent($member, 'billing.renewal_email'));
    }

    public function test_a_member_is_told_when_their_plan_starts(): void
    {
        Notification::fake();
        $member = AppUser::factory()->create();

        app(Subscriptions::class)->grant($member, Plan::query()->where('slug', 'gold')->firstOrFail(), now()->addMonth());

        $this->assertSame(1, $this->emailsSent($member, 'billing.plan_started'));
    }
}
