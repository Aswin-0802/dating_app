@props([
    'title' => null,
    'breadcrumbs' => [],
])

@php
    // Read on the server so the first painted byte already carries the right
    // theme and sidebar width. Anything resolved client-side flashes.
    $theme = request()->cookie('platform_theme', App\Support\Branding::themeMode());
    $sidebar = request()->cookie('platform_sidebar', 'expanded');
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => $theme === 'dark']) data-theme-default="{{ App\Support\Branding::themeMode() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ? $title.' · ' : '' }}{{ App\Support\Branding::name() }}</title>


    <script>
        // Runs before first paint. `system` cannot be resolved server-side, so it
        // is settled here rather than after hydration.
        (function () {
            var theme = '{{ $theme }}';
            var dark = theme === 'dark' ||
                (theme === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);

            document.documentElement.classList.toggle('dark', dark);
        })();
    </script>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <x-brand.head />
    @livewireStyles
</head>

<body class="min-h-screen bg-background font-sans text-foreground antialiased">
    <div
        class="platform-shell flex min-h-screen"
        x-data="platformShell('{{ $sidebar }}')"
        data-sidebar-binding
        :data-sidebar="state"
        @platform:toggle-sidebar.window="toggle()"
    >
        @include('layouts.partials.sidebar')

        {{-- Mobile backdrop. The sheet is dismissed by the shell on resize too. --}}
        <div
            x-show="mobileOpen"
            x-cloak
            x-transition.opacity
            @click="closeMobile()"
            class="fixed inset-0 z-30 bg-black/50 lg:hidden"
        ></div>

        <div class="flex min-w-0 flex-1 flex-col">
            @include('layouts.partials.topbar')

            <main class="flex-1">
                <div class="mx-auto w-full max-w-[1536px] p-4 md:p-6 2xl:p-10">
                    @if ($breadcrumbs || isset($header))
                        <div class="mb-5 flex flex-col gap-3 md:mb-6 md:flex-row md:items-start md:justify-between">
                            <div class="min-w-0 space-y-1.5">
                                @if ($breadcrumbs)
                                    <x-ui.breadcrumb :items="$breadcrumbs" />
                                @endif

                                @if ($title)
                                    <h1 class="truncate text-2xl font-semibold tracking-tight">{{ $title }}</h1>
                                @endif

                                @isset($description)
                                    <p class="text-sm text-muted-foreground">{{ $description }}</p>
                                @endisset
                            </div>

                            @isset($actions)
                                <div class="flex shrink-0 flex-wrap items-center gap-2">{{ $actions }}</div>
                            @endisset
                        </div>
                    @endif

                    {{ $slot }}
                </div>
            </main>
        </div>
    </div>

    @auth
        <livewire:shell.command-palette />
    @endauth

    <x-ui.toasts />

    @livewireScripts

    @if (session('status') || session('error'))
        <script>
            window.addEventListener('DOMContentLoaded', function () {
                window.dispatchEvent(new CustomEvent('platform:toast', {
                    detail: {
                        message: @json(session('status') ?? session('error')),
                        type: @json(session('error') ? 'error' : 'success'),
                    },
                }));
            });
        </script>
    @endif
</body>
</html>
