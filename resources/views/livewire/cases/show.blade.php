@php
    use App\Enums\LadderStep;

    $subject = $reportCase->subject;
    $step = $pendingStep ? LadderStep::tryFrom($pendingStep) : null;
    $canSeeContent = auth()->user()->can('view_message_content');
@endphp

<div class="space-y-4 md:space-y-6">

    {{-- Someone else holding this case has to be obvious before you act. --}}
    @if ($reportCase->isClaimedByAnotherUser(auth()->id()))
        <div class="flex flex-wrap items-center gap-3 rounded-xl border border-warning/40 bg-warning-subtle px-4 py-3">
            <x-ui.avatar :name="$reportCase->claimedBy?->name" size="sm" class="shrink-0" />
            <p class="min-w-0 flex-1 text-sm text-warning-subtle-foreground">
                <span class="font-medium">{{ $reportCase->claimedBy?->name }}</span>
                is reviewing this case, claimed {{ veyra_duration($reportCase->claimed_at) }} ago.
            </p>
        </div>
    @endif

    <div class="grid gap-4 md:gap-6 xl:grid-cols-[1fr_360px]">
        <div class="space-y-4 md:space-y-6">

            {{-- ---- aggregated reports -------------------------------- --}}
            <x-ui.card
                :title="$reportCase->reports_count.' '.str('report')->plural($reportCase->reports_count).' against this member'"
                :description="$reportCase->distinct_reporters_count.' distinct '.str('reporter')->plural($reportCase->distinct_reporters_count).'. Acting once resolves all of them.'"
            >
                <x-slot:action>
                    <x-veyra.status-badge :status="$reportCase->severity" />
                </x-slot:action>

                <div class="divide-y divide-border">
                    @foreach ($reportCase->reports as $report)
                        <div class="flex flex-wrap items-start gap-3 py-3 first:pt-0 last:pb-0">
                            <div class="min-w-0 flex-1 space-y-1">
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <x-veyra.status-badge :status="$report->category" size="sm" />

                                    @if ($report->source !== 'user')
                                        <x-ui.badge variant="outline" size="sm">
                                            {{ ucfirst($report->source) }}
                                        </x-ui.badge>
                                    @endif

                                    <span class="text-xs text-muted-foreground">
                                        {{ veyra_duration($report->created_at) }} ago
                                    </span>
                                </div>

                                @if ($report->description)
                                    <p class="text-sm text-foreground">{{ $report->description }}</p>
                                @endif

                                <p class="text-xs text-muted-foreground">
                                    Reported by
                                    @can('view_user_pii')
                                        {{ $report->reporter?->display_name ?? 'a deleted account' }}
                                    @else
                                        a member
                                    @endcan
                                    ·
                                    {{-- Reporter credibility is snapshotted at
                                         report time, so a serial false-reporter's
                                         later reputation cannot rewrite history. --}}
                                    <span @class([
                                        'font-medium',
                                        'text-success-subtle-foreground' => $report->reporter_credibility_at_time >= 60,
                                        'text-warning-subtle-foreground' => $report->reporter_credibility_at_time < 40,
                                    ])>{{ $report->reporter_credibility_at_time }}% credibility</span>
                                </p>
                            </div>
                        </div>
                    @endforeach
                </div>
            </x-ui.card>

            {{-- ---- evidence ------------------------------------------ --}}
            @if ($evidence['anchor'])
                <x-ui.card
                    title="Reported message in context"
                    :description="'The anchored message with '.veyra_setting('privacy.message_context_window', 10).' messages either side.'"
                >
                    <x-slot:action>
                        @unless ($canSeeContent)
                            <x-ui.badge variant="muted" icon="lock">Content hidden</x-ui.badge>
                        @endunless
                    </x-slot:action>

                    <div class="max-h-96 space-y-2 overflow-y-auto pr-1">
                        @foreach ($evidence['context'] as $message)
                            @php
                                $isAnchor = $message->id === $evidence['anchor']->id;
                                $fromSubject = $message->sender_app_user_id === $subject?->id;
                            @endphp

                            <div @class([
                                'flex gap-2',
                                'justify-end' => ! $fromSubject,
                            ])>
                                <div @class([
                                    'max-w-[75%] rounded-lg px-3 py-2 text-sm',
                                    'bg-muted' => $fromSubject && ! $isAnchor,
                                    'bg-primary-subtle' => ! $fromSubject && ! $isAnchor,
                                    'bg-destructive-subtle ring-2 ring-destructive/50' => $isAnchor,
                                ])>
                                    <p class="mb-0.5 text-[11px] font-medium text-muted-foreground">
                                        {{ $message->sender?->display_name ?? 'Unknown' }}
                                        @if ($isAnchor)
                                            · reported
                                        @endif
                                    </p>

                                    {{-- Content only when the viewer holds the
                                         permission. Otherwise the shape of the
                                         message, which is usually enough to triage. --}}
                                    <p @class(['text-foreground', 'text-muted-foreground italic' => ! $canSeeContent])>
                                        {{ $canSeeContent
                                            ? ($message->getRawOriginal('body') ?? $message->redactedPreview())
                                            : $message->redactedPreview() }}
                                    </p>

                                    <div class="mt-1 flex flex-wrap items-center gap-1">
                                        @if ($message->contains_contact_info)
                                            <x-ui.badge variant="warning" size="sm">Contact details</x-ui.badge>
                                        @endif
                                        @if ($message->contains_link)
                                            <x-ui.badge variant="warning" size="sm">Link</x-ui.badge>
                                        @endif
                                        <span class="text-[10px] text-muted-foreground">
                                            {{ veyra_datetime($message->created_at) }}
                                        </span>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </x-ui.card>
            @endif

            {{-- ---- decision bar -------------------------------------- --}}
            <x-ui.card title="Decision" description="Every action records a reason code, the policy clause, and whether a human or a rule decided.">
                @if ($reportCase->status->isOpen())
                    <div class="flex flex-wrap items-center gap-2">
                        @foreach ($ladder as $rung)
                            @can($rung === LadderStep::Warn ? 'warn_users' : ($rung === LadderStep::FeatureLimit ? 'limit_users' : ($rung === LadderStep::ShadowBan ? 'shadow_ban_users' : ($rung === LadderStep::Suspend ? 'suspend_users' : ($rung === LadderStep::PermanentBan ? 'ban_users' : 'cases')))))
                                <button
                                    type="button"
                                    wire:click="openStep('{{ $rung->value }}')"
                                    @class([
                                        'inline-flex h-9 items-center gap-2 rounded-md px-3 text-sm font-medium transition-colors',
                                        $rung->buttonClasses(),
                                        'ring-2 ring-ring ring-offset-2 ring-offset-background' => $pendingStep === $rung->value,
                                    ])
                                >
                                    {{ $rung->label() }}
                                    @if ($rung->shortcut())
                                        <span class="rounded-xs bg-black/15 px-1 text-[11px]">{{ $rung->shortcut() }}</span>
                                    @endif
                                </button>
                            @endcan
                        @endforeach

                        <div class="flex-1"></div>

                        @can('close_cases')
                            <x-ui.button variant="ghost" size="sm" wire:click="close"
                                wire:confirm="Close this case with no action?">
                                Close — no action
                            </x-ui.button>
                        @endcan
                    </div>

                    {{-- Confirmation collects everything the audit log and the
                         statement of reasons need. Nothing is optional by accident. --}}
                    @if ($step)
                        <div class="mt-4 space-y-4 rounded-lg border border-border bg-muted/30 p-4">
                            <p class="text-sm font-medium">
                                {{ $step->label() }} — {{ $subject?->display_name }}
                            </p>

                            <x-ui.select
                                label="Reason code"
                                required
                                placeholder="Choose a reason…"
                                wire:model.live="reasonCode"
                                :grouped="collect($reasons)->map(fn ($codes) => collect($codes)->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all())->all()"
                                :error="$errors->first('reasonCode')"
                            />

                            @if ($step->requiresDuration())
                                <x-ui.select
                                    label="Duration"
                                    required
                                    wire:model="durationHours"
                                    :selected="$durationHours"
                                    :options="['24' => '24 hours', '168' => '7 days', '720' => '30 days']"
                                    :error="$errors->first('durationHours')"
                                />
                            @endif

                            @if ($step->requiresReviewDate())
                                <div class="space-y-1.5">
                                    <label class="block text-sm font-medium">
                                        Review due <span class="text-destructive">*</span>
                                    </label>
                                    <input
                                        type="datetime-local"
                                        wire:model="reviewDueAt"
                                        class="h-9 w-full rounded-md border border-input bg-card px-3 text-sm"
                                    >
                                    <p class="text-xs text-muted-foreground">
                                        A shadow ban is invisible to the member, so it cannot be
                                        open-ended. Somebody must revisit it on this date.
                                    </p>
                                    @error('reviewDueAt')
                                        <p class="text-xs text-destructive">{{ $message }}</p>
                                    @enderror
                                </div>
                            @endif

                            @if ($step === LadderStep::FeatureLimit)
                                <div class="space-y-2">
                                    <p class="text-sm font-medium">Features to limit</p>
                                    @foreach (config('veyra.enforcement.feature_limit_options') as $key => $label)
                                        <x-ui.checkbox
                                            :label="$label"
                                            wire:model="limitedFeatures"
                                            value="{{ $key }}"
                                        />
                                    @endforeach
                                </div>
                            @endif

                            <x-ui.textarea
                                label="Internal note"
                                rows="3"
                                wire:model="note"
                                placeholder="What did you see, and why does it meet the policy?"
                                hint="Staff only. Recorded in the audit log and shown on any appeal."
                                :error="$errors->first('note')"
                                :required="$step->isDestructive()"
                            />

                            <x-ui.toggle
                                size="lg"
                                label="Notify the member"
                                description="Sends the statement of reasons for this policy clause."
                                wire:model="notifyUser"
                                :checked="$notifyUser"
                            />

                            <div class="flex items-center gap-2">
                                <button
                                    type="button"
                                    wire:click="confirmStep"
                                    class="inline-flex h-9 items-center gap-2 rounded-md px-4 text-sm font-medium transition-colors {{ $step->buttonClasses() }}"
                                >
                                    Confirm {{ strtolower($step->label()) }}
                                </button>
                                <x-ui.button variant="ghost" size="sm" wire:click="cancelStep">Cancel</x-ui.button>
                            </div>
                        </div>
                    @endif
                @else
                    <div class="flex flex-wrap items-center gap-3">
                        <x-veyra.status-badge :status="$reportCase->status" />
                        <p class="text-sm text-muted-foreground">
                            {{ $reportCase->outcome ?? 'Resolved' }}
                            @if ($reportCase->resolvedBy)
                                by {{ $reportCase->resolvedBy->name }}
                            @endif
                            {{ $reportCase->resolved_at ? '· '.veyra_datetime($reportCase->resolved_at) : '' }}
                        </p>
                    </div>
                @endif
            </x-ui.card>

            {{-- ---- action history ------------------------------------ --}}
            @if ($reportCase->actions->isNotEmpty())
                <x-ui.card title="Actions on this case">
                    <ol class="space-y-3">
                        @foreach ($reportCase->actions as $action)
                            <li class="flex gap-3">
                                <span class="mt-1 size-2 shrink-0 rounded-full bg-border"></span>
                                <div class="min-w-0 flex-1 space-y-0.5">
                                    <div class="flex flex-wrap items-center gap-1.5">
                                        <x-veyra.status-badge :status="$action->ladder_step" size="sm" />
                                        <span class="text-sm">{{ $action->reason_code->label() }}</span>
                                    </div>
                                    <p class="text-xs text-muted-foreground">
                                        {{ $action->actorLabel() }} · {{ veyra_datetime($action->created_at) }}
                                        @if ($action->policy_clause)
                                            · clause {{ $action->policy_clause }}
                                        @endif
                                    </p>
                                    @if ($action->internal_note)
                                        <p class="text-sm text-muted-foreground">{{ $action->internal_note }}</p>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ol>
                </x-ui.card>
            @endif
        </div>

        {{-- ---- context rail ------------------------------------------ --}}
        <div class="space-y-4 md:space-y-6">
            <x-ui.card title="Subject">
                <div class="space-y-3">
                    <x-veyra.user-cell
                        :name="$subject?->display_name"
                        :age="$subject?->age"
                        :photo="$subject?->primaryPhoto?->thumb_url"
                        :meta="$subject?->city?->name"
                        size="md"
                        :href="$subject ? route('admin.users.show', $subject) : null"
                    />

                    <div class="flex flex-wrap gap-1.5">
                        @if ($subject)
                            <x-veyra.status-badge :status="$subject->account_status" />
                            <x-veyra.status-badge :status="$subject->verification_status" />
                            <x-veyra.risk-badge
                                :score="$subject->risk_score"
                                :band="$subject->risk_band"
                                :factors="$subject->riskScore?->factors"
                            />
                        @endif
                    </div>

                    <dl class="divide-y divide-border text-sm">
                        @foreach ([
                            'Member since' => veyra_date($subject?->created_at),
                            'Last active' => $subject?->last_active_at ? veyra_duration($subject->last_active_at).' ago' : '—',
                            'Reports (all time)' => $subject?->reportsAgainst()->count(),
                            'Prior actions' => $priorActions->count(),
                            'Case opened' => veyra_datetime($reportCase->created_at),
                        ] as $label => $value)
                            <div class="flex items-center justify-between gap-3 py-2 first:pt-0 last:pb-0">
                                <dt class="text-muted-foreground">{{ $label }}</dt>
                                <dd class="font-medium">{{ $value }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </div>
            </x-ui.card>

            {{-- Repeat offending is the single most useful thing to know before
                 choosing a rung, so it is a panel rather than a buried tab. --}}
            @if ($priorActions->isNotEmpty())
                <x-ui.card
                    :title="'Prior enforcement ('.$priorActions->count().')'"
                    description="Actions on this member from other cases."
                >
                    <ol class="space-y-2.5">
                        @foreach ($priorActions as $prior)
                            <li class="space-y-0.5">
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <x-veyra.status-badge :status="$prior->ladder_step" size="sm" />
                                    <span class="text-xs text-muted-foreground">
                                        {{ veyra_date($prior->created_at) }}
                                    </span>
                                </div>
                                <p class="text-xs text-muted-foreground">{{ $prior->reason_code->label() }}</p>
                            </li>
                        @endforeach
                    </ol>
                </x-ui.card>
            @endif

            @if ($subject?->activeBan)
                <div class="rounded-xl border border-destructive/30 bg-destructive-subtle p-4">
                    <p class="text-sm font-medium text-destructive-subtle-foreground">
                        {{ $subject->activeBan->type->label() }} already in force
                    </p>
                    <p class="mt-1 text-xs text-destructive-subtle-foreground/85">
                        @if ($subject->activeBan->expires_at)
                            Expires {{ veyra_datetime($subject->activeBan->expires_at) }}
                        @else
                            No expiry
                        @endif
                    </p>
                </div>
            @endif

            @if ($reportCase->claimed_by === auth()->id() && $reportCase->status->isOpen())
                <x-ui.button variant="outline" size="sm" class="w-full" wire:click="release">
                    Release claim
                </x-ui.button>
            @endif
        </div>
    </div>
</div>
