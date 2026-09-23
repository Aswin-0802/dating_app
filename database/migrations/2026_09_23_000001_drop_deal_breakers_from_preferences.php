<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remove a column nothing ever wrote.
 *
 * `preferences.deal_breakers` was cast to an array on the model and seeded as
 * null; no screen, endpoint or query has ever read or written it. A column
 * like that reads as a feature to the next person to open the schema, and
 * costs a conversation every time.
 *
 * Dropping loses nothing, because there is nothing in it. If deal-breakers are
 * built later they will want their own shape anyway — most likely rows keyed
 * to profile options, not an opaque JSON blob.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('preferences', function (Blueprint $table): void {
            $table->dropColumn('deal_breakers');
        });
    }

    public function down(): void
    {
        Schema::table('preferences', function (Blueprint $table): void {
            $table->json('deal_breakers')->nullable();
        });
    }
};
