@php
    $member = $verification->appUser;
    $photos = $member?->photos ?? collect();
    $canApprove = $verification->canBeApproved();
    $duplicates = $verification->duplicate_face_account_count;
@endphp

<div class="space-y-4 md:space-y-6" x-data="{ zoom: 1, compare: 50 }">

    {{-- A submission that cannot be approved says so before anything else. --}}
    @unless ($canApprove)
        <div class="flex items-start gap-3 rounded-xl border border-destructive/40 bg-destructive-subtle px-4 py-3">
            <x-ui.icon name="lock" size="sm" class="mt-0.5 shrink-0 text-destructive-subtle-foreground" />
            <div class="min-w-0 text-sm text-destructive-subtle-foreground">
                <p class="font-medium">This submission cannot be approved</p>
                <p class="mt-0.5 opacity-90">
                    The estimated age conflicts with the stated age. It must be escalated to
                    the minor safety queue or rejected.
                </p>
            </div>
        </div>
    @endunless

    {{-- The hero signal. A face on several accounts decides most of these
         reviews on its own, so it sits above the images, not beside them. --}}
    @if ($duplicates > 0)
        <div class="rounded-xl border border-destructive/40 bg-destructive-subtle px-4 py-3">
            <div class="flex flex-wrap items-center gap-3">
                <x-ui.icon name="link" size="sm" class="shrink-0 text-destructive-subtle-foreground" />
                <p class="min-w-0 flex-1 text-sm font-medium text-destructive-subtle-foreground">
                    This face appears on {{ $duplicates }} other
                    {{ str('account')->plural($duplicates) }}.
                </p>
            </div>

            @if ($duplicateAccounts->isNotEmpty())
                <div class="mt-3 flex flex-wrap gap-2">
                    @foreach ($duplicateAccounts as $account)
                        <a
                            href="{{ route('admin.users.show', $account) }}"
                            wire:navigate
                            class="flex items-center gap-2 rounded-lg border border-destructive/30 bg-card px-2.5 py-1.5 transition-colors hover:border-destructive"
                        >
                            <x-ui.avatar :src="$account->primaryPhoto?->thumb_url" :name="$account->display_name" size="xs" />
                            <span class="text-xs font-medium">{{ $account->display_name }}</span>
                            <x-veyra.status-badge :status="$account->account_status" size="sm" :show-icon="false" />
                        </a>
                    @endforeach
                </div>
            @endif
        </div>
    @endif

    <div class="grid gap-4 md:gap-6 xl:grid-cols-[1fr_360px]">
        <div class="space-y-4 md:space-y-6">

            {{-- ---- comparator ---------------------------------------- --}}
            <x-ui.card
                title="Identity comparison"
                description="Drag the handle to compare the submitted selfie against the profile photo."
            >
                <x-slot:action>
                    <div class="flex items-center gap-2">
                        <span class="text-xs text-muted-foreground">Zoom</span>
                        <input
                            type="range" min="1" max="3" step="0.1"
                            x-model="zoom"
                            class="h-1 w-24 cursor-pointer appearance-none rounded-full bg-muted accent-primary"
                        >
                        <button type="button" @click="zoom = 1"
                            class="rounded-md p-1 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground">
                            <x-ui.icon name="arrows-pointing-out" size="xs" />
                            <span class="sr-only">Reset zoom</span>
                        </button>
                    </div>
                </x-slot:action>

                @php
                    $selfieUrl = $verification->selfieExists() ? $verification->selfieUrl() : null;
                    $primaryUrl = $member?->primaryPhoto?->url;
                @endphp

                @if ($selfieUrl || $primaryUrl)
                    {{-- Both panes share one zoom value, so the faces stay the
                         same size as you magnify — comparing two images at
                         different scales tells you nothing. --}}
                    <div
                        class="relative aspect-square w-full max-w-lg select-none overflow-hidden rounded-lg border border-border bg-muted"
                        x-ref="comparator"
                        @pointermove="if ($event.buttons === 1) {
                            const r = $refs.comparator.getBoundingClientRect();
                            compare = Math.min(100, Math.max(0, (($event.clientX - r.left) / r.width) * 100));
                        }"
                    >
                        <img
                            src="{{ $primaryUrl }}"
                            alt="Profile photo"
                            class="absolute inset-0 size-full object-cover"
                            :style="`transform: scale(${zoom})`"
                        >

                        <div class="absolute inset-0 overflow-hidden" :style="`width: ${compare}%`">
                            <img
                                src="{{ $selfieUrl ?? $primaryUrl }}"
                                alt="Submitted selfie"
                                class="absolute inset-0 h-full object-cover"
                                :style="`width: ${$refs.comparator?.clientWidth}px; transform: scale(${zoom})`"
                            >
                        </div>

                        <div class="absolute inset-y-0 w-0.5 bg-white shadow-lg" :style="`left: ${compare}%`">
                            <div class="absolute top-1/2 left-1/2 flex size-8 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full bg-white text-black shadow-lg">
                                <x-ui.icon name="chevron-left" size="xs" class="-mr-1" />
                                <x-ui.icon name="chevron-right" size="xs" class="-ml-1" />
                            </div>
                        </div>

                        <span class="absolute left-2 top-2 rounded-full bg-black/60 px-2 py-0.5 text-[11px] font-medium text-white">
                            Selfie
                        </span>
                        <span class="absolute right-2 top-2 rounded-full bg-black/60 px-2 py-0.5 text-[11px] font-medium text-white">
                            Profile
                        </span>
                    </div>
                @else
                    {{-- A decided submission having no capture is correct: the
                         selfie is deleted after review and only the derived face
                         signature is kept. --}}
                    <x-ui.empty-state
                        icon="photo"
                        :heading="$verification->status->isOpen() ? 'No capture stored' : 'Capture deleted after review'"
                        :description="$verification->status->isOpen()
                            ? 'This submission has no stored selfie. Request a resubmission.'
                            : 'Verification captures are deleted once a decision is made. The derived face signature is retained.'"
                    />
                @endif

                {{-- All profile photos, so a reviewer can check the face against
                     every one rather than only the primary. --}}
                @if ($photos->isNotEmpty())
                    <div class="mt-4 space-y-2">
                        <p class="text-xs font-medium text-muted-foreground">All profile photos</p>
                        <div class="flex flex-wrap gap-2">
                            @foreach ($photos as $photo)
                                <img
                                    src="{{ $photo->thumb_url }}"
                                    alt="Profile photo {{ $photo->position + 1 }}"
                                    class="size-16 rounded-lg border border-border object-cover"
                                >
                            @endforeach
                        </div>
                    </div>
                @endif
            </x-ui.card>

            {{-- ---- automated signals --------------------------------- --}}
            <x-ui.card title="Automated signals" description="Computed at submission. Every value here is stored, not recalculated.">
                <div class="grid gap-2 sm:grid-cols-2">
                    @foreach ($verification->signals as $signal)
                        <div class="flex items-center justify-between gap-3 rounded-lg border border-border px-3 py-2">
                            <span class="flex min-w-0 items-center gap-2">
                                <x-ui.icon :name="$signal->icon()" size="sm"
                                    class="{{ $signal->passed ? 'text-success' : ($signal->severity === 'critical' ? 'text-destructive' : 'text-warning') }}" />
                                <span class="truncate text-sm">{{ $signal->label }}</span>
                            </span>

                            <span class="shrink-0 rounded-full px-2 py-0.5 text-xs font-medium tabular {{ $signal->chipClasses() }}">
                                {{ $signal->value }}
                            </span>
                        </div>
                    @endforeach
                </div>
            </x-ui.card>

            {{-- ---- decision bar -------------------------------------- --}}
            <x-ui.card>
                @error('decision')
                    <p class="mb-3 text-sm text-destructive">{{ $message }}</p>
                @enderror

                <div class="flex flex-wrap items-center gap-2">
                    @can('decide_verifications')
                        @if ($canApprove)
                            <x-ui.button variant="success" icon="check" wire:click="approve">
                                Approve
                                <x-ui.kbd>A</x-ui.kbd>
                            </x-ui.button>
                        @else
                            <x-ui.button variant="success" icon="check" disabled
                                title="Cannot approve a submission flagged for minor safety">
                                Approve
                            </x-ui.button>
                        @endif

                        <x-ui.button variant="destructive" icon="x-mark" wire:click="$toggle('rejectOpen')">
                            Reject
                            <x-ui.kbd>R</x-ui.kbd>
                        </x-ui.button>

                        <x-ui.button variant="outline" icon="flag" wire:click="escalate">
                            Escalate
                            <x-ui.kbd>E</x-ui.kbd>
                        </x-ui.button>
                    @else
                        <p class="text-sm text-muted-foreground">
                            You have read-only access to this queue.
                        </p>
                    @endcan

                    <div class="flex-1"></div>

                    <x-ui.button variant="ghost" size="sm" :href="route('admin.verifications.index')">
                        Back to queue
                    </x-ui.button>
                </div>

                {{-- Rejection reasons are an enumerated list, never free text:
                     they must be comparable across moderators and defensible in
                     an appeal. --}}
                @if ($rejectOpen)
                    <div class="mt-4 space-y-3 border-t border-border pt-4">
                        <x-ui.select
                            label="Rejection reason"
                            required
                            placeholder="Choose a reason…"
                            wire:model.live="reasonCode"
                            :options="collect($rejectionReasons)->mapWithKeys(fn ($r) => [$r->value => $r->label()])->all()"
                            :error="$errors->first('reasonCode')"
                        />

                        <x-ui.textarea
                            label="Internal note"
                            rows="2"
                            wire:model="note"
                            placeholder="What did you see? Visible to staff only."
                            :error="$errors->first('note')"
                            hint="Required when the reason is 'Other'."
                        />

                        <div class="flex items-center gap-2">
                            <x-ui.button variant="destructive" size="sm" wire:click="reject">
                                Confirm rejection
                            </x-ui.button>
                            <x-ui.button variant="ghost" size="sm" wire:click="$set('rejectOpen', false)">
                                Cancel
                            </x-ui.button>
                        </div>
                    </div>
                @endif
            </x-ui.card>
        </div>

        {{-- ---- context rail ------------------------------------------ --}}
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
                            <x-veyra.risk-badge
                                :score="$member->risk_score"
                                :band="$member->risk_band"
                                :factors="$member->riskScore?->factors"
                            />
                        @endif
                    </div>

                    <dl class="divide-y divide-border text-sm">
                        @foreach ([
                            'Attempt' => $verification->attempt_no.' of '.config('veyra.verification.max_attempts'),
                            'Submitted' => veyra_datetime($verification->submitted_at),
                            'Waiting' => veyra_duration($verification->submitted_at),
                            'Gesture code' => $verification->gesture_code,
                            'Stated age' => $member?->age,
                            'Estimated age' => $verification->estimated_age_min.'–'.$verification->estimated_age_max,
                            'Joined' => veyra_date($member?->created_at),
                        ] as $label => $value)
                            <div class="flex items-center justify-between gap-3 py-2 first:pt-0 last:pb-0">
                                <dt class="text-muted-foreground">{{ $label }}</dt>
                                <dd class="font-medium">{{ $value ?? '—' }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </div>
            </x-ui.card>

            @if ($verification->claimedBy)
                <div class="flex items-center gap-2 rounded-lg border border-border bg-muted/40 px-3 py-2 text-sm">
                    <x-ui.avatar :name="$verification->claimedBy->name" size="xs" />
                    <span class="min-w-0 truncate text-muted-foreground">
                        Claimed by
                        <span class="font-medium text-foreground">
                            {{ $verification->claimed_by === auth()->id() ? 'you' : $verification->claimedBy->name }}
                        </span>
                    </span>
                </div>
            @endif
        </div>
    </div>
</div>
