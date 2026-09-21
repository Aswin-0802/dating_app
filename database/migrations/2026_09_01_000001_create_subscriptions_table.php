<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A member's history on paid plans.
 *
 * `app_users.is_premium / premium_tier / premium_until` stay as the hot-path
 * mirror the API and the deck read on every request; this table is the record
 * of how they got there — who granted it, what was paid, when it ended and
 * which reminders have already gone out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('app_user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();

            // Kept alongside plan_id so history still reads correctly if the
            // plan is renamed or deleted later.
            $table->string('plan_slug', 40);
            $table->string('plan_name', 40);

            $table->enum('status', ['active', 'expired', 'cancelled'])->default('active');

            // manual  — staff granted it (bank transfer, support gesture)
            // payment — bought through checkout
            $table->enum('source', ['manual', 'payment'])->default('manual');
            $table->enum('billing_period', ['monthly', 'yearly', 'custom'])->default('custom');

            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable(); // null = open-ended
            $table->timestamp('ended_at')->nullable(); // when it actually stopped

            $table->decimal('amount', 10, 2)->nullable();
            $table->string('currency', 3)->nullable();

            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note', 500)->nullable();

            // Which renewal reminders have already been sent, e.g.
            // ["push_7", "email_3", "email_0"] — so a reminder is never
            // repeated when the task runs every few minutes.
            $table->json('reminders_sent')->nullable();

            $table->timestamps();

            $table->index(['app_user_id', 'status']);
            $table->index(['status', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
