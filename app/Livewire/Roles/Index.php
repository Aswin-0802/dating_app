<?php

declare(strict_types=1);

namespace App\Livewire\Roles;

use App\Models\Role;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class Index extends Component
{
    public function render(): View
    {
        return view('livewire.roles.index', [
            'roles' => Role::query()
                ->withCount(['permissions', 'users'])
                ->orderBy('id')
                ->get(),
        ])->layout('components.layouts.admin', [
            'title' => 'Roles',
            'breadcrumbs' => [
                ['label' => 'Veyra', 'href' => route('admin.dashboard')],
                ['label' => 'Roles'],
            ],
        ]);
    }
}
