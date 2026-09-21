@php
    $tabs = [
        'admin.billing.payments' => ['Payments', 'payments'],
        'admin.billing.subscriptions' => ['Subscriptions', 'payments'],
        'admin.billing.plans' => ['Plans', 'settings'],
        'admin.billing.gateways' => ['Payment gateways', 'settings'],
    ];
@endphp

<nav class="flex flex-wrap gap-1" aria-label="Billing">
    @foreach ($tabs as $route => [$label, $permission])
        @can($permission)
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
        @endcan
    @endforeach
</nav>
