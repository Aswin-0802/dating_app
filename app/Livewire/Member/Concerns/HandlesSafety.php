<?php

declare(strict_types=1);

namespace App\Livewire\Member\Concerns;

use App\Enums\ReportCategory;
use App\Models\AppUser;
use App\Services\Members\SafetyActions;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The report and block dialog, shared by profiles and conversations.
 *
 * Needs InteractsWithMember alongside it.
 */
trait HandlesSafety
{
    public ?string $reportingUuid = null;

    public string $reportCategory = '';

    public string $reportDetails = '';

    public bool $alsoBlock = true;

    /** Set when reporting from inside a conversation, so the case has evidence. */
    public ?string $reportMessageUuid = null;

    public function openReport(string $uuid, ?string $messageUuid = null): void
    {
        $this->resetValidation();
        $this->reportingUuid = $uuid;
        $this->reportMessageUuid = $messageUuid;
        $this->reportCategory = '';
        $this->reportDetails = '';
        $this->alsoBlock = true;
    }

    public function closeReport(): void
    {
        $this->reportingUuid = null;
    }

    public function submitReport(SafetyActions $safety): void
    {
        $this->validate([
            'reportCategory' => ['required', Rule::enum(ReportCategory::class)],
            'reportDetails' => ['nullable', 'string', 'max:2000'],
        ], ['reportCategory.required' => 'Choose what happened.']);

        $reported = AppUser::query()->where('uuid', $this->reportingUuid)->firstOrFail();

        try {
            $safety->report(
                $this->member(),
                $reported,
                ReportCategory::from($this->reportCategory),
                filled($this->reportDetails) ? $this->reportDetails : null,
                $this->reportMessageUuid,
            );
        } catch (ValidationException $e) {
            // Shown on the form rather than as a field the member cannot see.
            $this->addError('reportCategory', collect($e->errors())->flatten()->first());

            return;
        }

        if ($this->alsoBlock) {
            $safety->block($this->member(), $reported, 'reported');
        }

        $this->reportingUuid = null;
        $this->afterSafetyAction($this->alsoBlock);
        $this->toast('Thank you. Our safety team will review this.'.($this->alsoBlock ? ' They can no longer see you.' : ''));
    }

    public function blockMember(string $uuid, SafetyActions $safety): void
    {
        $safety->block($this->member(), AppUser::query()->where('uuid', $uuid)->firstOrFail());

        $this->afterSafetyAction(true);
        $this->toast('Blocked. You will not see each other again.');
    }

    /**
     * Report categories as the reporter sees them, grouped and in plain words.
     *
     * @return array<string, array<string, string>>
     */
    public function reportCategories(): array
    {
        return collect(ReportCategory::cases())
            // Minor safety is the staff-side label for the same concern as
            // "suspected underage"; one option is clearer for a reporter.
            ->reject(fn (ReportCategory $c): bool => $c === ReportCategory::MinorSafety)
            ->groupBy(fn (ReportCategory $c): string => $c->group())
            ->map(fn ($group) => $group->mapWithKeys(fn (ReportCategory $c): array => [$c->value => $c->label()])->all())
            ->all();
    }

    /** Hook for the host component, e.g. to leave a conversation that is now closed. */
    protected function afterSafetyAction(bool $blocked): void {}
}
