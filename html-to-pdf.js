#!/usr/bin/env node
/**
 * html-to-pdf.js — render a finished PageSpeed HTML report to PDF
 * -----------------------------------------------------------------------------
 * The PHP layer already produces a self-contained HTML report. This helper opens
 * that file in headless Chromium (Playwright) and prints it to PDF — a
 * pixel-faithful copy of the on-screen report.
 *
 * Before printing it expands the collapsible detail rows used by the report.
 * Print CSS then keeps the Recommended Improvements section focused on pages
 * with Lighthouse category scores below the Good threshold.
 *
 * Usage:  node html-to-pdf.js <input.html> <output.pdf>
 */
const { chromium } = require('playwright');
const fs = require('fs');
const os = require('os');
const path = require('path');
const { pathToFileURL } = require('url');

// Resilient launch: default headless build, then a full-build fallback for
// locked-down Linux servers. Override with PSI_CHROME_PATH if needed.
async function launchBrowser() {
  const baseArgs = ['--no-sandbox', '--disable-dev-shm-usage', '--disable-gpu'];

  if (process.env.PSI_CHROME_PATH) {
    return chromium.launch({
      headless: true,
      executablePath: process.env.PSI_CHROME_PATH,
      args: baseArgs.concat('--headless=new'),
    });
  }

  try {
    return await chromium.launch({ headless: true, args: baseArgs });
  } catch (firstErr) {
    process.stderr.write(
      `   ⚠  Default Chromium launch failed (${firstErr.message.split('\n')[0]}); ` +
      'trying full-build fallback …\n'
    );
  }

  const cacheRoot = path.join(os.homedir(), '.cache', 'ms-playwright');
  let exe = null;
  try {
    for (const dir of fs.readdirSync(cacheRoot)) {
      if (/^chromium-\d+$/.test(dir)) {
        for (const candidate of [
          path.join(cacheRoot, dir, 'chrome-linux', 'chrome'),
          path.join(cacheRoot, dir, 'chrome-mac', 'Chromium.app', 'Contents', 'MacOS', 'Chromium'),
          path.join(cacheRoot, dir, 'chrome-win', 'chrome.exe'),
        ]) {
          if (fs.existsSync(candidate)) { exe = candidate; break; }
        }
      }
      if (exe) break;
    }
  } catch (_) { /* cache dir not found */ }

  if (!exe) throw new Error('Chromium launch failed and no full build found. Run: npx playwright install chromium');
  return chromium.launch({
    headless: true,
    executablePath: exe,
    args: baseArgs.concat('--headless=new'),
  });
}

async function main() {
  const input = process.argv[2];
  const output = process.argv[3];
  if (!input || !output) {
    process.stderr.write('usage: node html-to-pdf.js <input.html> <output.pdf>\n');
    process.exit(2);
  }
  if (!fs.existsSync(input)) {
    process.stderr.write(`❌  Input HTML not found: ${input}\n`);
    process.exit(2);
  }

  const browser = await launchBrowser();
  try {
    const page = await browser.newPage();
    await page.goto(pathToFileURL(path.resolve(input)).href, { waitUntil: 'load' });

    // Reveal every collapsed per-page detail row (and any legacy <details>) so
    // nothing is hidden in print.
    await page.evaluate(() => {
      document.querySelectorAll('details').forEach(d => { d.open = true; });
      document.querySelectorAll('tr.detail-row[hidden]')
        .forEach(d => d.removeAttribute('hidden'));
    });

    await page.pdf({
      path: output,
      format: 'A4',
      landscape: true,          // wide score/metric tables need the horizontal room
      scale: 0.82,              // shrink slightly so all columns fit within the page
      printBackground: true,
      margin: { top: '12mm', bottom: '16mm', left: '10mm', right: '10mm' },
      displayHeaderFooter: true,
      headerTemplate: '<span></span>',
      footerTemplate:
        '<div style="font-size:8px;color:#64748b;width:100%;padding:0 10mm;' +
        'display:flex;justify-content:space-between;">' +
        '<span>⚡ PageSpeed Bulk Report</span>' +
        '<span>Page <span class="pageNumber"></span> / <span class="totalPages"></span></span>' +
        '</div>',
    });
  } finally {
    await browser.close();
  }
}

main().catch((e) => {
  process.stderr.write('PDF generation failed: ' + (e && e.stack ? e.stack : e) + '\n');
  process.exit(1);
});
