<?php

declare(strict_types=1);

namespace App\Livewire\Verifications;

use App\Livewire\Concerns\WithDataTable;
use App\Models\Verification;
use App\Support\Branding;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Livewire\Component;

class Queue extends Component
{
    use WithDataTable;

    /** 'standard' or 'restricted_minor'. */
    public string $queue = 'standard';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $signal = '';

    #[Url(except: 'open')]
    public string $view = 'open';

    public function mount(string $queue = 'standard'): void
    {
        $this->queue = $queue;

        // The restricted queue is invisible without its own permission, not
        // merely read-only: a queue somebody can see but not open is an
        // invitation to ask colleagues what is in it.
        if ($queue === 'restricted_minor') {
            abort_unless(auth()->user()?->can('restricted_minor_queue'), 404);
        }

        $this->mountWithDataTable();
    }

    public function render(): View
    {
        $verifications = $this->applySort($this->baseQuery())
            ->with(['appUser.primaryPhoto', 'appUser.city', 'claimedBy'])
            ->paginate($this->perPage);

        $isRestricted = $this->queue === 'restricted_minor';

        return view('livewire.verifications.queue', [
            'verifications' => $verifications,
            'breaching' => $this->breachingCount(),
            'oldest' => $this->oldestOpen(),
            'isRestricted' => $isRestricted,
        ])->layout('components.layouts.admin', [
            'title' => $isRestricted ? 'Minor safety queue' : 'Verification',
            'breadcrumbs' => [
                ['label' => Branding::name(), 'href' => route('admin.dashboard')],
                ['label' => 'Verification', 'href' => route('admin.verifications.index')],
                ...($isRestricted ? [['label' => 'Minor safety']] : []),
            ],
        ]);
    }

    protected function sortableFields(): array
    {
        return ['submitted_at', 'sla_due_at', 'face_match_score', 'duplicate_face_account_count'];
    }

    /** Highest risk first, then oldest — never pure FIFO. */
    protected function defaultSortField(): string
    {
        return 'sla_due_at';
    }

    protected function defaultSortDirection(): string
    {
        return 'asc';
    }

    protected function baseQuery(): Builder
    {
        return Verification::query()
            ->where('queue', $this->queue)
            ->when($this->view === 'open', fn (Builder $q) => $q->open())
            ->when($this->view === 'breaching', fn (Builder $q) => $q->breachingSla())
            ->when($this->view === 'mine', fn (Builder $q) => $q->where('claimed_by', auth()->id()))
            ->when($this->status !== '', fn (Builder $q) => $q->where('status', $this->status))
            ->when($this->signal === 'duplicate_face', fn (Builder $q) => $q->where('duplicate_face_account_count', '>', 0))
            ->when($this->signal === 'liveness_failed', fn (Builder $q) => $q->where('liveness_passed', false))
            ->when($this->signal === 'low_match', fn (Builder $q) => $q->where('face_match_score', '<', 0.8))
            ->when($this->signal === 'device_reuse', fn (Builder $q) => $q->where('device_reuse_count', '>', 0))
            ->when($this->search !== '', fn (Builder $q) => $q->whereHas(
                'appUser',
                fn (Builder $u) => $u->search($this->search),
            ));
    }

    public function setView(string $view): void
    {
        $this->view = $view;
        $this->resetPage();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['status', 'signal', 'search'], true)) {
            $this->resetPage();
        }
    }

    private function breachingCount(): int
    {
        return Verification::query()->where('queue', $this->queue)->breachingSla()->count();
    }

    /**
     * How long the oldest open item has waited.
     *
     * Shown alongside the queue depth because depth alone hides the problem:
     * 200 items all filed in the last hour is a healthy queue, 12 items where
     * the oldest is three days old is not.
     */
    private function oldestOpen(): ?Verification
    {
        return Verification::query()
            ->where('queue', $this->queue)
            ->open()
            ->orderBy('submitted_at')
            ->first();
    }
}
