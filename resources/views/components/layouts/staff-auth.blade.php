@props([
    'title' => 'Sign in',
    'heading' => null,
    'subheading' => null,
])

@php
    use App\Support\Branding;

    $theme = request()->cookie('veyra_theme', Branding::themeMode());
    // The buyer's own sign-in artwork when uploaded; stock photography otherwise.
    $panelImage = Branding::loginImageUrl() ?? asset('images/site/couple-silhouette.jpg');
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => $theme === 'dark']) data-theme-default="{{ Branding::themeMode() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $title }} · {{ Branding::name() }}</title>

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
        <div class="flex items-center justify-center p-6 sm:p-10">
            <div class="w-full max-w-sm">
                <div class="mb-8 flex items-center gap-2.5">
                    <x-brand.mark variant="admin" />
                    <div>
                        <p class="text-base font-semibold leading-tight">{{ Branding::name() }}</p>
                        <p class="text-xs leading-tight text-muted-foreground">{{ Branding::tagline() }}</p>
                    </div>
                </div>

                <div class="mb-6 space-y-1">
                    <h1 class="text-2xl font-semibold tracking-tight">{{ $heading ?? $title }}</h1>
                    @if ($subheading)
                        <p class="text-sm text-muted-foreground">{{ $subheading }}</p>
                    @endif
                </div>

                @if (session('status'))
                    <div class="mb-4 flex items-start gap-2 rounded-md border border-success/30 bg-success-subtle p-3 text-sm text-success-subtle-foreground" role="status">
                        <x-ui.icon name="check-circle" size="sm" class="mt-px shrink-0" />
                        <span>{{ session('status') }}</span>
                    </div>
                @endif

                @if (session('error'))
                    <div class="mb-4 flex items-start gap-2 rounded-md border border-destructive/30 bg-destructive-subtle p-3 text-sm text-destructive-subtle-foreground" role="alert">
                        <x-ui.icon name="warning" size="sm" class="mt-px shrink-0" />
                        <span>{{ session('error') }}</span>
                    </div>
                @endif

                {{ $slot }}
            </div>
        </div>

        <div class="relative hidden overflow-hidden bg-sidebar lg:block">
            <img src="{{ $panelImage }}" alt="" class="absolute inset-0 size-full object-cover">
            <div class="absolute inset-0 bg-gradient-to-t from-black/85 via-black/35 to-black/10"></div>

            <div class="relative flex h-full flex-col justify-end p-12 text-white">
                <p class="max-w-md text-2xl font-semibold leading-snug">
                    {{ Branding::name() }} {{ Branding::tagline() ?: 'Console' }}
                </p>
                <p class="mt-2 max-w-md text-sm text-white/75">
                    Access to this console is restricted to authorised staff and every action is recorded.
                </p>
            </div>
        </div>
    </div>
</body>
</html>
