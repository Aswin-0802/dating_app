/**
 * Take the mobile-app screenshots in docs/images from the Expo web build.
 *
 *   php artisan serve --port=8000                                   (terminal 1)
 *   cd dating_app_mobile && npx expo start --web --port 8081        (terminal 2, with EXPO_PUBLIC_API_URL=http://127.0.0.1:8000/api/v1 in .env)
 *   node scripts/app-screenshots.mjs [appUrl=http://localhost:8081] [email=revathi.49@outlook.com] [password=password]
 *
 * Renders at a phone size (390×844, 2×) so the pictures read as a phone even
 * though the web target draws them. Three screens: sign-in, Discover, a
 * conversation. Needs Playwright with Chromium, like docs-screenshots.mjs.
 */
import { createRequire } from 'node:module';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const require = createRequire(import.meta.url);
const { chromium } = require('playwright');

const [appUrl = 'http://localhost:8081', email = 'revathi.49@outlook.com', password = 'password'] = process.argv.slice(2);
const out = path.join(path.dirname(fileURLToPath(import.meta.url)), '..', 'docs', 'images');

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true });
page.setDefaultTimeout(120_000);

async function shot(name) {
  await page.waitForTimeout(1200); // images and the last query settle
  await page.screenshot({ path: path.join(out, `${name}.png`) });
  console.log('  ✓', name);
}

// First load bundles the app; give Metro time.
await page.goto(appUrl, { waitUntil: 'networkidle', timeout: 240_000 });
await page.getByText('Sign in to keep going.').waitFor({ state: 'visible' });
await page.getByLabel('Email').fill(email);
await page.getByLabel('Password').fill(password);
await shot('app-sign-in');

await page.getByRole('button', { name: /^sign in$/i }).click();
await page.waitForURL(/discover/, { timeout: 60_000 });
await page.waitForLoadState('networkidle');
await page.waitForTimeout(2000); // the deck's first card and its photo
// A card without a photo makes a poor picture; pass on it (this records a
// swipe for the demo member, which a reseed clears).
for (let i = 0; i < 6 && (await page.getByText('No photo yet').count()); i++) {
  await page.getByRole('button', { name: /^pass$/i }).click();
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(1500);
}
await shot('app-discover');

await page.goto(`${appUrl}/messages`, { waitUntil: 'networkidle' });
await page.waitForTimeout(1500);
// Rows are Pressables with a button role; the first one is the newest conversation.
const thread = page.getByRole('button').filter({ hasText: /Last message|No messages yet/ }).first();
await thread.waitFor({ state: 'visible' });
await thread.click();
await page.waitForURL(/thread/, { timeout: 60_000 });
await page.getByPlaceholder('Write a message').waitFor({ state: 'visible' }); // the composer appears once messages have loaded
await page.waitForTimeout(1500);
await shot('app-thread');

await browser.close();
console.log('Done →', out);
