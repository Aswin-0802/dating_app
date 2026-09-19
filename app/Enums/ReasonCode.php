<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasBadge;
use App\Support\Masters;

/**
 * Structured reasons for every enforcement and verification decision.
 *
 * One source of truth, used three ways: it populates the admin's select, it is
 * stored on the immutable moderation action, and it generates the user-facing
 * statement the API returns on a 403. That last use is why the statement text
 * and the policy clause live here rather than being typed by a moderator —
 * a statement of reasons has to be consistent across every decision.
 */
enum ReasonCode: string
{
    use HasBadge;

    // --- content & conduct ---
    case HarassmentConfirmed = 'harassment_confirmed';
    case HateSpeechConfirmed = 'hate_speech_confirmed';
    case ThreatOfViolence = 'threat_of_violence';
    case UnsolicitedSexualContent = 'unsolicited_sexual_content';
    case ProhibitedNudity = 'prohibited_nudity';

    // --- authenticity ---
    case FakeProfileConfirmed = 'fake_profile_confirmed';
    case ImpersonationConfirmed = 'impersonation_confirmed';
    case StolenPhotos = 'stolen_photos';
    case DuplicateAccount = 'duplicate_account';

    // --- commercial & fraud ---
    case RomanceScam = 'romance_scam';
    case FinancialSolicitation = 'financial_solicitation';
    case SpamOrAdvertising = 'spam_or_advertising';
    case OffPlatformSolicitation = 'off_platform_solicitation';
    case CommercialSexualServices = 'commercial_sexual_services';

    // --- safety (restricted) ---
    case UnderageUser = 'underage_user';
    case MinorSafetyConcern = 'minor_safety_concern';
    case SelfHarmConcern = 'self_harm_concern';

    // --- verification ---
    case FaceMismatch = 'face_mismatch';
    case LivenessFailed = 'liveness_failed';
    case NotARealPerson = 'not_a_real_person';
    case FaceObscured = 'face_obscured';
    case MultiplePeopleInFrame = 'multiple_people_in_frame';
    case DuplicateFaceAcrossAccounts = 'duplicate_face_across_accounts';
    case AgeEstimateConflict = 'age_estimate_conflict';
    case PoorImageQuality = 'poor_image_quality';

    // --- process ---
    case EvidenceInsufficient = 'evidence_insufficient';
    case AppealUpheld = 'appeal_upheld';
    case AppealOverturned = 'appeal_overturned';
    case ModeratorError = 'moderator_error';
    case ExpiredAutomatically = 'expired_automatically';
    case Other = 'other';

    /** The wording set in Masters -> Enforcement reasons, or the built-in one. */
    public function label(): string
    {
        return Masters::reason($this->value)['label'] ?? $this->builtInLabel();
    }

    public function policyClause(): string
    {
        return Masters::reason($this->value)['policy_clause'] ?? $this->builtInPolicyClause();
    }

    /** Template for the statement of reasons sent to the member. */
    public function statement(): string
    {
        return Masters::reason($this->value)['statement'] ?? $this->builtInStatement();
    }

    /**
     * Reasons the system itself records (appeal outcomes, expiry, corrections)
     * cannot be switched off, or those flows would have nothing to write.
     */
    public function isSystem(): bool
    {
        return in_array($this, [
            self::EvidenceInsufficient, self::AppealUpheld, self::AppealOverturned,
            self::ModeratorError, self::ExpiredAutomatically, self::Other,
        ], true);
    }

    public function isActive(): bool
    {
        return $this->isSystem() || (Masters::reason($this->value)['is_active'] ?? true);
    }

    public function builtInLabel(): string
    {
        return match ($this) {
            self::HarassmentConfirmed => 'Harassment confirmed',
            self::HateSpeechConfirmed => 'Hate speech confirmed',
            self::ThreatOfViolence => 'Threat of violence',
            self::UnsolicitedSexualContent => 'Unsolicited sexual content',
            self::ProhibitedNudity => 'Prohibited nudity',
            self::FakeProfileConfirmed => 'Fake profile confirmed',
            self::ImpersonationConfirmed => 'Impersonation confirmed',
            self::StolenPhotos => 'Photos taken from another person',
            self::DuplicateAccount => 'Duplicate account',
            self::RomanceScam => 'Romance scam',
            self::FinancialSolicitation => 'Requests for money',
            self::SpamOrAdvertising => 'Spam or advertising',
            self::OffPlatformSolicitation => 'Off-platform solicitation',
            self::CommercialSexualServices => 'Commercial sexual services',
            self::UnderageUser => 'User is under 18',
            self::MinorSafetyConcern => 'Minor safety concern',
            self::SelfHarmConcern => 'Self-harm concern',
            self::FaceMismatch => 'Face does not match profile photos',
            self::LivenessFailed => 'Liveness check failed',
            self::NotARealPerson => 'Submission is not of a real person',
            self::FaceObscured => 'Face obscured',
            self::MultiplePeopleInFrame => 'Multiple people in frame',
            self::DuplicateFaceAcrossAccounts => 'Same face on another account',
            self::AgeEstimateConflict => 'Estimated age conflicts with stated age',
            self::PoorImageQuality => 'Image quality too low to assess',
            self::EvidenceInsufficient => 'Insufficient evidence',
            self::AppealUpheld => 'Appeal reviewed, decision upheld',
            self::AppealOverturned => 'Appeal reviewed, decision overturned',
            self::ModeratorError => 'Moderator error',
            self::ExpiredAutomatically => 'Expired automatically',
            self::Other => 'Other (explain in note)',
        };
    }

