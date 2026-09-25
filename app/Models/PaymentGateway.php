<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentGateway extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            // Encrypted at rest. A leaked database dump should not be a leaked
            // set of live payment keys.
            'credentials' => 'encrypted:array',
            'is_active' => 'boolean',
            'is_test_mode' => 'boolean',
        ];
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Gateways that take money on the website, as opposed to the app stores. */
    public function scopeCheckout(Builder $query): Builder
    {
        return $query->where('kind', 'checkout');
    }

    /** An app store: it verifies purchases the store already charged, and is never a way to pay on the web. */
    public function isStore(): bool
    {
        return $this->kind === 'store';
    }

    /** The fields this provider needs, so the form is not a free-text blob. */
    public function credentialFields(): array
    {
        return match ($this->slug) {
            'stripe' => ['publishable_key', 'secret_key', 'webhook_secret'],
            'razorpay' => ['key_id', 'key_secret', 'webhook_secret'],
            'payu' => ['merchant_key', 'merchant_salt'],
            'paypal' => ['client_id', 'client_secret'],
            // App Store Server API key (issuer id, key id, the .p8) plus what
            // identifies the app in a signed transaction.
            'apple' => ['issuer_id', 'key_id', 'private_key', 'bundle_id', 'app_apple_id'],
            // A service account with access to the app in Play Console, and
            // optionally the account Pub/Sub pushes with, to pin notifications.
            'google' => ['package_name', 'service_account_json', 'pubsub_service_account'],
            default => ['api_key', 'api_secret'],
        };
    }

    /**
     * Credentials a gateway works without. A web gateway is usable for
     * testing before its webhook secret exists; Google Play works without the
     * Pub/Sub account pin (the token's issuer and audience are still checked).
     *
     * @return array<int, string>
     */
    public function optionalCredentialFields(): array
    {
        return ['webhook_secret', 'pubsub_service_account'];
    }

    /**
     * Credentials that are whole files — a .p8 key, a service-account JSON —
     * and need a textarea rather than a single line.
     *
     * @return array<int, string>
     */
    public function multilineCredentialFields(): array
    {
        return ['private_key', 'service_account_json'];
    }

    /**
     * Which credentials are set — never their values.
     *
     * A secret is write-only from the console: the form shows whether one
     * exists and lets you replace it. Rendering it back would put live keys in
     * page source, browser history and any screenshot somebody takes.
     *
     * @return array<string, bool>
     */
    public function credentialStatus(): array
    {
        $stored = $this->credentials ?? [];

        return collect($this->credentialFields())
            ->mapWithKeys(fn (string $field): array => [$field => filled($stored[$field] ?? null)])
            ->all();
    }

    public function isConfigured(): bool
    {
        foreach ($this->credentialStatus() as $field => $set) {
            if (! $set && ! in_array($field, $this->optionalCredentialFields(), true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * A gateway live with test credentials takes real money nowhere.
     */
    public function hasConfigurationWarning(): bool
    {
        // A store's key serves sandbox and production alike, so test mode is
        // not a warning there: the transaction's own environment decides.
        return $this->is_active && ((! $this->isStore() && $this->is_test_mode) || ! $this->isConfigured());
    }
}
