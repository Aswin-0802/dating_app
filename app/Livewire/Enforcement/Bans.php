<?php

declare(strict_types=1);

namespace App\Livewire\Enforcement;

use App\Actions\Moderation\ApplyEnforcement;
use App\Enums\BanType;
use App\Enums\ReasonCode;
use App\Livewire\Concerns\WithDataTable;
use App\Models\Ban;
use App\Support\Branding;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Livewire\Component;

class Bans extends Component
{
    use WithDataTable;

    #[Url(except: '')]
    public string $type = '';

    #[Url(except: 'active')]
    public string $view = 'active';

    public function mount(): void
    {
        $this->mountWithDataTable();
    }

    public function render(): View
    {
        $bans = $this->applySort($this->baseQuery())
            ->with(['appUser.primaryPhoto', 'issuedBy', 'liftedBy'])
            ->paginate($this->perPage);

        return view('livewire.enforcement.bans', [
            'bans' => $bans,
            'counts' => $this->counts(),
        ])->layout('components.layouts.admin', [
            'title' => 'Enforcement',
            'breadcrumbs' => [
                ['label' => Branding::name(), 'href' => route('admin.dashboard')],
                ['label' => 'Enforcement'],
            ],
        ]);
    }

    protected function sortableFields(): array
    {
        return ['starts_at', 'expires_at', 'created_at'];
    }

    protected function defaultSortField(): string
    {
        return 'starts_at';
    }

    protected function baseQuery(): Builder
    {
        return Ban::query()
            ->when($this->view === 'active', fn (Builder $q) => $q->active())
            ->when($this->view === 'lifted', fn (Builder $q) => $q->whereNotNull('lifted_at'))
            ->when($this->view === 'expiring', fn (Builder $q) => $q->active()
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', now()->addDay()))
            ->when($this->type !== '', fn (Builder $q) => $q->where('type', $this->type))
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

    public function updatedType(): void
    {
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
            note: 'Lifted from the enforcement list.',
        );

        session()->flash('status', "{$ban->type->label()} lifted for {$ban->appUser?->display_name}.");
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        $byType = Ban::query()
            ->active()
            ->selectRaw('type, count(*) as c')
            ->groupBy('type')
            ->pluck('c', 'type')
            ->all();

        return [
            'active' => array_sum($byType),
            'shadow' => $byType[BanType::ShadowBan->value] ?? 0,
            'suspended' => $byType[BanType::Suspension->value] ?? 0,
            'permanent' => $byType[BanType::PermanentBan->value] ?? 0,
        ];
    }
}
