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
