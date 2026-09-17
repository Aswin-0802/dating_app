<?php

declare(strict_types=1);

namespace App\Livewire\Enforcement;

use App\Actions\Moderation\ApplyEnforcement;
use App\Enums\BanType;
use App\Enums\ReasonCode;
use App\Livewire\Concerns\WithDataTable;
use App\Models\Ban;
use App\Services\Audit\ActivityLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The shadow ban review queue.
 *
 * This screen exists to make one failure mode impossible: a member quietly
 * removed from discovery, unable to tell, and never looked at again. Every
 * shadow ban carries a mandatory review date, and this is where they surface
 * when that date passes.
 */
class ShadowBanReviews extends Component
{
    use WithDataTable;

    #[Url(except: 'due')]
    public string $view = 'due';

    public ?int $extendingBanId = null;

    public ?string $newReviewDate = null;

    public string $extendNote = '';

    public function mount(): void
    {
        $this->mountWithDataTable();
    }

    public function render(): View
    {
        $bans = $this->applySort($this->baseQuery())
            ->with(['appUser.primaryPhoto', 'issuedBy', 'moderationAction'])
            ->paginate($this->perPage);

        return view('livewire.enforcement.shadow-ban-reviews', [
            'bans' => $bans,
            'dueCount' => Ban::query()->reviewDue()->count(),
            'activeCount' => Ban::query()->active()->where('type', BanType::ShadowBan->value)->count(),
        ])->layout('components.layouts.admin', [
            'title' => 'Shadow ban reviews',
            'breadcrumbs' => [
                ['label' => 'Veyra', 'href' => route('admin.dashboard')],
                ['label' => 'Enforcement'],
                ['label' => 'Shadow ban reviews'],
            ],
        ]);
    }

    protected function sortableFields(): array
    {
        return ['review_due_at', 'starts_at', 'expires_at'];
    }

    protected function defaultSortField(): string
    {
        return 'review_due_at';
    }

    protected function defaultSortDirection(): string
    {
        return 'asc';
    }

    protected function baseQuery(): Builder
    {
        return Ban::query()
            ->where('type', BanType::ShadowBan->value)
            ->when($this->view === 'due', fn (Builder $q) => $q->reviewDue())
            ->when($this->view === 'active', fn (Builder $q) => $q->active())
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

    public function lift(int $banId): void
    {
        $this->authorize('lift_enforcement');

        $ban = Ban::query()->findOrFail($banId);

        app(ApplyEnforcement::class)->lift(
            ban: $ban,
            actor: auth()->user(),
            reason: ReasonCode::EvidenceInsufficient,
            note: 'Lifted at scheduled review.',
        );

        session()->flash('status', "Shadow ban lifted for {$ban->appUser?->display_name}.");
    }

    public function startExtend(int $banId): void
    {
        $this->authorize('extend_enforcement');

        $this->extendingBanId = $banId;
        $this->extendNote = '';

        $hours = (int) veyra_setting('enforcement.shadow_ban_review_hours', 168);
        $this->newReviewDate = now()->addHours($hours)->format('Y-m-d\TH:i');
    }

    /**
     * Extending always demands a NEW review date.
     *
     * Allowing an extension without one would reintroduce exactly the
     * open-ended shadow ban this queue exists to prevent.
     */
    public function extend(): void
    {
        $this->authorize('extend_enforcement');

        $this->validate([
            'newReviewDate' => ['required', 'date', 'after:now'],
            'extendNote' => ['required', 'string', 'min:10', 'max:1000'],
        ], [
            'newReviewDate.required' => 'A shadow ban cannot be extended without a new review date.',
            'extendNote.required' => 'Explain why this shadow ban should continue.',
        ]);

        $ban = Ban::query()->findOrFail($this->extendingBanId);

        $ban->forceFill([
            'review_due_at' => $this->newReviewDate,
            'internal_note' => trim($ban->internal_note."\n\n".now()->toDateString().': '.$this->extendNote),
        ])->save();

        app(ActivityLogger::class)->log(
            module: 'enforcement',
            action: 'extended_shadow_ban',
            subject: $ban->appUser,
            description: "Shadow ban review extended to {$this->newReviewDate}",
            new: ['review_due_at' => $this->newReviewDate, 'note' => $this->extendNote],
        );

        $this->reset(['extendingBanId', 'newReviewDate', 'extendNote']);

        session()->flash('status', 'Review date extended.');
    }

    public function cancelExtend(): void
    {
        $this->reset(['extendingBanId', 'newReviewDate', 'extendNote']);
        $this->resetErrorBag();
    }
}
