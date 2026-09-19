<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Masters: the lists an operator maintains from the console instead of code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 40)->unique();
            $table->string('name', 60);
            $table->string('tagline', 160)->nullable();
            $table->decimal('monthly_price', 10, 2);
            $table->decimal('yearly_price', 10, 2)->nullable();
            // What the plan unlocks; the keys are defined in App\Models\Plan.
            $table->json('features')->nullable();
            // Extra marketing lines shown on the pricing cards.
            $table->json('perks')->nullable();
            $table->char('badge_color', 7)->nullable();
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::table('interests', function (Blueprint $table): void {
            $table->boolean('is_active')->default(true)->after('category');
            $table->unsignedSmallInteger('sort_order')->default(0)->after('is_active');
        });

        // Keyed by the ReportCategory / ReasonCode values: the keys stay in code
        // (routing depends on them), the wording and availability live here.
        Schema::create('report_category_settings', function (Blueprint $table): void {
            $table->string('key', 60)->primary();
            $table->string('label', 80);
            $table->string('description', 255)->nullable();
            $table->string('severity', 20);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('reason_code_settings', function (Blueprint $table): void {
            $table->string('key', 60)->primary();
            $table->string('label', 80);
            $table->text('statement');
            $table->string('policy_clause', 160);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('profile_options', function (Blueprint $table): void {
            $table->id();
            // education | relationship_goal | drinking | smoking | children | prompt
            $table->string('group', 30);
            $table->string('key', 80);
            $table->string('label', 160);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['group', 'key']);
        });

        // Plans are now data, so a member can be on any plan's slug.
        Schema::table('app_users', function (Blueprint $table): void {
            $table->string('premium_tier', 40)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_options');
        Schema::dropIfExists('reason_code_settings');
        Schema::dropIfExists('report_category_settings');
        Schema::dropIfExists('plans');

        Schema::table('interests', function (Blueprint $table): void {
            $table->dropColumn(['is_active', 'sort_order']);
        });
    }
};
