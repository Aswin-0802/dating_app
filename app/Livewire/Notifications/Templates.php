<?php

declare(strict_types=1);

namespace App\Livewire\Notifications;

use App\Models\NotificationTemplate;
use App\Services\Audit\ActivityLogger;
use App\Support\Branding;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;

class Templates extends Component
{
    #[Url(except: '')]
    public string $audience = '';

    public ?int $editing = null;

    public string $subject = '';

    public string $body = '';

    // ---- new template ----------------------------------------------------------

    public bool $creating = false;

    public string $newName = '';

    public string $newKey = '';

    public string $newAudience = 'member';

    public string $newChannel = 'push';

    public string $newCategory = '';

    public string $newSubject = '';

    public string $newBody = '';

    public string $newPlaceholders = 'first_name';

    public bool $newActive = true;

    public function render(): View
    {
        return view('livewire.notifications.templates', [
            'templates' => NotificationTemplate::query()
                ->when($this->audience !== '', fn ($q) => $q->where('audience', $this->audience))
                ->orderBy('category')
                ->orderBy('name')
                ->get()
                ->groupBy('category'),
            'canEdit' => auth()->user()?->can('edit_notification_templates') ?? false,
            'categories' => NotificationTemplate::query()->distinct()->orderBy('category')->pluck('category')->filter()->values(),
        ])->layout('components.layouts.admin', [
            'title' => 'Notification templates',
            'breadcrumbs' => [
                ['label' => Branding::name(), 'href' => route('admin.dashboard')],
                ['label' => 'Notifications', 'href' => route('admin.notifications.campaigns')],
                ['label' => 'Templates'],
            ],
        ]);
    }

    public function edit(int $id): void
    {
        $this->authorize('edit_notification_templates');

        $template = NotificationTemplate::query()->findOrFail($id);

        $this->editing = $id;
        $this->subject = (string) $template->subject;
        $this->body = $template->body;
    }

    public function cancel(): void
    {
        $this->reset(['editing', 'subject', 'body']);
        $this->resetErrorBag();
    }

