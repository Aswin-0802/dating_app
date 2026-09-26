# Dating Platform — Trust & Safety Console

A white-label dating product: a public website, a member web app, a mobile app,
an admin console and the REST API the apps use. The product carries no fixed
brand — the name, logo, colours, currency, prices and every word a member reads
are rows in the database, edited from the console.

This copy is set up for **India, with Tamil Nadu as the launch state**: every
Indian city of 15,000 people or more is loaded, Tamil Nadu is the only state
shown at sign-up, prices are in rupees, and the demo members live in Chennai,
Coimbatore, Madurai, Tiruchirappalli and Salem. [Adding a state, a city or a
whole country](#adding-a-country-state-or-city) is a console setting, not a
code change.

Laravel 12 · Livewire 3 · Tailwind v4 · MySQL · Sanctum · React Native (Expo)

---

## Documentation

| Document | For | Covers |
|---|---|---|
| [The product](docs/product.html) | Owners, then operators, then engineers | What it is; the whole flow on one chart with the mobile app as a lane; every app-to-console round trip; and, collapsed underneath, twelve engineering diagrams cited to file and line |
| [User manual](docs/user-manual.html) | The people running it | Every console screen with screenshots, the locations set-up, a go-live checklist and common questions |
| [API reference](docs/api.html) | Tools and app developers | Every `/api/v1` endpoint from [`docs/openapi.yaml`](docs/openapi.yaml), which `OpenApiSpecTest` keeps in step with the routes |
| [Audit, September 2026](docs/audit-2026-09.md) | Engineers | The findings of the last code audit and what was done about each |
| [Mobile app](dating_app_mobile/README.md) | App developers | How the app is built, run and checked; its in-app purchase [receipt contract](dating_app_mobile/docs/premium-receipt-contract.md) |

The three HTML documents open in a browser, offline, from the folder, and are
written to be printed (Ctrl+P → Save as PDF). `node scripts/docs-screenshots.mjs`
re-takes the screenshots from the running product; `node scripts/docs-verify.mjs`
opens every page from `file://` and checks that diagrams, images and printing
work. After editing `docs/openapi.yaml`, run `php artisan platform:embed-openapi`
so the offline viewer picks it up (the test fails otherwise).

---

## Getting it running on a new machine

The steps below are for Windows with XAMPP, which is how it is developed. On
macOS or Linux the same commands work with your own PHP, Composer, Node and
MySQL.

### What you need

| | | Where |
|---|---|---|
| XAMPP | PHP 8.2+ with `pdo_mysql`, `mbstring`, `openssl`, `gd`, `fileinfo`, `zip`, `bcmath`, `exif`; MariaDB/MySQL | <https://www.apachefriends.org> |
| Composer | 2.x | <https://getcomposer.org/download/> |
| Node.js | 20 or newer (builds the CSS and JS, runs the mobile app) | <https://nodejs.org> |
| Git | any recent version | <https://git-scm.com> |

Nothing else: no Redis, no Docker. In XAMPP's control panel, start **Apache**
(optional) and **MySQL** before you begin. All the PHP extensions above are on
by default in XAMPP's `php.ini`; `intl` is not needed.

### 1. The server

Open a terminal (PowerShell or Git Bash) and:

```bash
cd C:\xampp\htdocs
git clone https://github.com/Aswin-0802/dating_app.git
cd dating_app
copy .env.example .env          # cp .env.example .env on macOS/Linux
```

Create the two databases — the product's, and one the test suite is allowed
to wipe:

```bash
C:\xampp\mysql\bin\mysql.exe -u root -e "CREATE DATABASE dating_app CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
C:\xampp\mysql\bin\mysql.exe -u root -e "CREATE DATABASE dating_app_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

(or create them in phpMyAdmin). The defaults in `.env` are XAMPP's: database
`dating_app`, user `root`, no password. Change `DB_USERNAME` / `DB_PASSWORD`
if your MySQL account differs. Then:

```bash
composer setup    # composer install, app key, storage link, migrate + seed India, npm install, npm run build
composer dev      # web server on :8000 + queue worker + scheduler + Vite, all in one terminal
```

Open <http://localhost:8000>. The console is at <http://localhost:8000/admin/login>.

`composer setup` seeds the India install: reference data, the India geography
and 80 demo members in Tamil Nadu. It is the same as running
`php artisan platform:seed-country india --fresh` yourself, and either one
rebuilds the database from scratch whenever you want a clean copy. Seeding
downloads a portrait per demo member from Unsplash once (about a minute); set
`PLATFORM_SEED_PHOTOS=generated` in `.env` to draw placeholders locally instead,
or `none` to skip pictures.

<details>
<summary>If <code>composer setup</code> stops half way</summary>

Run the steps by hand and you will see which one failed:

```bash
composer install
php artisan key:generate
php artisan storage:link
php artisan platform:seed-country india --fresh
npm install
npm run build
php artisan serve
```

The usual causes: MySQL is not running, the two databases do not exist, or
`DB_USERNAME` / `DB_PASSWORD` in `.env` do not match your MySQL account.

</details>

<details>
<summary>Running it under Apache instead of <code>php artisan serve</code></summary>

The folder also works at <http://localhost/dating_app/public/> under XAMPP's
Apache with no configuration, which is convenient for a phone on the same
network (Apache binds to every interface). Set `APP_URL` in `.env` to that
address so generated links are right. The documentation screenshot script
needs `php artisan serve`, not Apache; nothing else cares.

</details>

### 2. The mobile app

The app lives in [`dating_app_mobile/`](dating_app_mobile/) and has its own
`package.json`. With the server running:

```bash
cd dating_app_mobile
copy .env.example .env      # then set EXPO_PUBLIC_API_URL, see below
npm install
npx expo start              # press a for Android emulator, i for iOS simulator, w for the browser
```

`EXPO_PUBLIC_API_URL` is the API address **including `/api/v1`**:

| Where the app runs | Value |
|---|---|
| Browser (`w`) or iOS simulator, server on `php artisan serve` | `http://127.0.0.1:8000/api/v1` |
| Android emulator, server on `php artisan serve` | `http://10.0.2.2:8000/api/v1` |
| Android emulator, server under XAMPP Apache | `http://10.0.2.2/dating_app/public/api/v1` |
| A real phone on the same Wi-Fi | `http://<your PC's LAN address>:8000/api/v1` |

Sign-in, onboarding, discovery, matches, messaging and verification run in
**Expo Go** on a phone (install it from the app store, scan the QR code). Push
notifications and in-app purchase need a development build; the
[app README](dating_app_mobile/README.md) explains which and how. Before a
device is involved, `npm run apicheck -- http://127.0.0.1:8000/api/v1
revathi.49@outlook.com password` exercises the whole API from Node, and
`npm run typecheck && npm run lint` must pass before a change is done.

### 3. Sign in

Every demo account, staff and member, uses the password **`password`**.

| Console (`/admin/login`) | Role | What it can do |
|---|---|---|
| `admin@demo.test` | Super Admin | Everything |
| `ops@demo.test` | Admin | Platform operations — but not message content, appeals, or safety policy |
| `lead@demo.test` | T&S Lead | Safety policy, the restricted queue, appeals |
| `senior1@demo.test`, `senior2@demo.test` | Senior Moderator | Full enforcement ladder, appeals |
| `mod1@demo.test` … `mod5@demo.test` | Moderator | Cases and enforcement up to suspension |
| `support1@demo.test`, `support2@demo.test` | Support | Member PII, no enforcement, no message content |
| `analyst1@demo.test`, `analyst2@demo.test` | Analyst | Aggregates only, no PII |

| Member (website `/login`, or the app) | Who |
|---|---|
| `revathi.49@outlook.com` | Revathi Shanmugam, Chennai — an active Premium member with matches and conversations |
| any address in Users → Members | The list shows every seeded member; all take the same password |

Signing in as more than one staff account is the quickest way to see how much
of the console is permission-shaped — restricted areas are absent from the
navigation rather than present and refused.

**Delete these accounts before you launch.** They are published here with a
known password; `php artisan platform:preflight` refuses to pass while any of
them still takes it.

### The database backup

[`database/backups/dating_app_india.sql`](database/backups/) is a dump of the
seeded India database — the same thing `composer setup` builds, kept so a copy
can be restored without running the seeders:

```bash
C:\xampp\mysql\bin\mysql.exe -u root dating_app < database\backups\dating_app_india.sql
```

It holds the demo accounts above with the same `password`. Member photos are
files under `storage/app/public/photos`, not rows, so a restored copy shows
initials where the pictures would be; run the seed command instead when you
want pictures. Take a fresh dump the same way when the data changes:

```bash
C:\xampp\mysql\bin\mysqldump.exe -u root --single-transaction --default-character-set=utf8mb4 dating_app > database\backups\dating_app_india.sql
```

### Keep the clock running

One entry drives everything with a date on it — restrictions that expire, plans
that end, renewal warnings, campaigns waiting to go out:

```bash
* * * * * cd /path/to/the-app && php artisan schedule:run >> /dev/null 2>&1
```

`composer dev` does this for you while you are developing. On Windows in
production, a Task Scheduler task running `php artisan schedule:run` every
minute does the same job. Email and push are queued, so production also needs
`php artisan queue:work --tries=3` under a process supervisor; see
[The queue worker](#the-queue-worker).

| URL | Who it is for |
|---|---|
| `/` | The public website: home, safety centre, terms, privacy |
| `/join`, `/login` | Member sign-up and sign-in |
| `/app/*` | The member web app: discover, matches, messages, profile, verification |
| `/admin/login` | Staff sign-in for the console |
| `/api/v1/*` | The mobile app's API |

---

## India and Tamil Nadu

`php artisan platform:seed-country india --fresh` produces the launch state:

| | |
|---|---|
| Countries | India, shown. No other country is loaded. |
| States | All 28 states and 8 union territories that have a city of 15,000+ people (35 rows). **Tamil Nadu is shown; the others are hidden** and one click away in Masters → Locations. |
| Cities | 3,739 Indian cities with coordinates, from GeoNames; 496 in Tamil Nadu are selectable at sign-up. |
| Currency and plans | Rupees. Plus ₹299 / ₹2,499 a year, Gold ₹599 / ₹4,999 a year, editable under Billing → Subscription plans. |
| Demo members | 80, in Chennai (about half), Coimbatore, Madurai, Tiruchirappalli, Salem, Tirunelveli and Vellore, with Tamil names, `+91` numbers and Indian email domains. |
| Demo staff | The accounts above, with Indian names. |

The pieces, so you can change any of them:

- [`database/data/geonames-cities.csv`](database/data/geonames-cities.csv) — the
  city dataset (see [Where the cities come from](#where-the-cities-come-from)).
- [`database/seeders/India/IndiaGeographySeeder.php`](database/seeders/India/IndiaGeographySeeder.php)
  — imports the Indian rows and sets the switches the first time it runs.
- [`database/seeders/India/IndiaProfile.php`](database/seeders/India/IndiaProfile.php)
  — the focus cities and their weights, the names, phone format and email
  domains the demo members are drawn from.
- [`database/seeders/India/IndiaDemoSeeder.php`](database/seeders/India/IndiaDemoSeeder.php)
  — rupees, plan prices, staff names, member count, then the generic demo pipeline.
- [`app/Console/Commands/SeedCountry.php`](app/Console/Commands/SeedCountry.php)
  — the command that strings them together; `--no-demo` gives geography and
  reference data only, for a production database.

### Adding a country, state or city

Where the product is offered is a console setting. Everything hidden is still
in the table, so showing it is one click; members already in a hidden place
keep it.

**Another Tamil Nadu city.** Masters → Locations → India → Tamil Nadu. Every
city of 15,000+ people is already there; use **Show at sign-up** on one that is
hidden, or **Add city** with its latitude and longitude for a smaller place
(the distance filter measures from them).

**Another Indian state.** Masters → Locations → India → the state → **Show at
sign-up**. Its cities are already loaded and selectable, so members there can
sign up immediately. Hide individual cities if you want a narrower footprint.

**Another country.** Three ways, from quickest to most complete:

1. *A handful of cities, by hand.* Masters → Locations → **Add country**
   (name, ISO code, dial code), then its states if it has any, then cities with
   coordinates. Fine for a pilot.
2. *The whole country from the dataset.* The committed CSV already holds 24
   countries (every city of 15,000+). Import one with

   ```bash
   php artisan platform:import-geography database/data/geonames-cities.csv --country=LK --dry-run
   php artisan platform:import-geography database/data/geonames-cities.csv --country=LK
   ```

   then show it in Masters → Locations. The importer matches what is already
   there, so it is safe to re-run, and it never changes a switch you have set.
   A country not in the CSV: refresh the dataset (below) with `--country=XX`,
   or write your own CSV in the same nine-column format — the command's help
   documents it.
3. *A launch like India's.* Copy `database/seeders/India/` to a folder for the
   new country, adjust the geography seeder (which country and states to show),
   the profile (cities, names, phones) and the demo seeder (currency, prices),
   and list the pair in `SeedCountry::COUNTRIES`. Then
   `php artisan platform:seed-country <name> --fresh`.

The user manual's [Masters → Locations](docs/user-manual.html#masters) section
has the same steps with screenshots for the console side.

### Where the cities come from

The city data is [GeoNames](https://www.geonames.org/) `cities15000`
(every place with a population of 15,000 or more), licensed
[CC BY 4.0](https://creativecommons.org/licenses/by/4.0/). The attribution
travels in the first line of the CSV and must stay on any copy you publish.
`php artisan platform:convert-geonames --fetch` downloads the current GeoNames
files (about 12 MB), filters them to the countries already in your database,
maps regions to your states, reports any it could not match, and rewrites the
CSV; commit the result. Without `--fetch` it converts files already under
`storage/app/geonames`.

### Seeding

`platform:seed-country india` builds 80 members. For a larger population the
worldwide demo dataset is still there — it is what the test suite and the
documentation counts were built on:

```bash
PLATFORM_SEED_SCALE=small php artisan db:seed --class="Database\Seeders\DemoDataSeeder"
```

| Value | Members | Roughly |
|---|---|---|
| `tiny` | 50 | ~15 seconds |
| `small` | 1,200 | ~90 seconds |
| `demo` | 12,000 | several minutes |
| `large` | 48,000 | considerably longer |

Without a country profile the demo members are drawn from every city in the
table, with whatever names Faker produces, so this is for load and for the
console's analytics rather than for showing the India product.

Below roughly 50 members the matching graph is the binding constraint: mutual
likes need a pool to draw from, and without matches there are no conversations,
no reports anchored to real evidence, and no cases. At small sizes rare states
are *dealt* rather than rolled — the seeder guarantees a minimum of each (two
overdue shadow bans, two restricted-queue verifications, two cases past SLA) so
no screen opens empty; run `VerifyInvariants` (under [Testing](#testing)) to see
them counted.

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
- **Locations** (Masters → Locations): the countries, states and cities members
  can choose from, each with its own switch. See
  [Adding a country, state or city](#adding-a-country-state-or-city).
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
- **Locations**: countries, states and cities, each with a show/hide switch. A
  hidden place cannot be chosen anywhere — sign-up, the website, the app — but
  members already there keep it. A city must name its state wherever the
  country has any; members choose a city grouped as "India · Tamil Nadu", and
  staff can filter and export members by state. The screen warns in red if a
  change leaves no selectable city at all.

Plans, interests, profile questions and locations need `edit_general_settings`.
Report categories and reasons need `edit_moderation_settings`. Notification
templates can be added and deleted as well; enforcement notices are protected.
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

For a production database, seed geography and reference data without the demo
members: `php artisan platform:seed-country india --fresh --no-demo`, then
create your own staff account under Staff and delete the demo ones.

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

### The mobile app

[`dating_app_mobile/`](dating_app_mobile/) is a React Native (Expo) client:
sign-in and sign-up, onboarding with a city type-ahead, discovery, matches,
messaging, profile and photos, selfie verification, reporting and blocking,
Premium through in-app purchase, and push notifications. It holds no business
rules — every limit and entitlement comes from `/config` and `/me` — and every
screen branches on the API's error codes rather than on message text. Three
screenshots are in the [product document](docs/product.html).

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
| Masters | Plans, interests, profile questions, report categories, enforcement reasons, locations |
| System | Mail/SMTP, payment and SMS gateways, delivery and payment logs, database backup |

### The API

41 endpoints under `/api/v1`, authenticated with Sanctum bearer tokens. Auth,
profile and photos, cities (type-ahead search), discovery deck, swipes, matches
and who-liked-you, conversations, messages, reports, blocks, verification, push
devices, phone verification, account deletion, and in-app purchase (plans and
receipts). Every list endpoint is cursor-paginated, and every endpoint calls
the same services as the website, so the two cannot drift. The full reference
is [docs/api.html](docs/api.html).

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

**Where the product is offered is one rule.** `App\Rules\SelectableCity` is the
only thing that decides whether a city can be saved, and every place a city is
written — sign-up, onboarding, the profile, the web app, the API — uses it. A
test scans the code for `city_id` validation sites so a new one cannot quietly
skip the rule. The importer never touches a switch, so an operator's decision
survives every data refresh.

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
php artisan test          # the whole suite, including the UAT walkthroughs in tests/Uat
./vendor/bin/pint --test  # formatting

# Checks the seeded database itself, rather than a fixture
php artisan db:seed --class="Database\Seeders\VerifyInvariants"
```

The suite needs the `dating_app_testing` database from the install steps; it
is wiped on every run, and `dating_app` is never touched.

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
never carries private fields, reports against one member fold into a single
case, a hidden city cannot be saved from anywhere, and the API reference cannot
drift from the routes.

For the mobile app: `npm run typecheck`, `npm run lint` and
`npm run apicheck` in `dating_app_mobile/`.
