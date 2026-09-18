<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Enums\AccountStatus;
use App\Enums\Gender;
use App\Enums\VerificationStatus;
use App\Models\City;
use App\Models\Interest;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Demo members.
 *
 * The distributions here are not decoration — each one exists so a specific
 * screen says something true rather than something flat:
 *
 *  - signups follow a growth curve over 540 days, because a uniform spread makes
 *    every cohort and retention chart look synthetic;
 *  - verified members get a materially better funnel, because otherwise the
 *    flagship "verified vs unverified" comparison is a straight line;
 *  - one city is deliberately broken at 68/32, so the marketplace balance screen
 *    has a red row to find.
 */
class AppUserSeeder extends Seeder
{
    private const DAYS = 540;

    /** gender => weight */
    private const GENDER_MIX = [
        Gender::Woman->value => 46,
        Gender::Man->value => 49,
        Gender::NonBinary->value => 3.5,
        Gender::Other->value => 1.5,
    ];

    /** [minAge, maxAge] => weight — log-normal-ish, centred on 27. */
    private const AGE_BANDS = [
        [18, 21, 11], [22, 25, 26], [26, 29, 24], [30, 34, 19],
        [35, 39, 10], [40, 49, 7], [50, 64, 3],
    ];

    private const STATUS_MIX = [
        AccountStatus::Active->value => 84.5,
        AccountStatus::Pending->value => 2.5,
        AccountStatus::Limited->value => 1.6,
        AccountStatus::ShadowBanned->value => 1.2,
        AccountStatus::Suspended->value => 1.8,
        AccountStatus::Banned->value => 3.4,
        AccountStatus::Deactivated->value => 5.0,
    ];

    private const VERIFICATION_MIX = [
        VerificationStatus::Approved->value => 38,
        VerificationStatus::Unverified->value => 47,
        VerificationStatus::Pending->value => 4,
        VerificationStatus::Rejected->value => 8,
        VerificationStatus::Expired->value => 3,
    ];

    /**
     * Cities with an intentionally skewed gender ratio (share of "man").
     *
     * A healthy marketplace sits near 0.5. The 0.68 entry is the one the balance
     * screen should flag.
     */
    private const CITY_SKEW = [
        'Austin' => 0.68,
        'Berlin' => 0.58,
        'Lagos' => 0.58,
        'Chicago' => 0.57,
        'Sao Paulo' => 0.56,
        'Madrid' => 0.55,
        'Paris' => 0.43,
        'Amsterdam' => 0.44,
    ];

    public function __construct(private readonly int $target = 12_000) {}

