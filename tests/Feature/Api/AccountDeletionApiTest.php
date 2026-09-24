<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\AccountStatus;
use App\Models\AppUser;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Photo;
use App\Models\Preference;
use App\Models\Profile;
use App\Models\Report;
use App\Models\ReportCase;
use Database\Seeders\InterestSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccountDeletionApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, InterestSeeder::class, SettingSeeder::class]);
        Storage::fake('public');
    }

    private function member(array $attributes = []): AppUser
    {
        $member = AppUser::factory()->create($attributes + ['account_status' => AccountStatus::Active]);

        Profile::query()->create(['app_user_id' => $member->id, 'bio' => 'Something personal', 'job_title' => 'Teacher']);
        Preference::query()->create([
            'app_user_id' => $member->id,
            'interested_in' => ['woman', 'man', 'non_binary', 'other'],
            'age_min' => 18,
            'age_max' => 99,
        ]);

        return $member->fresh();
    }

    public function test_deletion_requires_the_correct_password(): void
    {
        $member = $this->member(['password' => 'correct-horse-9']);
        Sanctum::actingAs($member, ['*']);

        $this->deleteJson('/api/v1/me', ['password' => 'wrong'])->assertStatus(422)->assertJsonValidationErrors('password');
        $this->deleteJson('/api/v1/me', [])->assertStatus(422);

        $this->assertNotNull(AppUser::query()->find($member->id), 'EXPECTED the account to survive a wrong password.');
    }

    public function test_deleting_the_account_removes_the_person_but_not_the_evidence(): void
    {
        $me = $this->member(['email' => 'me@example.test', 'phone' => '+447700900111', 'password' => 'correct-horse-9',
            'last_latitude' => 51.5, 'last_longitude' => -0.12]);
        $other = $this->member(['email' => 'other@example.test']);

        // A photo, a match, a conversation with messages both ways, a report
        // I filed and a report filed against me.
        Sanctum::actingAs($me, ['*']);
        $this->postJson('/api/v1/me/photos', ['photo' => UploadedFile::fake()->image('me.jpg')])->assertCreated();
        $photo = Photo::query()->where('app_user_id', $me->id)->firstOrFail();

        Sanctum::actingAs($other, ['*']);
        $this->postJson('/api/v1/swipes', ['target_id' => $me->uuid, 'action' => 'like']);
        Sanctum::actingAs($me, ['*']);
        $this->postJson('/api/v1/swipes', ['target_id' => $other->uuid, 'action' => 'like']);
        $conversation = Conversation::query()->firstOrFail();

        $this->postJson("/api/v1/conversations/{$conversation->uuid}/messages", ['body' => 'from me'])->assertCreated();
        Sanctum::actingAs($other, ['*']);
        $this->postJson("/api/v1/conversations/{$conversation->uuid}/messages", ['body' => 'from them'])->assertCreated();

        $this->postJson('/api/v1/reports', ['reported_id' => $me->uuid, 'category' => 'harassment'])->assertCreated();
        Sanctum::actingAs($me, ['*']);
        $this->postJson('/api/v1/reports', ['reported_id' => $other->uuid, 'category' => 'spam_promotion'])->assertCreated();

        $this->postJson('/api/v1/devices/push-token', ['token' => str_repeat('t', 40), 'platform' => 'android'])->assertSuccessful();

        // ---- delete ----
        $this->deleteJson('/api/v1/me', ['password' => 'correct-horse-9'])->assertOk();

        // Gone from every normal query, present when asked for explicitly.
        $this->assertNull(AppUser::query()->find($me->id));
        $row = AppUser::withTrashed()->findOrFail($me->id);

        // No PII left on the row or the profile.
        $this->assertSame("deleted-{$me->uuid}@invalid", $row->email);
        $this->assertNull($row->phone);
        $this->assertSame('Deleted member', $row->display_name);
        $this->assertNull($row->last_latitude);
        $this->assertNull($row->last_longitude);
        $this->assertSame(AccountStatus::Deactivated, $row->account_status);
        $this->assertNull($row->profile->bio);
        $this->assertNull($row->profile->job_title);

        // Photos: soft-deleted, files removed.
        $this->assertNull(Photo::query()->find($photo->id));
        Storage::disk('public')->assertMissing($photo->path);
        Storage::disk('public')->assertMissing($photo->thumb_path);

        // Every way back in is closed.
        $this->assertSame(0, $row->tokens()->count());
        $this->assertSame(0, $row->pushTokens()->count());
        $this->postJson('/api/v1/auth/login', ['email' => 'me@example.test', 'password' => 'correct-horse-9'])->assertStatus(422);

        // The conversation is closed for the other party; the messages remain.
        $this->assertSame('closed', $conversation->fresh()->status);
        $this->assertSame(2, Message::query()->where('conversation_id', $conversation->id)->count());

        // The moderation record is untouched in both directions.
        $this->assertSame(1, Report::query()->where('reporter_app_user_id', $me->id)->count(), 'EXPECTED the report I filed to survive — it is evidence against somebody else.');
        $this->assertSame(1, ReportCase::query()->where('subject_app_user_id', $me->id)->count(), 'EXPECTED the case against me to survive.');

        $this->assertDatabaseHas('activity_logs', ['module' => 'members', 'action' => 'account_deleted']);
    }

    public function test_the_old_token_is_useless_after_deletion(): void
    {
        $this->member(['email' => 'gone@example.test', 'password' => 'correct-horse-9']);

        $token = $this->postJson('/api/v1/auth/login', ['email' => 'gone@example.test', 'password' => 'correct-horse-9'])
            ->assertOk()
            ->json('token');

        $this->withToken($token)->deleteJson('/api/v1/me', ['password' => 'correct-horse-9'])->assertOk();

        // The row behind the token is gone…
        $this->assertDatabaseCount('personal_access_tokens', 0);

        // …and a fresh request with it is refused as unauthenticated. The
        // guard is forgotten first: inside one test it keeps the user it
        // already resolved, which would mask a token that still worked.
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
    }
}
