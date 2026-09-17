<div class="space-y-4 md:space-y-6">

    <div class="flex items-start gap-3 rounded-xl border border-border bg-muted/40 px-4 py-3">
        <x-ui.icon name="info" size="sm" class="mt-0.5 shrink-0 text-muted-foreground" />
        <div class="min-w-0 text-sm">
            <p class="font-medium">Read the overturn rate first</p>
            <p class="mt-0.5 text-muted-foreground">
                Volume is the least interesting column here. Somebody deciding fifty cases a
                day with a third reversed on appeal is producing work, not outcomes — and
                ranking on volume alone rewards exactly that.
            </p>
        </div>
    </div>

    <x-ui.table>
        <x-slot:toolbar>
            <p class="text-sm text-muted-foreground">Last {{ $days }} days</p>

            <x-ui.select size="sm" wire:model.live="days" :selected="$days"
                :options="['7' => 'Last 7 days', '30' => 'Last 30 days', '90' => 'Last 90 days']" class="w-40" />
        </x-slot:toolbar>

        <thead class="[&_tr]:border-b [&_tr]:border-border">
            <tr>
                <x-ui.table.head>Moderator</x-ui.table.head>
                <x-ui.table.head align="right">Decisions</x-ui.table.head>
                <x-ui.table.head align="right">Verifications</x-ui.table.head>
                <x-ui.table.head align="right">Median review time</x-ui.table.head>
                <x-ui.table.head align="right">Appeals decided</x-ui.table.head>
                <x-ui.table.head align="right">Overturn rate</x-ui.table.head>
                <x-ui.table.head align="right">Message reveals</x-ui.table.head>
            </tr>
        </thead>

        <tbody>
            @forelse ($rows as $row)
                <x-ui.table.row :tint="$row->overturn_rate !== null && $row->overturn_rate >= 25 ? 'border-l-2 border-l-warning' : ''">
                    <x-ui.table.cell>
                        <div class="flex min-w-0 items-center gap-2.5">
                            <x-ui.avatar :name="$row->name" size="sm" />
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium">{{ $row->name }}</p>
                                <p class="truncate text-xs text-muted-foreground">{{ $row->email }}</p>
                            </div>
                        </div>
                    </x-ui.table.cell>

                    <x-ui.table.cell align="right" numeric>{{ $row->decisions }}</x-ui.table.cell>
                    <x-ui.table.cell align="right" numeric muted>{{ $row->verifications }}</x-ui.table.cell>

                    <x-ui.table.cell align="right" numeric muted>
                        @if ($row->median_minutes === null)
                            —
                        @elseif ($row->median_minutes >= 60)
                            {{ round($row->median_minutes / 60, 1) }}h
                        @else
                            {{ $row->median_minutes }}m
                        @endif
                    </x-ui.table.cell>

                    <x-ui.table.cell align="right" numeric muted>{{ $row->appeals_decided ?: '—' }}</x-ui.table.cell>

                    <x-ui.table.cell align="right">
                        @if ($row->overturn_rate === null)
                            <span class="text-muted-foreground">—</span>
                        @else
                            {{-- Above a quarter reversed, the problem is the
                                 first-instance decisions, not the appeals. --}}
                            <x-ui.badge :variant="match (true) {
                                $row->overturn_rate >= 25 => 'destructive',
                                $row->overturn_rate >= 15 => 'warning',
                                default => 'success',
                            }">{{ veyra_percent($row->overturn_rate) }}</x-ui.badge>
                        @endif
                    </x-ui.table.cell>

                    <x-ui.table.cell align="right" numeric>
                        @if ($row->reveals > 0)
                            <a href="{{ route('admin.audit.message-access') }}" wire:navigate
                                class="font-medium text-primary hover:underline">{{ $row->reveals }}</a>
                        @else
                            <span class="text-muted-foreground">—</span>
                        @endif
                    </x-ui.table.cell>
                </x-ui.table.row>
            @empty
                <tr>
                    <td colspan="7">
                        <x-ui.empty-state
                            icon="chart-bar"
                            heading="No activity in this period"
                            description="Widen the date range to see earlier work."
                        />
                    </td>
                </tr>
            @endforelse
        </tbody>
    </x-ui.table>
</div>
