<?php

declare(strict_types=1);

namespace App\Livewire\Billing;

use App\Models\Subscription;
use App\Support\Branding;
use App\Support\Masters;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Who is on a plan, and whose is about to run out.
 *
 * "Ending this week" is the view that earns this screen: it is the list
 * somebody can act on, by checking that the renewal warnings are going out
 * and by spotting a member whose payment keeps failing before they lapse.
 */
class Subscriptions extends Component
{
    use WithPagination;

    #[Url(except: 'active')]
    public string $view = 'active';

    #[Url(except: '')]
    public string $plan = '';

    #[Url(except: '')]
    public string $source = '';

    #[Url(except: '')]
    public string $search = '';

    public function render(): View
    {
        $subscriptions = $this->query()
            ->with(['appUser:id,uuid,display_name,email', 'grantedBy:id,name'])
            ->latest('starts_at')
            ->paginate(25);

        return view('livewire.billing.subscriptions', [
            'subscriptions' => $subscriptions,
            'counts' => [
                'active' => Subscription::query()->active()->count(),
                'ending' => Subscription::query()->active()
                    ->whereNotNull('ends_at')
                    ->whereBetween('ends_at', [now(), now()->addDays(7)])->count(),
                'expired' => Subscription::query()->where('status', 'expired')->count(),
            ],
            'plans' => Masters::plans(),
            'canGrant' => auth()->user()?->can('grant_plans') ?? false,
        ])->layout('components.layouts.admin', [
            'title' => 'Subscriptions',
            'breadcrumbs' => [
                ['label' => Branding::name(), 'href' => route('admin.dashboard')],
                ['label' => 'Billing'],
                ['label' => 'Subscriptions'],
            ],
        ]);
    }

    private function query(): Builder
    {
        return Subscription::query()
            ->when($this->view === 'active', fn (Builder $q) => $q->active())
            ->when($this->view === 'ending', fn (Builder $q) => $q->active()
                ->whereNotNull('ends_at')
                ->whereBetween('ends_at', [now(), now()->addDays(7)]))
            ->when($this->view === 'expired', fn (Builder $q) => $q->where('status', 'expired'))
            ->when($this->plan !== '', fn (Builder $q) => $q->where('plan_slug', $this->plan))
            ->when($this->source !== '', fn (Builder $q) => $q->where('source', $this->source))
            ->when($this->search !== '', fn (Builder $q) => $q->whereHas('appUser', fn (Builder $u) => $u
                ->where('display_name', 'like', '%'.trim($this->search).'%')
                ->orWhere('email', 'like', '%'.trim($this->search).'%')));
    }

    public function setView(string $view): void
    {
        $this->view = in_array($view, ['active', 'ending', 'expired', 'all'], true) ? $view : 'active';
        $this->resetPage();
    }

    public function updated(string $property): void
    {
        if ($property !== 'page') {
            $this->resetPage();
        }
    }
}
