<?php

declare(strict_types=1);

namespace App\Livewire\Verifications;

use App\Actions\Verification\DecideVerification;
use App\Enums\ReasonCode;
use App\Models\Verification;
use App\Support\Branding;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class Review extends Component
{
    public Verification $verification;

    public bool $rejectOpen = false;

    public string $reasonCode = '';

    public string $note = '';

    public function mount(Verification $verification): void
    {
        if ($verification->queue === 'restricted_minor') {
            abort_unless(auth()->user()?->can('restricted_minor_queue'), 404);
        }

        $this->verification = $verification->load([
            'appUser.photos',
            'appUser.city.country',
            'appUser.riskScore.factors',
            'signals',
            'claimedBy',
            'reviewedBy',
        ]);

        $this->claim();
    }

    public function render(): View
    {
        return view('livewire.verifications.review', [
            'duplicateAccounts' => $this->duplicateAccounts(),
            'rejectionReasons' => ReasonCode::forVerificationRejection(),
        ])->layout('components.layouts.admin', [
            'title' => 'Review verification',
            'breadcrumbs' => [
                ['label' => Branding::name(), 'href' => route('admin.dashboard')],
                ['label' => 'Verification', 'href' => route('admin.verifications.index')],
                ['label' => $this->verification->appUser?->display_name ?? 'Submission'],
            ],
        ]);
    }

    /**
     * Claim on open.
     *
     * Collisions — two moderators acting on the same item — are the most
     * commonly reported failure of review queues, and a soft claim on open is
     * the cheapest thing that prevents them.
     */
    public function claim(): void
    {
        if (! auth()->user()?->can('claim_verifications')) {
            return;
        }

        if ($this->verification->claimed_by === null && $this->verification->status->isOpen()) {
            $this->verification->forceFill([
                'claimed_by' => auth()->id(),
                'claimed_at' => now(),
                'status' => 'in_review',
            ])->save();
        }
    }

    public function approve(): void
    {
        $this->authorize('decide_verifications');

        // Approving a suspected minor must not be possible at all, not merely
        // discouraged. Checked here as well as hidden in the UI.
        if (! $this->verification->canBeApproved()) {
            throw ValidationException::withMessages([
                'decision' => 'This submission cannot be approved. It must be escalated or rejected.',
            ]);
        }

        app(DecideVerification::class)->approve($this->verification, auth()->user());

        session()->flash('status', 'Verification approved.');

        $this->redirectRoute('admin.verifications.index', navigate: true);
    }

    public function reject(): void
    {
        $this->authorize('decide_verifications');

        $this->validate([
            // An enumerated code, never free text: rejection reasons have to be
            // comparable across moderators and defensible in an appeal.
            'reasonCode' => ['required', 'string'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $reason = ReasonCode::tryFrom($this->reasonCode);

        if ($reason === null) {
            throw ValidationException::withMessages(['reasonCode' => 'Choose a rejection reason.']);
        }

        if ($reason->requiresNote() && blank($this->note)) {
            throw ValidationException::withMessages([
                'note' => 'This reason needs a written explanation.',
            ]);
        }

        app(DecideVerification::class)->reject($this->verification, auth()->user(), $reason, $this->note);

        session()->flash('status', 'Verification rejected.');

        $this->redirectRoute('admin.verifications.index', navigate: true);
    }

    public function escalate(): void
    {
        $this->authorize('decide_verifications');

        app(DecideVerification::class)->escalate($this->verification, auth()->user(), $this->note);

        session()->flash('status', 'Escalated to the restricted queue.');

        $this->redirectRoute('admin.verifications.index', navigate: true);
    }

    /**
     * Other accounts showing this face.
     *
     * The decisive signal on this screen: one face across several accounts is
     * the clearest evidence of a stolen identity or a ban evader there is.
     */
    private function duplicateAccounts()
    {
        return $this->verification
            ->duplicateFaceAccounts()
            ->with('primaryPhoto')
            ->limit(10)
            ->get();
    }
}
