<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Actions\Moderation\ApplyEnforcement;
use App\Enums\LadderStep;
use App\Enums\ReasonCode;
use App\Models\AppUser;
use App\Models\ReportCase;
use Illuminate\Validation\ValidationException;

/**
 * The enforcement form — reason code, duration, review date, note, notify —
 * for any screen that acts on members outside a case: the member page and the
 * member list's bulk actions.
 *
 * Everything goes through ApplyEnforcement, exactly as from a case, so the
 * audit trail, the ban and the account mirror are written the same way however
 * the decision was reached.
 */
trait AppliesEnforcement
{
    public string $pendingStep = '';

    public string $reasonCode = '';

    public string $note = '';

    public ?int $durationHours = null;

    /** @var array<int, string> */
    public array $limitedFeatures = [];

    public bool $notifyUser = true;

    public ?string $reviewDueAt = null;

    public function openStep(string $step): void
    {
        $ladderStep = LadderStep::tryFrom($step);

        abort_if($ladderStep === null, 404);
        $this->authorize(self::permissionForStep($ladderStep));

        $this->reset(['reasonCode', 'note', 'durationHours', 'limitedFeatures', 'reviewDueAt']);
        $this->notifyUser = true;
        $this->resetErrorBag();
        $this->pendingStep = $step;

        if ($ladderStep->requiresDuration()) {
            $this->durationHours = 168;
        }

        if ($ladderStep->requiresReviewDate()) {
            $hours = (int) platform_setting('enforcement.shadow_ban_review_hours', 168);
            $this->reviewDueAt = now()->addHours($hours)->format('Y-m-d\TH:i');
        }
    }

    public function cancelStep(): void
    {
        $this->pendingStep = '';
        $this->resetErrorBag();
    }

    /**
     * Applies the open step to every given member and returns how many were
     * actioned.
     *
     * @param  iterable<AppUser>  $subjects
     */
    protected function applyStepTo(iterable $subjects, ?ReportCase $case = null): int
    {
        $step = LadderStep::tryFrom($this->pendingStep);

        if ($step === null) {
            return 0;
        }

        $this->authorize(self::permissionForStep($step));

        $maxShadowHours = (int) platform_setting('enforcement.shadow_ban_max_hours', 2160);

        $this->validate([
            'reasonCode' => ['required', 'string'],
            'note' => ['nullable', 'string', 'max:2000'],
            'durationHours' => [$step->requiresDuration() ? 'required' : 'nullable', 'integer', 'in:24,168,720'],
            'reviewDueAt' => [
                $step->requiresReviewDate() ? 'required' : 'nullable', 'date', 'after:now',
                'before_or_equal:'.now()->addHours($maxShadowHours)->toDateTimeString(),
            ],
        ], [
            'reasonCode.required' => 'Choose a reason.',
            'reviewDueAt.after' => 'The review date must be in the future.',
            'reviewDueAt.before_or_equal' => 'A shadow ban must be reviewed within '.intdiv($maxShadowHours, 24).' days.',
        ]);

        $reason = ReasonCode::tryFrom($this->reasonCode)
            ?? throw ValidationException::withMessages(['reasonCode' => 'Choose a reason.']);

        if (platform_setting('moderation.require_note_on_ban', true) && $step->isDestructive() && blank($this->note)) {
            throw ValidationException::withMessages(['note' => 'An internal note is required for this action.']);
        }

        if ($step === LadderStep::FeatureLimit && $this->limitedFeatures === []) {
            throw ValidationException::withMessages(['limitedFeatures' => 'Choose at least one feature to limit.']);
        }

        $count = 0;

        foreach ($subjects as $subject) {
            app(ApplyEnforcement::class)->apply(
                subject: $subject,
                step: $step,
                reason: $reason,
                actor: auth()->user(),
                case: $case,
                note: $this->note ?: null,
                durationHours: $step->requiresDuration() ? $this->durationHours : null,
                limitedFeatures: $this->limitedFeatures,
                reviewDueAt: $this->reviewDueAt ? new \DateTimeImmutable($this->reviewDueAt) : null,
                notifyUser: $this->notifyUser,
            );
            $count++;
        }

        $this->pendingStep = '';

        return $count;
    }

    public static function permissionForStep(LadderStep $step): string
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
}
