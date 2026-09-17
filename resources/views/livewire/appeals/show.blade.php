@php
    $member = $appeal->appUser;
    $ban = $appeal->ban;
@endphp

<div class="space-y-4 md:space-y-6">

    {{-- The conflict-of-interest rule, stated where it applies. --}}
    @if ($isOriginalDecider)
        <div class="flex items-start gap-3 rounded-xl border border-warning/40 bg-warning-subtle px-4 py-3">
            <x-ui.icon name="lock" size="sm" class="mt-0.5 shrink-0 text-warning-subtle-foreground" />
            <div class="min-w-0 text-sm text-warning-subtle-foreground">
                <p class="font-medium">You made the original decision on this case</p>
                <p class="mt-0.5 opacity-90">
                    You can read this appeal but not decide it. An appeal reviewed by its own
                    decider is a rubber stamp that looks like a process.
                </p>
            </div>
        </div>
    @endif

    <div class="grid gap-4 md:gap-6 xl:grid-cols-[1fr_360px]">
        <div class="space-y-4 md:space-y-6">

            <x-ui.card title="What the member says">
                <x-slot:action>
                    <x-veyra.status-badge :status="$appeal->status" />
                </x-slot:action>

                <blockquote class="border-l-2 border-border pl-4 text-sm leading-relaxed">
                    {{ $appeal->user_statement ?? 'No statement provided.' }}
                </blockquote>

                <p class="mt-3 text-xs text-muted-foreground">
                    Filed {{ veyra_datetime($appeal->created_at) }}
                    @if ($appeal->sla_due_at)
                        · review due {{ veyra_datetime($appeal->sla_due_at) }}
                    @endif
                </p>
            </x-ui.card>

            {{-- The decision being appealed, in full. Reviewing an appeal without
                 the original reasoning in front of you is guesswork. --}}
            <x-ui.card title="The decision under appeal">
                @if ($ban)
                    <dl class="divide-y divide-border text-sm">
                        @foreach ([
                            'Action' => $ban->type->label(),
                            'Reason given' => $ban->reason_code?->label(),
                            'Policy clause' => $appeal->moderationAction?->policy_clause,
                            'Decided by' => $appeal->originalDecider?->name ?? 'Automated rule',
                            'Decided' => veyra_datetime($ban->starts_at),
                            'Expires' => $ban->expires_at ? veyra_datetime($ban->expires_at) : 'No expiry',
                            'Currently in force' => $ban->isActive() ? 'Yes' : 'No — already lifted or expired',
                        ] as $label => $value)
                            <div class="flex items-start justify-between gap-4 py-2 first:pt-0 last:pb-0">
                                <dt class="shrink-0 text-muted-foreground">{{ $label }}</dt>
                                <dd class="text-right font-medium">{{ $value ?: '—' }}</dd>
                            </div>
                        @endforeach
                    </dl>

                    @if ($ban->internal_note)
                        <div class="mt-4 rounded-lg bg-muted/50 p-3">
                            <p class="mb-1 text-xs font-medium text-muted-foreground">Moderator's note at the time</p>
                            <p class="text-sm">{{ $ban->internal_note }}</p>
                        </div>
                    @endif
                @else
                    <p class="text-sm text-muted-foreground">The original enforcement record is no longer available.</p>
                @endif
            </x-ui.card>

            {{-- ---- decision ------------------------------------------ --}}
            @if ($appeal->status->isOpen())
                <x-ui.card title="Your decision">
                    @error('decision')
                        <p class="mb-3 text-sm text-destructive">{{ $message }}</p>
                    @enderror

                    @if ($canDecide)
                        <div class="space-y-4">
                            <div class="space-y-2">
                                @foreach ([
                                    'upheld' => ['Uphold the original decision', 'The enforcement stands unchanged.'],
                                    'partially_overturned' => ['Partially overturn', 'Reduce the enforcement but do not remove it.'],
                                    'overturned' => ['Overturn', 'Lift the enforcement entirely and restore the account.'],
                                ] as $value => [$label, $help])
                                    <label @class([
                                        'flex cursor-pointer items-start gap-3 rounded-lg border p-3 transition-colors',
                                        'border-primary bg-primary-subtle' => $decision === $value,
                                        'border-border hover:bg-muted/50' => $decision !== $value,
                                    ])>
                                        <input type="radio" wire:model.live="decision" value="{{ $value }}"
                                            class="mt-0.5 accent-primary">
                                        <span class="min-w-0">
                                            <span class="block text-sm font-medium">{{ $label }}</span>
                                            <span class="block text-xs text-muted-foreground">{{ $help }}</span>
                                        </span>
                                    </label>
                                @endforeach
                            </div>

                            <x-ui.textarea
                                label="Reasoning"
                                required
                                rows="4"
                                wire:model="decisionNote"
                                placeholder="What did you review, and why does the original decision stand or not?"
                                hint="The member receives this. Write it as something you would be content to have quoted back."
                                :error="$errors->first('decisionNote')"
                            />

                            <x-ui.button wire:click="decide" :disabled="! $decision">
                                Record decision
                            </x-ui.button>
                        </div>
                    @elseif ($isOriginalDecider)
                        <p class="text-sm text-muted-foreground">
                            This appeal must be decided by somebody else.
                        </p>
                    @else
                        <p class="text-sm text-muted-foreground">
                            You do not have permission to decide appeals.
                        </p>
                    @endif
                </x-ui.card>
            @else
                <x-ui.card title="Decision">
                    <div class="space-y-2">
                        <x-veyra.status-badge :status="$appeal->status" />
                        <p class="text-sm">{{ $appeal->decision_note }}</p>
                        <p class="text-xs text-muted-foreground">
                            {{ $appeal->assignedTo?->name }} · {{ veyra_datetime($appeal->decided_at) }}
                        </p>
                    </div>
                </x-ui.card>
            @endif
        </div>

        {{-- ---- rail --------------------------------------------------- --}}
        <div class="space-y-4 md:space-y-6">
            <x-ui.card title="Member">
                <div class="space-y-3">
                    <x-veyra.user-cell
                        :name="$member?->display_name"
                        :age="$member?->age"
                        :photo="$member?->primaryPhoto?->thumb_url"
                        :meta="$member?->city?->name"
                        size="md"
                        :href="$member ? route('admin.users.show', $member) : null"
                    />

                    <div class="flex flex-wrap gap-1.5">
                        @if ($member)
                            <x-veyra.status-badge :status="$member->account_status" />
                            <x-veyra.risk-badge :score="$member->risk_score" :band="$member->risk_band"
                                :factors="$member->riskScore?->factors" />
                        @endif
                    </div>

                    <dl class="divide-y divide-border text-sm">
                        @foreach ([
                            'Member since' => veyra_date($member?->created_at),
                            'Prior appeals' => $member?->appeals()->count(),
                            'Prior enforcement' => $member?->bans()->count(),
                        ] as $label => $value)
                            <div class="flex items-center justify-between gap-3 py-2 first:pt-0 last:pb-0">
                                <dt class="text-muted-foreground">{{ $label }}</dt>
                                <dd class="font-medium">{{ $value }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </div>
            </x-ui.card>

            @can('assign_appeals')
                @if ($appeal->status->isOpen())
                    <x-ui.card title="Assign reviewer"
                        description="The original decider is not in this list.">
                        <div class="space-y-3">
                            <x-ui.select
                                placeholder="Choose a reviewer…"
                                wire:model="assignTo"
                                :selected="$assignTo"
                                :options="$reviewers->mapWithKeys(fn ($u) => [$u->id => $u->name.' · '.$u->role_name])->all()"
                                :error="$errors->first('assignTo')"
                            />
                            <x-ui.button size="sm" wire:click="assign">Assign</x-ui.button>
                        </div>
                    </x-ui.card>
                @endif
            @endcan
        </div>
    </div>
</div>
