# Audit findings — fix list

Audit date: 2026-09-22 · Laravel 12.69.2 · Livewire 3 · MySQL (`dating_app`, seeded `tiny`/50 members)

Every item below was **reproduced against the running application**, not inferred from reading code.
Write-probes ran inside transactions that were rolled back; no data was changed and no file was edited.

**How to use this file:** work top-down. Each item states the root cause, the exact files, the fix,
and a verification step. Tick the box when the verification passes.

At the time of writing: `php artisan test` = **231 passed**. All findings below coexist with a green suite —
the "Missing test" lines say why.

---

## Priority 0 — Safety. Fix these together; they share one root cause.

The product's stated architecture is that member rules live in `app/Services/Members/*` so
"the website and the apps enforce identical rules". Three guards were instead written into Livewire
components, so the **mobile API does not enforce them at all** and the website enforces them only on
some paths.

### [x] P0-1 — Blocking someone does not stop them messaging or reading the thread

* **Severity:** Critical
* **Reproduced:** A blocks B → B sends a message → **HTTP 201, delivered**. B still reads the full
  thread and it is still listed for B.
  ```
  After A blocks B -> match.status=blocked  conversation.status=open
  RESULT: BLOCKED USER B SUCCESSFULLY MESSAGED A
  B can still read the thread: true
  Thread still listed for blocked B: true
  ```
* **Affected paths:** the whole mobile API (`POST /api/v1/blocks`), **and** the website when blocking
  from a person's profile page (the most common route, from Discover / Matches).
* **Root cause:** `SafetyActions::block()` (`app/Services/Members/SafetyActions.php:96`) sets
  `matches.status='blocked'` but never touches `conversations.status`.
  `MessageSender::send()` checks participation only. The conversation is closed in exactly one place —
  `app/Livewire/Member/Messages.php:82` (`afterSafetyAction`) — which is a UI component reached only when
  blocking from inside the messages screen. `app/Livewire/Member/Person.php:87` only redirects.
* **Fix:**
  1. In `SafetyActions::block()`, inside the existing transaction, also close the conversation for any
     match between the two members (`conversations.status = 'closed'`).
  2. In `MessageSender::send()`, add a block check in **both** directions before writing.
  3. Delete the now-redundant close in `Member\Messages::afterSafetyAction()` so there is one owner.
* **Verify:** block via `POST /api/v1/blocks`, then `POST /api/v1/conversations/{uuid}/messages` as the
  blocked member → expect **403**, not 201.
* **Missing test:** `tests/Feature/Api/MobileFlowTest.php::test_blocking_also_unmatches` asserts only
  `matches.status='blocked'`. Add: *and the blocked member can no longer send or read.*

### [x] P0-2 — The API ignores `conversations.status` entirely

* **Severity:** Critical (root cause of P0-1 and P0-3)
* **Reproduced:** force `conversations.status='closed'` (exactly what the website does), then post via the API:
  ```
  POST message to a CLOSED conversation via API -> HTTP 201
  GET  messages of a CLOSED conversation via API -> HTTP 200
  ```
* **Root cause:** `Member\Messages::send()` checks `status === 'open'`; `MessageController::store()` does not.
  The guard is in the component, not the shared service.
* **Fix:** `abort_unless($conversation->status === 'open', 403, 'This conversation is closed.')` at the top of
  `MessageSender::send()`. Consider the same for reads in `MessageController::index()`.
* **Verify:** the probe above must return 403 / 403.

### [x] P0-3 — Unmatching via the API leaves the conversation fully open

* **Severity:** High
* **Reproduced:** `DELETE /api/v1/matches/{uuid}` as A → B sends a message → **delivered**. The thread is
  still listed for A, the member who unmatched.
* **Root cause:** `ProfileController::unmatch()` (`app/Http/Controllers/Api/V1/ProfileController.php:137`)
  writes only the match row. The website's `Member\Matches::unmatch()` (`app/Livewire/Member/Matches.php:40`)
  *does* close the conversation.
* **Fix:** extract an `Unmatch` action/service used by both the API controller and the Livewire component.
* **Verify:** unmatch via API, then post as the other party → expect 403.
* **Missing test:** `tests/Uat/UatMemberTest.php::test_m05_unmatch_closes_the_conversation` passes today
  because it only drives the Livewire path. **The suite asserts this exact invariant and still misses the
  violation.** Add the API equivalent.

---

## Priority 1 — Money, privacy, availability

### [x] P1-1 — A paid order is permanently stranded if fulfilment fails once

