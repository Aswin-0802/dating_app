@props([
    'title' => null,
    'description' => null,
])

@php
    use App\Support\Branding;

    $theme = request()->cookie('platform_theme', Branding::themeMode());
    $home = request()->routeIs('home') ? '' : route('home');
    $member = auth('member')->user();

    $socials = collect(['instagram', 'facebook', 'x', 'tiktok', 'youtube', 'linkedin'])
        ->mapWithKeys(fn (string $network): array => [$network => Branding::get('social.'.$network)])
        ->filter();

    $iosUrl = Branding::get('app.ios_url');
    $androidUrl = Branding::get('app.android_url');
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => $theme === 'dark', 'scroll-smooth']) data-theme-default="{{ Branding::themeMode() }}">
<head>
    @include('layouts.partials.document-head', ['title' => $title, 'description' => $description, 'theme' => $theme])
</head>

<body class="min-h-screen bg-background font-sans text-foreground antialiased">
    {{-- ---- header --------------------------------------------------------- --}}
    <header x-data="{ open: false }" class="sticky top-0 z-40 border-b border-border/60 bg-background/80 backdrop-blur-md">
        <div class="mx-auto flex h-16 max-w-6xl items-center gap-6 px-4 sm:px-6">
            <a href="{{ route('home') }}" class="flex min-w-0 items-center gap-2.5">
                <x-brand.mark size="sm" />
                <span class="truncate text-base font-semibold tracking-tight">{{ Branding::name() }}</span>
            </a>

            <nav class="hidden items-center gap-1 text-sm md:flex">
                <a href="{{ $home }}#how" class="rounded-md px-3 py-2 text-muted-foreground transition-colors hover:text-foreground">How it works</a>
                <a href="{{ route('site.safety') }}" class="rounded-md px-3 py-2 text-muted-foreground transition-colors hover:text-foreground">Safety</a>
                <a href="{{ $home }}#premium" class="rounded-md px-3 py-2 text-muted-foreground transition-colors hover:text-foreground">Premium</a>
                <a href="{{ $home }}#faq" class="rounded-md px-3 py-2 text-muted-foreground transition-colors hover:text-foreground">FAQ</a>
            </nav>

            <div class="ml-auto hidden items-center gap-2 md:flex">
                @if ($member)
                    <x-ui.button :href="route('member.discover')">Open {{ Branding::name() }}</x-ui.button>
                @else
                    <x-ui.button variant="ghost" :href="route('member.login')">Sign in</x-ui.button>
                    <x-ui.button :href="route('member.register')">Join free</x-ui.button>
                @endif
            </div>

            <button type="button" @click="open = ! open" class="ml-auto inline-flex size-10 items-center justify-center rounded-md hover:bg-muted md:hidden" :aria-expanded="open">
                <x-ui.icon name="menu" x-show="! open" />
                <x-ui.icon name="x-mark" x-show="open" x-cloak />
                <span class="sr-only">Menu</span>
            </button>
        </div>

        {{-- Mobile menu --}}
        <div x-show="open" x-cloak x-transition.origin.top @click.outside="open = false" class="border-t border-border bg-background px-4 pb-5 pt-2 md:hidden">
            <nav class="flex flex-col text-base">
                <a href="{{ $home }}#how" @click="open = false" class="rounded-md px-2 py-3 hover:bg-muted">How it works</a>
                <a href="{{ route('site.safety') }}" class="rounded-md px-2 py-3 hover:bg-muted">Safety</a>
                <a href="{{ $home }}#premium" @click="open = false" class="rounded-md px-2 py-3 hover:bg-muted">Premium</a>
                <a href="{{ $home }}#faq" @click="open = false" class="rounded-md px-2 py-3 hover:bg-muted">FAQ</a>
            </nav>
            <div class="mt-3 grid grid-cols-2 gap-2">
                @if ($member)
                    <x-ui.button class="col-span-2" size="lg" :href="route('member.discover')">Open {{ Branding::name() }}</x-ui.button>
                @else
                    <x-ui.button variant="outline" size="lg" :href="route('member.login')">Sign in</x-ui.button>
                    <x-ui.button size="lg" :href="route('member.register')">Join free</x-ui.button>
                @endif
            </div>
        </div>
    </header>

    <main>
        {{ $slot }}
    </main>

    {{-- ---- footer --------------------------------------------------------- --}}
    <footer class="border-t border-border bg-muted/30">
        <div class="mx-auto grid max-w-6xl gap-10 px-4 py-12 sm:px-6 md:grid-cols-[1.4fr_1fr_1fr_1fr]">
            <div class="space-y-4">
                <a href="{{ route('home') }}" class="flex items-center gap-2.5">
                    <x-brand.mark size="sm" />
                    <span class="text-base font-semibold tracking-tight">{{ Branding::name() }}</span>
                </a>
                @if ($footer = Branding::get('website.footer_text'))
                    <p class="max-w-xs text-sm text-muted-foreground">{{ $footer }}</p>
                @endif

                @if ($socials->isNotEmpty())
                    <div class="flex flex-wrap gap-1">
                        @foreach ($socials as $network => $url)
                            <a href="{{ $url }}" target="_blank" rel="noopener noreferrer" class="inline-flex size-9 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground">
                                <x-brand.social :network="$network" />
                                <span class="sr-only">{{ ucfirst($network) }}</span>
                            </a>
                        @endforeach
                    </div>
                @endif
            </div>

            <div>
                <p class="mb-3 text-sm font-semibold">Product</p>
                <ul class="space-y-2 text-sm text-muted-foreground">
                    <li><a href="{{ $home }}#how" class="hover:text-foreground">How it works</a></li>
                    <li><a href="{{ $home }}#premium" class="hover:text-foreground">Premium</a></li>
                    <li><a href="{{ route('member.register') }}" class="hover:text-foreground">Create an account</a></li>
                    <li><a href="{{ route('member.login') }}" class="hover:text-foreground">Sign in</a></li>
                </ul>
            </div>

            <div>
                <p class="mb-3 text-sm font-semibold">Trust</p>
                <ul class="space-y-2 text-sm text-muted-foreground">
                    <li><a href="{{ route('site.safety') }}" class="hover:text-foreground">Safety centre</a></li>
                    <li><a href="{{ route('site.terms') }}" class="hover:text-foreground">Terms</a></li>
                    <li><a href="{{ route('site.privacy') }}" class="hover:text-foreground">Privacy</a></li>
                    @if ($support = Branding::get('brand.support_email'))
                        <li><a href="mailto:{{ $support }}" class="hover:text-foreground">Contact support</a></li>
                    @endif
                </ul>
            </div>

            <div>
                <p class="mb-3 text-sm font-semibold">Get the app</p>
                @if ($iosUrl || $androidUrl)
                    <div class="flex flex-col items-start gap-2">
                        @if ($iosUrl)
                            <x-site.store-badge store="ios" :href="$iosUrl" />
                        @endif
                        @if ($androidUrl)
                            <x-site.store-badge store="android" :href="$androidUrl" />
                        @endif
                    </div>
                @else
                    <p class="text-sm text-muted-foreground">Use {{ Branding::name() }} right here in your browser.</p>
                @endif
            </div>
        </div>

        <div class="border-t border-border">
            <div class="mx-auto flex max-w-6xl flex-col gap-1 px-4 py-5 text-xs text-muted-foreground sm:flex-row sm:items-center sm:justify-between sm:px-6">
                <p>&copy; {{ now()->year }} {{ Branding::get('business.name', Branding::name()) }}. All rights reserved.</p>
                @if ($address = Branding::get('business.address'))
                    <p class="whitespace-pre-line sm:text-right">{{ $address }}</p>
                @endif
            </div>
        </div>
    </footer>

    <x-ui.toasts />

    @livewireScripts

    @if (session('status'))
        <script>
            window.addEventListener('DOMContentLoaded', function () {
                window.dispatchEvent(new CustomEvent('platform:toast', { detail: { message: @json(session('status')), type: 'success' } }));
            });
        </script>
    @endif
</body>
</html>
