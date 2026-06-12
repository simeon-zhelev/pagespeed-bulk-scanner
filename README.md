# PageSpeed Bulk Scanner

Scan the performance of **every page of a WordPress site** (or any site with an XML sitemap) using the [Google PageSpeed Insights API](https://developers.google.com/speed/docs/insights/v5/get-started), in parallel, and get a self-contained HTML dashboard + CSV export.

Built for Yoast SEO `sitemap_index.xml` files, but works with any standard sitemap or sitemap index.

## Features

- **Sitemap crawling** — recursively expands `sitemap_index.xml` into all child sitemaps; handles Yoast's default XML namespaces and excludes `<image:loc>` entries
- **Parallel scanning** — configurable worker pool (`curl_multi`)
- **All four Lighthouse categories** — Performance, Accessibility, Best Practices, SEO — for mobile and/or desktop
- **Core Web Vitals** — FCP, LCP, TBT, CLS, Speed Index, TTI per page
- **Optimization opportunities** — aggregated site-wide table of failing performance audits ranked by pages affected and estimated savings, plus per-page breakdowns
- **Accessibility issues** — WAVE-style automated WCAG checks (missing alt text, contrast errors, missing labels, …) with element counts
- **Sitemap group breakdown** — average scores per content type (Posts, Pages, Services, …) derived from child sitemap names
- **Outputs** — dark-themed standalone HTML report, CSV export, console summary
- **Rate-limit handling** — automatic retry on HTTP 429

## Quick start

Requires PHP 7.4+ with `curl` and `simplexml` (enabled by default everywhere).

```bash
php pagespeed_scanner.php \
  --sitemap=https://example.com/sitemap_index.xml \
  --api-key=YOUR_GOOGLE_API_KEY \
  --strategy=both \
  --workers=5 \
  --output=report.html
```

## Getting an API key

1. Open the [PageSpeed Insights API page](https://console.cloud.google.com/apis/library/pagespeedonline.googleapis.com) in Google Cloud Console
2. Enable the API
3. Go to **Credentials → Create credentials → API key**

The free tier allows **25,000 requests/day** and **240 requests/minute** — a 100-page site scanned on both strategies costs 200 requests per run. Without a key the anonymous quota is extremely limited (~2 requests/minute).

## Options

| Option | Default | Description |
|---|---|---|
| `--sitemap` | *(required)* | URL of `sitemap_index.xml` or any child sitemap |
| `--api-key` | none | Google API key (strongly recommended) |
| `--strategy` | `both` | `mobile`, `desktop`, or `both` |
| `--workers` | `5` | Parallel API requests (max recommended: 10) |
| `--max-urls` | all | Cap pages tested — useful for a trial run |
| `--output` | `pagespeed_report.html` | HTML report path |
| `--csv` | `pagespeed_report.csv` | CSV export path |

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

Cron example — every Monday at 07:00:

```cron
0 7 * * 1 php /path/to/pagespeed_scanner.php --sitemap=https://example.com/sitemap_index.xml --api-key=KEY --output=/path/to/reports/report-$(date +\%F).html --csv=/path/to/reports/report-$(date +\%F).csv
```

## License

MIT
