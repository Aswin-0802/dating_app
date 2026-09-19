<div class="space-y-4 md:space-y-6">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-muted-foreground">
            Templates marked <span class="font-medium text-foreground">transactional</span> carry the
            statement of reasons a member receives when action is taken against their account.
            Editing one is a policy change, and it is logged as such.
        </p>

        <div class="flex items-center gap-2">
            <x-ui.select size="sm" placeholder="All audiences" wire:model.live="audience"
                :options="['member' => 'Member-facing', 'staff' => 'Staff alerts']" class="w-44" />
            @if ($canEdit)
                <x-ui.button size="sm" icon="plus" wire:click="create">Add template</x-ui.button>
            @endif
        </div>
    </div>

    @foreach ($templates as $category => $group)
        <x-ui.card :title="str($category)->headline()->toString()">
            <div class="divide-y divide-border">
                @foreach ($group as $template)
                    <div class="py-4 first:pt-0 last:pb-0">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0 space-y-1">
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <p class="text-sm font-medium">{{ $template->name }}</p>

                                    <x-ui.badge variant="muted" size="sm">{{ $template->channel }}</x-ui.badge>

                                    @if ($template->is_transactional)
                                        <x-ui.badge variant="destructive" size="sm" icon="lock">Transactional</x-ui.badge>
                                    @endif

                                    @unless ($template->is_active)
                                        <x-ui.badge variant="warning" size="sm">Inactive</x-ui.badge>
                                    @endunless
                                </div>

                                <p class="font-mono text-[11px] text-muted-foreground">{{ $template->key }}</p>
                            </div>

                            @if ($canEdit && $editing !== $template->id)
                                <div class="flex items-center gap-1">
                                    <x-ui.button size="xs" variant="outline" wire:click="edit({{ $template->id }})">
                                        Edit
                                    </x-ui.button>
                                    @unless ($template->isProtected())
                                        <x-ui.button size="xs" variant="ghost" class="text-destructive" wire:click="delete({{ $template->id }})" wire:confirm="Delete the “{{ $template->name }}” template?">
                                            Delete
                                        </x-ui.button>
                                    @endunless
                                </div>
                            @endif
                        </div>

                        @if ($editing === $template->id)
                            <div class="mt-3 space-y-3 rounded-lg border border-border bg-muted/30 p-3">
                                <x-ui.input label="Subject" wire:model="subject" :error="$errors->first('subject')" />

                                <x-ui.textarea label="Body" rows="4" wire:model="body"
                                    :error="$errors->first('body')">{{ $body }}</x-ui.textarea>

                                @if ($template->placeholders)
                                    <div class="flex flex-wrap items-center gap-1.5">
                                        <span class="text-xs text-muted-foreground">Available:</span>
                                        @foreach ($template->placeholders as $placeholder)
                                            {{-- Built in PHP: Blade cannot parse a literal
                                                 double-brace inside an interpolation. --}}
                                            @php $token = sprintf('{{ %s }}', $placeholder); @endphp

                                            <code class="rounded bg-card px-1.5 py-0.5 text-[11px]">{{ $token }}</code>
                                        @endforeach
                                    </div>
                                @endif

                                <div class="flex items-center gap-2">
                                    <x-ui.button size="sm" wire:click="save">Save</x-ui.button>
                                    <x-ui.button size="sm" variant="ghost" wire:click="cancel">Cancel</x-ui.button>
                                </div>
                            </div>
                        @else
                            <div class="mt-2 space-y-1">
                                @if ($template->subject)
                                    <p class="text-sm font-medium">{{ $template->subject }}</p>
                                @endif
                                <p class="whitespace-pre-line text-sm text-muted-foreground">{{ $template->body }}</p>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </x-ui.card>
    @endforeach

    @if ($templates->isEmpty())
        <x-ui.card flush>
            <x-ui.empty-state icon="document" heading="No templates match this filter" />
        </x-ui.card>
    @endif

    <x-ui.dialog :show="$creating" close="closeCreate" size="lg">
        <form wire:submit="store" class="space-y-4 p-6" novalidate>
            <h2 class="text-lg font-semibold">Add template</h2>

            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.input label="Category" wire:model="newCategory" list="template-categories" placeholder="engagement" :error="$errors->first('newCategory')" required autofocus />
                <x-ui.input label="Name" wire:model.live.debounce.400ms="newName" placeholder="Weekend reminder" :error="$errors->first('newName')" required />
            </div>
            <datalist id="template-categories">
                @foreach ($categories as $c)
                    <option value="{{ $c }}"></option>
                @endforeach
            </datalist>

            <x-ui.input label="Key" wire:model="newKey" hint="How the apps and campaigns refer to it. Cannot be changed later." :error="$errors->first('newKey')" required />

            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.select label="Audience" wire:model="newAudience" :selected="$newAudience" :options="['member' => 'Members', 'staff' => 'Staff']" :error="$errors->first('newAudience')" />
                <x-ui.select label="Channel" wire:model.live="newChannel" :selected="$newChannel" :options="['push' => 'Push', 'email' => 'Email', 'in_app' => 'In-app']" :error="$errors->first('newChannel')" />
            </div>

            <x-ui.input :label="$newChannel === 'email' ? 'Subject' : 'Title'" wire:model="newSubject" :required="$newChannel === 'email'" :error="$errors->first('newSubject')" />
            <x-ui.textarea label="Body" rows="4" wire:model="newBody" :error="$errors->first('newBody')" required />
            @php $example = sprintf('Hi {{ %s }}', 'first_name'); @endphp
            <x-ui.input label="Placeholders" wire:model="newPlaceholders" :hint="'Comma-separated. Use them in the text as '.$example.'.'" :error="$errors->first('newPlaceholders')" />

            <x-ui.toggle label="Active" description="Turn off to keep a draft without the apps using it." wire:model="newActive" :checked="$newActive" />

            <div class="flex flex-col-reverse gap-2 pt-2 sm:flex-row sm:justify-end">
                <x-ui.button variant="ghost" wire:click="closeCreate">Cancel</x-ui.button>
                <x-ui.button type="submit">Add template</x-ui.button>
            </div>
        </form>
    </x-ui.dialog>
</div>
