<?php

declare(strict_types=1);

namespace App\Livewire\Enforcement;

use App\Livewire\Concerns\WithDataTable;
use App\Models\AppUser;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * Members blocked by an unusual number of others.
 *
 * Blocks are the quietest safety signal there is: nobody files a report, so
 * nothing reaches a queue, but the pattern is unmistakable. Someone blocked by
 * fifteen different people has a problem that no report count will show.
 */
class Blocks extends Component
{
    use WithDataTable;

    public int $threshold = 5;

    public function mount(): void
    {
        $this->mountWithDataTable();
    }

    public function render(): View
    {
        $members = $this->baseQuery()
            ->with(['primaryPhoto', 'city'])
            ->orderByDesc('blocks_received_count')
            ->paginate($this->perPage);

        return view('livewire.enforcement.blocks', [
            'members' => $members,
            'totalBlocks' => DB::table('blocks')->count(),
        ])->layout('components.layouts.admin', [
            'title' => 'Blocks',
            'breadcrumbs' => [
                ['label' => 'Veyra', 'href' => route('admin.dashboard')],
                ['label' => 'Enforcement'],
                ['label' => 'Blocks'],
            ],
        ]);
    }

    protected function sortableFields(): array
    {
        return [];
    }

    protected function baseQuery(): Builder
    {
        return AppUser::query()
            ->withCount('blocksReceived')
            ->having('blocks_received_count', '>=', $this->threshold)
            ->when($this->search !== '', fn (Builder $q) => $q->search($this->search));
    }

    public function updatedThreshold(): void
    {
        $this->resetPage();
    }
}
