<x-layouts.member-auth title="Forgot password">
    <h1 class="text-3xl font-bold tracking-tight">Forgot your password?</h1>
    <p class="mt-2 text-muted-foreground">Enter the email you signed up with and we will send you a link to choose a new one.</p>

    @if (session('status'))
        <div class="mt-6 flex items-start gap-2 rounded-xl border border-success/30 bg-success-subtle p-4 text-sm text-success-subtle-foreground" role="status">
            <x-ui.icon name="check-circle" size="sm" class="mt-px shrink-0" />
            <span>{{ session('status') }}</span>
        </div>
    @endif

    <form method="POST" action="{{ route('member.password.email') }}" class="mt-8 space-y-4" novalidate>
        @csrf
        <x-ui.input name="email" type="email" label="Email" :value="old('email')" :error="$errors->first('email')" autocomplete="email" autofocus required />
        <x-ui.button type="submit" size="lg" class="h-12 w-full text-base">Send reset link</x-ui.button>
    </form>

    <p class="mt-6 text-center text-sm text-muted-foreground">
        Remembered it? <a href="{{ route('member.login') }}" class="font-semibold text-primary hover:underline">Sign in</a>
    </p>
</x-layouts.member-auth>