    /**
     * The Terms clause the decision rests on. DSA Article 17 requires telling the
     * user the specific clause, not a vague category.
     */
    public function builtInPolicyClause(): string
    {
        return match ($this) {
            self::HarassmentConfirmed, self::HateSpeechConfirmed, self::ThreatOfViolence => '4.2 Respectful conduct',
            self::UnsolicitedSexualContent, self::ProhibitedNudity, self::CommercialSexualServices => '4.3 Sexual content',
            self::FakeProfileConfirmed, self::ImpersonationConfirmed, self::StolenPhotos, self::DuplicateAccount => '3.1 Authentic identity',
            self::RomanceScam, self::FinancialSolicitation, self::SpamOrAdvertising, self::OffPlatformSolicitation => '5.1 Fraud and commercial use',
            self::UnderageUser, self::MinorSafetyConcern => '2.1 Minimum age',
            self::SelfHarmConcern => '4.6 Wellbeing',
            self::FaceMismatch, self::LivenessFailed, self::NotARealPerson, self::FaceObscured,
            self::MultiplePeopleInFrame, self::DuplicateFaceAcrossAccounts, self::AgeEstimateConflict,
            self::PoorImageQuality => '3.2 Photo verification',
            default => '1.0 General terms',
        };
    }

    public function builtInStatement(): string
    {
        return match ($this) {
            self::Other => 'Your account was actioned following a review of reported activity.',
            self::AppealOverturned => 'We reviewed your appeal and have reversed the earlier decision. The restriction on your account has been lifted.',
            self::AppealUpheld => 'We reviewed your appeal. The original decision stands.',
            default => sprintf(
                'We reviewed activity on your account and found it did not meet our %s policy (%s).',
                strtolower($this->builtInLabel()),
                $this->builtInPolicyClause(),
            ),
        };
    }

    /** Free text is mandatory when the code cannot speak for itself. */
    public function requiresNote(): bool
    {
        return in_array($this, [self::Other, self::ModeratorError, self::EvidenceInsufficient], true);
    }

    /**
     * Codes only selectable by staff holding the restricted-queue permission.
     */
    public function isRestricted(): bool
    {
        return in_array($this, [self::UnderageUser, self::MinorSafetyConcern], true);
    }

    public function group(): string
    {
        return match ($this) {
            self::HarassmentConfirmed, self::HateSpeechConfirmed, self::ThreatOfViolence,
            self::UnsolicitedSexualContent, self::ProhibitedNudity => 'Content & conduct',
            self::FakeProfileConfirmed, self::ImpersonationConfirmed, self::StolenPhotos,
            self::DuplicateAccount => 'Authenticity',
            self::RomanceScam, self::FinancialSolicitation, self::SpamOrAdvertising,
            self::OffPlatformSolicitation, self::CommercialSexualServices => 'Fraud & commercial',
            self::UnderageUser, self::MinorSafetyConcern, self::SelfHarmConcern => 'Safety',
            self::FaceMismatch, self::LivenessFailed, self::NotARealPerson, self::FaceObscured,
            self::MultiplePeopleInFrame, self::DuplicateFaceAcrossAccounts,
            self::AgeEstimateConflict, self::PoorImageQuality => 'Verification',
            default => 'Process',
        };
    }

    public function badgeClasses(): string
    {
        return 'bg-muted text-muted-foreground';
    }

    /** @return array<int, self> The enumerated rejection list on the review screen. */
    public static function forVerificationRejection(): array
    {
        return array_values(array_filter([
            self::FaceMismatch,
            self::LivenessFailed,
            self::NotARealPerson,
            self::FaceObscured,
            self::MultiplePeopleInFrame,
            self::DuplicateFaceAcrossAccounts,
            self::AgeEstimateConflict,
            self::PoorImageQuality,
            self::Other,
        ], fn (self $c): bool => $c->isActive()));
    }

    /** @return array<string, array<int, self>> Codes grouped for a select. */
    public static function grouped(): array
    {
        $grouped = [];

        foreach (array_filter(self::cases(), fn (self $c): bool => $c->isActive()) as $case) {
            $grouped[$case->group()][] = $case;
        }

        return $grouped;
    }
}
