<div class="space-y-4 md:space-y-6">
    @include('livewire.masters.partials.tabs', ['active' => 'admin.masters.profile-options'])

    <div class="grid gap-4 md:gap-6 lg:grid-cols-[240px_1fr]">
        <x-ui.card title="Questions" :padding="true">
            <ul class="-mx-2 space-y-0.5">
                @foreach ($groups as $key => [$groupLabel, $groupAllowsNew])
                    <li wire:key="group-{{ $key }}">
                        <button
                            type="button"
                            wire:click="selectGroup('{{ $key }}')"
                            @class([
                                'flex w-full items-center justify-between gap-2 rounded-md px-2 py-2 text-left text-sm transition-colors',
                                'bg-primary-subtle font-medium text-primary-subtle-foreground' => $group === $key,
                                'hover:bg-muted' => $group !== $key,
                            ])
                            @if ($group === $key) aria-current="true" @endif
                        >
                            {{ $groupLabel }}
                            @unless ($groupAllowsNew)
                                <x-ui.icon name="lock" size="xs" class="text-muted-foreground" title="Fixed set of answers" />
                            @endunless
                        </button>
                    </li>
                @endforeach
            </ul>
        </x-ui.card>

        <x-ui.card
            :title="$groups[$group][0]"
            :description="$allowsNew
                ? ($group === 'prompt' ? 'Questions members can answer on their profile, up to three each.' : 'The choices members see for this question.')
                : 'These answers are fixed, but you can reword, reorder or hide them.'"
        >
            @if ($canEdit && $allowsNew)
                <x-slot:action>
                    <x-ui.button size="sm" icon="plus" wire:click="create">{{ $group === 'prompt' ? 'Add prompt' : 'Add option' }}</x-ui.button>
                </x-slot:action>
            @endif

            @if ($options->isEmpty())
                <x-ui.empty-state icon="list-bullet" heading="No options yet" description="Members see the built-in list until you add one." />
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-border text-left text-xs text-muted-foreground">
                                <th class="py-2 pr-3 font-medium">{{ $group === 'prompt' ? 'Prompt' : 'Shown to members as' }}</th>
                                <th class="py-2 pr-3 text-right font-medium">Members</th>
                                <th class="py-2 pr-3 font-medium">Status</th>
                                <th class="py-2"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @foreach ($options as $option)
                                <tr wire:key="option-{{ $option->id }}" @class(['opacity-60' => ! $option->is_active])>
                                    <td class="py-2.5 pr-3">
                                        {{ $option->label }}
                                        @if ($option->label !== $option->key)
                                            <span class="block font-mono text-xs text-muted-foreground">{{ $option->key }}</span>
                                        @endif
                                    </td>
                                    <td class="tabular py-2.5 pr-3 text-right">{{ platform_number($usage[$option->id] ?? 0) }}</td>
                                    <td class="py-2.5 pr-3">
                                        @if ($option->is_active)
                                            <x-ui.badge size="sm" variant="success" dot>Visible</x-ui.badge>
                                        @else
                                            <x-ui.badge size="sm" variant="muted">Hidden</x-ui.badge>
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap py-2.5 text-right">
                                        @if ($canEdit)
                                            <x-ui.button size="xs" variant="ghost" icon="arrow-up" wire:click="move({{ $option->id }}, -1)" :disabled="$loop->first" aria-label="Move up" />
                                            <x-ui.button size="xs" variant="ghost" icon="arrow-down" wire:click="move({{ $option->id }}, 1)" :disabled="$loop->last" aria-label="Move down" />
                                            <x-ui.button size="xs" variant="ghost" wire:click="edit({{ $option->id }})">Edit</x-ui.button>
                                            <x-ui.button size="xs" variant="ghost" wire:click="toggleActive({{ $option->id }})">{{ $option->is_active ? 'Hide' : 'Show' }}</x-ui.button>
                                            @if ($allowsNew)
                                                <x-ui.button size="xs" variant="ghost" class="text-destructive" wire:click="delete({{ $option->id }})" wire:confirm="Delete this option?">Delete</x-ui.button>
                                            @endif
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-ui.card>
    </div>

    <x-ui.dialog :show="$formOpen" close="closeForm">
        <form wire:submit="save" class="space-y-4 p-6" novalidate>
            <h2 class="text-lg font-semibold">
                {{ $editingId ? 'Edit option' : ($group === 'prompt' ? 'Add prompt' : 'Add option') }}
            </h2>
            <x-ui.input
                :label="$group === 'prompt' ? 'Prompt' : 'Shown to members as'"
                wire:model="label"
                :placeholder="$group === 'prompt' ? 'My ideal first date' : ''"
                :hint="$editingId && $group === 'prompt' ? 'Rewording changes it on every profile that uses it.' : null"
                :error="$errors->first('label')"
                required
                autofocus
            />
            <x-ui.toggle label="Visible to members" description="Hidden options stay on profiles that already use them." wire:model="isActive" :checked="$isActive" />
            @error('isActive') <p class="text-xs text-destructive">{{ $message }}</p> @enderror
            <div class="flex flex-col-reverse gap-2 pt-2 sm:flex-row sm:justify-end">
                <x-ui.button variant="ghost" wire:click="closeForm">Cancel</x-ui.button>
                <x-ui.button type="submit">Save</x-ui.button>
            </div>
        </form>
    </x-ui.dialog>
</div>
