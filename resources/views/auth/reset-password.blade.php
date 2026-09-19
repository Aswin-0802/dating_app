<x-layouts.staff-auth title="Choose a new password" subheading="At least 8 characters, with letters and numbers.">
    <form method="POST" action="{{ route('password.update') }}" class="space-y-4" novalidate>
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">

        <x-ui.input
            name="email"
            type="email"
            label="Email"
            icon="user-circle"
            :value="old('email', $email)"
            :error="$errors->first('email')"
            required
            autocomplete="username"
        />

        <x-ui.input
            name="password"
            type="password"
            label="New password"
            icon="lock"
            :error="$errors->first('password')"
            required
            autofocus
            autocomplete="new-password"
        />

        <x-ui.input
            name="password_confirmation"
            type="password"
            label="Repeat new password"
            icon="lock"
            required
            autocomplete="new-password"
        />

        <x-ui.button type="submit" class="w-full" size="lg">Save new password</x-ui.button>
    </form>
</x-layouts.staff-auth>
