<div>
    <div class="mb-6 flex items-center gap-2">
        @foreach ([1, 2] as $n)
            <span @class([
                'h-1.5 flex-1 rounded-full transition-colors',
                'bg-primary' => $step >= $n,
                'bg-muted' => $step < $n,
            ])></span>
        @endforeach
    </div>

    @if ($step === 1)
        <h1 class="text-3xl font-bold tracking-tight">Create your account</h1>
        <p class="mt-2 text-muted-foreground">Free, and it takes about two minutes.</p>

        <form wire:submit="next" class="mt-8 space-y-4">
            <x-ui.input label="First name" hint="This is how you appear to others." wire:model="display_name" :error="$errors->first('display_name')" autocomplete="given-name" autofocus required />
            <x-ui.input label="Email" type="email" wire:model="email" :error="$errors->first('email')" autocomplete="email" required />
            <x-ui.input label="Password" type="password" hint="At least 8 characters, with letters and numbers." wire:model="password" :error="$errors->first('password')" autocomplete="new-password" required />
            <x-ui.input label="Date of birth" type="date" :hint="'You must be '.$minAge.' or older. Only your age is shown.'" wire:model="birthdate" :error="$errors->first('birthdate')" :max="now()->subYears($minAge)->toDateString()" required />

            <x-ui.button type="submit" size="lg" class="h-12 w-full text-base">
                Continue <x-ui.icon name="arrow-right" size="sm" />
            </x-ui.button>
        </form>
    @else
        <h1 class="text-3xl font-bold tracking-tight">A little about you</h1>
        <p class="mt-2 text-muted-foreground">So we can show you the right people.</p>

        <form wire:submit="register" class="mt-8 space-y-6">
            <div>
                <p class="mb-2 text-sm font-medium">I am</p>
                <div class="grid grid-cols-2 gap-2">
                    @foreach ($genders as $value => $label)
                        <label class="flex cursor-pointer items-center justify-center rounded-xl border border-border px-3 py-3 text-sm font-medium transition has-[:checked]:border-primary has-[:checked]:bg-primary-subtle has-[:checked]:text-primary-subtle-foreground">
                            <input type="radio" wire:model="gender" value="{{ $value }}" class="sr-only">
                            {{ $label }}
                        </label>
                    @endforeach
                </div>
                @error('gender') <p class="mt-1.5 text-xs text-destructive">{{ $message }}</p> @enderror
            </div>

            <div>
                <p class="mb-2 text-sm font-medium">Show me</p>
                <div class="grid grid-cols-2 gap-2">
                    @foreach ($genders as $value => $label)
                        <label class="flex cursor-pointer items-center justify-center gap-2 rounded-xl border border-border px-3 py-3 text-sm font-medium transition has-[:checked]:border-primary has-[:checked]:bg-primary-subtle has-[:checked]:text-primary-subtle-foreground">
                            <input type="checkbox" wire:model="interested_in" value="{{ $value }}" class="sr-only">
                            {{ $label }}
                        </label>
                    @endforeach
                </div>
                @error('interested_in') <p class="mt-1.5 text-xs text-destructive">{{ $message }}</p> @enderror
            </div>

            <x-ui.select
                label="City"
                placeholder="Choose your city"
                wire:model="city_id"
                :grouped="$this->cities"
                :error="$errors->first('city_id')"
            />

            <label class="flex cursor-pointer items-start gap-3 text-sm">
                <input type="checkbox" wire:model="terms" class="mt-0.5 size-4 accent-[var(--primary)]">
                <span class="text-muted-foreground">
                    I am {{ $minAge }} or older and agree to the
                    <a href="{{ route('site.terms') }}" target="_blank" class="font-medium text-foreground underline">terms</a>
                    and
                    <a href="{{ route('site.privacy') }}" target="_blank" class="font-medium text-foreground underline">privacy policy</a>.
                </span>
            </label>
            @error('terms') <p class="-mt-4 text-xs text-destructive">{{ $message }}</p> @enderror

            @foreach (['display_name', 'email', 'password', 'birthdate'] as $field)
                @error($field) <p class="text-xs text-destructive">{{ $message }}</p> @enderror
            @endforeach

            <div class="flex gap-2">
                <x-ui.button variant="outline" size="lg" class="h-12" wire:click="back">Back</x-ui.button>
                <x-ui.button type="submit" size="lg" class="h-12 flex-1 text-base" wire:loading.attr="disabled" wire:target="register">
                    <span wire:loading.remove wire:target="register">Create my account</span>
                    <span wire:loading wire:target="register">Creating…</span>
                </x-ui.button>
            </div>
        </form>
    @endif

    <p class="mt-6 text-center text-sm text-muted-foreground">
        Already have an account?
        <a href="{{ route('member.login') }}" class="font-semibold text-primary hover:underline">Sign in</a>
    </p>
</div>
