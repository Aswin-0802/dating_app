<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Codes texted to members to prove a phone number is theirs.
 *
 * In the database rather than the cache: an expired row is the evidence that
 * a code was sent, support gets asked "did you text me?" constantly, and the
 * attempt counter has to survive a cache flush or it is not a limit at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phone_verifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('app_user_id')->constrained()->cascadeOnDelete();
            $table->string('phone', 20);

            // Hashed: a leaked database should not hand somebody a live code.
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);

            $table->timestamp('expires_at');
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['app_user_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phone_verifications');
    }
};
