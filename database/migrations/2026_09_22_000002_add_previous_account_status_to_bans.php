<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remember what the account was before it was restricted.
 *
 * Lifting a ban set the account to `active` whatever it had been, so a member
 * who was `pending` (profile still incomplete) or who had deactivated their own
 * account was silently promoted into the deck when their suspension ended —
 * published to other members without ever asking to be.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bans', function (Blueprint $table): void {
            $table->string('previous_account_status', 20)->nullable()->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('bans', function (Blueprint $table): void {
            $table->dropColumn('previous_account_status');
        });
    }
};
