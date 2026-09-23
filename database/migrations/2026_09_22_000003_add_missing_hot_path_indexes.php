<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for queries that run constantly and had none.
 *
 * Several are composites rather than the single columns the audit named. The
 * single column is what was missing; the composite is what the query actually
 * asks for, and it covers the single-column case as a prefix anyway. Where the
 * leading column alone is nearly useless — read_at is mostly NULL, status is
 * three values — a single-column index would have been measured as "present"
 * and still not used.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_users', function (Blueprint $table): void {
            // scopeAgeBetween, on every deck query.
            $table->index('birthdate');

            // Subscriptions::expireDue() scans this hourly.
            $table->index('premium_until');

            // The premium filter on the member list.
            $table->index('is_premium');

            // The discovery bounding box. Latitude leads because it is the
            // range MySQL can use; longitude then filters within it.
            $table->index(['last_latitude', 'last_longitude'], 'app_users_coordinates_index');
        });

        Schema::table('messages', function (Blueprint $table): void {
            // Unread counts: conversation, then the unread test.
            $table->index(['conversation_id', 'read_at'], 'messages_conversation_id_read_at_index');
        });

        Schema::table('conversations', function (Blueprint $table): void {
            // The member's own list: open threads, most recent first.
            $table->index(['status', 'last_message_at'], 'conversations_status_last_message_at_index');
        });

        Schema::table('swipes', function (Blueprint $table): void {
            // The daily like count, which now runs inside the swipe transaction
            // and holds a lock while it does.
            $table->index(['app_user_id', 'action', 'created_at'], 'swipes_app_user_id_action_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('app_users', function (Blueprint $table): void {
            $table->dropIndex(['birthdate']);
            $table->dropIndex(['premium_until']);
            $table->dropIndex(['is_premium']);
            $table->dropIndex('app_users_coordinates_index');
        });

        Schema::table('messages', function (Blueprint $table): void {
            $table->dropIndex('messages_conversation_id_read_at_index');
        });

        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropIndex('conversations_status_last_message_at_index');
        });

        Schema::table('swipes', function (Blueprint $table): void {
            $table->dropIndex('swipes_app_user_id_action_created_at_index');
        });
    }
};
