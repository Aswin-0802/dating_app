<?php

declare(strict_types=1);

namespace App\Livewire\Staff;

use App\Livewire\Concerns\WithDataTable;
use App\Models\Role;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
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
                ['label' => 'Veyra', 'href' => route('admin.dashboard')],
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

        app(\App\Services\Audit\ActivityLogger::class)->log(
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
