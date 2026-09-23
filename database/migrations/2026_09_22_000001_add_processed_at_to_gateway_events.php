<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tell "seen" apart from "handled".
 *
 * The dedup row was written before fulfilment and outside the transaction that
 * fulfilment rolls back, so one failure stranded a paid order for good: the
 * gateway's retry found the row, called it a duplicate and skipped it. A row
 * only counts as a duplicate once processed_at is set.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gateway_events', function (Blueprint $table): void {
            $table->timestamp('processed_at')->nullable()->after('payload');
        });

        // Everything already recorded was handled under the old rule, where
        // writing the row and acting on it were one step. Leaving these null
        // would invite a replay of every historical event.
        DB::table('gateway_events')->update(['processed_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('gateway_events', function (Blueprint $table): void {
            $table->dropColumn('processed_at');
        });
    }
};
