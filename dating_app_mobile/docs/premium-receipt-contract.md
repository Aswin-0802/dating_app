# Premium in the app: receipt-validation contract

Status: **approved and built** (2026-09-25). This document is the spec; the code follows it. Where the two disagree, the code is wrong.

Approved with five changes to the first draft, all incorporated below: sandbox transactions are honoured on a production server (§2), Apple subscription groups and Google replacement modes (§3), Family Sharing (§4), never shorten or downgrade an active entitlement (§5, §8), and what happens to a store subscription on a deleted account (§6, §8).

Implemented on the server in `App\Services\Store` (`StorePurchases`, `StoreDriver`, `AppleStoreDriver`, `GoogleStoreDriver`, `Jws`), `App\Services\Billing\Subscriptions`, `App\Services\Payments\Checkout::fulfil()`, `StoreReceiptController`, `StoreWebhookController`, migration `2026_09_25_000001_add_store_billing`; tested in `tests/Feature/StoreBillingTest.php` and `tests/Unit/JwsTest.php`. In the app: `src/app/profile/premium.tsx` with `expo-iap`.

The principle throughout: a store purchase is **one more way an order gets fulfilled**, not a second billing system. It reuses `orders`, `gateway_events`, `Checkout::fulfil()` and `Subscriptions`, so the replay and partial-failure guarantees already tested for Stripe and Razorpay apply unchanged.

---

## 1. The endpoint

`POST /api/v1/me/premium/receipt`
Middleware: `auth:sanctum`, `appuser.active`, `ability:profile:write`, `throttle:receipt` (10 per hour per member).

Request:

```json
{ "platform": "ios" | "android", "product_id": "plus_monthly", "transaction": "<string>" }
```

- iOS: `transaction` is the StoreKit 2 signed transaction (`jwsRepresentation`; `purchaseToken` in expo-iap).
- Android: `transaction` is the Play Billing purchase token.

Response `200`:

```json
{ "data": { ...MeResource } }
```

A fresh `MeResource`, so the app replaces `me` and `is_premium` is whatever the server decided. **Replaying the same transaction returns 200 and changes nothing.**

Errors, all in the existing envelope `{message, code}`:

| Status | `code` | When |
|---|---|---|
| 422 | `validation_failed` | shape wrong |
| 422 | `product_unknown` | the store's product id is not mapped to a plan (§3) |
| 422 | `receipt_invalid` | the store does not recognise it, or it is unpaid, expired, revoked, pending, or for another app |
| 409 | `receipt_owned_elsewhere` | a purchased (not family-shared) transaction already bound to a different member |
| 503 | `store_unavailable` | Apple or Google could not be reached, or the store is switched off; `Retry-After: 30` |

## 2. Verification: the server asks the store, never the client

The client's blob is an **identifier**, not evidence.

- **iOS**: verify the JWS signature chain (`x5c`, leaf first) link by link to the pinned Apple Root CA G3 (`resources/certs/AppleRootCA-G3.pem`), check `bundleId`; then call the App Store Server API `GET /inApps/v1/subscriptions/{originalTransactionId}` and use the latest signed transaction and renewal info it returns (verified the same way). Auth is a 20-minute ES256 JWT signed with the issuer id, key id and `.p8` key from the gateway credentials.
- **Android**: call the Play Developer API `purchases.subscriptionsv2.get(packageName, token)` with a service account. After a successful grant, **acknowledge** the purchase in `DB::afterCommit` (Google auto-refunds an unacknowledged purchase after three days). If acknowledgement fails, the next notification for that token retries it.
- **Environment — the required fix.** The `environment` field of the **verified** transaction (`Sandbox` / `Production` for Apple; the presence of `testPurchase` for Google) decides which App Store Server API host is called and is recorded on the order as `orders.environment`. **Both environments are honoured regardless of the gateway's `is_test_mode`.** TestFlight builds make Sandbox purchases against the production server, and every tester would otherwise get `receipt_invalid` at release-candidate stage. The test-mode switch is not consulted for stores at all: the same App Store Connect key serves both hosts, and the console no longer warns about test mode on a store row. Test: `test_a_testflight_sandbox_purchase_succeeds_against_a_production_mode_gateway`.

