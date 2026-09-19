# UAT report — Veyra dating app

**Date:** 19 September 2026
**Build:** `36e28ed` (main)
**Environment:** Windows / XAMPP, PHP 8.2.12, MySQL, Chrome (Playwright), demo data at `tiny` scale (50 members, 14 staff)

## Scope

| Area | How it was tested |
|---|---|
| Staff console, all 7 roles | 46 scripted cases (Livewire / HTTP) + browser sweep of every sidebar page for every role |
| Public website | Scripted cases + browser at desktop and phone width |
| Member web app | 34 scripted cases + browser flows (sign-up, sign-in, discover, match, message) |
| Mobile REST API | Scripted cases + direct HTTP calls |
| Reference parity | Every admin route in `C:\xampp\htdocs\Laravel` (Materialize) compared with this app |

## Summary

| | Count |
|---|---|
| Scripted cases run | 80 |
| Passed | 62 |
| Failed (real defects) | 16 |
| Failed (test-harness artefacts, re-verified by hand and passing) | 2 |
| Browser page loads (7 roles + member + guest) | 127, with no JavaScript errors, server errors, broken images or sideways scrolling |
| **Defects raised** | **26** (1 Critical, 8 High, 10 Medium, 7 Low), 20 in the first pass and 6 found while fixing |
| **Defects fixed and retested** | **26** |

### What works well

- Sign-in for staff and members: validation, throttling (7th staff attempt → 429, 6th member attempt → "Too many attempts"), suspended/deactivated/banned handling, no account enumeration.
- RBAC: every sidebar link opens for every role; restricted areas return 403/404 for the wrong role; the restricted minor-safety queue is invisible (404) to moderators.
- Verification: approve updates the member, reject needs a reason, minor-suspected submissions cannot be approved.
- Cases: claim, note-required suspension, moderators refused permanent bans.
- Appeals: the original decider can never be assigned; decisions need a written reason.
- Message privacy: reading content needs the permission, a reason and a justification, and writes an access log; conversation lists never contain message bodies.
- Member app: duplicate/invalid/under-age sign-ups refused, markup escaped in messages and bios, outsiders get 404 on other people's conversations, blocked people are hidden, photo and verification limits enforced, email/password changes need the current password.
- API: consistent JSON error envelope, 401 without a token, website sessions never authenticate API calls.

---

## Defects

Severity: **Critical** = data loss or security; **High** = a core flow is unusable; **Medium** = wrong behaviour with a workaround; **Low** = polish.

### UAT-01 — Critical — Running the tests wipes the development database

**Steps**
1. Run `php artisan optimize` (or `config:cache`).
2. Run `php artisan test`.

**Expected:** tests run against `dating_app_testing`.
**Actual:** with config cached, the `phpunit.xml` settings are ignored; tests run with `APP_ENV=local` against `dating_app`, and `RefreshDatabase` drops and re-creates every table. All development data is lost.
**Note:** found during this UAT: the config cache appeared at 16:31, and the first test run reset the dev database, which was then reseeded.

### UAT-02 — High — Enforcement buttons on the member page do nothing

**Steps:** Admin → Users → open any member → click **Warn**, or **Actions → Shadow ban / Suspend / Ban permanently**, or **Lift** on an active restriction.
**Expected:** a form for reason, note and duration, then the action is applied.
**Actual:** nothing happens. The buttons have no handler. Enforcement is only possible from a Case.

### UAT-03 — High — Member list Export and bulk/row actions do nothing

**Steps:** Admin → Users → click **Export**; or tick members → **Warn / Suspend / Export**; or a row's **⋯ → Warn / Suspend**.
**Expected:** CSV download / action applied to the selected members.
**Actual:** nothing happens.

### UAT-04 — High — No "forgot password" for staff or members

**Steps:** Open `/admin/login` or `/login`.
**Expected:** a "Forgot password?" link that emails a reset link (the reference admin has `password/reset`).
**Actual:** no reset exists anywhere. A member or staff user who forgets their password is locked out permanently.

### UAT-05 — High — Staff cannot change their own password

**Steps:** Admin → avatar → Profile.
**Expected:** a change-password form (reference: `change-password`).
**Actual:** the profile page is read-only.

### UAT-06 — High — Staff cannot be added or edited

**Steps:** Admin → Staff.
**Expected:** add a staff member, edit name/email/role, remove (reference: `users/create`, `users/edit`, `users/delete`).
**Actual:** only suspend/reactivate exists. New staff can only be added through the database.

### UAT-07 — High — Push campaigns cannot be created

**Steps:** Admin → Notifications → Campaigns.
**Expected:** a "New campaign" flow.
**Actual:** only seeded campaigns exist; they can be approved or cancelled but no new one can be made.

