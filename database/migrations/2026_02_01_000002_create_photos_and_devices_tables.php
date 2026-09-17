<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('photos', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('app_user_id')->constrained()->cascadeOnDelete();

            $table->string('disk')->default('public');
            $table->string('path');
            $table->string('thumb_path')->nullable();
            $table->unsignedTinyInteger('position')->default(0);
            $table->boolean('is_primary')->default(false);

            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->unsignedInteger('bytes')->nullable();

            /*
             * Two fingerprints, two different jobs:
             *
             *  - phash catches the SAME IMAGE reused across accounts (stolen
             *    photo rings).
             *  - face_signature catches the SAME PERSON across accounts even
             *    when the images differ. That second one is the highest-value
             *    signal on the verification review screen.
             */
            $table->char('phash', 16)->nullable();
            $table->char('face_signature', 64)->nullable();

            $table->enum('moderation_status', ['pending', 'approved', 'auto_flagged', 'rejected'])
                ->default('pending');
            $table->json('moderation_labels')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['app_user_id', 'position']);
            $table->index('phash');
            $table->index('face_signature');
            $table->index('moderation_status');
        });

        Schema::create('devices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('app_user_id')->constrained()->cascadeOnDelete();

            // Shared fingerprints link accounts: ban evasion, duplicate signups,
            // and coordinated reporting all surface through this column.
            $table->string('fingerprint_hash', 64);

            $table->enum('platform', ['ios', 'android', 'web'])->default('ios');
            $table->string('os_version')->nullable();
            $table->string('app_version')->nullable();
            $table->string('push_token')->nullable();
            $table->timestamp('last_seen_at')->nullable();

            $table->timestamps();

            $table->index('fingerprint_hash');
            $table->unique(['app_user_id', 'fingerprint_hash']);
        });

        Schema::create('app_user_logins', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('app_user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->nullable()->constrained()->nullOnDelete();

            $table->string('ip_address', 45)->nullable();
            $table->string('country_code', 2)->nullable();
            $table->boolean('succeeded')->default(true);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['app_user_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_user_logins');
        Schema::dropIfExists('devices');
        Schema::dropIfExists('photos');
    }
};
