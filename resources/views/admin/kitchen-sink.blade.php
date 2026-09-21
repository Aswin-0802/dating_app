@php
    use App\Enums\AccountStatus;
    use App\Enums\AppealStatus;
    use App\Enums\BanType;
    use App\Enums\CaseStatus;
    use App\Enums\LadderStep;
    use App\Enums\RiskBand;
    use App\Enums\Severity;
    use App\Enums\VerificationStatus;

    $sampleFactors = [
        ['label' => 'New account < 24h', 'points' => 30],
        ['label' => 'Off-platform link in first message', 'points' => 25],
        ['label' => '3 reports in 7 days', 'points' => 28],
        ['label' => 'Device shared with banned account', 'points' => 40],
        ['label' => 'Photo verified', 'points' => -15],
        ['label' => 'Account age over 180 days', 'points' => -12],
    ];
@endphp

<x-layouts.admin
    title="Component gallery"
    :breadcrumbs="[
        ['label' => App\Support\Branding::name(), 'href' => route('admin.dashboard')],
        ['label' => 'Component gallery'],
    ]"
>
    <x-slot:description>
        Every primitive in every variant. Check this page after a token change —
        it is the fastest way to catch a regression.
    </x-slot:description>

    <div class="space-y-6">

        {{-- ---------------------------------------------------------------- --}}
        <x-ui.card title="Colour tokens" description="Each pair must stay legible in both themes. Toggle the theme and re-read this row.">
            {{--
                Classes are spelled out in full rather than built from the token
                name. Tailwind v4 scans source files for literal strings, so
                "bg-{$token}" would be purged and every swatch would render blank
                — the same trap the enum badge helpers exist to avoid.
            --}}
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-6">
                @foreach ([
                    '--background' => 'bg-background text-foreground',
                    '--card' => 'bg-card text-card-foreground',
                    '--muted' => 'bg-muted text-muted-foreground',
                    '--primary' => 'bg-primary text-primary-foreground',
                    '--accent' => 'bg-accent text-accent-foreground',
                    '--secondary' => 'bg-secondary text-secondary-foreground',
                    '--success' => 'bg-success text-success-foreground',
                    '--warning' => 'bg-warning text-warning-foreground',
                    '--info' => 'bg-info text-info-foreground',
                    '--destructive' => 'bg-destructive text-destructive-foreground',
                    '--sidebar' => 'bg-sidebar text-sidebar-foreground',
                    '--sidebar-accent' => 'bg-sidebar-accent text-sidebar-accent-foreground',
                ] as $token => $swatch)
                    <div class="overflow-hidden rounded-lg border border-border">
                        <div class="flex h-16 items-center justify-center text-xs font-medium {{ $swatch }}">
                            Aa
                        </div>
                        <p class="truncate bg-card px-2 py-1.5 text-[11px] text-muted-foreground">{{ $token }}</p>
                    </div>
                @endforeach
            </div>

            <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
                @foreach (RiskBand::cases() as $band)
                    <div class="overflow-hidden rounded-lg border border-border">
                        <div class="flex h-12 items-center justify-center {{ $band->badgeClasses() }} text-xs font-medium">
                            {{ $band->label() }}
                        </div>
                        <p class="bg-card px-2 py-1.5 text-[11px] text-muted-foreground">{{ $band->range() }}</p>
                    </div>
                @endforeach
            </div>
        </x-ui.card>

        {{-- ---------------------------------------------------------------- --}}
        <x-ui.card title="Buttons">
            <div class="space-y-4">
                <div class="flex flex-wrap items-center gap-2">
                    @foreach (['default', 'secondary', 'outline', 'ghost', 'destructive', 'success', 'link'] as $variant)
                        <x-ui.button :variant="$variant">{{ ucfirst($variant) }}</x-ui.button>
                    @endforeach
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <x-ui.button size="xs">Extra small</x-ui.button>
                    <x-ui.button size="sm">Small</x-ui.button>
                    <x-ui.button size="md">Medium</x-ui.button>
                    <x-ui.button size="lg">Large</x-ui.button>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <x-ui.button icon="plus">With icon</x-ui.button>
                    <x-ui.button variant="outline" icon-right="arrow-right">Icon right</x-ui.button>
                    <x-ui.button variant="outline" size="icon"><x-ui.icon name="cog" size="sm" /></x-ui.button>
                    <x-ui.button loading>Loading</x-ui.button>
                    <x-ui.button disabled>Disabled</x-ui.button>
                </div>
            </div>
        </x-ui.card>

        {{-- ---------------------------------------------------------------- --}}
        <x-ui.card title="Status badges" description="Soft for states, solid only for urgency — so the eye is drawn to what needs action.">
            <div class="space-y-4">
                <div>
                    <p class="mb-2 text-xs font-medium text-muted-foreground">Account status</p>
                    <div class="flex flex-wrap gap-2">
                        @foreach (AccountStatus::cases() as $status)
                            <x-platform.status-badge :status="$status" />
                        @endforeach
                    </div>
                </div>

                <div>
                    <p class="mb-2 text-xs font-medium text-muted-foreground">Verification</p>
                    <div class="flex flex-wrap gap-2">
                        @foreach (VerificationStatus::cases() as $status)
                            <x-platform.status-badge :status="$status" />
                        @endforeach
                    </div>
                </div>

                <div>
                    <p class="mb-2 text-xs font-medium text-muted-foreground">Case status</p>
                    <div class="flex flex-wrap gap-2">
                        @foreach (CaseStatus::cases() as $status)
                            <x-platform.status-badge :status="$status" />
                        @endforeach
                    </div>
                </div>

                <div>
                    <p class="mb-2 text-xs font-medium text-muted-foreground">Severity &amp; appeals</p>
                    <div class="flex flex-wrap gap-2">
                        @foreach (Severity::cases() as $severity)
                            <x-platform.status-badge :status="$severity" />
                        @endforeach
                        @foreach (AppealStatus::cases() as $status)
                            <x-platform.status-badge :status="$status" />
                        @endforeach
                    </div>
                </div>

                <div>
                    <p class="mb-2 text-xs font-medium text-muted-foreground">Enforcement</p>
                    <div class="flex flex-wrap gap-2">
                        @foreach (BanType::cases() as $type)
                            <x-platform.status-badge :status="$type" />
                        @endforeach
                    </div>
                </div>
            </div>
        </x-ui.card>

        {{-- ---------------------------------------------------------------- --}}
        <x-ui.card
            title="Risk badge"
            description="Click a badge with factors. The listed factors are the stored rows that produced the score — they always sum to the number shown."
        >
            <div class="flex flex-wrap items-center gap-3">
                <x-platform.risk-badge :score="12" />
                <x-platform.risk-badge :score="38" />
                <x-platform.risk-badge :score="61" />
                <x-platform.risk-badge :score="96" :factors="$sampleFactors" />
            </div>

            <p class="mt-3 text-xs text-muted-foreground">
                The last badge is expandable: 30 + 25 + 28 + 40 − 15 − 12 = 96.
            </p>
        </x-ui.card>

        {{-- ---------------------------------------------------------------- --}}
        <x-ui.card title="SLA pills" description="Colour comes from the deadline when there is one. A breach always says by how much.">
            <div class="flex flex-wrap items-center gap-3">
                <x-platform.sla-pill :since="now()->subMinutes(12)" />
                <x-platform.sla-pill :since="now()->subHours(2)" />
                <x-platform.sla-pill :since="now()->subHours(9)" />
                <x-platform.sla-pill :since="now()->subHours(30)" :due-at="now()->subHours(6)" />
            </div>
        </x-ui.card>

        {{-- ---------------------------------------------------------------- --}}
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4 md:gap-6">
            <x-ui.stat-card label="Active members" value="11,284" icon="users" :delta="8.4" />
            <x-ui.stat-card label="Matches today" value="1,902" icon="heart" :delta="3.1" />
            <x-ui.stat-card
                label="Report rate / 1k matches"
                value="4.7"
                icon="flag"
                :delta="12.5"
                invert-delta
                hint="Rising — investigate"
            />
            <x-ui.stat-card label="Verification coverage" value="38%" icon="shield-check" :delta="-1.2" />
        </div>

        {{-- ---------------------------------------------------------------- --}}
        <x-ui.card title="Form controls">
            <div class="grid gap-5 md:grid-cols-2">
                <x-ui.input label="Search" icon="search" placeholder="Name, email or user ID" />
                <x-ui.input label="Email" type="email" placeholder="you@example.com" hint="We never email members from here." />
                <x-ui.input label="Duration" value="168" error="Must be 720 hours or fewer." />
                <x-ui.select
                    label="Reason code"
                    placeholder="Choose a reason…"
                    :options="['harassment_confirmed' => 'Harassment confirmed', 'romance_scam' => 'Romance scam', 'fake_profile_confirmed' => 'Fake profile confirmed']"
                />

                <div class="md:col-span-2">
                    <x-ui.textarea
                        label="Internal note"
                        rows="3"
                        placeholder="What did you see, and why does it meet the policy?"
                        hint="Visible to staff and recorded in the audit log. Never shown to the member."
                    />
                </div>

                <div class="space-y-3">
                    <x-ui.toggle label="Notify the member" description="Sends the statement of reasons." checked />
                    <x-ui.toggle size="lg" label="Apply shadow ban" description="Requires a review date." />
                </div>

                <div class="space-y-3">
                    <x-ui.checkbox label="Cannot send new likes" checked />
                    <x-ui.checkbox label="Cannot upload photos" />
                    <x-ui.checkbox label="Removed from discovery" description="Profile stops appearing in the deck." />
                </div>
            </div>
        </x-ui.card>

        {{-- ---------------------------------------------------------------- --}}
        <x-ui.card title="Overlays" description="One primitive, three presentations. Open the drawer, then open the confirm inside it — the modal stacks above and Escape closes only the top one.">
            <div class="flex flex-wrap items-center gap-2">
                <x-ui.overlay variant="modal" title="Modal" description="Dismissible, dynamic backdrop.">
                    <x-slot:trigger>
                        <x-ui.button variant="outline">Open modal</x-ui.button>
                    </x-slot:trigger>

                    <p class="text-sm text-muted-foreground">
                        Click the backdrop or press Escape to dismiss. Focus is trapped inside
                        and returns to the trigger on close.
                    </p>

                    <x-slot:footer>
                        <x-ui.button variant="outline" @click="close()">Cancel</x-ui.button>
                        <x-ui.button @click="close()">Confirm</x-ui.button>
                    </x-slot:footer>
                </x-ui.overlay>

                <x-ui.overlay
                    variant="modal"
                    size="sm"
                    backdrop="static"
                    title="Permanently ban this account?"
                    description="This cannot be undone from here."
                >
                    <x-slot:trigger>
                        <x-ui.button variant="destructive">Destructive (static backdrop)</x-ui.button>
                    </x-slot:trigger>

                    <div class="space-y-4">
                        <p class="text-sm text-muted-foreground">
                            Clicking outside will not dismiss this. Irreversible actions should never
                            be one stray click away.
                        </p>

                        <x-ui.select
                            label="Reason code"
                            required
                            placeholder="Choose a reason…"
                            :options="['romance_scam' => 'Romance scam', 'fake_profile_confirmed' => 'Fake profile confirmed']"
                        />

                        <x-ui.textarea label="Internal note" required rows="2" />
                    </div>

                    <x-slot:footer>
                        <x-ui.button variant="outline" @click="close()">Cancel</x-ui.button>
                        <x-ui.button variant="destructive" @click="close()">Ban permanently</x-ui.button>
                    </x-slot:footer>
                </x-ui.overlay>

                <x-ui.overlay variant="drawer" size="lg" title="Case VEY-2026-000412" description="Stacking test — open the confirm below.">
                    <x-slot:trigger>
                        <x-ui.button variant="outline" icon="flag">Open drawer</x-ui.button>
                    </x-slot:trigger>

                    <div class="space-y-4">
                        <p class="text-sm text-muted-foreground">
                            A drawer is the same component as the modal, positioned differently.
                            Opening a confirmation from inside it must place that modal above,
                            keep the backdrop stable, and route Escape to the modal only.
                        </p>

                        <x-ui.overlay variant="modal" size="sm" backdrop="static" title="Suspend for 7 days?">
                            <x-slot:trigger>
                                <x-ui.button variant="destructive" size="sm">Suspend from inside the drawer</x-ui.button>
                            </x-slot:trigger>

                            <p class="text-sm text-muted-foreground">
                                This modal sits above the drawer. Press Escape — the drawer should stay open.
                            </p>

                            <x-slot:footer>
                                <x-ui.button variant="outline" size="sm" @click="close()">Cancel</x-ui.button>
                                <x-ui.button variant="destructive" size="sm" @click="close()">Suspend</x-ui.button>
                            </x-slot:footer>
                        </x-ui.overlay>
                    </div>
                </x-ui.overlay>

                <x-ui.dropdown>
                    <x-slot:trigger>
                        <x-ui.button variant="outline" icon-right="chevron-down">Dropdown</x-ui.button>
                    </x-slot:trigger>

                    <x-ui.dropdown.label>Actions</x-ui.dropdown.label>
                    <x-ui.dropdown.item icon="eye">View profile</x-ui.dropdown.item>
                    <x-ui.dropdown.item icon="pencil" shortcut="E">Edit</x-ui.dropdown.item>
                    <x-ui.dropdown.separator />
                    <x-ui.dropdown.item icon="ban" variant="destructive">Ban account</x-ui.dropdown.item>
                </x-ui.dropdown>

                <x-ui.button
                    variant="outline"
                    x-data
                    @click="$store.toasts.push('Verification approved for Ines D.', 'success')"
                >Toast: success</x-ui.button>

                <x-ui.button
                    variant="outline"
                    x-data
                    @click="$store.toasts.push('Could not reach the face-match service.', 'error')"
                >Toast: error</x-ui.button>
            </div>
        </x-ui.card>

        {{-- ---------------------------------------------------------------- --}}
        <x-ui.card title="Tabs">
            <div class="space-y-5">
                <x-ui.tabs
                    :tabs="[
                        ['key' => 'all', 'label' => 'All cases', 'count' => 1680],
                        ['key' => 'mine', 'label' => 'Assigned to me', 'count' => 14],
                        ['key' => 'breaching', 'label' => 'Breaching SLA', 'count' => 9],
                        ['key' => 'closed', 'label' => 'Closed'],
                    ]"
                    active="all"
                />

                <x-ui.tabs
                    variant="pill"
                    :tabs="[
                        ['key' => '7d', 'label' => '7 days'],
                        ['key' => '30d', 'label' => '30 days'],
                        ['key' => '90d', 'label' => '90 days'],
                    ]"
                    active="30d"
                />
            </div>
        </x-ui.card>

        {{-- ---------------------------------------------------------------- --}}
        <x-ui.card title="Avatars, identity cells and keys">
            <div class="space-y-4">
                <div class="flex flex-wrap items-end gap-3">
                    @foreach (['xs', 'sm', 'md', 'lg', 'xl', '2xl'] as $size)
                        <x-ui.avatar name="Ines Dubois" :size="$size" />
                    @endforeach
                    <x-ui.avatar name="Kwame Mensah" size="lg" ring="success" />
                    <x-ui.avatar name="Lena Fischer" size="lg" ring="destructive" />
                </div>

                <div class="flex flex-wrap items-center gap-6">
                    <x-platform.user-cell name="Ines Dubois" :age="28" meta="London · joined 4 Mar 2026" verified />
                    <x-platform.user-cell name="Diego Santos" :age="34" meta="Sao Paulo · 3 reports" size="md" />
                </div>

                <div class="flex flex-wrap items-center gap-3 text-sm text-muted-foreground">
                    <span>Command palette <x-ui.kbd keys="mod+K" /></span>
                    <span>Toggle sidebar <x-ui.kbd keys="mod+B" /></span>
                    <span>Approve <x-ui.kbd>A</x-ui.kbd></span>
                    <span>Reject <x-ui.kbd>R</x-ui.kbd></span>
                </div>
            </div>
        </x-ui.card>

        {{-- ---------------------------------------------------------------- --}}
        <x-ui.card title="Enforcement ladder" description="Ordered by severity. Each step collects a reason code, note and duration in its own confirm modal.">
            <div class="flex flex-wrap items-center gap-2">
                @foreach (LadderStep::decisionBar() as $step)
                    <button
                        type="button"
                        class="inline-flex h-9 items-center gap-2 rounded-md px-3 text-sm font-medium transition-colors {{ $step->buttonClasses() }}"
                    >
                        {{ $step->label() }}
                        @if ($step->shortcut())
                            <span class="rounded-xs bg-black/15 px-1 text-[11px]">{{ $step->shortcut() }}</span>
                        @endif
                    </button>
                @endforeach
            </div>
        </x-ui.card>

        {{-- ---------------------------------------------------------------- --}}
        <x-ui.table>
            <x-slot:toolbar>
                <div class="flex flex-1 items-center gap-2">
                    <x-ui.input icon="search" placeholder="Search users…" class="max-w-xs" />
                    <x-ui.button variant="outline" size="sm" icon="filter">Filters</x-ui.button>
                </div>

                <div class="flex items-center gap-2">
                    <x-ui.button variant="outline" size="sm" icon="download">Export</x-ui.button>
                    <x-ui.button size="sm" icon="plus">Add</x-ui.button>
                </div>
            </x-slot:toolbar>

            <x-slot:bulkBar>
                <div class="flex flex-wrap items-center gap-3 border-b border-border bg-primary-subtle px-4 py-2.5">
                    <span class="text-sm font-medium text-primary">3 selected</span>
                    <button type="button" class="text-xs text-primary underline-offset-2 hover:underline">Select all 12,284 matching</button>
                    <div class="flex-1"></div>
                    <x-ui.button size="xs" variant="outline">Warn</x-ui.button>
                    <x-ui.button size="xs" variant="destructive">Suspend</x-ui.button>
                </div>
            </x-slot:bulkBar>

            <thead class="[&_tr]:border-b [&_tr]:border-border">
                <tr>
                    <x-ui.table.head><x-ui.checkbox role="checkbox" indeterminate /></x-ui.table.head>
                    <x-ui.table.head sortable field="name" direction="asc">Member</x-ui.table.head>
                    <x-ui.table.head>Status</x-ui.table.head>
                    <x-ui.table.head>Verification</x-ui.table.head>
                    <x-ui.table.head sortable field="risk">Risk</x-ui.table.head>
                    <x-ui.table.head sortable field="matches" align="right">Matches</x-ui.table.head>
                    <x-ui.table.head align="right">Last active</x-ui.table.head>
                </tr>
            </thead>

            <tbody>
                @foreach ([
                    ['Ines Dubois', 28, 'London', AccountStatus::Active, VerificationStatus::Approved, 12, 84, true],
                    ['Diego Santos', 34, 'Sao Paulo', AccountStatus::ShadowBanned, VerificationStatus::Unverified, 63, 21, false],
                    ['Lena Fischer', 26, 'Berlin', AccountStatus::Suspended, VerificationStatus::Rejected, 78, 4, false],
                    ['Kwame Mensah', 31, 'Lagos', AccountStatus::Banned, VerificationStatus::Escalated, 96, 1, false],
                    ['Hana Kobayashi', 29, 'Tokyo', AccountStatus::Active, VerificationStatus::Pending, 18, 47, false],
                ] as $index => [$name, $age, $city, $status, $verification, $risk, $matches, $verified])
                    @php $band = RiskBand::fromScore($risk); @endphp

                    <x-ui.table.row :selected="$index < 3" :tint="$band->rowClasses()">
                        <x-ui.table.cell><x-ui.checkbox role="checkbox" :checked="$index < 3" /></x-ui.table.cell>
                        <x-ui.table.cell>
                            <x-platform.user-cell :name="$name" :age="$age" :meta="$city" :verified="$verified" />
                        </x-ui.table.cell>
                        <x-ui.table.cell><x-platform.status-badge :status="$status" /></x-ui.table.cell>
                        <x-ui.table.cell><x-platform.status-badge :status="$verification" /></x-ui.table.cell>
                        <x-ui.table.cell>
                            <x-platform.risk-badge :score="$risk" :factors="$risk > 50 ? $sampleFactors : null" />
                        </x-ui.table.cell>
                        <x-ui.table.cell align="right" numeric>{{ $matches }}</x-ui.table.cell>
                        <x-ui.table.cell align="right" muted>{{ platform_duration(now()->subHours($index * 9 + 2)) }} ago</x-ui.table.cell>
                    </x-ui.table.row>
                @endforeach

                <x-ui.skeleton :rows="2" :columns="7" />
            </tbody>

            <x-slot:footer>
                <div class="flex flex-col items-center justify-between gap-3 sm:flex-row">
                    <p class="text-sm text-muted-foreground">
                        Showing <span class="tabular font-medium text-foreground">1</span>–<span class="tabular font-medium text-foreground">25</span>
                        of <span class="tabular font-medium text-foreground">12,284</span>
                    </p>

                    <div class="flex items-center gap-1">
                        <x-ui.button variant="outline" size="sm" icon="chevron-left" disabled>Previous</x-ui.button>
                        <x-ui.button variant="outline" size="sm" icon-right="chevron-right">Next</x-ui.button>
                    </div>
                </div>
            </x-slot:footer>
        </x-ui.table>

        {{-- ---------------------------------------------------------------- --}}
        <x-ui.card flush>
            <x-ui.empty-state
                icon="check-circle"
                heading="Queue is clear"
                description="No verifications are waiting. New submissions appear here automatically."
            >
                <x-slot:actions>
                    <x-ui.button variant="outline" size="sm" icon="arrow-path">Refresh</x-ui.button>
                    <x-ui.button size="sm">Review closed items</x-ui.button>
                </x-slot:actions>
            </x-ui.empty-state>
        </x-ui.card>

    </div>
</x-layouts.admin>
