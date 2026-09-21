<div class="space-y-4 md:space-y-6">

    <x-ui.card
        title="Database backup"
        description="A full copy of the database, saved as an SQL file."
    >
        @if ($mysqldump === null)
            <div class="flex items-start gap-3 rounded-lg border border-destructive/30 bg-destructive-subtle p-3">
                <x-ui.icon name="warning" size="sm" class="mt-0.5 shrink-0 text-destructive-subtle-foreground" />
                <div class="min-w-0 text-sm text-destructive-subtle-foreground">
                    <p class="font-medium">mysqldump was not found</p>
                    <p class="mt-0.5">
                        Set <code class="rounded bg-card px-1">MYSQLDUMP_PATH</code> in the environment.
                        On XAMPP it is usually <code class="rounded bg-card px-1">C:/xampp/mysql/bin/mysqldump.exe</code>.
                    </p>
                </div>
            </div>
        @else
            <div class="flex flex-wrap items-center gap-3">
                <x-ui.button icon="download" wire:click="create" wire:loading.attr="disabled" wire:target="create">
                    <span wire:loading.remove wire:target="create">Create backup now</span>
                    <span wire:loading wire:target="create">Dumping…</span>
                </x-ui.button>

                <p class="text-xs text-muted-foreground">
                    Written to <code class="rounded bg-muted px-1">storage/app/backups</code>.
                    Large databases take a while.
                </p>
            </div>
        @endif
    </x-ui.card>

    <x-ui.table>
        <x-slot:toolbar>
            <p class="text-sm text-muted-foreground">
                {{ count($backups) }} {{ str('backup')->plural(count($backups)) }} on disk
            </p>
        </x-slot:toolbar>

        <thead class="[&_tr]:border-b [&_tr]:border-border">
            <tr>
                <x-ui.table.head>File</x-ui.table.head>
                <x-ui.table.head align="right">Size</x-ui.table.head>
                <x-ui.table.head align="right">Created</x-ui.table.head>
                <x-ui.table.head align="right" width="90px"><span class="sr-only">Actions</span></x-ui.table.head>
            </tr>
        </thead>

        <tbody>
            @forelse ($backups as $backup)
                <x-ui.table.row>
                    <x-ui.table.cell>
                        <span class="font-mono text-sm">{{ $backup['name'] }}</span>
                    </x-ui.table.cell>

                    <x-ui.table.cell align="right" numeric muted>
                        {{ platform_compact_number($backup['bytes'] / 1024) }} KB
                    </x-ui.table.cell>

                    <x-ui.table.cell align="right" muted>
                        {{ platform_datetime(\Illuminate\Support\Carbon::createFromTimestamp($backup['created_at'])) }}
                    </x-ui.table.cell>

                    <x-ui.table.cell align="right">
                        <x-ui.button
                            size="xs"
                            variant="ghost"
                            wire:click="delete('{{ $backup['name'] }}')"
                            wire:confirm="Delete this backup? It cannot be recovered."
                        >Delete</x-ui.button>
                    </x-ui.table.cell>
                </x-ui.table.row>
            @empty
                <tr>
                    <td colspan="4">
                        <x-ui.empty-state
                            icon="document"
                            heading="No backups yet"
                            description="Create a backup to keep a copy of your data."
                        />
                    </td>
                </tr>
            @endforelse
        </tbody>
    </x-ui.table>
</div>
