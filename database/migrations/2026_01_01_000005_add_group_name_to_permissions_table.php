<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Groups and labels the ~100 permissions so the role matrix is navigable.
 *
 * Without this the matrix is an unsorted wall of snake_case strings, which is
 * how roles end up over-granted — nobody audits what they cannot read.
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = config('permission.table_names.permissions', 'permissions');

        Schema::table($table, function (Blueprint $blueprint): void {
            $blueprint->string('group_name')->default('General')->after('guard_name');
            $blueprint->string('label')->nullable()->after('group_name');
            $blueprint->string('description')->nullable()->after('label');
            $blueprint->unsignedSmallInteger('sort_order')->default(0)->after('description');

            // Permissions that must not be handed out casually: message content,
            // the restricted minor-safety queue, PII export.
            $blueprint->boolean('is_sensitive')->default(false)->after('sort_order');

            $blueprint->index(['group_name', 'sort_order']);
        });
    }

    public function down(): void
    {
        $table = config('permission.table_names.permissions', 'permissions');

        Schema::table($table, function (Blueprint $blueprint): void {
            $blueprint->dropIndex(['group_name', 'sort_order']);
            $blueprint->dropColumn(['group_name', 'label', 'description', 'sort_order', 'is_sensitive']);
        });
    }
};
