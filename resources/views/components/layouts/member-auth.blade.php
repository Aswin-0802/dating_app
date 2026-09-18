@props(['title' => null])

@php
    use App\Support\Branding;

    $theme = request()->cookie('veyra_theme', Branding::themeMode());
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => $theme === 'dark']) data-theme-default="{{ Branding::themeMode() }}">
<head>
    @include('layouts.partials.document-head', ['title' => $title, 'theme' => $theme])
</head>

<body class="min-h-screen bg-background font-sans text-foreground antialiased">
    <div class="grid min-h-screen lg:grid-cols-[1fr_1.05fr]">
        <div class="flex flex-col px-5 py-6 sm:px-10">
            <a href="{{ route('home') }}" class="flex w-fit items-center gap-2.5">
                <x-brand.mark size="sm" />
                <span class="text-base font-semibold tracking-tight">{{ Branding::name() }}</span>
            </a>

            <div class="flex flex-1 items-center justify-center py-10">
                <div class="w-full max-w-md">
                    {{ $slot }}
                </div>
            </div>

            <p class="text-center text-xs text-muted-foreground">
                <a href="{{ route('site.terms') }}" class="hover:text-foreground">Terms</a>
                <span class="mx-1.5">·</span>
                <a href="{{ route('site.privacy') }}" class="hover:text-foreground">Privacy</a>
                <span class="mx-1.5">·</span>
                <a href="{{ route('site.safety') }}" class="hover:text-foreground">Safety</a>
            </p>
        </div>

        <div class="relative hidden overflow-hidden bg-gradient-to-br from-primary via-primary/85 to-accent lg:block">
            <div aria-hidden="true" class="absolute -left-24 top-1/4 size-96 rounded-full bg-white/10 blur-3xl"></div>
            <div aria-hidden="true" class="absolute -bottom-24 right-0 size-96 rounded-full bg-black/10 blur-3xl"></div>

            <div class="relative flex h-full flex-col justify-end p-14 text-primary-foreground">
                <div class="mb-auto mt-16 max-w-sm space-y-3">
                    @foreach ([
                        ['check-badge', 'Every profile checked by a real person'],
                        ['lock', 'Nobody can message you until you match'],
                        ['flag', 'Report or block in two taps'],
                    ] as [$icon, $line])
                        <div class="flex items-center gap-3 rounded-2xl bg-white/12 px-4 py-3 backdrop-blur">
                            <x-ui.icon :name="$icon" size="md" />
                            <span class="text-sm font-medium">{{ $line }}</span>
                        </div>
                    @endforeach
                </div>

                <p class="max-w-md text-3xl font-bold leading-tight tracking-tight">
                    {{ Branding::get('website.hero_title', 'Meet people who are exactly who they say they are.') }}
                </p>
            </div>
        </div>
    </div>

    <x-ui.toasts />
    @livewireScripts

    @if (session('status'))
        <script>
            window.addEventListener('DOMContentLoaded', function () {
                window.dispatchEvent(new CustomEvent('veyra:toast', { detail: { message: @json(session('status')), type: 'info' } }));
            });
        </script>
    @endif
</body>
</html>
