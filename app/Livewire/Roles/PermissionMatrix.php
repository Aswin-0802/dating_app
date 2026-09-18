<?php

declare(strict_types=1);

namespace App\Livewire\Roles;

use App\Models\Permission;
use App\Models\Role;
use App\Services\Audit\ActivityLogger;
use App\Support\Branding;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class PermissionMatrix extends Component
{
    public Role $role;

    /** @var array<int, string> */
    public array $selected = [];

    public function mount(Role $role): void
    {
        $this->role = $role;
        $this->selected = $role->permissions->pluck('name')->map(fn ($n): string => (string) $n)->all();
    }

    public function render(): View
    {
        return view('livewire.roles.permission-matrix', [
            'grouped' => Permission::grouped(),
            'canEdit' => auth()->user()?->can('assign_permissions') && ! $this->role->isSuperAdmin(),
        ])->layout('components.layouts.admin', [
            'title' => $this->role->name.' permissions',
            'breadcrumbs' => [
                ['label' => Branding::name(), 'href' => route('admin.dashboard')],
                ['label' => 'Roles', 'href' => route('admin.roles.index')],
                ['label' => $this->role->name],
            ],
        ]);
    }

    public function toggleGroup(string $group): void
    {
        $names = Permission::query()->where('group_name', $group)->pluck('name')->all();
        $allSelected = empty(array_diff($names, $this->selected));

        $this->selected = $allSelected
            ? array_values(array_diff($this->selected, $names))
            : array_values(array_unique([...$this->selected, ...$names]));
    }

    public function save(): void
    {
        $this->authorize('assign_permissions');

        // Super Admin also passes through a Gate::before bypass, so editing its
        // row would be theatre — and removing a permission from it could lock
        // the instance out of its own recovery path.
        if ($this->role->isSuperAdmin()) {
            session()->flash('error', 'Super Admin holds every permission by design and cannot be edited.');

            return;
        }

        $before = $this->role->permissions->pluck('name')->sort()->values()->all();
        $after = collect($this->selected)->sort()->values()->all();

        $this->role->syncPermissions($this->selected);

        /*
         * Log only what changed.
         *
         * Recording both full lists would make every entry look like a total
         * rewrite and bury the one permission that actually moved — which is
         * the only thing anybody reviewing this log cares about.
         */
        $added = array_values(array_diff($after, $before));
        $removed = array_values(array_diff($before, $after));

        if ($added !== [] || $removed !== []) {
            app(ActivityLogger::class)->log(
                module: 'roles',
                action: 'permissions_changed',
                subject: $this->role,
                description: sprintf(
                    '%s: %d added, %d removed',
                    $this->role->name,
                    count($added),
                    count($removed),
                ),
                old: ['removed' => $removed],
                new: ['added' => $added],
                sensitive: true,
            );
        }

        $this->role->load('permissions');

        session()->flash('status', sprintf(
            'Saved. %d added, %d removed.',
            count($added),
            count($removed),
        ));
    }
}
