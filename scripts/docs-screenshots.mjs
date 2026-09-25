/**
 * Re-take the documentation screenshots from the running app with seeded data.
 *
 *   php artisan serve --port=8000        (in another terminal)
 *   node scripts/docs-screenshots.mjs [baseUrl=http://127.0.0.1:8000]
 *
 * Run it against `php artisan serve`, not a sub-folder under Apache: Livewire's
 * script tag is root-relative, so under http://localhost/some/folder/ the
 * page renders but no dialog opens and the two dialog screenshots come out
 * empty. Needs Playwright with Chromium (npx playwright install chromium)
 * and the demo accounts from the seeders: admin@demo.test / password for the
 * console, jakayla.1@example.com / password for the member app. Writes PNGs
 * into docs/images/, overwriting the existing ones, at a fixed 1440×900
 * viewport so the pictures in the manual stay consistent between runs.
 */
import { createRequire } from 'node:module';
import { execFileSync, execSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const require = createRequire(import.meta.url);
let chromium;
try {
  ({ chromium } = require('playwright'));
} catch {
  // Playwright is not a project dependency; fall back to the global install.
  const globalRoot = execSync('npm root -g').toString().trim();
  ({ chromium } = require(path.join(globalRoot, 'playwright')));
}

const base = (process.argv[2] ?? 'http://127.0.0.1:8000').replace(/\/$/, '');
const out = path.join(path.dirname(fileURLToPath(import.meta.url)), '..', 'docs', 'images');

const root = path.join(out, '..', '..');
const idsCode = "echo json_encode(['member' => App\\Models\\AppUser::query()->where('is_premium', true)->whereHas('subscriptions')->value('uuid'), 'verification' => App\\Models\\Verification::query()->where('status', 'pending')->value('id'), 'case' => App\\Models\\ReportCase::query()->where('status', 'new')->value('id')]);";
const ids = JSON.parse(execFileSync('php', ['artisan', 'tinker', '--execute', idsCode], { cwd: root }).toString().trim().split('\n').pop());

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: 1 });
page.setDefaultTimeout(30_000);

async function shot(name, { fullPage = false } = {}) {
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(400); // Livewire settles, charts draw
  await page.screenshot({ path: path.join(out, `${name}.png`), fullPage });
  console.log('  ✓', name);
}

async function go(url) {
  await page.goto(`${base}${url}`);
  await page.waitForLoadState('networkidle');
}

async function login(url, email, password) {
  await go(url);
  await page.fill('input[type="email"]', email);
  await page.fill('input[type="password"]', password);
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type="submit"]')]);
}

console.log('Console');
await go('/admin/login');
await shot('admin-login');
await login('/admin/login', 'admin@demo.test', 'password');

const consolePages = {
  'admin-dashboard': '/admin',
  'admin-analytics-funnel': '/admin/analytics/funnel',
  'admin-users': '/admin/users',
  'admin-verifications': '/admin/verifications',
  'admin-cases': '/admin/cases',
  'admin-bans': '/admin/enforcement/bans',
  'admin-shadow-reviews': '/admin/enforcement/shadow-reviews',
  'admin-appeals': '/admin/appeals',
  'admin-conversations': '/admin/conversations',
  'admin-billing-payments': '/admin/billing/payments',
  'admin-billing-subscriptions': '/admin/billing/subscriptions',
  'admin-billing-plans': '/admin/billing/plans',
  'admin-billing-gateways': '/admin/billing/gateways',
  'admin-push': '/admin/notifications/push',
  'admin-sms': '/admin/notifications/sms',
  'admin-campaigns': '/admin/notifications',
  'admin-templates': '/admin/notifications/templates',
  'admin-masters-interests': '/admin/masters/interests',
  'admin-masters-questions': '/admin/masters/profile-questions',
  'admin-masters-categories': '/admin/masters/report-categories',
  'admin-masters-reasons': '/admin/masters/reasons',
  'admin-masters-locations': '/admin/masters/locations',
  'admin-staff': '/admin/staff',
  'admin-roles': '/admin/roles',
  'admin-audit': '/admin/audit',
  'admin-settings-general': '/admin/settings',
  'admin-settings-branding': '/admin/settings/branding',
  'admin-settings-mail': '/admin/settings/mail',
};

for (const [name, url] of Object.entries(consolePages)) {
  await go(url);
  await shot(name);
}

if (ids.member) {
  await go(`/admin/users/${ids.member}`);
  await shot('admin-user-detail');
  const give = page.getByRole('button', { name: /give plan|change plan/i }).first();
  if (await give.count()) {
    await give.click();
    await page.getByRole('heading', { name: /give plan|change plan/i }).waitFor({ state: 'visible' });
    await page.waitForTimeout(400);
    await shot('admin-user-plan-dialog');
  }
}
if (ids.verification) {
  await go(`/admin/verifications/${ids.verification}`);
  await shot('admin-verification-review');
}
if (ids.case) {
  await go(`/admin/cases/${ids.case}`);
  await shot('admin-case-detail');
}

// The plan form with the store product ids (new since the manual was written).
await go('/admin/billing/plans');
const editPlan = page.getByRole('button', { name: /^edit$/i }).first();
if (await editPlan.count()) {
  await editPlan.click();
  await page.getByRole('heading', { name: /edit plan|add plan/i }).waitFor({ state: 'visible' });
  await page.waitForTimeout(400);
  await shot('admin-billing-plan-form');
}

// The App Store gateway card, configure open, showing the notification address and the .p8 textarea.
// Cards and their Configure buttons are in the same document order, so the
// card's index among the headings is the button's index.
await go('/admin/billing/gateways');
const cardIndex = await page.evaluate(() => [...document.querySelectorAll('h3')].map((h) => h.textContent.trim()).indexOf('App Store'));
if (cardIndex >= 0) {
  const configure = page.getByRole('button', { name: /configure/i }).nth(cardIndex);
  if (await configure.count()) {
    await configure.click();
    await page.getByPlaceholder(/paste the whole file/i).first().waitFor({ state: 'visible' });
    await page.waitForTimeout(400);
    await page.locator('h3', { hasText: 'App Store' }).first().scrollIntoViewIfNeeded();
  }
  await shot('admin-billing-gateways-store', { fullPage: true });
}

// Subscriptions: the "Move to another account" form, when a store subscription exists.
await go('/admin/billing/subscriptions?source=apple&view=all');
const move = page.getByRole('button', { name: /move to another account/i }).first();
if (await move.count()) {
  await move.click();
  await page.waitForTimeout(500);
  await shot('admin-billing-subscriptions-move');
}

console.log('Member app');
await page.context().clearCookies();
await go('/');
await shot('site-home');
await login('/login', 'jakayla.1@example.com', 'password');
for (const [name, url] of Object.entries({
  'member-discover': '/app/discover',
  'member-matches': '/app/matches',
  'member-messages': '/app/messages',
  'member-profile': '/app/profile',
  'member-premium': '/app/premium',
  'member-account': '/app/account',
})) {
  await go(url);
  await shot(name);
}

await browser.close();
console.log('Done →', out);