## 3. Product to plan mapping

Four nullable columns on `plans`, editable in Billing → Subscription plans (`apple_product_id_monthly`, `apple_product_id_yearly`, `google_product_id_monthly`, `google_product_id_yearly`). A product id identifies exactly one plan and period; the form refuses a duplicate across plans or across the two periods of one plan.

The store's product id in the verified transaction, not the client's `product_id`, is what is looked up. No match → `product_unknown`, and no order is created. The client's `product_id` is only cross-checked and logged.

Google: **one subscription product per period with a single base plan each** (answer to Q1), mirroring Apple, so a product id alone identifies plan and period.

**Subscription groups (Gap 1).** Apple only treats one subscription as an upgrade, downgrade or crossgrade of another when both products are in the **same subscription group**; otherwise a member can hold Plus and Gold at once and pay for both. Google has the same failure through replacement modes: without `subscriptionProductReplacementParams` on the purchase, a second subscription is simply added. So:

- Every plan's Apple products live in **one** subscription group, ranked by tier (Gold above Plus), with monthly and yearly of the same plan at the same level.
- On Android the app passes the current purchase token and a replacement mode when the member already has a store subscription (§8).
- The server tolerates the failure anyway: two active store rows are two entitlements and the mirror is the best of them (§5), so the member is never under-served — but they are double-charged, which only the store configuration prevents.

This is invisible until somebody buys both, so it is on the go-live checklist at the end.

## 4. Idempotency and replay

Two layers, exactly as `Checkout` does today.

**Transaction level, in `orders`.** Every store transaction becomes an order: `gateway` = `apple` or `google`, `gateway_ref` = original transaction id (Apple) or purchase token (Google), `payment_ref` = transaction id (Apple) or `latestOrderId` (Google), `purpose` = `plan`, `reference` = plan slug, `environment` = sandbox/production, amount and currency from the store when it reports them (Apple does; Google's v2 API does not, so those orders carry 0 and the plan's currency). The **unique index on `orders (gateway, payment_ref)`** makes a transaction fulfillable once (answer to Q4: `orders`, not a separate table — one fulfilment path). The order is committed before fulfilment, like a web order at checkout; fulfilment is `Checkout::fulfil()`, which re-reads it under `lockForUpdate` and does nothing if it is already paid. A concurrent create loses the unique index race, finds the other's row, and proceeds to the same lock.

**Notification level, in `gateway_events`.** Every store notification is recorded as `(gateway, event_id)` with `event_id` = Apple `notificationUUID` or Pub/Sub `messageId`. `processed_at` is set **only after fulfilment commits**; anything thrown leaves it null and the controller answers 500 so the store redelivers. A processed event replayed changes nothing.

**Ownership (answer to Q2) and Family Sharing (Gap 2).** The first member to present a *purchased* transaction owns it; another member presenting the same one gets `409 receipt_owned_elsewhere` and nothing changes. There is no automatic transfer; support moves it (§7). A transaction with `inAppOwnershipType = FAMILY_SHARED` carries the purchaser's `originalTransactionId` and is **not** a second claim on the purchaser's subscription: every family member may redeem it on their own account, each gets their own order (`payment_ref` = `{transactionId}@{member uuid}`, so the unique index still holds) and their own subscription row noted "Family Sharing", and the ownership check is skipped for it. Google Play has no family sharing for subscriptions; every Google transaction is `purchased`. Tests: `..._already_attached_to_another_member_is_refused`, `..._family_shared_transaction_is_honoured_for_each_family_member`.

## 5. Entitlement dates: the store owns the calendar, and nothing is ever taken away

