<?php

declare(strict_types=1);

namespace App\Livewire\Users;

use App\Actions\Moderation\ApplyEnforcement;
use App\Enums\LadderStep;
use App\Enums\ReasonCode;
use App\Livewire\Concerns\AppliesEnforcement;
use App\Models\ActivityLog;
use App\Models\AppUser;
use App\Models\AppUserLogin;
use App\Models\Ban;
use App\Models\Device;
use App\Models\MatchRecord;
use App\Models\ModerationAction;
use App\Models\Report;
use App\Services\Billing\Subscriptions;
use App\Support\Branding;
use App\Support\Masters;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

class Show extends Component
{
    use AppliesEnforcement;

    #[Locked]
    public AppUser $appUser;

    #[Url(except: 'profile')]
    public string $tab = 'profile';

    // ---- plan dialog ----------------------------------------------------------

    public bool $planFormOpen = false;

    public string $planSlug = '';

    /** 1m | 12m | custom | open */
    public string $planLength = '1m';

    public ?string $planEndsAt = null;

    public string $planNote = '';

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
            'tabData' => $this->tabData(),
        ])->layout('components.layouts.admin', [
            'title' => $this->appUser->display_name,
            'breadcrumbs' => [
                ['label' => Branding::name(), 'href' => route('admin.dashboard')],
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
            ['key' => 'billing', 'label' => 'Billing', 'permission' => 'users'],
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
        // Only tabs the viewer may open; a hand-typed ?tab= cannot reach others.
        if (collect($this->tabs())->contains('key', $tab)) {
            $this->tab = $tab;
        }
    }

    /**
     * Data for the open tab only, so the page never loads five tabs' worth of
     * queries to show one.
     *
     * @return array<string, mixed>
     */
    private function tabData(): array
    {
        if (! collect($this->tabs())->contains('key', $this->tab)) {
            $this->tab = 'profile';
        }

        $id = $this->appUser->id;

        return match ($this->tab) {
            'matches' => [
                'matches' => MatchRecord::query()->involving($id)
                    ->with(['userOne.primaryPhoto', 'userTwo.primaryPhoto', 'conversation'])
                    ->latest('matched_at')->limit(50)->get(),
            ],
            'reports' => [
                'reports' => Report::query()->where('reported_app_user_id', $id)
                    ->with(['reporter', 'reportCase'])->latest('created_at')->limit(50)->get(),
                'filed' => Report::query()->where('reporter_app_user_id', $id)->count(),
            ],
            'enforcement' => [
                'actions' => ModerationAction::query()->where('subject_app_user_id', $id)
                    ->with('actor')->latest('created_at')->limit(50)->get(),
                'bans' => Ban::query()->where('app_user_id', $id)->latest('starts_at')->limit(50)->get(),
            ],
            'billing' => [
                'subscriptions' => app(Subscriptions::class)->historyFor($this->appUser),
                'plans' => Masters::plans(),
            ],
            'devices' => [
                'devices' => Device::query()->where('app_user_id', $id)->latest('last_seen_at')->get()
                    ->map(function (Device $device): Device {
                        $device->setAttribute('shared_with', Device::query()
                            ->where('fingerprint_hash', $device->fingerprint_hash)
                            ->where('app_user_id', '!=', $device->app_user_id)
                            ->distinct()->count('app_user_id'));

                        return $device;
                    }),
                'logins' => AppUserLogin::query()->where('app_user_id', $id)->latest('created_at')->limit(25)->get(),
            ],
            'timeline' => [
                'events' => ActivityLog::query()
                    ->where('subject_type', $this->appUser->getMorphClass())
                    ->where('subject_id', $id)
                    ->latest('created_at')->limit(50)->get(),
            ],
            default => [],
        };
    }

    public function confirmStep(): void
    {
        $step = LadderStep::tryFrom($this->pendingStep);

        if ($this->applyStepTo([$this->appUser]) > 0) {
            session()->flash('status', "{$step?->label()} applied to {$this->appUser->display_name}.");
            $this->redirectRoute('admin.users.show', $this->appUser, navigate: true);
        }
    }

    public function liftActiveBan(): void
    {
        $this->authorize('lift_enforcement');

        $ban = $this->appUser->activeBan
            ?? Ban::query()->where('app_user_id', $this->appUser->id)->active()->latest('starts_at')->first();

        if ($ban === null) {
            session()->flash('error', 'There is no active restriction to lift.');

            return;
        }

        app(ApplyEnforcement::class)->lift(
            ban: $ban,
            actor: auth()->user(),
            reason: ReasonCode::EvidenceInsufficient,
            note: 'Lifted from the member page.',
        );

        session()->flash('status', "{$ban->type->label()} lifted for {$this->appUser->display_name}.");
        $this->redirectRoute('admin.users.show', $this->appUser, navigate: true);
    }

    // ---- plans ----------------------------------------------------------------

    /**
     * Give a member a plan by hand.
     *
     * Support needs this for bank transfers, refunds gone wrong and goodwill,
     * and it is the only way onto a plan until checkout is live. It writes the
     * same subscription record a payment would, so the billing history reads
     * the same either way.
     */
    public function openPlanForm(): void
    {
        $this->authorize('edit_users');

        $this->resetValidation();
        $current = app(Subscriptions::class)->currentFor($this->appUser);

        $this->planSlug = $this->appUser->premium_tier ?? (string) Masters::plans()->first()?->slug;
        $this->planLength = $current?->isOpenEnded() ? 'open' : '1m';
        $this->planEndsAt = $this->appUser->premium_until?->format('Y-m-d');
        $this->planNote = '';
        $this->planFormOpen = true;
    }

    public function closePlanForm(): void
    {
        $this->planFormOpen = false;
    }

    public function savePlan(Subscriptions $subscriptions): void
    {
        $this->authorize('edit_users');

        $this->validate([
            'planSlug' => ['required', 'string', Rule::in(Masters::plans()->pluck('slug')->all())],
            'planLength' => ['required', Rule::in(['1m', '12m', 'custom', 'open'])],
            'planEndsAt' => [Rule::requiredIf($this->planLength === 'custom'), 'nullable', 'date', 'after:today'],
            'planNote' => ['nullable', 'string', 'max:300'],
        ], [
            'planEndsAt.after' => 'Choose a date in the future.',
            'planEndsAt.required' => 'Choose the date the plan should end.',
        ], ['planSlug' => 'plan', 'planEndsAt' => 'end date', 'planNote' => 'note']);

        $plan = Masters::plan($this->planSlug);
        abort_if($plan === null, 404);

        $endsAt = match ($this->planLength) {
            '1m' => now()->addMonth(),
            '12m' => now()->addYear(),
            'custom' => Carbon::parse($this->planEndsAt)->endOfDay(),
            default => null,
        };

        $subscriptions->grant(
            member: $this->appUser,
            plan: $plan,
            endsAt: $endsAt,
            actor: auth()->user(),
            billingPeriod: match ($this->planLength) {
                '1m' => 'monthly',
                '12m' => 'yearly',
                default => 'custom',
            },
            note: $this->planNote ?: null,
        );

        $this->planFormOpen = false;
        $this->appUser->refresh();

        session()->flash('status', $plan->name.' given to '.$this->appUser->display_name
            .($endsAt ? ' until '.veyra_date($endsAt) : ' with no end date').'.');
    }

    public function removePlan(Subscriptions $subscriptions): void
    {
        $this->authorize('edit_users');

        if (! $this->appUser->is_premium) {
            session()->flash('error', 'This member is not on a plan.');

            return;
        }

        $subscriptions->revoke($this->appUser, auth()->user(), 'Removed from the member page');
        $this->appUser->refresh();

        session()->flash('status', 'Plan removed. The member keeps their account and goes back to free.');
    }
}
