<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ReasonCode;
use App\Enums\ReportCategory;
use App\Models\Plan;
use App\Models\ProfileOption;
use App\Models\ReasonCodeSetting;
use App\Models\ReportCategorySetting;
use App\Support\Masters;
use App\Support\ProfileOptions;
use Illuminate\Database\Seeder;

/**
 * The starting content of every master list.
 *
 * Only fills gaps: an existing row is never touched, so re-running this after
 * a deploy adds new built-in entries without undoing an operator's edits.
 */
class MasterSeeder extends Seeder
{
    public function run(): void
    {
        $this->plans();
        $this->reportCategories();
        $this->reasons();
        $this->profileOptions();

        Masters::flush();
    }

    private function plans(): void
    {
        $plans = [
            [
                'slug' => 'plus',
                'name' => 'Plus',
                'tagline' => 'For people who know what they want.',
                'monthly_price' => 12.99,
                'yearly_price' => 99.99,
                'features' => ['unlimited_likes', 'see_likers'],
                'perks' => ['Everything in Free'],
                'badge_color' => '#e11d48',
                'is_featured' => true,
                'sort_order' => 1,
            ],
            [
                'slug' => 'gold',
                'name' => 'Gold',
                'tagline' => 'The full experience.',
                'monthly_price' => 24.99,
                'yearly_price' => 199.99,
                'features' => ['unlimited_likes', 'see_likers', 'profile_badge', 'priority_support'],
                'perks' => ['Everything in Plus'],
                'badge_color' => '#d4a017',
                'is_featured' => false,
                'sort_order' => 2,
            ],
        ];

        foreach ($plans as $plan) {
            Plan::query()->firstOrCreate(['slug' => $plan['slug']], $plan + ['is_active' => true]);
        }
    }

    private function reportCategories(): void
    {
        foreach (ReportCategory::cases() as $i => $category) {
            ReportCategorySetting::query()->firstOrCreate(['key' => $category->value], [
                'label' => $category->builtInLabel(),
                'description' => null,
                'severity' => $category->builtInSeverity()->value,
                'is_active' => true,
                'sort_order' => $i,
            ]);
        }
    }

    private function reasons(): void
    {
        foreach (ReasonCode::cases() as $reason) {
            ReasonCodeSetting::query()->firstOrCreate(['key' => $reason->value], [
                'label' => $reason->builtInLabel(),
                'statement' => $reason->builtInStatement(),
                'policy_clause' => $reason->builtInPolicyClause(),
                'is_active' => true,
            ]);
        }
    }

    private function profileOptions(): void
    {
        foreach (array_keys(ProfileOption::GROUPS) as $group) {
            $position = 0;

            foreach (ProfileOptions::builtIn($group) as $key => $label) {
                ProfileOption::query()->firstOrCreate(
                    ['group' => $group, 'key' => (string) $key],
                    ['label' => $label, 'is_active' => true, 'sort_order' => $position++],
                );
            }
        }
    }
}
