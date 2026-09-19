<x-layouts.staff-auth title="Reset password" subheading="Enter your staff email and we will send you a link to choose a new password.">
    <form method="POST" action="{{ route('password.email') }}" class="space-y-4" novalidate>
        @csrf

        <x-ui.input
            name="email"
            type="email"
            label="Email"
            icon="user-circle"
            placeholder="name@company.com"
            :value="old('email')"
            :error="$errors->first('email')"
            required
            autofocus
            autocomplete="username"
        />

        <x-ui.button type="submit" class="w-full" size="lg">Send reset link</x-ui.button>
    </form>

    <p class="mt-6 text-center text-sm text-muted-foreground">
        <a href="{{ route('login') }}" class="font-medium text-primary hover:underline">Back to sign in</a>
    </p>
</x-layouts.staff-auth>
