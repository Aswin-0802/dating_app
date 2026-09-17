@php use App\Enums\AppealStatus; @endphp

<div class="space-y-4 md:space-y-6">

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4 md:gap-6">
        <x-ui.stat-card label="Open appeals" :value="veyra_number($stats['open'])" icon="scale" />
        <x-ui.stat-card label="Breaching SLA" :value="veyra_number($stats['breaching'])" icon="warning" />
        <x-ui.stat-card label="Decided" :value="veyra_number($stats['decided'])" icon="check-circle" />
        <x-ui.stat-card
            label="Overturn rate"
            :value="veyra_percent($stats['overturn_rate'])"
            icon="arrow-path"
            invert-delta
            hint="High means first-instance decisions are wrong"
        />
    </div>

    <div class="flex items-start gap-3 rounded-xl border border-border bg-muted/40 px-4 py-3">
        <x-ui.icon name="scale" size="sm" class="mt-0.5 shrink-0 text-muted-foreground" />
        <div class="min-w-0 text-sm">
            <p class="font-medium">An appeal is never decided by whoever made the original call</p>
            <p class="mt-0.5 text-muted-foreground">
                Enforced in the assignment action, the policy and the model. An appeal
                reviewed by its own decider is a rubber stamp that looks like a process.
            </p>
        </div>
    </div>

    <x-ui.table :density="$density">
        <x-slot:toolbar>
            <div class="flex flex-1 flex-wrap items-center gap-2">
                <div class="relative min-w-0 flex-1 sm:max-w-xs">
                    <x-ui.icon name="search" size="sm"
                        class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground" />
                    <input type="search" wire:model.live.debounce.300ms="search" placeholder="Search by member"
                        class="h-9 w-full rounded-md border border-input bg-card pl-9 pr-3 text-sm placeholder:text-muted-foreground">
                </div>

                <x-ui.select size="sm" placeholder="Any status" wire:model.live="status"
                    :options="AppealStatus::labels()" class="w-44" />
            </div>

            <div class="flex items-center gap-1">
                @foreach ([
                    'open' => 'Open', 'breaching' => 'Breaching', 'mine' => 'Assigned to me',
                    'conflicted' => 'My decisions', 'all' => 'All',
                ] as $key => $label)
                    <button type="button" wire:click="setView('{{ $key }}')" @class([
                        'rounded-md px-2.5 py-1.5 text-xs font-medium transition-colors',
                        'bg-primary-subtle text-primary-subtle-foreground' => $view === $key,
                        'text-muted-foreground hover:bg-muted hover:text-foreground' => $view !== $key,
                    ])>{{ $label }}</button>
                @endforeach
            </div>
        </x-slot:toolbar>

        <thead class="[&_tr]:border-b [&_tr]:border-border">
            <tr>
                <x-ui.table.head>Member</x-ui.table.head>
                <x-ui.table.head>Appealing</x-ui.table.head>
                <x-ui.table.head>Status</x-ui.table.head>
                <x-ui.table.head>Original decider</x-ui.table.head>
                <x-ui.table.head>Reviewer</x-ui.table.head>
                <x-ui.table.head sortable field="sla_due_at" :direction="$this->sortDirectionFor('sla_due_at')">Due</x-ui.table.head>
                <x-ui.table.head align="right" width="90px"><span class="sr-only">Open</span></x-ui.table.head>
            </tr>
        </thead>

        <tbody wire:loading.class="opacity-50">
            @forelse ($appeals as $appeal)
                @php $isMine = $appeal->original_decider_id === auth()->id(); @endphp

                <x-ui.table.row :tint="$appeal->status->isOpen() && $appeal->sla_due_at?->isPast() ? 'border-l-2 border-l-destructive' : ''">
                    <x-ui.table.cell>
                        <x-veyra.user-cell
                            :name="$appeal->appUser?->display_name"
                            :photo="$appeal->appUser?->primaryPhoto?->thumb_url"
                            :href="$appeal->appUser ? route('admin.users.show', $appeal->appUser) : null"
                        />
                    </x-ui.table.cell>

                    <x-ui.table.cell>
                        @if ($appeal->ban)
                            <x-veyra.status-badge :status="$appeal->ban->type" />
                        @else
                            <span class="text-sm text-muted-foreground">—</span>
                        @endif
                    </x-ui.table.cell>

                    <x-ui.table.cell><x-veyra.status-badge :status="$appeal->status" /></x-ui.table.cell>

                    <x-ui.table.cell muted>
                        {{ $appeal->originalDecider?->name ?? 'Automated rule' }}
                        @if ($isMine)
                            <x-ui.badge variant="warning" size="sm" class="ml-1">You</x-ui.badge>
                        @endif
                    </x-ui.table.cell>

                    <x-ui.table.cell muted>{{ $appeal->assignedTo?->name ?? 'Unassigned' }}</x-ui.table.cell>

                    <x-ui.table.cell>
                        @if ($appeal->status->isOpen())
                            <x-veyra.sla-pill :since="$appeal->created_at" :due-at="$appeal->sla_due_at" />
                        @else
                            <span class="text-xs text-muted-foreground">
                                {{ $appeal->decided_at ? veyra_date($appeal->decided_at) : '—' }}
                            </span>
                        @endif
                    </x-ui.table.cell>

                    <x-ui.table.cell align="right">
                        <x-ui.button size="xs" variant="outline" :href="route('admin.appeals.show', $appeal)">
                            Open
                        </x-ui.button>
                    </x-ui.table.cell>
                </x-ui.table.row>
            @empty
                <tr>
                    <td colspan="7">
                        <x-ui.empty-state icon="scale" heading="No appeals match this view" />
                    </td>
                </tr>
            @endforelse
        </tbody>

        <x-slot:footer>
            <x-ui.pagination :paginator="$appeals" :per-page="$perPage" :per-page-options="$this->perPageOptions()" simple />
        </x-slot:footer>
    </x-ui.table>
</div>
