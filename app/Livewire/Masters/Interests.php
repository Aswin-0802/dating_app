<?php

declare(strict_types=1);

namespace App\Livewire\Masters;

use App\Models\Interest;
use App\Services\Audit\ActivityLogger;
use App\Support\Branding;
use App\Support\Reorder;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The interests members pick for their profile.
 *
 * Hiding an interest takes it out of the picker without touching profiles
 * that already list it; deleting is only possible once nobody has it.
 */
class Interests extends Component
{
    #[Url(except: '')]
    public string $category = '';

    #[Url(except: '')]
    public string $search = '';

    public bool $formOpen = false;

    #[Locked]
    public ?int $editingId = null;

    public string $name = '';

    public string $interestCategory = '';

    public bool $isActive = true;

    public function render(): View
    {
        $categories = Interest::query()->distinct()->orderBy('category')->pluck('category')->filter()->values();

        $interests = Interest::query()
            ->when($this->category !== '', fn ($q) => $q->where('category', $this->category))
            ->when($this->search !== '', fn ($q) => $q->where('name', 'like', '%'.$this->search.'%'))
            ->orderBy('category')->orderBy('sort_order')->orderBy('name')
            ->get();

        $members = DB::table('app_user_interest')->whereIn('interest_id', $interests->pluck('id'))
            ->groupBy('interest_id')->selectRaw('interest_id, COUNT(*) c')->pluck('c', 'interest_id');

        return view('livewire.masters.interests', [
            'grouped' => $interests->groupBy('category'),
            'categories' => $categories,
            'total' => Interest::query()->count(),
            'hidden' => Interest::query()->where('is_active', false)->count(),
            'memberCounts' => $members,
            'canEdit' => auth()->user()?->can('edit_general_settings') ?? false,
        ])->layout('components.layouts.admin', [
            'title' => 'Interests',
            'breadcrumbs' => [
                ['label' => Branding::name(), 'href' => route('admin.dashboard')],
                ['label' => 'Masters'],
                ['label' => 'Interests'],
            ],
        ]);
    }

    public function create(?string $category = null): void
    {
        $this->authorize('edit_general_settings');
        $this->resetValidation();
        $this->editingId = null;
        $this->name = '';
        $this->interestCategory = $category ?? ($this->category !== '' ? $this->category : '');
        $this->isActive = true;
        $this->formOpen = true;
    }

    public function edit(int $id): void
    {
        $this->authorize('edit_general_settings');
        $interest = Interest::query()->findOrFail($id);

        $this->resetValidation();
        $this->editingId = $interest->id;
        $this->name = $interest->name;
        $this->interestCategory = (string) $interest->category;
        $this->isActive = (bool) $interest->is_active;
        $this->formOpen = true;
    }

    public function save(ActivityLogger $logger): void
    {
        $this->authorize('edit_general_settings');
        $this->name = trim($this->name);
        $this->interestCategory = trim($this->interestCategory);
        $slug = str($this->name)->slug()->toString();

        $this->validate([
            'name' => ['required', 'string', 'min:2', 'max:40', Rule::unique('interests', 'name')->ignore($this->editingId)],
            'interestCategory' => ['required', 'string', 'min:2', 'max:40'],
            'isActive' => ['boolean'],
        ], [
            'name.unique' => 'That interest is already in the list.',
        ], ['interestCategory' => 'category']);

        if ($slug === '' || Interest::query()->where('slug', $slug)->whereKeyNot($this->editingId ?? 0)->exists()) {
            $this->addError('name', 'That interest is already in the list.');

            return;
        }

        $values = ['name' => $this->name, 'category' => $this->interestCategory, 'is_active' => $this->isActive];

        if ($this->editingId === null) {
            $interest = Interest::query()->create($values + [
                'slug' => $slug,
                'sort_order' => (int) Interest::query()->where('category', $this->interestCategory)->max('sort_order') + 1,
            ]);
            $logger->log(module: 'masters', action: 'interest_created', subject: $interest, description: "Added interest {$interest->name}");
        } else {
            // The slug is what the API accepts, so it follows the name.
            $interest = Interest::query()->findOrFail($this->editingId);
            $old = $interest->only(['name', 'category', 'is_active']);
            $interest->update($values + ['slug' => $slug]);
            $logger->log(module: 'masters', action: 'interest_updated', subject: $interest, description: "Updated interest {$interest->name}", old: $old, new: $values);
        }

        $this->formOpen = false;
        session()->flash('status', "{$interest->name} saved.");
    }

    public function toggleActive(int $id, ActivityLogger $logger): void
    {
        $this->authorize('edit_general_settings');
        $interest = Interest::query()->findOrFail($id);
        $interest->update(['is_active' => ! $interest->is_active]);

        $logger->log(module: 'masters', action: $interest->is_active ? 'interest_shown' : 'interest_hidden', subject: $interest, description: ($interest->is_active ? 'Showed' : 'Hid')." interest {$interest->name}");
        session()->flash('status', $interest->is_active ? "{$interest->name} is back in the picker." : "{$interest->name} is hidden from the picker.");
    }

    public function move(int $id, int $direction): void
    {
        $this->authorize('edit_general_settings');
        $interest = Interest::query()->findOrFail($id);

        Reorder::move(
            Interest::query()->where('category', $interest->category)->orderBy('sort_order')->orderBy('name')->get(),
            $id,
            $direction,
        );
    }

    public function delete(int $id, ActivityLogger $logger): void
    {
        $this->authorize('edit_general_settings');
        $interest = Interest::query()->findOrFail($id);
        $used = DB::table('app_user_interest')->where('interest_id', $interest->id)->count();

        if ($used > 0) {
            session()->flash('error', "{$used} ".str('member')->plural($used)." list {$interest->name}, so it cannot be deleted. Hide it instead.");

            return;
        }

        $interest->delete();
        $logger->log(module: 'masters', action: 'interest_deleted', description: "Deleted interest {$interest->name}");
        session()->flash('status', "{$interest->name} deleted.");
    }

    public function closeForm(): void
    {
        $this->formOpen = false;
    }
}
