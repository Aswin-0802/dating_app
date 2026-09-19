@php
    use App\Enums\CaseStatus;
    use App\Enums\ReportCategory;
    use App\Enums\Severity;
@endphp

<div class="space-y-4 md:space-y-6">

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4 md:gap-6">
        <x-ui.stat-card label="Open cases" :value="veyra_number($stats['open'])" icon="flag" />
        <x-ui.stat-card
            label="Breaching SLA"
            :value="veyra_number($stats['breaching'])"
            icon="warning"
            :hint="$stats['breaching'] > 0 ? 'Act on these first' : 'Nothing overdue'"
        />
        <x-ui.stat-card
            label="Unclaimed"
            :value="veyra_number($stats['unclaimed'])"
            icon="inbox"
            hint="Nobody has picked these up"
        />
        <x-ui.stat-card
            label="Critical severity"
            :value="veyra_number($stats['critical'])"
            icon="fire"
            hint="One-hour review window"
        />
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
                        placeholder="Case number or member"
                        class="h-9 w-full rounded-md border border-input bg-card pl-9 pr-3 text-sm placeholder:text-muted-foreground"
                    >
                </div>

                <x-ui.select size="sm" placeholder="Any severity" wire:model.live="severity"
                    :options="Severity::labels()" class="w-36" />

                <x-ui.select size="sm" placeholder="Any category" wire:model.live="category"
                    :options="ReportCategory::labels()" class="w-52" />

                <x-ui.select size="sm" placeholder="Any status" wire:model.live="status"
                    :options="CaseStatus::labels()" class="w-36" />
            </div>

            <div class="flex items-center gap-1">
                @foreach ([
                    'open' => 'Open',
                    'unclaimed' => 'Unclaimed',
                    'breaching' => 'Breaching',
                    'mine' => 'Mine',
                    'all' => 'All',
                ] as $key => $label)
                    <button
                        type="button"
                        wire:click="setView('{{ $key }}')"
                        @class([
                            'rounded-md px-2.5 py-1.5 text-xs font-medium transition-colors',
                            'bg-primary-subtle text-primary-subtle-foreground' => $view === $key,
                            'text-muted-foreground hover:bg-muted hover:text-foreground' => $view !== $key,
                        ])
                    >{{ $label }}</button>
                @endforeach
            </div>
        </x-slot:toolbar>

        <thead class="[&_tr]:border-b [&_tr]:border-border">
            <tr>
                <x-ui.table.head width="130px">Case</x-ui.table.head>
                <x-ui.table.head>Subject</x-ui.table.head>
                <x-ui.table.head>Severity</x-ui.table.head>
                <x-ui.table.head>Status</x-ui.table.head>
                <x-ui.table.head sortable field="reports_count" align="right" :direction="$this->sortDirectionFor('reports_count')">
                    Reports
                </x-ui.table.head>
                <x-ui.table.head align="right">Reporters</x-ui.table.head>
                <x-ui.table.head>Risk at open</x-ui.table.head>
                <x-ui.table.head sortable field="sla_due_at" :direction="$this->sortDirectionFor('sla_due_at')">
                    Due
                </x-ui.table.head>
                <x-ui.table.head>Claimed by</x-ui.table.head>
                <x-ui.table.head align="right" width="90px"><span class="sr-only">Open</span></x-ui.table.head>
            </tr>
        </thead>

        <tbody wire:loading.class="opacity-50">
            @forelse ($cases as $case)
                <x-ui.table.row :tint="$case->isBreachingSla() ? 'border-l-2 border-l-destructive bg-destructive-subtle/30' : ''">
                    <x-ui.table.cell>
                        <a href="{{ route('admin.cases.show', $case) }}" wire:navigate
                            class="font-mono text-xs font-medium text-primary hover:underline">
                            {{ $case->case_number }}
                        </a>
                    </x-ui.table.cell>

                    <x-ui.table.cell>
                        <x-veyra.user-cell
                            :name="$case->subject?->display_name"
                            :age="$case->subject?->age"
                            :photo="$case->subject?->primaryPhoto?->thumb_url"
                            :meta="$case->subject?->city?->name"
                            :href="$case->subject ? route('admin.users.show', $case->subject) : null"
                        />
                    </x-ui.table.cell>

                    <x-ui.table.cell><x-veyra.status-badge :status="$case->severity" /></x-ui.table.cell>
                    <x-ui.table.cell><x-veyra.status-badge :status="$case->status" /></x-ui.table.cell>

                    <x-ui.table.cell align="right" numeric>
                        <span @class(['font-medium', 'text-destructive-subtle-foreground' => $case->reports_count >= 3])>
                            {{ $case->reports_count }}
                        </span>
                    </x-ui.table.cell>

                    <x-ui.table.cell align="right" numeric muted>{{ $case->distinct_reporters_count }}</x-ui.table.cell>

                    <x-ui.table.cell>
                        <x-veyra.risk-badge :score="$case->risk_score_at_open" :show-score="true" />
                    </x-ui.table.cell>

                    <x-ui.table.cell>
                        @if ($case->status->isOpen())
                            <x-veyra.sla-pill :since="$case->created_at" :due-at="$case->sla_due_at" />
                        @else
                            <span class="text-xs text-muted-foreground">
                                {{ $case->resolved_at ? 'Resolved '.veyra_date($case->resolved_at) : '—' }}
                            </span>
                        @endif
                    </x-ui.table.cell>

                    <x-ui.table.cell muted>
                        @if ($case->claimedBy)
                            <span class="inline-flex items-center gap-1.5">
                                <x-ui.avatar :name="$case->claimedBy->name" size="xs" />
                                <span class="truncate">{{ $case->claimedBy->name }}</span>
                            </span>
                        @else
                            <span class="text-xs">Unclaimed</span>
                        @endif
                    </x-ui.table.cell>

                    <x-ui.table.cell align="right">
                        <x-ui.button size="xs" variant="outline" :href="route('admin.cases.show', $case)">
                            Open
                        </x-ui.button>
                    </x-ui.table.cell>
                </x-ui.table.row>
            @empty
                <tr>
                    <td colspan="10">
                        <x-ui.empty-state
                            icon="check-circle"
                            heading="No cases match this view"
                            description="Reports about the same member are grouped into a single case."
                        />
                    </td>
                </tr>
            @endforelse
        </tbody>

        <x-slot:footer>
            {{-- Prev/next rather than numbered pages: this queue reorders as
                 moderators work, so "page 3" is not the same page 3 a minute later. --}}
            <x-ui.pagination :paginator="$cases" :per-page="$perPage" :per-page-options="$this->perPageOptions()" simple />
        </x-slot:footer>
    </x-ui.table>
</div>
