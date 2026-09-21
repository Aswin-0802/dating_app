<?php

declare(strict_types=1);

namespace App\Livewire\Billing;

use App\Models\AppUser;
use App\Models\Plan;
use App\Services\Audit\ActivityLogger;
use App\Support\Branding;
use App\Support\Reorder;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Subscription plans: what members can buy and what each plan unlocks.
 *
 * The slug is what a member record points at, so it is fixed once created;
 * everything a member reads — name, prices, wording, colour — stays editable.
 */
class Plans extends Component
{
    public bool $formOpen = false;

    #[Locked]
    public ?int $editingId = null;

    public string $name = '';

    public string $slug = '';

    public string $tagline = '';

    public string $monthlyPrice = '';

    public string $yearlyPrice = '';

    /** @var array<int, string> */
    public array $features = [];

    public string $perks = '';

    public string $badgeColor = '#e11d48';

    public bool $isFeatured = false;

    public bool $isActive = true;

    public function render(): View
    {
        $plans = Plan::query()->orderBy('sort_order')->orderBy('monthly_price')->get();

        $members = AppUser::query()->where('is_premium', true)
            ->groupBy('premium_tier')->selectRaw('premium_tier, COUNT(*) c')->pluck('c', 'premium_tier');

        return view('livewire.billing.plans', [
            'plans' => $plans,
            'memberCounts' => $members,
            'canEdit' => $this->canEdit(),
        ])->layout('components.layouts.admin', [
            'title' => 'Subscription plans',
            'breadcrumbs' => [
                ['label' => Branding::name(), 'href' => route('admin.dashboard')],
                ['label' => 'Billing'],
                ['label' => 'Subscription plans'],
            ],
        ]);
    }

    public function create(): void
    {
        $this->authorizeEdit();
        $this->resetValidation();
        $this->reset('editingId', 'name', 'slug', 'tagline', 'monthlyPrice', 'yearlyPrice', 'features', 'perks', 'isFeatured');
        $this->badgeColor = '#e11d48';
        $this->isActive = true;
        $this->formOpen = true;
    }

    public function edit(int $id): void
    {
        $this->authorizeEdit();
        $plan = Plan::query()->findOrFail($id);

        $this->resetValidation();
        $this->editingId = $plan->id;
        $this->name = $plan->name;
        $this->slug = $plan->slug;
        $this->tagline = (string) $plan->tagline;
        $this->monthlyPrice = (string) $plan->monthly_price;
        $this->yearlyPrice = $plan->yearly_price === null ? '' : (string) $plan->yearly_price;
        $this->features = $plan->features ?? [];
        $this->perks = implode("\n", $plan->perks ?? []);
        $this->badgeColor = $plan->badge_color;
        $this->isFeatured = $plan->is_featured;
        $this->isActive = $plan->is_active;
        $this->formOpen = true;
    }

    public function updatedName(string $value): void
    {
        if ($this->editingId === null) {
            $this->slug = str($value)->slug('_')->limit(40, '')->toString();
        }
    }

