<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The city search within one state (the app's picker after the member chose
 * a state) filtered on state_id and then sorted by name with a filesort once
 * a state held hundreds of cities — Tamil Nadu has 496 after the GeoNames
 * import. (state_id, name) serves both the filter and the prefix match in
 * index order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cities', fn (Blueprint $table) => $table->index(['state_id', 'name']));
    }

    public function down(): void
    {
        Schema::table('cities', fn (Blueprint $table) => $table->dropIndex(['state_id', 'name']));
    }
};
