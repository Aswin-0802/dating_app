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
                            {{ App\Support\Masters::plan($appUser->premium_tier)?->name ?? ucfirst($appUser->premium_tier ?? 'Premium') }}
                            @if ($appUser->premium_until)
                                · until {{ veyra_date($appUser->premium_until) }}
                            @else
                                · no end date
                            @endif
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
                @can('edit_users')
                    <x-ui.button variant="outline" size="sm" icon="sparkles" wire:click="openPlanForm">
                        {{ $appUser->is_premium ? 'Change plan' : 'Give plan' }}
                    </x-ui.button>
                @endcan

                @can('warn_users')
                    <x-ui.button variant="outline" size="sm" icon="warning" wire:click="openStep('warn')">Warn</x-ui.button>
                @endcan

                @canany(['limit_users', 'suspend_users', 'ban_users', 'shadow_ban_users'])
                    <x-ui.dropdown align="end">
                        <x-slot:trigger>
                            <x-ui.button variant="outline" size="sm" icon-right="chevron-down">Actions</x-ui.button>
                        </x-slot:trigger>

                        @can('limit_users')
                            <x-ui.dropdown.item icon="adjustments" wire:click="openStep('feature_limit')">Limit features…</x-ui.dropdown.item>
                        @endcan
                        @can('shadow_ban_users')
                            <x-ui.dropdown.item icon="eye-off" wire:click="openStep('shadow_ban')">Shadow ban…</x-ui.dropdown.item>
                        @endcan
                        @can('suspend_users')
                            <x-ui.dropdown.item icon="pause-circle" wire:click="openStep('suspend')">Suspend…</x-ui.dropdown.item>
                        @endcan
                        @can('ban_users')
                            <x-ui.dropdown.separator />
                            <x-ui.dropdown.item icon="ban" variant="destructive" wire:click="openStep('permanent_ban')">Ban permanently…</x-ui.dropdown.item>
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
                <x-ui.button
                    size="xs"
                    variant="outline"
                    wire:click="liftActiveBan"
                    wire:confirm="Lift this {{ strtolower($appUser->activeBan->type->label()) }}? The member regains full access straight away."
                >Lift</x-ui.button>
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

        @elseif ($tab === 'matches')
            <x-ui.card :title="'Matches'" :description="$tabData['matches']->count() >= 50 ? 'The 50 most recent.' : $tabData['matches']->count().' in total.'" flush>
                @if ($tabData['matches']->isEmpty())
                    <x-ui.empty-state icon="heart" heading="No matches yet" />
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-border text-left text-xs text-muted-foreground">
                                    <th class="px-4 py-2.5 font-medium">Matched with</th>
                                    <th class="px-4 py-2.5 font-medium">Matched</th>
                                    <th class="px-4 py-2.5 font-medium">Status</th>
                                    <th class="px-4 py-2.5 text-right font-medium">Messages</th>
                                    <th class="px-4 py-2.5"><span class="sr-only">Open</span></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border">
                                @foreach ($tabData['matches'] as $match)
                                    @php $other = $match->otherParty($appUser); @endphp
                                    <tr wire:key="m-{{ $match->id }}">
                                        <td class="px-4 py-2.5">
                                            @if ($other)
                                                <a href="{{ route('admin.users.show', $other) }}" wire:navigate class="flex items-center gap-2.5 hover:underline">
                                                    <x-ui.avatar :src="$other->primaryPhoto?->thumb_url" :name="$other->display_name" size="xs" />
                                                    {{ $other->display_name }}
                                                </a>
                                            @else
                                                <span class="text-muted-foreground">Deleted member</span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-2.5 text-muted-foreground">{{ veyra_date($match->matched_at) }}</td>
                                        <td class="px-4 py-2.5"><x-ui.badge size="sm" :variant="$match->status === 'active' ? 'success' : 'muted'">{{ ucfirst($match->status) }}</x-ui.badge></td>
                                        <td class="tabular px-4 py-2.5 text-right">{{ veyra_number($match->messages_count) }}</td>
                                        <td class="px-4 py-2.5 text-right">
                                            @if ($match->conversation && auth()->user()->can('conversations'))
                                                <x-ui.button size="xs" variant="ghost" :href="route('admin.conversations.show', $match->conversation)">Conversation</x-ui.button>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-ui.card>

        @elseif ($tab === 'reports')
            <x-ui.card title="Reports about this member" :description="'This member has filed '.$tabData['filed'].' '.str('report')->plural($tabData['filed']).' about others.'" flush>
                @if ($tabData['reports']->isEmpty())
                    <x-ui.empty-state icon="flag" heading="No reports about this member" />
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-border text-left text-xs text-muted-foreground">
                                    <th class="px-4 py-2.5 font-medium">Received</th>
                                    <th class="px-4 py-2.5 font-medium">Category</th>
                                    <th class="px-4 py-2.5 font-medium">Severity</th>
                                    <th class="px-4 py-2.5 font-medium">Reported by</th>
                                    <th class="px-4 py-2.5 font-medium">Case</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border">
                                @foreach ($tabData['reports'] as $report)
                                    <tr wire:key="r-{{ $report->id }}">
                                        <td class="px-4 py-2.5 text-muted-foreground">{{ veyra_datetime($report->created_at) }}</td>
                                        <td class="px-4 py-2.5">{{ $report->category->label() }}</td>
                                        <td class="px-4 py-2.5"><x-veyra.status-badge :status="$report->severity" /></td>
                                        <td class="px-4 py-2.5">{{ $report->reporter?->display_name ?? 'Automated' }}</td>
                                        <td class="px-4 py-2.5">
                                            @if ($report->reportCase)
                                                <a href="{{ route('admin.cases.show', $report->reportCase) }}" wire:navigate class="font-mono text-xs text-primary hover:underline">{{ $report->reportCase->case_number }}</a>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-ui.card>

        @elseif ($tab === 'enforcement')
            <x-ui.card title="Enforcement history" flush>
                @if ($tabData['actions']->isEmpty())
                    <x-ui.empty-state icon="shield-check" heading="No enforcement on record" description="This member has never been warned or restricted." />
                @else
                    <ol class="divide-y divide-border">
                        @foreach ($tabData['actions'] as $action)
                            <li class="flex flex-wrap items-start gap-x-4 gap-y-1 px-4 py-3 text-sm" wire:key="a-{{ $action->id }}">
                                <span class="w-36 shrink-0 text-muted-foreground">{{ veyra_datetime($action->created_at) }}</span>
                                <span class="min-w-0 flex-1">
                                    <span class="font-medium">{{ $action->ladder_step->label() }}</span>
                                    <span class="text-muted-foreground">· {{ $action->reason_code?->label() }}</span>
                                    @if ($action->duration_hours)
                                        <span class="text-muted-foreground">· {{ veyra_hours_label($action->duration_hours) }}</span>
                                    @endif
                                    @if ($action->internal_note)
                                        <span class="mt-0.5 block text-xs text-muted-foreground">{{ $action->internal_note }}</span>
                                    @endif
                                </span>
                                <span class="shrink-0 text-xs text-muted-foreground">{{ $action->actorLabel() }}</span>
                            </li>
                        @endforeach
                    </ol>
                @endif
            </x-ui.card>

        @elseif ($tab === 'billing')
            <x-ui.card
                title="Plan"
                :description="$appUser->is_premium
                    ? 'On '.(App\Support\Masters::plan($appUser->premium_tier)?->name ?? $appUser->premium_tier).($appUser->premium_until ? ' until '.veyra_date($appUser->premium_until) : ', with no end date')
                    : 'On the free tier.'"
            >
                @can('edit_users')
                    <x-slot:action>
                        <div class="flex gap-2">
                            <x-ui.button size="sm" variant="outline" icon="sparkles" wire:click="openPlanForm">
                                {{ $appUser->is_premium ? 'Change plan' : 'Give plan' }}
                            </x-ui.button>
                            @if ($appUser->is_premium)
                                <x-ui.button
                                    size="sm"
                                    variant="ghost"
                                    class="text-destructive"
                                    wire:click="removePlan"
                                    wire:confirm="Remove this plan? The member goes back to the free tier straight away."
                                >Remove</x-ui.button>
                            @endif
                        </div>
                    </x-slot:action>
                @endcan

                @if ($tabData['subscriptions']->isEmpty())
                    <x-ui.empty-state
                        icon="sparkles"
                        heading="No plan history"
                        description="Nothing has been bought or granted for this member yet."
                    />
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-border text-left text-xs text-muted-foreground">
                                    <th class="py-2 pr-3 font-medium">Plan</th>
                                    <th class="py-2 pr-3 font-medium">From</th>
                                    <th class="py-2 pr-3 font-medium">Until</th>
                                    <th class="py-2 pr-3 font-medium">How</th>
                                    <th class="py-2 pr-3 text-right font-medium">Paid</th>
                                    <th class="py-2 pr-3 font-medium">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border">
                                @foreach ($tabData['subscriptions'] as $subscription)
                                    <tr wire:key="sub-{{ $subscription->id }}">
                                        <td class="py-2.5 pr-3 font-medium">{{ $subscription->plan_name }}</td>
                                        <td class="py-2.5 pr-3 text-muted-foreground">{{ veyra_date($subscription->starts_at) }}</td>
                                        <td class="py-2.5 pr-3 text-muted-foreground">
                                            {{ $subscription->ends_at ? veyra_date($subscription->ends_at) : 'No end date' }}
                                        </td>
                                        <td class="py-2.5 pr-3 text-muted-foreground">
                                            {{ $subscription->source === 'payment' ? 'Paid online' : 'Given by staff' }}
                                            @if ($subscription->grantedBy)
                                                <span class="block text-xs">{{ $subscription->grantedBy->name }}</span>
                                            @endif
                                            @if ($subscription->note)
                                                <span class="block text-xs italic">{{ $subscription->note }}</span>
                                            @endif
                                        </td>
                                        <td class="tabular py-2.5 pr-3 text-right">
                                            {{ $subscription->amount !== null ? App\Support\Currency::format($subscription->amount) : '—' }}
                                        </td>
                                        <td class="py-2.5 pr-3">
                                            @if ($subscription->status === 'active')
                                                <x-ui.badge size="sm" variant="success" dot>Active</x-ui.badge>
                                            @elseif ($subscription->status === 'expired')
                                                <x-ui.badge size="sm" variant="muted">Ended</x-ui.badge>
                                            @else
                                                <x-ui.badge size="sm" variant="warning">Replaced</x-ui.badge>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-ui.card>

        @elseif ($tab === 'devices')
            <div class="grid gap-4 md:gap-6 lg:grid-cols-2">
                <x-ui.card title="Devices" flush>
                    @if ($tabData['devices']->isEmpty())
                        <x-ui.empty-state icon="device" heading="No devices recorded" />
                    @else
                        <ul class="divide-y divide-border">
                            @foreach ($tabData['devices'] as $device)
                                <li class="flex items-center gap-3 px-4 py-3 text-sm" wire:key="d-{{ $device->id }}">
                                    <x-ui.icon name="device" size="md" class="shrink-0 text-muted-foreground" />
                                    <div class="min-w-0 flex-1">
                                        <p class="font-medium">{{ ucfirst($device->platform) }} {{ $device->os_version }}</p>
                                        <p class="text-xs text-muted-foreground">App {{ $device->app_version }} · last seen {{ $device->last_seen_at ? veyra_duration($device->last_seen_at).' ago' : 'never' }}</p>
                                    </div>
                                    @if ($device->shared_with > 0)
                                        <x-ui.badge variant="warning" size="sm">Shared with {{ $device->shared_with }} {{ str('account')->plural($device->shared_with) }}</x-ui.badge>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </x-ui.card>

                <x-ui.card title="Recent sign-ins" flush>
                    @if ($tabData['logins']->isEmpty())
                        <x-ui.empty-state icon="lock" heading="No sign-ins recorded" />
                    @else
                        <ul class="divide-y divide-border">
                            @foreach ($tabData['logins'] as $login)
                                <li class="flex items-center justify-between gap-3 px-4 py-2.5 text-sm" wire:key="l-{{ $login->id }}">
                                    <span class="text-muted-foreground">{{ veyra_datetime($login->created_at) }}</span>
                                    <span class="font-mono text-xs">{{ $login->ip_address }}</span>
                                    <x-ui.badge size="sm" :variant="$login->succeeded ? 'success' : 'destructive'">{{ $login->succeeded ? 'Signed in' : 'Failed' }}</x-ui.badge>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </x-ui.card>
            </div>

        @elseif ($tab === 'timeline')
            <x-ui.card title="Staff activity on this member" flush>
                @if ($tabData['events']->isEmpty())
                    <x-ui.empty-state icon="clipboard-list" heading="No staff activity yet" />
                @else
                    <ol class="divide-y divide-border">
                        @foreach ($tabData['events'] as $event)
                            <li class="flex flex-wrap items-start gap-x-4 gap-y-1 px-4 py-3 text-sm" wire:key="e-{{ $event->id }}">
                                <span class="w-36 shrink-0 text-muted-foreground">{{ veyra_datetime($event->created_at) }}</span>
                                <span class="min-w-0 flex-1">
                                    {{ $event->description }}
                                    @if ($event->is_sensitive)
                                        <x-ui.badge size="sm" variant="destructive" class="ml-1">Sensitive</x-ui.badge>
                                    @endif
                                </span>
                                <span class="shrink-0 text-xs text-muted-foreground">{{ $event->actor_name ?? 'System' }}</span>
                            </li>
                        @endforeach
                    </ol>
                @endif
            </x-ui.card>
        @endif
    </div>
    <x-ui.dialog :show="$planFormOpen" close="closePlanForm">
        <form wire:submit="savePlan" class="space-y-4 p-6" novalidate>
            <div>
                <h2 class="text-lg font-semibold">{{ $appUser->is_premium ? 'Change plan' : 'Give plan' }}</h2>
                <p class="mt-1 text-sm text-muted-foreground">{{ $appUser->display_name }}</p>
            </div>

            @if (App\Support\Masters::plans()->isEmpty())
                <p class="rounded-md bg-muted px-3 py-2 text-sm text-muted-foreground">
                    There are no plans on sale. Add one in
                    <a href="{{ route('admin.billing.plans') }}" wire:navigate class="font-medium text-primary hover:underline">Masters → Subscription plans</a>.
                </p>
            @else
                <x-ui.select
                    label="Plan"
                    wire:model="planSlug"
                    :selected="$planSlug"
                    :options="App\Support\Masters::plans()->mapWithKeys(fn ($p) => [$p->slug => $p->name.' · '.App\Support\Currency::format($p->monthly_price).' / month'])->all()"
                    :error="$errors->first('planSlug')"
                    required
                />

                <x-ui.select
                    label="How long"
                    wire:model.live="planLength"
                    :selected="$planLength"
                    :options="['1m' => 'One month', '12m' => 'One year', 'custom' => 'Until a date I choose', 'open' => 'No end date']"
                    :error="$errors->first('planLength')"
                />

                @if ($planLength === 'custom')
                    <x-ui.input label="Ends on" type="date" wire:model="planEndsAt" :error="$errors->first('planEndsAt')" required />
                @endif

                <x-ui.input
                    label="Note"
                    wire:model="planNote"
                    placeholder="Paid by bank transfer, ref 4471"
                    hint="Internal only. The member never sees it."
                    :error="$errors->first('planNote')"
                />

                <p class="rounded-md bg-muted px-3 py-2 text-xs text-muted-foreground">
                    The member gets everything the plan unlocks straight away, and it ends by itself on the date above.
                </p>
            @endif

            <div class="flex flex-col-reverse gap-2 pt-2 sm:flex-row sm:justify-end">
                <x-ui.button variant="ghost" wire:click="closePlanForm">Cancel</x-ui.button>
                @if (App\Support\Masters::plans()->isNotEmpty())
                    <x-ui.button type="submit">Save plan</x-ui.button>
                @endif
            </div>
        </form>
    </x-ui.dialog>

    <x-veyra.enforcement-dialog
        :step="$pendingStep"
        :target="$appUser->display_name.' · '.$appUser->email"
        :reason-code="$reasonCode"
        :duration-hours="$durationHours"
        :notify-user="$notifyUser"
    />
</div>
