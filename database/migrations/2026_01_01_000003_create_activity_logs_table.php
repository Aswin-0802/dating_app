<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staff audit trail.
 *
 * Append-only (enforced by ActivityLogObserver) and deliberately deduplicated:
 * duplicate log rows are how a repeat offender's history gets buried, which is a
 * documented failure of moderation tooling rather than a cosmetic problem.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // Denormalised so the log still reads correctly after a staff member
            // is deleted and their row is gone.
            $table->string('actor_name')->nullable();
            $table->string('actor_role')->nullable();

            $table->string('module', 64);
            $table->string('action', 64);

            $table->nullableMorphs('subject');
            $table->string('description')->nullable();

            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();

            // Flags a privileged read (message content, PII export) for review.
            $table->boolean('is_sensitive')->default(false);

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['module', 'action']);
            $table->index(['user_id', 'created_at']);
            $table->index('created_at');
            $table->index('is_sensitive');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
