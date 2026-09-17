<div class="space-y-4 md:space-y-6">

    <div class="grid gap-4 md:gap-6 lg:grid-cols-[220px_1fr]">

        {{-- ---- group nav ---------------------------------------------- --}}
        <nav class="flex gap-1 overflow-x-auto lg:flex-col lg:overflow-visible">
            @foreach ($groups as $key => $meta)
                <button
                    type="button"
                    wire:click="switchGroup('{{ $key }}')"
                    @class([
                        'flex shrink-0 items-center justify-between gap-2 rounded-md px-3 py-2 text-sm transition-colors lg:w-full',
                        'bg-primary-subtle font-medium text-primary-subtle-foreground' => $group === $key,
                        'text-muted-foreground hover:bg-muted hover:text-foreground' => $group !== $key,
                    ])
                >
                    <span>{{ $meta['label'] }}</span>

                    @unless ($meta['editable'])
                        {{-- Read-only groups are shown rather than hidden: the
                             split between platform settings and safety policy is
                             worth being visible. --}}
                        <x-ui.icon name="lock" size="xs" class="opacity-60" />
                    @endunless
                </button>
            @endforeach
        </nav>

        {{-- ---- fields -------------------------------------------------- --}}
        <div class="space-y-4 md:space-y-6">
            @unless ($canEdit)
                <div class="flex items-start gap-3 rounded-xl border border-border bg-muted/40 px-4 py-3">
                    <x-ui.icon name="lock" size="sm" class="mt-0.5 shrink-0 text-muted-foreground" />
                    <p class="min-w-0 text-sm text-muted-foreground">
                        You can read these settings but not change them. Moderation and risk
                        thresholds are safety policy and belong to Trust &amp; Safety.
                    </p>
                </div>
            @endunless

            <x-ui.card :title="$groups[$group]['label']">
                @if ($settings->isEmpty())
                    <p class="text-sm text-muted-foreground">No settings in this group.</p>
                @else
                    <div class="space-y-5">
                        @foreach ($settings as $setting)
                            <div>
                                @if ($setting->type === 'boolean')
                                    <x-ui.toggle
                                        size="lg"
                                        :label="$setting->label ?? $setting->key"
                                        :description="$setting->description"
                                        wire:model="values.{{ $setting->key }}"
                                        :checked="(bool) ($values[$setting->key] ?? false)"
                                        :disabled="! $canEdit"
                                    />
                                @elseif ($setting->type === 'textarea')
                                    <x-ui.textarea
                                        :label="$setting->label ?? $setting->key"
                                        :hint="$setting->description"
                                        rows="3"
                                        wire:model="values.{{ $setting->key }}"
                                        :disabled="! $canEdit"
                                    >{{ $values[$setting->key] ?? '' }}</x-ui.textarea>
                                @else
                                    <x-ui.input
                                        :label="$setting->label ?? $setting->key"
                                        :hint="$setting->description"
                                        :type="$setting->type === 'number' ? 'number' : 'text'"
                                        wire:model="values.{{ $setting->key }}"
                                        :disabled="! $canEdit"
                                    />
                                @endif

                                <p class="mt-1 font-mono text-[11px] text-muted-foreground">{{ $setting->key }}</p>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-ui.card>

            {{-- ---- risk weights --------------------------------------- --}}
            @if ($group === 'risk' && $riskFactors->isNotEmpty())
                <x-ui.card
                    title="Risk factor weights"
                    description="Changing a weight affects future scores only. Existing breakdowns keep the points they were computed with, which is why a past decision stays explicable."
                >
                    <div class="space-y-2">
                        @foreach ($riskFactors as $factor)
                            <div class="flex items-center gap-3 rounded-lg border border-border px-3 py-2">
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm">{{ $factor->label }}</span>
                                    <span class="block font-mono text-[11px] text-muted-foreground">{{ $factor->key }}</span>
                                </span>

                                @if ($factor->isMitigating())
                                    <x-ui.badge variant="success" size="sm">Mitigating</x-ui.badge>
                                @endif

                                <input
                                    type="number"
                                    wire:model="riskPoints.{{ $factor->id }}"
                                    @disabled(! $canEdit)
                                    class="tabular h-8 w-20 shrink-0 rounded-md border border-input bg-card px-2 text-right text-sm disabled:opacity-60"
                                >
                            </div>
                        @endforeach
                    </div>
                </x-ui.card>
            @endif

            @if ($canEdit)
                <div class="flex items-center gap-3">
                    <x-ui.button wire:click="save">Save changes</x-ui.button>
                    <p class="text-xs text-muted-foreground">Changes take effect immediately, without a deploy.</p>
                </div>
            @endif
        </div>
    </div>
</div>