    public function save(): void
    {
        $this->authorize('edit_notification_templates');

        $this->validate([
            'subject' => ['nullable', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:2000'],
        ]);

        $template = NotificationTemplate::query()->findOrFail($this->editing);
        $before = ['subject' => $template->subject, 'body' => $template->body];

        $template->fill(['subject' => $this->subject ?: null, 'body' => $this->body]);

        /*
         * A placeholder that is not declared will render literally.
         *
         * "{{ user_nmae }}" reaching twelve thousand people is a mistake nobody
         * catches by eye, so it is refused here rather than caught afterwards.
         */
        $undeclared = $template->undeclaredPlaceholders();

        if ($undeclared !== []) {
            $this->addError('body', 'Unknown placeholder: '.implode(', ', array_map(
                fn (string $p): string => '{{ '.$p.' }}',
                $undeclared,
            )).'. Declared: '.implode(', ', $template->placeholders ?? []));

            return;
        }

        $template->updated_by = auth()->id();
        $template->save();

        app(ActivityLogger::class)->log(
            module: 'notifications',
            action: 'template_updated',
            subject: $template,
            description: "Updated the \"{$template->name}\" template",
            old: $before,
            new: ['subject' => $template->subject, 'body' => $template->body],
            // Editing an enforcement notice changes the statement of reasons a
            // member receives, which is a policy change rather than copywriting.
            sensitive: $template->is_transactional,
        );

        $this->cancel();

        session()->flash('status', 'Template saved.');
    }

    public function create(): void
    {
        $this->authorize('edit_notification_templates');
        $this->resetErrorBag();
        $this->reset('newName', 'newKey', 'newCategory', 'newSubject', 'newBody');
        $this->newAudience = 'member';
        $this->newChannel = 'push';
        $this->newPlaceholders = 'first_name';
        $this->newActive = true;
        $this->creating = true;
    }

    public function updatedNewName(string $value): void
    {
        $category = str($this->newCategory ?: 'custom')->slug('_');
        $this->newKey = $category.'.'.str($value)->slug('_')->limit(60, '');
    }

    public function closeCreate(): void
    {
        $this->creating = false;
    }

    public function store(): void
    {
        $this->authorize('edit_notification_templates');
        $this->newKey = strtolower(trim($this->newKey));

        $this->validate([
            'newName' => ['required', 'string', 'min:3', 'max:120'],
            'newKey' => ['required', 'max:120', 'regex:/^[a-z][a-z0-9_]*(\.[a-z0-9_]+)*$/', Rule::unique('notification_templates', 'key')],
            'newAudience' => ['required', Rule::in(['member', 'staff'])],
            'newChannel' => ['required', Rule::in(['push', 'email', 'in_app'])],
            'newCategory' => ['required', 'string', 'min:2', 'max:40'],
            'newSubject' => [Rule::requiredIf($this->newChannel === 'email'), 'nullable', 'string', 'max:200'],
            'newBody' => ['required', 'string', 'max:2000'],
            'newPlaceholders' => ['nullable', 'string', 'max:300', 'regex:/^[a-z0-9_,\s]*$/i'],
        ], [
            'newKey.regex' => 'Use lowercase letters, numbers, underscores and dots, such as promo.summer_sale.',
            'newKey.unique' => 'Another template already uses this key.',
            'newSubject.required' => 'Emails need a subject.',
            'newPlaceholders.regex' => 'List placeholder names separated by commas, such as first_name, city.',
        ], [
            'newName' => 'name', 'newKey' => 'key', 'newCategory' => 'category',
            'newSubject' => 'subject', 'newBody' => 'body', 'newPlaceholders' => 'placeholders',
        ]);

        $template = new NotificationTemplate([
            'key' => $this->newKey,
            'name' => trim($this->newName),
            'audience' => $this->newAudience,
            'channel' => $this->newChannel,
            'category' => str(trim($this->newCategory))->slug('_')->toString(),
            'subject' => filled($this->newSubject) ? trim($this->newSubject) : null,
            'body' => trim($this->newBody),
            'placeholders' => collect(explode(',', $this->newPlaceholders))->map(fn (string $p): string => strtolower(trim($p)))->filter()->unique()->values()->all(),
            // Only the built-in enforcement notices are transactional.
            'is_transactional' => false,
            'is_active' => $this->newActive,
        ]);

        if (($undeclared = $template->undeclaredPlaceholders()) !== []) {
            $this->addError('newBody', 'Unknown placeholder: '.implode(', ', array_map(fn (string $p): string => '{{ '.$p.' }}', $undeclared)).'. Add it to the placeholder list or remove it.');

            return;
        }

        $template->updated_by = auth()->id();
        $template->save();

        app(ActivityLogger::class)->log(
            module: 'notifications',
            action: 'template_created',
            subject: $template,
            description: "Created the \"{$template->name}\" template",
            new: $template->only(['key', 'audience', 'channel', 'subject', 'body']),
        );

        $this->creating = false;
        session()->flash('status', 'Template added.');
    }

    public function delete(int $id): void
    {
        $this->authorize('edit_notification_templates');
        $template = NotificationTemplate::query()->findOrFail($id);

        if ($template->isProtected()) {
            session()->flash('error', 'Enforcement notices carry the statement of reasons, so they cannot be deleted. Edit the wording instead.');

            return;
        }

        if (DB::table('push_campaigns')->where('notification_template_id', $template->id)->exists()) {
            session()->flash('error', "Campaigns were sent with \"{$template->name}\", so it is kept for their history. Mark it inactive instead.");

            return;
        }

        $template->delete();

        app(ActivityLogger::class)->log(
            module: 'notifications',
            action: 'template_deleted',
            description: "Deleted the \"{$template->name}\" template",
            old: $template->only(['key', 'audience', 'channel', 'subject', 'body']),
        );

        if ($this->editing === $id) {
            $this->cancel();
        }

        session()->flash('status', 'Template deleted.');
    }
}
