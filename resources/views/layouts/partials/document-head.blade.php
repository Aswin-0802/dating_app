{{--
    <head> contents shared by the public website and the member app.

    Expects $theme (the resolved cookie or the brand default) and $title.
--}}
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="description" content="{{ $description ?? App\Support\Branding::get('website.meta_description', '') }}">
<meta name="theme-color" content="{{ App\Support\Branding::primaryColor() }}">

<title>{{ $title ? $title.' · ' : '' }}{{ App\Support\Branding::name() }}</title>

<meta property="og:title" content="{{ $title ?? App\Support\Branding::name() }}">
<meta property="og:description" content="{{ $description ?? App\Support\Branding::get('website.meta_description', '') }}">
<meta property="og:site_name" content="{{ App\Support\Branding::name() }}">

<script>
    // Before first paint, so there is no flash of the wrong theme.
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
@livewireStyles
