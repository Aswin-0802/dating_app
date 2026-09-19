<?php

declare(strict_types=1);

namespace App\Livewire\Member;

use App\Enums\Gender;
use App\Livewire\Member\Concerns\InteractsWithMember;
use App\Models\City;
use App\Models\Interest;
use App\Models\Photo;
use App\Services\Media\MemberPhotoStore;
use App\Services\Members\ContentScanner;
use App\Services\Members\ProfileCompletion;
use App\Support\ProfileOptions;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use RuntimeException;

/**
 * Editing your own profile. Each section saves on its own, so changing your
 * age range never risks a half-written bio.
 */
class Profile extends Component
{
    use InteractsWithMember;
    use WithFileUploads;

    /** @var array<int, TemporaryUploadedFile> */
    public array $uploads = [];

    // about
    public string $display_name = '';

    public string $bio = '';

    public string $job_title = '';

    public string $company = '';

    public string $school = '';

    public string $education = '';

    public ?int $height_cm = null;

    public string $relationship_goal = 'unspecified';

    public string $drinking = 'unspecified';

    public string $smoking = 'unspecified';

    public string $children = 'unspecified';

    public string $languages = '';

    public ?int $city_id = null;

    /** @var array<int, array{q: string, a: string}> */
    public array $prompts = [];

    /** @var array<int, int> */
    public array $interestIds = [];

    // preferences
    /** @var array<int, string> */
    public array $interested_in = [];

    public int $age_min = 18;

    public int $age_max = 45;

    public int $max_distance_km = 50;

    public bool $global_mode = false;

    public bool $show_verified_only = false;

    public bool $previewing = false;

    public function mount(): void
    {
        $me = $this->member()->load(['profile', 'preferences', 'interests']);
        $profile = $me->profile;
        $prefs = $me->preferences;

        $this->display_name = $me->display_name;
        $this->city_id = $me->city_id;
        $this->bio = (string) $profile?->bio;
        $this->job_title = (string) $profile?->job_title;
        $this->company = (string) $profile?->company;
        $this->school = (string) $profile?->school;
        $this->education = (string) $profile?->education;
        $this->height_cm = $profile?->height_cm;
        $this->relationship_goal = $profile?->relationship_goal ?? 'unspecified';
        $this->drinking = $profile?->drinking ?? 'unspecified';
        $this->smoking = $profile?->smoking ?? 'unspecified';
        $this->children = $profile?->children ?? 'unspecified';
        $this->languages = collect($profile?->languages ?? [])->join(', ');

        $this->prompts = collect($profile?->prompts ?? [])->take(3)->values()->all();

        while (count($this->prompts) < 2) {
            $this->prompts[] = ['q' => '', 'a' => ''];
        }

        $this->interestIds = $me->interests->pluck('id')->all();

        $this->interested_in = $prefs?->interested_in ?? [];
        $this->age_min = (int) ($prefs?->age_min ?? 18);
        $this->age_max = (int) ($prefs?->age_max ?? 45);
        $this->max_distance_km = (int) ($prefs?->max_distance_km ?? 50);
        $this->global_mode = (bool) $prefs?->global_mode;
        $this->show_verified_only = (bool) $prefs?->show_verified_only;
    }

    public function render(ProfileCompletion $completion): View
    {
        $me = $this->member()->load(['photos' => fn ($q) => $q->orderByDesc('is_primary')->orderBy('position'), 'interests', 'profile', 'city']);

        return view('livewire.member.profile', [
            'me' => $me,
            'checklist' => $completion->checklist($me),
            'percent' => (int) $me->profile_completion,
            'genders' => collect(Gender::cases())->mapWithKeys(fn (Gender $g): array => [$g->value => $g->label()])->all(),
            'maxPhotos' => MemberPhotoStore::MAX_PHOTOS,
        ])->layout('components.layouts.member', ['title' => 'Your profile']);
    }

