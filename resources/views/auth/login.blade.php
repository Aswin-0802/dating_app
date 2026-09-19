<x-layouts.staff-auth title="Sign in" subheading="Sign in with your staff account.">
    <form method="POST" action="{{ route('login') }}" class="space-y-4" novalidate>
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

        <x-ui.input
            name="password"
            type="password"
            label="Password"
            icon="lock"
            :error="$errors->first('password')"
            required
            autocomplete="current-password"
        />

        <div class="flex items-center justify-between gap-3">
            <x-ui.checkbox name="remember" value="1" label="Keep me signed in" />
            <a href="{{ route('password.request') }}" class="text-sm font-medium text-primary hover:underline">Forgot password?</a>
        </div>

        <x-ui.button type="submit" class="w-full" size="lg">
            Sign in
        </x-ui.button>
    </form>
</x-layouts.staff-auth>
