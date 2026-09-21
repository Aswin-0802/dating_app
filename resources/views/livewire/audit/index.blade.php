<div class="space-y-4 md:space-y-6">

    @cannot('export_audit_logs')
        <div class="flex items-start gap-3 rounded-xl border border-border bg-muted/40 px-4 py-3">
            <x-ui.icon name="info" size="sm" class="mt-0.5 shrink-0 text-muted-foreground" />
            <p class="min-w-0 text-sm text-muted-foreground">
                You are seeing your own activity. The full log is available to staff with
                oversight responsibilities.
            </p>
        </div>
    @endcannot

    <x-ui.table :density="$density">
        <x-slot:toolbar>
            <div class="flex flex-1 flex-wrap items-center gap-2">
                <div class="relative min-w-0 flex-1 sm:max-w-xs">
                    <x-ui.icon name="search" size="sm"
                        class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground" />
                    <input type="search" wire:model.live.debounce.300ms="search" placeholder="Search descriptions"
                        class="h-9 w-full rounded-md border border-input bg-card pl-9 pr-3 text-sm placeholder:text-muted-foreground">
                </div>

                <x-ui.select size="sm" placeholder="Any module" wire:model.live="module"
                    :options="collect($modules)->mapWithKeys(fn ($m) => [$m => str($m)->headline()->toString()])->all()"
                    class="w-44" />

                @can('export_audit_logs')
                    <x-ui.select size="sm" placeholder="Anyone" wire:model.live="actor" :options="$actors" class="w-48" />
                @endcan

                <x-ui.select size="sm" placeholder="All entries" wire:model.live="sensitive"
                    :options="['yes' => 'Privileged access only']" class="w-52" />
            </div>

            @can('message_access_log')
                <x-ui.button variant="outline" size="sm" icon="lock" :href="route('admin.audit.message-access')">
                    Message access
                </x-ui.button>
            @endcan
        </x-slot:toolbar>

        <thead class="[&_tr]:border-b [&_tr]:border-border">
            <tr>
                <x-ui.table.head sortable field="created_at" :direction="$this->sortDirectionFor('created_at')" width="170px">
                    When
                </x-ui.table.head>
                <x-ui.table.head>Who</x-ui.table.head>
                <x-ui.table.head>Module</x-ui.table.head>
                <x-ui.table.head>Action</x-ui.table.head>
                <x-ui.table.head>What happened</x-ui.table.head>
                <x-ui.table.head align="right" width="90px"><span class="sr-only">Detail</span></x-ui.table.head>
            </tr>
        </thead>

        <tbody wire:loading.class="opacity-50">
            @forelse ($logs as $log)
                <x-ui.table.row :tint="$log->is_sensitive ? 'border-l-2 border-l-warning' : ''">
                    <x-ui.table.cell muted>{{ platform_datetime($log->created_at) }}</x-ui.table.cell>

                    <x-ui.table.cell>
                        <div class="flex min-w-0 items-center gap-2">
                            {{-- Actor name is snapshotted on the row, so the log
                                 still reads after a staff member is deleted. --}}
                            <x-ui.avatar :name="$log->actor_name" size="xs" />
                            <span class="min-w-0">
                                <span class="block truncate text-sm">{{ $log->actor_name ?? 'System' }}</span>
                                @if ($log->actor_role)
                                    <span class="block truncate text-[11px] text-muted-foreground">{{ $log->actor_role }}</span>
                                @endif
                            </span>
                        </div>
                    </x-ui.table.cell>

                    <x-ui.table.cell>
                        <x-ui.badge variant="muted">{{ str($log->module)->headline() }}</x-ui.badge>
                    </x-ui.table.cell>

                    <x-ui.table.cell>
                        <span class="font-mono text-xs">{{ $log->action }}</span>
                        @if ($log->is_sensitive)
                            <x-ui.badge variant="warning" size="sm" icon="lock" class="ml-1">Privileged</x-ui.badge>
                        @endif
                    </x-ui.table.cell>

                    <x-ui.table.cell wrap>
                        <span class="text-sm">{{ $log->description }}</span>
                    </x-ui.table.cell>

                    <x-ui.table.cell align="right">
                        @if ($log->changes_diff !== [] || $log->new_values)
                            <x-ui.button size="xs" variant="ghost" wire:click="toggleExpanded({{ $log->id }})">
                                {{ $expanded === $log->id ? 'Hide' : 'Detail' }}
                            </x-ui.button>
                        @endif
                    </x-ui.table.cell>
                </x-ui.table.row>

                @if ($expanded === $log->id)
                    <tr class="border-b border-border bg-muted/40">
                        <td colspan="6" class="px-3 py-4">
                            @php $diff = $log->changes_diff; @endphp

                            @if ($diff !== [])
                                {{-- Only changed fields. Recording the whole
                                     model on both sides makes every entry look
                                     like a rewrite and buries the real change. --}}
                                <div class="space-y-2">
                                    @foreach ($diff as $field => $change)
                                        <div class="grid gap-2 text-sm sm:grid-cols-[160px_1fr_1fr]">
                                            <span class="font-medium">{{ str($field)->headline() }}</span>
                                            <span class="rounded bg-destructive-subtle px-2 py-1 text-destructive-subtle-foreground">
                                                {{ is_array($change['old']) ? json_encode($change['old']) : ($change['old'] ?? '—') }}
                                            </span>
                                            <span class="rounded bg-success-subtle px-2 py-1 text-success-subtle-foreground">
                                                {{ is_array($change['new']) ? json_encode($change['new']) : ($change['new'] ?? '—') }}
                                            </span>
                                        </div>
                                    @endforeach
                                </div>
                            @elseif ($log->new_values)
                                <pre class="overflow-x-auto rounded bg-card p-3 text-xs">{{ json_encode($log->new_values, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                            @endif

                            <p class="mt-3 text-xs text-muted-foreground">
                                IP {{ $log->ip_address ?? 'unknown' }}
                                @if ($log->subject_type)
                                    · subject {{ class_basename($log->subject_type) }} #{{ $log->subject_id }}
                                @endif
                            </p>
                        </td>
                    </tr>
                @endif
            @empty
                <tr>
                    <td colspan="6"><x-ui.empty-state icon="clipboard-list" heading="No activity matches this view" /></td>
                </tr>
            @endforelse
        </tbody>

        <x-slot:footer>
            <x-ui.pagination :paginator="$logs" :per-page="$perPage" :per-page-options="$this->perPageOptions()" />
        </x-slot:footer>
    </x-ui.table>
</div>
