<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `users` holds STAFF only — admins, moderators, analysts.
 *
 * Dating-app members live in `app_users`. Keeping them apart means a policy
 * never has to ask "is this a member or an operator?", and staff can never
 * accidentally acquire a swipe history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('username')->unique()->nullable()->after('name');
            $table->string('avatar_path')->nullable()->after('email');
            $table->string('job_title')->nullable()->after('avatar_path');
            $table->string('timezone')->default('UTC')->after('job_title');

            $table->enum('status', ['active', 'suspended', 'invited'])
                ->default('active')
                ->after('timezone');

            // Staff TOTP. Stored encrypted; see the User model's casts.
            $table->text('two_factor_secret')->nullable()->after('password');
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');

            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();

            $table->softDeletes();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['status']);

            $table->dropColumn([
                'username',
                'avatar_path',
                'job_title',
                'timezone',
                'status',
                'two_factor_secret',
                'two_factor_recovery_codes',
                'two_factor_confirmed_at',
                'last_login_at',
                'last_login_ip',
                'deleted_at',
            ]);
        });
    }
};
