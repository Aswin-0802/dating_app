<?php

declare(strict_types=1);

namespace App\Livewire\Notifications;

use App\Livewire\Concerns\WithDataTable;
use App\Models\PushCampaign;
use App\Services\Audit\ActivityLogger;
use App\Support\Branding;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
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

        if ($campaign->created_by === auth()->id()) {
            session()->flash('error', 'A campaign has to be approved by somebody other than its author.');

            return;
        }

        $campaign->forceFill([
            'approved_by' => auth()->id(),
            'approved_at' => now(),
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