### UAT-08 — Medium — Roles cannot be created

**Steps:** Admin → Roles.
**Expected:** create a custom role (reference: `roles/add-role`).
**Actual:** only the seven built-in roles can be edited.

### UAT-09 — Medium — Campaign status rules are not enforced

**Steps:** cancel a campaign, then click **Approve**; or approve/cancel a campaign already marked sent.
**Expected:** a cancelled campaign cannot be approved; a sent campaign cannot be cancelled.
**Actual:** both succeed.

### UAT-10 — Medium — Numeric settings accept nonsense values

**Steps:** Settings → Matching → set "Daily like limit (free)" to `-5` → Save.
**Expected:** a validation error.
**Actual:** saved. The same applies to every numeric setting (SLA hours, thresholds, limits).

### UAT-11 — Medium — "Maintenance mode" does nothing

**Steps:** Settings → General → Maintenance mode on → Save → open `/`.
**Expected:** members and visitors see a maintenance page; staff can still work.
**Actual:** nothing changes. The setting is not read anywhere.

### UAT-12 — Medium — The same person can be reported repeatedly

**Steps:** as a member, report the same match three times in a row.
**Expected:** duplicates within a short window are ignored or refused, so one person cannot inflate a case.
**Actual:** three reports are filed, and the case's report count rises each time.

### UAT-13 — Medium — Dashboard Refresh and Export do nothing

**Steps:** Admin → Dashboard → click **Refresh** or **Export**.
**Actual:** nothing happens.

### UAT-14 — Medium — "Gender balance by city" chart is blank

**Steps:** Admin → Dashboard with fewer than 20 members in every city.
**Expected:** a message such as "Not enough members in any city yet."
**Actual:** an empty chart with only a legend.

### UAT-15 — Medium — No country/city management

**Expected:** manage countries and cities (reference: `masters/country`, `masters/state`, `masters/city`).
**Actual:** they can only be changed by editing the seeder.

### UAT-16 — Low — Demo passwords shown on sign-in pages

**Steps:** open `/admin/login` or `/login` on a local install.
**Actual:** "Demo accounts … password" / "Local demo … password" boxes are shown. This is unwanted text on a product screen.

### UAT-17 — Low — Display names accept HTML

**Steps:** sign up with the name `<b>Robin</b>`.
**Expected:** refused, since only letters, spaces, apostrophes and hyphens make sense.
**Actual:** accepted. It is escaped on output (no security risk) but shows as literal markup.

### UAT-18 — Low — Error pages are unbranded

**Steps:** open `/any-missing-page`.
**Actual:** Laravel's default 404 page, with no logo and no way home. The same applies to 403, 419, 500 and 503.

### UAT-19 — Low — Dashboard figures and copy read as unprofessional

**Actual:** "Report rate / 1k matches: 2500" on a 12-match sample; card descriptions such as "The earliest quality alarm there is" and "however good the product is" read as notes, not product text.

### UAT-20 — Low — Member photos are drawn placeholders

**Actual:** profiles use generated gradient silhouettes, and the website uses illustrations. You asked for real photography.

---

### Found while fixing

| ID | Severity | Defect |
|---|---|---|
| UAT-21 | High | Member page tabs Matches, Reports, Enforcement, Devices and Timeline showed "tab is not built yet". |
| UAT-22 | High | SMTP settings saved in System → Mail were only used for the test message; real email (password resets) ignored them. The SMTP password was stored in plain text despite the form saying "encrypted". |
| UAT-23 | Medium | Confirmation messages after admin actions ("Campaign approved", "Appeal assigned" and 16 more) only appeared after the next page load. |
| UAT-24 | Medium | Every setting lookup ran a database schema query; branded pages made dozens per view. |
| UAT-25 | Low | Leftover unused dashboard view containing "Analytics are not built yet" text. |
| UAT-26 | Low | Currency was a free-text symbol defaulting to £; no USD/INR choice and no correct rupee formatting. |

---

## Retest — 19 September 2026 (after fixes)

All 26 defects fixed and retested. The scripted suites now live in the project as `tests/Uat` and run with every `php artisan test`: **148 tests, 775 assertions, all passing**; the browser check of the changed screens found no JavaScript or server errors.

