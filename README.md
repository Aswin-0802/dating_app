# Veyra — Trust & Safety Console

Admin console for a dating platform, plus the REST API its mobile clients use.

Laravel 12 · Livewire 3 · Tailwind v4 · MySQL · Sanctum

---

## Getting it running

Requires PHP 8.2+, Composer, Node 20+, and MySQL. On XAMPP everything below
works as-is.

```bash
git clone <repo> && cd dating_app
cp .env.example .env

# Create the databases (the second one is for the test suite).
mysql -u root -e "CREATE DATABASE dating_app CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -e "CREATE DATABASE dating_app_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

composer setup   # install, key, storage link, migrate, seed, npm install, build
composer dev     # serve + queue + scheduler + vite
```

Then open <http://localhost:8000>.

| URL | Who it is for |
|---|---|
| `/` | The public website: home, safety centre, terms, privacy |
| `/join`, `/login` | Member sign-up and sign-in |
| `/app/*` | The member web app: discover, matches, messages, profile, verification |
| `/admin/login` | Staff sign-in for the console |

Every seeded member signs in with the password `password`. Staff accounts:

| Account | Role | What it can do |
|---|---|---|
| `admin@veyra.test` | Super Admin | Everything |
| `ops@veyra.test` | Admin | Platform operations — but not message content, appeals, or safety policy |
| `lead@veyra.test` | T&S Lead | Safety policy, the restricted queue, appeals |
| `senior1@veyra.test` | Senior Moderator | Full enforcement ladder, appeals |
| `mod1@veyra.test` | Moderator | Cases and enforcement up to suspension |
| `support1@veyra.test` | Support | Member PII, no enforcement, no message content |
| `analyst1@veyra.test` | Analyst | Aggregates only, no PII |

Password for all of them: `password`.

Signing in as more than one of these is the quickest way to see how much of the
console is permission-shaped — restricted areas are absent from the navigation
rather than present and refused.

### Seeding

`VEYRA_SEED_SCALE` controls the demo population:

| Value | Members | Rows | Roughly |
|---|---|---|---|
| `tiny` | 50 | 1,784 | ~15 seconds — **the default** |
| `small` | 1,200 | 46,127 | ~90 seconds |
| `demo` | 12,000 | | several minutes |
| `large` | 48,000 | | considerably longer |

Row counts are measured, not estimated. `demo` and `large` are left blank
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

`VEYRA_SEED_PHOTOS=none` skips image generation entirely and falls back to
initials tiles, which makes a rebuild much faster. The default, `generated`,
draws placeholder imagery locally with GD and needs no network access.

---

## What is here

### Re-branding it

Settings → Branding changes the product name, admin and website logos,
favicon, sign-in image, brand colour, default theme, company details, website
copy, prices, app store links and social links. Every screen (console, sign-in
pages, website, member app) reads them through `App\Support\Branding`, so
nothing needs editing in a template. One colour generates the whole token set
for light and dark mode, with button text picked for contrast. SVG uploads
are refused because an SVG served from your own domain can run script.

The legal pages (`resources/views/site/legal.blade.php`) are template text.
Have them reviewed before launch; signed-in staff see a reminder on them.

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
- **There is no card checkout.** Premium shows the plans and sends upgrades to
  the store apps or support. The payment gateway settings under System hold
  credentials, but nothing charges through them yet.

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

28 endpoints under `/api/v1`, authenticated with Sanctum bearer tokens. Auth,
profile, discovery deck, swipes, matches, conversations, messages, reports,
blocks and verification. Every list endpoint is cursor-paginated.

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
php artisan test          # 60 tests
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
