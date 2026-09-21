<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Member\Account;
use App\Models\AppUser;
use App\Models\PhoneVerification as VerificationRecord;
use App\Models\PushCampaign;
use App\Models\PushLog;
use App\Models\PushToken;
use App\Models\Setting;
use App\Models\SmsGateway;
use App\Models\User;
use App\Services\Sms\PhoneVerification;
use App\Services\Sms\SmsSender;
use App\Support\PushSettings;
use Database\Seeders\MasterSeeder;
use Database\Seeders\NotificationTemplateSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\SystemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

class CampaignsAndSmsTest extends TestCase
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

    // ---- campaigns -----------------------------------------------------------------

    private function configurePush(): void
    {
        // Reused from the push tests: a real key so the JWT is really signed.
        $key = null;

        foreach ([null, 'C:\xampp\apache\conf\openssl.cnf', 'C:\xampp\php\extras\ssl\openssl.cnf'] as $config) {
            $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
            $resource = @openssl_pkey_new($config === null ? $options : $options + ['config' => $config]);

            if ($resource !== false) {
                @openssl_pkey_export($resource, $key, null, $config === null ? [] : ['config' => $config]);

                if (filled($key)) {
                    break;
                }
            }
        }

        if (blank($key)) {
            $this->markTestSkipped('This PHP build cannot generate an RSA key.');
        }

        PushSettings::storeServiceAccount(json_encode([
            'type' => 'service_account',
            'project_id' => 'platform-test',
            'private_key' => $key,
            'client_email' => 'push@platform-test.iam.gserviceaccount.com',
        ], JSON_THROW_ON_ERROR));

        Setting::put('push.enabled', true);
        Setting::flush();

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => Http::response(['name' => 'projects/platform-test/messages/1']),
        ]);
    }

    private function campaign(array $attributes = []): PushCampaign
    {
        $author = User::factory()->create(['status' => 'active']);

        return PushCampaign::query()->create(array_merge([
            'uuid' => (string) Str::uuid(),
            'name' => 'Weekend nudge',
            'title' => 'People are waiting',
            'body' => 'Three new people near you this weekend.',
            'audience_filters' => ['audience' => 'all'],
            'audience_label' => 'Everyone active',
            'estimated_recipients' => 0,
            'status' => 'scheduled',
            'scheduled_for' => now()->subMinute(),
            'approved_at' => now()->subMinute(),
            'approved_by' => $author->id,
            'created_by' => $author->id,
        ], $attributes));
    }

    public function test_an_approved_campaign_sends_and_logs_one_row_per_member(): void
    {
        $this->configurePush();

        $reachable = AppUser::factory()->create();
        PushToken::register($reachable, str_repeat('a', 40), 'android');

        $unreachable = AppUser::factory()->create();
        $campaign = $this->campaign();

        $this->artisan('platform:send-campaigns')->assertSuccessful();

        $campaign->refresh();
        $this->assertSame('sent', $campaign->status);
        $this->assertSame(1, $campaign->sent_count);
        $this->assertNotNull($campaign->completed_at);

        $this->assertDatabaseHas('push_logs', ['push_campaign_id' => $campaign->id, 'app_user_id' => $reachable->id, 'status' => 'sent']);
        $this->assertDatabaseHas('push_logs', [
            'push_campaign_id' => $campaign->id,
            'app_user_id' => $unreachable->id,
            'status' => 'failed',
            'failure_reason' => 'No device registered for notifications',
        ]);
    }

    public function test_a_campaign_is_never_sent_twice(): void
    {
        $this->configurePush();
        PushToken::register(AppUser::factory()->create(), str_repeat('b', 40), 'web');

        $campaign = $this->campaign();

        $this->artisan('platform:send-campaigns')->assertSuccessful();
        $this->artisan('platform:send-campaigns')->assertSuccessful();

        $this->assertSame(1, PushLog::query()->where('push_campaign_id', $campaign->id)->where('status', 'sent')->count());
    }

    public function test_an_unapproved_or_future_campaign_waits(): void
    {
        $this->configurePush();
        PushToken::register(AppUser::factory()->create(), str_repeat('c', 40), 'web');

        $unapproved = $this->campaign(['approved_at' => null, 'approved_by' => null]);
        $future = $this->campaign(['scheduled_for' => now()->addDay()]);

        $this->artisan('platform:send-campaigns')->assertSuccessful();

        $this->assertSame('scheduled', $unapproved->fresh()->status);
        $this->assertSame('scheduled', $future->fresh()->status);
        $this->assertSame(0, PushLog::query()->count());
    }

    public function test_nothing_is_sent_when_push_is_not_set_up(): void
    {
        Http::fake();
        $campaign = $this->campaign();

        $this->artisan('platform:send-campaigns')->assertSuccessful();

        $this->assertSame('scheduled', $campaign->fresh()->status);
        Http::assertNothingSent();
    }

    // ---- sms -------------------------------------------------------------------------

    private function enableTwilio(): SmsGateway
    {
        $gateway = SmsGateway::query()->where('slug', 'twilio')->firstOrFail();

        $gateway->forceFill([
            'credentials' => ['account_sid' => 'AC123', 'auth_token' => 'token', 'from_number' => '+15550001111'],
            'is_active' => true,
        ])->save();

        return $gateway;
    }

    public function test_a_text_is_sent_through_the_active_gateway_and_logged(): void
    {
        $this->enableTwilio();
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM123', 'status' => 'queued'], 201)]);

        $member = AppUser::factory()->create();
        $sent = app(SmsSender::class)->send('+91 98765 43210', 'Hello from Platform', $member);

        $this->assertTrue($sent);
        $this->assertDatabaseHas('sms_logs', ['to' => '+919876543210', 'gateway' => 'twilio', 'status' => 'sent']);

        Http::assertSent(fn ($request): bool => $request['To'] === '+919876543210' && $request['From'] === '+15550001111');
    }

    public function test_a_failure_is_recorded_rather_than_reported_as_sent(): void
    {
        $this->enableTwilio();
        Http::fake(['api.twilio.com/*' => Http::response(['message' => 'The number is unverified'], 400)]);

        $this->assertFalse(app(SmsSender::class)->send('+919876543210', 'Hello'));
        $this->assertDatabaseHas('sms_logs', ['status' => 'failed', 'failure_reason' => 'The number is unverified']);
    }

    public function test_an_unusable_number_never_reaches_the_gateway(): void
    {
        $this->enableTwilio();
        Http::fake();

        $this->assertFalse(app(SmsSender::class)->send('12345', 'Hello'));
        Http::assertNothingSent();
    }

    public function test_nothing_is_sent_without_a_gateway(): void
    {
        Http::fake();

        $this->assertFalse(app(SmsSender::class)->send('+919876543210', 'Hello'));
        $this->assertDatabaseHas('sms_logs', ['status' => 'failed', 'failure_reason' => 'No SMS gateway is configured']);
        Http::assertNothingSent();
    }

    // ---- phone verification -------------------------------------------------------------

    public function test_a_member_verifies_their_phone_with_a_texted_code(): void
    {
        $this->enableTwilio();
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201)]);

        $member = AppUser::factory()->create(['phone' => null, 'phone_verified_at' => null]);

        Livewire::actingAs($member, 'member')
            ->test(Account::class)
            ->set('phone', '+919876543210')
            ->call('sendPhoneCode')
            ->assertHasNoErrors()
            ->assertSet('codeSent', true);

        // The code is only in the text, so the test reads it the way the
        // member would: from what was sent.
        $record = VerificationRecord::query()->where('app_user_id', $member->id)->firstOrFail();
        $code = null;

        Http::assertSent(function ($request) use (&$code): bool {
            if (preg_match('/(\d{6}) is your/', (string) ($request['Body'] ?? ''), $matches) === 1) {
                $code = $matches[1];
            }

            return true;
        });

        $this->assertNotNull($code);
        $this->assertTrue(Hash::check($code, $record->code_hash));

        Livewire::actingAs($member, 'member')
            ->test(Account::class)
            ->set('codeSent', true)
            ->set('phoneCode', $code)
            ->call('confirmPhoneCode')
            ->assertHasNoErrors();

        $member->refresh();
        $this->assertSame('+919876543210', $member->phone);
        $this->assertNotNull($member->phone_verified_at);
    }

    public function test_a_wrong_code_is_refused_and_runs_out_of_tries(): void
    {
        $this->enableTwilio();
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201)]);

        $member = AppUser::factory()->create(['phone_verified_at' => null]);
        app(PhoneVerification::class)->start($member, '+919876543210');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            Livewire::actingAs($member, 'member')
                ->test(Account::class)
                ->set('phoneCode', '000000')
                ->call('confirmPhoneCode')
                ->assertHasErrors('phoneCode');
        }

        // Out of tries: still on the field the member is looking at.
        Livewire::actingAs($member, 'member')
            ->test(Account::class)
            ->set('phoneCode', '000000')
            ->call('confirmPhoneCode')
            ->assertHasErrors('phoneCode');

        $this->assertNull($member->fresh()->phone_verified_at);
    }

    public function test_a_number_verified_elsewhere_cannot_be_claimed(): void
    {
        $this->enableTwilio();
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201)]);

        AppUser::factory()->create(['phone' => '+919876543210', 'phone_verified_at' => now()]);
        $member = AppUser::factory()->create(['phone_verified_at' => null]);

        Livewire::actingAs($member, 'member')
            ->test(Account::class)
            ->set('phone', '+919876543210')
            ->call('sendPhoneCode')
            ->assertHasErrors('phone');

        Http::assertNothingSent();
    }

    public function test_codes_cannot_be_requested_back_to_back(): void
    {
        $this->enableTwilio();
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201)]);

        $member = AppUser::factory()->create(['phone_verified_at' => null]);
        $verification = app(PhoneVerification::class);

        $verification->start($member, '+919876543210');

        Livewire::actingAs($member, 'member')
            ->test(Account::class)
            ->set('phone', '+919876543210')
            ->call('sendPhoneCode')
            ->assertHasErrors('phone');
    }

    public function test_the_api_sends_and_verifies_a_code(): void
    {
        $this->enableTwilio();
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201)]);

        $member = AppUser::factory()->create(['phone_verified_at' => null]);
        Sanctum::actingAs($member, ['*']);

        $this->postJson(route('api.v1.phone.send-code'), ['phone' => '+919876543211'])
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'sent');

        $this->postJson(route('api.v1.phone.verify'), ['code' => '111111'])
            ->assertStatus(422);
    }

    public function test_the_api_says_so_when_no_gateway_is_set_up(): void
    {
        $member = AppUser::factory()->create();
        Sanctum::actingAs($member, ['*']);

        $this->postJson(route('api.v1.phone.send-code'), ['phone' => '+919876543210'])
            ->assertStatus(503)
            ->assertJsonPath('code', 'sms_unavailable');
    }
}
