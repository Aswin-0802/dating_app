<?php

declare(strict_types=1);

namespace App\Livewire\Staff;

use App\Livewire\Concerns\WithDataTable;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\ActivityLogger;
use App\Support\Branding;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

class Index extends Component
{
    use WithDataTable;

    #[Url(except: '')]
    public string $role = '';

    #[Url(except: '')]
    public string $status = '';

    public function mount(): void
    {
        $this->mountWithDataTable();
    }

    public function render(): View
    {
        $staff = $this->applySort($this->baseQuery())
            ->with('roles')
            ->withCount('moderationActions')
            ->paginate($this->perPage);

        return view('livewire.staff.index', [
            'staff' => $staff,
            'roles' => Role::query()->orderBy('name')->get(),
        ])->layout('components.layouts.admin', [
            'title' => 'Staff',
            'breadcrumbs' => [
                ['label' => Branding::name(), 'href' => route('admin.dashboard')],
                ['label' => 'Staff'],
            ],
        ]);
    }

    protected function sortableFields(): array
    {
        return ['name', 'created_at', 'last_login_at'];
    }

    protected function defaultSortField(): string
    {
        return 'name';
    }

    protected function defaultSortDirection(): string
    {
        return 'asc';
    }

    protected function baseQuery(): Builder
    {
        return User::query()
            ->when($this->status !== '', fn (Builder $q) => $q->where('status', $this->status))
            ->when($this->role !== '', fn (Builder $q) => $q->whereHas(
                'roles',
                fn (Builder $r) => $r->where('name', $this->role),
            ))
            ->when($this->search !== '', fn (Builder $q) => $q->where(function (Builder $inner): void {
                $inner->where('name', 'like', "%{$this->search}%")
                    ->orWhere('email', 'like', "%{$this->search}%");
            }));
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['role', 'status', 'search'], true)) {
            $this->resetPage();
        }
    }

    // ---- add / edit / remove ------------------------------------------------

    public bool $formOpen = false;

    #[Locked]
    public ?int $editingId = null;

    public string $formName = '';

    public string $formEmail = '';

    public string $formJobTitle = '';

    public string $formRole = '';

    public function create(): void
    {
        $this->authorize('add_staff');
        $this->resetForm();
        $this->formOpen = true;
    }

    public function edit(int $userId): void
    {
        $this->authorize('edit_staff');

        $user = User::query()->with('roles')->findOrFail($userId);

        $this->resetForm();
        $this->editingId = $user->id;
        $this->formName = (string) $user->name;
        $this->formEmail = (string) $user->email;
        $this->formJobTitle = (string) $user->job_title;
        $this->formRole = (string) $user->roles->first()?->name;
        $this->formOpen = true;
    }

    public function closeForm(): void
    {
        $this->formOpen = false;
        $this->resetForm();
    }

    public function saveStaff(ActivityLogger $logger): void
    {
        $this->authorize($this->editingId === null ? 'add_staff' : 'edit_staff');

        $this->validate([
            'formName' => ['required', 'string', 'min:2', 'max:80', 'regex:/^[\pL\pM\s\'.-]+$/u'],
            'formEmail' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->editingId)->whereNull('deleted_at')],
            'formJobTitle' => ['nullable', 'string', 'max:80'],
            'formRole' => ['required', Rule::exists('roles', 'name')->where('guard_name', 'web')],
        ], [
            'formName.regex' => 'Use letters, spaces, apostrophes and hyphens only.',
            'formEmail.unique' => 'A staff account already uses this email.',
        ], [
            'formName' => 'name', 'formEmail' => 'email', 'formJobTitle' => 'job title', 'formRole' => 'role',
        ]);

        $actor = auth()->user();

        // Only a Super Admin can hand out Super Admin.
        if ($this->formRole === Role::SUPER_ADMIN && ! $actor->hasRole(Role::SUPER_ADMIN)) {
            throw ValidationException::withMessages(['formRole' => 'Only a Super Admin can grant the Super Admin role.']);
        }

        if ($this->editingId === $actor->id && ! $actor->hasRole($this->formRole)) {
            throw ValidationException::withMessages(['formRole' => 'You cannot change your own role. Ask another administrator.']);
        }

        if ($this->editingId === null) {
            $user = User::query()->create([
                'name' => trim($this->formName),
                'email' => strtolower(trim($this->formEmail)),
                'job_title' => trim($this->formJobTitle) ?: null,
                // Never used: the new member of staff sets their own password
                // from the emailed link, so no password is ever shown or sent.
                'password' => Str::password(40),
                'status' => 'active',
            ]);
            $user->syncRoles([$this->formRole]);

            Password::broker('users')->sendResetLink(['email' => $user->email]);

            $logger->log(module: 'staff', action: 'created', subject: $user, description: "Added {$user->name} as {$this->formRole}", new: ['email' => $user->email, 'role' => $this->formRole]);
            $message = "{$user->name} has been added. They have been emailed a link to set their password.";
        } else {
            $user = User::query()->with('roles')->findOrFail($this->editingId);
            $before = ['name' => $user->name, 'email' => $user->email, 'job_title' => $user->job_title, 'role' => $user->roles->first()?->name];

            if ($before['role'] === Role::SUPER_ADMIN && $this->formRole !== Role::SUPER_ADMIN && $this->superAdminCount() <= 1) {
                throw ValidationException::withMessages(['formRole' => 'This is the only Super Admin. Make someone else Super Admin first.']);
            }

            $user->forceFill([
                'name' => trim($this->formName),
                'email' => strtolower(trim($this->formEmail)),
                'job_title' => trim($this->formJobTitle) ?: null,
            ])->save();
            $user->syncRoles([$this->formRole]);

            $after = ['name' => $user->name, 'email' => $user->email, 'job_title' => $user->job_title, 'role' => $this->formRole];
            $logger->log(module: 'staff', action: 'updated', subject: $user, description: "Updated {$user->name}", old: array_diff_assoc($before, $after), new: array_diff_assoc($after, $before));
            $message = "{$user->name} has been updated.";
        }

        $this->closeForm();
        session()->flash('status', $message);
    }

    public function remove(int $userId, ActivityLogger $logger): void
    {
        $this->authorize('delete_staff');

        $user = User::query()->findOrFail($userId);

        if ($user->id === auth()->id()) {
            session()->flash('error', 'You cannot remove your own account.');

            return;
        }

        if ($user->hasRole(Role::SUPER_ADMIN) && $this->superAdminCount() <= 1) {
            session()->flash('error', 'This is the only Super Admin and cannot be removed.');

            return;
        }

        // A soft delete: their past decisions and audit entries keep their name.
        $user->forceFill(['status' => 'suspended'])->save();
        $user->delete();

        $logger->log(module: 'staff', action: 'removed', subject: $user, description: "Removed {$user->name}");
        session()->flash('status', "{$user->name} has been removed.");
    }

    private function superAdminCount(): int
    {
        return User::query()->role(Role::SUPER_ADMIN)->count();
    }

    private function resetForm(): void
    {
        $this->resetValidation();
        $this->editingId = null;
        $this->formName = '';
        $this->formEmail = '';
        $this->formJobTitle = '';
        $this->formRole = Role::MODERATOR;
    }

    public function toggleStatus(int $userId): void
    {
        $this->authorize('staff_status_toggle');

        $user = User::query()->findOrFail($userId);

        // Locking yourself out is an easy mistake to make and an annoying one
        // to recover from, so it is simply not possible here.
        if ($user->id === auth()->id()) {
            session()->flash('error', 'You cannot suspend your own account.');

            return;
        }

        $before = $user->status;
        $user->forceFill(['status' => $before === 'active' ? 'suspended' : 'active'])->save();

        app(ActivityLogger::class)->log(
            module: 'staff',
            action: 'status_changed',
            subject: $user,
            description: "{$user->name} set to {$user->status}",
            old: ['status' => $before],
            new: ['status' => $user->status],
        );

        session()->flash('status', "{$user->name} is now {$user->status}.");
    }
}