    public function run(): void
    {
        $faker = fake();
        $faker->seed(config('veyra.seed.faker_seed', 20260917));

        $cities = City::query()->with('country')->get();
        $focusCities = $cities->where('is_focus', true)->values();
        $otherCities = $cities->where('is_focus', false)->values();
        $interestIds = Interest::query()->pluck('id')->all();

        $password = Hash::make('password');
        $now = Carbon::now();

        $users = [];
        $profiles = [];
        $preferences = [];
        $pivots = [];

        // Dealt across the population rather than rolled per member. See
        // stratify() — at 50 members an independent roll leaves whole account
        // states with nobody in them.
        $statuses = $this->stratify($faker, self::STATUS_MIX, $this->target);
        $verifications = $this->stratify($faker, self::VERIFICATION_MIX, $this->target);

        $bar = $this->command?->getOutput()->createProgressBar($this->target);
        $bar?->start();

        for ($i = 1; $i <= $this->target; $i++) {
            $createdAt = $this->signupDate($faker);

            // 70% of members live in the 12 focus cities, so per-city screens
            // have enough volume per row to be worth reading.
            $city = $faker->boolean(70) && $focusCities->isNotEmpty()
                ? $focusCities[$faker->numberBetween(0, $focusCities->count() - 1)]
                : $otherCities[$faker->numberBetween(0, max(0, $otherCities->count() - 1))];

            $gender = $this->genderFor($faker, $city->name);
            $age = $this->age($faker);
            $birthdate = $now->copy()->subYears($age)->subDays($faker->numberBetween(0, 364));

            $verification = $verifications[$i - 1];
            $isVerified = $verification === VerificationStatus::Approved->value;
            $status = $statuses[$i - 1];

            $firstName = $this->firstNameFor($faker, $gender);
            $displayName = $firstName.' '.$faker->lastName();

            $milestones = $this->milestones($faker, $createdAt, $isVerified);

            // Verified members convert better and are worth more; both facts are
            // what the verification business case on the dashboard rests on.
            $isPremium = $faker->boolean($isVerified ? 19 : 6);

            $users[] = [
                'uuid' => $faker->uuid(),
                'display_name' => $displayName,
                'email' => strtolower(str($firstName)->slug().'.'.$i.'@'.$faker->safeEmailDomain()),
                'phone' => $faker->boolean(72) ? '+'.$faker->numerify('###########') : null,
                'password' => $password,
                'email_verified_at' => $createdAt,
                'phone_verified_at' => $faker->boolean(60) ? $createdAt : null,
                'birthdate' => $birthdate->toDateString(),
                'gender' => $gender,
                'pronouns' => $this->pronounsFor($gender),
                'city_id' => $city->id,
                'country_id' => $city->country_id,
                'last_latitude' => $city->latitude + $faker->randomFloat(4, -0.08, 0.08),
                'last_longitude' => $city->longitude + $faker->randomFloat(4, -0.08, 0.08),
                'account_status' => $status,
                'verification_status' => $verification,
                'is_premium' => $isPremium,
                'premium_tier' => $isPremium ? ($faker->boolean(70) ? 'plus' : 'gold') : null,
                'premium_until' => $isPremium ? $now->copy()->addDays($faker->numberBetween(1, 330)) : null,
                'signup_source' => $this->weighted($faker, ['ios' => 46, 'android' => 47, 'web' => 7]),
                'profile_completion' => $this->completion($faker),
                'profile_completed_at' => $milestones['profile_completed_at'],
                'verified_at' => $isVerified ? $milestones['verified_at'] : null,
                'first_swipe_at' => $milestones['first_swipe_at'],
                'first_match_at' => $milestones['first_match_at'],
                'first_message_at' => $milestones['first_message_at'],
                'first_reply_at' => $milestones['first_reply_at'],
                'last_active_at' => $this->lastActive($faker, $createdAt, $now),
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ];

            $profiles[] = $this->profileRow($faker, $i);
            $preferences[] = $this->preferenceRow($faker, $i, $gender, $age);

            foreach ($faker->randomElements($interestIds, $faker->numberBetween(2, 4)) as $interestId) {
                $pivots[] = ['app_user_id' => $i, 'interest_id' => $interestId];
            }

            if (count($users) >= 1000) {
                $this->flush($users, $profiles, $preferences, $pivots);
                $bar?->advance(1000);
            }
        }

        $remaining = count($users);
        $this->flush($users, $profiles, $preferences, $pivots);
        $bar?->advance($remaining);
        $bar?->finish();

        $this->command?->newLine(2);
        $this->command?->info("Seeded {$this->target} members.");
    }

    /**
     * @param  array<int, array<string, mixed>>  $users
     * @param  array<int, array<string, mixed>>  $profiles
     * @param  array<int, array<string, mixed>>  $preferences
     * @param  array<int, array<string, mixed>>  $pivots
     */
    private function flush(array &$users, array &$profiles, array &$preferences, array &$pivots): void
    {
        if ($users !== []) {
            DB::table('app_users')->insert($users);
            DB::table('profiles')->insert($profiles);
            DB::table('preferences')->insert($preferences);
        }

        foreach (array_chunk($pivots, 2000) as $chunk) {
            DB::table('app_user_interest')->insertOrIgnore($chunk);
        }

        $users = $profiles = $preferences = $pivots = [];
    }

    /**
     * Signups ramp from ~300/month to ~1,400/month with a seasonal bump, rather
     * than spreading evenly across the window.
     */
    private function signupDate($faker): Carbon
    {
        // Squaring a uniform draw biases towards recent dates, which is what a
        // growing product's signup curve actually looks like.
        $t = sqrt($faker->randomFloat(6, 0, 1));
        $daysAgo = (int) round((1 - $t) * self::DAYS);

        return Carbon::now()
            ->subDays($daysAgo)
            ->setTime($this->activeHour($faker), $faker->numberBetween(0, 59));
    }

