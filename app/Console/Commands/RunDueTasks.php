<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Moderation\ApplyEnforcement;
use App\Models\Ban;
use App\Services\Billing\Subscriptions;
use Illuminate\Console\Command;
use Throwable;

/**
 * Everything the clock is responsible for.
 *
 * One command rather than four, because an operator who forgets one cron line
 * gets a product where bans never lift; and it is safe to run by hand at any
 * time, which is how support fixes "this should have ended yesterday".
 */
class RunDueTasks extends Command
{
    protected $signature = 'veyra:run-due-tasks {--only= : enforcement|subscriptions}';

    protected $description = 'Lift expired restrictions and end lapsed subscriptions';

    public function handle(ApplyEnforcement $enforcement, Subscriptions $subscriptions): int
    {
        $only = $this->option('only');

        if ($only === null || $only === 'enforcement') {
            $this->line('Expired restrictions lifted: '.$this->expireEnforcements($enforcement));
        }

        if ($only === null || $only === 'subscriptions') {
            $this->line('Subscriptions ended: '.$subscriptions->expireDue());
        }

        return self::SUCCESS;
    }

    private function expireEnforcements(ApplyEnforcement $enforcement): int
    {
        $lifted = 0;

        Ban::query()
            ->whereNull('lifted_at')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->with('appUser')
            ->chunkById(100, function ($bans) use ($enforcement, &$lifted): void {
                foreach ($bans as $ban) {
                    try {
                        $enforcement->expire($ban);
                        $lifted++;
                    } catch (Throwable $e) {
                        // One member's bad state must not stop the rest of the
                        // queue from being cleared.
                        report($e);
                        $this->warn("Could not expire ban {$ban->id}: {$e->getMessage()}");
                    }
                }
            });

        return $lifted;
    }
}
