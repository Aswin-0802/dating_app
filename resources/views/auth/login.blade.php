@php
    $theme = request()->cookie('veyra_theme', App\Support\Branding::themeMode());
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => $theme === 'dark']) data-theme-default="{{ App\Support\Branding::themeMode() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in · {{ App\Support\Branding::name() }}</title>


    <script>
        (function () {
            var theme = '{{ $theme }}';
            document.documentElement.classList.toggle(
                'dark',
                theme === 'dark' || (theme === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches)
            );
        })();
    </script>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <x-brand.head />
</head>

<body class="min-h-screen bg-background font-sans text-foreground antialiased">
    <div class="grid min-h-screen lg:grid-cols-2">
        {{-- Form --}}
        <div class="flex items-center justify-center p-6 sm:p-10">
            <div class="w-full max-w-sm">
                <div class="mb-8 flex items-center gap-2.5">
                    <x-brand.mark variant="admin" />
                    <div>
                        <p class="text-base font-semibold leading-tight">{{ App\Support\Branding::name() }}</p>
                        <p class="text-xs leading-tight text-muted-foreground">{{ App\Support\Branding::tagline() }}</p>
                    </div>
                </div>

                <div class="mb-6 space-y-1">
                    <h1 class="text-2xl font-semibold tracking-tight">Sign in</h1>
                    <p class="text-sm text-muted-foreground">
                        Access is logged. Only use this console for authorised work.
                    </p>
                </div>

                @if (session('error'))
                    <div class="mb-4 flex items-start gap-2 rounded-md border border-destructive/30 bg-destructive-subtle p-3 text-sm text-destructive">
                        <x-ui.icon name="warning" size="sm" class="mt-px shrink-0" />
                        <span>{{ session('error') }}</span>
                    </div>
                @endif

                <form method="POST" action="{{ route('login') }}" class="space-y-4">
                    @csrf

                    <x-ui.input
                        name="email"
                        type="email"
                        label="Email"
                        icon="user-circle"
                        placeholder="you@veyra.test"
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
                        placeholder="••••••••"
                        :error="$errors->first('password')"
                        required
                        autocomplete="current-password"
                    />

                    <div class="flex items-center justify-between">
                        <x-ui.checkbox name="remember" value="1" label="Keep me signed in" />
                    </div>

                    <x-ui.button type="submit" class="w-full" size="lg">
                        Sign in
                    </x-ui.button>
                </form>

                @if (app()->environment('local'))
                    <div class="mt-6 rounded-md border border-border bg-muted/40 p-3">
                        <p class="mb-1.5 text-xs font-medium text-foreground">Demo accounts</p>
                        <dl class="space-y-0.5 text-xs text-muted-foreground">
                            <div class="flex justify-between gap-2">
                                <dt>admin@veyra.test</dt><dd class="font-mono">password</dd>
                            </div>
                            <div class="flex justify-between gap-2">
                                <dt>lead@veyra.test</dt><dd class="font-mono">password</dd>
                            </div>
                            <div class="flex justify-between gap-2">
                                <dt>mod1@veyra.test</dt><dd class="font-mono">password</dd>
                            </div>
                        </dl>
                    </div>
                @endif
            </div>
        </div>

        {{-- Brand panel. Hidden below lg so the form gets the whole viewport. --}}
        <div class="relative hidden overflow-hidden bg-sidebar lg:block">
            @if ($loginImage = App\Support\Branding::loginImageUrl())
                {{-- A buyer's own artwork, darkened at the foot so the quote
                     stays legible whatever the image is. --}}
                <img src="{{ $loginImage }}" alt="" class="absolute inset-0 size-full object-cover">
                <div class="absolute inset-0 bg-gradient-to-t from-black/75 via-black/20 to-transparent"></div>
            @endif

            <div class="absolute inset-0 bg-gradient-to-br from-primary/25 via-transparent to-accent/25"></div>

            <div class="absolute -right-24 -top-24 size-96 rounded-full bg-primary/20 blur-3xl"></div>
            <div class="absolute -bottom-32 -left-16 size-96 rounded-full bg-accent/20 blur-3xl"></div>

            <div class="relative flex h-full flex-col justify-end p-12">
                <blockquote class="max-w-md space-y-4">
                    <p class="text-2xl font-medium leading-snug text-sidebar-foreground">
                        Every decision here affects a real person's safety — and another real
                        person's account. Both deserve a record.
                    </p>
                    <footer class="text-sm text-sidebar-muted-foreground">
                        {{ App\Support\Branding::name() }} Trust &amp; Safety operating principles
                    </footer>
                </blockquote>
            </div>
        </div>
    </div>
</body>
</html>
