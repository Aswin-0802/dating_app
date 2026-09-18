@props([
    'title' => null,
    'wide' => false,
])

@php
    use App\Support\Branding;
    use Illuminate\Support\Facades\DB;

    $theme = request()->cookie('veyra_theme', Branding::themeMode());

    /** @var \App\Models\AppUser $me */
    $me = auth('member')->user();
    $me?->loadMissing('primaryPhoto');

    // Messages waiting in the member's own open conversations, for the badge.
    $unread = $me === null ? 0 : (int) DB::table('messages as m')
        ->join('conversation_participants as cp', 'cp.conversation_id', '=', 'm.conversation_id')
        ->join('conversations as c', 'c.id', '=', 'm.conversation_id')
        ->where('cp.app_user_id', $me->id)
        ->where('c.status', 'open')
        ->where('m.sender_app_user_id', '!=', $me->id)
        ->whereNull('m.read_at')
        ->whereNull('m.deleted_at')
        ->count();

    $tabs = [
        ['label' => 'Discover', 'icon' => 'fire', 'route' => 'member.discover', 'active' => 'member.discover'],
        ['label' => 'Matches', 'icon' => 'heart', 'route' => 'member.matches', 'active' => 'member.matches'],
        ['label' => 'Messages', 'icon' => 'chat', 'route' => 'member.messages', 'active' => 'member.messages', 'badge' => $unread],
        ['label' => 'Profile', 'icon' => 'user-circle', 'route' => 'member.profile', 'active' => 'member.profile'],
    ];
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => $theme === 'dark']) data-theme-default="{{ Branding::themeMode() }}">
<head>
    @include('layouts.partials.document-head', ['title' => $title, 'theme' => $theme])
</head>

