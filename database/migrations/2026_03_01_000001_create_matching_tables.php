<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('swipes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('app_user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('target_app_user_id')->constrained('app_users')->cascadeOnDelete();

            $table->enum('action', ['like', 'pass', 'superlike', 'rewind']);
            $table->enum('source', ['deck', 'likes_you', 'profile'])->default('deck');
            $table->boolean('is_match')->default(false);

            $table->timestamp('created_at')->useCurrent();

            // One decision per pair, per direction. Also the index that answers
            // "has this member already seen that one?" on every deck build.
            $table->unique(['app_user_id', 'target_app_user_id']);
            $table->index(['target_app_user_id', 'action']);
            $table->index('created_at');
        });

        Schema::create('matches', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            /*
             * INVARIANT: app_user_one_id < app_user_two_id, enforced in
             * MatchRecord::booted(). Without a canonical ordering the unique
             * index below does not actually prevent duplicate pairs, because
             * (A,B) and (B,A) are different rows.
             */
            $table->foreignId('app_user_one_id')->constrained('app_users')->cascadeOnDelete();
            $table->foreignId('app_user_two_id')->constrained('app_users')->cascadeOnDelete();

            $table->dateTime('matched_at');
            $table->enum('status', ['active', 'unmatched', 'blocked', 'expired'])->default('active');
            $table->foreignId('unmatched_by')->nullable()->constrained('app_users')->nullOnDelete();

            $table->timestamp('first_message_at')->nullable();
            $table->timestamp('first_reply_at')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->unsignedInteger('messages_count')->default(0);
            $table->boolean('same_city')->default(false);

            $table->timestamps();

            $table->unique(['app_user_one_id', 'app_user_two_id']);
            $table->index('app_user_two_id');
            $table->index('status');
            $table->index('matched_at');
            $table->index('messages_count');
        });

        Schema::create('conversations', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('match_id')->unique()->constrained()->cascadeOnDelete();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->unsignedInteger('messages_count')->default(0);

            $table->boolean('is_flagged')->default(false);
            $table->unsignedTinyInteger('risk_score')->default(0);
            $table->enum('status', ['open', 'closed', 'frozen'])->default('open');

            $table->timestamps();

            $table->index('is_flagged');
            $table->index('last_message_at');
        });

        Schema::create('conversation_participants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('app_user_id')->constrained()->cascadeOnDelete();

            $table->timestamp('last_read_at')->nullable();
            $table->unsignedInteger('unread_count')->default(0);
            $table->boolean('muted')->default(false);

            $table->unique(['conversation_id', 'app_user_id']);
            $table->index('app_user_id');
        });

        Schema::create('messages', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_app_user_id')->constrained('app_users')->cascadeOnDelete();

            $table->enum('type', ['text', 'image', 'gif', 'voice', 'system'])->default('text');

            /*
             * Plaintext, deliberately. Encrypting kills LIKE search and the
             * flagged-message pipeline; the protection here is the permission
             * gate plus message_access_logs, not the storage format. Message
             * bodies are $hidden on the model so they cannot leak by accident.
             */
            $table->text('body')->nullable();
            $table->string('media_path')->nullable();

            $table->boolean('contains_link')->default(false);
            $table->boolean('contains_contact_info')->default(false);
            $table->boolean('is_flagged')->default(false);
            $table->json('flag_labels')->nullable();
            $table->enum('moderation_status', ['none', 'flagged', 'removed'])->default('none');

            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->softDeletes();

            $table->index(['conversation_id', 'created_at']);
            $table->index('sender_app_user_id');
            $table->index('is_flagged');
            $table->index('created_at');
        });

        Schema::create('blocks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('app_user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('blocked_app_user_id')->constrained('app_users')->cascadeOnDelete();

            $table->string('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['app_user_id', 'blocked_app_user_id']);
            // "Blocked by many" is a harassment signal, so the reverse lookup
            // needs to be as fast as the forward one.
            $table->index('blocked_app_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blocks');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversation_participants');
        Schema::dropIfExists('conversations');
        Schema::dropIfExists('matches');
        Schema::dropIfExists('swipes');
    }
};
