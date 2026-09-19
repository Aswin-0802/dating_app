<?php

declare(strict_types=1);

namespace App\Livewire\System;

use App\Models\Setting;
use App\Services\Audit\ActivityLogger;
use App\Support\Branding;
use App\Support\MailSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Mail as Mailer;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Outgoing mail configuration.
 *
 * Stored in settings rather than .env so it can be changed without a deploy,
 * and applied to every outgoing message by App\Support\MailSettings.
 */
class Mail extends Component
{
    /** @var array<string, mixed> */
    public array $values = [];

    public string $testRecipient = '';

    public function mount(): void
    {
        $this->loadValues();
        $this->testRecipient = (string) auth()->user()?->email;
    }

    public function render(): View
    {
        return view('livewire.system.mail', [
            'settings' => $this->settings(),
            'canEdit' => auth()->user()?->can('edit_api_settings') ?? false,
        ])->layout('components.layouts.admin', [
            'title' => 'Mail settings',
            'breadcrumbs' => [
                ['label' => Branding::name(), 'href' => route('admin.dashboard')],
                ['label' => 'System'],
                ['label' => 'Mail'],
            ],
        ]);
    }

    public function save(): void
    {
        $this->authorize('edit_api_settings');

        $this->validate([
            'values.mail.mailer' => ['required', Rule::in(array_keys(MailSettings::MAILERS))],
            'values.mail.host' => ['required', 'string', 'max:255'],
            'values.mail.port' => ['required', 'integer', 'min:1', 'max:65535'],
            'values.mail.username' => ['nullable', 'string', 'max:255'],
            'values.mail.password' => ['nullable', 'string', 'max:255'],
            'values.mail.encryption' => ['nullable', Rule::in(array_keys(MailSettings::ENCRYPTION))],
            'values.mail.from_address' => ['required', 'email', 'max:255'],
            'values.mail.from_name' => ['required', 'string', 'max:100'],
        ], [], [
            'values.mail.mailer' => 'delivery',
            'values.mail.host' => 'SMTP host',
            'values.mail.port' => 'SMTP port',
            'values.mail.encryption' => 'encryption',
            'values.mail.from_address' => 'from address',
            'values.mail.from_name' => 'from name',
        ]);

        $changed = [];

        foreach ($this->settings() as $setting) {
            $new = data_get($this->values, $setting->key);

            if ($setting->type === 'boolean') {
                $new = $new ? '1' : '0';
            }

            /*
             * A blank password keeps what is stored.
             *
             * Otherwise saving after changing only the port would silently wipe
             * the SMTP credentials, and nobody would notice until mail stopped
             * going out.
             */
            if ($setting->key === 'mail.password' && blank($new)) {
                continue;
            }

            // Stored encrypted, as the form promises; decrypted only when
            // the mailer is configured.
            if ($setting->key === 'mail.password') {
                $new = MailSettings::encrypt((string) $new);
            }

            if ((string) $new === (string) $setting->value) {
                continue;
            }

            $changed[] = $setting->key;
            $setting->update(['value' => $new, 'updated_by' => auth()->id()]);
        }

        if ($changed !== []) {
            app(ActivityLogger::class)->log(
                module: 'system',
                action: 'mail_settings_updated',
                description: count($changed).' mail settings changed',
                // The password value is never logged; only that it changed.
                new: ['changed' => $changed],
                sensitive: true,
            );
        }

        $this->loadValues();

        session()->flash('status', $changed === [] ? 'No changes to save.' : 'Mail settings saved.');
    }

    /**
     * Send a test message.
     *
     * The only way to know SMTP settings are right is to use them; a form that
     * saves without ever proving the connection works is how a broken mailer
     * survives until the first password reset fails.
     */
    public function sendTest(): void
    {
        $this->authorize('edit_api_settings');

        $this->validate(['testRecipient' => ['required', 'email']]);

        MailSettings::apply();
        $name = Branding::name();

        try {
            Mailer::raw(
                "This is a test message from the {$name} console.\n\nIf you are reading it, outgoing mail is configured correctly.",
                fn ($message) => $message->to($this->testRecipient)->subject("{$name} test message"),
            );
        } catch (\Throwable $e) {
            $this->addError('testRecipient', 'The message could not be sent. Check the host, port, username and password. ('.str($e->getMessage())->limit(160).')');

            return;
        }

        if (config('mail.default') === 'log') {
            session()->flash('status', 'Delivery is set to "Do not send", so the test message was written to the log instead of being emailed.');

            return;
        }

        app(ActivityLogger::class)->log(
            module: 'system',
            action: 'mail_test_sent',
            description: "Sent a test message to {$this->testRecipient}",
        );

        session()->flash('status', "Test message sent to {$this->testRecipient}.");
    }

    private function loadValues(): void
    {
        $this->values = [];

        foreach ($this->settings() as $setting) {
            // The stored password is never sent to the browser.
            data_set(
                $this->values,
                $setting->key,
                $setting->key === 'mail.password' ? '' : $setting->typed_value,
            );
        }
    }

    private function settings()
    {
        return Setting::query()->where('group', 'mail')->orderBy('sort_order')->get();
    }
}