* **Severity:** High (revenue)
* **Reproduced:**
  ```
  order status before retry: pending
  gateway RETRY -> handleWebhook returned false, order status now: pending
  *** PAID ORDER NEVER FULFILLED — retry treated as duplicate ***
  ```
* **Root cause:** `Checkout::handleWebhook()` (`app/Services/Payments/Checkout.php:243`) writes the
  `gateway_events` dedup row **before** fulfilling and **outside** the transaction that `fulfil()` rolls back.
  On the gateway's retry, `firstOrCreate` finds the row, `wasRecentlyCreated` is false, and it returns early.
  The code comment asserts the opposite of the actual behaviour:
  *"Recorded before acting: if fulfilment then fails, the retry is not mistaken for a duplicate and skipped."*
* **Fix:** add a `processed_at` (or `status`) column to `gateway_events`; only a row with `processed_at`
  counts as a duplicate. Unprocessed rows must be retryable.
* **Do not regress:** the happy path is currently correct — replaying an identical event produced exactly
  **1** subscription. Keep that.
* **Verify:** make `fulfil()` throw once, replay the event, assert the order reaches `paid`.

### [x] P1-2 — Member email addresses leak to staff roles denied PII

* **Severity:** High (privacy / least privilege)
* **Reproduced:** signed in as `analyst1@demo.test` (Analyst: has `users`, lacks `view_user_pii`) →
  `/admin/users` returned **17 member email addresses on one page**. Searching by email also works.
* **Root cause:** `resources/views/livewire/users/index.blade.php:220` renders `:meta="$user->email"`
  with no gate. The same data **is** correctly gated in the other two places —
  `resources/views/livewire/users/show.blade.php:63` (commented *"an analyst has no business reading emails"*)
  and the CSV export (`app/Livewire/Users/Index.php:274`). Additionally `AppUser::scopeSearch` allows
  ungated roles to search by `email` and `phone`.
* **Affected roles:** Analyst and Moderator — 7 of the 14 seeded staff.
* **Fix:** wrap the `:meta` value in `@can('view_user_pii')`; restrict the email/phone branches of
  `scopeSearch` to the same permission.
* **Verify:** re-run as Analyst; zero member emails in the HTML.

### [x] P1-3 — Any staff GET can exhaust 512 MB of memory (admin DoS)

* **Severity:** High (availability)
* **Reproduced:** `GET /admin/users?page=999999`
  ```
  FatalError — Allowed memory size of 536870912 bytes exhausted (tried to allocate 207634432 bytes)
  ```
  Also fires on `/admin/audit`.
* **Root cause:** unbounded `page` reaching the paginator's URL-window builder via
  `WithDataTable` / `WithPagination`; no clamp to `lastPage()`.
* **Fix:** clamp `page` to `[1, lastPage]` in `app/Livewire/Concerns/WithDataTable.php`.
* **Verify:** the URL above returns 200 with the last page, not a 500.

### [x] P1-4 — Malformed table URLs 500 across the entire admin

* **Severity:** Medium
* **Reproduced:**
  ```
  /admin/users?perPage=abc                             -> 500  TypeError: Cannot assign string
                                                                to property $perPage of type int
  /admin/users?sortField=created_at&sortDirection=DROP -> 500  (Laravel orderBy rejects it)
  /admin/users?perPage=100000                          -> 200  (accepted, unbounded)
  ```
  Also 500s on `/admin/cases`, `/admin/verifications`, `/admin/audit`.
* **Root cause:** in `app/Livewire/Concerns/WithDataTable.php`, `#[Url] public int $perPage` takes an
  unvalidated string from the query string; `$sortDirection` is never whitelisted (unlike `$sortField`,
  which **is** correctly whitelisted — `sortField=password` is safely ignored); `$perPage` is never clamped
  to `perPageOptions()`.
* **Why it matters:** this trait backs every admin table. Its own docblock says
  *"a change here lands everywhere at once, and so does a mistake."* It also undermines the shareable-URL
  design the trait exists for — a stale bookmark shows a stack trace.
* **Fix:** coerce + clamp `perPage` to `perPageOptions()`, whitelist `sortDirection` to `asc|desc`.
* **Verify:** all three URLs above return 200.

---

## Priority 2 — Integrity and correctness

### [x] P2-1 — Report evidence can be attached to an unrelated member

* **Severity:** Medium (evidence integrity + privacy)
* **Reproduced:** as a legitimate participant in a conversation with user 13, filed a report against
  **user 1** passing user 13's `message_id`:
  ```
  report created, reported_app_user_id=1, message_id=1, conversation_id=1
  Evidence message belongs to user 13 but is filed against user 1 => MISMATCH CONFIRMED
  ```
