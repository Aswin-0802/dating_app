<div class="space-y-4 md:space-y-6">
    @include('livewire.masters.partials.tabs', ['active' => 'admin.masters.report-categories'])

    <x-ui.card title="Report categories" description="What members can choose when they report someone, in this order. Severity decides how quickly a new case is picked up.">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-border text-left text-xs text-muted-foreground">
                        <th class="py-2 pr-3 font-medium">Category</th>
                        <th class="py-2 pr-3 font-medium">Group</th>
                        <th class="py-2 pr-3 font-medium">Severity</th>
                        <th class="py-2 pr-3 text-right font-medium">Reports</th>
                        <th class="py-2 pr-3 font-medium">Members see it</th>
                        <th class="py-2"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @foreach ($categories as $category)
                        <tr wire:key="category-{{ $category->value }}" @class(['opacity-60' => ! $category->isActive()])>
                            <td class="py-2.5 pr-3">
                                <span class="font-medium">{{ $category->label() }}</span>
                                @if ($category->isLocked())
                                    <x-ui.icon name="lock" size="xs" class="ml-1 inline text-muted-foreground" title="Always available" />
                                @endif
                                @if ($category->description())
                                    <span class="block text-xs text-muted-foreground">{{ $category->description() }}</span>
                                @endif
                            </td>
                            <td class="py-2.5 pr-3 text-muted-foreground">{{ $category->group() }}</td>
                            <td class="py-2.5 pr-3"><x-platform.status-badge :status="$category->defaultSeverity()" size="sm" /></td>
                            <td class="tabular py-2.5 pr-3 text-right">{{ platform_number($counts[$category->value] ?? 0) }}</td>
                            <td class="py-2.5 pr-3">
                                @if ($category->isActive())
                                    <x-ui.badge size="sm" variant="success" dot>Yes</x-ui.badge>
                                @else
                                    <x-ui.badge size="sm" variant="muted">Hidden</x-ui.badge>
                                @endif
                            </td>
                            <td class="whitespace-nowrap py-2.5 text-right">
                                @if ($canEdit)
                                    <x-ui.button size="xs" variant="ghost" icon="arrow-up" wire:click="move('{{ $category->value }}', -1)" :disabled="$loop->first" aria-label="Move up" />
                                    <x-ui.button size="xs" variant="ghost" icon="arrow-down" wire:click="move('{{ $category->value }}', 1)" :disabled="$loop->last" aria-label="Move down" />
                                    <x-ui.button size="xs" variant="ghost" wire:click="edit('{{ $category->value }}')">Edit</x-ui.button>
                                    @unless ($category->isLocked())
                                        <x-ui.button size="xs" variant="ghost" wire:click="toggleActive('{{ $category->value }}')">{{ $category->isActive() ? 'Hide' : 'Show' }}</x-ui.button>
                                    @endunless
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-ui.card>

    <x-ui.dialog :show="$formOpen" close="closeForm">
        @if ($editingKey)
            @php $editing = App\Enums\ReportCategory::from($editingKey); @endphp
            <form wire:submit="save" class="space-y-4 p-6" novalidate>
                <h2 class="text-lg font-semibold">Edit report category</h2>
                <x-ui.input label="Name members see" wire:model="label" :error="$errors->first('label')" required autofocus />
                <x-ui.textarea label="Help text" rows="2" wire:model="description" hint="Optional. Shown to staff under the name." :error="$errors->first('description')" />
                <x-ui.select
                    label="Severity of new cases"
                    wire:model="severity"
                    :selected="$severity"
                    :options="collect(App\Enums\Severity::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all()"
                    :disabled="$editing->isRestricted()"
                    :hint="$editing->isRestricted() ? 'Minor-safety reports are always Critical.' : null"
                    :error="$errors->first('severity')"
                />
                @if ($editing->isLocked())
                    <p class="rounded-md bg-muted px-3 py-2 text-xs text-muted-foreground">
                        <x-ui.icon name="lock" size="xs" class="-mt-0.5 mr-1 inline" /> Always available to members, so it cannot be hidden.
                    </p>
                @else
                    <x-ui.toggle label="Members can choose it" wire:model="isActive" :checked="$isActive" />
                @endif
                <div class="flex flex-col-reverse gap-2 pt-2 sm:flex-row sm:justify-end">
                    <x-ui.button variant="ghost" wire:click="closeForm">Cancel</x-ui.button>
                    <x-ui.button type="submit">Save</x-ui.button>
                </div>
            </form>
        @endif
    </x-ui.dialog>
</div>
