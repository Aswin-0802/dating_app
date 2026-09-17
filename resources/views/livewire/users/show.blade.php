@php
    use App\Enums\VerificationStatus;

    $profile = $appUser->profile;
    $isVerified = $appUser->verification_status === VerificationStatus::Approved;
@endphp

<div class="space-y-4 md:space-y-6">

    {{-- ---- identity header ------------------------------------------- --}}
    <x-ui.card>
        <div class="flex flex-col gap-5 sm:flex-row sm:items-start">
            <x-ui.avatar
                :src="$appUser->primaryPhoto?->url"
                :name="$appUser->display_name"
                size="2xl"
                :ring="$isVerified ? 'success' : null"
                class="shrink-0"
            />

            <div class="min-w-0 flex-1 space-y-3">
                <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                    <h2 class="text-xl font-semibold">{{ $appUser->display_name }}</h2>
                    <span class="tabular text-lg text-muted-foreground">{{ $appUser->age }}</span>
                    @if ($isVerified)
                        <x-ui.icon name="shield-check" size="sm" class="text-success" />
                    @endif
                </div>

                <div class="flex flex-wrap items-center gap-1.5">
                    <x-veyra.status-badge :status="$appUser->account_status" />
                    <x-veyra.status-badge :status="$appUser->verification_status" />
                    <x-veyra.risk-badge
                        :score="$appUser->risk_score"
                        :band="$appUser->risk_band"
                        :factors="$appUser->riskScore?->factors"
                    />
                    @if ($appUser->is_premium)
                        <x-ui.badge variant="accent" icon="sparkles">
                            {{ ucfirst($appUser->premium_tier ?? 'Premium') }}
                        </x-ui.badge>
                    @endif
                </div>

                <dl class="grid grid-cols-2 gap-x-6 gap-y-1.5 text-sm sm:grid-cols-3 lg:grid-cols-4">
                    @php
                        $facts = [
                            'Location' => $appUser->city ? $appUser->city->name.', '.($appUser->city->country?->iso2 ?? '') : '—',
                            'Joined' => veyra_date($appUser->created_at),
                            'Last active' => $appUser->last_active_at ? veyra_duration($appUser->last_active_at).' ago' : '—',
                            'Profile' => $appUser->profile_completion.'% complete',
                            'Signed up on' => ucfirst($appUser->signup_source),
                            'Gender' => $appUser->gender->label(),
                        ];

                        // PII is permission-gated even on a record the user can
                        // otherwise see; an analyst has no business reading emails.
                        if (auth()->user()->can('view_user_pii')) {
                            $facts['Email'] = $appUser->email;
                            $facts['Phone'] = $appUser->phone ?? '—';
                        }
                    @endphp

                    @foreach ($facts as $label => $value)
                        <div class="min-w-0">
                            <dt class="text-xs text-muted-foreground">{{ $label }}</dt>
                            <dd class="truncate font-medium">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            </div>

            <div class="flex shrink-0 flex-wrap items-center gap-2">
                @can('warn_users')
                    <x-ui.button variant="outline" size="sm" icon="warning">Warn</x-ui.button>
                @endcan

                @canany(['suspend_users', 'ban_users', 'shadow_ban_users'])
                    <x-ui.dropdown align="end">
                        <x-slot:trigger>
                            <x-ui.button variant="outline" size="sm" icon-right="chevron-down">Actions</x-ui.button>
                        </x-slot:trigger>

                        @can('shadow_ban_users')
                            <x-ui.dropdown.item icon="eye-off">Shadow ban…</x-ui.dropdown.item>
                        @endcan
                        @can('suspend_users')
                            <x-ui.dropdown.item icon="pause-circle">Suspend…</x-ui.dropdown.item>
                        @endcan
                        @can('ban_users')
                            <x-ui.dropdown.separator />
                            <x-ui.dropdown.item icon="ban" variant="destructive">Ban permanently…</x-ui.dropdown.item>
                        @endcan
                    </x-ui.dropdown>
                @endcanany
            </div>
        </div>
    </x-ui.card>

    {{-- Active enforcement is a banner, not a tab: it changes how every other
         thing on this page should be read. --}}
    @if ($appUser->activeBan)
        <div class="flex flex-wrap items-start gap-3 rounded-xl border border-destructive/30 bg-destructive-subtle p-4">
            <x-ui.icon name="ban" size="sm" class="mt-0.5 shrink-0 text-destructive-subtle-foreground" />

            <div class="min-w-0 flex-1 space-y-1">
                <p class="text-sm font-medium text-destructive-subtle-foreground">
                    {{ $appUser->activeBan->type->label() }} in force
                    @if ($appUser->activeBan->expires_at)
                        · expires {{ veyra_datetime($appUser->activeBan->expires_at) }}
                    @else
                        · no expiry
                    @endif
                </p>

                @if ($appUser->activeBan->review_due_at)
                    <p class="text-xs text-destructive-subtle-foreground/80">
                        Review due {{ veyra_datetime($appUser->activeBan->review_due_at) }}
                    </p>
                @endif
            </div>

            @can('lift_enforcement')
                <x-ui.button size="xs" variant="outline">Lift</x-ui.button>
            @endcan
        </div>
    @endif

    {{-- ---- tabs ------------------------------------------------------ --}}
    <div>
        <x-ui.tabs>
            @foreach ($tabs as $item)
                <button
                    type="button"
                    wire:click="setTab('{{ $item['key'] }}')"
                    @class([
                        "relative inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap px-3 py-2.5 -mb-px text-sm font-medium transition-colors after:absolute after:inset-x-3 after:bottom-0 after:h-0.5 after:content-['']",
                        'text-foreground after:bg-primary' => $tab === $item['key'],
                        'text-muted-foreground after:bg-transparent hover:text-foreground' => $tab !== $item['key'],
                    ])
                >
                    {{ $item['label'] }}

                    @if (($item['count'] ?? 0) > 0)
                        <span @class([
                            'tabular rounded-full px-1.5 py-px text-[11px] font-medium',
                            'bg-primary-subtle text-primary-subtle-foreground' => $tab === $item['key'],
                            'bg-muted text-muted-foreground' => $tab !== $item['key'],
                        ])>{{ $item['count'] }}</span>
                    @endif
                </button>
            @endforeach
        </x-ui.tabs>
    </div>

    {{-- ---- panels ---------------------------------------------------- --}}
    <div>
        @if ($tab === 'profile')
            <div class="grid gap-4 md:gap-6 lg:grid-cols-3">
                <x-ui.card class="lg:col-span-2" title="About">
                    @if ($profile?->bio)
                        <p class="whitespace-pre-line text-sm leading-relaxed">{{ $profile->bio }}</p>

                        @if ($profile->bio_contains_contact)
                            <div class="mt-3 flex items-start gap-2 rounded-md border border-warning/30 bg-warning-subtle p-2.5">
                                <x-ui.icon name="warning" size="xs" class="mt-0.5 shrink-0 text-warning-subtle-foreground" />
                                <p class="text-xs text-warning-subtle-foreground">
                                    This bio appears to contain off-platform contact details.
                                </p>
                            </div>
                        @endif
                    @else
                        <p class="text-sm text-muted-foreground">No bio written.</p>
                    @endif

                    @if ($appUser->interests->isNotEmpty())
                        <div class="mt-5 space-y-2">
                            <p class="text-xs font-medium text-muted-foreground">Interests</p>
                            <div class="flex flex-wrap gap-1.5">
                                @foreach ($appUser->interests as $interest)
                                    <x-ui.badge variant="muted">{{ $interest->name }}</x-ui.badge>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </x-ui.card>

                <x-ui.card title="Details">
                    <dl class="divide-y divide-border text-sm">
                        @foreach ([
                            'Job' => $profile?->job_title,
                            'Company' => $profile?->company,
                            'Education' => $profile?->education,
                            'Height' => $profile?->height_cm ? $profile->height_cm.' cm' : null,
                            'Looking for' => $profile?->relationship_goal ? str($profile->relationship_goal)->headline() : null,
                            'Drinking' => $profile?->drinking ? ucfirst($profile->drinking) : null,
                            'Smoking' => $profile?->smoking ? ucfirst($profile->smoking) : null,
                            'Children' => $profile?->children ? str($profile->children)->headline() : null,
                        ] as $label => $value)
                            <div class="flex items-start justify-between gap-4 py-2 first:pt-0 last:pb-0">
                                <dt class="shrink-0 text-muted-foreground">{{ $label }}</dt>
                                <dd class="truncate text-right font-medium">
                                    {{ filled($value) && $value !== 'Unspecified' ? $value : '—' }}
                                </dd>
                            </div>
                        @endforeach
                    </dl>
                </x-ui.card>
            </div>

        @elseif ($tab === 'photos')
            <x-ui.card :title="$appUser->photos->count().' '.str('photo')->plural($appUser->photos->count())">
                @if ($appUser->photos->isEmpty())
                    <x-ui.empty-state icon="photo" heading="No photos" description="This member has not uploaded any photos." />
                @else
                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
                        @foreach ($appUser->photos as $photo)
                            {{-- Graphic content is blurred until deliberately
                                 revealed. Moderators review hundreds of these a
                                 day; unsolicited exposure is a wellbeing issue. --}}
                            <div x-data="{ revealed: {{ $photo->isGraphic() ? 'false' : 'true' }} }" class="group relative aspect-4/5 overflow-hidden rounded-lg border border-border bg-muted">
                                <img
                                    src="{{ $photo->thumb_url }}"
                                    alt="Photo {{ $photo->position + 1 }}"
                                    loading="lazy"
                                    class="size-full object-cover transition"
                                    ::class="revealed ? '' : 'blur-xl scale-110'"
                                >

                                <template x-if="!revealed">
                                    <button
                                        type="button"
                                        @click="revealed = true"
                                        class="absolute inset-0 flex flex-col items-center justify-center gap-1 bg-black/40 text-white"
                                    >
                                        <x-ui.icon name="eye-off" size="sm" />
                                        <span class="text-[11px] font-medium">Sensitive · tap to view</span>
                                    </button>
                                </template>

                                <div class="absolute inset-x-1.5 top-1.5 flex items-center justify-between gap-1">
                                    @if ($photo->is_primary)
                                        <span class="rounded-full bg-black/60 px-1.5 py-0.5 text-[10px] font-medium text-white">Primary</span>
                                    @else
                                        <span></span>
                                    @endif

                                    @if ($photo->moderation_status !== 'approved')
                                        <span class="rounded-full bg-warning px-1.5 py-0.5 text-[10px] font-medium text-warning-foreground">
                                            {{ str($photo->moderation_status)->headline() }}
                                        </span>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-ui.card>

        @else
            {{-- Remaining tabs land with their modules. An honest placeholder
                 beats a fake empty state that looks like real emptiness. --}}
            <x-ui.card flush>
                <x-ui.empty-state
                    icon="squares"
                    :heading="'The '.strtolower(collect($tabs)->firstWhere('key', $tab)['label'] ?? $tab).' tab is not built yet'"
                    description="It arrives with its own module. The profile and photos tabs are live."
                />
            </x-ui.card>
        @endif
    </div>
</div>