* **Root cause:** `SafetyActions::report()` (`app/Services/Members/SafetyActions.php:60`) resolves the
  message by UUID with **no check** that the reporter participates in it or that its sender is the
  reported member.
* **Impact:** a moderator opens a case against an innocent member and, via `MessageRevealService`, reads a
  private message from a conversation that member was never part of. The README lists *"report evidence
  belonging to the reported member"* as a guaranteed invariant; `VerifyInvariants` checks it for seeded
  data, but nothing enforces it at write time.
* **Fix:** require the message's conversation to include the reporter **and** its sender to be the reported
  member; otherwise drop the evidence link (do not reject the report — the report itself is still valid).

### [x] P2-2 — Partial preference update creates an impossible age range

* **Severity:** Medium
* **Reproduced:** `PATCH /api/v1/me/preferences {"age_min":60}` → stored `age_min=60, age_max=28`.
  The deck then returns 200 with zero candidates, permanently.
* **The rule is one-sided:**
  ```
  {"age_max":20}              -> rejected ("must be >= age min")
  {"age_min":60}              -> PASSES          <-- the gap
  {"age_min":60,"age_max":20} -> rejected
  ```
* **Root cause:** `ProfileController::updatePreferences()` gives `age_max` a `gte:age_min` rule but gives
  `age_min` no `lte:age_max`; with `sometimes`, the cross-field rule is skipped when only `age_min` is sent.
* **Fix:** validate the **merged** (stored + incoming) pair, not just the request payload.

### [x] P2-3 — The verification gesture code is not server-issued

* **Severity:** Medium
* **Reproduced:** `GET /api/v1/verification/gesture` returns a code that is **never stored or bound to the
  member**. `VerificationSubmission::submit()` persists whatever `gesture_code` the client sends.
* **Impact:** the anti-replay property the design rests on is void. The docblock says the code
  *"is what stops somebody uploading a photograph of a photograph"* — an attacker picks their own code and
  prepares a matching image. Reviewers then compare the photo against an attacker-chosen value and see a match.
  **A verified badge is not currently trustworthy.**
* **Fix:** persist the issued code against the member with a short TTL (cache or a column); reject any
  submission whose code does not match the outstanding one.

### [x] P2-4 — Distance-based matching does not exist

* **Severity:** Medium (core matching behaviour)
* **Reproduced:** `max_distance_km` is collected on both clients, validated (`min:1,max:500`), stored,
  returned by `PreferenceResource`, and advertised by `GET /api/v1/config` — but
  `DiscoveryDeck::query()` filters by **`city_id` only**. The only haversine code in the app
  (`app/Support/ProfileOptions.php:162-172`) is a display helper for "X km away".
  All 50 members have `last_latitude`/`last_longitude` populated, so the data is there.
* **Impact:** a member who sets 5 km and a member who sets 500 km get identical decks. The setting is
  decorative, which is a visible product lie on a dating app.
* **Fix:** either apply a bounding-box + haversine filter in `DiscoveryDeck` when coordinates exist
  (falling back to `city_id`), **or** remove the control from both clients and from `/api/v1/config`.
  Do not leave it collected-but-ignored.

### [x] P2-5 — Lifting a ban promotes `pending` / `deactivated` accounts to `active`

* **Severity:** Low–Medium
* **Root cause:** `ApplyEnforcement::recomputeMirror()` sets `account_status = Active` unconditionally when
  no bans remain. A member who was `pending` (incomplete profile) or self-`deactivated` before a suspension
  is silently promoted into the deck when it expires.
* **Fix:** restore the pre-ban status, or recompute from profile completion rather than defaulting to Active.

### [ ] P2-6 — Unlimited free "rewind" by re-swiping

> **Reverted — not a defect. Free rewind is intended product behaviour.**
>
> This was fixed in error and the fix has been backed out: `record()` uses
> `updateOrCreate` again, so a second swipe replaces the first and a pass may
> become a like. The owner confirmed re-swiping is deliberate, so the box stays
> unticked rather than closed.
>
> `tests/Feature/Api/MobileFlowTest.php::test_re_swiping_the_same_person_replaces_the_earlier_decision`
> now pins the intended behaviour: one row per pair, updated rather than added to.
>
> Nothing else from that change was reverted. The `lockForUpdate` on the member
> row and the daily-like count running inside the transaction are P2-8 and remain.

