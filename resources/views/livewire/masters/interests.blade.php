<div class="space-y-4 md:space-y-6">
    @include('livewire.masters.partials.tabs', ['active' => 'admin.masters.interests'])

    <x-ui.card title="Interests" :description="$total.' in the list'.($hidden ? ', '.$hidden.' hidden' : '').'. Members pick up to 10 for their profile.'">
        @if ($canEdit)
            <x-slot:action>
                <x-ui.button size="sm" icon="plus" wire:click="create">Add interest</x-ui.button>
            </x-slot:action>
        @endif

        <div class="mb-5 flex flex-wrap items-center gap-2">
            <div class="relative min-w-0 flex-1 sm:max-w-xs">
                <x-ui.icon name="search" size="sm" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground" />
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="Search interests" aria-label="Search interests" class="h-9 w-full rounded-md border border-input bg-card pl-9 pr-3 text-sm placeholder:text-muted-foreground">
            </div>
            <x-ui.select size="sm" wire:model.live="category" :selected="$category" placeholder="All categories" :options="$categories->combine($categories)->all()" class="sm:w-52" aria-label="Category" />
        </div>

        @if ($grouped->isEmpty())
            <x-ui.empty-state icon="sparkles" heading="No interests found" :description="$search !== '' || $category !== '' ? 'Nothing matches the filter.' : 'Add the first interest members can pick.'" />
        @else
            <div class="grid gap-4 lg:grid-cols-2">
                @foreach ($grouped as $group => $items)
                    <section class="rounded-lg border border-border" wire:key="group-{{ Str::slug($group) }}">
                        <header class="flex items-center justify-between gap-2 border-b border-border px-4 py-2.5">
                            <h3 class="text-sm font-semibold">{{ $group }} <span class="font-normal text-muted-foreground">· {{ $items->count() }}</span></h3>
                            @if ($canEdit)
                                <x-ui.button size="xs" variant="ghost" icon="plus" wire:click="create(@js($group))">Add</x-ui.button>
                            @endif
                        </header>
                        <ul class="divide-y divide-border">
                            @foreach ($items as $interest)
                                <li class="flex items-center justify-between gap-2 px-4 py-2 text-sm" wire:key="interest-{{ $interest->id }}">
                                    <span @class(['min-w-0 truncate', 'text-muted-foreground line-through decoration-1' => ! $interest->is_active])>
                                        {{ $interest->name }}
                                        @unless ($interest->is_active)
                                            <x-ui.badge size="sm" variant="muted" class="ml-1 no-underline">Hidden</x-ui.badge>
                                        @endunless
                                    </span>
                                    <span class="flex shrink-0 items-center gap-1">
                                        <span class="tabular mr-2 text-xs text-muted-foreground" title="Members with this interest">{{ platform_number($memberCounts[$interest->id] ?? 0) }}</span>
                                        @if ($canEdit)
                                            <x-ui.button size="xs" variant="ghost" icon="arrow-up" wire:click="move({{ $interest->id }}, -1)" :disabled="$loop->first" aria-label="Move {{ $interest->name }} up" />
                                            <x-ui.button size="xs" variant="ghost" icon="arrow-down" wire:click="move({{ $interest->id }}, 1)" :disabled="$loop->last" aria-label="Move {{ $interest->name }} down" />
                                            <x-ui.button size="xs" variant="ghost" wire:click="edit({{ $interest->id }})">Edit</x-ui.button>
                                            <x-ui.button size="xs" variant="ghost" wire:click="toggleActive({{ $interest->id }})">{{ $interest->is_active ? 'Hide' : 'Show' }}</x-ui.button>
                                            <x-ui.button size="xs" variant="ghost" class="text-destructive" wire:click="delete({{ $interest->id }})" wire:confirm="Delete {{ $interest->name }}?">Delete</x-ui.button>
                                        @endif
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endforeach
            </div>
        @endif
    </x-ui.card>

    <x-ui.dialog :show="$formOpen" close="closeForm">
        <form wire:submit="save" class="space-y-4 p-6" novalidate>
            <h2 class="text-lg font-semibold">{{ $editingId ? 'Edit interest' : 'Add interest' }}</h2>
            <x-ui.input label="Name" wire:model="name" placeholder="Salsa dancing" :error="$errors->first('name')" required autofocus />
            <div>
                <x-ui.input label="Category" wire:model="interestCategory" list="interest-categories" placeholder="Choose or type a new one" :error="$errors->first('interestCategory')" required />
                <datalist id="interest-categories">
                    @foreach ($categories as $c)
                        <option value="{{ $c }}"></option>
                    @endforeach
                </datalist>
            </div>
            <x-ui.toggle label="Show in the picker" description="Hidden interests stay on profiles that already have them." wire:model="isActive" :checked="$isActive" />
            <div class="flex flex-col-reverse gap-2 pt-2 sm:flex-row sm:justify-end">
                <x-ui.button variant="ghost" wire:click="closeForm">Cancel</x-ui.button>
                <x-ui.button type="submit">Save interest</x-ui.button>
            </div>
        </form>
    </x-ui.dialog>
</div>
