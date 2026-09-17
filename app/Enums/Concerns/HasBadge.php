<?php

declare(strict_types=1);

namespace App\Enums\Concerns;

/**
 * Shared presentation contract for every status-like enum.
 *
 * `badgeClasses()` implementations must return COMPLETE Tailwind class strings.
 * Tailwind v4 scans source files for literal classes, so anything assembled at
 * runtime — "bg-risk-{$band}-subtle" — is purged and the badge silently renders
 * unstyled. Every match arm therefore spells the classes out in full.
 */
trait HasBadge
{
    /**
     * Human-readable label. Defaults to title-casing the case name, which is
     * correct often enough that most enums never override it.
     */
    public function label(): string
    {
        return str($this->name)->headline()->toString();
    }

    /**
     * Soft/tinted treatment is the default for states; solid is reserved for
     * the few values that genuinely demand attention, so the eye is drawn only
     * to what needs action.
     */
    public function isUrgent(): bool
    {
        return false;
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $case): array => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        $labels = [];

        foreach (self::cases() as $case) {
            $labels[$case->value] = $case->label();
        }

        return $labels;
    }
}
