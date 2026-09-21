<div class="space-y-4 md:space-y-6">
    <x-ui.card
        title="Signup to conversation"
        description="Share of sign-ups that reached each step."
    >
        <div class="space-y-3">
            @foreach ($funnel as $stage)
                <div>
                    <div class="mb-1 flex items-center justify-between gap-3 text-sm">
                        <span>{{ $stage['step'] }}</span>
                        <span class="tabular text-muted-foreground">
                            {{ platform_number($stage['count']) }}
                            <span class="ml-1 font-medium text-foreground">{{ $stage['rate'] }}%</span>
                        </span>
                    </div>

                    <div class="h-3 overflow-hidden rounded-full bg-muted">
                        <div class="h-full rounded-full bg-primary" style="width: {{ max(1, $stage['rate']) }}%"></div>
                    </div>

                    {{-- Only the lossy steps are annotated. Labelling every step
                         trains the eye to skip all of them. --}}
                    @unless ($loop->first)
                        <p @class([
                            'mt-1 text-xs',
                            'text-destructive-subtle-foreground' => $stage['step_rate'] < 55,
                            'text-warning-subtle-foreground' => $stage['step_rate'] >= 55 && $stage['step_rate'] < 75,
                            'text-muted-foreground' => $stage['step_rate'] >= 75,
                        ])>
                            {{ $stage['step_rate'] }}% of the previous step
                            @if ($stage['step_rate'] < 75)
                                — {{ platform_number($funnel[$loop->index - 1]['count'] - $stage['count']) }} people lost here
                            @endif
                        </p>
                    @endunless
                </div>
            @endforeach
        </div>
    </x-ui.card>

    <x-ui.card title="Cold start" description="New members matched within 48 hours of signing up.">
        <div class="flex flex-wrap items-center gap-6">
            <div>
                <p class="tabular text-4xl font-bold">{{ platform_percent($coldStart['rate']) }}</p>
                <p class="mt-1 text-sm text-muted-foreground">
                    {{ platform_number($coldStart['matched_in_48h']) }} of {{ platform_number($coldStart['cohort']) }}
                    recent signups
                </p>
            </div>

            <p class="min-w-0 max-w-md text-sm text-muted-foreground">
                This is the number that decides whether acquisition spend is worth anything.
                Somebody who gets no match in their first two days rarely returns, and
                nothing later in the product recovers them.
            </p>
        </div>
    </x-ui.card>
</div>
