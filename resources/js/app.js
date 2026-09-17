import './bootstrap';

import registerOverlay from './overlay';
import registerTheme from './theme';
import registerCharts from './charts';
import registerShell, { registerGlobalShortcuts } from './shell';

// Livewire ships and boots Alpine itself, so Veyra registers into that instance
// on `alpine:init` rather than importing and starting a second copy.
document.addEventListener('alpine:init', () => {
    registerOverlay(window.Alpine);
    registerTheme(window.Alpine);
    registerCharts(window.Alpine);
    registerShell(window.Alpine);

    window.Alpine.store('toasts', {
        items: [],

        push(message, type = 'info', timeout = 5000) {
            const id = crypto.randomUUID();

            this.items.push({ id, message, type });

            if (timeout) {
                setTimeout(() => this.dismiss(id), timeout);
            }

            return id;
        },

        dismiss(id) {
            this.items = this.items.filter((toast) => toast.id !== id);
        },
    });
});

registerGlobalShortcuts();

// Server-side flashes and Livewire actions both surface through one toast channel.
window.addEventListener('veyra:toast', (event) => {
    window.Alpine?.store('toasts')?.push(event.detail.message, event.detail.type ?? 'info');
});