    /** Evening-weighted, because that is when dating apps are used. */
    private function activeHour($faker): int
    {
        return $faker->boolean(62)
            ? $faker->numberBetween(18, 23)
            : $faker->numberBetween(7, 17);
    }

    private function age($faker): int
    {
        $bands = [];

        foreach (self::AGE_BANDS as [$min, $max, $weight]) {
            $bands["{$min}-{$max}"] = $weight;
        }

        [$min, $max] = explode('-', $this->weighted($faker, $bands));

        return $faker->numberBetween((int) $min, (int) $max);
    }

    private function genderFor($faker, string $cityName): string
    {
        $skew = self::CITY_SKEW[$cityName] ?? null;

        if ($skew !== null) {
            // Skewed cities still get a small non-binary/other tail.
            if ($faker->boolean(5)) {
                return $faker->boolean(70) ? Gender::NonBinary->value : Gender::Other->value;
            }

            return $faker->randomFloat(4, 0, 1) < $skew ? Gender::Man->value : Gender::Woman->value;
        }

        return $this->weighted($faker, self::GENDER_MIX);
    }

    private function pronounsFor(string $gender): ?string
    {
        return match ($gender) {
            Gender::Woman->value => 'she/her',
            Gender::Man->value => 'he/him',
            default => 'they/them',
        };
    }

    /** Bimodal: people either fill their profile in or barely start it. */
    private function completion($faker): int
    {
        return match ($this->weighted($faker, ['high' => 34, 'mid' => 28, 'low' => 22, 'none' => 16])) {
            'high' => $faker->numberBetween(90, 100),
            'mid' => $faker->numberBetween(60, 89),
            'low' => $faker->numberBetween(30, 59),
            default => $faker->numberBetween(0, 29),
        };
    }

    private function lastActive($faker, Carbon $createdAt, Carbon $now): Carbon
    {
        $bucket = $this->weighted($faker, ['24h' => 22, '7d' => 19, '30d' => 22, 'older' => 37]);

        $candidate = match ($bucket) {
            '24h' => $now->copy()->subMinutes($faker->numberBetween(1, 1440)),
            '7d' => $now->copy()->subDays($faker->numberBetween(1, 7)),
            '30d' => $now->copy()->subDays($faker->numberBetween(8, 30)),
            default => $now->copy()->subDays($faker->numberBetween(31, self::DAYS)),
        };

        // Nobody can be active before they signed up.
        return $candidate->lessThan($createdAt) ? $createdAt->copy() : $candidate;
    }

    /**
     * Funnel milestones, generated by rule rather than randomly — these columns
     * ARE the funnel chart, so the drop-off between steps has to be deliberate.
     *
     * @return array<string, ?Carbon>
     */
    private function milestones($faker, Carbon $createdAt, bool $isVerified): array
    {
        $steps = [
            'profile_completed_at' => null,
            'verified_at' => null,
            'first_swipe_at' => null,
            'first_match_at' => null,
            'first_message_at' => null,
            'first_reply_at' => null,
        ];

        $cursor = $createdAt->copy();

        if (! $faker->boolean($isVerified ? 96 : 71)) {
            return $steps;
        }

        $steps['profile_completed_at'] = $cursor = $cursor->copy()->addMinutes($faker->numberBetween(3, 90));

        if ($isVerified) {
            $steps['verified_at'] = $cursor = $cursor->copy()->addHours($faker->numberBetween(1, 120));
        }

        if (! $faker->boolean($isVerified ? 97 : 88)) {
            return $steps;
        }

        $steps['first_swipe_at'] = $cursor = $cursor->copy()->addMinutes($faker->numberBetween(1, 60));

        if (! $faker->boolean($isVerified ? 79 : 54)) {
            return $steps;
        }

        $steps['first_match_at'] = $cursor = $cursor->copy()->addHours($faker->numberBetween(1, 48));

        if (! $faker->boolean($isVerified ? 81 : 62)) {
            return $steps;
        }

        $steps['first_message_at'] = $cursor = $cursor->copy()->addMinutes($faker->numberBetween(5, 900));

        if (! $faker->boolean($isVerified ? 74 : 57)) {
            return $steps;
        }

        $steps['first_reply_at'] = $cursor->copy()->addMinutes($faker->numberBetween(2, 600));

        return $steps;
    }

