<?php

declare(strict_types=1);

namespace App\Livewire\Appeals;

use App\Actions\Moderation\ApplyEnforcement;
use App\Enums\AppealStatus;
use App\Enums\ReasonCode;
use App\Models\Appeal;
use App\Models\User;
use App\Services\Audit\ActivityLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class Show extends Component
{
    public Appeal $appeal;

    public ?int $assignTo = null;

    public string $decision = '';

    public string $decisionNote = '';

    public function mount(Appeal $appeal): void
    {
        $this->appeal = $appeal->load([
            'appUser.primaryPhoto',
            'appUser.city',
            'appUser.riskScore.factors',
            'ban.issuedBy',
            'moderationAction.actor',
            'originalDecider',
            'assignedTo',
        ]);

        $this->assignTo = $appeal->assigned_to_id;
    }

    public function render(): View
    {
        return view('livewire.appeals.show', [
            'reviewers' => $this->eligibleReviewers(),
            'canDecide' => $this->appeal->canBeDecidedBy(auth()->user()),
            'isOriginalDecider' => $this->appeal->original_decider_id === auth()->id(),
        ])->layout('components.layouts.admin', [
            'title' => 'Appeal',
            'breadcrumbs' => [
                ['label' => 'Veyra', 'href' => route('admin.dashboard')],
                ['label' => 'Appeals', 'href' => route('admin.appeals.index')],
                ['label' => $this->appeal->appUser?->display_name ?? 'Appeal'],
            ],
        ]);
    }

    /**
     * Staff who may review this appeal.
     *
     * The original decider is excluded from the list, not merely blocked at
     * submit: an option you can pick and then be refused teaches nothing, while
     * an absence prompts the question and the answer is on the page.
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    private function eligibleReviewers()
    {
        $permitted = DB::table('permissions')->where('name', 'decide_appeals')->value('id');

        return User::query()
            ->active()
            ->when($this->appeal->original_decider_id, fn ($q, $id) => $q->where('id', '!=', $id))
            ->where(function ($query) use ($permitted): void {
                $query->whereHas('roles.permissions', fn ($q) => $q->where('permissions.id', $permitted))
                    ->orWhereHas('permissions', fn ($q) => $q->where('permissions.id', $permitted));
            })
            ->orderBy('name')
            ->get();
    }

    public function assign(): void
    {
        $this->authorize('assign_appeals');

        $this->validate(['assignTo' => ['required', 'integer', 'exists:users,id']]);

        if ($this->assignTo === $this->appeal->original_decider_id) {
            throw ValidationException::withMessages([
                'assignTo' => 'An appeal cannot be reviewed by whoever made the original decision.',
            ]);
        }

        $this->appeal->forceFill([
            'assigned_to_id' => $this->assignTo,
            'status' => AppealStatus::Assigned,
        ])->save();

        app(ActivityLogger::class)->log(
            module: 'appeals',
            action: 'assigned',
            subject: $this->appeal,
            description: 'Appeal assigned for review',
            new: ['assigned_to_id' => $this->assignTo],
        );

        session()->flash('status', 'Appeal assigned.');
    }

    public function decide(): void
    {
        $this->authorize('decide_appeals');

        // Belt and braces: the UI hides the form, the policy would refuse, and
        // this refuses again. Getting it wrong produces an appeals process that
        // looks legitimate and rubber-stamps everything it reviews.
        if (! $this->appeal->canBeDecidedBy(auth()->user())) {
            throw ValidationException::withMessages([
                'decision' => 'You made the original decision on this case and cannot review the appeal.',
            ]);
        }

        $this->validate([
            'decision' => ['required', 'in:upheld,overturned,partially_overturned'],
            'decisionNote' => ['required', 'string', 'min:20', 'max:2000'],
        ], [
            'decisionNote.min' => 'Explain the decision in at least 20 characters. The member receives this reasoning.',
        ]);

        $status = AppealStatus::from($this->decision);

        DB::transaction(function () use ($status): void {
            $this->appeal->forceFill([
                'status' => $status,
                'decision_note' => $this->decisionNote,
                'decided_at' => now(),
                'assigned_to_id' => auth()->id(),
            ])->save();

            // An overturn actually reverses the enforcement. An appeals process
            // that records a decision without changing anything is theatre.
            if ($status->reversesEnforcement() && $this->appeal->ban && $this->appeal->ban->isActive()) {
                app(ApplyEnforcement::class)->lift(
                    ban: $this->appeal->ban,
                    actor: auth()->user(),
                    reason: ReasonCode::AppealOverturned,
                    note: $this->decisionNote,
                );
            }

            app(ActivityLogger::class)->log(
                module: 'appeals',
                action: $status->value,
                subject: $this->appeal,
                description: "Appeal {$status->label()} for {$this->appeal->appUser?->display_name}",
                new: ['decision' => $status->value, 'note' => $this->decisionNote],
            );
        });

        session()->flash('status', "Appeal {$status->label()}.");

        $this->redirectRoute('admin.appeals.index', navigate: true);
    }
}
