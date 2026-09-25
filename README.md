# Dating Platform — Trust & Safety Console

A white-label dating product: a public website, a member app, an admin console and
the REST API its mobile clients use. The product carries no fixed brand — the
name, logo, colours, currency, prices and every word a member reads are rows in
the database, edited from the console.

Laravel 12 · Livewire 3 · Tailwind v4 · MySQL · Sanctum

---

## Documentation

Two written documents live in [`docs/`](docs/) and open in a browser:

| Document | For | Covers |
|---|---|---|
| [Project overview](docs/project-overview.html) | Owners and engineers | What the product is, how it is built, the decisions behind it, and what is deliberately not included |
| [User manual](docs/user-manual.html) | The people running it | Every console screen with screenshots, plus a go-live checklist and common questions |

Both are written to be printed: open one and press Ctrl+P → Save as PDF. The
screenshots come from the running product with seeded data, and are regenerated
rather than drawn.

---

## Getting it running

### What you need

| | |
|---|---|
| PHP | 8.2 or newer, with `pdo_mysql`, `mbstring`, `openssl`, `gd`, `fileinfo`, `zip`, `bcmath`, `exif` |
| Composer | 2.x |
| Node | 20 or newer (only to build the CSS and JS) |
| MySQL | 8.x (MariaDB 10.6+ works too) |

