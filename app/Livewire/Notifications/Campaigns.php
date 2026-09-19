<?php

declare(strict_types=1);

namespace App\Livewire\Notifications;

use App\Enums\AccountStatus;
use App\Enums\VerificationStatus;
use App\Livewire\Concerns\WithDataTable;
use App\Models\AppUser;
use App\Models\PushCampaign;
use App\Services\Audit\ActivityLogger;
use App\Support\Branding;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;

class Campaigns extends Component
{
    use WithDataTable;

    #[Url(except: '')]
    public string $status = '';

    public function mount(): void
    {
        $this->mountWithDataTable();
    }

    public function render(): View
    {
        $campaigns = $this->applySort($this->baseQuery())
            ->with(['createdBy', 'approvedBy', 'template'])
            ->paginate($this->perPage);

        return view('livewire.notifications.campaigns', [
            'campaigns' => $campaigns,
            'awaitingApproval' => PushCampaign::query()->whereNull('approved_at')
                ->whereNotIn('status', ['sent', 'cancelled'])->count(),
        ])->layout('components.layouts.admin', [
            'title' => 'Campaigns',
            'breadcrumbs' => [
                ['label' => Branding::name(), 'href' => route('admin.dashboard')],
                ['label' => 'Notifications'],
                ['label' => 'Campaigns'],
            ],
        ]);
    }

    protected function sortableFields(): array
    {
        return ['created_at', 'scheduled_for', 'sent_count'];
    }

