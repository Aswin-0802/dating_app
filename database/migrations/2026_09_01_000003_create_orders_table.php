<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One attempt to pay for something.
 *
 * Deliberately not called "plan purchase": `purpose` and `reference` keep it
 * usable for anything else that will be charged for later (a boost, a
 * one-off unlock) without a second payment pipeline being bolted on beside
 * this one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('app_user_id')->constrained()->cascadeOnDelete();

            $table->string('purpose', 30)->default('plan');   // plan | other
            $table->string('reference', 60)->nullable();      // plan slug, etc.
            $table->string('description', 160);

            // Held in the smallest unit (paise, cents) exactly as the gateways
            // want it, so nothing is ever re-rounded on the way out.
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3);
            $table->enum('billing_period', ['monthly', 'yearly'])->default('monthly');

            $table->string('gateway', 20);                    // stripe | razorpay
            $table->string('gateway_ref', 120)->nullable();    // cs_… | plink_…
            $table->string('payment_ref', 120)->nullable();    // pay_… | pi_…

            $table->enum('status', ['pending', 'paid', 'failed', 'cancelled', 'expired'])->default('pending');
            $table->string('failure_reason', 255)->nullable();

            // Everything the gateway last told us, for reconciliation when a
            // member and the dashboard disagree.
            $table->json('gateway_payload')->nullable();

            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['app_user_id', 'status']);
            $table->index(['gateway', 'gateway_ref']);
            $table->index('created_at');
        });

        // Webhooks arrive more than once, out of order, and sometimes while the
        // browser is still on the return page. Every event seen is recorded so
        // an order is only ever fulfilled once.
        Schema::create('gateway_events', function (Blueprint $table): void {
            $table->id();
            $table->string('gateway', 20);
            $table->string('event_id', 160);
            $table->string('event_type', 80);
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['gateway', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gateway_events');
        Schema::dropIfExists('orders');
    }
};
