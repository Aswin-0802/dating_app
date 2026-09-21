@props([
    'since' => null,
    'dueAt' => null,
    'label' => null,
])

{{--
    Queue age with SLA state.

    Colour comes from the deadline when one exists, and from elapsed time
    otherwise. A breached item always says by how much — "breached" alone tells a
    moderator nothing about whether to jump on it.
--}}

@php
    use Illuminate\Support\Carbon;

    $now = Carbon::now();
    $since = $since ? Carbon::parse($since) : null;
    $dueAt = $dueAt ? Carbon::parse($dueAt) : null;

    $breached = $dueAt && $now->greaterThan($dueAt);
    $warningHours = (int) config('platform.sla.pill.warning_hours', 1);
    $breachHours = (int) config('platform.sla.pill.breach_hours', 4);

    if ($dueAt) {
        // Inside the last quarter of the window counts as "at risk".
        $atRisk = ! $breached && $now->diffInMinutes($dueAt) < ($dueAt->diffInMinutes($since ?? $dueAt->copy()->subDay()) * 0.25);
    } else {
        $elapsed = $since ? $since->diffInHours($now) : 0;
        $breached = $elapsed >= $breachHours;
        $atRisk = ! $breached && $elapsed >= $warningHours;
    }

    $classes = match (true) {
        $breached => 'bg-destructive text-white',
        $atRisk => 'bg-warning-subtle text-warning-subtle-foreground',
        default => 'bg-muted text-muted-foreground',
    };

    $text = $label ?? platform_duration($since);
@endphp

<span
    {{ $attributes->class(['inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium tabular whitespace-nowrap', $classes]) }}
    @if ($dueAt) title="Due {{ platform_datetime($dueAt) }}" @endif
>
    <x-ui.icon name="clock" size="xs" />
    {{ $text }}
    @if ($breached && $dueAt)
        <span class="opacity-80">· over by {{ platform_duration($dueAt) }}</span>
    @endif
</span>