XAMPP ships with everything except Composer and Node. Nothing else is needed
to run it locally — no Redis, no Docker. A production deployment wants two
background processes as well: the scheduler and a queue worker, both described
under [Running it for real](#the-scheduler).

### Install

```bash
git clone https://github.com/Aswin-0802/dating_app.git
cd dating_app
cp .env.example .env

# Two databases: the app, and one the test suite is allowed to wipe.
mysql -u root -e "CREATE DATABASE dating_app CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -e "CREATE DATABASE dating_app_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

composer setup   # install, app key, storage link, migrate, seed, npm install, build
composer dev     # serve + queue + scheduler + vite, all in one terminal
```

On Windows the `mysql` command lives at `C:\xampp\mysql\bin\mysql.exe`, or you
can create the two databases in phpMyAdmin instead.

Then open <http://localhost:8000>.

<details>
<summary>If <code>composer setup</code> stops half way</summary>

Run it by hand and you will see which step failed:

```bash
composer install
php artisan key:generate
php artisan storage:link
php artisan migrate:fresh --seed
npm install && npm run build
php artisan serve
```

The usual causes: MySQL is not running, the two databases do not exist, or
`DB_USERNAME` / `DB_PASSWORD` in `.env` do not match your MySQL account.
Seeding downloads member photos once — set `PLATFORM_SEED_PHOTOS=generated` to
draw them locally instead, or `none` to skip them.

</details>

### Keep the clock running

One entry drives everything with a date on it — restrictions that expire, plans
that end, renewal warnings, campaigns waiting to go out:

```bash
* * * * * cd /path/to/the-app && php artisan schedule:run >> /dev/null 2>&1
```

`composer dev` does this for you while you are developing. On Windows in
production, a Task Scheduler task running `php artisan schedule:run` every
minute does the same job.

| URL | Who it is for |
|---|---|
| `/` | The public website: home, safety centre, terms, privacy |
| `/join`, `/login` | Member sign-up and sign-in |
| `/app/*` | The member web app: discover, matches, messages, profile, verification |
| `/admin/login` | Staff sign-in for the console |

Every seeded member signs in with the password `password`. Staff accounts:

| Account | Role | What it can do |
|---|---|---|
| `admin@demo.test` | Super Admin | Everything |
| `ops@demo.test` | Admin | Platform operations — but not message content, appeals, or safety policy |
| `lead@demo.test` | T&S Lead | Safety policy, the restricted queue, appeals |
| `senior1@demo.test` | Senior Moderator | Full enforcement ladder, appeals |
| `mod1@demo.test` | Moderator | Cases and enforcement up to suspension |
| `support1@demo.test` | Support | Member PII, no enforcement, no message content |
| `analyst1@demo.test` | Analyst | Aggregates only, no PII |

Password for all of them: `password`.

Signing in as more than one of these is the quickest way to see how much of the
console is permission-shaped — restricted areas are absent from the navigation
rather than present and refused. The [user manual](docs/user-manual.html) has a
table of exactly which menus each role gets.

**Delete these accounts before you launch.** They are published here with a
known password.

### Seeding

`PLATFORM_SEED_SCALE` controls the demo population:

| Value | Members | Rows | Roughly |
|---|---|---|---|
| `tiny` | 50 | ~2,500 | ~15 seconds — **the default** |
| `small` | 1,200 | 46,127 | ~90 seconds |
| `demo` | 12,000 | | several minutes |
| `large` | 48,000 | | considerably longer |

Row counts are measured, and `tiny` includes reference data (cities, permissions, settings). `demo` and `large` are left blank
because they are not run often enough to quote honestly.

`tiny` is the default deliberately. The dataset is here to exercise the console,
not to demo it, and 50 members rebuild fast enough that reseeding is not a
decision. Every queue, chart and filter still has rows in it at that size — run
the invariant check below to see them counted.

Below roughly 50 members the matching graph is the binding constraint: mutual
likes need a pool to draw from, and without matches there are no conversations,
no reports anchored to real evidence, and no cases. That is the floor, not the
seeder's.

One trade-off worth knowing about at `tiny`: rare states are *dealt* rather than
rolled. Shadow-banned is 1.2% of the account mix, which over 50 members is a
coin that comes up empty more often than not — and a category that rounds to
nobody takes a whole screen down with it. So the seeder guarantees a minimum of
each (two overdue shadow bans, two restricted-queue verifications, two cases
past SLA) instead of leaving it to chance. The proportions are therefore less
realistic at 50 members than at 12,000. `small` and up are unaffected: the
minimums are far below what those populations produce naturally.

`PLATFORM_SEED_PHOTOS` controls member photos. The default, `stock`, gives each
seeded member a real portrait from Unsplash (free licence), downloaded once
into `storage/app/public/photos/_stock` and reused; it falls back to
`generated` when offline. `generated` draws placeholders locally with GD, and
`none` skips images for the fastest rebuild. Replace demo photography with your
own before launch — see `public/images/site/CREDITS.md`.

---

## What is here

### Re-branding it

Settings → Branding changes the product name, admin and website logos,
favicon, sign-in image, brand colour, default theme, company details, website
copy, currency, app store links and social links. Every screen (console, sign-in
pages, website, member app) reads them through `App\Support\Branding`, so
nothing needs editing in a template. One colour generates the whole token set
for light and dark mode, with button text picked for contrast. SVG uploads
are refused because an SVG served from your own domain can run script.

The legal pages (`resources/views/site/legal.blade.php`) are template text.
Have them reviewed before launch; signed-in staff see a reminder on them.

Other settings worth knowing:

- **Currency** (Settings → Branding): US Dollar, Indian Rupee, Euro or British
  Pound. Every price and payment amount follows it; rupees use Indian digit
  grouping (₹1,23,456.00).
- **Locations** (Settings → Locations): the countries and cities members can
  choose from. Hide a country to take it off sign-up without affecting
  existing members.
- **Mail** (System → Mail): the SMTP server used for every email, including
  password resets. Set *Delivery* to "Send with SMTP" once the details are
  right; until then messages are written to the log.
- **Maintenance mode** (Settings → General): shows a maintenance page on the
  website, member app and API. The staff console keeps working.

### Masters

The lists the product is built from are managed under **Masters** in the
console, with no code changes:

- **Subscription plans**: name, monthly and yearly price, badge colour, the
  "Most popular" highlight, and what each plan unlocks (unlimited likes, seeing
  who liked you, a profile badge, priority support). The website pricing and
  the member Premium page read from here. A plan with members on it can be
  hidden but not deleted.
- **Interests**: add, rename, re-categorise, reorder and hide. An interest
  members have chosen can't be deleted.
- **Profile questions**: prompts and education levels can be added and removed.
  "Looking for", drinking, smoking and children can be reworded, reordered and
  hidden but not extended, because they are stored as fixed values. Members keep
  an answer that has since been hidden.
- **Report categories**: wording, order, severity and whether members see a
  category. Self-harm, underage and minor-safety reports are always available,
  and minor-safety reports are always Critical.
- **Enforcement reasons**: the name staff see, the policy clause and the
  statement sent to the member. Changes apply to new decisions only. Reasons the
  system records itself, such as appeal outcomes and expiry, cannot be turned
  off.

Countries, **states** and cities live in Settings → Locations: India ships with
all 28 states and 8 union territories, and a city must name its state wherever
the country has any. Members choose a city grouped as "India · Maharashtra",
and staff can filter and export members by state.

Plans, interests and profile questions need `edit_general_settings`. Report
categories and reasons need `edit_moderation_settings`. Notification templates
can now be added and deleted as well; enforcement notices are protected.
Every change is recorded in the audit log.

Password reset is available to staff (`/admin/forgot-password`) and members
(`/forgot-password`). New staff added under Staff are emailed a link to set
their own password.

### Subscriptions and payments

Plans are bought or given:

- **Checkout** (member app → Premium): Stripe or Razorpay, whichever is switched
  on in System → Payment gateways. With both on, the member picks at the point of
  paying. Stripe uses a hosted Checkout Session, Razorpay a Payment Link, so no
  card details ever reach this server. A payment counts only when the gateway
  confirms it — the return page asks the gateway directly, and the webhook is
  signature-checked — and fulfilment is locked so a return page plus two webhook
  retries still produce one subscription. Renewing early extends the time left
  rather than replacing it.
- **In the mobile app** (App Store / Google Play): the app buys through the
  store, then posts the store's transaction to `POST /api/v1/me/premium/receipt`.
  The server asks Apple or Google itself — the client's blob is never trusted —
  and the store's expiry is the plan's end date. Renewals, refunds and lapses
  arrive as store notifications at `webhooks/store/{apple,google}`, recorded in
  `gateway_events` and fulfilled through the same locked `Checkout::fulfil()`,
  so a notification delivered twice, or one whose fulfilment fails once, still
  produces exactly one subscription. Sandbox and TestFlight purchases are
  honoured whatever the test-mode switch says. Set the product ids on each plan
  under Billing → Subscription plans and the keys under System → Payment
  gateways. Design and rules: `dating_app_mobile/docs/premium-receipt-contract.md`.
- **By hand** (Users → a member → Give plan): for bank transfers and goodwill,
  with a note and an end date. Needs `edit_users`.

A member can hold plans from more than one source at once (a web plan until
December and a store plan renewing monthly). Nothing ever shortens or
downgrades an active plan: the member's tier is the best of their active
plans and the end date the latest, and staff can move a store subscription to
another account from Billing → Subscriptions.

Both write the same subscription history, shown on the member's Billing tab and
in their own "Your payments" list. Real payments write to the payment log.

To go live: create the account, paste the keys into System → Payment gateways
(they are stored encrypted and never shown again), register the webhook address
shown on that screen in the gateway's dashboard, paste the signing secret back,
then turn test mode off. Razorpay charges in INR; Stripe covers USD, EUR, GBP and
INR. The screen warns when the currency and the gateway disagree.

### Notifications

- **Push** (System → Push notifications) goes through Firebase Cloud Messaging
  v1. Upload the service account JSON from the Firebase console — the old
  "server key" API was switched off by Google — and, for browser notifications,
  the web config and VAPID key. Members turn notifications on from Account;
  mobile apps post their token to `/api/v1/devices/push-token`. A token Firebase
  reports as unregistered is deleted rather than retried for ever.
- **Renewal warnings**: push at 7, 3 and 1 days before a plan ends and an email
  at 3 days (both lists editable in Settings), plus an email the day it ends.
  Each reminder is recorded, so the hourly task never repeats one.
- **Campaigns** are sent by the server, not the browser: approve one and it goes
  out within five minutes, with a delivery-log row per member — including the
  ones with no device registered, so the totals match the audience.
- **SMS** (System → SMS gateways): Twilio, MSG91, Vonage or Textlocal. Used for
  phone verification — members verify a number from Account, apps through
  `/api/v1/phone/send-code`. Six digits, ten minutes, five guesses, one code at
  a time, and every text is written to the SMS log.

### The scheduler

One cron entry drives everything the clock owns — expiring restrictions and
plans, renewal warnings and campaign sending:

```
* * * * * cd /path/to/the-app && php artisan schedule:run >> /dev/null 2>&1
```

On Windows, a Task Scheduler task running `php artisan schedule:run` every
minute does the same. Without it the product still works — suspensions clear
themselves on the member's next request — but nothing else ends on time.
`php artisan platform:run-due-tasks` can always be run by hand.

### The queue worker

Email and push notifications are queued, so they need a worker running
alongside the scheduler:

```
php artisan queue:work --tries=3
```

Use a process supervisor (systemd, supervisord, or a Windows service) so it
restarts if it stops. **Without a worker, queued mail and push are never
delivered** — nothing fails visibly, the member simply never hears from you.
`composer dev` runs one for local work, and `QUEUE_CONNECTION=sync` in `.env`
falls back to sending inside the request if you would rather not run one.

### Before you go live

```
php artisan platform:preflight
```

Checks the things that expose real people: debug mode, demo accounts that
still take the published password, an empty `APP_KEY`, a localhost `APP_URL`,
and a mailer that swallows everything. It exits non-zero when any of those are
true, so it can sit in a deploy pipeline. Warnings (environment name, missing
queue worker, push switched off) do not block it.

### The website and member app

A public marketing site, plus a web version of the dating app: sign-up,
discover (one card at a time, arrow keys work), matches with "liked you" for
Premium, messaging, profile and photo editing, selfie verification, reporting,
blocking, account settings, and a restricted-account page with an appeal
form. It uses the same services as the mobile API (`app/Services/Members`), so
the website and the apps enforce identical rules. Members and staff use
separate guards over separate tables.

Things worth knowing:

- **Uploaded photos are re-encoded**, which strips EXIF data. Phone photos
  often carry GPS coordinates.
- **When a member's city runs out of people, the web deck widens** to people
  further away and says so. The mobile API keeps its city-only deck.
- **Deleting an account does not cancel a store subscription.** Only the
  member can, in the App Store or Google Play; they are told so on the deletion
  screen, and a renewal that still arrives is recorded and ignored.

### The console

| Area | Notes |
|---|---|
| Dashboard | Funnel, per-city gender balance, attention concentration, cold start, retention by verification |
| Users | 11 filters, bulk actions, per-member detail with photos and enforcement history |
| Verification | Queue sorted by deadline; side-by-side comparator; enumerated rejection reasons; restricted minor-safety queue |
| Matches | Derived from real mutual likes, with engagement state |
| Conversations | Metadata only by default; revealing content is gated and logged |
| Cases | Reports aggregated by subject, evidence in context, the enforcement ladder |
| Enforcement | Bans, the shadow-ban review queue, shared devices, blocks |
| Appeals | Routed away from the original decider |
| Notifications | Campaigns needing second-person approval, templates, delivery logs |
| Staff, Roles, Audit, Settings | Permission matrix, immutable audit trail, operator-tunable settings |
| System | Mail/SMTP, payment and SMS gateways, delivery and payment logs, database backup |

### The API

41 endpoints under `/api/v1`, authenticated with Sanctum bearer tokens. Auth,
profile and photos, discovery deck, swipes, matches and who-liked-you,
conversations, messages, reports, blocks, verification, push devices, phone
verification, account deletion, and in-app purchase (plans and receipts). Every list endpoint is cursor-paginated, and
every endpoint calls the same services as the website, so the two cannot drift.

---

## Decisions worth knowing about

These are the ones that would be surprising to inherit without explanation.

**Staff and members are separate tables.** `users` is staff; `app_users` is
dating-app members. They share almost no columns, and keeping them apart means
no policy ever has to ask which kind of account it is looking at.

**A shadow ban must be invisible to the member and visible to staff.** The API
reports a shadow-banned account as `active`, keeps its token abilities, and
serves it a normal deck — the restriction lives only in discovery ranking. In
the console it is an ordinary status with a mandatory review date, and
`/admin/enforcement/shadow-reviews` exists specifically to stop one becoming a
permanent punishment nobody revisits. A shadow ban without a review date is
refused by `ApplyEnforcement`.

**Message content is gated structurally, not procedurally.** `body` is in
`Message::$hidden`, so no controller, view or JSON response can leak it by
accident. Reading it means going through `MessageRevealService`, which requires
the permission, a reason from a fixed list and a written justification, and
writes two immutable records.

**Moderation actions, activity logs and message access logs are append-only.**
Updates and deletes throw. A reversal is recorded as a new action linked to the
original rather than an edit of it.

**Every risk score stores its own factors.** The breakdown the UI renders is
those stored rows, never recomputed — so it always sums to the number on the
badge, including for a score calculated months ago under weights since retuned.

**Appeals are never decided by the original decider.** Enforced in the model,
the assignment action, and the seeder. That last one matters: a seeder allowed
to violate the rule would let the test guarding it pass against bad data.

**Settings and System are separate menus.** Settings is product and safety
policy — SLA windows, risk weights, matching rules. System is infrastructure —
SMTP, payment and SMS providers. The people who own them are rarely the same,
and mixing them puts "switch the payment provider" next to "change what counts
as a ban".

**Integration credentials are write-only.** Stored encrypted, never rendered
back into the page, and a blank field on save keeps what is already there — so
toggling a provider on cannot silently wipe its keys.

**Admin and T&S Lead are deliberately different.** Admin runs the platform but
cannot read message content, decide appeals, or change moderation policy. That
separation is what makes the audit log meaningful.

**Tests run against MySQL, not SQLite.** The analytics use `DATE_ADD`, the
severity ranking uses `FIELD()`, the risk engine uses `JSON_EXTRACT`, and the
seeders use multi-table `UPDATE JOIN`s. Testing on SQLite would skip all of it.

**Dynamic Tailwind classes are never assembled at runtime.** Tailwind v4 scans
source files for literal strings, so `"bg-risk-{$band}"` is purged and the badge
renders unstyled — silently. Every status helper returns complete class strings
from a `match`.

---

## Design system

Tokens live in `resources/css/theme.css`. Each semantic colour carries four:
`--x`, `--x-foreground` (legible on the solid fill), `--x-subtle` (the tint used
by status badges) and `--x-subtle-foreground` (legible on the tint). The last is
easy to forget: pairing a tinted background with the solid fill's foreground
looks fine in light mode and goes dark-on-dark the moment the theme flips.

The sidebar has its own `--sidebar-*` namespace, which is how the rail stays dark
plum-navy against a light content canvas.

Theme and sidebar state persist in cookies excluded from encryption, so Blade
renders both correctly on the first byte. Resolving them client-side is what
causes the flash on hard refresh.

`/admin/_kitchen-sink` renders every primitive in every variant. It is the
fastest way to catch a token regression, and the place to look before building a
new screen.

---

## Testing

```bash
php artisan test          # 148 tests, including the UAT suites in tests/Uat
./vendor/bin/pint --test  # formatting

# Checks the seeded database itself, rather than a fixture
php artisan db:seed --class="Database\Seeders\VerifyInvariants"
```

`VerifyInvariants` runs against whatever is actually in the database. It asserts
eight structural rules — canonical match ordering, every shadow ban carrying a
review date, no appeal assigned to its original decider, report evidence
belonging to the reported member, the enforcement mirror agreeing with `bans`,
risk factors summing to their stored score — and then counts eight queues that
must not be empty. The second half is what catches a scale change that leaves a
screen with nothing to render, which no unit test would notice.

The suite concentrates on the things that would be expensive to get wrong: the
enforcement ladder writes its three records together, moderation actions cannot
be edited, a shadow ban is undetectable through the API, another member's profile
never carries private fields, and reports against one member fold into a single
case.
