<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform integrations and their delivery logs.
 *
 * Credentials live in the database rather than .env so an operator can rotate a
 * key or switch a provider from the console. They are stored encrypted, and the
 * UI never renders a stored secret back — it shows whether one is set and lets
 * you replace it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_gateways', function (Blueprint $table): void {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('logo')->nullable();

            // Encrypted at rest via the model cast.
            $table->text('credentials')->nullable();

            $table->boolean('is_active')->default(false);
            $table->boolean('is_test_mode')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('supported_currencies')->nullable();

            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('sms_gateways', function (Blueprint $table): void {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');

            $table->text('credentials')->nullable();
            $table->string('sender_id')->nullable();

            $table->boolean('is_active')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('email_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('to');
            $table->string('subject');
            $table->string('mailable')->nullable();
            $table->string('template_key')->nullable();

            $table->enum('status', ['queued', 'sent', 'delivered', 'bounced', 'failed'])->default('queued');
            $table->string('failure_reason')->nullable();

            $table->nullableMorphs('recipient');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('status');
            $table->index('created_at');
        });

        Schema::create('sms_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('to', 32);
            $table->text('body');
            $table->string('gateway', 40)->nullable();

            $table->enum('status', ['queued', 'sent', 'delivered', 'failed'])->default('queued');
            $table->string('failure_reason')->nullable();
            // Providers bill per segment, so the count is worth keeping.
            $table->unsignedTinyInteger('segments')->default(1);
            $table->decimal('cost', 8, 4)->nullable();

            $table->nullableMorphs('recipient');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('status');
            $table->index('created_at');
        });

        Schema::create('payment_logs', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('app_user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('gateway', 40);
            $table->string('gateway_reference')->nullable();
            $table->string('product', 60)->nullable();

            $table->decimal('amount', 10, 2);
            $table->char('currency', 3)->default('GBP');

            $table->enum('status', ['pending', 'succeeded', 'failed', 'refunded', 'disputed'])
                ->default('pending');
            $table->string('failure_reason')->nullable();

            // Disputes are a fraud signal as much as a finance one, which is why
            // they are a status here rather than a separate ledger.
            $table->timestamp('disputed_at')->nullable();
            $table->timestamp('refunded_at')->nullable();

            $table->json('response')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('app_user_id');
            $table->index('gateway');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_logs');
        Schema::dropIfExists('sms_logs');
        Schema::dropIfExists('email_logs');
        Schema::dropIfExists('sms_gateways');
        Schema::dropIfExists('payment_gateways');
    }
};
