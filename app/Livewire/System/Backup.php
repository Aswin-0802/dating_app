<?php

declare(strict_types=1);

namespace App\Livewire\System;

use App\Services\Audit\ActivityLogger;
use App\Support\Branding;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Symfony\Component\Process\Process;

/**
 * Database backup.
 *
 * Shells out to mysqldump rather than iterating tables in PHP: a dump produced
 * by the database's own tool restores with the database's own tool, and does
 * not quietly mangle a charset or drop a foreign key at three in the morning.
 */
class Backup extends Component
{
    public bool $running = false;

    public function render(): View
    {
        return view('livewire.system.backup', [
            'backups' => $this->backups(),
            'mysqldump' => $this->mysqldumpPath(),
        ])->layout('components.layouts.admin', [
            'title' => 'Database backup',
            'breadcrumbs' => [
                ['label' => Branding::name(), 'href' => route('admin.dashboard')],
                ['label' => 'System'],
                ['label' => 'Backup'],
            ],
        ]);
    }

    public function create(): void
    {
        $this->authorize('run_maintenance_jobs');

        $binary = $this->mysqldumpPath();

        if ($binary === null) {
            session()->flash('error', 'mysqldump was not found. Set MYSQLDUMP_PATH in the environment.');

            return;
        }

        $filename = str(Branding::name())->slug().'-'.now()->format('Y-m-d-His').'.sql';
        $target = Storage::disk('local')->path('backups/'.$filename);

        Storage::disk('local')->makeDirectory('backups');

        $process = new Process([
            $binary,
            '--host='.config('database.connections.mysql.host'),
            '--port='.config('database.connections.mysql.port'),
            '--user='.config('database.connections.mysql.username'),
            '--password='.config('database.connections.mysql.password'),
            '--single-transaction',
            // Without this a dump taken while the app is running can capture a
            // half-written transaction.
            '--quick',
            '--default-character-set=utf8mb4',
            '--result-file='.$target,
            config('database.connections.mysql.database'),
        ]);

        $process->setTimeout(600);
        $process->run();

        if (! $process->isSuccessful()) {
            session()->flash('error', 'Backup failed: '.trim($process->getErrorOutput()));

            return;
        }

        app(ActivityLogger::class)->log(
            module: 'system',
            action: 'backup_created',
            description: "Created database backup {$filename}",
            new: ['file' => $filename, 'bytes' => filesize($target) ?: 0],
        );

        session()->flash('status', "Backup written to storage/app/backups/{$filename}.");
    }

    public function delete(string $filename): void
    {
        $this->authorize('run_maintenance_jobs');

        // Basename only: a filename from the browser must not be able to walk
        // out of the backups directory.
        $safe = basename($filename);

        if (! str_ends_with($safe, '.sql')) {
            return;
        }

        Storage::disk('local')->delete('backups/'.$safe);

        app(ActivityLogger::class)->log(
            module: 'system',
            action: 'backup_deleted',
            description: "Deleted database backup {$safe}",
            sensitive: true,
        );

        session()->flash('status', 'Backup deleted.');
    }

    /** @return array<int, array<string, mixed>> */
    private function backups(): array
    {
        $disk = Storage::disk('local');

        if (! $disk->exists('backups')) {
            return [];
        }

        return collect($disk->files('backups'))
            ->filter(fn (string $path): bool => str_ends_with($path, '.sql'))
            ->map(fn (string $path): array => [
                'name' => basename($path),
                'bytes' => $disk->size($path),
                'created_at' => $disk->lastModified($path),
            ])
            ->sortByDesc('created_at')
            ->values()
            ->all();
    }

    private function mysqldumpPath(): ?string
    {
        foreach ([
            env('MYSQLDUMP_PATH'),
            'C:/xampp/mysql/bin/mysqldump.exe',
            '/usr/bin/mysqldump',
            '/usr/local/bin/mysqldump',
        ] as $candidate) {
            if (filled($candidate) && is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
