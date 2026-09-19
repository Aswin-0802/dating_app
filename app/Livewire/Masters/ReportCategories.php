<?php

declare(strict_types=1);

namespace App\Livewire\Masters;

use App\Enums\ReportCategory;
use App\Enums\Severity;
use App\Models\ReportCategorySetting;
use App\Services\Audit\ActivityLogger;
use App\Support\Branding;
use App\Support\Reorder;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * What members can report, in the operator's words.
 *
 * The categories themselves are fixed — routing, the restricted queue and the
 * risk engine all depend on them — but the wording, order, default severity
 * and whether members see a category are editable. Safety categories are
 * locked on and at Critical: a member must always be able to report a child
 * at risk, and that report must never wait behind spam.
 */
class ReportCategories extends Component
{
    public bool $formOpen = false;

    #[Locked]
    public ?string $editingKey = null;

    public string $label = '';

    public string $description = '';

    public string $severity = 'medium';

    public bool $isActive = true;

    public function render(): View
    {
        $settings = ReportCategorySetting::query()->orderBy('sort_order')->get()->keyBy('key');

        $categories = collect(ReportCategory::cases())
            ->sortBy(fn (ReportCategory $c): int => $settings[$c->value]->sort_order ?? 999)
            ->values();

        $counts = DB::table('reports')->groupBy('category')->selectRaw('category, COUNT(*) c')->pluck('c', 'category');

        return view('livewire.masters.report-categories', [
            'categories' => $categories,
            'counts' => $counts,
            'canEdit' => auth()->user()?->can('edit_moderation_settings') ?? false,
        ])->layout('components.layouts.admin', [
            'title' => 'Report categories',
            'breadcrumbs' => [
                ['label' => Branding::name(), 'href' => route('admin.dashboard')],
                ['label' => 'Masters'],
                ['label' => 'Report categories'],
            ],
        ]);
    }

    public function edit(string $key): void
    {
        $this->authorize('edit_moderation_settings');
        $category = ReportCategory::from($key);

        $this->resetValidation();
        $this->editingKey = $category->value;
        $this->label = $category->label();
        $this->description = (string) $category->description();
        $this->severity = $category->defaultSeverity()->value;
        $this->isActive = $category->isActive();
        $this->formOpen = true;
    }

    public function save(ActivityLogger $logger): void
    {
        $this->authorize('edit_moderation_settings');
        $category = ReportCategory::from((string) $this->editingKey);

        $this->validate([
            'label' => ['required', 'string', 'min:2', 'max:80'],
            'description' => ['nullable', 'string', 'max:200'],
            'severity' => ['required', Rule::enum(Severity::class)],
            'isActive' => ['boolean'],
        ], [], ['label' => 'name']);

        $setting = $this->setting($category);
        $old = $setting->only(['label', 'description', 'severity', 'is_active']);

        $setting->update([
            'label' => trim($this->label),
            'description' => filled($this->description) ? trim($this->description) : null,
            // Locked categories keep their built-in severity and stay on.
            'severity' => $category->isRestricted() ? $category->builtInSeverity()->value : $this->severity,
            'is_active' => $category->isLocked() ? true : $this->isActive,
        ]);

        $logger->log(module: 'masters', action: 'report_category_updated', description: "Updated report category {$setting->label}", old: $old, new: ['key' => $setting->key] + $setting->only(['label', 'description', 'severity', 'is_active']));

        $this->formOpen = false;
        session()->flash('status', "{$setting->label} saved.");
    }

    public function toggleActive(string $key, ActivityLogger $logger): void
    {
        $this->authorize('edit_moderation_settings');
        $category = ReportCategory::from($key);

        if ($category->isLocked()) {
            session()->flash('error', "{$category->label()} is always available to members.");

            return;
        }

        $setting = $this->setting($category);
        $setting->update(['is_active' => ! $setting->is_active]);

        $logger->log(module: 'masters', action: $setting->is_active ? 'report_category_shown' : 'report_category_hidden', description: ($setting->is_active ? 'Showed' : 'Hid')." report category {$setting->label}", new: ['key' => $setting->key]);
        session()->flash('status', $setting->is_active ? "Members can report “{$setting->label}” again." : "“{$setting->label}” is hidden from members. Existing reports are unaffected.");
    }

    public function move(string $key, int $direction): void
    {
        $this->authorize('edit_moderation_settings');

        foreach (ReportCategory::cases() as $category) {
            $this->setting($category);
        }

        Reorder::move(ReportCategorySetting::query()->orderBy('sort_order')->get(), $key, $direction);
    }

    public function closeForm(): void
    {
        $this->formOpen = false;
    }

    /** The settings row, created from the built-in values if it is missing. */
    private function setting(ReportCategory $category): ReportCategorySetting
    {
        return ReportCategorySetting::query()->firstOrCreate(['key' => $category->value], [
            'label' => $category->builtInLabel(),
            'severity' => $category->builtInSeverity()->value,
            'is_active' => true,
            'sort_order' => (int) array_search($category, ReportCategory::cases(), true),
        ]);
    }
}
