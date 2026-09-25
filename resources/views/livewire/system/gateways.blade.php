<div class="space-y-4 md:space-y-6">

    <div class="flex items-start gap-3 rounded-xl border border-border bg-muted/40 px-4 py-3">
        <x-ui.icon name="lock" size="sm" class="mt-0.5 shrink-0 text-muted-foreground" />
        <div class="min-w-0 text-sm">
            <p class="font-medium">Credentials are write-only</p>
            <p class="mt-0.5 text-muted-foreground">
                Stored secrets are encrypted and never rendered back into this page. Leave a
                field blank to keep what is already saved; fill it in to replace it.
            </p>
        </div>
    </div>

    <div class="grid gap-4 md:gap-6 lg:grid-cols-2">
        @foreach ($gateways as $gateway)
            <x-ui.card>
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0 space-y-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="text-base font-semibold">{{ $gateway->name }}</h3>

                            @if ($gateway->is_active)
                                <x-ui.badge variant="success" size="sm">Active</x-ui.badge>
                            @else
                                <x-ui.badge variant="muted" size="sm">Off</x-ui.badge>
                            @endif

                            @if ($kind === 'payment' && $gateway->isStore())
                                <x-ui.badge variant="muted" size="sm">In-app purchase</x-ui.badge>
                            @elseif ($kind === 'payment' && $gateway->is_test_mode)
                                <x-ui.badge variant="warning" size="sm">Test mode</x-ui.badge>
                            @endif
                        </div>

                        <p class="font-mono text-[11px] text-muted-foreground">{{ $gateway->slug }}</p>
                    </div>

                    @if ($canEdit && $editing !== $gateway->id)
                        <div class="flex shrink-0 items-center gap-1">
                            <x-ui.button size="xs" variant="outline" wire:click="edit({{ $gateway->id }})">
                                Configure
                            </x-ui.button>
                            <x-ui.button size="xs" variant="ghost" wire:click="toggleActive({{ $gateway->id }})">
                                {{ $gateway->is_active ? 'Disable' : 'Enable' }}
                            </x-ui.button>
                        </div>
                    @endif
                </div>

                @if ($kind === 'payment')
                    @php $supported = in_array($gateway->slug, ['stripe', 'razorpay'], true); @endphp

                    @if ($gateway->isStore())
                        {{-- The store tells us about renewals, refunds and expiry
                             at this address. Without it, a lapsed subscription
                             is only caught by the expiry job. --}}
                        <div class="mt-3 rounded-lg bg-muted px-3 py-2.5">
                            <p class="text-xs font-medium">Notification address</p>
                            <p class="mt-1 break-all font-mono text-[11px] text-muted-foreground">{{ route('webhooks.store', $gateway->slug) }}</p>
                            <p class="mt-1.5 text-[11px] text-muted-foreground">
                                @if ($gateway->slug === 'apple')
                                    App Store Connect → your app → App Information → App Store Server Notifications → set this as the
                                    <span class="font-mono">Version 2</span> production <em>and</em> sandbox URL. The key above is an
                                    App Store Connect API key with the <em>In-App Purchase</em> role. Sandbox and TestFlight purchases are
                                    honoured on this server whatever the test-mode switch says; the transaction itself says which it is.
                                @else
                                    Play Console → Monetise → Monetisation setup → Real-time developer notifications → a Pub/Sub topic with a
                                    <em>push</em> subscription to this address, authenticated with a service account (OIDC). The JSON key above
                                    is a service account with access to this app in Play Console. Licence-tester purchases are honoured.
                                @endif
                            </p>
                            @if (! request()->secure() && ! app()->environment('local'))
                                <p class="mt-1.5 text-[11px] text-destructive">The stores only send notifications to https addresses.</p>
                            @endif
                        </div>
                    @elseif ($supported)
                        {{-- The webhook is what confirms a payment, so its address
                             is shown here rather than buried in documentation. --}}
                        <div class="mt-3 rounded-lg bg-muted px-3 py-2.5">
                            <p class="text-xs font-medium">Webhook address</p>
                            <p class="mt-1 break-all font-mono text-[11px] text-muted-foreground">{{ route('webhooks.payments', $gateway->slug) }}</p>
                            <p class="mt-1.5 text-[11px] text-muted-foreground">
                                @if ($gateway->slug === 'stripe')
                                    Stripe Dashboard → Developers → Webhooks → add this address, subscribe to
                                    <span class="font-mono">checkout.session.completed</span>, then paste the signing secret (whsec_…) above.
                                @else
                                    Razorpay Dashboard → Account &amp; Settings → Webhooks → add this address, tick
                                    <span class="font-mono">payment_link.paid</span> and <span class="font-mono">payment.failed</span>,
                                    and use the same secret you type above.
                                @endif
                            </p>
                            @if (! request()->secure() && ! app()->environment('local'))
                                <p class="mt-1.5 text-[11px] text-destructive">Gateways only send webhooks to https addresses.</p>
                            @endif
                        </div>
                    @else
                        <div class="mt-3 flex items-start gap-2 rounded-md border border-border bg-muted/50 p-2.5">
                            <x-ui.icon name="info" size="xs" class="mt-0.5 shrink-0 text-muted-foreground" />
                            <p class="text-xs text-muted-foreground">
                                Credentials can be stored, but checkout does not use {{ $gateway->name }} yet — members are offered Stripe and Razorpay.
                            </p>
                        </div>
                    @endif

                    @if ($gateway->is_active && $supported && ! in_array(App\Support\Currency::code(), $gateway->slug === 'razorpay' ? ['INR'] : ['USD', 'EUR', 'GBP', 'INR'], true))
                        <div class="mt-3 flex items-start gap-2 rounded-md border border-warning/30 bg-warning-subtle p-2.5">
                            <x-ui.icon name="warning" size="xs" class="mt-0.5 shrink-0 text-warning-subtle-foreground" />
                            <p class="text-xs text-warning-subtle-foreground">
                                Your prices are in {{ App\Support\Currency::code() }}, which {{ $gateway->name }} cannot charge.
                                Members will not be offered it.
                            </p>
                        </div>
                    @endif
                @endif

                {{-- A gateway switched on but not configured, or live in test
                     mode, takes real money nowhere. Said plainly. --}}
                @if ($gateway->hasConfigurationWarning() ?? false)
                    <div class="mt-3 flex items-start gap-2 rounded-md border border-warning/30 bg-warning-subtle p-2.5">
                        <x-ui.icon name="warning" size="xs" class="mt-0.5 shrink-0 text-warning-subtle-foreground" />
                        <p class="text-xs text-warning-subtle-foreground">
                            {{ $gateway->isConfigured()
                                ? 'This gateway is live but still in test mode.'
                                : 'This gateway is enabled but missing credentials.' }}
                        </p>
                    </div>
                @endif

                @if ($editing === $gateway->id)
                    <div class="mt-4 space-y-3 border-t border-border pt-4">
                        @foreach ($gateway->credentialFields() as $field)
                            @if (method_exists($gateway, 'multilineCredentialFields') && in_array($field, $gateway->multilineCredentialFields(), true))
                                <x-ui.textarea
                                    :label="str($field)->headline()->toString()"
                                    rows="4"
                                    class="font-mono text-xs"
                                    wire:model="credentials.{{ $field }}"
                                    :placeholder="($gateway->credentialStatus()[$field] ?? false) ? '•••••••• (saved — leave blank to keep)' : 'Paste the whole file'"
                                    autocomplete="off"
                                />
                            @else
                                <x-ui.input
                                    :label="str($field)->headline()->toString()"
                                    type="password"
                                    wire:model="credentials.{{ $field }}"
                                    :placeholder="($gateway->credentialStatus()[$field] ?? false) ? '•••••••• (saved — leave blank to keep)' : (method_exists($gateway, 'optionalCredentialFields') && in_array($field, $gateway->optionalCredentialFields(), true) ? 'Optional' : 'Not set')"
                                    autocomplete="off"
                                />
                            @endif
                        @endforeach

                        @if ($kind === 'sms')
                            <x-ui.input label="Sender ID" wire:model="senderId"
                                hint="Shown as the sender on delivered messages." />
                        @endif

                        <div class="space-y-2">
                            <x-ui.toggle size="lg" label="Active" wire:model="isActive" :checked="$isActive" />

                            @if ($kind === 'payment')
                                <x-ui.toggle
                                    size="lg"
                                    label="Test mode"
                                    description="Test mode: no real payments are taken."
                                    wire:model="isTestMode"
                                    :checked="$isTestMode"
                                />
                            @endif
                        </div>

                        <div class="flex items-center gap-2">
                            <x-ui.button size="sm" wire:click="save">Save</x-ui.button>
                            <x-ui.button size="sm" variant="ghost" wire:click="cancel">Cancel</x-ui.button>
                        </div>
                    </div>
                @else
                    <dl class="mt-3 space-y-1.5">
                        @foreach ($gateway->credentialStatus() as $field => $isSet)
                            <div class="flex items-center justify-between gap-3 text-sm">
                                <dt class="text-muted-foreground">{{ str($field)->headline() }}</dt>
                                <dd>
                                    @if ($isSet)
                                        <span class="inline-flex items-center gap-1 text-xs text-success-subtle-foreground">
                                            <x-ui.icon name="check" size="xs" /> Saved
                                        </span>
                                    @else
                                        <span class="text-xs text-muted-foreground">Not set</span>
                                    @endif
                                </dd>
                            </div>
                        @endforeach
                    </dl>

                    @if ($gateway->updatedBy)
                        <p class="mt-3 text-xs text-muted-foreground">
                            Last changed by {{ $gateway->updatedBy->name }}, {{ platform_date($gateway->updated_at) }}
                        </p>
                    @endif
                @endif
            </x-ui.card>
        @endforeach
    </div>
</div>
