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

    /** The fields this provider needs, so the form is not a free-text blob. */
    public function credentialFields(): array
    {
        return match ($this->slug) {
            'stripe' => ['publishable_key', 'secret_key', 'webhook_secret'],
            'razorpay' => ['key_id', 'key_secret', 'webhook_secret'],
            'payu' => ['merchant_key', 'merchant_salt'],
            'paypal' => ['client_id', 'client_secret'],
            default => ['api_key', 'api_secret'],
        };
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
        return ! in_array(false, $this->credentialStatus(), true);
    }

    /**
     * A gateway live with test credentials takes real money nowhere.
     */
    public function hasConfigurationWarning(): bool
    {
        return $this->is_active && ($this->is_test_mode || ! $this->isConfigured());
    }
}
