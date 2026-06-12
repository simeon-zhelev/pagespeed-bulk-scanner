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
- 📄 **Outputs** — dark-themed standalone HTML report, CSV export, console summary
- 🔁 **Rate-limit handling** — automatic retry on HTTP 429

## Quick start

Requires PHP 7.4+ with `curl` and `simplexml` extensions (enabled by default on macOS, most Linux distros, and all common hosting). No Composer, no dependencies.

```bash
# WordPress / Yoast site
php pagespeed_scanner.php \
  --sitemap=https://example.com/sitemap_index.xml \
  --api-key=YOUR_GOOGLE_API_KEY \
  --strategy=both \
  --workers=10 \
  --output=report.html

# Shopify site
php pagespeed_scanner.php \
  --sitemap=https://your-store.com/sitemap.xml \
  --api-key=YOUR_GOOGLE_API_KEY \
  --strategy=both \
  --workers=10
```

**Tip:** for a first run on a large site, do a trial with `--max-urls=20` to verify everything works before scanning all pages.

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
| `--workers` | `5` | Parallel API requests |
| `--max-urls` | all | Cap pages tested — useful for a trial run |
| `--output` | `pagespeed_report.html` | HTML report path |
| `--csv` | `pagespeed_report.csv` | CSV export path |

## API limits & choosing worker count

Free tier: **25,000 requests/day**, **240 requests/minute**. One page × one strategy = one request, so a 1,000-page site scanned on both strategies = 2,000 requests (8% of daily quota).

Each PSI request takes 10–30 s (Lighthouse actually renders the page), so the per-minute limit is only a concern at high worker counts:

| Pages | Recommended `--workers` | Est. scan time (both strategies) |
|---|---|---|
| < 100 | 5 | < 5 min |
| 100–500 | 8–10 | 10–25 min |
| 500–1,000 | 10–15 | 15–25 min |
| 1,000+ | 15–20 | 25–40 min |

Above ~25 workers you'll start hitting per-minute 429s; the built-in retry handles them, but extra workers stop paying off.

## Report contents

1. **Mobile / Desktop averages** — score cards for all four categories with poor/warn counts
2. **By Sitemap Group** — average performance per content type
3. **Top Optimizations** — failing performance audits ranked by pages affected, with total estimated savings
4. **Accessibility Issues** — automated WCAG check failures with affected-pages bars and element counts
5. **Optimizations Per Page** — collapsible per-URL issue lists
6. **Full Results** — every page with all scores and Core Web Vitals

## Accessibility disclaimer

Automated checks (this tool, WAVE, axe, Lighthouse) catch only roughly **30–40% of WCAG success criteria**. Full ADA/WCAG compliance also requires manual keyboard navigation, screen reader, and focus-order testing. Use the accessibility report as a cleanup checklist, not a compliance certificate.

## Scheduling regular scans

Cron example — every Monday at 07:00, with dated report files:

```cron
0 7 * * 1 php /path/to/pagespeed_scanner.php --sitemap=https://example.com/sitemap_index.xml --api-key=KEY --output=/path/to/reports/report-$(date +\%F).html --csv=/path/to/reports/report-$(date +\%F).csv
```

## Troubleshooting

**`Failed to resolve <host>` / DNS errors** — double-check the sitemap hostname; staging domains are easy to mistype.

**Lots of 429 errors** — lower `--workers`, or verify your `--api-key` is being passed (anonymous quota is tiny).

**0 URLs found** — confirm the URL returns XML (`curl -I <sitemap-url>`), and that it's a `<sitemapindex>` or `<urlset>` document.

**`Call to undefined function curl_init()` / `simplexml_load_string()`** — install the missing extension, e.g. `sudo apt install php-curl php-xml` on Debian/Ubuntu. On macOS the built-in PHP includes both.

## Project structure

```
pagespeed-bulk-scanner/
├── pagespeed_scanner.php   # the scanner — single file, no dependencies
├── README.md
├── LICENSE                 # MIT
└── .gitignore
```

## License

MIT — see [LICENSE](LICENSE).
