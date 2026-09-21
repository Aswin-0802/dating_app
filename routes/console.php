<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled work
|--------------------------------------------------------------------------
|
| One cron entry drives all of this:
|
|   * * * * * cd /path/to/veyra && php artisan schedule:run >> /dev/null 2>&1
|
| On Windows, a Task Scheduler task running `php artisan schedule:run` every
| minute does the same. Without it the product still works — suspensions clear
| themselves on the member's next request — but restrictions and plans would
| only end when somebody opened the right screen, and no renewal reminder
| would ever be sent.
|
*/

// Restrictions that have reached their end date, and plans that have run out.
Schedule::command('veyra:run-due-tasks')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();

// Renewal warnings. Hourly rather than daily so a plan bought at 11pm is still
// warned about at a sensible hour, and because each reminder is recorded as
// sent, running often costs nothing.
Schedule::command('veyra:send-renewal-reminders')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();
