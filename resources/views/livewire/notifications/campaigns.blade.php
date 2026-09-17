<div class="space-y-4 md:space-y-6">

    @if ($awaitingApproval > 0)
        <div class="flex flex-wrap items-center gap-3 rounded-xl border border-warning/40 bg-warning-subtle px-4 py-3">
            <x-ui.icon name="warning" size="sm" class="shrink-0 text-warning-subtle-foreground" />
            <p class="min-w-0 flex-1 text-sm text-warning-subtle-foreground">
                <span class="font-medium">{{ $awaitingApproval }}</span>
                {{ str('campaign')->plural($awaitingApproval) }} waiting for approval. A push reaches
                every recipient at once and cannot be recalled, so somebody other than the
                author has to read it first.
            </p>
        </div>
    @endif

    <x-ui.table :density="$density">
        <x-slot:toolbar>
            <div class="flex flex-1 flex-wrap items-center gap-2">
                <div class="relative min-w-0 flex-1 sm:max-w-xs">
                    <x-ui.icon name="search" size="sm"
                        class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground" />
                    <input type="search" wire:model.live.debounce.300ms="search" placeholder="Campaign name"
                        class="h-9 w-full rounded-md border border-input bg-card pl-9 pr-3 text-sm placeholder:text-muted-foreground">
                </div>

                <x-ui.select size="sm" placeholder="Any status" wire:model.live="status"
                    :options="['draft' => 'Draft', 'scheduled' => 'Scheduled', 'sending' => 'Sending', 'sent' => 'Sent', 'cancelled' => 'Cancelled']"
                    class="w-40" />
            </div>

            <div class="flex items-center gap-2">
                <x-ui.button variant="outline" size="sm" icon="document" :href="route('admin.notifications.templates')">
                    Templates
                </x-ui.button>
                <x-ui.button variant="outline" size="sm" icon="inbox" :href="route('admin.notifications.logs')">
                    Delivery logs
                </x-ui.button>
            </div>
        </x-slot:toolbar>

        <thead class="[&_tr]:border-b [&_tr]:border-border">
            <tr>
                <x-ui.table.head>Campaign</x-ui.table.head>
                <x-ui.table.head>Audience</x-ui.table.head>
                <x-ui.table.head>Status</x-ui.table.head>
                <x-ui.table.head align="right">Sent</x-ui.table.head>
                <x-ui.table.head align="right">Delivered</x-ui.table.head>
                <x-ui.table.head align="right">Opened</x-ui.table.head>
                <x-ui.table.head>Approval</x-ui.table.head>
                <x-ui.table.head align="right" width="140px"><span class="sr-only">Actions</span></x-ui.table.head>
            </tr>
        </thead>

        <tbody wire:loading.class="opacity-50">
            @forelse ($campaigns as $campaign)
                <x-ui.table.row :tint="$campaign->needsApproval() && $campaign->status !== 'draft' ? 'border-l-2 border-l-warning' : ''">
                    <x-ui.table.cell wrap>
                        <p class="text-sm font-medium">{{ $campaign->name }}</p>
                        <p class="truncate text-xs text-muted-foreground">{{ $campaign->title }}</p>
                    </x-ui.table.cell>

                    <x-ui.table.cell muted>
                        <span class="text-sm">{{ $campaign->audience_label ?? 'All members' }}</span>
                        <span class="tabular block text-xs">
                            {{ veyra_compact_number($campaign->estimated_recipients) }} recipients
                        </span>
                    </x-ui.table.cell>

                    <x-ui.table.cell>
                        <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium {{ $campaign->statusClasses() }}">
                            {{ ucfirst($campaign->status) }}
                        </span>
                        @if ($campaign->scheduled_for && $campaign->status === 'scheduled')
                            <span class="mt-0.5 block text-xs text-muted-foreground">
                                {{ veyra_datetime($campaign->scheduled_for) }}
                            </span>
                        @endif
                    </x-ui.table.cell>

                    <x-ui.table.cell align="right" numeric>{{ veyra_compact_number($campaign->sent_count) }}</x-ui.table.cell>

                    <x-ui.table.cell align="right" numeric muted>
                        @if ($campaign->deliveryRate() !== null)
                            {{ veyra_percent($campaign->deliveryRate(), 0) }}
                        @else
                            —
                        @endif
                    </x-ui.table.cell>

                    <x-ui.table.cell align="right" numeric>
                        @if ($campaign->openRate() !== null)
                            {{-- Against delivered, not sent: measuring against
                                 sent credits a campaign for notifications that
                                 never arrived. --}}
                            <span class="font-medium">{{ veyra_percent($campaign->openRate(), 0) }}</span>
                        @else
                            <span class="text-muted-foreground">—</span>
                        @endif
                    </x-ui.table.cell>

                    <x-ui.table.cell>
                        @if ($campaign->approved_at)
                            <span class="text-xs text-muted-foreground">
                                {{ $campaign->approvedBy?->name }}
                            </span>
                        @elseif ($campaign->status === 'cancelled')
                            <span class="text-xs text-muted-foreground">—</span>
                        @else
                            <x-ui.badge variant="warning" size="sm">Needs approval</x-ui.badge>
                        @endif
                    </x-ui.table.cell>

                    <x-ui.table.cell align="right">
                        <div class="flex items-center justify-end gap-1">
                            @can('approve_campaigns')
                                @if ($campaign->needsApproval() && $campaign->status !== 'cancelled')
                                    <x-ui.button size="xs" variant="outline" wire:click="approve({{ $campaign->id }})">
                                        Approve
                                    </x-ui.button>
                                @endif
                            @endcan

                            @can('send_notifications')
                                @if (in_array($campaign->status, ['draft', 'scheduled'], true))
                                    <x-ui.button size="xs" variant="ghost" wire:click="cancel({{ $campaign->id }})"
                                        wire:confirm="Cancel this campaign?">Cancel</x-ui.button>
                                @endif
                            @endcan
                        </div>
                    </x-ui.table.cell>
                </x-ui.table.row>
            @empty
                <tr>
                    <td colspan="8"><x-ui.empty-state icon="bell" heading="No campaigns match this view" /></td>
                </tr>
            @endforelse
        </tbody>

        <x-slot:footer>
            <x-ui.pagination :paginator="$campaigns" :per-page="$perPage" :per-page-options="$this->perPageOptions()" />
        </x-slot:footer>
    </x-ui.table>
</div>
