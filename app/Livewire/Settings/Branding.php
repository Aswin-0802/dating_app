<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Models\Setting;
use App\Services\Audit\ActivityLogger;
use App\Support\Branding as Brand;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Settings -> Branding: everything a buyer changes to make the product theirs.
 *
 * Name, logos, favicon, sign-in artwork, brand colour, company details, public
 * website copy, app store and social links. It is its own screen rather than a
 * group in the generic settings list because it needs file uploads, a colour
 * picker and a live preview — and because it is the first screen anybody who
 * buys this opens.
 */
class Branding extends Component
{
    use WithFileUploads;

    /**
     * Text settings, keyed by a dot-free field name.
     *
     * Setting keys are dotted ("brand.name") and Livewire reads a dot in
     * wire:model as a path into a nested array, so they never appear in a
     * binding directly — that exact mistake is what stopped the general
     * settings screen from saving anything.
     *
     * @var array<string, mixed>
     */
    public array $form = [];

    /** @var array<string, TemporaryUploadedFile|null> */
    public array $uploads = [
        'admin_logo' => null,
        'logo' => null,
        'favicon' => null,
        'login_image' => null,
    ];

    /** Upload field -> setting key. */
    private const IMAGES = [
        'admin_logo' => 'brand.admin_logo',
        'logo' => 'brand.logo',
        'favicon' => 'brand.favicon',
        'login_image' => 'brand.login_image',
    ];

    /** Colours offered as one-click swatches; any hex is still accepted. */
    public const PRESETS = [
        '#c2265a' => 'Rose',
        '#e11d48' => 'Crimson',
        '#db2777' => 'Pink',
        '#9333ea' => 'Violet',
        '#4f46e5' => 'Indigo',
        '#2563eb' => 'Blue',
        '#0891b2' => 'Teal',
        '#059669' => 'Emerald',
        '#ea580c' => 'Orange',
        '#1f2937' => 'Graphite',
    ];

    public function mount(): void
    {
        $this->load();
    }

    public function render(): View
    {
        return view('livewire.settings.branding', [
            'canEdit' => $this->canEdit(),
            'current' => collect(self::IMAGES)->map(fn (string $key): ?string => $this->storedUrl($key))->all(),
            'previewColor' => Brand::isHex((string) ($this->form['brand__primary_color'] ?? ''))
                ? $this->form['brand__primary_color']
                : Brand::DEFAULT_COLOR,
            'presets' => self::PRESETS,
        ])->layout('components.layouts.admin', [
            'title' => 'Branding',
            'breadcrumbs' => [
                ['label' => Brand::name(), 'href' => route('admin.dashboard')],
                ['label' => 'Settings', 'href' => route('admin.settings.general')],
                ['label' => 'Branding'],
            ],
        ]);
    }

    public function usePreset(string $hex): void
    {
        if (array_key_exists($hex, self::PRESETS)) {
            $this->form['brand__primary_color'] = $hex;
        }
    }

    public function save(ActivityLogger $logger): void
    {
        abort_unless($this->canEdit(), 403);

        $this->validate($this->rules(), [
            'form.brand__primary_color.regex' => 'Use a hex colour such as #c2265a.',
        ], $this->attributes());

        $changed = [];

        foreach ($this->textSettings() as $setting) {
            $field = $this->field($setting->key);
            $new = $this->form[$field] ?? null;

            if ($setting->type === 'boolean') {
                $new = $new ? '1' : '0';
            }

            $new = is_string($new) ? trim($new) : (string) ($new ?? '');

            if ($new === (string) $setting->value) {
                continue;
            }

            $changed[$setting->key] = ['old' => $setting->value, 'new' => $new];
            $setting->update(['value' => $new, 'updated_by' => auth()->id()]);
        }

        foreach (self::IMAGES as $field => $key) {
            $file = $this->uploads[$field] ?? null;

            if (! $file instanceof TemporaryUploadedFile) {
                continue;
            }

            $old = (string) Setting::query()->where('key', $key)->value('value');
            $path = $file->store('branding', 'public');

            Setting::put($key, $path, auth()->id());
            $this->deleteFile($old);

            $changed[$key] = ['old' => $old ?: null, 'new' => $path];
        }

        if ($changed !== []) {
            $logger->log(
                module: 'settings',
                action: 'updated',
                description: sprintf('Branding updated (%d %s)', count($changed), str('change')->plural(count($changed))),
                old: array_map(fn (array $c) => $c['old'], $changed),
                new: array_map(fn (array $c) => $c['new'], $changed),
            );
        }

        session()->flash('status', $changed === [] ? 'No changes to save.' : 'Branding saved.');

        // A full reload rather than a re-render: the sidebar, favicon, page title
        // and colour tokens all live in the layout, outside this component.
        $this->redirectRoute('admin.settings.branding');
    }

