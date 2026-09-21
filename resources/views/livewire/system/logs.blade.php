<div class="space-y-4 md:space-y-6">

    {{-- One component serves all four logs: they differ in columns, not
         behaviour, and four near-identical classes would drift apart the first
         time somebody fixed a bug in only one of them. --}}
    <div class="flex flex-wrap items-center gap-1">
        @foreach ($kinds as $key => $meta)
            <a
                href="{{ route('admin.settings.logs', $key) }}"
                wire:navigate
                @class([
                    'rounded-md px-3 py-1.5 text-sm font-medium transition-colors',
                    'bg-primary-subtle text-primary-subtle-foreground' => $kind === $key,
                    'text-muted-foreground hover:bg-muted hover:text-foreground' => $kind !== $key,
                ])
            >{{ $meta['label'] }}</a>
        @endforeach
    </div>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4 md:gap-6">
        @foreach ($summary as $label => $value)
            @php
                $lower = strtolower($label);
                $isBad = str_contains($lower, 'fail') || str_contains($lower, 'bounce') || str_contains($lower, 'disput');
            @endphp

            <x-ui.stat-card
                :label="$label"
                :value="veyra_compact_number($value)"
                :icon="$isBad ? 'warning' : (str_contains($lower, 'deliver') || str_contains($lower, 'succe') ? 'check-circle' : 'inbox')"
                :invert-delta="$isBad"
            />
        @endforeach
    </div>

    <x-ui.table :density="$density">
        <x-slot:toolbar>
            <div class="flex flex-1 flex-wrap items-center gap-2">
                <div class="relative min-w-0 flex-1 sm:max-w-xs">
                    <x-ui.icon name="search" size="sm"
                        class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground" />
                    <input
                        type="search"
                        wire:model.live.debounce.300ms="search"
                        placeholder="{{ $kind === 'payment' ? 'Reference or member' : 'Search' }}"
                        class="h-9 w-full rounded-md border border-input bg-card pl-9 pr-3 text-sm placeholder:text-muted-foreground"
                    >
                </div>

                <x-ui.select size="sm" placeholder="Any status" wire:model.live="status"
                    :options="$this->statusOptions()" class="w-40" />
            </div>
        </x-slot:toolbar>

        <thead class="[&_tr]:border-b [&_tr]:border-border">
            <tr>
                <x-ui.table.head sortable field="created_at" :direction="$this->sortDirectionFor('created_at')" width="170px">
                    When
                </x-ui.table.head>

                @if ($kind === 'email')
                    <x-ui.table.head>Recipient</x-ui.table.head>
                    <x-ui.table.head>Subject</x-ui.table.head>
                    <x-ui.table.head>Status</x-ui.table.head>
                    <x-ui.table.head>Reason</x-ui.table.head>
                @elseif ($kind === 'sms')
                    <x-ui.table.head>Number</x-ui.table.head>
                    <x-ui.table.head>Message</x-ui.table.head>
                    <x-ui.table.head>Gateway</x-ui.table.head>
                    <x-ui.table.head align="right">Segments</x-ui.table.head>
                    <x-ui.table.head>Status</x-ui.table.head>
                @elseif ($kind === 'payment')
                    <x-ui.table.head>Member</x-ui.table.head>
                    <x-ui.table.head>Product</x-ui.table.head>
                    <x-ui.table.head>Gateway</x-ui.table.head>
                    <x-ui.table.head align="right">Amount</x-ui.table.head>
                    <x-ui.table.head>Status</x-ui.table.head>
                    <x-ui.table.head>Reference</x-ui.table.head>
                @else
                    <x-ui.table.head>Member</x-ui.table.head>
                    <x-ui.table.head>IP</x-ui.table.head>
                    <x-ui.table.head>Result</x-ui.table.head>
                @endif
            </tr>
        </thead>

        <tbody wire:loading.class="opacity-50">
            @forelse ($rows as $row)
                <x-ui.table.row>
                    <x-ui.table.cell muted>{{ veyra_datetime($row->created_at) }}</x-ui.table.cell>

                    @if ($kind === 'email')
                        <x-ui.table.cell>
                            <span class="text-sm">{{ $row->to }}</span>
                        </x-ui.table.cell>

                        <x-ui.table.cell wrap>
                            <span class="text-sm">{{ $row->subject }}</span>
                            @if ($row->template_key)
                                <span class="block font-mono text-[11px] text-muted-foreground">{{ $row->template_key }}</span>
                            @endif
                        </x-ui.table.cell>

                        <x-ui.table.cell>
                            <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium {{ $row->statusClasses() }}">
                                {{ ucfirst($row->status) }}
                            </span>
                        </x-ui.table.cell>

                        <x-ui.table.cell muted>
                            <span class="text-xs">{{ $row->failure_reason ?? '—' }}</span>
                        </x-ui.table.cell>

                    @elseif ($kind === 'sms')
                        <x-ui.table.cell>
                            {{-- Masked: staff need the record, not the number. --}}
                            <span class="tabular text-sm">{{ $row->maskedNumber() }}</span>
                        </x-ui.table.cell>

                        <x-ui.table.cell wrap>
                            <span class="text-sm">{{ str($row->body)->limit(70) }}</span>
                        </x-ui.table.cell>

                        <x-ui.table.cell muted>{{ $row->gateway ?? '—' }}</x-ui.table.cell>
                        <x-ui.table.cell align="right" numeric muted>{{ $row->segments }}</x-ui.table.cell>

                        <x-ui.table.cell>
                            <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium {{ $row->statusClasses() }}">
                                {{ ucfirst($row->status) }}
                            </span>
                        </x-ui.table.cell>

                    @elseif ($kind === 'payment')
                        <x-ui.table.cell>
                            @if ($row->appUser)
                                <a href="{{ route('admin.users.show', $row->appUser) }}" wire:navigate
                                    class="text-sm hover:text-primary">{{ $row->appUser->display_name }}</a>
                            @else
                                <span class="text-sm text-muted-foreground">Deleted account</span>
                            @endif
                        </x-ui.table.cell>

                        <x-ui.table.cell muted>{{ $row->product ?? '—' }}</x-ui.table.cell>
                        <x-ui.table.cell muted>{{ $row->gateway }}</x-ui.table.cell>
                        <x-ui.table.cell align="right" numeric>{{ $row->formattedAmount() }}</x-ui.table.cell>

                        <x-ui.table.cell>
                            <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium {{ $row->statusClasses() }}">
                                {{ ucfirst($row->status) }}
                            </span>
                        </x-ui.table.cell>

                        <x-ui.table.cell muted>
                            <span class="font-mono text-xs">{{ $row->gateway_reference ?? '—' }}</span>
                        </x-ui.table.cell>

                    @else
                        <x-ui.table.cell>
                            @if ($row->appUser)
                                <a href="{{ route('admin.users.show', $row->appUser) }}" wire:navigate
                                    class="text-sm hover:text-primary">{{ $row->appUser->display_name }}</a>
                            @else
                                <span class="text-sm text-muted-foreground">—</span>
                            @endif
                        </x-ui.table.cell>

                        <x-ui.table.cell muted>
                            <span class="font-mono text-xs">{{ $row->ip_address ?? '—' }}</span>
                        </x-ui.table.cell>

                        <x-ui.table.cell>
                            <x-ui.badge :variant="$row->succeeded ? 'success' : 'destructive'" size="sm">
                                {{ $row->succeeded ? 'Signed in' : 'Failed' }}
                            </x-ui.badge>
                        </x-ui.table.cell>
                    @endif
                </x-ui.table.row>
            @empty
                <tr>
                    <td colspan="7"><x-ui.empty-state icon="inbox" heading="Nothing in this log yet" /></td>
                </tr>
            @endforelse
        </tbody>

        <x-slot:footer>
            <x-ui.pagination :paginator="$rows" :per-page="$perPage" :per-page-options="$this->perPageOptions()" />
        </x-slot:footer>
    </x-ui.table>
</div>
