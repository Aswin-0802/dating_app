<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationTemplate extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'placeholders' => 'array',
            'is_transactional' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeForMembers(Builder $query): Builder
    {
        return $query->where('audience', 'member');
    }

    /**
     * Enforcement notices cannot be deleted.
     *
     * They carry the statement of reasons a member receives when action is taken
     * against them, which is a legal obligation rather than marketing copy.
     */
    public function isProtected(): bool
    {
        return $this->is_transactional;
    }

    /**
     * Placeholders used in the body that are not declared.
     *
     * Surfaced in the editor, because {{ user_nmae }} reaching 12,000 people is
     * a mistake nobody catches by eye.
     *
     * @return array<int, string>
     */
    public function undeclaredPlaceholders(): array
    {
        preg_match_all('/\{\{\s*([a-z0-9_]+)\s*\}\}/i', $this->body.' '.$this->subject, $matches);

        return array_values(array_diff(
            array_unique($matches[1] ?? []),
            $this->placeholders ?? [],
        ));
    }

    public function render(array $values): string
    {
        $body = $this->body;

        foreach ($values as $key => $value) {
            $body = preg_replace('/\{\{\s*'.preg_quote((string) $key, '/').'\s*\}\}/', (string) $value, $body);
        }

        return $body;
    }
}
