<x-layouts.admin title="Overview">
    <x-slot:description>
        Marketplace health, safety load and verification throughput.
    </x-slot:description>

    <x-slot:actions>
        <x-ui.button variant="outline" size="sm" icon="arrow-path">Refresh</x-ui.button>
        <x-ui.button variant="outline" size="sm" icon="download">Export</x-ui.button>
    </x-slot:actions>

    {{--
        Placeholder until the metrics rollup lands. Deliberately says what is
        missing and how to get it, rather than showing zeroes that read as real.
    --}}
    <x-ui.card flush>
        <x-ui.empty-state
            icon="chart-bar"
            heading="Analytics are not built yet"
            description="The dashboard reads from daily metric rollups. Seed the demo dataset and run the rollup to populate it."
        >
            <x-slot:actions>
                @if (Route::has('admin.kitchen-sink'))
                    <x-ui.button variant="outline" size="sm" :href="route('admin.kitchen-sink')">
                        Component gallery
                    </x-ui.button>
                @endif
            </x-slot:actions>
        </x-ui.empty-state>
    </x-ui.card>
</x-layouts.admin>
