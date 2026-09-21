<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Services\Notifications\MemberNotifier;
use Illuminate\Console\Command;
use Throwable;

/**
 * Warn members before their plan runs out.
 *
 * Push through the last week, email closer in — the defaults are
 * 7, 3 and 1 days for push and 3 days for email, and both lists are editable
 * in Settings so an operator can make them quieter or louder without a deploy.
 *
 * Each reminder is recorded on the subscription, so running this every hour
 * (or twice by accident) never sends the same warning twice.
 */
class SendRenewalReminders extends Command
{
    protected $signature = 'platform:send-renewal-reminders {--dry-run : List who would be told, and send nothing}';

    protected $description = 'Tell members whose plan is about to end';

    public function handle(MemberNotifier $notifier): int
    {
        $pushDays = $this->days('billing.reminder_push_days', '7,3,1');
        $emailDays = $this->days('billing.reminder_email_days', '3');

        if ($pushDays === [] && $emailDays === []) {
            $this->info('Renewal reminders are switched off.');

            return self::SUCCESS;
        }

        $window = max([...$pushDays, ...$emailDays, 1]);
        $sent = 0;

        Subscription::query()
            ->active()
            ->whereNotNull('ends_at')
            ->whereBetween('ends_at', [now(), now()->addDays($window)->endOfDay()])
            ->with('appUser')
            ->chunkById(100, function ($subscriptions) use ($notifier, $pushDays, $emailDays, &$sent): void {
                foreach ($subscriptions as $subscription) {
                    $sent += $this->remind($subscription, $notifier, $pushDays, $emailDays);
                }
            });

        $this->line($this->option('dry-run') ? "Would send {$sent} reminders." : "Reminders sent: {$sent}");

        return self::SUCCESS;
    }

    /**
     * @param  array<int, int>  $pushDays
     * @param  array<int, int>  $emailDays
     */
    private function remind(Subscription $subscription, MemberNotifier $notifier, array $pushDays, array $emailDays): int
    {
        $member = $subscription->appUser;
        $daysLeft = $subscription->daysLeft();

        if ($member === null || $daysLeft === null || $daysLeft < 0) {
            return 0;
        }

        // A member with a restricted account has bigger problems than renewing,
        // and a banned account cannot use what it would be paying for.
        if ($member->isRestricted()) {
            return 0;
        }

        $values = [
            'first_name' => str($member->display_name)->before(' ')->toString(),
            'plan_name' => $subscription->plan_name,
            'days_left' => $daysLeft,
            'end_date' => platform_date($subscription->ends_at),
        ];

        $sent = 0;

        foreach ([['push', $pushDays], ['email', $emailDays]] as [$channel, $days]) {
            // The first threshold the member has reached and not yet been told
            // about — so a plan bought for two days still gets one warning.
            $due = collect($days)->filter(fn (int $day): bool => $daysLeft <= $day)->max();

            if ($due === null) {
                continue;
            }

            $key = "{$channel}_{$due}";

            if ($subscription->hasSentReminder($key)) {
                continue;
            }

            if ($this->option('dry-run')) {
                $this->line("  would {$channel} {$member->email} ({$daysLeft} days left)");
                $sent++;

                continue;
            }

            try {
                $delivered = $channel === 'push'
                    ? $notifier->push($member, 'billing.renewal_push', $values, route('member.premium'))
                    : $notifier->email($member, 'billing.renewal_email', $values, 'See your plan', route('member.premium'));
            } catch (Throwable $e) {
                report($e);
                $this->warn("Could not warn {$member->email}: {$e->getMessage()}");

                continue;
            }

            // Only recorded when it actually went out: if push was not
            // configured yet, the member should still get the warning once it is.
            if ($delivered) {
                $subscription->markReminderSent($key);
                $sent++;
            }
        }

        return $sent;
    }

    /** @return array<int, int> */
    private function days(string $setting, string $default): array
    {
        return collect(explode(',', (string) platform_setting($setting, $default)))
            ->map(fn (string $day): int => (int) trim($day))
            ->filter(fn (int $day): bool => $day >= 0 && $day <= 60)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}