- `Subscriptions::grantFromStore(endsAt: <store expiry>, source: 'apple'|'google', externalRef, billingPeriod, autoRenewing, environment)`. The "renewing early adds to what is left" branch in `fulfil()` is skipped for store orders: the store already did that arithmetic and its expiry is authoritative.
- A renewal is a new transaction, a new order and a new row; the previous row **for the same store subscription** (same `external_ref`) is closed as superseded (`expired`). Nothing stacks within one store subscription.
- **Never shorten or downgrade (Gap 3, answer to Q3).** A member may hold active rows from more than one source at once: a web or staff plan and a store plan, or two store plans (§3 failure). Rows from different sources never close each other. A web or staff grant replaces the previous web or staff row only; a store grant replaces the previous row of the same store subscription only. The mirror on `app_users` — `is_premium`, `premium_tier`, `premium_until` — is recomputed from **all** active rows every time one changes: the best tier (by monthly price) and the latest end date (open-ended wins). So a member with a web Gold plan until December who buys store Plus monthly keeps Gold until December, then carries on with Plus; the store purchase was accepted and fulfilled (refusing it would take money and give nothing), and prevention of the double purchase is the app's job (§8). `refreshMirror()` in `Subscriptions` is the single place the mirror is written from rows. Tests: `..._never_shortens_or_downgrades_an_active_plan`, `..._when_the_better_plan_lapses_the_other_one_carries_on`.
- **Grace period / billing retry**: the entitlement runs to the store's `gracePeriodExpiresDate` (Apple, status 4) or `expiryTime` while in grace (Google). Billing retry without grace (Apple status 3) and account hold or pause (Google) end the entitlement for that subscription until the store reports it recovered.
- **Refund / revoke / expiry**: the store rows for that subscription close (`revokeExternal`); the mirror is recomputed, so a plan from another source carries on. The member is emailed only when they actually drop off premium.
- **Auto-renew turned off**: no change to dates; `subscriptions.auto_renewing` is set false so the console and the app say "ends on" rather than "renews on".
- **Staff removal** (`revoke()`) still closes every row including store rows; the next store renewal recreates its own.
- `expireDue()` remains the safety net for anything the notifications miss, and now recomputes the mirror rather than clearing it.

Columns on `subscriptions`: `source` enum gains `apple` and `google`; `external_ref` (original transaction id / purchase token, indexed); `auto_renewing` nullable boolean.

## 6. Server notifications

Two web routes beside `webhooks/payments/{gateway}`, no session, no CSRF (`webhooks/*` is exempt):

