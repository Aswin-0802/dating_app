/**
 * Veyra overlay primitive.
 *
 * One implementation drives three presentations — centre modal, right drawer and
 * the mobile sidebar sheet. They differ only in positioning classes.
 *
 * Stacking is the reason this is centralised: "open the case drawer, then open a
 * Ban confirmation inside it" is a required flow, so every overlay takes the next
 * z-index off a shared stack, Escape only ever closes the topmost one, and the
 * body scroll lock is released only when the last overlay closes.
 */

const BASE_Z = 50;
const Z_STEP = 10;

const stack = [];

function topmost() {
    return stack[stack.length - 1] ?? null;
}

function syncBodyLock() {
    if (stack.length > 0) {
        document.body.setAttribute('data-overlay-open', '');
    } else {
        document.body.removeAttribute('data-overlay-open');
    }
}

function push(overlay) {
    stack.push(overlay);
    syncBodyLock();
    return BASE_Z + (stack.length - 1) * Z_STEP;
}

function remove(overlay) {
    const index = stack.indexOf(overlay);

    if (index !== -1) {
        stack.splice(index, 1);
    }

    syncBodyLock();
}

const FOCUSABLE = [
    'a[href]',
    'button:not([disabled])',
    'input:not([disabled]):not([type="hidden"])',
    'select:not([disabled])',
    'textarea:not([disabled])',
    '[tabindex]:not([tabindex="-1"])',
].join(',');

function focusableWithin(root) {
    return Array.from(root.querySelectorAll(FOCUSABLE)).filter(
        (el) => el.offsetParent !== null || el === document.activeElement,
    );
}

// A single global key handler, rather than one per overlay, so "Escape closes
// only the topmost" is true by construction instead of by luck.
document.addEventListener('keydown', (event) => {
    const current = topmost();

    if (!current) {
        return;
    }

    if (event.key === 'Escape') {
        if (current.dismissible) {
            event.preventDefault();
            event.stopPropagation();
            current.close();
        }

        return;
    }

    if (event.key !== 'Tab') {
        return;
    }

    // Focus trap: cycle within the topmost overlay only.
    const focusable = focusableWithin(current.el);

    if (focusable.length === 0) {
        event.preventDefault();
        return;
    }

    const first = focusable[0];
    const last = focusable[focusable.length - 1];

    if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
    }
});

export default function registerOverlay(Alpine) {
    Alpine.data('veyraOverlay', (config = {}) => ({
        open: false,
        zIndex: BASE_Z,

        // `static` backdrop means an outside click will not dismiss. Every
        // irreversible moderation action uses it so a stray click can never ban
        // somebody.
        backdrop: config.backdrop ?? 'dynamic',
        dismissible: config.dismissible ?? true,
        // 'modal' | 'drawer' | 'sheet'
        variant: config.variant ?? 'modal',

        _returnFocusTo: null,

        init() {
            if (config.openOn) {
                // Allow `$dispatch('open-ban-modal')` from anywhere in the tree.
                window.addEventListener(config.openOn, (event) => this.show(event.detail));
            }

            this.$watch('open', (value) => (value ? this._onOpen() : this._onClose()));
        },

        show(detail = null) {
            this.detail = detail;
            this.open = true;
        },

        close() {
            this.open = false;
        },

        toggle() {
            this.open = !this.open;
        },

        onBackdropClick() {
            if (this.backdrop !== 'static' && this.dismissible) {
                this.close();
            }
        },

        _onOpen() {
            this._returnFocusTo = document.activeElement;
            this.zIndex = push(this);

            this.$nextTick(() => {
                const panel = this.$refs.panel ?? this.$el;
                const autofocus = panel.querySelector('[autofocus]');
                const target = autofocus ?? focusableWithin(panel)[0] ?? panel;

                target.focus?.();
            });
        },

        _onClose() {
            remove(this);

            // Returning focus to the trigger is what makes keyboard review
            // sessions survive opening and closing a dozen drawers.
            this._returnFocusTo?.focus?.();
            this._returnFocusTo = null;
        },

        // Bound on the root element.
        rootAttrs() {
            return {
                'x-show': 'open',
                style: `z-index: ${this.zIndex}`,
            };
        },
    }));

    // Close any open overlay when Livewire swaps the page out from under it.
    document.addEventListener('livewire:navigating', () => {
        [...stack].forEach((overlay) => overlay.close());
    });
}

export { stack as overlayStack };
