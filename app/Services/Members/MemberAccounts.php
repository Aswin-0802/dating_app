<?php

declare(strict_types=1);

namespace App\Services\Members;

use App\Enums\AccountStatus;
use App\Models\AppUser;
use App\Models\AppUserLogin;
use App\Models\Preference;
use App\Models\Profile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Sign-up and sign-in bookkeeping, shared by the website and the mobile API.
 */
final class MemberAccounts
{
    /**
     * @param  array{display_name: string, email: string, password: string, birthdate: string, gender: string, interested_in: array<int, string>, city_id?: int|null}  $data
     * @param  'ios'|'android'|'web'  $source
     */
    public function register(array $data, string $source): AppUser
    {
        return DB::transaction(function () use ($data, $source): AppUser {
            $member = AppUser::query()->create([
                'uuid' => (string) Str::uuid(),
                'display_name' => $data['display_name'],
                'email' => strtolower($data['email']),
                'password' => Hash::make($data['password']),
                'birthdate' => $data['birthdate'],
                'gender' => $data['gender'],
                'city_id' => $data['city_id'] ?? null,
                'country_id' => isset($data['city_id'])
                    ? DB::table('cities')->where('id', $data['city_id'])->value('country_id')
                    : null,
                // Pending until a profile exists — an empty profile in the deck
                // is a bad experience for everybody who sees it.
                'account_status' => AccountStatus::Pending,
                'signup_source' => $source,
                'last_active_at' => now(),
            ]);

            Profile::query()->create(['app_user_id' => $member->id]);

            Preference::query()->create([
                'app_user_id' => $member->id,
                'interested_in' => $data['interested_in'],
            ]);

            return $member;
        });
    }

    /**
     * Every attempt is recorded, including failures, so credential stuffing is
     * visible in System -> Member sign-ins.
     */
    public function recordLogin(AppUser $member, ?string $ip, bool $succeeded): void
    {
        AppUserLogin::query()->create([
            'app_user_id' => $member->id,
            'ip_address' => $ip,
            'succeeded' => $succeeded,
        ]);

        if ($succeeded) {
            $member->forceFill(['last_active_at' => now()])->saveQuietly();
        }
    }
}
