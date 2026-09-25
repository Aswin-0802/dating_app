<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One city can be switched off without taking its whole state down.
 *
 * `is_focus` stays a display hint (featured cities are listed first); this
 * column is the permission, checked by App\Rules\SelectableCity together
 * with the country's and the state's own switches. Every existing city is
 * on, so nothing changes for anyone until an operator hides one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cities', function (Blueprint $table): void {
            $table->boolean('is_active')->default(true)->after('is_focus');
            $table->index(['country_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::table('cities', function (Blueprint $table): void {
            $table->dropIndex(['country_id', 'is_active']);
            $table->dropColumn('is_active');
        });
    }
};
