<div class="space-y-4 md:space-y-6">

    <div class="flex items-start gap-3 rounded-xl border border-border bg-muted/40 px-4 py-3">
        <x-ui.icon name="eye-off" size="sm" class="mt-0.5 shrink-0 text-muted-foreground" />
        <div class="min-w-0 text-sm">
            <p class="font-medium">Why this queue exists</p>
            <p class="mt-0.5 text-muted-foreground">
                A shadow ban is invisible to the member, so nobody will ever complain about
                one. Without a scheduled review it becomes a permanent, silent punishment
                that no one revisits. Every shadow ban therefore carries a review date, and
                lands here when that date passes.
            </p>
        </div>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 md:gap-6">
        <x-ui.stat-card
            label="Overdue for review"
            :value="veyra_number($dueCount)"
            icon="warning"
            :hint="$dueCount > 0 ? 'Each needs a decision today' : 'Nothing overdue'"
        />
        <x-ui.stat-card
            label="Shadow bans in force"
            :value="veyra_number($activeCount)"
            icon="eye-off"
        />
    </div>

    <x-ui.table :density="$density">
        <x-slot:toolbar>
            <div class="relative min-w-0 flex-1 sm:max-w-xs">
                <x-ui.icon name="search" size="sm"
                    class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground" />
                <input
                    type="search"
                    wire:model.live.debounce.300ms="search"
                    placeholder="Search by member"
                    class="h-9 w-full rounded-md border border-input bg-card pl-9 pr-3 text-sm placeholder:text-muted-foreground"
                >
            </div>

            <div class="flex items-center gap-1">
                @foreach (['due' => 'Overdue', 'active' => 'In force', 'all' => 'All'] as $key => $label)
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
                <x-ui.table.head>Member</x-ui.table.head>
                <x-ui.table.head>Reason</x-ui.table.head>
                <x-ui.table.head>Issued by</x-ui.table.head>
                <x-ui.table.head sortable field="starts_at" :direction="$this->sortDirectionFor('starts_at')">
                    In force since
                </x-ui.table.head>
                <x-ui.table.head sortable field="review_due_at" :direction="$this->sortDirectionFor('review_due_at')">
                    Review due
                </x-ui.table.head>
                <x-ui.table.head align="right" width="170px"><span class="sr-only">Decision</span></x-ui.table.head>
            </tr>
        </thead>

        <tbody wire:loading.class="opacity-50">
            @forelse ($bans as $ban)
                @php $overdue = $ban->isReviewOverdue(); @endphp

                <x-ui.table.row :tint="$overdue ? 'border-l-2 border-l-destructive bg-destructive-subtle/30' : ''">
                    <x-ui.table.cell>
                        <x-veyra.user-cell
                            :name="$ban->appUser?->display_name"
                            :photo="$ban->appUser?->primaryPhoto?->thumb_url"
                            :meta="$ban->appUser?->email"
                            :href="$ban->appUser ? route('admin.users.show', $ban->appUser) : null"
                        />
                    </x-ui.table.cell>

                    <x-ui.table.cell>
                        <span class="text-sm">{{ $ban->reason_code?->label() ?? '—' }}</span>
                    </x-ui.table.cell>

                    <x-ui.table.cell muted>{{ $ban->issuedBy?->name ?? 'Automated rule' }}</x-ui.table.cell>

                    <x-ui.table.cell muted>
                        {{ veyra_duration($ban->starts_at) }}
                    </x-ui.table.cell>

                    <x-ui.table.cell>
                        @if ($overdue)
                            <x-ui.badge variant="solid-destructive" icon="warning">
                                Overdue by {{ veyra_duration($ban->review_due_at) }}
                            </x-ui.badge>
                        @else
                            <span class="text-sm text-muted-foreground">
                                {{ veyra_date($ban->review_due_at) }}
                            </span>
                        @endif
                    </x-ui.table.cell>

                    <x-ui.table.cell align="right">
                        <div class="flex items-center justify-end gap-1">
                            @can('lift_enforcement')
                                <x-ui.button
                                    size="xs"
                                    variant="success"
                                    wire:click="lift({{ $ban->id }})"
                                    wire:confirm="Lift this shadow ban? The member returns to normal discovery."
                                >Lift</x-ui.button>
                            @endcan

                            @can('extend_enforcement')
                                <x-ui.button size="xs" variant="outline" wire:click="startExtend({{ $ban->id }})">
                                    Extend
                                </x-ui.button>
                            @endcan
                        </div>
                    </x-ui.table.cell>
                </x-ui.table.row>

                @if ($extendingBanId === $ban->id)
                    <tr class="border-b border-border bg-muted/40">
                        <td colspan="6" class="px-3 py-4">
                            <div class="max-w-xl space-y-3">
                                <p class="text-sm font-medium">
                                    Extend the shadow ban on {{ $ban->appUser?->display_name }}
                                </p>

                                <div class="space-y-1.5">
                                    <label class="block text-sm font-medium">
                                        New review date <span class="text-destructive">*</span>
                                    </label>
                                    <input
                                        type="datetime-local"
                                        wire:model="newReviewDate"
                                        class="h-9 w-full rounded-md border border-input bg-card px-3 text-sm"
                                    >
                                    @error('newReviewDate')
                                        <p class="text-xs text-destructive">{{ $message }}</p>
                                    @enderror
                                </div>

                                <x-ui.textarea
                                    label="Why should this continue?"
                                    required
                                    rows="2"
                                    wire:model="extendNote"
                                    :error="$errors->first('extendNote')"
                                    hint="Appended to the ban's internal note and recorded in the audit log."
                                />

                                <div class="flex items-center gap-2">
                                    <x-ui.button size="sm" wire:click="extend">Extend review</x-ui.button>
                                    <x-ui.button size="sm" variant="ghost" wire:click="cancelExtend">Cancel</x-ui.button>
                                </div>
                            </div>
                        </td>
                    </tr>
                @endif
            @empty
                <tr>
                    <td colspan="6">
                        <x-ui.empty-state
                            icon="check-circle"
                            heading="No shadow bans need review"
                            description="Every shadow ban in force has a future review date."
                        />
                    </td>
                </tr>
            @endforelse
        </tbody>

        <x-slot:footer>
            <x-ui.pagination :paginator="$bans" :per-page="$perPage" :per-page-options="$this->perPageOptions()" simple />
        </x-slot:footer>
    </x-ui.table>
</div>
