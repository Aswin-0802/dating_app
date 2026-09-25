<div class="space-y-4 md:space-y-6">
    @include('livewire.billing.partials.tabs', ['active' => 'admin.billing.plans'])

    <x-ui.card title="Subscription plans" description="What members can buy, shown on the website and in the member app. The free tier is always offered and needs no plan.">
        @if ($canEdit)
            <x-slot:action>
                <x-ui.button size="sm" icon="plus" wire:click="create">Add plan</x-ui.button>
            </x-slot:action>
        @endif

        @if ($plans->isEmpty())
            <x-ui.empty-state icon="sparkles" heading="No plans yet" description="Without a plan, members only see the free tier." />
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-border text-left text-xs text-muted-foreground">
                            <th class="py-2 pr-3 font-medium">Plan</th>
                            <th class="py-2 pr-3 text-right font-medium">Monthly</th>
                            <th class="py-2 pr-3 text-right font-medium">Yearly</th>
                            <th class="py-2 pr-3 font-medium">Unlocks</th>
                            <th class="py-2 pr-3 text-right font-medium">Members</th>
                            <th class="py-2 pr-3 font-medium">Status</th>
                            <th class="py-2"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @foreach ($plans as $plan)
                            <tr wire:key="plan-{{ $plan->id }}" @class(['opacity-60' => ! $plan->is_active])>
                                <td class="py-3 pr-3">
                                    <div class="flex items-center gap-2">
                                        <span class="size-3 shrink-0 rounded-full" style="background-color: {{ $plan->badge_color }}"></span>
                                        <span class="font-medium">{{ $plan->name }}</span>
                                        @if ($plan->is_featured)
                                            <x-ui.badge size="sm" variant="primary">Most popular</x-ui.badge>
                                        @endif
                                    </div>
                                    <p class="mt-0.5 text-xs text-muted-foreground">
                                        <span class="font-mono">{{ $plan->slug }}</span>@if ($plan->tagline) · {{ $plan->tagline }} @endif
                                    </p>
                                </td>
                                <td class="tabular py-3 pr-3 text-right">{{ App\Support\Currency::format($plan->monthly_price) }}</td>
                                <td class="tabular py-3 pr-3 text-right">
                                    @if ($plan->yearly_price !== null)
                                        {{ App\Support\Currency::format($plan->yearly_price) }}
                                        @if ($saving = $plan->yearlySavingPercent())
                                            <span class="block text-xs text-muted-foreground">save {{ $saving }}%</span>
                                        @endif
                                    @else
                                        <span class="text-muted-foreground">—</span>
                                    @endif
                                </td>
                                <td class="py-3 pr-3">
                                    <div class="flex max-w-xs flex-wrap gap-1">
                                        @forelse ($plan->features ?? [] as $feature)
                                            <x-ui.badge size="sm" variant="outline">{{ App\Models\Plan::FEATURES[$feature] ?? $feature }}</x-ui.badge>
                                        @empty
                                            <span class="text-xs text-muted-foreground">Nothing yet</span>
                                        @endforelse
                                    </div>
                                </td>
                                <td class="tabular py-3 pr-3 text-right">{{ platform_number($memberCounts[$plan->slug] ?? 0) }}</td>
                                <td class="py-3 pr-3">
                                    @if ($plan->is_active)
                                        <x-ui.badge size="sm" variant="success" dot>On sale</x-ui.badge>
                                    @else
                                        <x-ui.badge size="sm" variant="muted">Hidden</x-ui.badge>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap py-3 text-right">
                                    @if ($canEdit)
                                        <x-ui.button size="xs" variant="ghost" icon="arrow-up" wire:click="move({{ $plan->id }}, -1)" :disabled="$loop->first" aria-label="Move {{ $plan->name }} up" />
                                        <x-ui.button size="xs" variant="ghost" icon="arrow-down" wire:click="move({{ $plan->id }}, 1)" :disabled="$loop->last" aria-label="Move {{ $plan->name }} down" />
                                        <x-ui.button size="xs" variant="ghost" wire:click="edit({{ $plan->id }})">Edit</x-ui.button>
                                        <x-ui.button size="xs" variant="ghost" wire:click="toggleActive({{ $plan->id }})">{{ $plan->is_active ? 'Hide' : 'Show' }}</x-ui.button>
                                        <x-ui.button size="xs" variant="ghost" class="text-destructive" wire:click="delete({{ $plan->id }})" wire:confirm="Delete the {{ $plan->name }} plan?">Delete</x-ui.button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-ui.card>

    <x-ui.dialog :show="$formOpen" close="closeForm" size="lg">
        <form wire:submit="save" class="space-y-4 p-6" novalidate>
            <h2 class="text-lg font-semibold">{{ $editingId ? 'Edit plan' : 'Add plan' }}</h2>

            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.input label="Name" wire:model.live.debounce.400ms="name" placeholder="Platinum" :error="$errors->first('name')" required autofocus />
                @if ($editingId)
                    <x-ui.input label="Code" :value="$slug" disabled hint="Members are linked to this, so it cannot change." />
                @else
                    <x-ui.input label="Code" wire:model="slug" hint="Used in exports and the API." :error="$errors->first('slug')" required />
                @endif
            </div>

            <x-ui.input label="Tagline" wire:model="tagline" placeholder="The full experience." :error="$errors->first('tagline')" />

            <div class="grid gap-4 sm:grid-cols-3">
                <x-ui.input label="Monthly price" type="number" step="0.01" min="0" wire:model="monthlyPrice" :trailing="App\Support\Currency::code()" :error="$errors->first('monthlyPrice')" required />
                <x-ui.input label="Yearly price" type="number" step="0.01" min="0" wire:model="yearlyPrice" :trailing="App\Support\Currency::code()" hint="Leave empty for monthly only." :error="$errors->first('yearlyPrice')" />
                <x-ui.input label="Badge colour" type="color" wire:model="badgeColor" class="h-9 p-1" :error="$errors->first('badgeColor')" />
            </div>

            <fieldset class="space-y-2">
                <legend class="text-sm font-medium">In-app purchase products</legend>
                <p class="text-xs text-muted-foreground">
                    The product identifiers created in App Store Connect and Google Play Console for this plan. Prices are set
                    there, in each member's currency. Leave a field blank if the plan is not sold in that store for that period.
                    Put every plan in the <em>same</em> Apple subscription group, or a member can hold two at once.
                </p>
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.input label="App Store — monthly" wire:model="appleMonthly" placeholder="plus_monthly" class="font-mono" :error="$errors->first('appleMonthly')" />
                    <x-ui.input label="App Store — yearly" wire:model="appleYearly" placeholder="plus_yearly" class="font-mono" :error="$errors->first('appleYearly')" />
                    <x-ui.input label="Google Play — monthly" wire:model="googleMonthly" placeholder="plus_monthly" class="font-mono" :error="$errors->first('googleMonthly')" />
                    <x-ui.input label="Google Play — yearly" wire:model="googleYearly" placeholder="plus_yearly" class="font-mono" :error="$errors->first('googleYearly')" />
                </div>
            </fieldset>

            <fieldset class="space-y-2">
                <legend class="text-sm font-medium">What it unlocks</legend>
                <div class="grid gap-2 sm:grid-cols-2">
                    @foreach (App\Models\Plan::FEATURES as $key => $label)
                        <label class="flex items-center gap-2.5 rounded-lg border border-border px-3 py-2 text-sm hover:bg-muted/50" wire:key="feature-{{ $key }}">
                            <input type="checkbox" value="{{ $key }}" wire:model="features" class="size-4 rounded border-input text-primary">
                            {{ $label }}
                        </label>
                    @endforeach
                </div>
            </fieldset>

            <x-ui.textarea label="Extra lines on the pricing card" rows="3" wire:model="perks" hint="One per line, e.g. “Everything in Plus”. Shown above the features." :error="$errors->first('perks')" />

            <div class="grid gap-3 sm:grid-cols-2">
                <x-ui.toggle label="Most popular" description="Highlighted on the website. Only one plan at a time." wire:model="isFeatured" :checked="$isFeatured" />
                <x-ui.toggle label="On sale" description="Hidden plans stay with members already on them." wire:model="isActive" :checked="$isActive" />
            </div>

            <div class="flex flex-col-reverse gap-2 pt-2 sm:flex-row sm:justify-end">
                <x-ui.button variant="ghost" wire:click="closeForm">Cancel</x-ui.button>
                <x-ui.button type="submit">Save plan</x-ui.button>
            </div>
        </form>
    </x-ui.dialog>
</div>
