<?php

declare(strict_types=1);

namespace App\Livewire\Member;

use App\Enums\ReasonCode;
use App\Livewire\Member\Concerns\InteractsWithMember;
use App\Services\Members\VerificationSubmission;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
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

    public function mount(VerificationSubmission $submission): void
    {
        $this->gestureCode = $submission->issueGestureCode($this->member());
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

        try {
            $verification = $submission->submit($this->member(), $this->selfie, $this->gestureCode);
        } catch (ValidationException) {
            // The code is locked server-side, so on the website this only means
            // it expired while the member was taking the photo. Give them a
            // fresh one rather than an error they cannot act on.
            $this->selfie = null;
            $this->gestureCode = $submission->issueGestureCode($this->member());
            $this->addError('selfie', 'That code expired. Here is a new one — please take the photo again.');

            return;
        }

        $this->selfie = null;

        if ($verification === null) {
            $this->addError('selfie', 'You have used all your verification attempts. Please contact support.');

            return;
        }

        $this->gestureCode = $submission->issueGestureCode($this->member());
        $this->toast('Sent! We will let you know once a reviewer has checked it.');
    }
}
