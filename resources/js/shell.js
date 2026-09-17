/**
 * Admin shell: sidebar collapse, mobile sheet, and the global keyboard shortcuts.
 *
 * Sidebar state lives in a cookie for the same reason the theme does — Blade
 * renders the correct width on first paint, so the rail never visibly snaps from
 * 264px to 72px after hydration.
 */

const COOKIE = 'veyra_sidebar';
const ONE_YEAR = 60 * 60 * 24 * 365;
const DESKTOP = 1024; // lg

function writeState(value) {
    document.cookie = `${COOKIE}=${value}; path=/; max-age=${ONE_YEAR}; SameSite=Lax`;
}

export default function registerShell(Alpine) {
    Alpine.data('veyraShell', (initial = 'expanded') => ({
        state: initial, // 'expanded' | 'collapsed'
        mobileOpen: false,

        init() {
            // Crossing back to desktop must dismiss the mobile sheet, otherwise a
            // resize or rotate leaves a stuck backdrop over the content.
            const query = window.matchMedia(`(min-width: ${DESKTOP}px)`);

            query.addEventListener('change', (event) => {
                if (event.matches) {
                    this.mobileOpen = false;
                }
            });

            this.$watch('mobileOpen', (open) => {
                document.body.classList.toggle('overflow-hidden', open && !query.matches);
            });
        },

        get collapsed() {
            return this.state === 'collapsed';
        },

        toggle() {
            // Below lg the same control opens the sheet instead of collapsing.
            if (window.innerWidth < DESKTOP) {
                this.mobileOpen = !this.mobileOpen;
                return;
            }

            this.state = this.collapsed ? 'expanded' : 'collapsed';
            writeState(this.state);
        },

        closeMobile() {
            this.mobileOpen = false;
        },
    }));

    Alpine.magic('shortcut', () => (keys, handler) => {
        // Thin helper so components can declare shortcuts without each one
        // re-implementing modifier matching.
        const parts = keys.toLowerCase().split('+');
        const key = parts.pop();
        const needsMeta = parts.includes('mod');

        return (event) => {
            if (needsMeta && !(event.metaKey || event.ctrlKey)) {
                return;
            }

            if (event.key.toLowerCase() !== key) {
                return;
            }

            event.preventDefault();
            handler(event);
        };
    });
}

/**
 * Global shortcuts that are not scoped to any one component.
 *
 * Typing in a field must never trigger them — a moderator writing "ban reason:
 * bot" should not have the sidebar collapse under them.
 */
export function registerGlobalShortcuts() {
    document.addEventListener('keydown', (event) => {
        const el = event.target;
        const typing =
            el instanceof HTMLElement &&
            (el.tagName === 'INPUT' ||
                el.tagName === 'TEXTAREA' ||
                el.tagName === 'SELECT' ||
                el.isContentEditable);

        const mod = event.metaKey || event.ctrlKey;

        if (mod && event.key.toLowerCase() === 'k') {
            event.preventDefault();
            window.dispatchEvent(new CustomEvent('veyra:open-command-palette'));
            return;
        }

        if (typing) {
            return;
        }

        if (mod && event.key.toLowerCase() === 'b') {
            event.preventDefault();
            window.dispatchEvent(new CustomEvent('veyra:toggle-sidebar'));
        }
    });
}
