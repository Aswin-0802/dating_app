/**
 * Open every HTML document in docs/ from file://, exactly as a reader would,
 * and report what a reader would see: diagrams that failed to render, images
 * that did not load, and whether the page prints. Writes a PDF of each into
 * the directory given as the first argument (default: the system temp dir).
 *
 *   node scripts/docs-verify.mjs [outDir]
 */
import { createRequire } from 'node:module';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const require = createRequire(import.meta.url);
const { chromium } = require('playwright');

const docs = path.join(path.dirname(fileURLToPath(import.meta.url)), '..', 'docs');
const outDir = process.argv[2] ?? os.tmpdir();
const files = ['index.html', 'product.html', 'user-manual.html', 'api.html'];

const browser = await chromium.launch();
let failed = false;

for (const file of files) {
  const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
  const consoleErrors = [];
  page.on('pageerror', (e) => consoleErrors.push(String(e)));
  page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(m.text()); });

  await page.goto(pathToFileURL(path.join(docs, file)).href);
  await page.waitForLoadState('load');
  // Mermaid and Redoc render after load; give them a moment, then wait for quiet.
  await page.waitForTimeout(file === 'api.html' ? 4000 : 2500);

  const report = await page.evaluate(() => ({
    title: document.title,
    diagrams: document.querySelectorAll('script[type="text/x-mermaid"]').length,
    svgs: document.querySelectorAll('.render svg').length,
    errors: [...document.querySelectorAll('.err')].map((e) => e.textContent.trim().slice(0, 160)),
    brokenImages: [...document.images].filter((i) => !i.complete || i.naturalWidth === 0).map((i) => i.getAttribute('src')),
    redocOperations: document.querySelectorAll('[data-section-id^="operation/"]').length,
    text: document.body.innerText.length,
  }));

  const ok = report.errors.length === 0 && report.brokenImages.length === 0 && report.svgs === report.diagrams
    && (file !== 'api.html' || report.redocOperations >= 41);
  failed ||= !ok;

  console.log(`${ok ? '✓' : '✗'} ${file} — "${report.title}" · ${report.svgs}/${report.diagrams} diagrams · ${report.redocOperations} API operations · ${report.text.toLocaleString()} chars`);
  for (const e of report.errors) console.log('    diagram error:', e);
  for (const i of report.brokenImages) console.log('    broken image:', i);
  for (const e of consoleErrors.slice(0, 5)) console.log('    console:', e.slice(0, 160));

  await page.emulateMedia({ media: 'print' });
  const pdf = path.join(outDir, file.replace(/\.html$/, '.pdf'));
  await page.pdf({ path: pdf, format: 'A4', printBackground: true, margin: { top: '12mm', bottom: '12mm', left: '10mm', right: '10mm' } });
  console.log(`    printed → ${pdf} (${(fs.statSync(pdf).size / 1024).toFixed(0)} KB)`);
  await page.close();
}

await browser.close();
process.exit(failed ? 1 : 0);
