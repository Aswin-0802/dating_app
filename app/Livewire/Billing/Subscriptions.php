<?php

declare(strict_types=1);

namespace App\Livewire\Billing;

use App\Models\AppUser;
use App\Models\Subscription;
use App\Services\Billing\Subscriptions as SubscriptionService;
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

    /** The store subscription being moved to another account, if any. */
    public ?int $reassigning = null;

    public string $reassignEmail = '';

    public string $reassignNote = '';

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

    /**
     * Support moving a store subscription to a different account: somebody
     * signed up twice, or redeemed a purchase on the wrong login. Without
     * this, the first such case gets fixed by editing rows by hand.
     */
    public function openReassign(int $id): void
    {
        $this->authorize('grant_plans');
        $this->resetValidation();
        $this->reassigning = $id;
        $this->reassignEmail = '';
        $this->reassignNote = '';
    }

    public function cancelReassign(): void
    {
        $this->reset('reassigning', 'reassignEmail', 'reassignNote');
        $this->resetValidation();
    }

    public function reassign(SubscriptionService $subscriptions): void
    {
        $this->authorize('grant_plans');

        $subscription = Subscription::query()->findOrFail($this->reassigning);

        abort_unless($subscription->isFromStore() && $subscription->external_ref !== null, 422, 'Only store subscriptions can be moved.');

        $this->validate([
            'reassignEmail' => ['required', 'email'],
            'reassignNote' => ['nullable', 'string', 'max:300'],
        ], [], ['reassignEmail' => 'email', 'reassignNote' => 'note']);

        $to = AppUser::query()->where('email', strtolower(trim($this->reassignEmail)))->first();

        if ($to === null) {
            $this->addError('reassignEmail', 'No member has that email address.');

            return;
        }

        if ($to->id === $subscription->app_user_id) {
            $this->addError('reassignEmail', 'That is already the account it belongs to.');

            return;
        }

        $moved = $subscriptions->reassignExternal($subscription->source, $subscription->external_ref, $to, auth()->user(), $this->reassignNote ?: null);

        $this->cancelReassign();
        session()->flash('status', "{$subscription->sourceLabel()} subscription moved to {$to->display_name} ({$moved} ".($moved === 1 ? 'row' : 'rows').').');
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