    public function removeImage(string $field, ActivityLogger $logger): void
    {
        abort_unless($this->canEdit(), 403);
        abort_unless(array_key_exists($field, self::IMAGES), 404);

        $key = self::IMAGES[$field];
        $old = (string) Setting::query()->where('key', $key)->value('value');

        if ($old === '') {
            return;
        }

        Setting::put($key, '', auth()->id());
        $this->deleteFile($old);

        $logger->log(
            module: 'settings',
            action: 'updated',
            description: "Removed the {$this->attributes()["uploads.{$field}"]}",
            old: [$key => $old],
            new: [$key => null],
        );

        session()->flash('status', 'Image removed.');
        $this->redirectRoute('admin.settings.branding');
    }

    public function clearUpload(string $field): void
    {
        if (array_key_exists($field, $this->uploads)) {
            $this->uploads[$field] = null;
        }
    }

    /** @return array<string, mixed> */
    protected function rules(): array
    {
        return [
            'form.brand__name' => ['required', 'string', 'max:60'],
            'form.brand__tagline' => ['nullable', 'string', 'max:80'],
            'form.brand__primary_color' => ['required', 'regex:/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
            'form.brand__theme_mode' => ['required', 'in:light,dark,system'],
            'form.brand__support_email' => ['nullable', 'email', 'max:255'],
            'form.business__email' => ['nullable', 'email', 'max:255'],
            'form.website__plus_price' => ['nullable', 'numeric', 'min:0'],
            'form.website__gold_price' => ['nullable', 'numeric', 'min:0'],
            'form.app__ios_url' => ['nullable', 'url', 'max:255'],
            'form.app__android_url' => ['nullable', 'url', 'max:255'],
            'form.social__instagram' => ['nullable', 'url', 'max:255'],
            'form.social__facebook' => ['nullable', 'url', 'max:255'],
            'form.social__x' => ['nullable', 'url', 'max:255'],
            'form.social__tiktok' => ['nullable', 'url', 'max:255'],
            'form.social__youtube' => ['nullable', 'url', 'max:255'],
            'form.social__linkedin' => ['nullable', 'url', 'max:255'],

            /*
             * SVG is deliberately not accepted. It is served from this origin,
             * and an SVG opened directly is a document that can run script —
             * an upload field for "the logo" would become a stored-XSS vector
             * for whoever can reach this screen.
             */
            'uploads.admin_logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:1024'],
            'uploads.logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:1024'],
            'uploads.favicon' => ['nullable', 'file', 'mimes:png,ico', 'max:256'],
            'uploads.login_image' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:3072'],
        ];
    }

    /** @return array<string, string> */
    protected function attributes(): array
    {
        return [
            'form.brand__name' => 'product name',
            'form.brand__primary_color' => 'brand colour',
            'uploads.admin_logo' => 'admin logo',
            'uploads.logo' => 'website logo',
            'uploads.favicon' => 'favicon',
            'uploads.login_image' => 'sign-in image',
        ];
    }

    private function load(): void
    {
        $this->form = $this->textSettings()
            ->mapWithKeys(fn (Setting $s): array => [
                $this->field($s->key) => $s->type === 'boolean' ? (bool) $s->typed_value : (string) $s->value,
            ])
            ->all();
    }

    /** @return Collection<int, Setting> */
    private function textSettings()
    {
        return Setting::query()
            ->where('group', 'branding')
            ->where('type', '!=', 'image')
            ->orderBy('sort_order')
            ->get();
    }

    private function field(string $key): string
    {
        return str_replace('.', '__', $key);
    }

    private function storedUrl(string $key): ?string
    {
        $path = Setting::query()->where('key', $key)->value('value');

        return filled($path) ? asset('storage/'.$path) : null;
    }

    private function deleteFile(string $path): void
    {
        // Only ever files this screen wrote — never an arbitrary path from the
        // settings table.
        if ($path !== '' && str_starts_with($path, 'branding/')) {
            Storage::disk('public')->delete($path);
        }
    }

    private function canEdit(): bool
    {
        return auth()->user()?->can('edit_general_settings') ?? false;
    }
}
