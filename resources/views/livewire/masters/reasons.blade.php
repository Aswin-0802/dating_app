<div class="space-y-4 md:space-y-6">
    @include('livewire.masters.partials.tabs', ['active' => 'admin.masters.reasons'])

    <x-ui.card title="Enforcement reasons" description="The reasons staff choose for a decision, and the statement the member receives with it. Changes apply to new decisions only.">
        <div class="space-y-6">
            @foreach ($grouped as $group => $reasons)
                <section wire:key="reason-group-{{ Str::slug($group) }}">
                    <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">{{ $group }}</h3>
                    <div class="overflow-x-auto rounded-lg border border-border">
                        <table class="w-full text-sm">
                            <tbody class="divide-y divide-border">
                                @foreach ($reasons as $reason)
                                    <tr wire:key="reason-{{ $reason->value }}" @class(['opacity-60' => ! $reason->isActive()])>
                                        <td class="w-1/3 px-3 py-2.5 align-top">
                                            <span class="font-medium">{{ $reason->label() }}</span>
                                            @if ($reason->isSystem())
                                                <x-ui.icon name="lock" size="xs" class="ml-1 inline text-muted-foreground" title="Recorded by the system" />
                                            @endif
                                            <span class="block text-xs text-muted-foreground">{{ $reason->policyClause() }}</span>
                                        </td>
                                        <td class="hidden px-3 py-2.5 align-top text-muted-foreground md:table-cell">
                                            <p class="line-clamp-2">{{ $reason->statement() }}</p>
                                        </td>
                                        <td class="tabular whitespace-nowrap px-3 py-2.5 text-right align-top text-xs text-muted-foreground" title="Decisions using this reason">
                                            {{ platform_number($counts[$reason->value] ?? 0) }} used
                                        </td>
                                        <td class="whitespace-nowrap px-3 py-2.5 text-right align-top">
                                            @unless ($reason->isActive())
                                                <x-ui.badge size="sm" variant="muted" class="mr-1">Off</x-ui.badge>
                                            @endunless
                                            @if ($canEdit)
                                                <x-ui.button size="xs" variant="ghost" wire:click="edit('{{ $reason->value }}')">Edit</x-ui.button>
                                                @unless ($reason->isSystem())
                                                    <x-ui.button size="xs" variant="ghost" wire:click="toggleActive('{{ $reason->value }}')">{{ $reason->isActive() ? 'Turn off' : 'Turn on' }}</x-ui.button>
                                                @endunless
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            @endforeach
        </div>
    </x-ui.card>

    <x-ui.dialog :show="$formOpen" close="closeForm" size="lg">
        @if ($editingKey)
            @php $editing = App\Enums\ReasonCode::from($editingKey); @endphp
            <form wire:submit="save" class="space-y-4 p-6" novalidate>
                <div class="flex items-start justify-between gap-3">
                    <h2 class="text-lg font-semibold">Edit reason</h2>
                    <x-ui.button size="xs" variant="ghost" icon="arrow-path" wire:click="restoreDefaults">Restore original wording</x-ui.button>
                </div>
                <div class="grid gap-4 sm:grid-cols-[1fr_180px]">
                    <x-ui.input label="Name staff see" wire:model="label" :error="$errors->first('label')" required autofocus />
                    <x-ui.input label="Policy clause" wire:model="policyClause" placeholder="4.2 Respectful conduct" :error="$errors->first('policyClause')" required />
                </div>
                <x-ui.textarea label="Statement to the member" rows="4" wire:model="statement" hint="Sent with every decision that uses this reason, and shown if they try to sign in while restricted." :error="$errors->first('statement')" required />
                @if ($editing->isSystem())
                    <p class="rounded-md bg-muted px-3 py-2 text-xs text-muted-foreground">
                        <x-ui.icon name="lock" size="xs" class="-mt-0.5 mr-1 inline" /> Recorded by the system, so it is always on.
                    </p>
                @else
                    <x-ui.toggle label="Staff can choose it" wire:model="isActive" :checked="$isActive" />
                @endif
                <div class="flex flex-col-reverse gap-2 pt-2 sm:flex-row sm:justify-end">
                    <x-ui.button variant="ghost" wire:click="closeForm">Cancel</x-ui.button>
                    <x-ui.button type="submit">Save</x-ui.button>
                </div>
            </form>
        @endif
    </x-ui.dialog>
</div>
