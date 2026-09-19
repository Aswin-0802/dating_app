<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasBadge;
use App\Support\Masters;

enum ReportCategory: string
{
    use HasBadge;

    case Harassment = 'harassment';
    case SexualContent = 'sexual_content';
    case Nudity = 'nudity';
    case FakeProfile = 'fake_profile';
    case Impersonation = 'impersonation';
    case ScamFraud = 'scam_fraud';
    case SpamPromotion = 'spam_promotion';
    case OffPlatformSolicitation = 'off_platform_solicitation';
    case Prostitution = 'prostitution';
    case HateSpeech = 'hate_speech';
    case ViolenceThreats = 'violence_threats';
    case SelfHarm = 'self_harm';
    case UnderageSuspected = 'underage_suspected';
    case MinorSafety = 'minor_safety';
    case Other = 'other';

    /** The wording set in Masters -> Report categories, or the built-in one. */
    public function label(): string
    {
        return Masters::reportCategory($this->value)['label'] ?? $this->builtInLabel();
    }

    public function description(): ?string
    {
        return Masters::reportCategory($this->value)['description'] ?? null;
    }

    public function builtInLabel(): string
    {
        return match ($this) {
            self::Harassment => 'Harassment or abuse',
            self::SexualContent => 'Unsolicited sexual content',
            self::Nudity => 'Nudity',
            self::FakeProfile => 'Fake profile',
            self::Impersonation => 'Impersonation',
            self::ScamFraud => 'Romance scam or fraud',
            self::SpamPromotion => 'Spam or promotion',
            self::OffPlatformSolicitation => 'Off-platform solicitation',
            self::Prostitution => 'Solicitation / sex work',
            self::HateSpeech => 'Hate speech',
            self::ViolenceThreats => 'Violence or threats',
            self::SelfHarm => 'Self-harm concern',
            self::UnderageSuspected => 'Suspected underage user',
            self::MinorSafety => 'Minor safety',
            self::Other => 'Other',
        };
    }

    /**
     * Severity is derived from category, not chosen by the reporter.
     *
     * Reporters routinely mislabel severity, and letting them set it is how a
     * minor-safety report ends up behind a spam complaint. Operators can re-rate
     * a category in Masters, except the restricted ones, which stay Critical.
     */
    public function defaultSeverity(): Severity
    {
        $override = Masters::reportCategory($this->value)['severity'] ?? null;

        if ($this->isRestricted() || $override === null) {
            return $this->builtInSeverity();
        }

        return Severity::tryFrom($override) ?? $this->builtInSeverity();
    }

    public function builtInSeverity(): Severity
    {
        return match ($this) {
            self::MinorSafety, self::UnderageSuspected, self::SelfHarm, self::ViolenceThreats => Severity::Critical,
            self::ScamFraud, self::HateSpeech, self::SexualContent, self::Impersonation => Severity::High,
            self::Harassment, self::FakeProfile, self::Nudity, self::Prostitution, self::OffPlatformSolicitation => Severity::Medium,
            self::SpamPromotion, self::Other => Severity::Low,
        };
    }

    /**
     * Categories that must never enter the standard queue. These route to a
     * restricted queue that is invisible — not merely disabled — without the
     * dedicated permission.
     */
    public function isRestricted(): bool
    {
        return in_array($this, [self::MinorSafety, self::UnderageSuspected], true);
    }

    /**
     * Whether members can pick this category. Safety categories can never be
     * switched off: a member must always be able to report a child at risk.
     */
    public function isActive(): bool
    {
        if ($this->isRestricted() || $this === self::SelfHarm || $this === self::Other) {
            return true;
        }

        return Masters::reportCategory($this->value)['is_active'] ?? true;
    }

    public function isLocked(): bool
    {
        return $this->isRestricted() || $this === self::SelfHarm || $this === self::Other;
    }

    /** @return array<int, self> categories members can choose, in the operator's order */
    public static function selectable(): array
    {
        return collect(self::cases())
            ->filter(fn (self $c): bool => $c->isActive())
            ->sortBy(fn (self $c): int => Masters::reportCategory($c->value)['sort_order'] ?? array_search($c, self::cases(), true))
            ->values()
            ->all();
    }

    public function badgeClasses(): string
    {
        return $this->defaultSeverity()->badgeClasses();
    }

    /** Grouping used by the filter dropdown so 15 categories stay navigable. */
    public function group(): string
    {
        return match ($this) {
            self::Harassment, self::HateSpeech, self::ViolenceThreats => 'Abuse',
            self::SexualContent, self::Nudity, self::Prostitution => 'Sexual content',
            self::FakeProfile, self::Impersonation, self::ScamFraud, self::SpamPromotion, self::OffPlatformSolicitation => 'Deception',
            self::SelfHarm, self::UnderageSuspected, self::MinorSafety => 'Safety',
            self::Other => 'Other',
        };
    }
}
