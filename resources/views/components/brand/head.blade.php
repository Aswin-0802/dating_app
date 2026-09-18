{{--
    Favicon and brand colour, shared by every layout.

    Placed after @vite so the override tokens come later in the cascade than
    theme.css and win at equal specificity.
--}}
{!! App\Support\Branding::faviconTag() !!}
{!! App\Support\Branding::styleTag() !!}
