<?php

declare(strict_types=1);

namespace App\Livewire\System;

use App\Models\PaymentGateway;
use App\Models\SmsGateway;
use App\Services\Audit\ActivityLogger;
use App\Support\Branding;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Payment and SMS provider configuration.
 *
 * Credentials are write-only from here. The form reports whether a secret is
 * set and lets you replace it, but never renders a stored value back — a live
 * API key in page source ends up in browser history, screenshots and anything
 * that scrapes the DOM.
 */
class Gateways extends Component
{
    /** 'payment' or 'sms'. */
    public string $kind = 'payment';

    public ?int $editing = null;

    /** @var array<string, string> */
    public array $credentials = [];

    public bool $isActive = false;

    public bool $isTestMode = true;

    public string $senderId = '';

    public function mount(string $kind = 'payment'): void
    {
        $this->kind = in_array($kind, ['payment', 'sms'], true) ? $kind : 'payment';
    }

    public function render(): View
    {
        return view('livewire.system.gateways', [
            'gateways' => $this->kind === 'payment'
                ? PaymentGateway::query()->orderBy('sort_order')->get()
                : SmsGateway::query()->orderBy('sort_order')->get(),
            'canEdit' => auth()->user()?->can('edit_api_settings') ?? false,
        ])->layout('components.layouts.admin', [
            'title' => $this->kind === 'payment' ? 'Payment gateways' : 'SMS gateways',
            'breadcrumbs' => [
                ['label' => Branding::name(), 'href' => route('admin.dashboard')],
                ['label' => 'System'],
                ['label' => $this->kind === 'payment' ? 'Payments' : 'SMS'],
            ],
        ]);
    }

    public function edit(int $id): void
    {
        $this->authorize('edit_api_settings');

        $gateway = $this->find($id);

        $this->editing = $id;
        $this->isActive = (bool) $gateway->is_active;
        $this->isTestMode = (bool) ($gateway->is_test_mode ?? true);
        $this->senderId = (string) ($gateway->sender_id ?? '');

        // Blank, always. The form never receives the stored secret.
        $this->credentials = collect($gateway->credentialFields())
            ->mapWithKeys(fn (string $field): array => [$field => ''])
            ->all();
    }

    public function cancel(): void
    {
        $this->reset(['editing', 'credentials', 'isActive', 'isTestMode', 'senderId']);
        $this->resetErrorBag();
    }

    public function save(): void
    {
        $this->authorize('edit_api_settings');

        $gateway = $this->find($this->editing);

        /*
         * Blank fields keep whatever is stored.
         *
         * Otherwise saving after only toggling "active" would silently wipe
         * every credential, and the failure would not surface until the next
         * real payment attempt.
         */
        $stored = $gateway->credentials ?? [];

        foreach ($this->credentials as $field => $value) {
            if (filled($value)) {
                $stored[$field] = trim($value);
            }
        }

        $attributes = [
            'credentials' => $stored,
            'is_active' => $this->isActive,
            'updated_by' => auth()->id(),
        ];

        if ($gateway instanceof PaymentGateway) {
            $attributes['is_test_mode'] = $this->isTestMode;
        } else {
            $attributes['sender_id'] = $this->senderId ?: null;
        }

        $wasActive = $gateway->is_active;
        $gateway->update($attributes);

        // The credential VALUES are never logged — only which ones changed, and
        // whether the provider was switched on.
        app(ActivityLogger::class)->log(
            module: 'system',
            action: 'gateway_updated',
            subject: $gateway,
            description: "Updated the {$gateway->name} gateway",
            old: ['is_active' => $wasActive],
            new: [
                'is_active' => $this->isActive,
                'credentials_changed' => array_keys(array_filter($this->credentials, 'filled')),
            ],
            sensitive: true,
        );

        $this->cancel();

        session()->flash('status', "{$gateway->name} saved.");
    }

    public function toggleActive(int $id): void
    {
        $this->authorize('edit_api_settings');

        $gateway = $this->find($id);

        if (! $gateway->is_active && ! $gateway->isConfigured()) {
            session()->flash('error', "{$gateway->name} is missing credentials and cannot be switched on.");

            return;
        }

        $gateway->update(['is_active' => ! $gateway->is_active, 'updated_by' => auth()->id()]);

        app(ActivityLogger::class)->log(
            module: 'system',
            action: $gateway->is_active ? 'gateway_enabled' : 'gateway_disabled',
            subject: $gateway,
            description: "{$gateway->name} ".($gateway->is_active ? 'enabled' : 'disabled'),
            sensitive: true,
        );

        session()->flash('status', "{$gateway->name} ".($gateway->is_active ? 'enabled' : 'disabled').'.');
    }

    private function find(?int $id): PaymentGateway|SmsGateway
    {
        return $this->kind === 'payment'
            ? PaymentGateway::query()->findOrFail($id)
            : SmsGateway::query()->findOrFail($id);
    }
}