    protected function baseQuery(): Builder
    {
        return PushCampaign::query()
            ->when($this->status !== '', fn (Builder $q) => $q->where('status', $this->status))
            ->when($this->search !== '', fn (Builder $q) => $q->where('name', 'like', "%{$this->search}%"));
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['status', 'search'], true)) {
            $this->resetPage();
        }
    }

    // ---- new campaign ---------------------------------------------------------

    /** Who a campaign can go to. Kept to rules that are safe to message. */
    public const AUDIENCES = [
        'all' => 'All active members',
        'inactive_14' => 'Members inactive for 14+ days',
        'unverified' => 'Members not yet photo-verified',
        'incomplete' => 'Members with an incomplete profile',
        'premium' => 'Premium members',
        'free' => 'Free members',
    ];

    public const LINKS = [
        '' => 'Open the app',
        'discover' => 'Discover',
        'matches' => 'Matches',
        'messages' => 'Messages',
        'profile' => 'Edit profile',
        'verification' => 'Verification',
        'premium' => 'Premium',
    ];

    public bool $formOpen = false;

    public string $formName = '';

    public string $formTitle = '';

    public string $formBody = '';

    public string $formAudience = 'all';

    public string $formLink = '';

    public ?string $formScheduledFor = null;

    public function create(): void
    {
        $this->authorize('create_campaigns');
        $this->resetValidation();
        $this->reset('formName', 'formTitle', 'formBody', 'formLink', 'formScheduledFor');
        $this->formAudience = 'all';
        $this->formOpen = true;
    }

    public function closeForm(): void
    {
        $this->formOpen = false;
    }

    public function audienceSize(string $audience): int
    {
        return $this->audienceQuery($audience)->count();
    }

    public function saveCampaign(ActivityLogger $logger): void
    {
        $this->authorize('create_campaigns');

        $this->validate([
            'formName' => ['required', 'string', 'min:3', 'max:80'],
            'formTitle' => ['required', 'string', 'min:3', 'max:65'],
            'formBody' => ['required', 'string', 'min:10', 'max:180'],
            'formAudience' => ['required', Rule::in(array_keys(self::AUDIENCES))],
            'formLink' => ['nullable', Rule::in(array_keys(self::LINKS))],
            'formScheduledFor' => ['nullable', 'date', 'after:now', 'before:'.now()->addDays(60)->toDateTimeString()],
        ], [
            'formScheduledFor.after' => 'Choose a time in the future, or leave it empty to send once approved.',
            'formScheduledFor.before' => 'Campaigns can be scheduled up to 60 days ahead.',
        ], [
            'formName' => 'campaign name', 'formTitle' => 'title', 'formBody' => 'message',
            'formAudience' => 'audience', 'formScheduledFor' => 'send time',
        ]);

        $recipients = $this->audienceSize($this->formAudience);

        if ($recipients === 0) {
            $this->addError('formAudience', 'Nobody matches this audience right now.');

            return;
        }

        $campaign = PushCampaign::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => trim($this->formName),
            'title' => trim($this->formTitle),
            'body' => trim($this->formBody),
            'deep_link' => $this->formLink !== '' ? 'app://'.$this->formLink : null,
            'audience_filters' => ['audience' => $this->formAudience],
            'audience_label' => self::AUDIENCES[$this->formAudience],
            'estimated_recipients' => $recipients,
            'status' => 'draft',
            'scheduled_for' => $this->formScheduledFor ? Carbon::parse($this->formScheduledFor) : null,
            'created_by' => auth()->id(),
        ]);

        $logger->log(module: 'notifications', action: 'campaign_created', subject: $campaign, description: "Created campaign \"{$campaign->name}\" to {$recipients} recipients");

        $this->formOpen = false;
        session()->flash('status', 'Campaign saved as a draft. Another team member needs to approve it before it is sent.');
    }

    private function audienceQuery(string $audience): Builder
    {
        $query = AppUser::query()->where('account_status', AccountStatus::Active->value);

        return match ($audience) {
            'inactive_14' => $query->where('last_active_at', '<', now()->subDays(14)),
            'unverified' => $query->where('verification_status', '!=', VerificationStatus::Approved->value),
            'incomplete' => $query->where('profile_completion', '<', 50),
            'premium' => $query->where('is_premium', true),
            'free' => $query->where('is_premium', false),
            default => $query,
        };
    }

    /**
     * Approve a campaign for sending.
     *
     * Somebody other than the author has to read it first. A push reaches every
     * member at once and cannot be recalled, which makes it the one action in
     * the console where a second pair of eyes is worth the friction.
     */
    public function approve(int $campaignId): void
    {
        $this->authorize('approve_campaigns');

        $campaign = PushCampaign::query()->findOrFail($campaignId);

        if (! in_array($campaign->status, ['draft', 'scheduled'], true) || $campaign->approved_at !== null) {
            session()->flash('error', 'Only a draft or scheduled campaign that has not been approved yet can be approved.');

            return;
        }

        if ($campaign->created_by === auth()->id()) {
            session()->flash('error', 'A campaign has to be approved by somebody other than its author.');

            return;
        }

        $campaign->forceFill([
            'approved_by' => auth()->id(),
            'approved_at' => now(),
            // Approval is what schedules it: immediately unless a time was set.
            'status' => 'scheduled',
            'scheduled_for' => $campaign->scheduled_for ?? now(),
        ])->save();

        app(ActivityLogger::class)->log(
            module: 'notifications',
            action: 'campaign_approved',
            subject: $campaign,
            description: "Approved campaign \"{$campaign->name}\" to {$campaign->estimated_recipients} recipients",
        );

        session()->flash('status', 'Campaign approved.');
    }

    public function cancel(int $campaignId): void
    {
        $this->authorize('send_notifications');

        $campaign = PushCampaign::query()->findOrFail($campaignId);

        // Once a push has gone out it cannot be recalled, so it cannot be
        // "cancelled" either — that would misstate what members received.
        if (! in_array($campaign->status, ['draft', 'scheduled'], true)) {
            session()->flash('error', 'Only a draft or scheduled campaign can be cancelled.');

            return;
        }

        $campaign->forceFill(['status' => 'cancelled'])->save();

        app(ActivityLogger::class)->log(
            module: 'notifications',
            action: 'campaign_cancelled',
            subject: $campaign,
            description: "Cancelled campaign \"{$campaign->name}\"",
        );

        session()->flash('status', 'Campaign cancelled.');
    }
}
