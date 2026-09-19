@php
    use App\Support\Branding as Brand;

    $imageFields = [
        'admin_logo' => ['label' => 'Admin logo', 'hint' => 'Sidebar and staff sign-in. PNG, JPG or WebP, up to 1 MB. A square icon or a short wordmark both work.', 'accept' => 'image/png,image/jpeg,image/webp'],
        'logo' => ['label' => 'Website logo', 'hint' => 'Public website and member app. PNG, JPG or WebP, up to 1 MB.', 'accept' => 'image/png,image/jpeg,image/webp'],
        'favicon' => ['label' => 'Favicon', 'hint' => 'Browser tab icon. PNG or ICO, up to 256 KB, ideally 64×64.', 'accept' => 'image/png,image/x-icon,.ico'],
        'login_image' => ['label' => 'Sign-in image', 'hint' => 'Fills the right half of the staff sign-in page. PNG, JPG or WebP, up to 3 MB.', 'accept' => 'image/png,image/jpeg,image/webp'],
    ];
@endphp

<div class="space-y-4 md:space-y-6">
    @unless ($canEdit)
        <div class="flex items-start gap-3 rounded-xl border border-border bg-muted/40 px-4 py-3">
            <x-ui.icon name="lock" size="sm" class="mt-0.5 shrink-0 text-muted-foreground" />
            <p class="min-w-0 text-sm text-muted-foreground">You can see the branding but not change it.</p>
        </div>
    @endunless

    <div class="flex flex-wrap gap-1">
        <a href="{{ route('admin.settings.branding') }}" wire:navigate class="rounded-md bg-primary-subtle px-3 py-1.5 text-sm font-medium text-primary-subtle-foreground">Branding</a>
        <a href="{{ route('admin.settings.general') }}" wire:navigate class="rounded-md px-3 py-1.5 text-sm text-muted-foreground hover:bg-muted hover:text-foreground">Product &amp; safety settings</a>
        <a href="{{ route('admin.settings.locations') }}" wire:navigate class="rounded-md px-3 py-1.5 text-sm text-muted-foreground hover:bg-muted hover:text-foreground">Locations</a>
    </div>

    <div class="grid gap-4 md:gap-6 xl:grid-cols-[1fr_360px]">
        <form wire:submit="save" class="min-w-0 space-y-4 md:space-y-6">
            <fieldset @disabled(! $canEdit) class="min-w-0 space-y-4 md:space-y-6">

                {{-- ---- identity ------------------------------------------ --}}
                <x-ui.card title="Identity" description="What the product is called, everywhere it appears.">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-ui.input label="Product name" wire:model.live.debounce.300ms="form.brand__name" :error="$errors->first('form.brand__name')" required />
                        <x-ui.input label="Admin tagline" hint="Under the name in the admin sidebar." wire:model.live.debounce.300ms="form.brand__tagline" :error="$errors->first('form.brand__tagline')" />
                        <div class="sm:col-span-2">
                            <x-ui.input label="Support email" type="email" hint="Shown to members on the website and in the app." wire:model="form.brand__support_email" :error="$errors->first('form.brand__support_email')" />
                        </div>
                    </div>
                </x-ui.card>

                {{-- ---- colour & theme ------------------------------------ --}}
                <x-ui.card title="Colour and theme" description="One colour drives buttons, links, highlights and the sidebar accent, in light and dark mode.">
                    <div class="space-y-4">
                        <div>
                            <p class="mb-2 text-sm font-medium">Brand colour</p>

                            <div class="flex flex-wrap items-center gap-2">
                                @foreach ($presets as $hex => $name)
                                    <button
                                        type="button"
                                        wire:click="usePreset('{{ $hex }}')"
                                        title="{{ $name }}"
                                        aria-label="{{ $name }}"
                                        @class([
                                            'size-8 rounded-full ring-offset-2 ring-offset-card transition',
                                            'ring-2 ring-foreground' => strtolower($previewColor) === $hex,
                                            'hover:scale-110' => strtolower($previewColor) !== $hex,
                                        ])
                                        style="background-color: {{ $hex }}"
                                    ></button>
                                @endforeach
                            </div>

                            <div class="mt-3 flex items-center gap-2">
                                {{-- The native picker and the text field bind to the
                                     same value, so either can be used. --}}
                                <input
                                    type="color"
                                    wire:model.live="form.brand__primary_color"
                                    class="h-9 w-12 shrink-0 cursor-pointer rounded-md border border-input bg-card p-1"
                                    aria-label="Pick a colour"
                                >
                                <div class="w-40">
                                    <x-ui.input wire:model.live.debounce.400ms="form.brand__primary_color" placeholder="#c2265a" class="font-mono" />
                                </div>
                                @if (strtolower($previewColor) !== Brand::DEFAULT_COLOR)
                                    <x-ui.button variant="ghost" size="sm" wire:click="usePreset('{{ Brand::DEFAULT_COLOR }}')">Reset</x-ui.button>
                                @endif
                            </div>
                            @error('form.brand__primary_color')
                                <p class="mt-1.5 text-xs text-destructive">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="max-w-xs">
                            <x-ui.select
                                label="Default theme"
                                hint="Used until each person picks their own."
                                wire:model="form.brand__theme_mode"
                                :selected="$form['brand__theme_mode'] ?? 'system'"
                                :options="['system' => 'Follow the device', 'light' => 'Light', 'dark' => 'Dark']"
                            />
                        </div>
                    </div>
                </x-ui.card>

                {{-- ---- logos & images ------------------------------------ --}}
                <x-ui.card title="Logos and images" description="SVG is not accepted: an SVG file can carry script, and these are served from your own domain.">
                    <div class="grid gap-5 sm:grid-cols-2">
                        @foreach ($imageFields as $field => $meta)
                            @php
                                $pending = $uploads[$field] ?? null;
                                $pendingUrl = null;

                                if ($pending && method_exists($pending, 'isPreviewable') && $pending->isPreviewable()) {
                                    $pendingUrl = $pending->temporaryUrl();
                                }

                                $shown = $pendingUrl ?? $current[$field];
                            @endphp

                            <div class="space-y-2" wire:key="upload-{{ $field }}">
                                <p class="text-sm font-medium">{{ $meta['label'] }}</p>

                                <div @class([
                                    'flex items-center justify-center overflow-hidden rounded-lg border border-dashed border-border bg-muted/40',
                                    'h-28' => $field !== 'login_image',
                                    'h-36' => $field === 'login_image',
                                ])>
                                    @if ($shown)
                                        <img src="{{ $shown }}" alt="" @class([
                                            'max-h-full',
                                            'max-w-[70%] object-contain p-3' => $field !== 'login_image',
                                            'size-full object-cover' => $field === 'login_image',
                                        ])>
                                    @else
                                        <span class="flex flex-col items-center gap-1 text-xs text-muted-foreground">
                                            <x-ui.icon name="photo" size="md" />
                                            Nothing uploaded
                                        </span>
                                    @endif
                                </div>

                                <div class="flex flex-wrap items-center gap-2">
                                    <label class="inline-flex h-8 cursor-pointer items-center gap-1.5 rounded-md border border-border bg-card px-3 text-sm hover:bg-muted">
                                        <x-ui.icon name="arrow-up" size="xs" />
                                        {{ $current[$field] ? 'Replace' : 'Upload' }}
                                        <input type="file" wire:model="uploads.{{ $field }}" accept="{{ $meta['accept'] }}" class="sr-only">
                                    </label>

                                    @if ($pending)
                                        <x-ui.button variant="ghost" size="sm" wire:click="clearUpload('{{ $field }}')">Cancel</x-ui.button>
                                        <x-ui.badge variant="warning" size="sm">Unsaved</x-ui.badge>
                                    @elseif ($current[$field])
                                        <x-ui.button
                                            variant="ghost"
                                            size="sm"
                                            wire:click="removeImage('{{ $field }}')"
                                            wire:confirm="Remove this image?"
                                        >Remove</x-ui.button>
                                    @endif

                                    <span wire:loading wire:target="uploads.{{ $field }}" class="text-xs text-muted-foreground">Uploading…</span>
                                </div>

                                @error('uploads.'.$field)
                                    <p class="text-xs text-destructive">{{ $message }}</p>
                                @else
                                    <p class="text-xs text-muted-foreground">{{ $meta['hint'] }}</p>
                                @enderror
                            </div>
                        @endforeach
                    </div>
                </x-ui.card>

                {{-- ---- website ------------------------------------------- --}}
                <x-ui.card title="Public website" description="The home page members see before they sign up.">
                    <div class="space-y-4">
                        <x-ui.toggle
                            size="lg"
                            label="Public website"
                            description="When off, the home page sends visitors straight to sign in."
                            wire:model="form.website__enabled"
                            :checked="(bool) ($form['website__enabled'] ?? true)"
                        />
                        <x-ui.input label="Headline" wire:model="form.website__hero_title" />
                        <x-ui.textarea label="Subheading" rows="2" wire:model="form.website__hero_subtitle" />
                        <x-ui.input label="Search engine description" hint="The snippet under your link in search results." wire:model="form.website__meta_description" />
                        <x-ui.input label="Footer text" wire:model="form.website__footer_text" />

                        <div class="grid gap-4 sm:grid-cols-3">
                            <x-ui.select
                                label="Currency"
                                hint="Used for every price and amount."
                                wire:model.live="form.billing__currency"
                                :selected="$form['billing__currency'] ?? App\Support\Currency::DEFAULT"
                                :options="App\Support\Currency::options()"
                                :error="$errors->first('form.billing__currency')"
                            />
                            <x-ui.input label="Plus price / month" wire:model="form.website__plus_price" :error="$errors->first('form.website__plus_price')" />
                            <x-ui.input label="Gold price / month" wire:model="form.website__gold_price" :error="$errors->first('form.website__gold_price')" />
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <x-ui.input label="App Store link" placeholder="https://apps.apple.com/…" wire:model="form.app__ios_url" :error="$errors->first('form.app__ios_url')" />
                            <x-ui.input label="Google Play link" placeholder="https://play.google.com/…" wire:model="form.app__android_url" :error="$errors->first('form.app__android_url')" />
                        </div>
                    </div>
                </x-ui.card>

                {{-- ---- company ------------------------------------------- --}}
                <x-ui.card title="Company details" description="Shown in the website footer and on the legal pages.">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-ui.input label="Company name" wire:model="form.business__name" />
                        <x-ui.input label="Company email" type="email" wire:model="form.business__email" :error="$errors->first('form.business__email')" />
                        <x-ui.input label="Phone" wire:model="form.business__phone" />
                        <div class="sm:col-span-2">
                            <x-ui.textarea label="Address" rows="2" wire:model="form.business__address" />
                        </div>
                    </div>
                </x-ui.card>

                {{-- ---- social -------------------------------------------- --}}
                <x-ui.card title="Social links" description="Leave any blank to hide its icon.">
                    <div class="grid gap-4 sm:grid-cols-2">
                        @foreach (['instagram' => 'Instagram', 'facebook' => 'Facebook', 'x' => 'X / Twitter', 'tiktok' => 'TikTok', 'youtube' => 'YouTube', 'linkedin' => 'LinkedIn'] as $network => $label)
                            <x-ui.input
                                :label="$label"
                                placeholder="https://"
                                wire:model="form.social__{{ $network }}"
                                :error="$errors->first('form.social__'.$network)"
                            />
                        @endforeach
                    </div>
                </x-ui.card>
            </fieldset>

            @if ($canEdit)
                <div class="sticky bottom-0 z-10 -mx-4 flex items-center gap-3 border-t border-border bg-background/90 px-4 py-3 backdrop-blur md:mx-0 md:rounded-xl md:border">
                    <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">
                        <span wire:loading.remove wire:target="save">Save branding</span>
                        <span wire:loading wire:target="save">Saving…</span>
                    </x-ui.button>
                    <p class="text-xs text-muted-foreground">Applies to the admin, sign-in pages and website as soon as it is saved.</p>
                </div>
            @endif
        </form>

        {{-- ---- live preview ---------------------------------------------- --}}
        <aside class="xl:sticky xl:top-20 xl:self-start">
            <x-ui.card title="Preview" description="Updates as you type. Not saved until you save.">
                {{-- Tokens applied inline to this panel only, so the chosen colour
                     can be judged against real components before it goes live. --}}
                <div style="{{ Brand::inlineTokens($previewColor) }}" class="space-y-4">
                    <div class="overflow-hidden rounded-lg border border-border">
                        <div class="flex items-center gap-2.5 bg-sidebar px-3 py-3 text-sidebar-foreground">
                            @if ($current['admin_logo'] || $current['logo'])
                                <img src="{{ $current['admin_logo'] ?? $current['logo'] }}" alt="" class="h-8 w-auto max-w-28 object-contain">
                            @else
                                <span class="flex size-8 items-center justify-center rounded-lg bg-sidebar-primary text-sidebar-primary-foreground">
                                    <svg viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 8l7 11 7-11" /></svg>
                                </span>
                            @endif
                            <span class="min-w-0">
                                <span class="block truncate text-sm font-semibold leading-tight">{{ $form['brand__name'] ?? '' }}</span>
                                <span class="block truncate text-[11px] leading-tight text-sidebar-muted-foreground">{{ $form['brand__tagline'] ?? '' }}</span>
                            </span>
                        </div>
                        <div class="space-y-1 bg-sidebar px-2 pb-3">
                            <span class="flex items-center gap-2 rounded-md bg-sidebar-accent px-2.5 py-1.5 text-sm text-sidebar-accent-foreground">
                                <span class="size-1.5 rounded-full bg-sidebar-primary"></span> Dashboard
                            </span>
                            <span class="flex items-center gap-2 px-2.5 py-1.5 text-sm text-sidebar-foreground/70">Members</span>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <span class="inline-flex h-9 items-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground shadow-sm">Primary action</span>
                        <span class="inline-flex h-9 items-center rounded-md border border-border bg-card px-4 text-sm">Secondary</span>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <span class="rounded-full bg-primary-subtle px-2 py-0.5 text-xs font-medium text-primary-subtle-foreground">Highlighted</span>
                        <span class="text-sm font-medium text-primary">A link in the brand colour</span>
                    </div>

                    <div class="h-2 overflow-hidden rounded-full bg-muted">
                        <div class="h-full w-2/3 rounded-full bg-primary"></div>
                    </div>
                </div>
            </x-ui.card>
        </aside>
    </div>
</div>
