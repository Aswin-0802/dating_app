<?php

declare(strict_types=1);

use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Veyra global helpers
|--------------------------------------------------------------------------
|
| Autoloaded through composer's `files` block. Kept small on purpose — anything
| with real behaviour belongs in a service, not here.
|
| Note: ext-intl is not available in this environment, so all number and date
| formatting below is done by hand rather than through Number:: or IntlDateFormatter.
|
*/

if (! function_exists('veyra_setting')) {
    /**
     * Read an operator-editable setting, falling back to config/veyra.php.
     *
     * Guarded against a missing table so `migrate:fresh` and early boot do not
     * explode before the settings table exists.
     */
    function veyra_setting(string $key, mixed $default = null): mixed
    {
        /*
         * Delegates to Setting::allValues(), which caches through the cache
         * store and busts on write.
         *
         * A static cache local to this function would be faster but would not
         * see a change made during the same process — so an operator saving a
         * setting would not see it take effect, and neither would a test.
         */
        try {
            // No Schema::hasTable() check: that is a schema query on every
            // call, and branding alone calls this dozens of times per page. A
            // missing table throws, which the catch below already handles.
            $values = Setting::allValues();
        } catch (Throwable) {
            return $default ?? config("veyra.{$key}");
        }

        return $values[$key] ?? $default ?? config("veyra.{$key}");
    }
}

if (! function_exists('veyra_number')) {
    /** 1234567 -> "1,234,567" */
    function veyra_number(int|float|null $value, int $decimals = 0): string
    {
        return number_format((float) ($value ?? 0), $decimals, '.', ',');
    }
}

if (! function_exists('veyra_compact_number')) {
    /**
     * 1234 -> "1.2k", 1500000 -> "1.5M". Used in stat tiles where the exact
     * figure is available on hover but the headline must stay short.
     */
    function veyra_compact_number(int|float|null $value): string
    {
        $value = (float) ($value ?? 0);
        $abs = abs($value);

        [$divisor, $suffix] = match (true) {
            $abs >= 1_000_000_000 => [1_000_000_000, 'B'],
            $abs >= 1_000_000 => [1_000_000, 'M'],
            $abs >= 1_000 => [1_000, 'k'],
            default => [1, ''],
        };

        $scaled = $value / $divisor;

        // One decimal only while it adds information: 1.2k, but 12k not 12.0k.
        $decimals = $suffix !== '' && abs($scaled) < 10 ? 1 : 0;
        $formatted = number_format($scaled, $decimals, '.', ',');

        // Trim only a trailing fractional zero ("1.0k" -> "1k"). Trimming
        // unconditionally also eats the real zeros in "890", turning it into 89.
        if (str_contains($formatted, '.')) {
            $formatted = rtrim(rtrim($formatted, '0'), '.');
        }

        return $formatted.$suffix;
    }
}

if (! function_exists('veyra_percent')) {
    function veyra_percent(int|float|null $value, int $decimals = 1): string
    {
        return number_format((float) ($value ?? 0), $decimals, '.', ',').'%';
    }
}

if (! function_exists('veyra_date')) {
    /** "17 Sep 2026" — no ext-intl, so the format is explicit. */
    function veyra_date(Carbon|string|null $value): string
    {
        if ($value === null) {
            return '—';
        }

        return Carbon::parse($value)->format('j M Y');
    }
}

if (! function_exists('veyra_datetime')) {
    /** "17 Sep 2026, 14:05" */
    function veyra_datetime(Carbon|string|null $value): string
    {
        if ($value === null) {
            return '—';
        }

        return Carbon::parse($value)->format('j M Y, H:i');
    }
}

if (! function_exists('veyra_duration')) {
    /**
     * A compact elapsed-time label for queue age pills: "8m", "3h 12m", "2d 4h".
     *
     * Deliberately not Carbon's diffForHumans() — moderators scan these in
     * columns, and "about 3 hours ago" is both longer and less precise.
     */
    function veyra_duration(Carbon|string|null $from, Carbon|string|null $to = null): string
    {
        if ($from === null) {
            return '—';
        }

        $from = Carbon::parse($from);
        $to = $to ? Carbon::parse($to) : Carbon::now();
        $minutes = (int) abs($from->diffInMinutes($to));

        if ($minutes < 60) {
            return "{$minutes}m";
        }

        if ($minutes < 1440) {
            $hours = intdiv($minutes, 60);
            $rest = $minutes % 60;

            return $rest > 0 ? "{$hours}h {$rest}m" : "{$hours}h";
        }

        $days = intdiv($minutes, 1440);
        $hours = intdiv($minutes % 1440, 60);

        return $hours > 0 ? "{$days}d {$hours}h" : "{$days}d";
    }
}

if (! function_exists('veyra_hours_label')) {
    /** 24 -> "24 hours", 168 -> "7 days", 720 -> "30 days" */
    function veyra_hours_label(?int $hours): string
    {
        if ($hours === null) {
            return 'Permanent';
        }

        if ($hours % 24 === 0) {
            $days = intdiv($hours, 24);

            return $days === 1 ? '1 day' : "{$days} days";
        }

        return $hours === 1 ? '1 hour' : "{$hours} hours";
    }
}

if (! function_exists('veyra_initials')) {
    /** Avatar fallback: "Ada Lovelace" -> "AL". */
    function veyra_initials(?string $name): string
    {
        $parts = preg_split('/\s+/', trim((string) $name)) ?: [];
        $parts = array_values(array_filter($parts));

        if ($parts === []) {
            return '?';
        }

        $first = mb_strtoupper(mb_substr($parts[0], 0, 1));

        if (count($parts) === 1) {
            return $first;
        }

        return $first.mb_strtoupper(mb_substr(end($parts), 0, 1));
    }
}
