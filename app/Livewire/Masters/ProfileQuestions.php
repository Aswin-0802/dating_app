<?php

declare(strict_types=1);

namespace App\Livewire\Masters;

use App\Models\Profile;
use App\Models\ProfileOption;
use App\Services\Audit\ActivityLogger;
use App\Support\Branding;
use App\Support\Reorder;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The choices on a member's profile form: prompts, education levels and the
 * lifestyle answers.
 *
 * Every option can be reworded, reordered and hidden. Prompts and education
 * can also be added and deleted; the lifestyle answers are stored in fixed
 * columns, so their set of values is fixed too.
 */
class ProfileQuestions extends Component
{
    #[Url(except: 'prompt')]
    public string $group = 'prompt';

    public bool $formOpen = false;

    #[Locked]
    public ?int $editingId = null;

    public string $label = '';

    public bool $isActive = true;

    public function mount(): void
    {
        if (! array_key_exists($this->group, ProfileOption::GROUPS)) {
            $this->group = 'prompt';
        }
    }

    public function render(): View
    {
        $options = ProfileOption::query()->where('group', $this->group)->orderBy('sort_order')->orderBy('id')->get();

        return view('livewire.masters.profile-questions', [
            'groups' => ProfileOption::GROUPS,
            'options' => $options,
            'usage' => $options->mapWithKeys(fn (ProfileOption $o): array => [$o->id => $this->usage($o)]),
            'allowsNew' => ProfileOption::groupAllowsNew($this->group),
            'canEdit' => auth()->user()?->can('edit_general_settings') ?? false,
        ])->layout('components.layouts.admin', [
            'title' => 'Profile questions',
            'breadcrumbs' => [
                ['label' => Branding::name(), 'href' => route('admin.dashboard')],
                ['label' => 'Masters'],
                ['label' => 'Profile questions'],
            ],
        ]);
    }

    public function selectGroup(string $group): void
    {
        abort_unless(array_key_exists($group, ProfileOption::GROUPS), 404);
        $this->group = $group;
        $this->formOpen = false;
    }

    public function create(): void
    {
        $this->authorize('edit_general_settings');
        abort_unless(ProfileOption::groupAllowsNew($this->group), 403);

        $this->resetValidation();
        $this->editingId = null;
        $this->label = '';
        $this->isActive = true;
        $this->formOpen = true;
    }

    public function edit(int $id): void
    {
        $this->authorize('edit_general_settings');
        $option = $this->option($id);

        $this->resetValidation();
        $this->editingId = $option->id;
        $this->label = $option->label;
        $this->isActive = $option->is_active;
        $this->formOpen = true;
    }

    public function save(ActivityLogger $logger): void
    {
        $this->authorize('edit_general_settings');
        $this->label = trim($this->label);
        $max = $this->group === 'prompt' ? 80 : 60;

        $this->validate([
            'label' => [
                'required', 'string', 'min:2', "max:{$max}",
                Rule::unique('profile_options', 'label')->where('group', $this->group)->ignore($this->editingId),
            ],
            'isActive' => ['boolean'],
        ], ['label.unique' => 'That option is already in the list.'], ['label' => $this->group === 'prompt' ? 'prompt' : 'wording']);

        if ($this->editingId === null) {
            abort_unless(ProfileOption::groupAllowsNew($this->group), 403);

            // New options store their own wording as the value, the same way
            // the built-in prompts and education levels do.
            if (ProfileOption::query()->where('group', $this->group)->where('key', $this->label)->exists()) {
                $this->addError('label', 'That option is already in the list.');

                return;
            }

            $option = ProfileOption::query()->create([
                'group' => $this->group,
                'key' => $this->label,
                'label' => $this->label,
                'is_active' => $this->isActive,
                'sort_order' => (int) ProfileOption::query()->where('group', $this->group)->max('sort_order') + 1,
            ]);
            $logger->log(module: 'masters', action: 'profile_option_created', subject: $option, description: "Added {$this->groupLabel()} option “{$option->label}”");
        } else {
            $option = $this->option($this->editingId);

            if (! $this->isActive && $option->is_active && $this->isLastActive($option)) {
                $this->addError('isActive', 'Keep at least one option visible, or members could not answer this question.');

                return;
            }

            $old = $option->only(['label', 'is_active']);
            $option->update(['label' => $this->label, 'is_active' => $this->isActive]);
            $logger->log(module: 'masters', action: 'profile_option_updated', subject: $option, description: "Updated {$this->groupLabel()} option “{$option->label}”", old: $old, new: $option->only(['label', 'is_active']));
        }

        $this->formOpen = false;
        session()->flash('status', 'Saved.');
    }

    public function toggleActive(int $id, ActivityLogger $logger): void
    {
        $this->authorize('edit_general_settings');
        $option = $this->option($id);

        if ($option->is_active && $this->isLastActive($option)) {
            session()->flash('error', 'Keep at least one option visible, or members could not answer this question.');

            return;
        }

        $option->update(['is_active' => ! $option->is_active]);
        $logger->log(module: 'masters', action: $option->is_active ? 'profile_option_shown' : 'profile_option_hidden', subject: $option, description: ($option->is_active ? 'Showed' : 'Hid')." {$this->groupLabel()} option “{$option->label}”");
        session()->flash('status', $option->is_active ? "“{$option->label}” is visible again." : "“{$option->label}” is hidden. Members who chose it keep it.");
    }

    public function move(int $id, int $direction): void
    {
        $this->authorize('edit_general_settings');
        Reorder::move(ProfileOption::query()->where('group', $this->group)->orderBy('sort_order')->orderBy('id')->get(), $id, $direction);
    }

    public function delete(int $id, ActivityLogger $logger): void
    {
        $this->authorize('edit_general_settings');
        $option = $this->option($id);

        if (! ProfileOption::groupAllowsNew($option->group)) {
            session()->flash('error', 'These answers are fixed. Hide the option instead.');

            return;
        }

        if (($used = $this->usage($option)) > 0) {
            session()->flash('error', "{$used} ".str('member')->plural($used).' chose “'.$option->label.'”, so it cannot be deleted. Hide it instead.');

            return;
        }

        if ($option->is_active && $this->isLastActive($option)) {
            session()->flash('error', 'Keep at least one option visible, or members could not answer this question.');

            return;
        }

        $option->delete();
        $logger->log(module: 'masters', action: 'profile_option_deleted', description: "Deleted {$this->groupLabel()} option “{$option->label}”");
        session()->flash('status', "“{$option->label}” deleted.");
    }

    public function closeForm(): void
    {
        $this->formOpen = false;
    }

    private function option(int $id): ProfileOption
    {
        return ProfileOption::query()->where('group', $this->group)->findOrFail($id);
    }

    private function isLastActive(ProfileOption $option): bool
    {
        return ! ProfileOption::query()->where('group', $option->group)->where('is_active', true)->whereKeyNot($option->id)->exists();
    }

    /** How many profiles use this option. */
    private function usage(ProfileOption $option): int
    {
        return Profile::query()
            ->when(
                $option->group === 'prompt',
                fn (Builder $q) => $q->whereJsonContains('prompts', ['q' => $option->key]),
                fn (Builder $q) => $q->where($option->group, $option->key),
            )
            ->count();
    }

    private function groupLabel(): string
    {
        return strtolower(ProfileOption::GROUPS[$this->group][0]);
    }
}
