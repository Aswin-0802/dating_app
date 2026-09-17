<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_public' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Settings are read on nearly every request, so the lookup is cached and
        // busted on write rather than queried each time.
        static::saved(fn () => Cache::forget('veyra.settings'));
        static::deleted(fn () => Cache::forget('veyra.settings'));
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * The typed value. The column is text, so booleans and JSON have to be cast
     * back on the way out.
     */
    public function getTypedValueAttribute(): mixed
    {
        return match ($this->type) {
            'boolean' => filter_var($this->value, FILTER_VALIDATE_BOOLEAN),
            'number' => is_numeric($this->value) ? $this->value + 0 : 0,
            'json' => json_decode((string) $this->value, true) ?? [],
            default => $this->value,
        };
    }

    /** @return array<string, mixed> */
    public static function allValues(): array
    {
        return Cache::rememberForever(
            'veyra.settings',
            fn (): array => static::query()
                ->get()
                ->mapWithKeys(fn (self $setting): array => [$setting->key => $setting->typed_value])
                ->all(),
        );
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return static::allValues()[$key] ?? $default;
    }

    public static function put(string $key, mixed $value, ?int $userId = null): void
    {
        $setting = static::query()->where('key', $key)->first();

        if ($setting === null) {
            return;
        }

        $setting->update([
            'value' => is_array($value) ? json_encode($value) : (is_bool($value) ? ($value ? '1' : '0') : $value),
            'updated_by' => $userId,
        ]);
    }
}
