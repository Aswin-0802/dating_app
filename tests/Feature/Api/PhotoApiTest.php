<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\AccountStatus;
use App\Models\AppUser;
use App\Models\Interest;
use App\Models\Photo;
use App\Models\Preference;
use App\Models\Profile;
use App\Models\Setting;
use Database\Seeders\InterestSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PhotoApiTest extends TestCase
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

        Profile::query()->create(['app_user_id' => $member->id]);
        Preference::query()->create([
            'app_user_id' => $member->id,
            'interested_in' => ['woman', 'man', 'non_binary', 'other'],
            'age_min' => 18,
            'age_max' => 99,
        ]);

        return $member->fresh();
    }

    private function upload(string $name = 'photo.jpg')
    {
        return $this->postJson('/api/v1/me/photos', ['photo' => UploadedFile::fake()->image($name, 800, 600)]);
    }

    // ---------------------------------------------------------------- upload

    public function test_a_member_can_upload_a_photo_and_the_first_one_becomes_primary(): void
    {
        $member = $this->member();
        Sanctum::actingAs($member, ['profile:write']);

        $response = $this->upload()->assertCreated()
            ->assertJsonStructure(['data' => ['id', 'url', 'thumb_url', 'position', 'is_primary', 'moderation_status']]);

        $this->assertTrue($response->json('data.is_primary'));
        $this->assertSame(1, $response->json('data.position'));
        $this->assertSame('pending', $response->json('data.moderation_status'));

        // Through MemberPhotoStore: re-encoded to JPEG on the public disk,
        // both sizes, never the original bytes.
        $photo = Photo::query()->where('app_user_id', $member->id)->firstOrFail();
        Storage::disk('public')->assertExists($photo->path);
        Storage::disk('public')->assertExists($photo->thumb_path);
        $this->assertStringEndsWith('.jpg', $photo->path);

        $this->upload('second.jpg')->assertCreated()->assertJsonPath('data.is_primary', false)->assertJsonPath('data.position', 2);
    }

    public function test_a_non_image_and_an_oversized_upload_are_refused(): void
    {
        Sanctum::actingAs($this->member(), ['profile:write']);

        $this->postJson('/api/v1/me/photos', [
            'photo' => UploadedFile::fake()->create('cv.pdf', 200, 'application/pdf'),
        ])->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors('photo');

        $this->postJson('/api/v1/me/photos', [
            'photo' => UploadedFile::fake()->image('huge.jpg')->size(10241),
        ])->assertStatus(422)->assertJsonValidationErrors('photo');

        $this->assertDatabaseCount('photos', 0);
    }

    public function test_the_photo_limit_comes_from_settings_and_answers_with_its_own_code(): void
    {
        Setting::query()->updateOrCreate(['key' => 'matching.max_photos'], ['value' => 2, 'type' => 'number', 'group' => 'matching']);
        Setting::flush();

        Sanctum::actingAs($this->member(), ['profile:write']);

        $this->upload('one.jpg')->assertCreated();
        $this->upload('two.jpg')->assertCreated();

        $this->upload('three.jpg')
            ->assertStatus(422)
            ->assertJsonPath('code', 'photo_limit_reached')
            ->assertJsonPath('limit', 2);

        $this->assertDatabaseCount('photos', 2);
    }

    // ---------------------------------------------------------------- ownership

    public function test_deleting_reordering_and_promoting_somebody_elses_photo_is_refused(): void
    {
        $owner = $this->member();
        $intruder = $this->member(['email' => 'intruder@example.test']);

        Sanctum::actingAs($owner, ['profile:write']);
        $theirs = $this->upload()->json('data.id');

        Sanctum::actingAs($intruder, ['profile:write']);
        $mine = $this->upload('mine.jpg')->json('data.id');

        $this->deleteJson("/api/v1/me/photos/{$theirs}")->assertForbidden();
        $this->patchJson("/api/v1/me/photos/{$theirs}/primary")->assertForbidden();

        // One foreign uuid poisons the whole reorder — nothing is applied.
        $this->patchJson('/api/v1/me/photos/reorder', ['uuids' => [$mine, $theirs]])->assertForbidden();

        $this->assertSame(1, Photo::query()->where('uuid', $mine)->value('position'));
        $this->assertNotNull(Photo::query()->where('uuid', $theirs)->first(), 'EXPECTED the other member\'s photo to be untouched.');

        // A made-up id is not found, not forbidden: the client's list is stale.
        $this->deleteJson('/api/v1/me/photos/00000000-0000-0000-0000-000000000000')->assertNotFound();
    }

    public function test_a_member_can_delete_reorder_and_choose_a_primary(): void
    {
        $member = $this->member();
        Sanctum::actingAs($member, ['profile:write']);

        $a = $this->upload('a.jpg')->json('data.id');
        $b = $this->upload('b.jpg')->json('data.id');
        $c = $this->upload('c.jpg')->json('data.id');

        // Reorder: the full list, in the new order.
        $order = $this->patchJson('/api/v1/me/photos/reorder', ['uuids' => [$c, $a, $b]])->assertOk()->json('data');
        $this->assertSame([$c, $a, $b], array_column($order, 'id'));
        $this->assertSame([1, 2, 3], array_column($order, 'position'));

        // A partial list is a stale client, not an attack.
        $this->patchJson('/api/v1/me/photos/reorder', ['uuids' => [$a, $b]])->assertStatus(422)->assertJsonValidationErrors('uuids');

        // Primary moves, and only one photo holds it.
        $photos = $this->patchJson("/api/v1/me/photos/{$b}/primary")->assertOk()->json('data');
        $this->assertSame([$b], array_column(array_filter($photos, fn (array $p): bool => $p['is_primary']), 'id'));

        // Deleting the primary hands it to the next photo by position.
        $this->deleteJson("/api/v1/me/photos/{$b}")->assertOk();
        $this->assertNull(Photo::query()->where('uuid', $b)->first());
        $this->assertNotNull(Photo::withTrashed()->where('uuid', $b)->first(), 'EXPECTED a soft delete, so a photo that is evidence survives.');
        $this->assertTrue((bool) Photo::query()->where('uuid', $c)->value('is_primary'));
    }

    // ---------------------------------------------------------------- the dead end

    /**
     * The whole reason these endpoints exist. A pending token carries only
     * profile abilities; it must still be able to upload, and once completion
     * crosses the threshold the account is promoted and a fresh token carries
     * the swipe and message abilities.
     */
    public function test_a_pending_member_can_upload_and_is_promoted_when_completion_crosses_half(): void
    {
        $member = $this->member([
            'account_status' => AccountStatus::Pending,
            'email' => 'pending@example.test',
            'password' => 'correct-horse-9',
        ]);

        /*
         * Real tokens throughout, never Sanctum::actingAs(): actingAs pins the
         * guard's user for the rest of the test, so a later withToken() would
         * silently keep the pinned abilities and prove nothing.
         */
        $login = fn () => $this->postJson('/api/v1/auth/login', ['email' => 'pending@example.test', 'password' => 'correct-horse-9'])
            ->assertOk()
            ->json('token');

        $pendingToken = $login();

        // Three checklist items: 3/8 = 38%. Still pending.
        $this->using($pendingToken)
            ->patchJson('/api/v1/me/profile', ['bio' => 'Hello there', 'job_title' => 'Nurse', 'education' => 'Undergrad'])
            ->assertOk();
        $this->assertSame(AccountStatus::Pending, $member->fresh()->account_status);

        // A pending token lacks the swipe ability.
        $this->using($pendingToken)->getJson('/api/v1/deck')->assertForbidden();

        // …but it can upload. The photo is the fourth item: 4/8 = 50%.
        $this->using($pendingToken)
            ->postJson('/api/v1/me/photos', ['photo' => UploadedFile::fake()->image('first.jpg', 800, 600)])
            ->assertCreated();

        $member->refresh();
        $this->assertSame(AccountStatus::Active, $member->account_status);
        $this->assertSame(50, $member->profile_completion);
        $this->assertNotNull($member->profile_completed_at);

        // A token issued now carries the full set.
        $activeToken = $login();

        $this->using($activeToken)->getJson('/api/v1/deck')->assertOk();
        $this->using($activeToken)->getJson('/api/v1/conversations')->assertOk();

        // And the old pending token still does not — abilities are fixed at issue.
        $this->using($pendingToken)->getJson('/api/v1/deck')->assertForbidden();
    }

    /**
     * Send the next request as this token.
     *
     * The auth guard keeps the user it resolved for the rest of the test, so
     * switching tokens with withToken() alone would keep the FIRST token's
     * abilities and prove nothing. Forgetting the guards forces a real
     * re-resolution from the token, as a new request would.
     */
    private function using(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    public function test_interests_saved_from_the_api_count_towards_completion(): void
    {
        $member = $this->member(['account_status' => AccountStatus::Pending]);
        Sanctum::actingAs($member, ['profile:read', 'profile:write', 'verify']);

        // bio · job · education = 3, interests = 4 → 50%. The website already
        // did this; the API silently did not.
        $this->patchJson('/api/v1/me/profile', ['bio' => 'Hello there', 'job_title' => 'Nurse', 'education' => 'Undergrad'])->assertOk();

        $slugs = Interest::query()->where('is_active', true)->limit(3)->pluck('slug')->all();
        $this->putJson('/api/v1/me/interests', ['slugs' => $slugs])->assertOk();

        $this->assertSame(AccountStatus::Active, $member->fresh()->account_status);
    }
}
