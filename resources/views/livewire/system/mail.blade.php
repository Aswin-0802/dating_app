<div class="grid gap-4 md:gap-6 lg:grid-cols-[1fr_320px]">

    <x-ui.card title="Outgoing mail" description="Stored in settings rather than .env, so it can be changed without a deploy.">
        @unless ($canEdit)
            <div class="mb-4 flex items-start gap-3 rounded-lg border border-border bg-muted/40 px-3 py-2.5">
                <x-ui.icon name="lock" size="sm" class="mt-0.5 shrink-0 text-muted-foreground" />
                <p class="text-sm text-muted-foreground">You can read these settings but not change them.</p>
            </div>
        @endunless

        <div class="grid gap-4 sm:grid-cols-2">
            @foreach ($settings as $setting)
                <div @class(['sm:col-span-2' => in_array($setting->key, ['mail.from_address', 'mail.from_name'], true)])>
                    @if ($setting->type === 'boolean')
                        <x-ui.toggle
                            size="lg"
                            :label="$setting->label ?? $setting->key"
                            :description="$setting->description"
                            wire:model="values.{{ $setting->key }}"
                            :checked="(bool) data_get($values, $setting->key)"
                            :disabled="! $canEdit"
                        />
                    @else
                        <x-ui.input
                            :label="$setting->label ?? $setting->key"
                            :hint="$setting->description"
                            :type="$setting->key === 'mail.password' ? 'password' : ($setting->type === 'number' ? 'number' : 'text')"
                            :placeholder="$setting->key === 'mail.password' ? '•••••••• (saved — leave blank to keep)' : ''"
                            wire:model="values.{{ $setting->key }}"
                            :disabled="! $canEdit"
                            :error="$errors->first('values.'.$setting->key)"
                            autocomplete="off"
                        />
                    @endif
                </div>
            @endforeach
        </div>

        @if ($canEdit)
            <div class="mt-5">
                <x-ui.button wire:click="save">Save settings</x-ui.button>
            </div>
        @endif
    </x-ui.card>

    {{-- A form that saves without ever proving the connection works is how a
         broken mailer survives until the first password reset fails. --}}
    <x-ui.card title="Send a test" description="The only way to know these settings are right is to use them.">
        <div class="space-y-3">
            <x-ui.input
                label="Send to"
                type="email"
                wire:model="testRecipient"
                :error="$errors->first('testRecipient')"
                :disabled="! $canEdit"
            />

            @if ($canEdit)
                <x-ui.button variant="outline" size="sm" icon="inbox" wire:click="sendTest">
                    Send test message
                </x-ui.button>
            @endif

            <p class="text-xs text-muted-foreground">
                With <code class="rounded bg-muted px-1">MAIL_MAILER=log</code> in the environment,
                the message is written to <code class="rounded bg-muted px-1">storage/logs/laravel.log</code>
                rather than delivered.
            </p>
        </div>
    </x-ui.card>
</div>
