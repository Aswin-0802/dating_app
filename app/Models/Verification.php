<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReasonCode;
use App\Enums\VerificationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class Verification extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => VerificationStatus::class,
            'rejection_reason_code' => ReasonCode::class,
            'face_match_score' => 'float',
            'liveness_score' => 'float',
            'liveness_passed' => 'boolean',
            'minor_suspected' => 'boolean',
            'claimed_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'submitted_at' => 'datetime',
            'sla_due_at' => 'datetime',
        ];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function appUser(): BelongsTo
    {
        return $this->belongsTo(AppUser::class);
    }

    public function signals(): HasMany
    {
        return $this->hasMany(VerificationSignal::class);
    }

    public function claimedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'claimed_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    // ---- scopes ----

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', ['pending', 'in_review', 'escalated']);
    }

    public function scopeInQueue(Builder $query, string $queue): Builder
    {
        return $query->where('queue', $queue);
    }

    public function scopeBreachingSla(Builder $query): Builder
    {
        return $query->open()->where('sla_due_at', '<', now());
    }

    // ---- behaviour ----

    public function isBreachingSla(): bool
    {
        return $this->status->isOpen() && $this->sla_due_at?->isPast();
    }

    /**
     * A signed, expiring URL for the selfie.
     *
     * Selfies live on a private disk because they are biometric submissions, so
     * they cannot be served as a plain storage URL.
     */
    public function selfieUrl(int $minutes = 10): ?string
    {
        if ($this->selfie_path === null) {
            return null;
        }

        return URL::temporarySignedRoute(
            'admin.verifications.selfie',
            now()->addMinutes($minutes),
            ['verification' => $this->uuid],
        );
    }

    public function selfieExists(): bool
    {
        return $this->selfie_path !== null
            && Storage::disk($this->selfie_disk)->exists($this->selfie_path);
    }

    /**
     * Other accounts showing this member's face.
     *
     * The hero signal on the review screen: one face on several accounts is the
     * clearest evidence of a stolen identity or a ban evader there is.
     */
    public function duplicateFaceAccounts()
    {
        $signature = $this->appUser?->primaryPhoto?->face_signature;

        if ($signature === null) {
            return AppUser::query()->whereRaw('1 = 0');
        }

        return AppUser::query()
            ->whereHas('photos', fn (Builder $q) => $q->where('face_signature', $signature))
            ->where('id', '!=', $this->app_user_id);
    }

    /** Approving a suspected minor must never be one click away. */
    public function canBeApproved(): bool
    {
        return ! $this->minor_suspected && $this->queue !== 'restricted_minor';
    }
}
