<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ---- verification ----

        Schema::create('verifications', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('app_user_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('attempt_no')->default(1);

            $table->enum('type', ['selfie', 'id_document', 'phone'])->default('selfie');
            $table->enum('status', ['pending', 'in_review', 'approved', 'rejected', 'escalated', 'expired'])
                ->default('pending');

            /*
             * Minor-safety submissions route to `restricted_minor`, which is
             * invisible — not merely disabled — without the dedicated
             * permission. A restricted queue a moderator can see but not open
             * is an invitation to ask colleagues what is in it.
             */
            $table->enum('queue', ['standard', 'restricted_minor', 'appeal'])->default('standard');

            $table->string('selfie_disk')->default('verifications');
            $table->string('selfie_path')->nullable();
            $table->string('gesture_code', 8)->nullable();

            // Populated by FaceMatchService. Currently simulated; the interface
            // exists so a real provider can be dropped in without schema change.
            $table->decimal('face_match_score', 4, 3)->nullable();
            $table->boolean('liveness_passed')->default(false);
            $table->decimal('liveness_score', 4, 3)->nullable();

            // The single highest-value signal on the review screen.
            $table->unsignedSmallInteger('duplicate_face_account_count')->default(0);
            $table->unsignedSmallInteger('device_reuse_count')->default(0);

            $table->unsignedTinyInteger('estimated_age_min')->nullable();
            $table->unsignedTinyInteger('estimated_age_max')->nullable();
            $table->boolean('minor_suspected')->default(false);

            $table->foreignId('claimed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('claimed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();

            $table->string('rejection_reason_code')->nullable();
            $table->text('internal_note')->nullable();

            $table->dateTime('submitted_at');
            $table->dateTime('sla_due_at');
            $table->timestamps();

            $table->index(['status', 'queue']);
            $table->index('sla_due_at');
            $table->index('app_user_id');
            $table->index('minor_suspected');
        });

        Schema::create('verification_signals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('verification_id')->constrained()->cascadeOnDelete();

            $table->string('key', 64);
            $table->string('label');
            $table->string('value')->nullable();
            $table->enum('severity', ['info', 'warning', 'critical'])->default('info');
            $table->boolean('passed')->default(true);

            $table->index('verification_id');
        });

        // ---- reports & cases ----

        Schema::create('report_cases', function (Blueprint $table): void {
            $table->id();
            $table->string('case_number', 20)->unique();
            $table->foreignId('subject_app_user_id')->constrained('app_users')->cascadeOnDelete();

            $table->enum('status', ['new', 'claimed', 'in_review', 'actioned', 'appealed', 'closed'])
                ->default('new');
            $table->enum('severity', ['low', 'medium', 'high', 'critical'])->default('low');

            // Denormalised counters so the queue can sort by "most reported"
            // without aggregating over reports on every page load.
            $table->unsignedInteger('reports_count')->default(0);
            $table->unsignedInteger('distinct_reporters_count')->default(0);
            $table->unsignedTinyInteger('risk_score_at_open')->default(0);

            $table->foreignId('claimed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('claimed_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->string('outcome')->nullable();

            $table->dateTime('sla_due_at');
            $table->timestamps();

            $table->index(['status', 'severity']);
            $table->index('sla_due_at');
            $table->index('subject_app_user_id');
            $table->index('reports_count');
        });

        Schema::create('reports', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            $table->foreignId('reporter_app_user_id')->nullable()->constrained('app_users')->nullOnDelete();
            $table->foreignId('reported_app_user_id')->constrained('app_users')->cascadeOnDelete();
            $table->foreignId('report_case_id')->nullable()->constrained()->nullOnDelete();

            $table->string('category', 40);
            $table->enum('severity', ['low', 'medium', 'high', 'critical'])->default('low');
            $table->enum('source', ['user', 'automation', 'partner'])->default('user');

            // Evidence anchors. The case detail shows the anchored message with
            // context either side — moderators leave the queue to find context,
            // so it has to be in the review pane.
            $table->foreignId('message_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('photo_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();

            $table->text('description')->nullable();
            // Snapshotted, because a serial false-reporter's credibility changes
            // after the fact and the decision must be judged on what was known.
            $table->unsignedTinyInteger('reporter_credibility_at_time')->default(50);

            $table->timestamp('created_at')->useCurrent();

            $table->index('reported_app_user_id');
            $table->index(['category', 'severity']);
            $table->index('report_case_id');
            $table->index('created_at');
        });

        // ---- enforcement ----

        Schema::create('moderation_actions', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            $table->foreignId('report_case_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('verification_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('subject_app_user_id')->constrained('app_users')->cascadeOnDelete();

            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            // DSA Article 17 requires telling the member whether a human or an
            // automated system decided. This column is that answer.
            $table->enum('actor_type', ['human', 'automation'])->default('human');
            $table->unsignedBigInteger('automation_rule_id')->nullable();

            $table->string('ladder_step', 32);
            $table->string('reason_code', 64);
            $table->string('policy_clause')->nullable();
            $table->text('internal_note')->nullable();
            $table->text('user_facing_message')->nullable();

            $table->unsignedInteger('duration_hours')->nullable();
            $table->boolean('notified_user')->default(false);
            $table->unsignedBigInteger('reversed_by_action_id')->nullable();

            // APPEND ONLY — no updated_at. ModerationActionObserver blocks
            // updates and deletes outright.
            $table->timestamp('created_at')->useCurrent();

            $table->index('subject_app_user_id');
            $table->index('report_case_id');
            $table->index(['actor_id', 'created_at']);
            $table->index('ladder_step');
        });

        Schema::create('bans', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('app_user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('moderation_action_id')->nullable()->constrained()->nullOnDelete();

            $table->enum('type', ['feature_limit', 'shadow_ban', 'suspension', 'permanent_ban', 'device_ban']);
            $table->json('limited_features')->nullable();

            $table->string('reason_code', 64);
            $table->text('internal_note')->nullable();
            $table->text('user_facing_message')->nullable();

            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('starts_at')->useCurrent();
            $table->timestamp('expires_at')->nullable();

            /*
             * NOT NULL for every shadow ban, enforced in ApplyEnforcement and
             * asserted in tests. A shadow ban is invisible to the member, so
             * nothing but a calendar entry will ever prompt a revisit — an
             * open-ended one is a permanent secret punishment.
             */
            $table->timestamp('review_due_at')->nullable();

            $table->foreignId('lifted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('lifted_at')->nullable();
            $table->string('lift_reason')->nullable();

            $table->timestamps();

            $table->index(['app_user_id', 'type']);
            $table->index('expires_at');
            $table->index('review_due_at');
            $table->index('lifted_at');
        });

        Schema::create('banned_devices', function (Blueprint $table): void {
            $table->id();
            $table->string('fingerprint_hash', 64)->unique();
            $table->foreignId('ban_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('matched_app_users_count')->default(0);
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('appeals', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('app_user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ban_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('moderation_action_id')->nullable()->constrained()->nullOnDelete();

            /*
             * DSA Article 20: an appeal may not be decided by the person who
             * made the original decision. Storing the original decider is what
             * makes that rule enforceable rather than aspirational — the assign
             * action, the policy and a test all compare against it.
             */
            $table->foreignId('original_decider_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_to_id')->nullable()->constrained('users')->nullOnDelete();

            $table->enum('status', [
                'new', 'assigned', 'in_review', 'upheld', 'overturned', 'partially_overturned', 'withdrawn',
            ])->default('new');

            $table->text('user_statement')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('sla_due_at')->nullable();

            $table->timestamps();

            $table->index('status');
            $table->index('assigned_to_id');
            $table->index('sla_due_at');
        });

        // ---- risk ----

        Schema::create('risk_factor_definitions', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 64)->unique();
            $table->string('label');
            $table->smallInteger('points');
            $table->string('category', 40)->default('behaviour');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('risk_scores', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('app_user_id')->constrained()->cascadeOnDelete();

            $table->unsignedTinyInteger('score')->default(0);
            $table->enum('band', ['low', 'elevated', 'high', 'critical'])->default('low');
            $table->timestamp('computed_at')->useCurrent();
            $table->boolean('is_current')->default(true);

            $table->index(['app_user_id', 'is_current']);
            $table->index('computed_at');
        });

        Schema::create('risk_factors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('risk_score_id')->constrained()->cascadeOnDelete();

            $table->string('definition_key', 64);
            $table->string('label');
            $table->smallInteger('points');
            $table->json('evidence')->nullable();

            $table->index('risk_score_id');
        });

        Schema::create('automation_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('trigger', 64);
            $table->json('conditions')->nullable();
            $table->string('action', 64);
            $table->unsignedSmallInteger('threshold')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // ---- privacy ----

        Schema::create('message_access_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('message_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('report_case_id')->nullable()->constrained()->nullOnDelete();

            $table->string('reason_code', 40);
            $table->text('justification')->nullable();
            $table->unsignedSmallInteger('messages_revealed')->default(0);
            $table->string('ip_address', 45)->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
            $table->index('conversation_id');
        });

        // ---- analytics ----

        Schema::create('daily_metrics', function (Blueprint $table): void {
            $table->id();
            $table->date('date');
            $table->string('metric_key', 64);
            $table->string('dimension', 40)->default('all');
            $table->string('dimension_value', 64)->default('all');
            $table->decimal('value', 16, 4)->default(0);

            $table->unique(['date', 'metric_key', 'dimension', 'dimension_value'], 'daily_metrics_unique');
            $table->index(['metric_key', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_metrics');
        Schema::dropIfExists('message_access_logs');
        Schema::dropIfExists('automation_rules');
        Schema::dropIfExists('risk_factors');
        Schema::dropIfExists('risk_scores');
        Schema::dropIfExists('risk_factor_definitions');
        Schema::dropIfExists('appeals');
        Schema::dropIfExists('banned_devices');
        Schema::dropIfExists('bans');
        Schema::dropIfExists('moderation_actions');
        Schema::dropIfExists('reports');
        Schema::dropIfExists('report_cases');
        Schema::dropIfExists('verification_signals');
        Schema::dropIfExists('verifications');
    }
};