    public function save(ActivityLogger $logger): void
    {
        $this->authorizeEdit();
        $this->slug = strtolower(trim($this->slug));

        $this->validate([
            'name' => ['required', 'string', 'min:2', 'max:40'],
            'slug' => [
                Rule::requiredIf($this->editingId === null), 'regex:/^[a-z][a-z0-9_]*$/', 'max:40',
                Rule::notIn(['free']),
                Rule::unique('plans', 'slug')->ignore($this->editingId),
            ],
            'tagline' => ['nullable', 'string', 'max:120'],
            'monthlyPrice' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'yearlyPrice' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'features' => ['array'],
            'features.*' => [Rule::in(array_keys(Plan::FEATURES))],
            'perks' => ['nullable', 'string', 'max:1000'],
            'badgeColor' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'isFeatured' => ['boolean'],
            'isActive' => ['boolean'],
        ], [
            'slug.regex' => 'Use lowercase letters, numbers and underscores, starting with a letter.',
            'slug.not_in' => '“free” is reserved for members without a plan.',
            'slug.unique' => 'Another plan already uses this code.',
            'badgeColor.regex' => 'Use a hex colour such as #e11d48.',
        ], [
            'slug' => 'code', 'monthlyPrice' => 'monthly price', 'yearlyPrice' => 'yearly price', 'badgeColor' => 'colour',
        ]);

        $perks = collect(preg_split('/\R/', $this->perks))
            ->map(fn (string $line): string => trim($line))->filter()->take(8)->values()->all();

        $values = [
            'name' => trim($this->name),
            'tagline' => filled($this->tagline) ? trim($this->tagline) : null,
            'monthly_price' => round((float) $this->monthlyPrice, 2),
            'yearly_price' => $this->yearlyPrice === '' ? null : round((float) $this->yearlyPrice, 2),
            'features' => array_values(array_intersect(array_keys(Plan::FEATURES), $this->features)),
            'perks' => $perks,
            'badge_color' => strtolower($this->badgeColor),
            'is_featured' => $this->isFeatured,
            'is_active' => $this->isActive,
        ];

        DB::transaction(function () use ($values, $logger): void {
            // One "Most popular" plan at a time; two badges cancel each other out.
            if ($values['is_featured']) {
                Plan::query()->whereKeyNot($this->editingId ?? 0)->update(['is_featured' => false]);
            }

            if ($this->editingId === null) {
                $plan = Plan::query()->create($values + [
                    'slug' => $this->slug,
                    'sort_order' => (int) Plan::query()->max('sort_order') + 1,
                ]);
                $logger->log(module: 'masters', action: 'plan_created', description: "Created plan {$plan->name}", subject: $plan, new: $values);
            } else {
                $plan = Plan::query()->findOrFail($this->editingId);
                $old = $plan->only(array_keys($values));
                $plan->update($values);
                $logger->log(module: 'masters', action: 'plan_updated', description: "Updated plan {$plan->name}", subject: $plan, old: $old, new: $values);
            }
        });

        $this->formOpen = false;
        session()->flash('status', trim($this->name).' saved.');
    }

    public function toggleActive(int $id, ActivityLogger $logger): void
    {
        $this->authorizeEdit();
        $plan = Plan::query()->findOrFail($id);
        $plan->update(['is_active' => ! $plan->is_active]);

        $logger->log(module: 'masters', action: $plan->is_active ? 'plan_shown' : 'plan_hidden', description: ($plan->is_active ? 'Showed' : 'Hid')." plan {$plan->name}", subject: $plan);
        session()->flash('status', $plan->is_active ? "{$plan->name} is on sale again." : "{$plan->name} is hidden. Members already on it keep it.");
    }

    public function move(int $id, int $direction): void
    {
        $this->authorizeEdit();
        Reorder::move(Plan::query()->orderBy('sort_order')->orderBy('monthly_price')->get(), $id, $direction);
    }

    public function delete(int $id, ActivityLogger $logger): void
    {
        $this->authorizeEdit();
        $plan = Plan::query()->findOrFail($id);

        if (AppUser::query()->withTrashed()->where('premium_tier', $plan->slug)->exists()) {
            session()->flash('error', "Members are on {$plan->name}, so it cannot be deleted. Hide it instead — they keep their plan and nobody new can choose it.");

            return;
        }

        $plan->delete();
        $logger->log(module: 'masters', action: 'plan_deleted', description: "Deleted plan {$plan->name}");
        session()->flash('status', "{$plan->name} deleted.");
    }

    public function closeForm(): void
    {
        $this->formOpen = false;
    }

    private function canEdit(): bool
    {
        return auth()->user()?->can('edit_general_settings') ?? false;
    }

    private function authorizeEdit(): void
    {
        $this->authorize('edit_general_settings');
    }
}
