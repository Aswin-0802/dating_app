<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\AppUser;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Demo email, SMS and payment logs.
 *
 * Subscription revenue is derived from the members who are actually marked
 * premium, so the payment log and the member records agree — invented rows
 * would show revenue from accounts that never paid for anything.
 */
class SystemLogSeeder extends Seeder
{
    public function __construct(private readonly float $scale = 1.0) {}

    public function run(): void
    {
        $faker = fake();
        $faker->seed(config('veyra.seed.faker_seed', 20260917) + 5);

        $emails = $this->seedEmailLogs($faker);
        $sms = $this->seedSmsLogs($faker);
        $payments = $this->seedPaymentLogs($faker);

        $this->command?->info(sprintf(
            'Seeded %s email, %s SMS and %s payment records.',
            number_format($emails),
            number_format($sms),
            number_format($payments),
        ));
    }

    private function seedEmailLogs($faker): int
    {
        $members = DB::table('app_users')
            ->inRandomOrder()
            // Floored, not purely proportional: at 50 members a strict share
            // would be five rows, and the delivery log is read as a log — it
            // needs enough history to page through and filter.
            ->limit(max(60, (int) round(1200 * $this->scale)))
            ->get(['id', 'email', 'display_name']);

        $kinds = [
            ['Welcome to Veyra', 'welcome'],
            ['Verify your email address', 'email.verify'],
            ['Reset your password', 'password.reset'],
            ['Your verification was approved', 'verification.approved'],
            ['We could not verify your photo', 'verification.rejected'],
            ['Action has been taken on your account', 'enforcement.notice'],
            ['We received your appeal', 'appeal.received'],
            ['Your receipt from Veyra', 'billing.receipt'],
        ];

        $rows = [];
        $total = 0;

        foreach ($members as $member) {
            foreach (range(1, $faker->numberBetween(1, 3)) as $ignored) {
                [$subject, $key] = $faker->randomElement($kinds);

                // 3% bounce, 1% fail — a realistic tail, and enough for the
                // failure filter to return something.
                $status = $faker->boolean(3) ? 'bounced' : ($faker->boolean(1) ? 'failed' : 'delivered');
                $sentAt = Carbon::now()->subDays($faker->numberBetween(0, 120))
                    ->subMinutes($faker->numberBetween(0, 1440));

                $rows[] = [
                    'to' => $member->email,
                    'subject' => $subject,
                    'template_key' => $key,
                    'mailable' => null,
                    'status' => $status,
                    'failure_reason' => match ($status) {
                        'bounced' => $faker->randomElement(['Mailbox does not exist', 'Mailbox full']),
                        'failed' => 'SMTP connection refused',
                        default => null,
                    },
                    'recipient_type' => AppUser::class,
                    'recipient_id' => $member->id,
                    'sent_at' => $status === 'failed' ? null : $sentAt,
                    'created_at' => $sentAt,
                ];

                $total++;

                if (count($rows) >= 1000) {
                    DB::table('email_logs')->insert($rows);
                    $rows = [];
                }
            }
        }

        if ($rows !== []) {
            DB::table('email_logs')->insert($rows);
        }

        return $total;
    }

    private function seedSmsLogs($faker): int
    {
        $members = DB::table('app_users')
            ->whereNotNull('phone')
            ->inRandomOrder()
            ->limit(max(40, (int) round(700 * $this->scale)))
            ->get(['id', 'phone']);

        $rows = [];
        $total = 0;

        foreach ($members as $member) {
            $code = $faker->numerify('######');
            $body = "Your Veyra verification code is {$code}. It expires in 10 minutes.";
            $status = $faker->boolean(4) ? 'failed' : 'delivered';
            $sentAt = Carbon::now()->subDays($faker->numberBetween(0, 90));

            $rows[] = [
                'to' => $member->phone,
                'body' => $body,
                'gateway' => $faker->randomElement(['twilio', 'msg91', 'vonage']),
                'status' => $status,
                'failure_reason' => $status === 'failed'
                    ? $faker->randomElement(['Unreachable number', 'Carrier rejected', 'Invalid number'])
                    : null,
                'segments' => 1,
                'cost' => $faker->randomFloat(4, 0.006, 0.045),
                'recipient_type' => AppUser::class,
                'recipient_id' => $member->id,
                'sent_at' => $status === 'failed' ? null : $sentAt,
                'created_at' => $sentAt,
            ];

            $total++;

            if (count($rows) >= 1000) {
                DB::table('sms_logs')->insert($rows);
                $rows = [];
            }
        }

        if ($rows !== []) {
            DB::table('sms_logs')->insert($rows);
        }

        return $total;
    }

    /**
     * Payments come from the members actually marked premium, so the revenue on
     * this screen matches the subscribers on the users screen.
     */
    private function seedPaymentLogs($faker): int
    {
        $subscribers = DB::table('app_users')
            ->where('is_premium', true)
            ->get(['id', 'premium_tier', 'created_at']);

        $prices = ['plus' => 12.99, 'gold' => 24.99];
        $rows = [];
        $total = 0;

        foreach ($subscribers as $subscriber) {
            $tier = $subscriber->premium_tier ?? 'plus';

            // A few months of billing history each.
            foreach (range(1, $faker->numberBetween(1, 6)) as $month) {
                $chargedAt = Carbon::now()->subMonths($month);

                if ($chargedAt->lessThan(Carbon::parse($subscriber->created_at))) {
                    continue;
                }

                $status = match (true) {
                    $faker->boolean(2) => 'disputed',
                    $faker->boolean(3) => 'refunded',
                    $faker->boolean(4) => 'failed',
                    default => 'succeeded',
                };

                $rows[] = [
                    'uuid' => (string) Str::uuid(),
                    'app_user_id' => $subscriber->id,
                    'gateway' => $faker->randomElement(['stripe', 'paypal']),
                    'gateway_reference' => 'ch_'.$faker->bothify('??##########'),
                    'product' => 'Veyra '.ucfirst($tier),
                    'amount' => $prices[$tier] ?? 12.99,
                    'currency' => 'GBP',
                    'status' => $status,
                    'failure_reason' => $status === 'failed'
                        ? $faker->randomElement(['Card declined', 'Insufficient funds', 'Card expired'])
                        : null,
                    'disputed_at' => $status === 'disputed' ? $chargedAt->copy()->addDays(9) : null,
                    'refunded_at' => $status === 'refunded' ? $chargedAt->copy()->addDays(3) : null,
                    'response' => null,
                    'created_at' => $chargedAt,
                    'updated_at' => $chargedAt,
                ];

                $total++;

                if (count($rows) >= 1000) {
                    DB::table('payment_logs')->insert($rows);
                    $rows = [];
                }
            }
        }

        if ($rows !== []) {
            DB::table('payment_logs')->insert($rows);
        }

        return $total;
    }
}
