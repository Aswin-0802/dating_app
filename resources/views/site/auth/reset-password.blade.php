<x-layouts.member-auth title="Choose a new password">
    <h1 class="text-3xl font-bold tracking-tight">Choose a new password</h1>
    <p class="mt-2 text-muted-foreground">At least 8 characters, with letters and numbers.</p>

    <form method="POST" action="{{ route('member.password.update') }}" class="mt-8 space-y-4" novalidate>
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">

        <x-ui.input name="email" type="email" label="Email" :value="old('email', $email)" :error="$errors->first('email')" autocomplete="email" required />
        <x-ui.input name="password" type="password" label="New password" :error="$errors->first('password')" autocomplete="new-password" autofocus required />
        <x-ui.input name="password_confirmation" type="password" label="Repeat new password" autocomplete="new-password" required />

        <x-ui.button type="submit" size="lg" class="h-12 w-full text-base">Save new password</x-ui.button>
    </form>
</x-layouts.member-auth>
