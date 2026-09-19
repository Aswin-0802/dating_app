<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Models\RiskFactorDefinition;
use App\Models\Setting;
use App\Services\Audit\ActivityLogger;
use App\Support\Branding;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class Index extends Component
{
    public string $group = 'general';

    /** @var array<string, mixed> */
    public array $values = [];

    /** @var array<int, int> */
    public array $riskPoints = [];

    /**
     * Which permission each settings group needs.
     *
     * Deliberately not one blanket "edit settings" permission: moderation and
     * risk thresholds are safety policy and belong to T&S, while brand and API
     * limits are platform operations. Collapsing them would give whoever can
     * change the logo the power to change what counts as a ban.
     */
    private const GROUP_PERMISSIONS = [
        'general' => 'edit_general_settings',
        'moderation' => 'edit_moderation_settings',
        'risk' => 'edit_risk_settings',
        'verification' => 'edit_moderation_settings',
        'enforcement' => 'edit_moderation_settings',
        'matching' => 'edit_matching_settings',
        'api' => 'edit_api_settings',
        'privacy' => 'edit_moderation_settings',
    ];

    public function mount(string $group = 'general'): void
    {
        $this->group = array_key_exists($group, self::GROUP_PERMISSIONS) ? $group : 'general';
        $this->loadValues();
    }

    public function render(): View
    {
        return view('livewire.settings.index', [
            'settings' => $this->settingsForGroup(),
            'groups' => $this->groups(),
            'canEdit' => $this->canEditCurrentGroup(),
            'riskFactors' => $this->group === 'risk'
                ? RiskFactorDefinition::query()->orderByDesc('points')->get()
                : collect(),
        ])->layout('components.layouts.admin', [
            'title' => 'Settings',
            'breadcrumbs' => [
                ['label' => Branding::name(), 'href' => route('admin.dashboard')],
                ['label' => 'Settings'],
                ['label' => str($this->group)->headline()->toString()],
            ],
        ]);
    }

    public function switchGroup(string $group): void
    {
        if (! array_key_exists($group, self::GROUP_PERMISSIONS)) {
            return;
        }

        $this->group = $group;
        $this->resetErrorBag();
        $this->loadValues();
    }

    /**
     * Limits for every numeric setting.
     *
     * Without these a negative like limit or a zero-hour SLA saves happily and
     * breaks the product quietly. Anything not listed still has to be a whole,
     * non-negative number.
     *
     * @var array<string, array{0: int|float, 1: int|float}>
     */
    private const RANGES = [
        'general.min_age' => [18, 99],
        'api.rate_limit_default' => [10, 10000],
        'api.rate_limit_swipe' => [10, 10000],
        'api.rate_limit_message' => [5, 10000],
        'enforcement.shadow_ban_review_hours' => [1, 2160],
        'enforcement.shadow_ban_max_hours' => [24, 8760],
        'enforcement.appeal_window_days' => [1, 365],
        'matching.daily_like_limit_free' => [1, 10000],
        'matching.max_distance_km' => [1, 500],
        'matching.max_photos' => [1, 12],
        'matching.unmatch_after_days' => [0, 365],
        'sla.case_critical_hours' => [1, 24],
        'sla.case_high_hours' => [1, 168],
        'sla.case_medium_hours' => [1, 336],
        'sla.case_low_hours' => [1, 720],
        'sla.appeal_hours' => [1, 720],
        'moderation.claim_release_minutes' => [1, 1440],
        'privacy.message_reveal_minutes' => [1, 240],
        'privacy.message_context_window' => [1, 100],
        'risk.auto_queue_at' => [1, 100],
        'risk.auto_limit_at' => [1, 100],
        'risk.recalculate_after_hours' => [1, 720],
        'verification.sla_hours' => [1, 168],
        'verification.restricted_sla_hours' => [1, 48],
        'verification.max_attempts' => [1, 10],
        'verification.approve_threshold' => [0.5, 1],
    ];

    public function save(): void
    {
        abort_unless($this->canEditCurrentGroup(), 403);

        $this->validateValues();

        $changed = [];

        foreach ($this->settingsForGroup() as $setting) {
            $new = $this->values[$setting->id] ?? null;

            if ($setting->type === 'boolean') {
                $new = $new ? '1' : '0';
            }

            if ((string) $new === (string) $setting->value) {
                continue;
            }

            $changed[$setting->key] = ['old' => $setting->value, 'new' => $new];

            $setting->update(['value' => $new, 'updated_by' => auth()->id()]);
        }

        // Risk weights are policy. Changing them silently would make past scores
        // inexplicable, so the change is logged with the before and after.
        foreach ($this->riskPoints as $id => $points) {
            $definition = RiskFactorDefinition::query()->find($id);

            if ($definition === null || (int) $definition->points === (int) $points) {
                continue;
            }

            $changed["risk.{$definition->key}"] = ['old' => $definition->points, 'new' => (int) $points];
            $definition->update(['points' => (int) $points]);
        }

        if ($changed !== []) {
            app(ActivityLogger::class)->log(
                module: 'settings',
                action: 'updated',
                description: sprintf('%d %s changed in %s', count($changed), str('setting')->plural(count($changed)), $this->group),
                old: array_map(fn (array $c) => $c['old'], $changed),
                new: array_map(fn (array $c) => $c['new'], $changed),
            );
        }

        $this->loadValues();

        session()->flash('status', $changed === []
            ? 'No changes to save.'
            : sprintf('%d %s updated.', count($changed), str('setting')->plural(count($changed))));
    }

    private function validateValues(): void
    {
        $rules = [];
        $attributes = [];

        foreach ($this->settingsForGroup() as $setting) {
            $field = "values.{$setting->id}";
            $attributes[$field] = strtolower((string) ($setting->label ?? $setting->key));

            $rules[$field] = match ($setting->type) {
                'number' => isset(self::RANGES[$setting->key])
                    ? ['required', 'numeric', 'between:'.self::RANGES[$setting->key][0].','.self::RANGES[$setting->key][1]]
                    : ['required', 'integer', 'min:0', 'max:1000000'],
                'boolean' => ['boolean'],
                'json' => ['nullable', 'array'],
                default => ['nullable', 'string', 'max:2000'],
            };

            if ($setting->key === 'api.min_supported_version') {
                $rules[$field] = ['required', 'regex:/^\d+\.\d+\.\d+$/'];
            }
        }

        foreach ($this->riskPoints as $id => $points) {
            $rules["riskPoints.{$id}"] = ['required', 'integer', 'between:-50,100'];
            $attributes["riskPoints.{$id}"] = 'points';
        }

        $this->validate($rules, [
            'values.*.between' => 'Must be between :min and :max.',
            'values.*.regex' => 'Use a version number such as 2.4.0.',
        ], $attributes);

        // The two risk thresholds only make sense in order.
        $queue = $this->valueFor('risk.auto_queue_at');
        $limit = $this->valueFor('risk.auto_limit_at');

        if ($queue !== null && $limit !== null && (float) $limit < (float) $queue) {
            $id = $this->settingsForGroup()->firstWhere('key', 'risk.auto_limit_at')?->id;

            throw ValidationException::withMessages([
                "values.{$id}" => 'The auto-limit score must be at or above the auto-queue score.',
            ]);
        }
    }

    private function valueFor(string $key): mixed
    {
        $setting = $this->settingsForGroup()->firstWhere('key', $key);

        return $setting ? ($this->values[$setting->id] ?? null) : null;
    }

    private function loadValues(): void
    {
        /*
         * Keyed by id, never by the setting key. Every key is dotted
         * ("brand.name"), and Livewire reads a dot in wire:model as a path into
         * a nested array — so `values.brand.name` wrote to $values['brand']['name']
         * while save() read $values['brand.name'], saw nothing change, and
         * reported "No changes to save" for every setting in every group.
         */
        $this->values = $this->settingsForGroup()
            ->mapWithKeys(fn (Setting $s): array => [$s->id => $s->typed_value])
            ->all();

        $this->riskPoints = $this->group === 'risk'
            ? RiskFactorDefinition::query()->pluck('points', 'id')->all()
            : [];
    }

    /** @return Collection<int, Setting> */
    private function settingsForGroup(): Collection
    {
        return Setting::query()
            ->where('group', $this->group)
            ->orderBy('sort_order')
            ->get();
    }

    /** @return array<string, array{label: string, count: int}> */
    private function groups(): array
    {
        $counts = Setting::query()
            ->selectRaw('`group`, COUNT(*) as c')
            ->groupBy('group')
            ->pluck('c', 'group')
            ->all();

        $out = [];

        foreach (self::GROUP_PERMISSIONS as $group => $permission) {
            $out[$group] = [
                'label' => $group === 'api' ? 'API' : str($group)->headline()->toString(),
                'count' => (int) ($counts[$group] ?? 0),
                'editable' => auth()->user()?->can($permission) ?? false,
            ];
        }

        return $out;
    }

    private function canEditCurrentGroup(): bool
    {
        return auth()->user()?->can(self::GROUP_PERMISSIONS[$this->group] ?? 'settings') ?? false;
    }
}
