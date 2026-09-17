<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReportCategory;
use App\Enums\Severity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Report extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'category' => ReportCategory::class,
            'severity' => Severity::class,
            'created_at' => 'datetime',
        ];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(AppUser::class, 'reporter_app_user_id');
    }

    public function reported(): BelongsTo
    {
        return $this->belongsTo(AppUser::class, 'reported_app_user_id');
    }

    public function reportCase(): BelongsTo
    {
        return $this->belongsTo(ReportCase::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function photo(): BelongsTo
    {
        return $this->belongsTo(Photo::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function scopeUnassigned(Builder $query): Builder
    {
        return $query->whereNull('report_case_id');
    }

    /** Reports from members who report accurately carry more weight. */
    public function isFromCredibleReporter(): bool
    {
        return $this->reporter_credibility_at_time >= 60;
    }
}
