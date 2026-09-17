<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasBadge;

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

    public function label(): string
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
     * minor-safety report ends up behind a spam complaint.
     */
    public function defaultSeverity(): Severity
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
