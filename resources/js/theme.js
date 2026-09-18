/**
 * Theme control: light / dark / system.
 *
 * The resolved theme is written to a cookie rather than localStorage so Blade can
 * read it server-side and render `<html class="dark">` on the very first byte.
 * The inline script in the layout head applies it before paint; this module only
 * handles changes made after load.
 */

const COOKIE = 'veyra_theme';
const ONE_YEAR = 60 * 60 * 24 * 365;

export function readPreference() {
    const match = document.cookie.match(new RegExp(`(?:^|; )${COOKIE}=([^;]*)`));

    // Without a personal choice, fall back to the default set in
    // Settings -> Branding, which every layout stamps onto <html>.
    return match ? decodeURIComponent(match[1]) : document.documentElement.dataset.themeDefault || 'system';
}

function writePreference(value) {
    document.cookie = `${COOKIE}=${encodeURIComponent(value)}; path=/; max-age=${ONE_YEAR}; SameSite=Lax`;
}

export function resolve(preference) {
    if (preference === 'system') {
        return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }

    return preference;
}

function apply(preference) {
    document.documentElement.classList.toggle('dark', resolve(preference) === 'dark');

    // Charts and anything else holding rendered colour need to recolour without a
    // page reload, so broadcast rather than expecting listeners to poll.
    window.dispatchEvent(
        new CustomEvent('veyra:theme-changed', { detail: { resolved: resolve(preference) } }),
    );
}

export default function registerTheme(Alpine) {
    Alpine.data('veyraTheme', () => ({
        preference: readPreference(),

        init() {
            // Follow the OS while the preference is 'system'.
            window
                .matchMedia('(prefers-color-scheme: dark)')
                .addEventListener('change', () => {
                    if (this.preference === 'system') {
                        apply('system');
                    }
                });
        },

        get resolved() {
            return resolve(this.preference);
        },

        set(value) {
            this.preference = value;
            writePreference(value);
            apply(value);
        },

        cycle() {
            this.set({ light: 'dark', dark: 'system', system: 'light' }[this.preference]);
        },
    }));
}
