<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The city type-ahead searches by the start of the name across every
 * country (the website's picker has no country step). EXPLAIN showed a
 * full table scan for that query — `type: ALL`, no key — while a search
 * within one country already used (country_id, name). A plain index on
 * name turns the cross-country prefix search into a range scan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cities', fn (Blueprint $table) => $table->index('name'));
    }

    public function down(): void
    {
        Schema::table('cities', fn (Blueprint $table) => $table->dropIndex(['name']));
    }
};
