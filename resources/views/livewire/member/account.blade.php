<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-bold tracking-tight">Account &amp; privacy</h1>
        <p class="mt-1 text-muted-foreground">Signed in as {{ $me->email }}</p>
    </div>

    <form wire:submit="updateEmail">
        <x-ui.card title="Email address">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.input label="Email" type="email" wire:model="email" :error="$errors->first('email')" autocomplete="email" />
                <x-ui.input label="Your password" type="password" hint="To confirm it is you." wire:model="emailPassword" :error="$errors->first('emailPassword')" autocomplete="current-password" />
            </div>
            <div class="mt-4 flex justify-end">
                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="updateEmail">Update email</x-ui.button>
            </div>
        </x-ui.card>
    </form>

    <form wire:submit="updatePassword">
        <x-ui.card title="Password" description="Changing it signs you out of the app on your other devices.">
            <div class="grid gap-4 sm:grid-cols-3">
                <x-ui.input label="Current password" type="password" wire:model="currentPassword" :error="$errors->first('currentPassword')" autocomplete="current-password" />
                <x-ui.input label="New password" type="password" wire:model="newPassword" :error="$errors->first('newPassword')" autocomplete="new-password" />
                <x-ui.input label="Repeat new password" type="password" wire:model="newPassword_confirmation" autocomplete="new-password" />
            </div>
            <div class="mt-4 flex justify-end">
                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="updatePassword">Change password</x-ui.button>
            </div>
        </x-ui.card>
    </form>

    {{-- ---- notifications ---------------------------------------------- --}}
    @if ($pushEnabled)
        <x-ui.card title="Notifications" description="Get a notification when you match, when somebody messages you, and before your plan runs out.">
            <div
                x-data="{
                    busy: false,
                    error: '',
                    done: false,
                    config: @js($pushConfig),
                    vapid: @js($vapidKey),

                    async enable() {
                        this.error = '';

                        if (!('Notification' in window) || !('serviceWorker' in navigator)) {
                            this.error = 'This browser cannot show notifications.';
                            return;
                        }

                        this.busy = true;

                        try {
                            const permission = await Notification.requestPermission();

                            if (permission !== 'granted') {
                                this.error = permission === 'denied'
                                    ? 'Your browser is blocking notifications for this site. Allow them in the address bar, then try again.'
                                    : 'Notifications were not allowed.';
                                return;
                            }

                            await this.loadSdk();

                            if (!firebase.apps.length) {
                                firebase.initializeApp(this.config);
                            }

                            const registration = await navigator.serviceWorker.register('/firebase-messaging-sw.js');
                            const token = await firebase.messaging().getToken({
                                vapidKey: this.vapid,
                                serviceWorkerRegistration: registration,
                            });

                            if (!token) {
                                this.error = 'The browser did not return a notification token. Try again.';
                                return;
                            }

                            const response = await fetch(@js(route('member.push.token.store')), {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '',
                                    'Accept': 'application/json',
                                },
                                body: JSON.stringify({ token }),
                            });

                            if (!response.ok) {
                                this.error = 'We could not save this device. Please try again.';
                                return;
                            }

                            this.done = true;
                            $wire.$refresh();
                        } catch (e) {
                            this.error = e?.message ?? 'Something went wrong turning notifications on.';
                        } finally {
                            this.busy = false;
                        }
                    },

                    loadSdk() {
                        if (window.firebase?.messaging) {
                            return Promise.resolve();
                        }

                        const load = (src) => new Promise((resolve, reject) => {
                            const tag = document.createElement('script');
                            tag.src = src;
                            tag.onload = resolve;
                            tag.onerror = () => reject(new Error('Could not load the notification library.'));
                            document.head.appendChild(tag);
                        });

                        return load('https://www.gstatic.com/firebasejs/12.19.0/firebase-app-compat.js')
                            .then(() => load('https://www.gstatic.com/firebasejs/12.19.0/firebase-messaging-compat.js'));
                    },
                }"
                class="space-y-4"
            >
                @if ($devices->isEmpty())
                    <p class="text-sm text-muted-foreground">
                        Notifications are off. Turning them on lets us reach you when the app is closed.
                    </p>
                @else
                    <ul class="divide-y divide-border rounded-2xl border border-border">
                        @foreach ($devices as $device)
                            <li class="flex items-center justify-between gap-3 px-4 py-3 text-sm" wire:key="device-{{ $device->id }}">
                                <span class="min-w-0">
                                    <span class="block truncate font-medium">{{ $device->label ?? ucfirst($device->platform) }}</span>
                                    <span class="block text-xs text-muted-foreground">
                                        {{ $device->platform === 'web' ? 'Browser' : ucfirst($device->platform) }}
                                        @if ($device->last_used_at) · last used {{ veyra_duration($device->last_used_at) }} ago @endif
                                    </span>
                                </span>
                                <x-ui.button size="xs" variant="ghost" class="text-destructive" wire:click="forgetDevice({{ $device->id }})">
                                    Turn off
                                </x-ui.button>
                            </li>
                        @endforeach
                    </ul>
                @endif

                <div class="flex flex-wrap items-center gap-3">
                    <x-ui.button type="button" variant="outline" x-on:click="enable()" x-bind:disabled="busy">
                        <x-ui.icon name="bell" size="sm" />
                        <span x-show="!busy">{{ $devices->isEmpty() ? 'Turn on notifications' : 'Add this device' }}</span>
                        <span x-show="busy" x-cloak>Just a moment…</span>
                    </x-ui.button>

                    <p x-show="done" x-cloak class="text-sm text-success">This device is set up.</p>
                </div>

                <p x-show="error" x-cloak x-text="error" class="text-sm text-destructive"></p>
            </div>
        </x-ui.card>
    @endif

    <x-ui.card title="Blocked people" description="They cannot see you, and you cannot see them.">
        @if ($blocked->isEmpty())
            <p class="text-sm text-muted-foreground">You have not blocked anyone.</p>
        @else
            <ul class="divide-y divide-border">
                @foreach ($blocked as $person)
                    <li class="flex items-center gap-3 py-2.5" wire:key="blocked-{{ $person->uuid }}">
                        <x-ui.avatar :src="$person->primaryPhoto?->thumb_url" :name="$person->display_name" size="sm" />
                        <span class="min-w-0 flex-1 truncate text-sm font-medium">{{ $person->display_name }}</span>
                        <x-ui.button variant="outline" size="sm" wire:click="unblock('{{ $person->uuid }}')" wire:confirm="Unblock {{ $person->display_name }}? You may see each other in discovery again.">Unblock</x-ui.button>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.card>

    <div x-data="{ confirming: false }" class="rounded-3xl border border-destructive/30 bg-card p-5 sm:p-6">
        <h2 class="font-semibold">Deactivate account</h2>
        <p class="mt-1 text-sm text-muted-foreground">Your profile disappears from discovery straight away and you are signed out everywhere. Your matches will no longer be able to message you.</p>

        <x-ui.button variant="outline" class="mt-4" x-show="! confirming" @click="confirming = true">Deactivate my account</x-ui.button>

        <form wire:submit="deactivate" x-show="confirming" x-cloak class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-start">
            <div class="flex-1">
                <x-ui.input type="password" placeholder="Your password" wire:model="deactivatePassword" :error="$errors->first('deactivatePassword')" autocomplete="current-password" />
            </div>
            <x-ui.button type="submit" variant="destructive">Deactivate</x-ui.button>
            <x-ui.button variant="ghost" @click="confirming = false">Cancel</x-ui.button>
        </form>
    </div>
</div>
