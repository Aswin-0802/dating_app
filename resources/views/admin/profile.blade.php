@php
    $user = auth()->user();
@endphp

<x-layouts.admin
    title="My profile"
    :breadcrumbs="[
        ['label' => App\Support\Branding::name(), 'href' => route('admin.dashboard')],
        ['label' => 'My profile'],
    ]"
>
    <div class="grid gap-4 md:gap-6 lg:grid-cols-3">
        <x-ui.card class="lg:col-span-2" title="Account">
            <dl class="divide-y divide-border text-sm">
                @foreach ([
                    'Name' => $user->name,
                    'Email' => $user->email,
                    'Job title' => $user->job_title ?? '—',
                    'Role' => $user->role_name,
                    'Last sign-in' => veyra_datetime($user->last_login_at),
                    'Last sign-in IP' => $user->last_login_ip ?? '—',
                ] as $label => $value)
                    <div class="flex items-center justify-between gap-4 py-2.5 first:pt-0 last:pb-0">
                        <dt class="text-muted-foreground">{{ $label }}</dt>
                        <dd class="truncate font-medium text-foreground">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>
        </x-ui.card>

        <x-ui.card title="Your permissions" :description="$user->getAllPermissions()->count().' granted'">
            <div class="max-h-96 space-y-3 overflow-y-auto">
                @foreach ($user->getAllPermissions()->groupBy('group_name')->sortKeys() as $group => $permissions)
                    <div>
                        <p class="mb-1.5 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                            {{ $group }}
                        </p>

                        <div class="flex flex-wrap gap-1">
                            @foreach ($permissions as $permission)
                                <x-ui.badge :variant="$permission->is_sensitive ? 'destructive' : 'muted'" size="sm">
                                    {{ $permission->display_label }}
                                </x-ui.badge>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </x-ui.card>
    </div>
</x-layouts.admin>
