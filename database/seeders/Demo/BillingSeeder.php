<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\AppUser;
use App\Models\Plan;
use App\Support\Currency;
use Faker\Generator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Subscriptions and the payments behind them.
 *
 * The members already carry `is_premium` and a tier from the account mix; this
 * gives that money a history — who bought it, who was given it by support,
 * what failed on the way — so the billing screens have something to show on a
 * fresh install rather than an empty state.
 */
class BillingSeeder extends Seeder
{
    public function __construct(private readonly float $scale = 1.0) {}

    public function run(): void
    {
        $plans = Plan::query()->get()->keyBy('slug');

        if ($plans->isEmpty()) {
            return;
        }

        $faker = fake();
        $currency = Currency::code();
        $now = now();

        $members = AppUser::query()
            ->where('is_premium', true)
            ->whereNotNull('premium_tier')
            // Anyone who already has one keeps it: re-running must not give a
            // member two live subscriptions.
            ->whereNotIn('id', fn ($q) => $q->select('app_user_id')->from('subscriptions')->where('status', 'active'))
            ->get(['id', 'premium_tier', 'premium_until', 'created_at']);

        $subscriptions = [];
        $orders = [];

        foreach ($members as $member) {
            $plan = $plans->get($member->premium_tier);

            if ($plan === null) {
                continue;
            }

            // Most people pay; some were given a plan by support. Both need to
            // appear, because the screens read differently for each.
            $paid = $faker->boolean(80);
            $yearly = $faker->boolean(35);
            $endsAt = $member->premium_until ?? $now->copy()->addDays($faker->numberBetween(5, 300));
            $startsAt = $yearly ? $endsAt->copy()->subYear() : $endsAt->copy()->subMonth();

            if ($startsAt->lt($member->created_at)) {
                $startsAt = $member->created_at->copy()->addDay();
            }

            // A subscription cannot have started in the future, and neither can
            // the payment for it — a payments list dated next November reads as
            // broken data, because it would be.
            if ($startsAt->gt($now)) {
                $startsAt = $now->copy()->subDays($faker->numberBetween(1, 40));
            }

            $price = $yearly ? $plan->yearly_price ?? $plan->monthly_price : $plan->monthly_price;

            $subscriptions[] = [
                'app_user_id' => $member->id,
                'plan_id' => $plan->id,
                'plan_slug' => $plan->slug,
                'plan_name' => $plan->name,
                'status' => 'active',
                'source' => $paid ? 'payment' : 'manual',
                'billing_period' => $yearly ? 'yearly' : 'monthly',
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'amount' => $paid ? $price : null,
                'currency' => $paid ? $currency : null,
                'note' => $paid ? null : $faker->randomElement([
                    'Paid by bank transfer',
                    'Goodwill after a support issue',
                    'Press account',
                ]),
                'created_at' => $startsAt,
                'updated_at' => $startsAt,
            ];

            if ($paid) {
                $orders[] = $this->order($member->id, $plan, $yearly, $currency, $startsAt, 'paid', $faker);
            }
        }

        // A handful that did not go through, because a payments screen showing
        // nothing but successes teaches an operator nothing.
        $others = AppUser::query()->where('is_premium', false)->inRandomOrder()
            ->limit(max(3, (int) round(12 * $this->scale)))
            ->pluck('id');

        foreach ($others as $index => $memberId) {
            $plan = $plans->random();
            $status = match ($index % 4) {
                0 => 'pending',
                1, 2 => 'failed',
                default => 'cancelled',
            };

            $orders[] = $this->order(
                $memberId,
                $plan,
                false,
                $currency,
                $now->copy()->subDays($faker->numberBetween(1, 25)),
                $status,
                $faker,
            );
        }

        foreach (array_chunk($subscriptions, 500) as $chunk) {
            DB::table('subscriptions')->insert($chunk);
        }

        foreach (array_chunk($orders, 500) as $chunk) {
            DB::table('orders')->insert($chunk);
        }

        // The mirror on the member row points at the subscription that is live.
        DB::statement('
            UPDATE app_users u
            JOIN subscriptions s ON s.app_user_id = u.id AND s.status = "active"
            SET u.premium_until = s.ends_at
            WHERE u.is_premium = 1
        ');

        $this->command?->info('Seeded '.count($subscriptions).' subscriptions and '.count($orders).' payments.');
    }

    /** @return array<string, mixed> */
    private function order(int $memberId, Plan $plan, bool $yearly, string $currency, Carbon $at, string $status, Generator $faker): array
    {
        $price = $yearly ? $plan->yearly_price ?? $plan->monthly_price : $plan->monthly_price;
        $gateway = $currency === 'INR' ? 'razorpay' : 'stripe';
        $paid = $status === 'paid';

        return [
            'uuid' => (string) Str::uuid(),
            'app_user_id' => $memberId,
            'purpose' => 'plan',
            'reference' => $plan->slug,
            'description' => $plan->name.' · '.($yearly ? '12 months' : '1 month'),
            'amount_minor' => Currency::toMinor($price, $currency),
            'currency' => $currency,
            'billing_period' => $yearly ? 'yearly' : 'monthly',
            'gateway' => $gateway,
            'gateway_ref' => ($gateway === 'stripe' ? 'cs_test_' : 'plink_').$faker->regexify('[A-Za-z0-9]{14}'),
            'payment_ref' => $paid ? ($gateway === 'stripe' ? 'pi_' : 'pay_').$faker->regexify('[A-Za-z0-9]{14}') : null,
            'status' => $status,
            'failure_reason' => match ($status) {
                'failed' => $faker->randomElement([
                    'The card was declined.',
                    'The payment did not go through.',
                    'Authentication failed at the bank.',
                ]),
                'cancelled' => 'The member closed the payment page.',
                default => null,
            },
            'paid_at' => $paid ? $at : null,
            'created_at' => $at,
            'updated_at' => $at,
        ];
    }
}
