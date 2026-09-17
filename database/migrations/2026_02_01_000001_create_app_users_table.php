<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dating-app members.
 *
 * Separate from `users` (staff) on purpose: the two share almost no columns and
 * merging them would give staff a swipe history and make every policy ambiguous
 * about which kind of account it is looking at.
 *
 * Two groups of columns here are denormalised copies of data that lives
 * authoritatively elsewhere, and both are deliberate:
 *
 *  - the enforcement mirror (shadow_banned_until, suspended_until, banned_at)
 *    duplicates `bans`, so the API's hot path can answer "may this account act?"
 *    without a join. BanObserver writes both inside one transaction, and
 *    `veyra:reconcile-enforcement` re-checks them.
 *
 *  - the funnel milestones ARE the funnel chart. Deriving them from swipes,
 *    matches and messages at read time would make the dashboard unusable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_users', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            $table->string('display_name');
            $table->string('email')->unique();
            $table->string('phone')->nullable()->unique();
            $table->string('password');
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('phone_verified_at')->nullable();

            $table->date('birthdate');
            $table->enum('gender', ['woman', 'man', 'non_binary', 'other']);
            $table->string('pronouns')->nullable();

            $table->foreignId('city_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('country_id')->nullable()->constrained()->nullOnDelete();
            // Coarse last-known position for distance ranking. Never exposed by
            // the API — AppUserResource buckets distance to the nearest km.
            $table->decimal('last_latitude', 10, 7)->nullable();
            $table->decimal('last_longitude', 10, 7)->nullable();

            $table->enum('account_status', [
                'active', 'pending', 'limited', 'shadow_banned', 'suspended', 'banned', 'deactivated',
            ])->default('pending');

            $table->enum('verification_status', [
                'unverified', 'pending', 'in_review', 'approved', 'rejected', 'escalated', 'expired',
            ])->default('unverified');

            $table->boolean('is_premium')->default(false);
            $table->enum('premium_tier', ['plus', 'gold'])->nullable();
            $table->timestamp('premium_until')->nullable();

            $table->enum('signup_source', ['ios', 'android', 'web'])->default('ios');
            $table->foreignId('referred_by_id')->nullable()->constrained('app_users')->nullOnDelete();
            $table->unsignedTinyInteger('profile_completion')->default(0);

            // ---- enforcement mirror ----
            $table->timestamp('shadow_banned_until')->nullable();
            $table->timestamp('suspended_until')->nullable();
            $table->timestamp('banned_at')->nullable();
            $table->unsignedBigInteger('active_ban_id')->nullable();

            // ---- risk mirror ----
            $table->unsignedTinyInteger('risk_score')->default(0);
            $table->enum('risk_band', ['low', 'elevated', 'high', 'critical'])->default('low');
            $table->timestamp('risk_calculated_at')->nullable();

            // ---- funnel milestones ----
            $table->timestamp('profile_completed_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('first_swipe_at')->nullable();
            $table->timestamp('first_match_at')->nullable();
            $table->timestamp('first_message_at')->nullable();
            $table->timestamp('first_reply_at')->nullable();

            $table->timestamp('last_active_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();

            $table->index('account_status');
            $table->index('verification_status');
            $table->index(['risk_band', 'risk_score']);
            $table->index('city_id');
            $table->index('last_active_at');
            $table->index('created_at');
            $table->index('gender');
            // Covers the default users list: active accounts, newest first.
            $table->index(['account_status', 'created_at']);
        });

        Schema::create('profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('app_user_id')->unique()->constrained()->cascadeOnDelete();

            $table->text('bio')->nullable();
            $table->unsignedSmallInteger('height_cm')->nullable();
            $table->string('job_title')->nullable();
            $table->string('company')->nullable();
            $table->string('school')->nullable();
            $table->string('education')->nullable();

            $table->enum('relationship_goal', [
                'long_term', 'short_term', 'friends', 'figuring_out', 'unspecified',
            ])->default('unspecified');
            $table->enum('drinking', ['never', 'socially', 'often', 'unspecified'])->default('unspecified');
            $table->enum('smoking', ['never', 'socially', 'often', 'unspecified'])->default('unspecified');
            $table->enum('children', ['have', 'want', 'dont_want', 'unspecified'])->default('unspecified');

            $table->string('religion')->nullable();
            $table->string('politics')->nullable();
            $table->json('languages')->nullable();
            $table->json('prompts')->nullable();

            // Set by the risk engine's text scan; drives the bio_contains_contact factor.
            $table->boolean('bio_contains_contact')->default(false);

            $table->timestamps();
        });

        Schema::create('preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('app_user_id')->unique()->constrained()->cascadeOnDelete();

            $table->json('interested_in');
            $table->unsignedTinyInteger('age_min')->default(18);
            $table->unsignedTinyInteger('age_max')->default(45);
            $table->unsignedSmallInteger('max_distance_km')->default(80);
            $table->boolean('global_mode')->default(false);
            $table->boolean('show_verified_only')->default(false);
            $table->json('deal_breakers')->nullable();

            $table->timestamps();
        });

        Schema::create('interests', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('category');
            $table->unsignedInteger('usage_count')->default(0);
        });

        Schema::create('app_user_interest', function (Blueprint $table): void {
            $table->foreignId('app_user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('interest_id')->constrained()->cascadeOnDelete();

            $table->primary(['app_user_id', 'interest_id']);
            $table->index('interest_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_user_interest');
        Schema::dropIfExists('interests');
        Schema::dropIfExists('preferences');
        Schema::dropIfExists('profiles');
        Schema::dropIfExists('app_users');
    }
};
