# Dating — mobile client

A React Native (Expo SDK 57) client for the dating platform whose Laravel API lives one level up, in the root of this repository. The app holds no business rules: every limit, threshold and entitlement comes from the server, and every outcome (a match, a refused swipe, a closed thread) is what the server said.

## Running it

```
cp .env.example .env         # set EXPO_PUBLIC_API_URL
npm install
npx expo start
```

`EXPO_PUBLIC_API_URL` is the API base **including `/api/v1`**. Localhost only works in the iOS simulator; the Android emulator reaches the host machine at `10.0.2.2`, and a physical phone needs the machine's LAN address. The Laravel side must be reachable at that address (XAMPP's Apache binds to all interfaces by default).

Before a device is involved, the API layer can be checked from Node:

```
npm run apicheck -- http://localhost/Aswin/dating_app/public/api/v1 jakayla.1@example.com password
```

It logs in, reads `/config`, `/me`, the deck and the conversations, and provokes each error code the app branches on.

Checks that must pass before a change is done:

```
npm run typecheck
npm run lint
```

## Expo Go or a development build?

Sign-in, onboarding, discovery, matches and messaging all run in **Expo Go**. Three things do not, and need a development build (`npx expo run:android` / `run:ios`, or `eas build --profile development`):

- **Push notifications.** Expo Go cannot receive FCM device tokens on SDK 53+.
- **In-app purchase.** `expo-iap` is a native module; the Premium screen connects to the store only in a development build.
- **Secure storage** works in Expo Go, but the Android backup exclusion configured in `app.json` only applies to a real build.
- **Camera** for the verification selfie works in Expo Go; the permission strings in `app.json` apply to builds.

## How the app talks to the API

| Concern | Where | What it does |
|---|---|---|
| Token | `src/auth/tokenStore.ts` | Sanctum bearer token in **expo-secure-store**, never AsyncStorage. |
| Requests | `src/api/client.ts`, `src/api/endpoints.ts` | One typed function per endpoint (41). Cursor pagination only: `{data, meta.next_cursor}`. |
| Errors | `src/api/errors.ts` | Every failure becomes an `ApiError` with the server's `code`. Screens branch on `code`, never on message text. `Retry-After` is parsed into `retryAfter` seconds. |
| Session | `src/auth/SessionProvider.tsx` | Boot from stored token, `/me`, global handling of `unauthenticated` (sign out), `account_restricted` / `account_deactivated` / `maintenance` (full-screen blockers), `rate_limited` (non-blocking strip). |
| Config | `src/config/ConfigProvider.tsx` | `GET /config` on launch. `min_age`, `max_photos`, `daily_like_limit`, `max_distance_km` and `min_supported_version` are read from it everywhere. An outdated build gets an update screen. |

### Codes the app handles

`unauthenticated`, `forbidden`, `validation_failed`, `rate_limited`, `account_restricted`, `account_deactivated`, `premium_required`, `photo_limit_reached`, `maintenance`, `sms_unavailable`, `verification_attempts_exhausted`, the purchase codes `product_unknown`, `receipt_invalid`, `receipt_owned_elsewhere` and `store_unavailable`, plus `network` for anything that never reached the server.

### The token swap

A member who registers is `pending`, and their token can only read and write the profile and submit verification. When the profile crosses the server's completion threshold the account becomes `active`, **but the existing token keeps its reduced abilities**. So:

1. After any write that could change completion (profile, interests, city, photos) the app re-fetches `/me`.
2. If `account_status` went `pending → active`, the app signs in again silently with the credentials it kept in secure storage during onboarding, stores the new token and discards the credentials.
3. If that fails (password changed on the website, offline) it shows a re-authentication modal instead.

The same applies if the flip happened elsewhere: an `active` account with onboarding credentials still stored triggers the swap on the next `/me`.

## Screens

