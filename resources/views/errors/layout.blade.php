@php
    use App\Support\Branding;

    $isStaffArea = request()->is('admin', 'admin/*');
    $home = $isStaffArea ? url('/admin') : url('/');
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('title') · {{ Branding::name() }}</title>
    <script>
        (function () {
            var m = document.cookie.match(/(?:^|; )veyra_theme=([^;]*)/);
            var t = m ? decodeURIComponent(m[1]) : 'system';
            document.documentElement.classList.toggle('dark', t === 'dark' || (t === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches));
        })();
    </script>
    @vite(['resources/css/app.css'])
    {!! Branding::faviconTag() !!}
    {!! Branding::styleTag() !!}
</head>
<body class="min-h-screen bg-background font-sans text-foreground antialiased">
    <main class="flex min-h-screen flex-col items-center justify-center px-6 py-16 text-center">
        <a href="{{ $home }}" class="mb-10 flex items-center gap-2.5">
            <x-brand.mark :variant="$isStaffArea ? 'admin' : 'web'" size="sm" />
            <span class="text-base font-semibold tracking-tight">{{ Branding::name() }}</span>
        </a>

        <p class="text-sm font-semibold text-primary">@yield('code')</p>
        <h1 class="mt-2 text-3xl font-bold tracking-tight sm:text-4xl">@yield('heading')</h1>
        <p class="mt-3 max-w-md text-muted-foreground">@yield('message')</p>

        <div class="mt-8 flex flex-col gap-3 sm:flex-row">
            @hasSection('actions')
                @yield('actions')
            @else
                <a href="{{ $home }}" class="inline-flex h-10 items-center justify-center rounded-lg bg-primary px-5 text-sm font-medium text-primary-foreground shadow-sm transition hover:bg-primary/90">
                    {{ $isStaffArea ? 'Back to the dashboard' : 'Go to the home page' }}
                </a>
                <button type="button" onclick="history.back()" class="inline-flex h-10 items-center justify-center rounded-lg border border-border bg-card px-5 text-sm font-medium transition hover:bg-muted">
                    Go back
                </button>
            @endif
        </div>

        @if ($support = Branding::get('brand.support_email'))
            <p class="mt-10 text-sm text-muted-foreground">
                Need help? <a href="mailto:{{ $support }}" class="font-medium text-primary hover:underline">{{ $support }}</a>
            </p>
        @endif
    </main>
</body>
</html>
