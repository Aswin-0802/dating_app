<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Masters;
use Illuminate\Database\Eloquent\Model;

/**
 * One choice in a profile list: an education level, a relationship goal, a
 * prompt question. Managed in Masters -> Profile questions.
 */
class ProfileOption extends Model
{
    /** Group => [label, whether new keys can be added]. */
    public const GROUPS = [
        'prompt' => ['Prompts', true],
        'education' => ['Education', true],
        'relationship_goal' => ['Looking for', false],
        'drinking' => ['Drinking', false],
        'smoking' => ['Smoking', false],
        'children' => ['Children', false],
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saved(fn () => Masters::flush());
        static::deleted(fn () => Masters::flush());
    }

    /**
     * Relationship goal, drinking, smoking and children are stored in columns
     * with a fixed set of values, so their options can be renamed or hidden
     * but new ones cannot be invented.
     */
    public static function groupAllowsNew(string $group): bool
    {
        return self::GROUPS[$group][1] ?? false;
    }
}
