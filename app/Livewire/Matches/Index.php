<?php

declare(strict_types=1);

namespace App\Livewire\Matches;

use App\Livewire\Concerns\WithDataTable;
use App\Models\MatchRecord;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Livewire\Component;

class Index extends Component
{
    use WithDataTable;

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $engagement = '';

    public function mount(): void
    {
        $this->mountWithDataTable();
    }

    public function render(): View
    {
        $matches = $this->applySort($this->baseQuery())
            ->with(['userOne.primaryPhoto', 'userOne.city', 'userTwo.primaryPhoto', 'userTwo.city'])
            ->paginate($this->perPage);

        return view('livewire.matches.index', [
            'matches' => $matches,
            'stats' => $this->stats(),
        ])->layout('components.layouts.admin', [
            'title' => 'Matches',
            'breadcrumbs' => [
                ['label' => 'Veyra', 'href' => route('admin.dashboard')],
                ['label' => 'Matches'],
            ],
        ]);
    }

    protected function sortableFields(): array
    {
        return ['matched_at', 'messages_count', 'last_message_at'];
    }

    protected function defaultSortField(): string
    {
        return 'matched_at';
    }

    protected function baseQuery(): Builder
    {
        return MatchRecord::query()
            ->when($this->status !== '', fn (Builder $q) => $q->where('status', $this->status))
            ->when($this->engagement === 'silent', fn (Builder $q) => $q->silent())
            ->when($this->engagement === 'replied', fn (Builder $q) => $q->whereNotNull('first_reply_at'))
            ->when($this->engagement === 'one_sided', fn (Builder $q) => $q
                ->whereNotNull('first_message_at')
                ->whereNull('first_reply_at'))
            ->when($this->search !== '', fn (Builder $q) => $q->where(function (Builder $inner): void {
                $inner->whereHas('userOne', fn (Builder $u) => $u->search($this->search))
                    ->orWhereHas('userTwo', fn (Builder $u) => $u->search($this->search));
            }));
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['status', 'engagement', 'search'], true)) {
            $this->resetPage();
        }
    }

    /**
     * Matches alone are a vanity metric.
     *
     * What matters is how many produce a message, and how many of those get a
     * reply — a match nobody speaks in is indistinguishable from no match.
     *
     * @return array<string, float|int>
     */
    private function stats(): array
    {
        $total = MatchRecord::query()->count();
        $messaged = MatchRecord::query()->whereNotNull('first_message_at')->count();
        $replied = MatchRecord::query()->whereNotNull('first_reply_at')->count();

        return [
            'total' => $total,
            'messaged' => $messaged,
            'replied' => $replied,
            'silent_rate' => $total > 0 ? round(($total - $messaged) / $total * 100, 1) : 0.0,
            'reply_rate' => $messaged > 0 ? round($replied / $messaged * 100, 1) : 0.0,
        ];
    }
}
