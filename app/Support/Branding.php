<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\HtmlString;

/**
 * The one place that knows what the product is called and what it looks like.
 *
 * Every screen — the admin console, the sign-in pages and the public website —
 * reads its name, logos and brand colour through here, never from config().
 * That is what makes the product re-brandable from Settings -> Branding without
 * touching a template: a buyer uploads their logo, picks a colour, and every
 * surface follows.
 */
final class Branding
{
    /** The colour the hand-tuned oklch palette in theme.css was designed around. */
    public const DEFAULT_COLOR = '#c2265a';

    public static function name(): string
    {
        return (string) (platform_setting('brand.name') ?: config('platform.brand.name', 'Platform'));
    }

    public static function tagline(): string
    {
        return (string) (platform_setting('brand.tagline') ?: config('platform.brand.tagline', ''));
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = platform_setting($key);

        return filled($value) ? $value : $default;
    }

    /** @param  'admin'|'web'  $variant */
    public static function logoUrl(string $variant = 'web'): ?string
    {
        $key = $variant === 'admin' ? 'brand.admin_logo' : 'brand.logo';

        // The admin falls back to the website logo and vice versa, so a buyer
        // who uploads one logo sees it everywhere rather than only half the time.
        $path = platform_setting($key) ?: platform_setting($variant === 'admin' ? 'brand.logo' : 'brand.admin_logo');

        return self::url($path);
    }

    public static function faviconUrl(): ?string
    {
        return self::url(platform_setting('brand.favicon'));
    }

    public static function loginImageUrl(): ?string
    {
        return self::url(platform_setting('brand.login_image'));
    }

    public static function primaryColor(): string
    {
        $color = (string) platform_setting('brand.primary_color', self::DEFAULT_COLOR);

        return self::isHex($color) ? self::normaliseHex($color) : self::DEFAULT_COLOR;
    }

    /** light | dark | system — the default before a person picks their own. */
    public static function themeMode(): string
    {
        $mode = (string) platform_setting('brand.theme_mode', 'system');

        return in_array($mode, ['light', 'dark', 'system'], true) ? $mode : 'system';
    }

    public static function initial(): string
    {
        return mb_strtoupper(mb_substr(self::name(), 0, 1));
    }

    /**
     * CSS overriding the brand tokens with the chosen colour.
     *
     * Emitted only when the colour has actually been changed: the default
     * palette is hand-tuned in oklch, and regenerating it mechanically from its
     * own hex would make it slightly worse for no reason.
     *
     * The tints are derived with color-mix() so one hex produces the whole
     * four-token set — solid, text-on-solid, subtle tint and text-on-tint — in
     * both themes. Text-on-solid is picked by luminance, so a buyer choosing a
     * pale yellow gets dark button text rather than white-on-yellow.
     */
    public static function styleTag(?string $color = null): HtmlString
    {
        $hex = $color !== null && self::isHex($color) ? self::normaliseHex($color) : self::primaryColor();

        if ($hex === self::DEFAULT_COLOR) {
            return new HtmlString('');
        }

        $declare = fn (array $tokens): string => implode('', array_map(
            fn (string $name, string $value): string => "{$name}:{$value};",
            array_keys($tokens),
            $tokens,
        ));

        return new HtmlString(
            '<style id="brand-tokens">:root{'.$declare(self::tokens($hex)).'}.dark{'.$declare(self::tokens($hex, dark: true)).'}</style>'
        );
    }

    /**
     * The brand token set for one colour.
     *
     * Public so the branding screen can apply it to a preview panel inline,
     * which is how a buyer sees their colour before saving it.
     *
     * @return array<string, string>
     */
    public static function tokens(string $hex, bool $dark = false): array
    {
        $hex = self::isHex($hex) ? self::normaliseHex($hex) : self::DEFAULT_COLOR;
        $on = self::contrastingText($hex);

        if ($dark) {
            return [
                '--primary' => "color-mix(in oklch, {$hex} 88%, white)",
                '--primary-subtle' => "color-mix(in oklch, {$hex} 24%, oklch(0.2 0.02 315))",
                '--primary-subtle-foreground' => "color-mix(in oklch, {$hex} 45%, white)",
                '--ring' => "color-mix(in oklch, {$hex} 88%, white)",
            ];
        }

        return [
            '--primary' => $hex,
            '--primary-foreground' => $on,
            '--primary-subtle' => "color-mix(in oklch, {$hex} 11%, white)",
            '--primary-subtle-foreground' => "color-mix(in oklch, {$hex} 78%, black)",
            '--ring' => $hex,
            '--chart-1' => $hex,
            '--sidebar-primary' => $hex,
            '--sidebar-primary-foreground' => $on,
        ];
    }

    /** Inline style form of tokens(), for a scoped preview. */
    public static function inlineTokens(string $hex): string
    {
        return collect(self::tokens($hex))
            ->map(fn (string $value, string $name): string => "{$name}: {$value}")
            ->implode('; ');
    }

    /**
     * Favicon, falling back to a generated one in the brand colour so a fresh
     * install never shows the browser's blank page icon.
     */
    public static function faviconTag(): HtmlString
    {
        $url = self::faviconUrl();

        if ($url !== null) {
            return new HtmlString('<link rel="icon" href="'.e($url).'">');
        }

        $svg = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32"><rect width="32" height="32" rx="8" fill="%s"/><path d="M9 11l7 12 7-12" stroke="%s" stroke-width="3" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg>',
            self::primaryColor(),
            self::contrastingText(self::primaryColor()),
        );

        return new HtmlString('<link rel="icon" href="data:image/svg+xml,'.rawurlencode($svg).'">');
    }

    public static function isHex(string $value): bool
    {
        return (bool) preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i', $value);
    }

    private static function normaliseHex(string $hex): string
    {
        $hex = strtolower($hex);

        if (strlen($hex) === 4) {
            $hex = '#'.$hex[1].$hex[1].$hex[2].$hex[2].$hex[3].$hex[3];
        }

        return $hex;
    }

    /** WCAG relative luminance, so the text colour follows the fill. */
    private static function contrastingText(string $hex): string
    {
        $hex = ltrim(self::normaliseHex($hex), '#');

        $channel = function (string $pair): float {
            $c = hexdec($pair) / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };

        $luminance = 0.2126 * $channel(substr($hex, 0, 2))
            + 0.7152 * $channel(substr($hex, 2, 2))
            + 0.0722 * $channel(substr($hex, 4, 2));

        // Contrast against white vs against near-black; whichever is higher wins.
        return (1.05 / ($luminance + 0.05)) >= (($luminance + 0.05) / 0.05) ? '#ffffff' : '#1a1320';
    }

    private static function url(mixed $path): ?string
    {
        return filled($path) ? asset('storage/'.ltrim((string) $path, '/')) : null;
    }
}