    /** @return array<string, mixed> */
    private function profileRow($faker, int $appUserId): array
    {
        $hasBio = $faker->boolean(84);

        // ~3% of bios carry a handle or phone-like string, which is what the
        // bio_contains_contact risk factor keys off.
        $containsContact = $hasBio && $faker->boolean(4);
        $bio = $hasBio ? $this->bio($faker, $containsContact) : null;

        return [
            'app_user_id' => $appUserId,
            'bio' => $bio,
            'height_cm' => $faker->boolean(70) ? $faker->numberBetween(150, 200) : null,
            'job_title' => $faker->boolean(72) ? $faker->jobTitle() : null,
            'company' => $faker->boolean(48) ? $faker->company() : null,
            'school' => $faker->boolean(41) ? $faker->city().' University' : null,
            'education' => $faker->boolean(50) ? $faker->randomElement(['High school', 'Undergrad', 'Postgrad', 'PhD', 'Trade school']) : null,
            'relationship_goal' => $faker->randomElement(['long_term', 'long_term', 'short_term', 'friends', 'figuring_out', 'unspecified']),
            'drinking' => $faker->randomElement(['never', 'socially', 'socially', 'often', 'unspecified']),
            'smoking' => $faker->randomElement(['never', 'never', 'socially', 'often', 'unspecified']),
            'children' => $faker->randomElement(['have', 'want', 'dont_want', 'unspecified', 'unspecified']),
            'religion' => $faker->boolean(35) ? $faker->randomElement(['Agnostic', 'Atheist', 'Christian', 'Muslim', 'Jewish', 'Hindu', 'Buddhist', 'Spiritual']) : null,
            'politics' => $faker->boolean(28) ? $faker->randomElement(['Liberal', 'Moderate', 'Conservative', 'Apolitical', 'Left', 'Green']) : null,
            'languages' => json_encode($faker->randomElements(['English', 'Spanish', 'French', 'German', 'Portuguese', 'Yoruba', 'Hindi', 'Japanese', 'Italian', 'Polish'], $faker->numberBetween(1, 3))),
            'prompts' => json_encode($this->prompts($faker)),
            'bio_contains_contact' => $containsContact,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * Two prompt answers in plain English.
     *
     * These are read on the member website, where lorem ipsum makes the whole
     * product look broken rather than seeded.
     *
     * @return array<int, array{q: string, a: string}>
     */
    private function prompts($faker): array
    {
        $answers = [
            'A perfect Sunday' => [
                'Long breakfast, longer walk, then a nap I will deny taking.',
                'Farmers market, cooking something ambitious, failing, ordering pizza.',
                'Swimming in the sea no matter the weather, then a pub roast.',
                'Record shop, coffee, and absolutely no plans after 4pm.',
            ],
            'I geek out on' => [
                'Maps. Old ones, new ones, the ones on the back of cereal boxes.',
                'Bread. I have a sourdough starter with a name and a birthday.',
                'Football tactics. I will draw you a diagram on a napkin.',
                'Houseplants. Currently keeping 34 of them alive, just.',
            ],
            'The way to win me over' => [
                'Recommend me a book and then actually want to talk about it.',
                'Laugh at my jokes, even the bad ones. Especially the bad ones.',
                'Know a good dumpling place.',
            ],
            'My simple pleasures' => [
                'The first coffee, a clean kitchen, and a good playlist.',
                'Cold pillows and the smell of rain.',
                'Finding a parking space right outside.',
            ],
        ];

        return collect($faker->randomElements(array_keys($answers), 2))
            ->map(fn (string $question): array => ['q' => $question, 'a' => $faker->randomElement($answers[$question])])
            ->all();
    }

    private function bio($faker, bool $withContact): string
    {
        $openers = [
            'Probably overthinking my next coffee order.',
            'Here for good conversation and better playlists.',
            'Weekend hiker, weekday spreadsheet wrangler.',
            'I will out-argue you about pizza toppings.',
            'New to the city, show me something I would never find alone.',
            'Looking for someone who also reads the plaques in museums.',
            'Two things: I cook, and I do the washing up.',
            'Fluent in sarcasm and mediocre guitar.',
            'Dog person. Cat-tolerant. Negotiable.',
            'Ask me about the time I got lost in Lisbon.',
        ];

        $closers = [
            'Tell me your best terrible joke.',
            'Swipe right if you own a passport and use it.',
            'Bonus points for board games.',
            'No small talk, please.',
            'Let us get a drink and find out.',
        ];

        $bio = $faker->randomElement($openers).' '.$faker->randomElement($closers);

        if ($withContact) {
            $bio .= $faker->randomElement([
                ' IG: @'.$faker->userName(),
                ' add me on snap - '.$faker->userName(),
                ' whatsapp me '.$faker->numerify('0## ### ####'),
            ]);
        }

        return $bio;
    }

    /** @return array<string, mixed> */
    private function preferenceRow($faker, int $appUserId, string $gender, int $age): array
    {
        $interestedIn = match ($gender) {
            Gender::Woman->value => $faker->boolean(85) ? ['man'] : $faker->randomElements(['man', 'woman', 'non_binary'], 2),
            Gender::Man->value => $faker->boolean(87) ? ['woman'] : $faker->randomElements(['man', 'woman', 'non_binary'], 2),
            default => $faker->randomElements(['man', 'woman', 'non_binary'], $faker->numberBetween(1, 3)),
        };

        return [
            'app_user_id' => $appUserId,
            'interested_in' => json_encode(array_values($interestedIn)),
            'age_min' => max(18, $age - $faker->numberBetween(2, 8)),
            'age_max' => $age + $faker->numberBetween(3, 12),
            'max_distance_km' => $faker->randomElement([10, 25, 50, 80, 100, 160]),
            'global_mode' => $faker->boolean(8),
            'show_verified_only' => $faker->boolean(14),
            'deal_breakers' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function firstNameFor($faker, string $gender): string
    {
        return match ($gender) {
            Gender::Woman->value => $faker->firstNameFemale(),
            Gender::Man->value => $faker->firstNameMale(),
            default => $faker->firstName(),
        };
    }

    /**
     * Weighted pick. Weights need not sum to 100.
     *
     * @param  array<string, float|int>  $weights
     */
    /**
     * Deal a weighted mix across the population instead of rolling it per member.
     *
     * Independent draws are correct at scale and wrong at the bottom of it.
     * Shadow-banned is 1.2% of the mix; over 50 members that is a coin which
     * comes up empty more often than not, and a category that rounds to nobody
     * takes a whole admin screen down with it — no shadow bans means no shadow
     * ban review queue, and the screen reads as broken rather than as quiet.
     *
     * Dealing guarantees every category is represented while keeping the
     * proportions it asks for, and at demo scale the result is indistinguishable
     * from the rolls it replaces.
     *
     * @param  array<string, float>  $mix
     * @return array<int, string>
     */
    private function stratify($faker, array $mix, int $count): array
    {
        $total = array_sum($mix);
        $dominant = (string) array_search(max($mix), $mix, true);
        $deck = [];

        foreach ($mix as $value => $weight) {
            $share = max(1, (int) round($count * $weight / $total));
            $deck = array_merge($deck, array_fill(0, $share, (string) $value));
        }

        // Rounding every category up to at least one overshoots on a small
        // population. The surplus comes off the dominant category, which is the
        // only one that can spare it.
        while (count($deck) > $count) {
            $position = array_search($dominant, $deck, true);

            if ($position === false) {
                break;
            }

            unset($deck[$position]);
            $deck = array_values($deck);
        }

        while (count($deck) < $count) {
            $deck[] = $dominant;
        }

        return $faker->shuffleArray($deck);
    }

    private function weighted($faker, array $weights): string
    {
        $total = array_sum($weights);
        $roll = $faker->randomFloat(4, 0, $total);
        $cumulative = 0.0;

        foreach ($weights as $key => $weight) {
            $cumulative += $weight;

            if ($roll <= $cumulative) {
                return (string) $key;
            }
        }

        return (string) array_key_last($weights);
    }
}
