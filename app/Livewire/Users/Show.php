<?php

declare(strict_types=1);

namespace App\Livewire\Users;

use App\Models\AppUser;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

class Show extends Component
{
    public AppUser $appUser;

    #[Url(except: 'profile')]
    public string $tab = 'profile';

    public function mount(AppUser $appUser): void
    {
        $this->appUser = $appUser->load([
            'profile',
            'preferences',
            'interests',
            'photos',
            'city.country',
            'riskScore.factors',
            'activeBan',
        ]);
    }

    public function render(): View
    {
        return view('livewire.users.show', [
            'tabs' => $this->tabs(),
        ])->layout('components.layouts.admin', [
            'title' => $this->appUser->display_name,
            'breadcrumbs' => [
                ['label' => 'Veyra', 'href' => route('admin.dashboard')],
                ['label' => 'Users', 'href' => route('admin.users.index')],
                ['label' => $this->appUser->display_name],
            ],
        ]);
    }

    /**
     * Tabs are filtered by permission, so a support agent never sees a
     * "Conversations" tab they cannot open.
     *
     * @return array<int, array<string, mixed>>
     */
    private function tabs(): array
    {
        $user = auth()->user();

        $tabs = [
            ['key' => 'profile', 'label' => 'Profile', 'permission' => null],
            ['key' => 'photos', 'label' => 'Photos', 'permission' => 'view_user_photos', 'count' => $this->appUser->photos->count()],
            ['key' => 'matches', 'label' => 'Matches', 'permission' => 'matches'],
            ['key' => 'reports', 'label' => 'Reports', 'permission' => 'cases', 'count' => $this->appUser->reportsAgainst()->count()],
            ['key' => 'enforcement', 'label' => 'Enforcement', 'permission' => 'bans', 'count' => $this->appUser->bans()->count()],
            ['key' => 'devices', 'label' => 'Devices', 'permission' => 'view_user_pii'],
            ['key' => 'timeline', 'label' => 'Timeline', 'permission' => 'activity_log'],
        ];

        return array_values(array_filter(
            $tabs,
            fn (array $tab): bool => $tab['permission'] === null || $user?->can($tab['permission']),
        ));
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab;
    }
}
