<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AccountStatus;
use App\Enums\Gender;
use App\Enums\RiskBand;
use App\Enums\VerificationStatus;
use App\Models\AppUser;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<AppUser>
 *
 * For tests. The realistic demo population comes from
 * Database\Seeders\Demo\AppUserSeeder, which shapes its distributions
 * deliberately; this just produces a valid member.
 */
class AppUserFactory extends Factory
{
    protected $model = AppUser::class;

    public function definition(): array
    {
        $gender = $this->faker->randomElement(Gender::cases());

        return [
            'uuid' => (string) Str::uuid(),
            'display_name' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'phone' => null,
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'birthdate' => $this->faker->dateTimeBetween('-45 years', '-19 years'),
            'gender' => $gender,
            'pronouns' => match ($gender) {
                Gender::Woman => 'she/her',
                Gender::Man => 'he/him',
                default => 'they/them',
            },
            'account_status' => AccountStatus::Active,
            'verification_status' => VerificationStatus::Unverified,
            'is_premium' => false,
            'signup_source' => $this->faker->randomElement(['ios', 'android', 'web']),
            'profile_completion' => $this->faker->numberBetween(40, 100),
            'risk_score' => 0,
            'risk_band' => 'low',
            'last_active_at' => now()->subHours($this->faker->numberBetween(0, 72)),
        ];
    }

    public function verified(): static
    {
        return $this->state(fn (): array => [
            'verification_status' => VerificationStatus::Approved,
            'verified_at' => now()->subDays(10),
        ]);
    }

    public function banned(): static
    {
        return $this->state(fn (): array => [
            'account_status' => AccountStatus::Banned,
            'banned_at' => now(),
        ]);
    }

    /**
     * A shadow ban must never be visible to the member, so tests that assert
     * the API hides it start here.
     */
    public function shadowBanned(): static
    {
        return $this->state(fn (): array => [
            'account_status' => AccountStatus::ShadowBanned,
            'shadow_banned_until' => now()->addWeek(),
        ]);
    }

    public function atRisk(int $score = 80): static
    {
        return $this->state(fn (): array => [
            'risk_score' => $score,
            'risk_band' => RiskBand::fromScore($score),
            'risk_calculated_at' => now(),
        ]);
    }
}
