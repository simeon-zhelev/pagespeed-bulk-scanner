# PageSpeed Bulk Scanner

Scan the performance of **every page of a website** using the [Google PageSpeed Insights API](https://developers.google.com/speed/docs/insights/v5/get-started), in parallel, and get a self-contained HTML dashboard + CSV export.

Works with **WordPress (Yoast SEO)**, **Shopify**, and any site with a standard XML sitemap or sitemap index.

## Features

- 🗺 **Universal sitemap crawling** — recursively expands sitemap indexes:
  - Yoast SEO (`sitemap_index.xml`, `post-sitemap.xml`, custom post types)
  - Shopify (`sitemap.xml` with query-string child sitemaps like `sitemap_products_1.xml?from=…&to=…`)
  - Gzipped sitemaps (`.xml.gz`)
  - `<image:loc>` entries excluded; paginated sitemaps (`_1`, `_2`, …) grouped together
- ⚡ **Parallel scanning** — configurable worker pool built on `curl_multi`, no dependencies beyond stock PHP
- 📊 **All four Lighthouse categories** — Performance, Accessibility, Best Practices, SEO — for mobile and/or desktop
- 🎯 **Core Web Vitals per page** — FCP, LCP, TBT, CLS, Speed Index, TTI
- 🔧 **Optimization opportunities** — site-wide table of failing performance audits ranked by pages affected and estimated savings, plus per-page breakdowns
- ♿ **Accessibility issues** — WAVE-style automated WCAG checks (missing alt text, contrast errors, missing labels, …) with element counts
- 📂 **Sitemap group breakdown** — average scores per content type (Posts, Pages, Products, Collections, …)
- 📄 **Outputs** — dark-themed standalone HTML report, CSV export, optional PDF, console summary
- 🔁 **Rate-limit handling** — non-blocking retries with backoff on 429s and transient errors (the worker pool keeps scanning while a failed request waits), plus a **shared per-key rate limiter** so simultaneous scans using the same API key collectively stay under the per-minute quota

## Quick start

Requires PHP 7.4+ with `curl` and `simplexml` extensions (enabled by default on macOS, most Linux distros, and all common hosting). No Composer, no dependencies. (The optional [PDF export](#pdf-export) adds Node + Playwright — nothing else does.)

```bash
# WordPress / Yoast site
php pagespeed_scanner.php \
  --sitemap=https://example.com/sitemap_index.xml \
  --api-key=YOUR_GOOGLE_API_KEY \
  --strategy=both \
  --workers=15 \
  --output=report.html \
  --pdf            # also write report.pdf (rendered from the HTML)

# Shopify site
php pagespeed_scanner.php \
  --sitemap=https://your-store.com/sitemap.xml \
  --api-key=YOUR_GOOGLE_API_KEY \
  --strategy=both \
  --workers=15
```

**Tip:** for a first run on a large site, do a trial with `--max-urls=20` to verify everything works before scanning all pages.

## Web UI

Prefer a browser to the command line? A small, light-themed landing page is included in `web/`. Enter a **website address** (the sitemap is found automatically) or a sitemap URL, add your API key, pick your options, and it streams **live per-page progress** and shows the full report inline (plus HTML, CSV, and — when the PDF engine is set up — PDF downloads) — no command line needed. A **single-page** mode is also available for scanning one URL.

```bash
# Start the built-in PHP web server with worker processes (needed for
# simultaneous scans / SSE streams), then open http://127.0.0.1:8082
PHP_CLI_SERVER_WORKERS=32 php -S 127.0.0.1:8082 -t web
```

It reuses the exact same engine as the CLI — `pagespeed_scanner.php` — so results are identical. Generated reports are written to `web/reports/` (git-ignored).

**Scans run as background jobs**: submitting the form spawns a detached worker process per scan (progress is journaled to `web/jobs/<id>/` and streamed to the browser via SSE), so

- **any number of scans run simultaneously** — open more tabs, or let several teammates scan at once; a shared per-key rate limiter keeps them collectively under the API quota (each scan can also use its own API key, giving every scan its own independent Google quota);
- **closing the tab doesn't kill a scan** — reconnecting resumes the live progress stream where it left off.

> **Note:** the web UI runs scans on demand and your API key is sent to it, so keep it bound to `127.0.0.1` / a trusted network rather than exposing it publicly.

## Getting an API key

1. Open the [PageSpeed Insights API page](https://console.cloud.google.com/apis/library/pagespeedonline.googleapis.com) in Google Cloud Console
2. Enable the API
3. Go to **Credentials → Create credentials → API key**

Without a key the anonymous quota is extremely limited (~2 requests/minute) and scans will fail with 429 errors.

⚠️ **Never commit your API key to the repository.** Pass it on the command line or via an environment variable, and consider adding an IP restriction to the key in Google Cloud Console.

## Options

| Option | Default | Description |
|---|---|---|
| `--sitemap` | *(required)* | URL of the sitemap index or any child sitemap |
| `--api-key` | none | Google API key (strongly recommended) |
| `--strategy` | `both` | `mobile`, `desktop`, or `both` |
| `--workers` | `15` | Parallel API requests |
| `--max-urls` | all | Cap pages tested — useful for a trial run |
| `--rate-limit` | `240` | API requests/minute budgeted for this key (240 = the default PSI per-project quota; `0` = off). Shared across simultaneous scans using the same key via a token bucket in the system temp dir |
| `--output` | `pagespeed_report.html` | HTML report path |
| `--csv` | `pagespeed_report.csv` | CSV export path |
| `--pdf[=FILE]` | off | Also export a PDF, rendered from the HTML via headless Chromium. Bare `--pdf` derives the name from `--output` (e.g. `report.pdf`). See [PDF export](#pdf-export) |
| `--node` | `node` | Path to the Node.js binary (PDF export only) |
| `--runner` | `./html-to-pdf.js` | Path to the PDF helper script |

## PDF export

The PDF is a pixel-faithful copy of the HTML dashboard: the same headless
Chromium opens the finished report and prints it to PDF, expanding the
collapsible per-page detail rows first so nothing is hidden.

Because the scan itself is pure PHP, the PDF engine is **optional** — set it up
once only if you want PDFs:

```bash
npm install                      # installs Playwright
npx playwright install chromium  # one-time browser download
```

Then add `--pdf` to any scan (or use the **Download PDF** button in the web UI).
If Node or Playwright isn't present, the tool prints a notice and still writes
the HTML and CSV — the scan never fails because of a missing PDF engine.

Free tier: **25,000 requests/day**, **240 requests/minute**. One page × one strategy = one request, so a 1,000-page site scanned on both strategies = 2,000 requests (8% of daily quota).

Each PSI request takes 10–30 s (Lighthouse actually renders the page), so scan time ≈ `requests × ~20 s ÷ workers`:

| Requests (pages × strategies) | Recommended `--workers` | Est. scan time |
|---|---|---|
| < 200 | 15 | < 5 min |
| 500 | 15 | ~11 min |
| 1,000 | 20 | ~17 min |
| 2,000 | 25 | ~27 min |

Even 25 workers only average ~75 requests/minute (each request is slow), comfortably under the 240/min quota. The shared `--rate-limit` token bucket throttles automatically if several simultaneous scans use the same key, and any residual 429s or transient errors are retried with backoff **without pausing the rest of the pool**.

**Running many scans at once?** Two ways to stay under quota:
- give each scan its **own API key** (each Google Cloud project gets its own 240/min + 25k/day quota) — the rate limiter tracks each key separately;
- or keep one key and request a quota increase in Google Cloud Console, then raise `--rate-limit` to match.

## Report contents

1. **Mobile / Desktop averages** — score cards for all four categories with poor/warn counts
2. **By Sitemap Group** — average performance per content type
3. **Top Optimizations** — failing performance audits ranked by pages affected, with total estimated savings
4. **Accessibility Issues** — automated WCAG check failures with affected-pages bars and element counts
5. **Full Results** — every page with all scores and Core Web Vitals; sortable columns, and each row expands to its per-URL optimization and accessibility issues

## Accessibility disclaimer

Automated checks (this tool, WAVE, axe, Lighthouse) catch only roughly **30–40% of WCAG success criteria**. Full ADA/WCAG compliance also requires manual keyboard navigation, screen reader, and focus-order testing. Use the accessibility report as a cleanup checklist, not a compliance certificate.

## Scheduling regular scans

Cron example — every Monday at 07:00, with dated report files:

```cron
0 7 * * 1 php /path/to/pagespeed_scanner.php --sitemap=https://example.com/sitemap_index.xml --api-key=KEY --output=/path/to/reports/report-$(date +\%F).html --csv=/path/to/reports/report-$(date +\%F).csv
```

## Troubleshooting

**`Failed to resolve <host>` / DNS errors** — double-check the sitemap hostname; staging domains are easy to mistype.

**Lots of 429 errors** — verify your `--api-key` is being passed (anonymous quota is tiny). With a key, the shared `--rate-limit` bucket should prevent them; if your key's quota was lowered, set `--rate-limit` to match it.

**A few pages fail with `FAILED_DOCUMENT_REQUEST` / `net::ERR_TIMED_OUT`** — Google's Lighthouse couldn't load *that page* within its budget. It's per-page, not a scan failure, and is usually transient: the tool now retries these automatically (they mostly recover). A cluster of them on one site often means the origin is throttling the burst of simultaneous Lighthouse fetches — lower `--workers` (e.g. 10) to hit it more gently, at some cost to speed.

**`NOT_HTML` errors** — the sitemap listed a non-HTML resource (KML store-locator file, nested `.xml`, PDF, image, feed). These are now filtered out during crawling and skipped with a note; if one slips through, it's simply reported and doesn't affect the rest of the scan.

**0 URLs found** — confirm the URL returns XML (`curl -I <sitemap-url>`), and that it's a `<sitemapindex>` or `<urlset>` document.

**`Call to undefined function curl_init()` / `simplexml_load_string()`** — install the missing extension, e.g. `sudo apt install php-curl php-xml` on Debian/Ubuntu. On macOS the built-in PHP includes both.

## Project structure

```
pagespeed-bulk-scanner/
├── pagespeed_scanner.php   # the scanner — single PHP file, no deps for scanning
├── html-to-pdf.js          # optional PDF helper (Playwright; used by --pdf)
├── package.json            # Node dependency for the optional PDF engine
├── web/                    # browser front-end
│   ├── index.php           #   form + live progress + inline report
│   ├── scan.php            #   job API: start scans + stream progress (SSE)
│   ├── scan-worker.php     #   detached per-scan worker process
│   ├── jobs/               #   per-scan progress journals (git-ignored)
│   └── reports/            #   generated HTML/CSV/PDF reports (git-ignored)
│   #   start with: PHP_CLI_SERVER_WORKERS=32 php -S 127.0.0.1:8082 -t web
├── README.md
├── LICENSE                 # MIT
└── .gitignore
```

## License

MIT — see [LICENSE](LICENSE).
