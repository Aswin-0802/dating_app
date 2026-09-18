<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Models\RiskFactorDefinition;
use App\Models\Setting;
use App\Services\Audit\ActivityLogger;
use App\Support\Branding;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
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

    public function save(): void
    {
        abort_unless($this->canEditCurrentGroup(), 403);

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
                'label' => str($group)->headline()->toString(),
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
