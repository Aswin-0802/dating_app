@php
    $tabs = [
        'admin.masters.plans' => 'Subscription plans',
        'admin.masters.interests' => 'Interests',
        'admin.masters.profile-options' => 'Profile questions',
        'admin.masters.report-categories' => 'Report categories',
        'admin.masters.reasons' => 'Enforcement reasons',
    ];
@endphp

<nav class="flex flex-wrap gap-1" aria-label="Masters">
    @foreach ($tabs as $route => $label)
        <a
            href="{{ route($route) }}"
            wire:navigate
            @class([
                'rounded-md px-3 py-1.5 text-sm',
                'bg-primary-subtle font-medium text-primary-subtle-foreground' => $active === $route,
                'text-muted-foreground hover:bg-muted hover:text-foreground' => $active !== $route,
            ])
            @if ($active === $route) aria-current="page" @endif
        >{{ $label }}</a>
    @endforeach
</nav>