| ID | Status | Fix |
|---|---|---|
| UAT-01 | Fixed | Tests refuse to start unless the database name ends in `_testing`, with a message to run `php artisan config:clear`. |
| UAT-02 | Fixed | Warn, Limit features, Shadow ban, Suspend, Ban and Lift work from the member page through the same enforcement rules as cases. |
| UAT-03 | Fixed | Row and bulk Warn/Suspend work (bulk capped at 500); Export downloads a CSV — contact details only for staff allowed to see them, and every export is audited. |
| UAT-04 | Fixed | "Forgot password?" for staff (`/admin/forgot-password`) and members (`/forgot-password`); separate token tables; the response never reveals whether an account exists. |
| UAT-05 | Fixed | My profile: edit name and job title, change password (signs out other sessions). |
| UAT-06 | Fixed | Add, edit, remove staff and change their role. New staff are emailed a link to set their own password. Nobody can change their own role, remove themselves, or remove the last Super Admin; only a Super Admin can grant Super Admin. |
| UAT-07 | Fixed | New campaign: audience with live recipient count, title and message with limits and preview, link target, optional send time. Saved as a draft for someone else to approve. |
| UAT-08 | Fixed | Create roles (optionally copying another role's permissions) and delete custom roles. Built-in roles and roles still in use are protected. |
| UAT-09 | Fixed | Only draft or scheduled campaigns can be approved or cancelled. |
| UAT-10 | Fixed | Every numeric setting has a sensible range and shows its error under the field. |
| UAT-11 | Fixed | Maintenance mode shows a branded 503 on the website and member app and a JSON 503 on the API; the staff console keeps working. |
| UAT-12 | Fixed | One report per person per reporter per day, on the website and the API. |
| UAT-13 | Fixed | Dashboard Refresh recalculates figures; Export downloads the headline figures and funnel as CSV. |
| UAT-14 | Fixed | The city chart shows "Not enough members yet" until a city has 20 members. |
| UAT-15 | Fixed | Settings → Locations: add, edit, hide and delete countries and cities; places in use cannot be deleted; hidden countries disappear from sign-up. |
| UAT-16 | Fixed | Demo credentials removed from both sign-in pages. |
| UAT-17 | Fixed | Names must start with a letter and contain only letters, spaces, apostrophes, hyphens and dots (website and API). |
| UAT-18 | Fixed | Branded 403, 404, 419, 429, 500 and 503 pages. |
| UAT-19 | Fixed | Report rate shows "—" until there are 100 matches; dashboard and admin copy rewritten as plain product text. |
| UAT-20 | Fixed | Real photography (Unsplash licence) on the website and sign-in pages; seeded members get real portraits, and verification selfies are made from the member's own portrait. |
| UAT-21 | Fixed | All five tabs built. |
| UAT-22 | Fixed | System → Mail now drives all outgoing email, with a Delivery setting (send via SMTP, or write to the log); the password is encrypted. |
| UAT-23 | Fixed | Messages from any admin action now appear immediately as a notification. |
| UAT-24 | Fixed | Settings are read once per request. |
| UAT-25 | Fixed | Removed. |
| UAT-26 | Fixed | Settings → Branding → Currency: US Dollar, Indian Rupee, Euro, British Pound (default USD), with Indian digit grouping for rupees. |

### Not built (reference parity, out of scope for this round)

Lock screen, cache-clear button, test SMS, social-login / FCM / Google Maps keys, deleting email templates and staff notifications, and payment checkout.

---

## Reference parity (`C:\xampp\htdocs\Laravel`)

| Reference feature | Here | Status |
|---|---|---|
| Dashboard | Dashboard + 4 analytics pages | ✔ |
| Users list / create / edit / delete / status | Staff: add, edit, role, suspend, remove | ✔ (UAT-06 fixed) |
| Roles list / add / edit | Create, edit, delete custom roles | ✔ (UAT-08 fixed) |
| Permissions list / role-has-permission | Permission matrix | ✔ |
| General settings (logos, favicon, colour, company, social) | Settings → Branding | ✔ |
| Social-login keys, FCM, Google Maps, web-notification toggles | — | ✘ not present |
| SMTP settings + test email | System → Mail (applies to all mail, with test send) | ✔ |
| SMS settings + toggle | System → SMS gateways | ✔ (no test SMS) |
| Payment settings | System → Payment gateways | ✔ |
| Email templates list / create / edit / delete | Templates edit only | ◑ no create/delete |
| Logs: activity, email, login, payment, push, SMS | Audit log + System → Delivery logs + Notifications → Logs | ✔ |
| Database backup | System → Backup | ✔ |
| Masters: country / state / city | Settings → Locations (countries and cities; no states) | ✔ (UAT-15 fixed) |
| Change password / forgot password / lock screen | Profile password change; forgot password for staff and members | ◑ no lock screen |
| Notifications: read / read all / delete | Bell: read / read all | ◑ no delete |
| Cache clear | — | ✘ not present |
| Checkout | — | ✘ no payment checkout (known) |

---

## Test assets

The scripted acceptance suites are in `tests/Uat` (`UatAdminTest.php`, `UatMemberTest.php`) and run as part of `php artisan test`, or alone with `php artisan test --testsuite=Uat`.