- **Auth** — sign in, register (age check mirrors `min_age` from `/config`; the server still decides).
- **Onboarding** — photos, about, interests, city, with the completion meter; the tabs are unreachable until the server says `active`.
- **Discover** — swipe card with drag, tilt, LIKE/PASS stamps and fling. The card only leaves after the server accepts the swipe; a 422 (daily limit), 403 or 429 brings it back with the reason. Reduce-motion turns off dragging and leaves the buttons. Match celebration with "Say hello".
- **Matches** and **Messages** — cursor-paginated. A thread is an inverted list polling every 6 s while on screen (there is no websocket). Sends are optimistic and a failure stays visible on the bubble with a retry; a 403 means the thread was closed by a block or unmatch and the composer is disabled.
- **Likes** — a free member gets the server's 403 with a count and sees "N people liked you"; a premium member sees who.
- **Profile** — edit, preferences (age range is always sent as a pair), verification (server-issued code, front camera, up to 10 MB, attempt count and exhaustion from the server), phone (texted code, `sms_unavailable` handled), blocked people, Premium (see below), account (sign out everywhere, delete with password, a permanent-deletion warning, and a warning that a store subscription is not cancelled by deleting).
- **Report / block** from any profile or thread, with an optional message anchor.

## Push notifications

The server sends through **Firebase Cloud Messaging HTTP v1** directly to a device token, so the app registers the **device** token (`getDevicePushTokenAsync`), not an Expo push token.

- **Android**: put the Firebase project's `google-services.json` in the project root and add `"googleServicesFile": "./google-services.json"` under `android` in `app.json`, then make a development build. The token is sent to `POST /devices/push-token` on launch and again whenever Firebase rotates it; it is removed with `DELETE /devices/push-token` on sign-out.
- **iOS**: `getDevicePushTokenAsync` returns an **APNs** token, which FCM v1 does not deliver to on its own. Delivering to iOS needs either the Firebase iOS SDK in the app (to exchange the APNs token for an FCM registration token) or an APNs sender on the server. Neither is in place yet; the iOS token is registered but nothing arrives.

Only two templates exist on the server: `match.new` (opens Matches) and `message.new` (opens the thread named in the notification's `link`).

## Known gaps

- **Profile option lists are mirrored.** There is no endpoint for the education / goal / drinking / smoking / children keys, so `src/lib/options.ts` copies them from the server's `ProfileOptions`. If the server's list changes, the app must be updated.
- **Feature labels are mirrored** too: `GET /plans` sends feature keys, and the Premium screen carries the wording for them.

## Premium and in-app purchase

The Premium screen buys through the store the app came from, with `expo-iap`. The rules are in `docs/premium-receipt-contract.md`; the app's part is:

1. `GET /plans` lists the plans on sale with their App Store / Google Play product ids; the app asks the store for those products and shows the store's localised prices.
2. A purchase is made with the member's uuid attached (`appAccountToken` / `obfuscatedAccountId`), so a notification can find the member even if the app never posts the receipt.
3. The store's transaction (`purchaseToken`: the StoreKit 2 JWS on iOS, the purchase token on Android) is posted to `POST /me/premium/receipt`. The server asks the store; the reply is a fresh `/me`. **Only after that 200 is the store transaction finished.** A 409 (`receipt_owned_elsewhere`) is finished too and shown; anything else leaves the transaction open so the store re-presents it and "Restore purchases" posts it again.
4. "Restore purchases" posts every available purchase for our product ids through the same endpoint.
5. "Manage subscription" opens the store's own subscription settings.
6. If `/me` says the plan came from the website or from staff, nothing is offered for sale, so a member never pays twice. The server would accept the purchase anyway and never shortens or downgrades an active plan; the app is where the needless charge is avoided.

Entitlement is always `is_premium` from `/me`. The app never decides it, never reads the store's own entitlement for that, and never trusts a purchase it has not had verified.
- **No offline cache.** Lists reload when opened; the token and the last known `/me` survive a cold offline start so the app opens, but nothing else is stored.
