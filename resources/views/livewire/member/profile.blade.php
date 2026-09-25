@php
    use App\Enums\VerificationStatus;
    use App\Support\ProfileOptions;

    $photos = $me->photos;
@endphp

<div class="space-y-6">
    {{-- ---- header & completion ------------------------------------------------ --}}
    <div class="flex flex-col gap-5 rounded-3xl border border-border bg-card p-5 shadow-sm sm:flex-row sm:items-center sm:p-6">
        <div class="relative w-fit shrink-0">
            <x-ui.avatar :src="$photos->first()?->thumb_url" :name="$me->display_name" size="2xl" />
            @if ($me->verification_status === VerificationStatus::Approved)
                <span class="absolute -bottom-1 -right-1 flex size-8 items-center justify-center rounded-full bg-card text-sky-500 shadow"><x-ui.icon name="check-badge" size="md" /></span>
            @endif
        </div>

        <div class="min-w-0 flex-1">
            <h1 class="text-2xl font-bold tracking-tight">{{ $me->display_name }}, {{ $me->age }}</h1>
            <p class="text-sm text-muted-foreground">{{ $me->city?->name }}</p>

            <div class="mt-3">
                <div class="flex items-center justify-between text-xs">
                    <span class="font-medium">Profile {{ $percent }}% complete</span>
                    @if ($percent < 100)
                        <span class="text-muted-foreground">{{ count(array_filter($checklist, fn ($done) => ! $done)) }} to go</span>
                    @endif
                </div>
                <div class="mt-1.5 h-2 overflow-hidden rounded-full bg-muted">
                    <div class="h-full rounded-full bg-primary transition-all" style="width: {{ $percent }}%"></div>
                </div>
            </div>
        </div>

        <div class="flex gap-2 sm:flex-col">
            <x-ui.button variant="outline" size="sm" wire:click="togglePreview">
                <x-ui.icon name="eye" size="sm" /> {{ $previewing ? 'Back to editing' : 'Preview' }}
            </x-ui.button>
            @if ($me->verification_status !== VerificationStatus::Approved)
                <x-ui.button size="sm" :href="route('member.verification')" wire:navigate>
                    <x-ui.icon name="check-badge" size="sm" /> Get verified
                </x-ui.button>
            @endif
        </div>
    </div>

    @if ($previewing)
        <div class="mx-auto max-w-md">
            <p class="mb-3 text-center text-sm text-muted-foreground">This is how other people see you.</p>
            <x-member.profile-card :person="$me" :me="$me" />
        </div>
    @else
        @if ($percent < 100)
            <div class="flex flex-wrap gap-2">
                @foreach ($checklist as $item => $done)
                    @unless ($done)
                        <span class="inline-flex items-center gap-1.5 rounded-full border border-dashed border-primary/40 bg-primary-subtle/50 px-3 py-1 text-xs font-medium text-primary-subtle-foreground">
                            <x-ui.icon name="plus" size="xs" /> {{ $item }}
                        </span>
                    @endunless
                @endforeach
            </div>
        @endif

        {{-- ---- photos ------------------------------------------------------- --}}
        <x-ui.card title="Photos" description="Your first photo is the one people see first. Clear, recent photos of just you work best.">
            <div class="grid grid-cols-3 gap-3">
                @foreach ($photos as $photo)
                    <div class="group relative aspect-[3/4] overflow-hidden rounded-2xl bg-muted" wire:key="photo-{{ $photo->uuid }}">
                        <img src="{{ $photo->thumb_url }}" alt="" class="size-full object-cover">

                        @if ($photo->is_primary)
                            <span class="absolute left-2 top-2 rounded-full bg-primary px-2 py-0.5 text-[10px] font-semibold text-primary-foreground">Main</span>
                        @endif
                        @if (in_array($photo->moderation_status, ['pending', 'auto_flagged'], true))
                            <span class="absolute bottom-2 left-2 rounded-full bg-black/60 px-2 py-0.5 text-[10px] font-medium text-white">Being checked</span>
                        @elseif ($photo->moderation_status === 'rejected')
                            <span class="absolute bottom-2 left-2 rounded-full bg-destructive px-2 py-0.5 text-[10px] font-medium text-white">Not approved</span>
                        @endif

                        <div class="absolute right-1.5 top-1.5 flex flex-col gap-1 opacity-100 transition sm:opacity-0 sm:group-hover:opacity-100">
                            @unless ($photo->is_primary)
                                <button type="button" wire:click="makePrimary('{{ $photo->uuid }}')" class="flex size-8 items-center justify-center rounded-full bg-white/90 text-foreground shadow" title="Make main photo">
                                    <x-ui.icon name="star" size="sm" /><span class="sr-only">Make main photo</span>
                                </button>
                            @endunless
                            <button type="button" wire:click="deletePhoto('{{ $photo->uuid }}')" wire:confirm="Remove this photo?" class="flex size-8 items-center justify-center rounded-full bg-white/90 text-destructive shadow" title="Remove">
                                <x-ui.icon name="trash" size="sm" /><span class="sr-only">Remove photo</span>
                            </button>
                        </div>
                    </div>
                @endforeach

                @if ($photos->count() < $maxPhotos)
                    <label class="relative flex aspect-[3/4] cursor-pointer flex-col items-center justify-center gap-1.5 rounded-2xl border-2 border-dashed border-border text-muted-foreground transition hover:border-primary hover:text-primary">
                        <span wire:loading.remove wire:target="uploads" class="flex flex-col items-center gap-1.5">
                            <x-ui.icon name="camera" size="lg" />
                            <span class="text-xs font-medium">Add photo</span>
                        </span>
                        <span wire:loading wire:target="uploads" class="text-xs font-medium">Uploading…</span>
                        <input type="file" wire:model="uploads" accept="image/jpeg,image/png,image/webp" multiple class="sr-only">
                    </label>
                @endif
            </div>

            @error('uploads') <p class="mt-3 text-sm text-destructive">{{ $message }}</p> @enderror
            @error('uploads.*') <p class="mt-3 text-sm text-destructive">{{ $message }}</p> @enderror
            <p class="mt-3 text-xs text-muted-foreground">Location data is removed from every photo before it is saved.</p>
        </x-ui.card>

        {{-- ---- about ------------------------------------------------------- --}}
        <form wire:submit="saveAbout">
            <x-ui.card title="About you">
                <div class="space-y-4">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-ui.input label="First name" wire:model="display_name" :error="$errors->first('display_name')" />
                        @include('livewire.member.partials.city-picker', ['error' => $errors->first('city_id')])
                    </div>

                    <div x-data="{ count: @js(mb_strlen($bio)) }">
                        <x-ui.textarea
                            label="Bio"
                            rows="4"
                            wire:model="bio"
                            maxlength="500"
                            x-on:input="count = $event.target.value.length"
                            placeholder="A few lines about you. What would your friends say?"
                            :error="$errors->first('bio')"
                        />
                        <p class="mt-1 text-right text-xs text-muted-foreground"><span x-text="count"></span>/500</p>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-ui.input label="Job title" wire:model="job_title" :error="$errors->first('job_title')" />
                        <x-ui.input label="Company" wire:model="company" :error="$errors->first('company')" />
                        <x-ui.input label="School or university" wire:model="school" :error="$errors->first('school')" />
                        <x-ui.select label="Education" placeholder="Choose" wire:model="education" :selected="$education" :options="ProfileOptions::forSelect('education', $education)" />
                        <x-ui.input label="Height (cm)" type="number" min="120" max="230" wire:model="height_cm" :error="$errors->first('height_cm')" />
                        <x-ui.input label="Languages" hint="Separate with commas." wire:model="languages" placeholder="English, Spanish" />
                        <x-ui.select label="Looking for" wire:model="relationship_goal" :selected="$relationship_goal" :options="ProfileOptions::forSelect('relationship_goal', $relationship_goal)" />
                        <x-ui.select label="Children" wire:model="children" :selected="$children" :options="ProfileOptions::forSelect('children', $children)" />
                        <x-ui.select label="Drinking" wire:model="drinking" :selected="$drinking" :options="ProfileOptions::forSelect('drinking', $drinking)" />
                        <x-ui.select label="Smoking" wire:model="smoking" :selected="$smoking" :options="ProfileOptions::forSelect('smoking', $smoking)" />
                    </div>

                    <div class="space-y-3 border-t border-border pt-4">
                        <div>
                            <p class="text-sm font-medium">Prompts</p>
                            <p class="text-xs text-muted-foreground">The easiest way to give people something to reply to.</p>
                        </div>
                        @foreach ($prompts as $i => $prompt)
                            <div class="space-y-2 rounded-2xl bg-muted/50 p-3" wire:key="prompt-{{ $i }}">
                                <x-ui.select placeholder="Choose a prompt" wire:model="prompts.{{ $i }}.q" :selected="$prompt['q']" :options="ProfileOptions::forSelect('prompt', $prompt['q'])" />
                                <x-ui.input wire:model="prompts.{{ $i }}.a" placeholder="Your answer" maxlength="160" :error="$errors->first('prompts.'.$i.'.a')" />
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="mt-5 flex justify-end">
                    <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="saveAbout">Save</x-ui.button>
                </div>
            </x-ui.card>
        </form>

        {{-- ---- interests --------------------------------------------------- --}}
        <x-ui.card title="Interests" :description="'Pick up to 10. You have chosen '.count($interestIds).'.'">
            <div class="space-y-4">
                @foreach ($this->interestGroups as $category => $interests)
                    <div>
                        <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">{{ $category }}</p>
                        <div class="flex flex-wrap gap-2">
                            @foreach ($interests as $id => $name)
                                @php $on = in_array($id, $interestIds, true); @endphp
                                <button
                                    type="button"
                                    wire:click="toggleInterest({{ $id }})"
                                    @class([
                                        'rounded-full border px-3.5 py-1.5 text-sm transition',
                                        'border-primary bg-primary text-primary-foreground' => $on,
                                        'border-border hover:border-primary/50' => ! $on,
                                        'opacity-40' => ! $on && count($interestIds) >= 10,
                                    ])
                                    aria-pressed="{{ $on ? 'true' : 'false' }}"
                                >{{ $name }}</button>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
            @error('interestIds') <p class="mt-3 text-sm text-destructive">{{ $message }}</p> @enderror

            <div class="mt-5 flex justify-end">
                <x-ui.button wire:click="saveInterests" wire:loading.attr="disabled" wire:target="saveInterests">Save interests</x-ui.button>
            </div>
        </x-ui.card>

        {{-- ---- preferences ------------------------------------------------- --}}
        <form wire:submit="savePreferences" id="preferences" class="scroll-mt-24">
            <x-ui.card title="Who you want to meet" description="Your deck follows these as soon as you save.">
                <div class="space-y-5">
                    <div>
                        <p class="mb-2 text-sm font-medium">Show me</p>
                        <div class="flex flex-wrap gap-2">
                            @foreach ($genders as $value => $label)
                                <label class="cursor-pointer rounded-full border border-border px-4 py-2 text-sm transition has-[:checked]:border-primary has-[:checked]:bg-primary-subtle has-[:checked]:text-primary-subtle-foreground">
                                    <input type="checkbox" wire:model="interested_in" value="{{ $value }}" class="sr-only"> {{ $label }}
                                </label>
                            @endforeach
                        </div>
                        @error('interested_in') <p class="mt-1.5 text-xs text-destructive">{{ $message }}</p> @enderror
                    </div>

                    <div class="grid gap-4 sm:grid-cols-3">
                        <x-ui.input label="Age from" type="number" min="18" max="99" wire:model="age_min" :error="$errors->first('age_min')" />
                        <x-ui.input label="Age to" type="number" min="18" max="99" wire:model="age_max" :error="$errors->first('age_max')" />
                        <x-ui.input label="Distance (km)" type="number" min="1" max="500" wire:model="max_distance_km" :error="$errors->first('max_distance_km')" />
                    </div>

                    <x-ui.toggle label="Global mode" description="See people outside your city — handy when travelling." wire:model="global_mode" :checked="$global_mode" />
                    <x-ui.toggle label="Verified people only" description="Only show profiles that have passed a photo check." wire:model="show_verified_only" :checked="$show_verified_only" />
                </div>

                <div class="mt-5 flex justify-end">
                    <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="savePreferences">Save preferences</x-ui.button>
                </div>
            </x-ui.card>
        </form>
    @endif
</div>
