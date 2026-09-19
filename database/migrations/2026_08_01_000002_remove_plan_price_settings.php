<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Plan prices moved from Branding into the plans master; the old settings
 * would only confuse anyone reading the settings table.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->whereIn('key', ['website.plus_price', 'website.gold_price'])->delete();
    }

    public function down(): void
    {
        //
    }
};
