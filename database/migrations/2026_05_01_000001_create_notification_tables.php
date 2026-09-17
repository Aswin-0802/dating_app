<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Member-facing notifications: templates, campaigns and delivery logs.
 *
 * Distinct from the `notifications` table, which is Laravel's database channel
 * and carries staff alerts for the console bell.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');

            $table->enum('audience', ['member', 'staff'])->default('member');
            $table->enum('channel', ['push', 'email', 'in_app'])->default('push');
            $table->string('category', 40)->default('general');

            $table->string('subject')->nullable();
            $table->text('body');

            // Placeholders the body may use, so the editor can validate rather
            // than letting somebody ship {{ user_nmae }} to 12,000 people.
            $table->json('placeholders')->nullable();

            /*
             * Enforcement notices are the statement of reasons required by DSA
             * Article 17. They are marked so the UI can refuse to let anybody
             * delete one, and so that editing one is treated as a policy change
             * rather than copywriting.
             */
            $table->boolean('is_transactional')->default(false);
            $table->boolean('is_active')->default(true);

            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['audience', 'channel']);
        });

        Schema::create('push_campaigns', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->string('name');

            $table->foreignId('notification_template_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->text('body');
            $table->string('deep_link')->nullable();

            // A saved audience definition, so a campaign can be re-run against
            // the same rule rather than a frozen list of ids.
            $table->json('audience_filters')->nullable();
            $table->string('audience_label')->nullable();
            $table->unsignedInteger('estimated_recipients')->default(0);

            $table->enum('status', ['draft', 'scheduled', 'sending', 'sent', 'cancelled'])
                ->default('draft');

            $table->timestamp('scheduled_for')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('delivered_count')->default(0);
            $table->unsignedInteger('opened_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            // Sending to the whole member base is not a one-person decision.
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            $table->timestamps();

            $table->index('status');
            $table->index('scheduled_for');
        });

        Schema::create('push_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('push_campaign_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('app_user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->nullable()->constrained()->nullOnDelete();

            $table->string('title');
            $table->enum('status', ['queued', 'sent', 'delivered', 'opened', 'failed'])->default('queued');
            $table->string('failure_reason')->nullable();

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['push_campaign_id', 'status']);
            $table->index(['app_user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_logs');
        Schema::dropIfExists('push_campaigns');
        Schema::dropIfExists('notification_templates');
    }
};
