<div class="space-y-4 md:space-y-6">
    @include('livewire.billing.partials.tabs', ['active' => 'admin.billing.payments'])

    {{-- ---- totals ---------------------------------------------------------- --}}
    <div class="grid gap-4 sm:grid-cols-3">
        <x-ui.card>
            <p class="text-xs text-muted-foreground">Taken</p>
            @if ($totals['amounts']->isEmpty())
                <p class="tabular mt-1 text-2xl font-semibold">{{ App\Support\Currency::format(0) }}</p>
            @else
                @foreach ($totals['amounts'] as $currency => $minor)
                    <p class="tabular mt-1 text-2xl font-semibold">{{ $this->formatMinor((int) $minor, $currency) }}</p>
                @endforeach
            @endif
            <p class="mt-1 text-xs text-muted-foreground">{{ platform_number($totals['count']) }} paid</p>
        </x-ui.card>

        <x-ui.card>
            <p class="text-xs text-muted-foreground">Waiting</p>
            <p class="tabular mt-1 text-2xl font-semibold">{{ platform_number($totals['pending']) }}</p>
            <p class="mt-1 text-xs text-muted-foreground">Started but never confirmed</p>
        </x-ui.card>

        <x-ui.card>
            <p class="text-xs text-muted-foreground">Failed</p>
            <p class="tabular mt-1 text-2xl font-semibold">{{ platform_number($totals['failed']) }}</p>
            <p class="mt-1 text-xs text-muted-foreground">A run of these usually means a key or a card rule</p>
        </x-ui.card>
    </div>

    {{-- ---- list ------------------------------------------------------------ --}}
    <x-ui.card title="Payments" :description="'Every attempt to pay, newest first.'">
        @if ($canExport)
            <x-slot:action>
                <x-ui.button size="sm" variant="outline" icon="download" wire:click="export">Export CSV</x-ui.button>
            </x-slot:action>
        @endif

        <div class="mb-5 flex flex-wrap items-end gap-2">
            <div class="relative min-w-0 flex-1 sm:max-w-xs">
                <x-ui.icon name="search" size="sm" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground" />
                <input
                    type="search"
                    wire:model.live.debounce.400ms="search"
                    placeholder="Name, email or reference"
                    aria-label="Search payments"
                    class="h-9 w-full rounded-md border border-input bg-card pl-9 pr-3 text-sm placeholder:text-muted-foreground"
                >
            </div>

            <x-ui.select size="sm" label="Status" placeholder="Any" wire:model.live="status"
                :options="['paid' => 'Paid', 'pending' => 'Waiting', 'failed' => 'Failed', 'cancelled' => 'Cancelled', 'expired' => 'Expired']" />

            @if ($gateways->isNotEmpty())
                <x-ui.select size="sm" label="Gateway" placeholder="Any" wire:model.live="gateway"
                    :options="$gateways->mapWithKeys(fn ($g) => [$g => ucfirst($g)])->all()" />
            @endif

            <x-ui.select size="sm" label="Period" wire:model.live="period" :selected="$period"
                :options="['7' => 'Last 7 days', '30' => 'Last 30 days', '90' => 'Last 90 days', '365' => 'Last year', '' => 'All time']" />

            <x-ui.button size="sm" variant="ghost" wire:click="resetFilters">Reset</x-ui.button>
        </div>

        @if ($orders->isEmpty())
            <x-ui.empty-state
                icon="document"
                heading="No payments yet"
                description="Payments appear here the moment a member is sent to a gateway, whether or not it goes through."
            />
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-border text-left text-xs text-muted-foreground">
                            <th class="py-2 pr-3 font-medium">When</th>
                            <th class="py-2 pr-3 font-medium">Member</th>
                            <th class="py-2 pr-3 font-medium">Item</th>
                            <th class="py-2 pr-3 text-right font-medium">Amount</th>
                            <th class="py-2 pr-3 font-medium">Gateway</th>
                            <th class="py-2 pr-3 font-medium">Status</th>
                            <th class="py-2"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @foreach ($orders as $order)
                            <tr wire:key="order-{{ $order->id }}">
                                <td class="whitespace-nowrap py-2.5 pr-3 text-muted-foreground">{{ platform_datetime($order->created_at) }}</td>
                                <td class="py-2.5 pr-3">
                                    @if ($order->appUser)
                                        <a href="{{ route('admin.users.show', $order->appUser) }}" wire:navigate class="font-medium hover:underline">
                                            {{ $order->appUser->display_name }}
                                        </a>
                                        <span class="block text-xs text-muted-foreground">{{ $order->appUser->email }}</span>
                                    @else
                                        <span class="text-muted-foreground">Deleted member</span>
                                    @endif
                                </td>
                                <td class="py-2.5 pr-3">
                                    {{ $order->description }}
                                    <span class="block font-mono text-[11px] text-muted-foreground">{{ $order->payment_ref ?? $order->gateway_ref ?? $order->uuid }}</span>
                                </td>
                                <td class="tabular py-2.5 pr-3 text-right font-medium">{{ $order->formattedAmount() }}</td>
                                <td class="py-2.5 pr-3 text-muted-foreground">{{ ucfirst($order->gateway) }}</td>
                                <td class="py-2.5 pr-3">
                                    <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium {{ $order->statusClasses() }}">
                                        {{ $order->status === 'pending' ? 'Waiting' : ucfirst($order->status) }}
                                    </span>
                                    @if ($order->failure_reason)
                                        <span class="block text-xs text-muted-foreground">{{ $order->failure_reason }}</span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap py-2.5 text-right">
                                    @if ($order->status === 'pending')
                                        <x-ui.button size="xs" variant="ghost" icon="arrow-path" wire:click="recheck({{ $order->id }})">
                                            Check gateway
                                        </x-ui.button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-4">{{ $orders->links() }}</div>
        @endif
    </x-ui.card>
</div>