- `POST /webhooks/store/apple`: App Store Server Notifications V2. The `signedPayload` JWS chain is verified to the pinned root and the bundle id checked; a bad signature is 400, never retried. The notification carries the transaction and renewal info, so no API call is needed.
- `POST /webhooks/store/google`: real-time developer notifications delivered by Pub/Sub push. The OIDC bearer token is verified (RS256 against Google's published certificates, issuer, expiry, audience = this route's URL unless `pubsub_audience` is set, and optionally the pushing service account's email); a bad token is 400. The payload only names the purchase token, so the handler **always re-fetches the subscription from the Play API** before acting. The notification is a hint, exactly like `ReturnHint`.

The notification type is recorded on the event, but the **verified state** decides what happens:

| Verified state | Apple (status) | Google (`subscriptionState`) | Action |
|---|---|---|---|
| entitled: active or in grace, expiry in the future | 1, 4 | `ACTIVE`, `CANCELED` (paid up), `IN_GRACE_PERIOD` | order for the transaction, `fulfil()` (idempotent) |
| expired | 2 | `EXPIRED` | close that subscription's rows |
| revoked / refunded | 5 | (revoked) | close that subscription's rows |
| hold: billing retry, account hold, paused | 3 | `ON_HOLD`, `PAUSED` | close that subscription's rows until recovery |
| pending / unknown | — | `PENDING`, other | recorded, nothing done |

Finding the member: any active or past subscription row with that `external_ref`, or any order with that `gateway_ref`. If nothing matches (the app crashed before it posted the receipt), the `appAccountToken` (Apple) / `obfuscatedExternalAccountId` (Google), which the app sets to the member's uuid at purchase time (§8), identifies the member and the order is created from the notification. If that is absent too: recorded, marked processed, logged at warning; "Restore purchases" in the app attaches it later.

**Deleted accounts (Gap 4).** Account deletion soft-deletes and pseudonymises the member; the store keeps charging, and only the member can cancel it in the store. The decision is **record and ignore**: a notification whose member is soft-deleted is recorded, marked processed and logged at info; nothing is granted to a pseudonymised row and nothing throws. At deletion time, the member's active store rows are closed with a note, an audit row `store_plan_orphaned` is written, and the member is told — in the app's deletion confirmation and on the website's account page — that deleting the account does not cancel the store subscription. Tests: `..._renewal_for_a_deleted_member_is_recorded_and_ignored`, `..._deleting_the_account_closes_store_rows_...`.

Controller behaviour is `WebhookController`'s: 2xx for anything understood, 400 for a failed signature, 500 for a thrown error so the store retries. Apple retries a failed delivery up to five times over three days; Pub/Sub redelivers until acknowledged.

## 7. Credentials, configuration, and the console

Two rows in `payment_gateways` with `kind = store` (seeded inactive), so the existing System → Payments screen, encrypted credentials and `is_active` are reused:

- `apple`: `issuer_id`, `key_id`, `private_key` (the `.p8`, pasted whole), `bundle_id`, `app_apple_id`
- `google`: `package_name`, `service_account_json` (pasted whole), `pubsub_service_account` (optional pin)

`Checkout::availableGateways()` filters on `kind = checkout`, so the website never offers "Pay with App Store". The console shows each store's notification address with setup steps, and uses textareas for the whole-file credentials.

**Support reassignment (answer to Q2).** Billing → Subscriptions has "Move to another account" on every store row (permission `grant_plans`): it takes the target member's email and a note, moves every subscription row and order for that store subscription, recomputes both members' mirrors, and writes a sensitive audit row `plan_reassigned`. Subsequent renewals follow the subscription to its new owner. Test: `test_support_can_move_a_store_subscription_to_another_account`.

`platform:preflight` gains a store check: for each active store it runs the driver's dry run (Apple `POST /inApps/v1/notifications/test`; a Play API call with a nonsense token that must fail with 400/404 rather than 401/403) and warns when no plan on sale has a product id for that store, or when no store is on at all.

`GET /config` does not change.

## 8. What the app does, and what `/me` gains

Public `GET /api/v1/plans`:

```json
{ "data": [ { "slug": "plus", "name": "Plus", "tagline": "...", "features": ["unlimited_likes", "see_likers"], "perks": [], "is_featured": false,
              "products": { "ios": { "monthly": "plus_monthly", "yearly": "plus_yearly" },
                            "android": { "monthly": "plus_monthly", "yearly": "plus_yearly" } } } ] }
```

Only active plans; a period is omitted when it is not mapped. This replaces the benefit lines that were hard-coded in the app.

`MeResource` gains `premium_source` (`manual` | `payment` | `apple` | `google` | `null`) and `auto_renewing` (`bool` | `null`), read from the row that gives the member their tier.

App flow (`expo-iap`, which needs a development build):

1. Load `/plans`, ask the store for those product ids, show the store's localised prices.
2. Purchase with `appAccountToken` (iOS) / `obfuscatedAccountId` (Android) set to `me.id`. On Android, when the member already has a store subscription, pass its purchase token and a replacement mode so it is replaced rather than added (§3).
3. `POST /me/premium/receipt`; on 200 replace `me`, **then** finish the store transaction. A 409 also finishes it (it is not this member's) and shows the message. Any other failure leaves the transaction unfinished so StoreKit / Play re-present it on next launch and the app re-posts.
4. "Restore purchases" posts each available transaction through the same endpoint.
5. "Manage subscription" opens the store's own management page, which the stores permit.
6. **Double-purchase prevention lives here (Gap 3).** If `premium_source` is `payment` or `manual` and the plan is active, the app shows that and offers no purchase. If it is a store plan, the app offers only a change within that store. The server never refuses a store purchase for this reason — the store has already charged — and never shortens or downgrades what the member had (§5); the app is the only place a needless second charge can be avoided, and an old build in the wild is exactly why the server rule exists.

New error codes for the app to branch on: `product_unknown`, `receipt_invalid`, `receipt_owned_elsewhere`, `store_unavailable`.

---

## Migrations (applied)

1. `plans`: four product id columns.
2. `subscriptions`: `source` enum + `apple`, `google`; `external_ref` varchar(200) indexed; `auto_renewing` nullable boolean.
3. `orders`: `environment` varchar(20) nullable; unique index `(gateway, payment_ref)`. The migration refuses to run if existing rows would violate it and names them (MySQL allows multiple NULLs, so Stripe/Razorpay rows without a payment reference are unaffected).
4. `payment_gateways`: `kind` column (`checkout` | `store`); the seeder adds the `apple` and `google` rows inactive.

`gateway_events` does not change.

## Tests (all present in `tests/Feature/StoreBillingTest.php` unless noted)

- a store notification delivered twice produces exactly one subscription;
- fulfilment made to throw once leaves the order pending and the event unprocessed, the redelivery fulfils it, and a third delivery changes nothing;
- the same receipt posted twice by the same member is 200 both times with one subscription;
- the same receipt posted by a second member is 409 and changes nothing;
- **a TestFlight-style sandbox transaction against a production-mode gateway succeeds** (§2 fix; fails before the fix, passes after);
- **a FAMILY_SHARED transaction is honoured for each family member on their own account, not refused with 409**;
- **a renewal notification for a soft-deleted member does not throw, grants nothing, and is marked processed**;
- deleting the account closes store rows and records that the store keeps billing;
- a refund notification clears `is_premium`; a hold ends the entitlement and a recovery restores it;
- a renewal moves `ends_at` and leaves one active subscription row;
- a Google notification is treated as a hint and the Play API is asked;
- an unmapped product is 422 `product_unknown` and creates no order; an expired, pending or unknown transaction is 422 `receipt_invalid`;
- a switched-off store is 503 with `Retry-After`;
- a notification with a bad signature is 400 and writes no `gateway_events` row; a test ping is recorded and ignored;
- a notification for an unknown transaction with an `appAccountToken` creates and fulfils the order; without one it is recorded, processed and ignored;
- a store purchase never shortens or downgrades an active web plan, a store renewal still does not, and a staff grant never downgrades a store plan; when the better plan lapses the other carries on;
- store gateways are never offered as a way to pay on the website;
- `GET /plans` lists store products;
- support can move a store subscription to another account and renewals follow it;
- `tests/Unit/JwsTest.php`: ES256 and RS256 round trips with tamper detection, DER/raw conversion, and an Apple-style chain that verifies only against the pinned root, refuses a broken chain and a foreign signature, and refuses malformed input.

## Go-live checklist (things no test can catch)

1. **Apple subscription group.** All products for all plans in one group, ranked Gold above Plus. Buy Plus, then Gold, in Sandbox: the Plus subscription must be upgraded, not added.
2. **Google base plans.** One product per period, one base plan each, matching the product ids in Billing → Subscription plans. Buy Plus then Gold with a licence tester: one active subscription in Play, not two.
3. **Notification URLs** entered in App Store Connect (production *and* sandbox, V2) and in Play Console (Pub/Sub push subscription with OIDC auth), both over https. Send Apple's test notification; publish a Play test notification. Both must appear as processed rows in `gateway_events`.
4. `php artisan platform:preflight` reports both stores reachable and mapped.
5. **TestFlight / internal testing** purchase on a production-mode server: the receipt must be accepted and `orders.environment` must read `sandbox`.
6. Refund a sandbox purchase from App Store Connect and revoke a test purchase in Play Console: both members must drop off premium within the notification delay.
