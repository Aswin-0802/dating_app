<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * In-app purchases through the App Store and Google Play.
 *
 * A store purchase is one more way an order gets fulfilled, not a second
 * billing system: it reuses `orders`, `gateway_events`, Checkout::fulfil()
 * and Subscriptions::grant(). What the tables lacked was the mapping from a
 * store product to a plan, a place for the store's own subscription
 * identifier, and a dedup key per store transaction.
 *
 * See dating_app_mobile/docs/premium-receipt-contract.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            // Store product identifiers, one per period. Edited in Masters ->
            // Subscription plans. A product id the store reports that matches
            // none of these is refused as `product_unknown`.
            $table->string('apple_product_id_monthly', 120)->nullable()->after('yearly_price');
            $table->string('apple_product_id_yearly', 120)->nullable()->after('apple_product_id_monthly');
            $table->string('google_product_id_monthly', 120)->nullable()->after('apple_product_id_yearly');
            $table->string('google_product_id_yearly', 120)->nullable()->after('google_product_id_monthly');
        });

        // Where the subscription came from now includes the two stores.
        DB::statement("ALTER TABLE `subscriptions` MODIFY `source` ENUM('manual', 'payment', 'apple', 'google') NOT NULL DEFAULT 'manual'");

        Schema::table('subscriptions', function (Blueprint $table): void {
            // The store's identifier for the subscription: Apple's
            // originalTransactionId, Google's purchase token. It is how a
            // server notification finds the member.
            $table->string('external_ref', 200)->nullable()->after('billing_period')->index();
            // null for anything not from a store; the store's word otherwise.
            $table->boolean('auto_renewing')->nullable()->after('external_ref');
        });

        /*
         * One order per store transaction, fulfilled once.
         *
         * MySQL allows any number of NULLs in a unique index, so Stripe and
         * Razorpay orders that never got a payment reference are unaffected.
         * Duplicates among existing non-null references would make the index
         * fail, so they are checked for first and named in the error.
         */
        $duplicates = DB::table('orders')
            ->select('gateway', 'payment_ref', DB::raw('COUNT(*) as c'))
            ->whereNotNull('payment_ref')
            ->groupBy('gateway', 'payment_ref')
            ->having('c', '>', 1)
            ->get();

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException(
                'orders has duplicate (gateway, payment_ref) rows that must be resolved before this migration: '
                .$duplicates->map(fn ($row): string => "{$row->gateway}/{$row->payment_ref} x{$row->c}")->implode(', ')
            );
        }

        Schema::table('orders', function (Blueprint $table): void {
            // 'sandbox' or 'production' for store transactions, so the console
            // can tell a TestFlight purchase from a paying member. Null for
            // the web gateways.
            $table->string('environment', 20)->nullable()->after('gateway_payload');
            $table->unique(['gateway', 'payment_ref']);
        });

        Schema::table('payment_gateways', function (Blueprint $table): void {
            // 'checkout' gateways take money on the website; 'store' gateways
            // only verify what the app stores already charged, and are never
            // offered as a way to pay on the web.
            $table->string('kind', 20)->default('checkout')->after('slug');
        });
    }

    public function down(): void
    {
        Schema::table('payment_gateways', fn (Blueprint $table) => $table->dropColumn('kind'));

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropUnique(['gateway', 'payment_ref']);
            $table->dropColumn('environment');
        });

        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropIndex(['external_ref']);
            $table->dropColumn(['external_ref', 'auto_renewing']);
        });

        DB::table('subscriptions')->whereIn('source', ['apple', 'google'])->update(['source' => 'payment']);
        DB::statement("ALTER TABLE `subscriptions` MODIFY `source` ENUM('manual', 'payment') NOT NULL DEFAULT 'manual'");

        Schema::table('plans', fn (Blueprint $table) => $table->dropColumn([
            'apple_product_id_monthly', 'apple_product_id_yearly', 'google_product_id_monthly', 'google_product_id_yearly',
        ]));
    }
};
