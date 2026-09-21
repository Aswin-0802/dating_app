/**
 * ApexCharts bridge.
 *
 * ApexCharts wants concrete colour strings, but the platform's colours live in CSS
 * variables that change when the theme flips. This reads the computed values off
 * :root at render time and re-reads them on `platform:theme-changed`, so charts
 * recolour without a page reload.
 */

// ApexCharts is ~900kB. Only analytics screens need it, so it is loaded on demand
// rather than shipped in the main bundle that every moderation page pays for.
let ApexChartsPromise = null;

function loadApexCharts() {
    ApexChartsPromise ??= import('apexcharts').then((module) => module.default);

    return ApexChartsPromise;
}

function token(name, fallback = '#000') {
    const value = getComputedStyle(document.documentElement).getPropertyValue(name).trim();

    return value || fallback;
}

export function palette() {
    return [token('--chart-1'), token('--chart-2'), token('--chart-3'), token('--chart-4'), token('--chart-5')];
}

/**
 * Shared chrome for every Platform chart: no toolbar, no drop shadows, tokenised
 * grid and axis colours, tabular tooltips. Individual charts supply only series,
 * type and whatever genuinely differs.
 */
export function baseOptions() {
    const foreground = token('--foreground');
    const muted = token('--muted-foreground');
    const grid = token('--chart-grid');

    return {
        chart: {
            fontFamily: token('--font-sans', 'Inter, sans-serif'),
            foreColor: muted,
            toolbar: { show: false },
            zoom: { enabled: false },
            animations: { enabled: true, speed: 300 },
            background: 'transparent',
        },
        colors: palette(),
        grid: {
            borderColor: grid,
            strokeDashArray: 4,
            padding: { left: 8, right: 8, top: 0, bottom: 0 },
        },
        dataLabels: { enabled: false },
        stroke: { curve: 'smooth', width: 2 },
        legend: {
            labels: { colors: muted },
            markers: { radius: 999 },
            fontSize: '12px',
        },
        tooltip: {
            theme: document.documentElement.classList.contains('dark') ? 'dark' : 'light',
            style: { fontSize: '12px' },
        },
        xaxis: {
            axisBorder: { color: grid },
            axisTicks: { color: grid },
            labels: { style: { colors: muted, fontSize: '12px' } },
        },
        yaxis: {
            labels: { style: { colors: muted, fontSize: '12px' } },
        },
        states: {
            hover: { filter: { type: 'lighten', value: 0.05 } },
        },
        theme: { mode: document.documentElement.classList.contains('dark') ? 'dark' : 'light' },
        noData: {
            text: 'No data for this period',
            style: { color: muted, fontSize: '13px' },
        },
        _foreground: foreground,
    };
}

function deepMerge(target, source) {
    const out = { ...target };

    for (const [key, value] of Object.entries(source ?? {})) {
        out[key] =
            value && typeof value === 'object' && !Array.isArray(value)
                ? deepMerge(target[key] ?? {}, value)
                : value;
    }

    return out;
}

export default function registerCharts(Alpine) {
    Alpine.data('platformChart', (options = {}) => ({
        chart: null,

        init() {
            this.render(options);

            window.addEventListener('platform:theme-changed', () => this.retheme());

            // Livewire may replace the series without replacing the element.
            this.$wire?.on?.('chart-updated', ({ id, series, labels }) => {
                if (id && id !== options.id) {
                    return;
                }

                this.chart?.updateOptions({ labels }, false, true);
                this.chart?.updateSeries(series, true);
            });
        },

        async render(opts) {
            const ApexCharts = await loadApexCharts();

            this.chart?.destroy();
            this.chart = new ApexCharts(this.$refs.canvas, deepMerge(baseOptions(), opts));
            this.chart.render();
        },

        retheme() {
            // updateOptions is cheaper than a full re-render and keeps the
            // current zoom/selection state intact.
            this.chart?.updateOptions(deepMerge(baseOptions(), options), false, false);
        },

        destroy() {
            this.chart?.destroy();
            this.chart = null;
        },
    }));
}

export { loadApexCharts };
