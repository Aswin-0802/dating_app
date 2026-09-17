<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmsGateway extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'is_active' => 'boolean',
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

    public function credentialFields(): array
    {
        return match ($this->slug) {
            'twilio' => ['account_sid', 'auth_token', 'from_number'],
            'msg91' => ['auth_key', 'template_id'],
            'vonage' => ['api_key', 'api_secret'],
            'textlocal' => ['api_key'],
            default => ['api_key', 'api_secret'],
        };
    }

    /** @return array<string, bool> */
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
     * A gateway switched on without credentials silently drops every message.
     *
     * Shared with PaymentGateway because the gateway view renders both.
     */
    public function hasConfigurationWarning(): bool
    {
        return $this->is_active && ! $this->isConfigured();
    }
}
