@props([
    'step' => null,
    'target' => '',
    'confirm' => 'confirmStep',
    'reasonCode' => '',
    'durationHours' => null,
    'notifyUser' => true,
])

{{-- The enforcement form in a dialog, used by any screen with AppliesEnforcement. --}}

@php
    use App\Enums\LadderStep;
    use App\Enums\ReasonCode;

    $step = $step instanceof LadderStep ? $step : LadderStep::tryFrom((string) $step);
@endphp

<x-ui.dialog :show="$step !== null" close="cancelStep" size="lg">
    @if ($step)
        <div class="space-y-4 p-6">
            <div>
                <h2 class="text-lg font-semibold">{{ $step->label() }}</h2>
                <p class="mt-1 text-sm text-muted-foreground">{{ $target }}</p>
            </div>

            <x-ui.select
                label="Reason"
                required
                placeholder="Choose a reason…"
                wire:model.live="reasonCode"
                :selected="$reasonCode"
                :grouped="collect(ReasonCode::grouped())->map(fn ($codes) => collect($codes)->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all())->all()"
                :error="$errors->first('reasonCode')"
            />

            @if ($step->requiresDuration())
                <x-ui.select
                    label="Duration"
                    required
                    wire:model="durationHours"
                    :selected="$durationHours"
                    :options="['24' => '24 hours', '168' => '7 days', '720' => '30 days']"
                    :error="$errors->first('durationHours')"
                />
            @endif

            @if ($step->requiresReviewDate())
                <x-ui.input
                    type="datetime-local"
                    label="Review due"
                    required
                    wire:model="reviewDueAt"
                    hint="A shadow ban is invisible to the member, so a reviewer must revisit it by this date."
                    :error="$errors->first('reviewDueAt')"
                />
            @endif

            @if ($step === LadderStep::FeatureLimit)
                <div class="space-y-2">
                    <p class="text-sm font-medium">Features to limit <span class="text-destructive">*</span></p>
                    @foreach (config('platform.enforcement.feature_limit_options') as $key => $label)
                        <x-ui.checkbox :label="$label" wire:model="limitedFeatures" value="{{ $key }}" />
                    @endforeach
                    @error('limitedFeatures') <p class="text-xs text-destructive">{{ $message }}</p> @enderror
                </div>
            @endif

            <x-ui.textarea
                label="Internal note"
                rows="3"
                wire:model="note"
                placeholder="What did you see, and why does it break the policy?"
                hint="Staff only. Recorded in the audit log and shown on any appeal."
                :error="$errors->first('note')"
                :required="$step->isDestructive()"
            />

            <x-ui.toggle
                size="lg"
                label="Notify the member"
                description="Sends them the reason and the rule it relates to."
                wire:model="notifyUser"
                :checked="$notifyUser"
            />

            <div class="flex flex-col-reverse gap-2 pt-2 sm:flex-row sm:justify-end">
                <x-ui.button variant="ghost" wire:click="cancelStep">Cancel</x-ui.button>
                <button
                    type="button"
                    wire:click="{{ $confirm }}"
                    wire:loading.attr="disabled"
                    wire:target="{{ $confirm }}"
                    class="inline-flex h-9 items-center justify-center gap-2 rounded-md px-4 text-sm font-medium transition-colors disabled:opacity-60 {{ $step->buttonClasses() }}"
                >Confirm {{ strtolower($step->label()) }}</button>
            </div>
        </div>
    @endif
</x-ui.dialog>