    /** @return array<string, array<int, string>> */
    #[Computed(persist: true)]
    public function interestGroups(): array
    {
        // Hidden interests drop out of the picker, but a member who already
        // chose one still sees it so they can keep or remove it.
        return Interest::query()
            ->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', $this->interestIds))
            ->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'category'])
            ->groupBy('category')
            ->map(fn ($group) => $group->pluck('name', 'id')->all())
            ->all();
    }

    /** @return array<string, array<int, string>> */
    #[Computed(persist: true)]
    public function cities(): array
    {
        return City::query()->whereHas('country', fn ($q) => $q->where('is_active', true))->with('country:id,name')->orderBy('name')->get(['id', 'name', 'country_id'])
            ->groupBy(fn (City $city): string => $city->country?->name ?? 'Other')
            ->sortKeys()
            ->map(fn ($group) => $group->pluck('name', 'id')->all())
            ->all();
    }

    // ---- photos ---------------------------------------------------------------

    /** Runs as soon as files are chosen: no separate "upload" button to miss. */
    public function updatedUploads(MemberPhotoStore $store, ProfileCompletion $completion): void
    {
        $this->validate([
            'uploads' => ['array', 'max:'.MemberPhotoStore::MAX_PHOTOS],
            'uploads.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
        ], ['uploads.*.max' => 'Each photo can be up to 8 MB.', 'uploads.*.image' => 'That file is not an image.']);

        $saved = 0;

        foreach ($this->uploads as $file) {
            try {
                $store->store($this->member(), $file);
                $saved++;
            } catch (RuntimeException $e) {
                $this->addError('uploads', $e->getMessage());
                break;
            }
        }

        $this->uploads = [];
        $completion->refresh($this->member());

        if ($saved > 0) {
            $this->toast($saved === 1 ? 'Photo added. It will show once our team has checked it.' : "{$saved} photos added. They will show once checked.");
        }
    }

    public function makePrimary(string $photoUuid, MemberPhotoStore $store): void
    {
        $store->makePrimary($this->ownPhoto($photoUuid));
        $this->toast('Main photo updated.');
    }

    public function deletePhoto(string $photoUuid, MemberPhotoStore $store, ProfileCompletion $completion): void
    {
        $store->delete($this->ownPhoto($photoUuid));
        $completion->refresh($this->member());
        $this->toast('Photo removed.');
    }

    // ---- sections -------------------------------------------------------------

    public function saveAbout(ProfileCompletion $completion): void
    {
        // Hidden options stay valid for members who already chose them.
        $profile = $this->member()->profile;
        $allowed = fn (string $group, ?string $current): array => array_keys(ProfileOptions::forSelect($group, $current));
        $savedPrompts = collect($profile?->prompts ?? [])->pluck('q')->filter()->all();
        $promptKeys = array_unique([...array_keys(ProfileOptions::options('prompt')), ...$savedPrompts]);

        $data = $this->validate([
            'display_name' => ['required', 'string', 'min:2', 'max:60', ProfileOptions::NAME_RULE],
            'bio' => ['nullable', 'string', 'max:500'],
            'job_title' => ['nullable', 'string', 'max:100'],
            'company' => ['nullable', 'string', 'max:100'],
            'school' => ['nullable', 'string', 'max:100'],
            'education' => ['nullable', Rule::in($allowed('education', $profile?->education))],
            'height_cm' => ['nullable', 'integer', 'min:120', 'max:230'],
            'relationship_goal' => ['required', Rule::in($allowed('relationship_goal', $profile?->relationship_goal))],
            'drinking' => ['required', Rule::in($allowed('drinking', $profile?->drinking))],
            'smoking' => ['required', Rule::in($allowed('smoking', $profile?->smoking))],
            'children' => ['required', Rule::in($allowed('children', $profile?->children))],
            'languages' => ['nullable', 'string', 'max:200'],
            'city_id' => ['required', 'exists:cities,id'],
            'prompts' => ['array', 'max:3'],
            'prompts.*.q' => ['nullable', Rule::in($promptKeys)],
            'prompts.*.a' => ['nullable', 'string', 'max:160'],
        ], [
            'display_name.regex' => ProfileOptions::NAME_MESSAGE,
            'height_cm.min' => 'Height must be between 120 and 230 cm.',
            'height_cm.max' => 'Height must be between 120 and 230 cm.',
        ]);

        $me = $this->member();
        $bio = filled($data['bio']) ? trim($data['bio']) : null;

        $me->forceFill([
            'display_name' => trim($data['display_name']),
            'city_id' => $data['city_id'],
            'country_id' => City::query()->whereKey($data['city_id'])->value('country_id'),
        ])->save();

        $me->profile()->updateOrCreate(['app_user_id' => $me->id], [
            'bio' => $bio,
            // Flagged on write, like the API, so the risk engine reads a fact.
            'bio_contains_contact' => $bio !== null && ContentScanner::containsContactInfo($bio),
            'job_title' => $data['job_title'] ?: null,
            'company' => $data['company'] ?: null,
            'school' => $data['school'] ?: null,
            'education' => $data['education'] ?: null,
            'height_cm' => $data['height_cm'] ?: null,
            'relationship_goal' => $data['relationship_goal'],
            'drinking' => $data['drinking'],
            'smoking' => $data['smoking'],
            'children' => $data['children'],
            'languages' => collect(explode(',', (string) $data['languages']))->map(fn ($l) => trim($l))->filter()->unique()->take(6)->values()->all(),
            'prompts' => collect($data['prompts'])
                ->filter(fn (array $p): bool => filled($p['q'] ?? null) && filled($p['a'] ?? null))
                ->map(fn (array $p): array => ['q' => $p['q'], 'a' => trim($p['a'])])
                ->values()
                ->all(),
        ]);

        $completion->refresh($me->fresh());
        $this->toast('Profile saved.');
    }

    public function toggleInterest(int $id): void
    {
        if (in_array($id, $this->interestIds, true)) {
            $this->interestIds = array_values(array_diff($this->interestIds, [$id]));
        } elseif (count($this->interestIds) < 10) {
            $this->interestIds[] = $id;
        }
    }

    public function saveInterests(ProfileCompletion $completion): void
    {
        $this->validate([
            'interestIds' => ['array', 'max:10'],
            'interestIds.*' => ['integer', Rule::exists('interests', 'id')->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', $this->member()->interests()->pluck('interests.id')->all()))],
        ], ['interestIds.max' => 'Pick up to 10.']);

        $this->member()->interests()->sync($this->interestIds);
        $completion->refresh($this->member());
        $this->toast('Interests saved.');
    }

    public function savePreferences(): void
    {
        $data = $this->validate([
            'interested_in' => ['required', 'array', 'min:1'],
            'interested_in.*' => [Rule::enum(Gender::class)],
            'age_min' => ['required', 'integer', 'min:18', 'max:99'],
            'age_max' => ['required', 'integer', 'min:18', 'max:99', 'gte:age_min'],
            'max_distance_km' => ['required', 'integer', 'min:1', 'max:500'],
            'global_mode' => ['boolean'],
            'show_verified_only' => ['boolean'],
        ], ['interested_in.required' => 'Choose at least one.', 'age_max.gte' => 'The top of the range must be above the bottom.']);

        $me = $this->member();
        $me->preferences()->updateOrCreate(['app_user_id' => $me->id], $data);

        $this->toast('Preferences saved. Your deck will reflect them straight away.');
    }

    public function togglePreview(): void
    {
        $this->previewing = ! $this->previewing;
    }

    private function ownPhoto(string $uuid): Photo
    {
        return Photo::query()->where('uuid', $uuid)->where('app_user_id', $this->member()->id)->firstOrFail();
    }
}
