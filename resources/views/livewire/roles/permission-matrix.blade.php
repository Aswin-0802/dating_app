<div class="space-y-4 md:space-y-6">

    @if ($role->isSuperAdmin())
        <div class="flex items-start gap-3 rounded-xl border border-border bg-muted/40 px-4 py-3">
            <x-ui.icon name="lock" size="sm" class="mt-0.5 shrink-0 text-muted-foreground" />
            <div class="min-w-0 text-sm">
                <p class="font-medium">Super Admin cannot be edited</p>
                <p class="mt-0.5 text-muted-foreground">
                    It holds every permission and also passes a gate-level bypass, so a
                    permission added tomorrow is covered without anybody remembering to grant
                    it. Editing this row could lock the instance out of its own recovery path.
                </p>
            </div>
        </div>
    @endif

    <div class="space-y-4">
        @foreach ($grouped as $group => $permissions)
            @php
                $names = $permissions->pluck('name')->all();
                $selectedInGroup = count(array_intersect($names, $selected));
            @endphp

            <x-ui.card :title="$group">
                <x-slot:action>
                    <div class="flex items-center gap-3">
                        <span class="tabular text-xs text-muted-foreground">
                            {{ $selectedInGroup }} / {{ count($names) }}
                        </span>

                        @if ($canEdit)
                            <x-ui.button size="xs" variant="ghost" wire:click="toggleGroup('{{ $group }}')">
                                {{ $selectedInGroup === count($names) ? 'Clear group' : 'Select group' }}
                            </x-ui.button>
                        @endif
                    </div>
                </x-slot:action>

                <div class="grid gap-x-6 gap-y-2.5 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($permissions as $permission)
                        <label @class([
                            'flex items-start gap-2.5',
                            'cursor-pointer' => $canEdit,
                            'opacity-70' => ! $canEdit,
                        ])>
                            <input
                                type="checkbox"
                                wire:model="selected"
                                value="{{ $permission->name }}"
                                @disabled(! $canEdit)
                                class="platform-checkbox mt-px size-4 shrink-0 appearance-none rounded-xs border border-input bg-card transition-colors checked:border-primary checked:bg-primary disabled:cursor-not-allowed"
                            >

                            <span class="min-w-0">
                                <span class="flex flex-wrap items-center gap-1.5">
                                    <span class="text-sm leading-tight">{{ $permission->display_label }}</span>

                                    {{-- Sensitive permissions are marked so that
                                         handing one out is a deliberate act
                                         rather than a tick among forty others. --}}
                                    @if ($permission->is_sensitive)
                                        <x-ui.badge variant="destructive" size="sm">Sensitive</x-ui.badge>
                                    @endif
                                </span>
                                <span class="block font-mono text-[11px] text-muted-foreground">{{ $permission->name }}</span>
                            </span>
                        </label>
                    @endforeach
                </div>
            </x-ui.card>
        @endforeach
    </div>

    @if ($canEdit)
        <div class="sticky bottom-4 flex items-center gap-3 rounded-xl border border-border bg-card/95 p-4 shadow-lg backdrop-blur">
            <p class="min-w-0 flex-1 text-sm text-muted-foreground">
                <span class="tabular font-medium text-foreground">{{ count($selected) }}</span>
                permissions selected for <span class="font-medium text-foreground">{{ $role->name }}</span>
            </p>

            <x-ui.button wire:click="save">Save changes</x-ui.button>
        </div>
    @endif
</div>
