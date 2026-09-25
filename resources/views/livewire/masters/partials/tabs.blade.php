@php
    $tabs = [
        'admin.masters.interests' => 'Interests',
        'admin.masters.profile-options' => 'Profile questions',
        'admin.masters.report-categories' => 'Report categories',
        'admin.masters.reasons' => 'Enforcement reasons',
    ];

    // Locations is platform configuration (edit_general_settings), not master
    // data: a tab that leads to a 403 is worse than no tab.
    if (auth()->user()?->can('edit_general_settings')) {
        $tabs['admin.masters.locations'] = 'Locations';
    }
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
