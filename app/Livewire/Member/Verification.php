<?php

declare(strict_types=1);

namespace App\Livewire\Member;

use App\Enums\ReasonCode;
use App\Livewire\Member\Concerns\InteractsWithMember;
use App\Services\Members\VerificationSubmission;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

class Verification extends Component
{
    use InteractsWithMember;
    use WithFileUploads;

    /**
     * Issued by the server and locked: the member must copy this exact code in
     * the photo, which is what stops a photograph of a photograph passing.
     */
    #[Locked]
    public string $gestureCode = '';

    /** @var TemporaryUploadedFile|null */
    public $selfie = null;

    public function mount(): void
    {
        $this->gestureCode = VerificationSubmission::newGestureCode();
    }

    public function render(VerificationSubmission $submission): View
    {
        $me = $this->member()->load('latestVerification');
        $latest = $me->latestVerification;

        $rejection = $latest?->rejection_reason_code
            ? ReasonCode::tryFrom($latest->rejection_reason_code)
            : null;

        return view('livewire.member.verification', [
            'me' => $me,
            'latest' => $latest,
            'rejectionText' => $rejection?->statement(),
            'attemptsLeft' => $submission->attemptsLeft($me),
        ])->layout('components.layouts.member', ['title' => 'Verification']);
    }

    public function submit(VerificationSubmission $submission): void
    {
        $this->validate(
            ['selfie' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240']],
            ['selfie.required' => 'Take or choose a selfie first.'],
        );

        $verification = $submission->submit($this->member(), $this->selfie, $this->gestureCode);

        $this->selfie = null;

        if ($verification === null) {
            $this->addError('selfie', 'You have used all your verification attempts. Please contact support.');

            return;
        }

        $this->gestureCode = VerificationSubmission::newGestureCode();
        $this->toast('Sent! We will let you know once a reviewer has checked it.');
    }
}