<body class="min-h-screen bg-muted/30 font-sans text-foreground antialiased">
    <header class="sticky top-0 z-40 border-b border-border bg-background/85 backdrop-blur-md">
        <div class="mx-auto flex h-14 max-w-6xl items-center gap-4 px-4 sm:h-16 sm:px-6">
            <a href="{{ route('member.discover') }}" wire:navigate class="flex min-w-0 items-center gap-2.5">
                <x-brand.mark size="sm" />
                <span class="hidden truncate text-base font-semibold tracking-tight sm:block">{{ Branding::name() }}</span>
            </a>

            <nav class="ml-4 hidden items-center gap-1 md:flex">
                @foreach ($tabs as $tab)
                    <a
                        href="{{ route($tab['route']) }}"
                        wire:navigate
                        @class([
                            'relative inline-flex items-center gap-2 rounded-full px-3.5 py-2 text-sm font-medium transition-colors',
                            'bg-primary-subtle text-primary-subtle-foreground' => request()->routeIs($tab['active']),
                            'text-muted-foreground hover:bg-muted hover:text-foreground' => ! request()->routeIs($tab['active']),
                        ])
                    >
                        <x-ui.icon :name="$tab['icon']" size="sm" />
                        {{ $tab['label'] }}
                        @if (($tab['badge'] ?? 0) > 0)
                            <span class="inline-flex min-w-5 items-center justify-center rounded-full bg-primary px-1.5 text-[11px] font-semibold text-primary-foreground">{{ $tab['badge'] > 99 ? '99+' : $tab['badge'] }}</span>
                        @endif
                    </a>
                @endforeach
            </nav>

            <div class="ml-auto flex items-center gap-1.5">
                @unless ($me?->is_premium)
                    <a href="{{ route('member.premium') }}" wire:navigate class="hidden items-center gap-1.5 rounded-full border border-warning/40 bg-warning-subtle px-3 py-1.5 text-xs font-semibold text-warning-subtle-foreground transition hover:brightness-95 sm:inline-flex">
                        <x-ui.icon name="bolt" size="xs" /> Go Premium
                    </a>
                @endunless

                <div x-data="veyraTheme()">
                    <button type="button" @click="cycle()" class="inline-flex size-9 items-center justify-center rounded-full text-muted-foreground transition-colors hover:bg-muted hover:text-foreground" ::title="`Theme: ${preference}`">
                        <span x-show="preference === 'light'" x-cloak><x-ui.icon name="sun" size="sm" /></span>
                        <span x-show="preference === 'dark'" x-cloak><x-ui.icon name="moon" size="sm" /></span>
                        <span x-show="preference === 'system'" x-cloak><x-ui.icon name="computer" size="sm" /></span>
                        <span class="sr-only">Change theme</span>
                    </button>
                </div>

                <x-ui.dropdown align="end">
                    <x-slot:trigger>
                        <button type="button" class="flex items-center rounded-full p-0.5 transition hover:ring-2 hover:ring-border">
                            <x-ui.avatar :src="$me?->primaryPhoto?->thumb_url" :name="$me?->display_name" size="sm" />
                            <span class="sr-only">Your account</span>
                        </button>
                    </x-slot:trigger>

                    <div class="px-2 py-1.5">
                        <p class="truncate text-sm font-medium">{{ $me?->display_name }}</p>
                        <p class="truncate text-xs text-muted-foreground">{{ $me?->email }}</p>
                    </div>
                    <div class="my-1 h-px bg-border"></div>
                    <x-ui.dropdown.item :href="route('member.profile')" icon="user-circle">Edit profile</x-ui.dropdown.item>
                    <x-ui.dropdown.item :href="route('member.verification')" icon="check-badge">Verification</x-ui.dropdown.item>
                    <x-ui.dropdown.item :href="route('member.premium')" icon="bolt">Premium</x-ui.dropdown.item>
                    <x-ui.dropdown.item :href="route('member.account')" icon="cog">Account &amp; privacy</x-ui.dropdown.item>
                    <x-ui.dropdown.item :href="route('site.safety')" icon="shield-check">Safety centre</x-ui.dropdown.item>
                    <div class="my-1 h-px bg-border"></div>
                    <form method="POST" action="{{ route('member.logout') }}">
                        @csrf
                        <button type="submit" class="flex w-full items-center gap-2 rounded-sm px-2 py-1.5 text-left text-sm text-muted-foreground hover:bg-muted hover:text-foreground">
                            <x-ui.icon name="logout" size="sm" /> Sign out
                        </button>
                    </form>
                </x-ui.dropdown>
            </div>
        </div>
    </header>

    <main @class([
        'mx-auto w-full px-4 pb-28 pt-5 sm:px-6 md:pb-12 md:pt-8',
        'max-w-6xl' => $wide,
        'max-w-3xl' => ! $wide,
    ])>
        {{ $slot }}
    </main>

    {{-- Bottom tabs on phones: the app is used one-handed, so primary
         navigation sits under the thumb. --}}
    <nav class="fixed inset-x-0 bottom-0 z-40 border-t border-border bg-background/95 pb-[env(safe-area-inset-bottom)] backdrop-blur-md md:hidden">
        <div class="grid grid-cols-4">
            @foreach ($tabs as $tab)
                <a
                    href="{{ route($tab['route']) }}"
                    wire:navigate
                    @class([
                        'relative flex flex-col items-center gap-0.5 py-2.5 text-[11px] font-medium',
                        'text-primary' => request()->routeIs($tab['active']),
                        'text-muted-foreground' => ! request()->routeIs($tab['active']),
                    ])
                >
                    <x-ui.icon :name="$tab['icon']" size="md" />
                    {{ $tab['label'] }}
                    @if (($tab['badge'] ?? 0) > 0)
                        <span class="absolute left-1/2 top-1.5 ml-2 inline-flex min-w-4 items-center justify-center rounded-full bg-primary px-1 text-[10px] font-semibold text-primary-foreground">{{ $tab['badge'] > 9 ? '9+' : $tab['badge'] }}</span>
                    @endif
                </a>
            @endforeach
        </div>
    </nav>

    <x-ui.toasts />

    @livewireScripts

    @if (session('status'))
        <script>
            window.addEventListener('DOMContentLoaded', function () {
                window.dispatchEvent(new CustomEvent('veyra:toast', { detail: { message: @json(session('status')), type: 'success' } }));
            });
        </script>
    @endif
</body>
</html>
