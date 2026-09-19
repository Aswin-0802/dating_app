<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\AppUser;

/**
 * The member-facing wording for profile fields, in one place.
 *
 * The edit form's options and the profile card's labels are the same list, so
 * a value can never be saved that the card does not know how to show.
 */
final class ProfileOptions
{
    /**
     * What a display name may contain: letters in any script, spaces,
     * apostrophes, hyphens and dots, starting with a letter. Rules out markup,
     * digits used as decoration and names that are only punctuation.
     */
    public const NAME_RULE = 'regex:/^\pL[\pL\pM\s\'.-]*$/u';

    public const NAME_MESSAGE = 'Use letters only (spaces, hyphens and apostrophes are fine).';

    public const RELATIONSHIP_GOALS = [
        'long_term' => 'A long-term relationship',
        'short_term' => 'Something casual',
        'friends' => 'New friends',
        'figuring_out' => 'Still figuring it out',
        'unspecified' => 'Prefer not to say',
    ];

    public const DRINKING = [
        'never' => 'Doesn’t drink',
        'socially' => 'Drinks socially',
        'often' => 'Drinks often',
        'unspecified' => 'Prefer not to say',
    ];

    public const SMOKING = [
        'never' => 'Doesn’t smoke',
        'socially' => 'Smokes socially',
        'often' => 'Smokes',
        'unspecified' => 'Prefer not to say',
    ];

    public const CHILDREN = [
        'have' => 'Has children',
        'want' => 'Wants children',
        'dont_want' => 'Doesn’t want children',
        'unspecified' => 'Prefer not to say',
    ];

    public const EDUCATION = [
        'High school' => 'High school',
        'Trade school' => 'Trade school',
        'Undergrad' => 'Undergraduate degree',
        'Postgrad' => 'Postgraduate degree',
        'PhD' => 'PhD',
    ];

    public const PROMPTS = [
        'A perfect Sunday',
        'I geek out on',
        'My most irrational fear',
        'The way to win me over',
        'Two truths and a lie',
        'I’m looking for',
        'My simple pleasures',
        'Best travel story',
    ];

    /**
     * The built-in lists above seed Masters -> Profile questions; from then on
     * the operator's list is what members see. A group left empty in Masters
     * (fresh install, tests) falls back to the built-in list.
     *
     * @return array<string, string> key => label
     */
    public static function options(string $group, bool $activeOnly = true): array
    {
        return Masters::profileOptions($group, $activeOnly) ?: self::builtIn($group);
    }

    /** @return array<string, string> */
    public static function builtIn(string $group): array
    {
        return match ($group) {
            'relationship_goal' => self::RELATIONSHIP_GOALS,
            'drinking' => self::DRINKING,
            'smoking' => self::SMOKING,
            'children' => self::CHILDREN,
            'education' => self::EDUCATION,
            'prompt' => array_combine(self::PROMPTS, self::PROMPTS),
            default => [],
        };
    }

    /**
     * Options for a select, keeping the member's current answer even if the
     * operator has since hidden it — otherwise the form would silently blank it.
     *
     * @return array<string, string>
     */
    public static function forSelect(string $group, ?string $current): array
    {
        $options = self::options($group);

        if (filled($current) && ! isset($options[$current])) {
            $options[$current] = self::options($group, false)[$current] ?? $current;
        }

        return $options;
    }

    /**
     * The facts line on a profile card — only the ones actually filled in, and
     * never "prefer not to say", which is a non-answer rather than a fact.
     *
     * @return array<int, array{icon: string, label: string}>
     */
    public static function facts(AppUser $person): array
    {
        $profile = $person->profile;
        $facts = [];

        if (filled($profile?->job_title)) {
            $facts[] = ['icon' => 'briefcase', 'label' => $profile->job_title.($profile->company ? ' at '.$profile->company : '')];
        }

        if (filled($profile?->school) || filled($profile?->education)) {
            $facts[] = ['icon' => 'academic-cap', 'label' => $profile->school ?: (self::options('education', false)[$profile->education] ?? $profile->education)];
        }

        if ($profile?->height_cm) {
            $facts[] = ['icon' => 'arrows-pointing-out', 'label' => $profile->height_cm.' cm'];
        }

        foreach ([
            ['sparkles', self::options('relationship_goal', false), $profile?->relationship_goal],
            ['fire', self::options('drinking', false), $profile?->drinking],
            ['fire', self::options('smoking', false), $profile?->smoking],
            ['users', self::options('children', false), $profile?->children],
        ] as [$icon, $labels, $value]) {
            if ($value !== null && $value !== 'unspecified' && isset($labels[$value])) {
                $facts[] = ['icon' => $icon, 'label' => $labels[$value]];
            }
        }

        return $facts;
    }

    /**
     * Rough distance, rounded up to whole kilometres.
     *
     * Deliberately coarse: an exact figure, taken from two points over time, is
     * enough to triangulate where somebody lives.
     */
    public static function distanceKm(AppUser $from, AppUser $to): ?int
    {
        if ($from->last_latitude === null || $to->last_latitude === null) {
            return null;
        }

        $lat1 = deg2rad($from->last_latitude);
        $lat2 = deg2rad($to->last_latitude);
        $dLat = $lat2 - $lat1;
        $dLng = deg2rad($to->last_longitude - $from->last_longitude);

        $a = sin($dLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($dLng / 2) ** 2;
        $km = 6371 * 2 * atan2(sqrt($a), sqrt(1 - $a));

        return max(1, (int) ceil($km));
    }
}