* **Severity:** Low–Medium (**confirm this is not intentional before fixing**)
* **Reproduced:**
  ```
  A passed on B       -> stored action='pass'
  A re-swiped as like -> stored action='like'   (free unlimited rewind: YES)
  ```
* **Root cause:** `SwipeRecorder::record()` uses `updateOrCreate` on the `(app_user_id,
  target_app_user_id)` unique key. The deck hides already-swiped people, but the API accepts any `target_id`.
* **Note:** duplicate-match prevention is **correct** — a second like produced no second match row. Keep that.
* **Fix (if rewind should be paid):** reject a swipe when a row already exists unless the member has the
  rewind entitlement.

### [x] P2-7 — `messages_count` is incremented with read-then-write

* **Severity:** Low
* **Root cause:** `MessageSender::send()` does `'messages_count' => $conversation->messages_count + 1` on
  both `conversations` and `matches`. Concurrent sends lose counts.
* **Fix:** use `increment()`.

### [x] P2-8 — Daily like limit is race-prone

`SwipeRecorder::enforceDailyLikeLimit()` counts then inserts with no lock; parallel requests exceed the cap.
Low exploit value, cheap to fix with an atomic per-day counter.

---

## Priority 3 — Hardening and hygiene

### [x] P3-1 — No security headers on any response
No CSP, `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy` or HSTS. The admin console is
framable — clickjacking on destructive bulk actions. Add a middleware.

### [x] P3-2 — `EnsureStaffIsActive` is not persistent for Livewire
`app/Providers/AppServiceProvider.php` registers **only** `EnsureMemberCanUseApp` as persistent Livewire
middleware. Livewire update requests do not re-run route middleware, so a staff account suspended
mid-session keeps acting through Livewire until a full page load — exactly what
`EnsureStaffIsActive`'s docblock says it prevents. Add it to `Livewire::addPersistentMiddleware`.
*(Enforcement actions themselves are safe — `AppliesEnforcement::openStep` and `applyStepTo` both call
`$this->authorize()`. This is the gap around them.)*

### [x] P3-3 — 11 permissions are granted, displayed, and checked nowhere
`delete_users`, `impersonate_users`, `moderate_photos`, `unmatch_users`, `close_conversations`,
`remove_messages`, `merge_cases`, `automation_rules`, `manage_api_tokens`, `login_log`, `export_analytics`.

Several imply capabilities that do not exist at all. An admin who **denies** "Remove messages" to a
moderator reasonably believes they have restricted something real — they have not. This is the most
misleading thing in the console. **Either implement them or remove them from the seeder and the matrix.**

### [x] P3-4 — Event-driven notifications are configured but never sent
Six member templates are editable in the console and referenced by nothing:
`match.new`, `message.new`, `likes.waiting`, `verification.prompt`, `appeal.decided`, `match.silent_nudge`.

`MemberNotifier` is called from two places, both billing. `push_logs` = **41 campaign / 0 event-driven**.
"You have a new match" and "You have a new message" are never delivered — the core re-engagement loop.
Wire them from `SwipeRecorder` and `MessageSender`, or mark unwired templates inactive so operators are not
editing dead text.

### [x] P3-5 — Advertised photo limit contradicts the enforced one
`GET /api/v1/config` reports `max_photos: 9`; `MemberPhotoStore::MAX_PHOTOS = 6`. A client honouring the
config fails on the 7th upload. Make one the source of truth.

### [x] P3-6 — Missing indexes (measured on the live schema)
```
app_users.birthdate      NO  <- used on EVERY deck query (scopeAgeBetween)
app_users.premium_until  NO  <- full scan in Subscriptions::expireDue()
messages.read_at         NO  <- unread counts
conversations.status     NO  <- conversation list filter
swipes.action            NO  <- daily like-limit count
app_users.is_premium     NO  <- member-list premium filter
```

### [x] P3-7 — `ORDER BY RAND()` on the discovery deck
`EXPLAIN` on the live deck query:
```
type=range  rows=41  extra=Using index condition; Using where; Using temporary; Using filesort
```
MySQL materialises and sorts the whole candidate set on the hottest query in the product. 19 ms at 50
members; a full temp-table sort at the documented `large` scale (48,000). Replace with a seeded random
offset, a random-key range scan, or a precomputed candidate pool.

### [x] P3-8 — Nothing is queued
`QUEUE_CONNECTION=database`, `jobs` table empty, nothing implements `ShouldQueue`.
`WithBulkActions::shouldQueueBulkAction()` exists and is never called. Email and FCM sends are synchronous
inside web requests. Queue campaign sends, bulk enforcement, email and push.

