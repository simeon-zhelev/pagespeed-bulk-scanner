#!/usr/bin/env node
/**
 * lighthouse-runner.js — local Lighthouse engine for pagespeed_scanner.php
 * (used by --engine=local; the default --engine=psi never touches Node).
 *
 * Reads URLs (one per line) on stdin, audits each with Lighthouse against a
 * locally launched headless Chromium, and emits one NDJSON line per URL:
 *
 *   { "url": "...", "strategy": "mobile", "lighthouseResult": { ... } }
 *   { "url": "...", "strategy": "mobile", "error": "..." }
 *
 * lighthouseResult is the exact same schema the PageSpeed Insights API wraps,
 * so the PHP parser/report pipeline is identical for both engines.
 *
 * URLs are audited SEQUENTIALLY on purpose: parallel Lighthouse runs contend
 * for CPU and skew the performance numbers (TBT/LCP).
 *
 * Browser resolution order: $CHROME_PATH → Playwright's managed Chromium →
 * whatever chrome-launcher finds on the system.
 */

'use strict';

const readline = require('readline');

function parseArgs(argv) {
  const args = {};
  for (const a of argv) {
    const m = a.match(/^--([^=]+)(?:=(.*))?$/);
    if (m) args[m[1]] = m[2] ?? true;
  }
  return args;
}

async function main() {
  const args = parseArgs(process.argv.slice(2));
  const strategy = args.strategy === 'desktop' ? 'desktop' : 'mobile';

  let lighthouse, chromeLauncher;
  try {
    lighthouse = (await import('lighthouse')).default;
    chromeLauncher = await import('chrome-launcher');
  } catch (e) {
    console.error('Node dependencies missing. In ' + __dirname + ' run:\n  npm install');
    process.exit(2);
  }

  // Desktop preset shipped with Lighthouse (same one PSI uses); mobile is the default config.
  let config;
  if (strategy === 'desktop') {
    try {
      config = (await import('lighthouse/core/config/desktop-config.js')).default;
    } catch {
      config = {
        extends: 'lighthouse:default',
        settings: {
          formFactor: 'desktop',
          screenEmulation: { mobile: false, width: 1350, height: 940, deviceScaleFactor: 1, disabled: false },
          throttling: { rttMs: 40, throughputKbps: 10240, cpuSlowdownMultiplier: 1, requestLatencyMs: 0, downloadThroughputKbps: 0, uploadThroughputKbps: 0 },
        },
      };
    }
  }

  let chromePath = process.env.CHROME_PATH || null;
  if (!chromePath) {
    try { chromePath = require('playwright').chromium.executablePath(); } catch { /* fall through */ }
  }

  const chrome = await chromeLauncher.launch({
    chromePath: chromePath || undefined,
    chromeFlags: ['--headless=new', '--no-sandbox', '--disable-gpu', '--disable-dev-shm-usage'],
  });

  const flags = { port: chrome.port, output: 'json', logLevel: 'error' };

  // Collect all URLs from stdin first (the PHP side writes them and closes the pipe)
  const urls = [];
  const rl = readline.createInterface({ input: process.stdin });
  for await (const line of rl) {
    const u = line.trim();
    if (u) urls.push(u);
  }

  for (const url of urls) {
    try {
      const result = await lighthouse(url, flags, config);
      process.stdout.write(JSON.stringify({
        url, strategy, lighthouseResult: JSON.parse(result.report),
      }) + '\n');
    } catch (e) {
      process.stdout.write(JSON.stringify({
        url, strategy, error: String((e && e.friendlyMessage) || (e && e.message) || e),
      }) + '\n');
    }
  }

  await chrome.kill();
}

main().catch((e) => { console.error(e); process.exit(1); });
