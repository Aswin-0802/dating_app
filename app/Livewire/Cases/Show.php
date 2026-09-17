<?php

declare(strict_types=1);

namespace App\Livewire\Cases;

use App\Actions\Moderation\ApplyEnforcement;
use App\Enums\CaseStatus;
use App\Enums\LadderStep;
use App\Enums\ReasonCode;
use App\Models\Message;
use App\Models\ReportCase;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class Show extends Component
{
    public ReportCase $reportCase;

    /** The ladder step whose confirmation form is open, if any. */
    public string $pendingStep = '';

    public string $reasonCode = '';

    public string $note = '';

    public ?int $durationHours = null;

    public array $limitedFeatures = [];

    public bool $notifyUser = true;

    public ?string $reviewDueAt = null;

    public function mount(ReportCase $reportCase): void
    {
        $this->reportCase = $reportCase->load([
            'subject.primaryPhoto',
            'subject.city.country',
            'subject.riskScore.factors',
            'subject.activeBan',
            'reports.reporter',
            'reports.message',
            'actions.actor',
            'claimedBy',
        ]);

        $this->claim();
        $this->notifyUser = (bool) veyra_setting('enforcement.notify_user_default', true);
    }

    public function render(): View
    {
        return view('livewire.cases.show', [
            'ladder' => LadderStep::decisionBar(),
            'reasons' => ReasonCode::grouped(),
            'priorActions' => $this->priorActions(),
            'evidence' => $this->evidence(),
        ])->layout('components.layouts.admin', [
            'title' => $this->reportCase->case_number,
            'breadcrumbs' => [
                ['label' => 'Veyra', 'href' => route('admin.dashboard')],
                ['label' => 'Cases', 'href' => route('admin.cases.index')],
                ['label' => $this->reportCase->case_number],
            ],
        ]);
    }

    /**
     * Claim on open.
     *
     * Two moderators acting on the same case is the most commonly reported
     * failure of review queues, and a soft claim is the cheapest prevention.
     */
    public function claim(): void
    {
        if (! auth()->user()?->can('claim_cases')) {
            return;
        }

        if (! veyra_setting('moderation.auto_claim_on_open', true)) {
            return;
        }

        if ($this->reportCase->claimed_by === null && $this->reportCase->status->isOpen()) {
            $this->reportCase->forceFill([
                'claimed_by' => auth()->id(),
                'claimed_at' => now(),
                'status' => CaseStatus::InReview,
            ])->save();
        }
    }

    public function release(): void
    {
        if ($this->reportCase->claimed_by === auth()->id()) {
            $this->reportCase->forceFill([
                'claimed_by' => null,
                'claimed_at' => null,
                'status' => CaseStatus::New,
            ])->save();
        }
    }

    public function openStep(string $step): void
    {
        $this->reset(['reasonCode', 'note', 'durationHours', 'limitedFeatures', 'reviewDueAt']);
        $this->resetErrorBag();

        $this->pendingStep = $step;

        $ladderStep = LadderStep::tryFrom($step);

        // Sensible defaults so the common case is one click plus a reason.
        if ($ladderStep?->requiresDuration()) {
            $this->durationHours = 168;
        }

        if ($ladderStep?->requiresReviewDate()) {
            $hours = (int) veyra_setting('enforcement.shadow_ban_review_hours', 168);
            $this->reviewDueAt = now()->addHours($hours)->format('Y-m-d\TH:i');
        }
    }

    public function cancelStep(): void
    {
        $this->pendingStep = '';
        $this->resetErrorBag();
    }

    public function confirmStep(): void
    {
        $step = LadderStep::tryFrom($this->pendingStep);

        if ($step === null) {
            return;
        }

        $this->authorize($this->permissionFor($step));

        $this->validate([
            'reasonCode' => ['required', 'string'],
            'note' => ['nullable', 'string', 'max:2000'],
            'durationHours' => [$step->requiresDuration() ? 'required' : 'nullable', 'integer', 'min:1'],
            'reviewDueAt' => [$step->requiresReviewDate() ? 'required' : 'nullable', 'date'],
        ]);

        $reason = ReasonCode::tryFrom($this->reasonCode);

        if ($reason === null) {
            throw ValidationException::withMessages(['reasonCode' => 'Choose a reason code.']);
        }

        if (veyra_setting('moderation.require_note_on_ban', true)
            && $step->isDestructive()
            && blank($this->note)) {
            throw ValidationException::withMessages([
                'note' => 'An internal note is required for this action.',
            ]);
        }

        app(ApplyEnforcement::class)->apply(
            subject: $this->reportCase->subject,
            step: $step,
            reason: $reason,
            actor: auth()->user(),
            case: $this->reportCase,
            note: $this->note ?: null,
            durationHours: $step->requiresDuration() ? $this->durationHours : null,
            limitedFeatures: $this->limitedFeatures,
            reviewDueAt: $this->reviewDueAt ? new \DateTimeImmutable($this->reviewDueAt) : null,
            notifyUser: $this->notifyUser,
        );

        session()->flash('status', "{$step->label()} applied to {$this->reportCase->subject?->display_name}.");

        $this->redirectRoute('admin.cases.index', navigate: true);
    }

    public function close(): void
    {
        $this->authorize('close_cases');

        $this->reportCase->forceFill([
            'status' => CaseStatus::Closed,
            'resolved_by' => auth()->id(),
            'resolved_at' => now(),
            'outcome' => 'No action',
        ])->save();

        session()->flash('status', 'Case closed with no action.');

        $this->redirectRoute('admin.cases.index', navigate: true);
    }

    private function permissionFor(LadderStep $step): string
    {
        return match ($step) {
            LadderStep::Warn => 'warn_users',
            LadderStep::FeatureLimit => 'limit_users',
            LadderStep::ShadowBan => 'shadow_ban_users',
            LadderStep::Suspend => 'suspend_users',
            LadderStep::PermanentBan => 'ban_users',
            LadderStep::DeviceBan => 'device_ban_users',
            default => 'cases',
        };
    }

    /**
     * Everything this member has been actioned for before.
     *
     * Surfaced as an explicit count because a history spread across a dozen rows
     * is a history nobody reads, and repeat offending is the single most useful
     * thing to know before choosing a rung.
     */
    private function priorActions()
    {
        return $this->reportCase->subject
            ?->moderationActions()
            ->where('report_case_id', '!=', $this->reportCase->id)
            ->with('actor')
            ->limit(10)
            ->get() ?? collect();
    }

    /**
     * The anchored message with context either side.
     *
     * Moderators routinely leave the queue to go and find context; putting it
     * in the review pane is the fix. Content itself stays hidden unless the
     * viewer holds `view_message_content` — the shape is usually enough to
     * triage, and the reveal is a logged act.
     */
    private function evidence(): array
    {
        $anchor = $this->reportCase->reports->firstWhere('message_id', '!=', null)?->message;

        if ($anchor === null) {
            return ['anchor' => null, 'context' => collect()];
        }

        $window = (int) veyra_setting('privacy.message_context_window', 10);

        $context = Message::query()
            ->where('conversation_id', $anchor->conversation_id)
            ->with('sender:id,display_name')
            ->orderBy('created_at')
            ->get()
            ->pipe(function ($messages) use ($anchor, $window) {
                $index = $messages->search(fn (Message $m): bool => $m->id === $anchor->id);

                return $index === false
                    ? $messages->take($window)
                    : $messages->slice(max(0, $index - $window), $window * 2 + 1);
            });

        return ['anchor' => $anchor, 'context' => $context];
    }
}
