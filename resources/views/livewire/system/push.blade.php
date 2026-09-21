<div class="space-y-4 md:space-y-6">

    {{-- ---- status ------------------------------------------------------- --}}
    <x-ui.card>
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0 space-y-1">
                <h2 class="flex items-center gap-2 text-base font-semibold">
                    <x-ui.icon name="bell" size="sm" class="text-muted-foreground" />
                    Firebase Cloud Messaging
                </h2>
                @if ($account)
                    <p class="text-sm text-muted-foreground">
                        Project <span class="font-medium text-foreground">{{ $account['project_id'] }}</span>
                        · {{ $account['client_email'] }}
                    </p>
                @else
                    <p class="text-sm text-muted-foreground">
                        No credentials yet. Push notifications are off until a service account is saved.
                    </p>
                @endif
            </div>

            <div class="flex shrink-0 items-center gap-2">
                @if ($account)
                    <x-ui.badge variant="{{ $enabled ? 'success' : 'muted' }}" :dot="$enabled">
                        {{ $enabled ? 'Sending' : 'Off' }}
                    </x-ui.badge>
                    @if ($canEdit)
                        <x-ui.button size="sm" variant="outline" icon="check-circle" wire:click="checkCredentials">Check credentials</x-ui.button>
                    @endif
                @endif
            </div>
        </div>

        <div class="mt-4 grid gap-3 sm:grid-cols-3">
            @foreach (['android' => 'Android devices', 'ios' => 'iPhones', 'web' => 'Browsers'] as $platform => $label)
                <div class="rounded-lg border border-border px-4 py-3">
                    <p class="text-xs text-muted-foreground">{{ $label }}</p>
                    <p class="tabular mt-0.5 text-xl font-semibold">{{ platform_number($tokenCounts[$platform] ?? 0) }}</p>
                </div>
            @endforeach
        </div>
    </x-ui.card>

    <form wire:submit="save" class="space-y-4 md:space-y-6" novalidate>

        {{-- ---- server credentials --------------------------------------- --}}
        <x-ui.card
            title="Sending (mobile and web)"
            description="Firebase console → Project settings → Service accounts → Generate new private key. Upload the .json file it downloads."
        >
            @if ($account)
                <div class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-lg bg-muted px-4 py-3">
                    <p class="text-sm">
                        <x-ui.icon name="lock" size="sm" class="-mt-0.5 mr-1 inline text-muted-foreground" />
                        A key file is saved for <span class="font-medium">{{ $account['project_id'] }}</span>. Upload another to replace it.
                    </p>
                    @if ($canEdit)
                        <x-ui.button
                            size="xs"
                            variant="ghost"
                            class="text-destructive"
                            type="button"
                            wire:click="removeServiceAccount"
                            wire:confirm="Remove the Firebase credentials? Push notifications stop immediately."
                        >Remove</x-ui.button>
                    @endif
                </div>
            @endif

            <div class="space-y-4">
                <div class="space-y-1.5">
                    <label for="sa-file" class="block text-sm font-medium">Service account file</label>
                    <input
                        id="sa-file"
                        type="file"
                        accept="application/json,.json"
                        wire:model="serviceAccountFile"
                        @disabled(! $canEdit)
                        class="block w-full text-sm file:mr-3 file:rounded-md file:border-0 file:bg-primary file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-primary-foreground hover:file:bg-primary/90"
                    >
                    @error('serviceAccountFile') <p class="text-xs text-destructive">{{ $message }}</p> @enderror
                    <p class="text-xs text-muted-foreground">Stored encrypted. It is never shown again, and never sent to the browser.</p>
                </div>

                <details class="rounded-lg border border-border px-4 py-3">
                    <summary class="cursor-pointer text-sm font-medium">Or paste the JSON instead</summary>
                    <div class="mt-3">
                        <x-ui.textarea
                            rows="5"
                            wire:model="serviceAccountJson"
                            placeholder='{ "type": "service_account", "project_id": "…", "private_key": "-----BEGIN PRIVATE KEY-----…" }'
                            :error="$errors->first('serviceAccountJson')"
                            :disabled="! $canEdit"
                        />
                    </div>
                </details>

                <x-ui.toggle
                    size="lg"
                    label="Push notifications"
                    description="Send to phones and browsers. Needs the key file above."
                    wire:model="enabled"
                    :checked="$enabled"
                    :disabled="! $canEdit"
                />
            </div>
        </x-ui.card>

        {{-- ---- web push -------------------------------------------------- --}}
        <x-ui.card
            title="Receiving in the browser"
            description="Firebase console → Project settings → General → Your apps → Web app → Config, plus the Web push certificate on the Cloud Messaging tab. These values are public: the browser needs them."
        >
            <div class="grid gap-4 sm:grid-cols-2">
                @foreach ($webFields as $field => $sdkKey)
                    <x-ui.input
                        :label="$sdkKey"
                        wire:model="web.{{ $field }}"
                        :error="$errors->first('web.'.$field)"
                        :disabled="! $canEdit"
                    />
                @endforeach
            </div>

            <div class="mt-4 space-y-4">
                <x-ui.input
                    label="Web push certificate (VAPID key pair)"
                    wire:model="vapidKey"
                    placeholder="BKagOny0KF_2pCJQ3m…"
                    hint="Cloud Messaging tab → Web configuration → Web push certificates → Generate key pair. Copy the public key."
                    :error="$errors->first('vapidKey')"
                    :disabled="! $canEdit"
                />

                <x-ui.toggle
                    size="lg"
                    label="Browser notifications"
                    description="Members see a “Turn on notifications” button in the web app. Needs all the values above and an https address."
                    wire:model="webEnabled"
                    :checked="$webEnabled"
                    :disabled="! $canEdit"
                />

                @if (! $webReady)
                    <p class="rounded-md bg-muted px-3 py-2 text-xs text-muted-foreground">
                        Fill in every field above to switch browser notifications on.
                    </p>
                @endif
            </div>
        </x-ui.card>

        @if ($canEdit)
            <div class="flex justify-end">
                <x-ui.button type="submit">Save push settings</x-ui.button>
            </div>
        @endif
    </form>

    {{-- ---- test ---------------------------------------------------------- --}}
    @if ($canEdit && $account)
        <x-ui.card title="Send a test" description="Goes to every device that member has allowed notifications on. It is a real notification, so use your own account.">
            <div class="flex flex-wrap items-end gap-3">
                <div class="min-w-0 flex-1 sm:max-w-sm">
                    <x-ui.input
                        label="Member email"
                        type="email"
                        wire:model="testEmail"
                        placeholder="you@example.com"
                        :error="$errors->first('testEmail')"
                    />
                </div>
                <x-ui.button variant="outline" icon="paper-airplane" wire:click="sendTest">Send test</x-ui.button>
            </div>
        </x-ui.card>
    @endif
</div>
