<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Plan;
use App\Models\ProfileOption;
use App\Models\ReasonCodeSetting;
use App\Models\ReportCategorySetting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Read access to the master lists, cached.
 *
 * Enum labels, pricing cards and profile dropdowns call this many times per
 * page, so everything is loaded in one go, kept for the request, cached
 * between requests, and flushed whenever an operator saves a change.
 *
 * Every read tolerates missing tables (fresh install, tests without the
 * migration) by returning nothing, and callers fall back to their built-in
 * defaults — so the product still renders before any master is edited.
 */
final class Masters
{
    private const CACHE_KEY = 'veyra.masters';

    /** @var array<string, mixed>|null */
    private static ?array $memo = null;

    public static function flush(): void
    {
        self::$memo = null;
        Cache::forget(self::CACHE_KEY);
    }

    /** Called on every boot: the memo must never outlive one request. */
    public static function forgetMemo(): void
    {
        self::$memo = null;
    }

    /** @return Collection<int, Plan> active plans, in display order */
    public static function plans(): Collection
    {
        return collect(self::all()['plans'])->filter(fn (Plan $p): bool => $p->is_active)->values();
    }

    /** Any plan by slug, active or not — a member keeps a plan that was later hidden. */
    public static function plan(?string $slug): ?Plan
    {
        return $slug === null ? null : collect(self::all()['plans'])->firstWhere('slug', $slug);
    }

    /** @return array{label: string, description: ?string, severity: string, is_active: bool, sort_order: int}|null */
    public static function reportCategory(string $key): ?array
    {
        return self::all()['report_categories'][$key] ?? null;
    }

    /** @return array{label: string, statement: string, policy_clause: string, is_active: bool}|null */
    public static function reason(string $key): ?array
    {
        return self::all()['reasons'][$key] ?? null;
    }

    /**
     * Options for one profile group, as key => label.
     *
     * @return array<string, string>
     */
    public static function profileOptions(string $group, bool $activeOnly = true): array
    {
        return collect(self::all()['profile_options'][$group] ?? [])
            ->filter(fn (array $o): bool => ! $activeOnly || $o['is_active'])
            ->mapWithKeys(fn (array $o): array => [$o['key'] => $o['label']])
            ->all();
    }

    /** @return array<string, mixed> */
    private static function all(): array
    {
        if (self::$memo !== null) {
            return self::$memo;
        }

        try {
            return self::$memo = Cache::rememberForever(self::CACHE_KEY, fn (): array => [
                'plans' => Plan::query()->orderBy('sort_order')->orderBy('monthly_price')->get()->all(),
                'report_categories' => ReportCategorySetting::query()->get()
                    ->mapWithKeys(fn ($r): array => [$r->key => [
                        'label' => $r->label, 'description' => $r->description, 'severity' => $r->severity,
                        'is_active' => (bool) $r->is_active, 'sort_order' => (int) $r->sort_order,
                    ]])->all(),
                'reasons' => ReasonCodeSetting::query()->get()
                    ->mapWithKeys(fn ($r): array => [$r->key => [
                        'label' => $r->label, 'statement' => $r->statement,
                        'policy_clause' => $r->policy_clause, 'is_active' => (bool) $r->is_active,
                    ]])->all(),
                'profile_options' => ProfileOption::query()->orderBy('sort_order')->orderBy('id')->get()
                    ->groupBy('group')
                    ->map(fn ($g) => $g->map(fn ($o): array => ['key' => $o->key, 'label' => $o->label, 'is_active' => (bool) $o->is_active])->all())
                    ->all(),
            ]);
        } catch (Throwable) {
            return ['plans' => [], 'report_categories' => [], 'reasons' => [], 'profile_options' => []];
        }
    }
}
