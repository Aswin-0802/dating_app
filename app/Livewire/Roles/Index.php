<?php

declare(strict_types=1);

namespace App\Livewire\Roles;

use App\Models\Role;
use App\Services\Audit\ActivityLogger;
use App\Support\Branding;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Index extends Component
{
    /** The seven roles the product is built around; they cannot be deleted. */
    public const BUILT_IN = [
        Role::SUPER_ADMIN, Role::ADMIN, Role::TS_LEAD, Role::SENIOR_MODERATOR,
        Role::MODERATOR, Role::SUPPORT, Role::ANALYST,
    ];

    public bool $formOpen = false;

    public string $name = '';

    public string $copyFrom = '';

    public function render(): View
    {
        return view('livewire.roles.index', [
            'roles' => Role::query()
                ->withCount(['permissions', 'users'])
                ->orderBy('id')
                ->get(),
            'builtIn' => self::BUILT_IN,
        ])->layout('components.layouts.admin', [
            'title' => 'Roles',
            'breadcrumbs' => [
                ['label' => Branding::name(), 'href' => route('admin.dashboard')],
                ['label' => 'Roles'],
            ],
        ]);
    }

    public function create(): void
    {
        $this->authorize('add_roles');
        $this->resetValidation();
        $this->reset('name', 'copyFrom');
        $this->formOpen = true;
    }

    public function closeForm(): void
    {
        $this->formOpen = false;
    }

    public function save(ActivityLogger $logger): void
    {
        $this->authorize('add_roles');

        $this->validate([
            'name' => ['required', 'string', 'min:3', 'max:40', 'regex:/^[\pL\pN &\'-]+$/u', Rule::unique('roles', 'name')->where('guard_name', 'web')],
            'copyFrom' => ['nullable', Rule::exists('roles', 'name')->where('guard_name', 'web')],
        ], [
            'name.regex' => 'Use letters, numbers, spaces, "&", apostrophes and hyphens only.',
            'name.unique' => 'A role with this name already exists.',
        ], ['copyFrom' => 'starting permissions']);

        // Never a copy of Super Admin: its access is total, and a lookalike role
        // would be a quiet way round the rule that only Super Admins create them.
        if ($this->copyFrom === Role::SUPER_ADMIN) {
            $this->addError('copyFrom', 'Super Admin access cannot be copied to another role.');

            return;
        }

        $role = Role::query()->create(['name' => trim($this->name), 'guard_name' => 'web']);

        if ($this->copyFrom !== '') {
            $role->syncPermissions(Role::findByName($this->copyFrom, 'web')->permissions);
        }

        $logger->log(module: 'roles', action: 'created', subject: $role, description: "Created role {$role->name}", new: ['copied_from' => $this->copyFrom ?: null]);

        $this->formOpen = false;
        $this->redirectRoute('admin.roles.permissions', $role, navigate: true);
    }

    public function delete(int $roleId, ActivityLogger $logger): void
    {
        $this->authorize('delete_roles');

        $role = Role::query()->withCount('users')->findOrFail($roleId);

        if (in_array($role->name, self::BUILT_IN, true)) {
            session()->flash('error', "{$role->name} is a built-in role and cannot be deleted.");

            return;
        }

        if ($role->users_count > 0) {
            session()->flash('error', "Move the {$role->users_count} staff members in {$role->name} to another role first.");

            return;
        }

        $role->delete();
        $logger->log(module: 'roles', action: 'deleted', description: "Deleted role {$role->name}");
        session()->flash('status', "Role {$role->name} deleted.");
    }
}
