@php
    use App\Enums\VerificationStatus;

    $status = $me->verification_status;
    $pending = in_array($latest?->status, [VerificationStatus::Pending, VerificationStatus::InReview, VerificationStatus::Escalated], true);
@endphp

<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-bold tracking-tight">Photo verification</h1>
        <p class="mt-1 text-muted-foreground">Prove your photos are really you, and get the blue badge.</p>
    </div>

    @if ($status === VerificationStatus::Approved)
        <div class="flex items-start gap-4 rounded-3xl border border-success/30 bg-success-subtle p-6 text-success-subtle-foreground">
            <x-ui.icon name="check-badge" size="xl" class="shrink-0" />
            <div>
                <p class="text-lg font-semibold">You are verified</p>
                <p class="mt-1 text-sm">Your badge shows on your profile. People who only want verified matches can now see you.</p>
            </div>
        </div>
    @elseif ($pending)
        <div class="flex items-start gap-4 rounded-3xl border border-info/30 bg-info-subtle p-6 text-info-subtle-foreground">
            <x-ui.icon name="clock" size="xl" class="shrink-0" />
            <div>
                <p class="text-lg font-semibold">Your selfie is with our team</p>
                <p class="mt-1 text-sm">Sent {{ $latest->submitted_at?->diffForHumans() }}. A reviewer compares it with your profile photos, usually within a day. You can keep using the app in the meantime.</p>
            </div>
        </div>
    @else
        @if ($latest?->status === VerificationStatus::Rejected)
            <div class="flex items-start gap-4 rounded-3xl border border-warning/40 bg-warning-subtle p-5 text-warning-subtle-foreground">
                <x-ui.icon name="warning" size="lg" class="shrink-0" />
                <div class="text-sm">
                    <p class="font-semibold">Your last attempt was not approved</p>
                    <p class="mt-1">{{ $rejectionText ?? 'The selfie did not clearly match your profile photos.' }} You can try again below.</p>
                </div>
            </div>
        @endif

        <div class="grid gap-6 md:grid-cols-2">
            <div class="rounded-3xl border border-border bg-card p-6">
                <p class="text-sm font-semibold uppercase tracking-wide text-muted-foreground">Step 1</p>
                <p class="mt-1 text-lg font-semibold">Copy this code</p>
                <p class="mt-1 text-sm text-muted-foreground">Write it on paper, or show it on another screen, and hold it next to your face.</p>
                <p class="mt-5 rounded-2xl bg-primary-subtle py-6 text-center font-mono text-5xl font-bold tracking-[0.3em] text-primary-subtle-foreground">{{ $gestureCode }}</p>
                <ul class="mt-5 space-y-2 text-sm text-muted-foreground">
                    <li class="flex gap-2"><x-ui.icon name="check" size="sm" class="mt-0.5 text-success" /> Face the camera in good light</li>
                    <li class="flex gap-2"><x-ui.icon name="check" size="sm" class="mt-0.5 text-success" /> No sunglasses, filters or hats</li>
                    <li class="flex gap-2"><x-ui.icon name="check" size="sm" class="mt-0.5 text-success" /> Just you in the photo</li>
                </ul>
            </div>

            <form wire:submit="submit" class="flex flex-col rounded-3xl border border-border bg-card p-6">
                <p class="text-sm font-semibold uppercase tracking-wide text-muted-foreground">Step 2</p>
                <p class="mt-1 text-lg font-semibold">Take your selfie</p>

                <label class="mt-4 flex flex-1 cursor-pointer flex-col items-center justify-center overflow-hidden rounded-2xl border-2 border-dashed border-border bg-muted/40 p-4 text-center transition hover:border-primary">
                    @if ($selfie && method_exists($selfie, 'isPreviewable') && $selfie->isPreviewable())
                        <img src="{{ $selfie->temporaryUrl() }}" alt="Your selfie" class="max-h-56 rounded-xl object-contain">
                        <span class="mt-2 text-xs text-muted-foreground">Tap to retake</span>
                    @else
                        <span wire:loading.remove wire:target="selfie" class="flex flex-col items-center gap-2 text-muted-foreground">
                            <x-ui.icon name="camera" size="xl" />
                            <span class="text-sm font-medium">Open camera or choose a photo</span>
                        </span>
                        <span wire:loading wire:target="selfie" class="text-sm">Loading…</span>
                    @endif
                    {{-- capture="user" opens the front camera directly on phones. --}}
                    <input type="file" wire:model="selfie" accept="image/*" capture="user" class="sr-only">
                </label>

                @error('selfie') <p class="mt-2 text-sm text-destructive">{{ $message }}</p> @enderror

                <x-ui.button type="submit" size="lg" class="mt-4 h-12 w-full" :disabled="$attemptsLeft === 0" wire:loading.attr="disabled" wire:target="submit,selfie">
                    Send for review
                </x-ui.button>
                <p class="mt-2 text-center text-xs text-muted-foreground">{{ $attemptsLeft }} {{ Str::plural('attempt', $attemptsLeft) }} left</p>
            </form>
        </div>
    @endif

    <div class="rounded-3xl border border-border bg-card p-5 text-sm text-muted-foreground">
        <p class="flex items-center gap-2 font-semibold text-foreground"><x-ui.icon name="lock" size="sm" /> Who sees your selfie</p>
        <p class="mt-1.5">Only our review team. It is stored separately from your profile, is never shown to other members, and every time a reviewer opens it is recorded.</p>
    </div>
</div>
