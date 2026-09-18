@props(['reportingUuid', 'categories', 'name' => 'this person'])

{{-- The report form, rendered by any component using HandlesSafety. --}}

<x-member.modal :show="$reportingUuid !== null" close="closeReport">
    <form wire:submit="submitReport" class="p-6">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h2 class="text-lg font-semibold">Report {{ $name }}</h2>
                <p class="mt-1 text-sm text-muted-foreground">They will not be told who reported them.</p>
            </div>
            <button type="button" wire:click="closeReport" class="-mr-2 -mt-1 inline-flex size-9 items-center justify-center rounded-full text-muted-foreground hover:bg-muted">
                <x-ui.icon name="x-mark" size="sm" /><span class="sr-only">Close</span>
            </button>
        </div>

        <fieldset class="mt-5 space-y-4">
            <legend class="sr-only">What happened?</legend>
            @foreach ($categories as $group => $options)
                <div>
                    <p class="mb-1.5 text-xs font-semibold uppercase tracking-wide text-muted-foreground">{{ $group }}</p>
                    <div class="grid gap-1.5">
                        @foreach ($options as $value => $label)
                            <label class="flex cursor-pointer items-center gap-3 rounded-xl border border-border px-3 py-2.5 text-sm transition has-[:checked]:border-primary has-[:checked]:bg-primary-subtle has-[:checked]:text-primary-subtle-foreground">
                                <input type="radio" wire:model="reportCategory" value="{{ $value }}" class="size-4 accent-[var(--primary)]">
                                {{ $label }}
                            </label>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </fieldset>
        @error('reportCategory') <p class="mt-2 text-sm text-destructive">{{ $message }}</p> @enderror

        <div class="mt-5">
            <x-ui.textarea label="Anything else we should know?" rows="3" wire:model="reportDetails" placeholder="Optional, but it helps our team act faster." />
        </div>

        <label class="mt-4 flex cursor-pointer items-start gap-3 text-sm">
            <input type="checkbox" wire:model="alsoBlock" class="mt-0.5 size-4 accent-[var(--primary)]">
            <span>Also block them, so you never see each other again</span>
        </label>

        <div class="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <x-ui.button variant="ghost" wire:click="closeReport">Cancel</x-ui.button>
            <x-ui.button type="submit" variant="destructive" wire:loading.attr="disabled" wire:target="submitReport">Send report</x-ui.button>
        </div>
    </form>
</x-member.modal>
