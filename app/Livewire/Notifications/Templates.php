<?php

declare(strict_types=1);

namespace App\Livewire\Notifications;

use App\Models\NotificationTemplate;
use App\Services\Audit\ActivityLogger;
use App\Support\Branding;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

class Templates extends Component
{
    #[Url(except: '')]
    public string $audience = '';

    public ?int $editing = null;

    public string $subject = '';

    public string $body = '';

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
}
