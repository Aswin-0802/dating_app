<?php

declare(strict_types=1);

namespace App\Livewire\Enforcement;

use App\Enums\AccountStatus;
use App\Livewire\Concerns\WithDataTable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Devices seen on more than one account.
 *
 * Shared hardware is the strongest single signal for ban evasion: a permanently
 * banned member signing up again almost always does so from the same phone.
 * Rows where one of the linked accounts is already banned are the ones worth
 * acting on.
 */
class Devices extends Component
{
    use WithDataTable;

    #[Url(except: 'banned_linked')]
    public string $view = 'banned_linked';

    public function mount(): void
    {
        $this->mountWithDataTable();
    }

    public function render(): View
    {
        return view('livewire.enforcement.devices', [
            'clusters' => $this->clusters(),
            'bannedDeviceCount' => DB::table('banned_devices')->count(),
        ])->layout('components.layouts.admin', [
            'title' => 'Shared devices',
            'breadcrumbs' => [
                ['label' => 'Veyra', 'href' => route('admin.dashboard')],
                ['label' => 'Enforcement'],
                ['label' => 'Shared devices'],
            ],
        ]);
    }

    protected function sortableFields(): array
    {
        return [];
    }

    protected function baseQuery(): QueryBuilder
    {
        return DB::table('devices');
    }

    /**
     * Fingerprints on two or more accounts, with a flag for whether any of those
     * accounts is banned.
     */
    private function clusters(): LengthAwarePaginator
    {
        $query = DB::table('devices as d')
            ->join('app_users as au', 'au.id', '=', 'd.app_user_id')
            ->select(
                'd.fingerprint_hash',
                DB::raw('COUNT(DISTINCT d.app_user_id) as accounts'),
                DB::raw(sprintf(
                    "SUM(CASE WHEN au.account_status = '%s' THEN 1 ELSE 0 END) as banned_accounts",
                    AccountStatus::Banned->value,
                )),
                DB::raw('MAX(d.last_seen_at) as last_seen_at'),
                DB::raw('GROUP_CONCAT(au.display_name ORDER BY au.id SEPARATOR " · ") as members'),
            )
            ->groupBy('d.fingerprint_hash')
            ->having('accounts', '>', 1);

        if ($this->view === 'banned_linked') {
            $query->having('banned_accounts', '>', 0);
        }

        return $query
            ->orderByDesc('banned_accounts')
            ->orderByDesc('accounts')
            ->paginate($this->perPage);
    }

    public function setView(string $view): void
    {
        $this->view = $view;
        $this->resetPage();
    }
}