### [x] P3-9 — Renewal email sent inside an uncommitted transaction
`Subscriptions::grant()` emails after *its own* transaction, but it is called from inside
`Checkout::fulfil()`'s outer transaction — so the email goes out before commit. Its comment
(*"a member should never be told about a plan that a later rollback took away"*) does not hold on the
checkout path. Dispatch after commit (`DB::afterCommit` / queued job).

### [x] P3-10 — `deal_breakers` is a dead column
`preferences.deal_breakers` exists and is cast to `array` in `app/Models/Preference.php:21`. Nothing writes
or reads it. Drop it or implement it.

### [x] P3-11 — Go-live checklist
`APP_DEBUG=true`, `APP_ENV=local`, and 14 seeded staff accounts with the password `password`
(the README already flags these). Confirm before launch.

---

## Not bugs — product decisions for the owner

These are **gaps, not defects**. They need a decision, not a patch.

| Gap | Note |
|---|---|
| **No email verification** | `email_verified_at` exists and is seeded, but there is no `MustVerifyEmail`, no route, no send, no check. Phone verification **is** built — phone-only verification is a legitimate choice for a dating app. Decide, then either wire email verification or drop the column. |
| **No account deletion** | Deactivation only. GDPR Art. 17 (right to erasure) has no implementation, and `delete_users` is a dead permission. Required if you operate in the EU/UK. |
| **No photo upload on the mobile API** | Photos are website-only. A mobile-only member can never add a photo — yet a photo is one of the checklist items behind the `pending -> active` promotion. |
| **No trial, downgrade, refund or proration** | The admin UI implies subscription lifecycle management that does not exist. |
| **No "likes you" API endpoint** | Built on the website (Premium-gated), absent from the API. |

---

## Verified sound — do not "fix" these

Re-checked and working correctly. Regression risk if touched.

* **Authorization at the API boundary.** Cross-user conversation read/write, foreign-match unmatch, and
  self-swipe all correctly refused (403/422). Member token against `/admin/*` → 401. Web session does not
  authenticate the API.
* **Mass assignment** — `is_premium`, `account_status`, `risk_score` all rejected on both register and
  `PATCH /me`, despite permissive `$guarded`.
* **No SQL injection.** The three interpolated `selectRaw` sites (`MetricRollup:293`,
  `Staff/Performance:64`, `Enforcement/Devices:73`) interpolate only hardcoded constants and enum values.
* **No XSS.** Only two `{!! !!}` sites, both `Branding::styleTag()`, which validates hex with a fallback.
* **File upload is strong.** Every image is decoded and re-encoded through GD (destroys polyglots/SVG/PHP
  payloads, strips EXIF GPS); filenames are server-generated UUIDs; verification selfies are on a private
  disk behind a signed URL **and** a permission check **and** an audit log.
* **Rate limiting works** — the admin login throttle actually blocked a rapid 7th login during testing.
  The auth limiter keys on IP **and** email, which is the right call.
* **Webhook signature verification and happy-path idempotency** (`lockForUpdate` + `gateway_events`).
* **Admin role matrix** — 33 routes x 7 roles tested over real HTTP; matches the documented design.
  The restricted-minor queue correctly 404s rather than 403s.
* **Message privacy design** — `body` in `$hidden` plus `MessageRevealService` (permission + fixed reason +
  >=10-char justification + two immutable records) is genuinely well built.
* **Enforcement ladder** — one transaction for action + ban + mirror; append-only; shadow ban refused
  without a review date.
* **Eager loading** — no N+1 found. `DiscoveryDeck` = 2 queries; `Member\Messages` deliberately batches
  latest-message and unread counts.
* **`ProfileCompletion::refresh()` is correctly called** after photo upload, photo delete and interest save
  on the website (`Member/Profile.php:174, 207, 290`). Only the API's `syncInterests` omits it.

---

## Suggested order of work

1. **P0-1 / P0-2 / P0-3 as one change** — move the three guards into `MessageSender` + `SafetyActions`,
   add the missing negative tests. One root cause, one fix, highest real-world impact.
2. **P1-2** (one line, stops a live privacy leak), then **P1-3 / P1-4** (one file, `WithDataTable`).
3. **P1-1** (revenue).
4. **P2-1 / P2-2 / P2-3**.
5. **P2-4** — decide: implement distance matching or remove the control.
6. P3 hardening, then the product decisions above.

**The architectural lesson:** every P0 is the same mistake — *a rule enforced in a Livewire component
instead of the shared service*. The codebase already states this principle and follows it almost
everywhere. Pull those three guards down into the services and this entire class of API/website divergence
disappears.
