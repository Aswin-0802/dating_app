<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AccountStatus;
use App\Enums\Gender;
use App\Enums\RiskBand;
use App\Enums\VerificationStatus;
use App\Support\Masters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\HasApiTokens;

/**
 * A dating-app member.
 *
 * Authenticates on the `api` guard via Sanctum. Staff are App\Models\User.
 */
class AppUser extends Authenticatable
{
    use HasApiTokens;
    use HasFactory;
    use Notifiable;
    use SoftDeletes;

    protected $guarded = ['id'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'birthdate' => 'date',
            'gender' => Gender::class,
            'account_status' => AccountStatus::class,
            'verification_status' => VerificationStatus::class,
            'risk_band' => RiskBand::class,
            'password' => 'hashed',
            'is_premium' => 'boolean',
            'premium_until' => 'datetime',
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'shadow_banned_until' => 'datetime',
            'suspended_until' => 'datetime',
            'banned_at' => 'datetime',
            'risk_calculated_at' => 'datetime',
            'profile_completed_at' => 'datetime',
            'verified_at' => 'datetime',
            'first_swipe_at' => 'datetime',
            'first_match_at' => 'datetime',
            'first_message_at' => 'datetime',
            'first_reply_at' => 'datetime',
            'last_active_at' => 'datetime',
            'last_latitude' => 'float',
            'last_longitude' => 'float',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    // ---- relationships ----

    public function profile(): HasOne
    {
        return $this->hasOne(Profile::class);
    }

    public function preferences(): HasOne
    {
        return $this->hasOne(Preference::class);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(Photo::class)->orderBy('position');
    }

    public function primaryPhoto(): HasOne
    {
        return $this->hasOne(Photo::class)->where('is_primary', true);
    }

    public function interests(): BelongsToMany
    {
        return $this->belongsToMany(Interest::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    public function logins(): HasMany
    {
        return $this->hasMany(AppUserLogin::class);
    }

    public function referredBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'referred_by_id');
    }

    public function swipesGiven(): HasMany
    {
        return $this->hasMany(Swipe::class);
    }

    public function swipesReceived(): HasMany
    {
        return $this->hasMany(Swipe::class, 'target_app_user_id');
    }

    public function blocksMade(): HasMany
    {
        return $this->hasMany(Block::class);
    }

    public function blocksReceived(): HasMany
    {
        return $this->hasMany(Block::class, 'blocked_app_user_id');
    }

    public function verifications(): HasMany
    {
        return $this->hasMany(Verification::class)->latest();
    }

    public function latestVerification(): HasOne
    {
        return $this->hasOne(Verification::class)->latestOfMany();
    }

    public function reportsMade(): HasMany
    {
        return $this->hasMany(Report::class, 'reporter_app_user_id');
    }

    public function reportsAgainst(): HasMany
    {
        return $this->hasMany(Report::class, 'reported_app_user_id');
    }

    public function cases(): HasMany
    {
        return $this->hasMany(ReportCase::class, 'subject_app_user_id');
    }

    public function bans(): HasMany
    {
        return $this->hasMany(Ban::class)->latest();
    }

    public function activeBan(): BelongsTo
    {
        return $this->belongsTo(Ban::class, 'active_ban_id');
    }

    public function moderationActions(): HasMany
    {
        return $this->hasMany(ModerationAction::class, 'subject_app_user_id')->latest();
    }

    public function appeals(): HasMany
    {
        return $this->hasMany(Appeal::class);
    }

    public function riskScore(): HasOne
    {
        return $this->hasOne(RiskScore::class)->where('is_current', true);
    }

    /**
     * Matches on either side of the pair.
     *
     * `matches` stores the lower id in app_user_one_id, so a member appears in
     * either column and both have to be searched.
     */
    public function matches(): Builder
    {
        return MatchRecord::query()->where(function (Builder $query): void {
            $query->where('app_user_one_id', $this->id)
                ->orWhere('app_user_two_id', $this->id);
        });
    }

    // ---- scopes ----

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('account_status', AccountStatus::Active);
    }

    public function scopeVerified(Builder $query): Builder
    {
        return $query->where('verification_status', VerificationStatus::Approved);
    }

    public function scopeInBand(Builder $query, RiskBand|string $band): Builder
    {
        return $query->where('risk_band', $band instanceof RiskBand ? $band->value : $band);
    }

    public function scopeNeedsAttention(Builder $query): Builder
    {
        return $query->whereIn('risk_band', [RiskBand::High->value, RiskBand::Critical->value]);
    }

    /** Members whose accounts are restricted in any way. */
    public function scopeRestricted(Builder $query): Builder
    {
        return $query->whereIn('account_status', [
            AccountStatus::Limited->value,
            AccountStatus::ShadowBanned->value,
            AccountStatus::Suspended->value,
            AccountStatus::Banned->value,
        ]);
    }

    public function scopeAgeBetween(Builder $query, ?int $min, ?int $max): Builder
    {
        // Age is derived from birthdate, so the filter inverts into a date range
        // rather than computing an age per row.
        if ($min !== null) {
            $query->where('birthdate', '<=', Carbon::today()->subYears($min));
        }

        if ($max !== null) {
            $query->where('birthdate', '>', Carbon::today()->subYears($max + 1));
        }

        return $query;
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        $term = trim($term);

        /*
         * Searching by email or phone is a read of that field: type an address,
         * learn from the result whether that person is a member here. So it is
         * gated by the same permission that hides the column.
         *
         * The check lives in the scope rather than in its callers because
         * thirteen admin screens share it, and a caller that forgot would leak
         * silently — there is nothing on screen to notice.
         */
        $withPii = Auth::guard('web')->user()?->can('view_user_pii') ?? false;

        return $query->where(function (Builder $inner) use ($term, $withPii): void {
            $inner->where('display_name', 'like', "%{$term}%")
                ->orWhere('uuid', $term);

            if ($withPii) {
                $inner->orWhere('email', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%");
            }
        });
    }

    // ---- accessors ----

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function pushTokens(): HasMany
    {
        return $this->hasMany(PushToken::class);
    }

    /** The plan the member is currently on, or null for free (or lapsed) members. */
    public function activePlan(): ?Plan
    {
        if (! $this->is_premium || ($this->premium_until !== null && $this->premium_until->isPast())) {
            return null;
        }

        return Masters::plan($this->premium_tier);
    }

    /** Whether the member's plan includes a Plan::FEATURES key. */
    public function hasPremiumFeature(string $feature): bool
    {
        return $this->activePlan()?->hasFeature($feature) ?? false;
    }

    public function getAgeAttribute(): int
    {
        return (int) $this->birthdate->diffInYears(Carbon::today());
    }

    public function getIsShadowBannedAttribute(): bool
    {
        return $this->account_status === AccountStatus::ShadowBanned;
    }

    public function isRestricted(): bool
    {
        return $this->account_status->isRestricted();
    }

    /**
     * Feature limits currently in force, for the API's `restrictions` payload.
     *
     * Only feature_limit bans contribute: a shadow ban must stay invisible to
     * the member, so it never appears here.
     *
     * @return array<int, string>
     */
    public function restrictions(): array
    {
        if ($this->account_status !== AccountStatus::Limited) {
            return [];
        }

        return $this->activeBan?->limited_features ?? [];
    }
}
