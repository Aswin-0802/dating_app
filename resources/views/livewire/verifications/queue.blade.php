@php
    use App\Enums\VerificationStatus;
@endphp

<div class="space-y-4 md:space-y-6">

    {{-- Queue health. Depth alone is misleading, so the oldest item and the
         breach count sit next to it. --}}
    <div class="grid gap-4 sm:grid-cols-3 md:gap-6">
        <x-ui.stat-card
            label="Open in this queue"
            :value="veyra_number($verifications->total())"
            icon="shield-check"
        />

        <x-ui.stat-card
            label="Oldest waiting"
            :value="$oldest ? veyra_duration($oldest->submitted_at) : '—'"
            icon="clock"
            :hint="$oldest ? 'Submitted '.veyra_datetime($oldest->submitted_at) : 'Queue is clear'"
        />

        <x-ui.stat-card
            label="Breaching SLA"
            :value="veyra_number($breaching)"
            icon="warning"
            :hint="$breaching > 0 ? 'Review these first' : 'Nothing overdue'"
        />
    </div>

    @if ($breaching > 0)
        <div class="flex flex-wrap items-center gap-3 rounded-xl border border-destructive/30 bg-destructive-subtle px-4 py-3">
            <x-ui.icon name="warning" size="sm" class="shrink-0 text-destructive-subtle-foreground" />
            <p class="min-w-0 flex-1 text-sm text-destructive-subtle-foreground">
                <span class="font-medium">{{ veyra_number($breaching) }}</span>
                {{ str('submission')->plural($breaching) }} past the review deadline.
            </p>
            <x-ui.button size="xs" variant="outline" wire:click="setView('breaching')">
                Show only these
            </x-ui.button>
        </div>
    @endif

    @if ($isRestricted)
        <div class="flex items-start gap-3 rounded-xl border border-destructive/40 bg-destructive-subtle px-4 py-3">
            <x-ui.icon name="lock" size="sm" class="mt-0.5 shrink-0 text-destructive-subtle-foreground" />
            <div class="min-w-0 text-sm text-destructive-subtle-foreground">
                <p class="font-medium">Restricted queue — minor safety</p>
                <p class="mt-0.5 opacity-90">
                    Submissions here cannot be approved. Every item must be escalated or
                    rejected, and every view is recorded in the audit log.
                </p>
            </div>
        </div>
    @endif

    <x-ui.table :density="$density">
        <x-slot:toolbar>
            <div class="flex flex-1 flex-wrap items-center gap-2">
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

                <x-ui.select
                    size="sm"
                    placeholder="Any signal"
                    wire:model.live="signal"
                    :options="[
                        'duplicate_face' => 'Duplicate face',
                        'liveness_failed' => 'Liveness failed',
                        'low_match' => 'Low face match',
                        'device_reuse' => 'Device reuse',
                    ]"
                    class="w-44"
                />
            </div>

            <div class="flex items-center gap-1">
                @foreach ([
                    'open' => 'Open',
                    'breaching' => 'Breaching',
                    'mine' => 'Claimed by me',
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
                <x-ui.table.head>Member</x-ui.table.head>
                <x-ui.table.head>Status</x-ui.table.head>
                <x-ui.table.head sortable field="face_match_score" :direction="$this->sortDirectionFor('face_match_score')">
                    Face match
                </x-ui.table.head>
                <x-ui.table.head>Signals</x-ui.table.head>
                <x-ui.table.head sortable field="submitted_at" :direction="$this->sortDirectionFor('submitted_at')">
                    Waiting
                </x-ui.table.head>
                <x-ui.table.head>Claimed by</x-ui.table.head>
                <x-ui.table.head align="right" width="110px"><span class="sr-only">Review</span></x-ui.table.head>
            </tr>
        </thead>

        <tbody wire:loading.class="opacity-50">
            @forelse ($verifications as $verification)
                @php
                    $breachingRow = $verification->isBreachingSla();
                    $duplicates = $verification->duplicate_face_account_count;
                @endphp

                <x-ui.table.row :tint="$breachingRow ? 'border-l-2 border-l-destructive' : ''">
                    <x-ui.table.cell>
                        <x-veyra.user-cell
                            :name="$verification->appUser?->display_name"
                            :age="$verification->appUser?->age"
                            :photo="$verification->appUser?->primaryPhoto?->thumb_url"
                            :meta="$verification->appUser?->city?->name"
                            :href="route('admin.users.show', $verification->appUser)"
                        />
                    </x-ui.table.cell>

                    <x-ui.table.cell>
                        <x-veyra.status-badge :status="$verification->status" />
                    </x-ui.table.cell>

                    <x-ui.table.cell numeric>
                        @php $score = (float) $verification->face_match_score; @endphp
                        <span @class([
                            'font-medium',
                            'text-success-subtle-foreground' => $score >= 0.8,
                            'text-warning-subtle-foreground' => $score >= 0.55 && $score < 0.8,
                            'text-destructive-subtle-foreground' => $score < 0.55,
                        ])>{{ veyra_percent($score * 100, 0) }}</span>
                    </x-ui.table.cell>

                    <x-ui.table.cell>
                        <div class="flex flex-wrap items-center gap-1">
                            {{-- Duplicate face first: it is the single most
                                 decisive signal on this screen. --}}
                            @if ($duplicates > 0)
                                <x-ui.badge :variant="$duplicates >= 2 ? 'solid-destructive' : 'destructive'" icon="link">
                                    {{ $duplicates }} other {{ str('account')->plural($duplicates) }}
                                </x-ui.badge>
                            @endif

                            @unless ($verification->liveness_passed)
                                <x-ui.badge variant="destructive" icon="x-circle">Liveness</x-ui.badge>
                            @endunless

                            @if ($verification->minor_suspected)
                                <x-ui.badge variant="solid-destructive" icon="warning">Age conflict</x-ui.badge>
                            @endif

                            @if ($verification->device_reuse_count > 0)
                                <x-ui.badge variant="warning" icon="device">
                                    {{ $verification->device_reuse_count }}
                                </x-ui.badge>
                            @endif

                            @if ($duplicates === 0 && $verification->liveness_passed && ! $verification->minor_suspected && $verification->device_reuse_count === 0)
                                <span class="text-xs text-muted-foreground">Clean</span>
                            @endif
                        </div>
                    </x-ui.table.cell>

                    <x-ui.table.cell>
                        <x-veyra.sla-pill :since="$verification->submitted_at" :due-at="$verification->sla_due_at" />
                    </x-ui.table.cell>

                    <x-ui.table.cell muted>
                        @if ($verification->claimedBy)
                            <span class="inline-flex items-center gap-1.5">
                                <x-ui.avatar :name="$verification->claimedBy->name" size="xs" />
                                {{ $verification->claimedBy->name }}
                            </span>
                        @else
                            —
                        @endif
                    </x-ui.table.cell>

                    <x-ui.table.cell align="right">
                        <x-ui.button size="xs" variant="outline" :href="route('admin.verifications.review', $verification)">
                            Review
                        </x-ui.button>
                    </x-ui.table.cell>
                </x-ui.table.row>
            @empty
                <tr>
                    <td colspan="7">
                        <x-ui.empty-state
                            icon="check-circle"
                            heading="Queue is clear"
                            description="Nothing is waiting for review. New submissions appear here automatically."
                        />
                    </td>
                </tr>
            @endforelse
        </tbody>

        <x-slot:footer>
            <x-ui.pagination :paginator="$verifications" :per-page="$perPage" :per-page-options="$this->perPageOptions()" simple />
        </x-slot:footer>
    </x-ui.table>
</div>
