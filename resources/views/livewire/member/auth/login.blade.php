<div>
    <h1 class="text-3xl font-bold tracking-tight">Welcome back</h1>
    <p class="mt-2 text-muted-foreground">Sign in to see who is waiting for you.</p>

    <form wire:submit="login" class="mt-8 space-y-4" novalidate>
        <x-ui.input label="Email" type="email" wire:model="email" :error="$errors->first('email')" autocomplete="email" autofocus required />
        <x-ui.input label="Password" type="password" wire:model="password" :error="$errors->first('password')" autocomplete="current-password" required />

        <div class="flex items-center justify-between gap-3">
            <label class="flex cursor-pointer items-center gap-2.5 text-sm">
                <input type="checkbox" wire:model="remember" class="size-4 accent-[var(--primary)]">
                Keep me signed in
            </label>
            <a href="{{ route('member.password.request') }}" class="text-sm font-medium text-primary hover:underline">Forgot password?</a>
        </div>

        <x-ui.button type="submit" size="lg" class="h-12 w-full text-base" wire:loading.attr="disabled" wire:target="login">
            <span wire:loading.remove wire:target="login">Sign in</span>
            <span wire:loading wire:target="login">Signing in…</span>
        </x-ui.button>
    </form>

    <p class="mt-6 text-center text-sm text-muted-foreground">
        New here?
        <a href="{{ route('member.register') }}" class="font-semibold text-primary hover:underline">Create a free account</a>
    </p>
</div>
