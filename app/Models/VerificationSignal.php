<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VerificationSignal extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['passed' => 'boolean'];
    }

    public function verification(): BelongsTo
    {
        return $this->belongsTo(Verification::class);
    }

    /** Complete class strings, never assembled at runtime. */
    public function chipClasses(): string
    {
        if ($this->passed) {
            return 'bg-success-subtle text-success-subtle-foreground';
        }

        return match ($this->severity) {
            'critical' => 'bg-destructive text-white',
            'warning' => 'bg-warning-subtle text-warning-subtle-foreground',
            default => 'bg-muted text-muted-foreground',
        };
    }

    public function icon(): string
    {
        return $this->passed ? 'check-circle' : match ($this->severity) {
            'critical' => 'x-circle',
            'warning' => 'warning',
            default => 'info',
        };
    }
}
