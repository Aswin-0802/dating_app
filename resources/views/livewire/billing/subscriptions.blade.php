<div class="space-y-4 md:space-y-6">
    @include('livewire.billing.partials.tabs', ['active' => 'admin.billing.subscriptions'])

    <x-ui.card title="Subscriptions" description="Who is on a plan, and whose is about to run out.">
        <div class="mb-5 space-y-3">
            <div class="flex flex-wrap gap-1">
                @foreach ([
                    'active' => ['On a plan', $counts['active']],
                    'ending' => ['Ending this week', $counts['ending']],
                    'expired' => ['Lapsed', $counts['expired']],
                    'all' => ['All', null],
                ] as $key => [$label, $count])
                    <button
                        type="button"
                        wire:click="setView('{{ $key }}')"
                        @class([
                            'rounded-md px-3 py-1.5 text-sm transition-colors',
                            'bg-primary-subtle font-medium text-primary-subtle-foreground' => $view === $key,
                            'text-muted-foreground hover:bg-muted hover:text-foreground' => $view !== $key,
                        ])
                    >
                        {{ $label }}
                        @if ($count !== null)
                            <span class="tabular ml-1 text-xs {{ $key === 'ending' && $count > 0 ? 'font-semibold text-warning-subtle-foreground' : 'text-muted-foreground' }}">{{ platform_number($count) }}</span>
                        @endif
                    </button>
                @endforeach
            </div>

            <div class="flex flex-wrap items-end gap-2">
                <div class="relative min-w-0 flex-1 sm:max-w-xs">
                    <x-ui.icon name="search" size="sm" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground" />
                    <input
                        type="search"
                        wire:model.live.debounce.400ms="search"
                        placeholder="Name or email"
                        aria-label="Search subscribers"
                        class="h-9 w-full rounded-md border border-input bg-card pl-9 pr-3 text-sm placeholder:text-muted-foreground"
                    >
                </div>

                @if ($plans->isNotEmpty())
                    <x-ui.select size="sm" label="Plan" placeholder="Any" wire:model.live="plan"
                        :options="$plans->mapWithKeys(fn ($p) => [$p->slug => $p->name])->all()" />
                @endif

                <x-ui.select size="sm" label="How" placeholder="Any" wire:model.live="source"
                    :options="['payment' => 'Paid online', 'manual' => 'Given by staff']" />
            </div>
        </div>

        @if ($subscriptions->isEmpty())
            <x-ui.empty-state
                icon="sparkles"
                heading="Nothing here"
                :description="$view === 'ending' ? 'No plan ends in the next seven days.' : 'No subscriptions match this view.'"
            />
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-border text-left text-xs text-muted-foreground">
                            <th class="py-2 pr-3 font-medium">Member</th>
                            <th class="py-2 pr-3 font-medium">Plan</th>
                            <th class="py-2 pr-3 font-medium">Started</th>
                            <th class="py-2 pr-3 font-medium">Ends</th>
                            <th class="py-2 pr-3 font-medium">How</th>
                            <th class="py-2 pr-3 text-right font-medium">Paid</th>
                            <th class="py-2 pr-3 font-medium">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @foreach ($subscriptions as $subscription)
                            <tr wire:key="sub-{{ $subscription->id }}">
                                <td class="py-2.5 pr-3">
                                    @if ($subscription->appUser)
                                        <a href="{{ route('admin.users.show', $subscription->appUser) }}" wire:navigate class="font-medium hover:underline">
                                            {{ $subscription->appUser->display_name }}
                                        </a>
                                        <span class="block text-xs text-muted-foreground">{{ $subscription->appUser->email }}</span>
                                    @else
                                        <span class="text-muted-foreground">Deleted member</span>
                                    @endif
                                </td>
                                <td class="py-2.5 pr-3 font-medium">{{ $subscription->plan_name }}</td>
                                <td class="whitespace-nowrap py-2.5 pr-3 text-muted-foreground">{{ platform_date($subscription->starts_at) }}</td>
                                <td class="whitespace-nowrap py-2.5 pr-3">
                                    @if ($subscription->ends_at === null)
                                        <span class="text-muted-foreground">No end date</span>
                                    @else
                                        {{ platform_date($subscription->ends_at) }}
                                        @if ($subscription->status === 'active' && $subscription->ends_at->isBefore(now()->addDays(7)))
                                            <span class="block text-xs font-medium text-warning-subtle-foreground">
                                                {{ $subscription->daysLeft() <= 0 ? 'today' : 'in '.$subscription->daysLeft().' days' }}
                                            </span>
                                        @endif
                                    @endif
                                </td>
                                <td class="py-2.5 pr-3 text-muted-foreground">
                                    {{ $subscription->source === 'payment' ? 'Paid online' : 'Given by staff' }}
                                    @if ($subscription->grantedBy)
                                        <span class="block text-xs">{{ $subscription->grantedBy->name }}</span>
                                    @endif
                                </td>
                                <td class="tabular py-2.5 pr-3 text-right">
                                    {{ $subscription->amount !== null ? App\Support\Currency::format($subscription->amount, $subscription->currency) : '—' }}
                                </td>
                                <td class="py-2.5 pr-3">
                                    @if ($subscription->status === 'active')
                                        <x-ui.badge size="sm" variant="success" dot>Active</x-ui.badge>
                                    @elseif ($subscription->status === 'expired')
                                        <x-ui.badge size="sm" variant="muted">Lapsed</x-ui.badge>
                                    @else
                                        <x-ui.badge size="sm" variant="warning">Replaced</x-ui.badge>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-4">{{ $subscriptions->links() }}</div>
        @endif
    </x-ui.card>
</div>
