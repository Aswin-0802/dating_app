<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where to reach a member with a push notification.
 *
 * Separate from `devices`, which exists for risk and device-ban work: one
 * device can hand back a new FCM token at any time, the same person may have
 * a phone and two browsers, and a token that Firebase rejects has to be
 * deleted on its own without touching the device record behind it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('app_user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->nullable()->constrained()->nullOnDelete();

            // FCM registration tokens are long; 512 keeps the unique index
            // inside InnoDB's key length on utf8mb4.
            $table->string('token', 512)->unique();
            $table->enum('platform', ['android', 'ios', 'web'])->default('web');
            $table->string('label', 120)->nullable(); // "Chrome on Windows"

            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('last_failed_at')->nullable();
            $table->unsignedSmallInteger('failure_count')->default(0);

            $table->timestamps();

            $table->index(['app_user_id', 'platform']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_tokens');
    }
};
