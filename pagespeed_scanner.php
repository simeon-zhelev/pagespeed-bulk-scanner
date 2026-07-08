#!/usr/bin/env php
<?php
/**
 * WordPress PageSpeed Bulk Scanner (PHP version)
 * ------------------------------------------------
 * Crawls a Yoast-generated sitemap_index.xml (or any plain sitemap.xml),
 * calls the PageSpeed Insights API for every page URL in parallel, and writes:
 *   - a self-contained HTML dashboard  (pagespeed_report.html)
 *   - a CSV export                     (pagespeed_report.csv)
 *
 * Handles Yoast SEO sitemaps:
 *   - Default XML namespaces on <sitemapindex> and <urlset>
 *   - <image:loc> entries are excluded
 *   - Trailing XML comments are ignored
 *
 * Requirements: PHP 7.4+ with curl and simplexml extensions (standard).
 *
 * Usage:
 *   php pagespeed_scanner.php \
 *       --sitemap=https://example.com/sitemap_index.xml \
 *       --api-key=YOUR_GOOGLE_API_KEY \
 *       --strategy=both \
 *       --workers=5 \
 *       --max-urls=50 \
 *       --output=report.html \
 *       --csv=report.csv
 */

// ─────────────────────────────────────────────────────────────────────────────
//  CLI arguments
// ─────────────────────────────────────────────────────────────────────────────

function parse_args(array $argv): array {
    $defaults = [
        'sitemap'  => null,
        'api-key'  => null,
        'strategy' => 'both',          // mobile | desktop | both
        'max-urls' => null,
        'workers'  => 5,
        'output'   => 'pagespeed_report.html',
        'csv'      => 'pagespeed_report.csv',
        'pdf'      => null,                        // null = off; set by --pdf[=FILE]
        'node'     => 'node',                      // Node.js binary (PDF export only)
        'runner'   => __DIR__ . '/html-to-pdf.js', // PDF helper script
        'cache-dir' => null,                       // PSI response cache directory (off by default)
        'cache-ttl' => 86400,                      // cache freshness in seconds (24h)
        'engine'    => 'psi',                      // psi (API) | local (Lighthouse in local Chromium)
        'lh-runner' => __DIR__ . '/lighthouse-runner.js',
    ];
    $opts = getopt('', [
        'sitemap:', 'api-key:', 'strategy:', 'max-urls:',
        'workers:', 'output:', 'csv:', 'pdf::', 'node:', 'runner:',
        'cache-dir:', 'cache-ttl:', 'engine:', 'lh-runner:', 'help',
    ]);

    if (isset($opts['help']) || empty($opts['sitemap'])) {
        echo <<<HELP

Bulk PageSpeed Insights scanner for WordPress / Yoast sitemaps

Usage:
  php pagespeed_scanner.php --sitemap=URL [options]

Options:
  --sitemap=URL     URL of sitemap_index.xml or any child sitemap (required)
  --api-key=KEY     Google PageSpeed Insights API key (strongly recommended)
  --strategy=S      mobile | desktop | both   (default: both)
  --max-urls=N      Cap total URLs scanned
  --workers=N       Parallel API requests (default: 5, max recommended: 10)
  --output=FILE     HTML report path (default: pagespeed_report.html)
  --csv=FILE        CSV export path  (default: pagespeed_report.csv)
  --pdf[=FILE]      Also export a PDF, rendered from the HTML report via headless
                    Chromium. Bare --pdf derives the name from --output
                    (pagespeed_report.pdf). Requires Node 18+ and Playwright
                    (npm install && npx playwright install chromium).
  --node=PATH       Node.js binary, for --pdf (default: node)
  --runner=PATH     PDF helper script (default: ./html-to-pdf.js)
  --cache-dir=DIR   Cache successful PSI responses per URL+strategy in DIR and
                    reuse them while fresh — repeat scans don't spend quota
  --cache-ttl=SECS  Cache freshness window (default: 86400 = 24h)
  --engine=E        psi (default) — Google PageSpeed Insights API, or
                    local — Lighthouse in a locally launched headless Chromium:
                    no API key/quota, works on private/staging sites, but runs
                    are sequential and numbers may differ from the PSI ones.
                    Needs Node 18+ (npm install && npx playwright install chromium).
  --lh-runner=PATH  Local Lighthouse helper (default: ./lighthouse-runner.js)
  --help            Show this help

Examples:
  # Scan entire site, both strategies, 8 parallel workers
  php pagespeed_scanner.php \\
      --sitemap=https://example.com/sitemap_index.xml \\
      --api-key=AIza... --workers=8

  # Quick mobile-only test of the first 20 pages
  php pagespeed_scanner.php \\
      --sitemap=https://example.com/sitemap_index.xml \\
      --strategy=mobile --max-urls=20

HELP;
        exit(empty($opts['sitemap']) && !isset($opts['help']) ? 1 : 0);
    }

    $args = array_merge($defaults, $opts);
    $args['max-urls'] = $args['max-urls'] !== null ? (int)$args['max-urls'] : null;
    $args['workers']  = max(1, (int)$args['workers']);
    $args['cache-dir'] = isset($args['cache-dir']) && $args['cache-dir'] !== '' ? (string)$args['cache-dir'] : null;
    $args['cache-ttl'] = max(0, (int)$args['cache-ttl']);

    if (!in_array($args['engine'], ['psi', 'local'], true)) {
        fwrite(STDERR, "❌  --engine must be psi or local\n");
        exit(1);
    }

    if (!in_array($args['strategy'], ['mobile', 'desktop', 'both'], true)) {
        fwrite(STDERR, "❌  --strategy must be mobile, desktop, or both\n");
        exit(1);
    }

    // --pdf is optional-value: bare --pdf derives the path from --output
    // (report.html → report.pdf); --pdf=FILE uses the given path.
    if (isset($opts['pdf'])) {
        $args['pdf'] = (is_string($opts['pdf']) && $opts['pdf'] !== '')
            ? $opts['pdf']
            : preg_replace('/\.html?$/i', '', $args['output']) . '.pdf';
    } else {
        $args['pdf'] = null;
    }

    return $args;
}

// ─────────────────────────────────────────────────────────────────────────────
//  HTTP helper
// ─────────────────────────────────────────────────────────────────────────────

/**
 * User-Agent used when fetching sitemaps and robots.txt. A browser-like string
 * is required because many WAFs (e.g. RunCloud's "8G" firewall) block requests
 * whose UA looks like a bot/scraper, redirecting them to a block page — which
 * otherwise makes perfectly valid sitemaps appear "unreachable". This is only
 * our own fetcher's UA; PageSpeed Insights fetches pages with its own Chrome
 * Lighthouse agent. For robots.txt purposes this presents as a generic client,
 * so the `*` rule group applies.
 */
const SCANNER_USER_AGENT =
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 '
    . '(KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

function http_get(string $url, int $timeout = 30): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_USERAGENT      => SCANNER_USER_AGENT,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_ENCODING       => '',   // accept + auto-decode gzip/deflate transfer encoding
    ]);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($body === false) {
        throw new RuntimeException("cURL error: $err");
    }
    if ($code >= 400) {
        throw new RuntimeException("HTTP $code for $url");
    }
    // Handle .xml.gz files served as raw gzip (magic bytes 1f 8b)
    if (strncmp($body, "\x1f\x8b", 2) === 0 && function_exists('gzdecode')) {
        $decoded = gzdecode($body);
        if ($decoded !== false) {
            $body = $decoded;
        }
    }
    return $body;
}

// ─────────────────────────────────────────────────────────────────────────────
//  Sitemap discovery (used by the web UI — accepts a plain site URL/domain)
// ─────────────────────────────────────────────────────────────────────────────

/** Does this URL look like it points at a sitemap (vs a plain page)? */
function looks_like_sitemap(string $url): bool {
    $path = strtolower((string)parse_url($url, PHP_URL_PATH));
    return (bool)preg_match('/\.xml(\.gz)?$/', $path) || strpos($path, 'sitemap') !== false;
}

/** Is this XML string a valid sitemap or sitemap index? */
function is_sitemap_xml(string $body): bool {
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($body);
    libxml_clear_errors();
    return $xml !== false && in_array($xml->getName(), ['sitemapindex', 'urlset'], true);
}

/**
 * Resolve a user-supplied URL into a usable sitemap URL.
 *
 * Accepts either a direct sitemap URL (returned as-is) or a plain site URL /
 * domain, in which case it auto-discovers the sitemap by (1) reading robots.txt
 * for `Sitemap:` directives, then (2) probing common sitemap paths.
 * Returns the discovered sitemap URL, or null if none could be confirmed.
 * $log is an optional callback for progress lines (defaults to echo).
 */
function discover_sitemap(string $input, ?callable $log = null): ?string {
    $say = function (string $m) use ($log) { $log ? $log($m) : print($m . "\n"); };

    $input = trim($input);
    if ($input === '') return null;
    if (!preg_match('#^https?://#i', $input)) {
        $input = 'https://' . ltrim($input, '/');
    }

    // Already a sitemap URL? Use it directly.
    if (looks_like_sitemap($input)) return $input;

    $say("🔎 Auto-discovering sitemap for $input …");

    $parts = parse_url($input);
    $host  = $parts['host'] ?? '';
    if ($host === '') return null;
    $origin = ($parts['scheme'] ?? 'https') . '://' . $host
            . (isset($parts['port']) ? ':' . $parts['port'] : '');

    $candidates = [];

    // 1 — robots.txt Sitemap: directives (the authoritative source)
    try {
        $robots = http_get($origin . '/robots.txt', 10);
        if (preg_match_all('/^\s*Sitemap:\s*(\S+)/im', $robots, $m)) {
            foreach ($m[1] as $loc) {
                $loc = trim($loc);
                if ($loc !== '') $candidates[] = $loc;
            }
            if ($candidates) $say('   robots.txt lists ' . count($candidates) . ' sitemap(s).');
        }
    } catch (Throwable $e) {
        // No robots.txt — fall through to common paths.
    }

    // 2 — Common sitemap locations (WordPress/Yoast, Shopify, generic)
    foreach ([
        '/sitemap_index.xml', '/sitemap-index.xml', '/sitemap.xml',
        '/wp-sitemap.xml', '/sitemap.xml.gz', '/sitemap/sitemap.xml',
    ] as $p) {
        $candidates[] = $origin . $p;
    }

    // Probe each candidate; return the first that is a real sitemap.
    $seen = [];
    foreach ($candidates as $url) {
        if (isset($seen[$url])) continue;
        $seen[$url] = true;
        try {
            $body = http_get($url, 12);
        } catch (Throwable $e) {
            continue;
        }
        if (is_sitemap_xml($body)) {
            $say("   ✓ Found sitemap: $url");
            return $url;
        }
    }

    return null;
}

// ─────────────────────────────────────────────────────────────────────────────
//  Sitemap crawling (namespace-agnostic, Yoast-compatible)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Derive a human-readable group label from a sub-sitemap URL.
 * Handles multiple sitemap naming conventions:
 *   Yoast:    post-sitemap.xml                          -> 'Post'
 *             app_service-sitemap.xml                   -> 'Service'
 *   Shopify:  sitemap_products_1.xml?from=...&to=...    -> 'Products'
 *             sitemap_collections_1.xml                 -> 'Collections'
 *             sitemap_blogs_1.xml                       -> 'Blogs'
 *   Generic:  sitemap-news.xml, news_sitemap.xml, etc.
 * Pagination suffixes (_1, -2) are stripped so paginated sitemaps
 * group together.
 */
function sitemap_group_name(string $url): string {
    // basename of the path only — drops Shopify's ?from=...&to=... query string
    $stem = basename(parse_url($url, PHP_URL_PATH) ?? $url);
    $stem = preg_replace('/\.xml(\.gz)?$/i', '', $stem);
    // Yoast style: {type}-sitemap / {type}_sitemap
    $stem = preg_replace('/[-_]sitemap$/i', '', $stem);
    // Shopify style: sitemap_{type} / sitemap-{type}
    $stem = preg_replace('/^sitemap[-_]?/i', '', $stem);
    // Pagination suffix: products_1, posts-2 …
    $stem = preg_replace('/[-_]\d+$/', '', $stem);
    // Yoast custom-post-type prefix
    $stem = preg_replace('/^app_/i', '', $stem);
    $stem = str_replace(['-', '_'], ' ', $stem);
    $stem = ucwords(trim($stem));
    return $stem !== '' ? $stem : 'Pages';
}

/**
 * Recursively expand a sitemap or sitemap-index.
 * Returns [urls (ordered, unique), url_to_group map].
 */
function collect_urls(string $sitemapUrl, ?int $maxUrls = null): array {
    $urls = [];
    $urlToGroup = [];
    $visited = [];

    $crawl = function (string $url, string $group) use (
        &$crawl, &$urls, &$urlToGroup, &$visited, $maxUrls
    ): void {
        if (isset($visited[$url])) return;
        $visited[$url] = true;
        echo "  ↳ Fetching sitemap: $url\n";

        try {
            $content = http_get($url, 20);
        } catch (Throwable $e) {
            echo "    ⚠  Could not fetch $url: {$e->getMessage()}\n";
            return;
        }

        // Suppress warnings from minor XML imperfections; Yoast comments are fine
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($content);
        libxml_clear_errors();
        if ($xml === false) {
            echo "    ⚠  Could not parse XML from $url\n";
            return;
        }

        $root = $xml->getName();   // local name, namespace-free

        if ($root === 'sitemapindex') {
            // Children are <sitemap><loc>…</loc></sitemap>
            foreach ($xml->sitemap as $sm) {
                if ($maxUrls && count($urls) >= $maxUrls) return;
                $childUrl = trim((string)$sm->loc);
                if ($childUrl === '') continue;
                $crawl($childUrl, sitemap_group_name($childUrl));
            }
        } elseif ($root === 'urlset') {
            // Children are <url><loc>…</loc></url>; <image:loc> lives in a
            // different namespace and is NOT matched by $u->loc — safe.
            foreach ($xml->url as $u) {
                if ($maxUrls && count($urls) >= $maxUrls) return;
                $pageUrl = trim((string)$u->loc);
                if ($pageUrl === '' || isset($urlToGroup[$pageUrl])) continue;
                $urls[] = $pageUrl;
                $urlToGroup[$pageUrl] = $group;
            }
        } else {
            echo "    ⚠  Unrecognised root element <$root> in $url\n";
        }
    };

    echo "\n📄 Collecting URLs from sitemap …\n";
    $crawl($sitemapUrl, sitemap_group_name($sitemapUrl));

    $groupCount = count(array_unique(array_values($urlToGroup)));
    echo '   Found ' . count($urls) . " unique page URLs across $groupCount sitemap group(s)\n\n";

    if ($maxUrls !== null) {
        $urls = array_slice($urls, 0, $maxUrls);
    }
    return [$urls, $urlToGroup];
}

// ─────────────────────────────────────────────────────────────────────────────
//  PageSpeed Insights API — parallel via curl_multi
// ─────────────────────────────────────────────────────────────────────────────

const PSI_ENDPOINT = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';
const CATEGORIES   = ['performance', 'accessibility', 'best-practices', 'seo'];

function psi_request_url(string $pageUrl, string $strategy, ?string $apiKey): string {
    $params = [
        'url'      => $pageUrl,
        'strategy' => $strategy,
    ];
    $query = http_build_query($params);
    // Repeated category params (http_build_query can't produce repeated keys cleanly)
    foreach (CATEGORIES as $cat) {
        $query .= '&category=' . urlencode($cat);
    }
    if ($apiKey) {
        $query .= '&key=' . urlencode($apiKey);
    }
    return PSI_ENDPOINT . '?' . $query;
}

function psi_score(array $json, string $category): ?int {
    $score = $json['lighthouseResult']['categories'][$category]['score'] ?? null;
    return $score !== null ? (int)round($score * 100) : null;
}

function psi_metric(array $json, string $auditId): string {
    return $json['lighthouseResult']['audits'][$auditId]['displayValue'] ?? '—';
}

/**
 * Extract failing optimization opportunities + diagnostics from a PSI response.
 * Returns a list of ['id','title','savings_ms','display'] sorted by impact.
 */
function extract_opportunities(array $json): array {
    $audits   = $json['lighthouseResult']['audits'] ?? [];
    // Only count audits that the performance category actually references,
    // so we don't pick up a11y/SEO audits here.
    $perfRefs = [];
    foreach (($json['lighthouseResult']['categories']['performance']['auditRefs'] ?? []) as $ref) {
        $perfRefs[$ref['id']] = true;
    }

    $opps = [];
    foreach ($audits as $id => $audit) {
        if (!isset($perfRefs[$id])) continue;

        $score = $audit['score'] ?? null;
        $mode  = $audit['scoreDisplayMode'] ?? '';
        // Skip passing, informational, manual and not-applicable audits
        if ($score === null || $score >= 0.9) continue;
        if (in_array($mode, ['informative', 'manual', 'notApplicable'], true)
            && empty($audit['details']['overallSavingsMs'])) continue;

        $savingsMs = (float)($audit['details']['overallSavingsMs'] ?? 0);
        $savingsKb = (float)($audit['details']['overallSavingsBytes'] ?? 0) / 1024;

        $display = $audit['displayValue'] ?? '';
        if ($display === '' && $savingsMs > 0) {
            $display = 'Est. savings ' . round($savingsMs / 1000, 1) . ' s';
        } elseif ($display === '' && $savingsKb > 0) {
            $display = 'Est. savings ' . round($savingsKb) . ' KiB';
        }

        $opps[] = [
            'id'         => $id,
            'title'      => $audit['title'] ?? $id,
            'savings_ms' => $savingsMs,
            'display'    => $display,
        ];
    }

    // Highest estimated savings first; tie-break on title for stable output
    usort($opps, function ($a, $b) {
        return $b['savings_ms'] <=> $a['savings_ms'] ?: strcmp($a['title'], $b['title']);
    });
    return $opps;
}

/**
 * Parse one PSI JSON response into flat row fields with M_/D_ prefix.
 */
function parse_psi_response(string $body, string $prefix): array {
    $row  = [];
    $json = json_decode($body, true);
    if (!is_array($json) || isset($json['error'])) {
        $msg = $json['error']['message'] ?? 'Invalid JSON response';
        foreach (['perf','a11y','bp','seo','fcp','lcp','tbt','cls','speed','tti'] as $k) {
            $row["{$prefix}_{$k}"] = null;
        }
        $row["{$prefix}_error"] = $msg;
        return $row;
    }
    $row["{$prefix}_perf"]  = psi_score($json, 'performance');
    $row["{$prefix}_a11y"]  = psi_score($json, 'accessibility');
    $row["{$prefix}_bp"]    = psi_score($json, 'best-practices');
    $row["{$prefix}_seo"]   = psi_score($json, 'seo');
    $row["{$prefix}_fcp"]   = psi_metric($json, 'first-contentful-paint');
    $row["{$prefix}_lcp"]   = psi_metric($json, 'largest-contentful-paint');
    $row["{$prefix}_tbt"]   = psi_metric($json, 'total-blocking-time');
    $row["{$prefix}_cls"]   = psi_metric($json, 'cumulative-layout-shift');
    $row["{$prefix}_speed"] = psi_metric($json, 'speed-index');
    $row["{$prefix}_tti"]   = psi_metric($json, 'interactive');
    $row["{$prefix}_opps"]  = extract_opportunities($json);
    $row["{$prefix}_a11y_issues"] = extract_a11y_issues($json);
    $row["{$prefix}_error"] = null;
    return $row;
}

function make_psi_handle(string $requestUrl) {
    $ch = curl_init($requestUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_USERAGENT      => 'PageSpeedBulkScanner-PHP/1.0',
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    return $ch;
}

// ─────────────────────────────────────────────────────────────────────────────
//  PSI response cache (optional, --cache-dir)
//  One file per url+strategy with the raw PSI JSON. Only clean HTTP-200
//  responses are cached, so transient failures never stick.
// ─────────────────────────────────────────────────────────────────────────────

function psi_cache_path(string $dir, string $url, string $strategy, string $engine = 'psi'): string {
    return rtrim($dir, '/') . '/' . md5($url . '|' . $strategy . '|' . $engine) . '.json';
}

function psi_cache_get(?string $dir, int $ttl, string $url, string $strategy, string $engine = 'psi'): ?string {
    if (!$dir || $ttl <= 0) return null;
    $f = psi_cache_path($dir, $url, $strategy, $engine);
    if (!is_file($f) || filemtime($f) < time() - $ttl) return null;
    $body = @file_get_contents($f);
    return ($body === false || $body === '') ? null : $body;
}

function psi_cache_put(?string $dir, string $url, string $strategy, string $body, string $engine = 'psi'): void {
    if (!$dir) return;
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) return;
    @file_put_contents(psi_cache_path($dir, $url, $strategy, $engine), $body);
}

/**
 * Run all (url × strategy) jobs through a rolling curl_multi pool.
 * Returns: map  pageUrl => result-row (merged across strategies).
 */
function scan_all(array $urls, array $strategies, ?string $apiKey, int $workers,
                  ?callable $onEvent = null,
                  ?string $cacheDir = null, int $cacheTtl = 86400): array {
    // Build the full job list: one HTTP request per url+strategy pair.
    // Fresh cache hits skip the network entirely (--cache-dir).
    $jobs = [];
    $cachedJobs = [];
    foreach ($urls as $url) {
        foreach ($strategies as $strategy) {
            $cachedBody = psi_cache_get($cacheDir, $cacheTtl, $url, $strategy);
            if ($cachedBody !== null) {
                $cachedJobs[] = [
                    'url'      => $url,
                    'strategy' => $strategy,
                    'prefix'   => strtoupper($strategy[0]),
                    'body'     => $cachedBody,
                ];
                continue;
            }
            $jobs[] = [
                'url'      => $url,
                'strategy' => $strategy,
                'prefix'   => strtoupper($strategy[0]),     // M or D
                'request'  => psi_request_url($url, $strategy, $apiKey),
                'retried'  => false,
            ];
        }
    }

    $results = [];
    foreach ($urls as $url) {
        $results[$url] = ['url' => $url];
    }

    $totalJobs = count($jobs) + count($cachedJobs);
    $doneJobs  = 0;
    if (!$onEvent) {
        echo "⚡  Scanning " . count($urls) . " URLs × " . count($strategies)
           . " strategies = $totalJobs requests, $workers parallel workers …\n\n";
    } else {
        $onEvent([
            'phase'      => 'scan-start',
            'total'      => $totalJobs,
            'urls'       => count($urls),
            'workers'    => $workers,
            'strategies' => $strategies,
        ]);
    }

    // Replay cache hits first — instant results, zero quota
    foreach ($cachedJobs as $cj) {
        $doneJobs++;
        $parsed = parse_psi_response($cj['body'], $cj['prefix']);
        $results[$cj['url']] = array_merge($results[$cj['url']], $parsed);
        if ($onEvent) {
            $onEvent([
                'phase'    => 'job',
                'done'     => $doneJobs,
                'total'    => $totalJobs,
                'url'      => $cj['url'],
                'strategy' => $cj['strategy'],
                'ok'       => ($parsed["{$cj['prefix']}_error"] ?? null) === null,
                'cached'   => true,
                'perf'     => $parsed["{$cj['prefix']}_perf"] ?? null,
                'a11y'     => $parsed["{$cj['prefix']}_a11y"] ?? null,
                'seo'      => $parsed["{$cj['prefix']}_seo"]  ?? null,
                'bp'       => $parsed["{$cj['prefix']}_bp"]   ?? null,
                'error'    => $parsed["{$cj['prefix']}_error"] ?? null,
            ]);
        } else {
            $score = $parsed["{$cj['prefix']}_perf"] ?? '—';
            echo "  [$doneJobs/$totalJobs] ⚡ [{$cj['strategy']}] perf=$score  {$cj['url']}  (cached)\n";
        }
    }

    $mh = curl_multi_init();
    $active = [];   // (int)$ch handle id => job

    $enqueue = function (array $job) use ($mh, &$active): void {
        $ch = make_psi_handle($job['request']);
        $job['handle'] = $ch;
        $active[(int)$ch] = $job;
        curl_multi_add_handle($mh, $ch);
    };

    // Prime the pool
    $queue = $jobs;
    for ($i = 0; $i < $workers && $queue; $i++) {
        $enqueue(array_shift($queue));
    }

    do {
        $status = curl_multi_exec($mh, $running);
        if ($running) {
            curl_multi_select($mh, 1.0);
        }

        // Collect finished transfers
        while ($info = curl_multi_info_read($mh)) {
            $ch  = $info['handle'];
            $job = $active[(int)$ch];
            unset($active[(int)$ch]);

            $body = curl_multi_getcontent($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_multi_remove_handle($mh, $ch);

            $url    = $job['url'];
            $prefix = $job['prefix'];

            if ($code === 429 && !$job['retried']) {
                // Rate-limited: requeue once after a pause
                if ($onEvent) {
                    $onEvent(['phase' => 'rate-limit', 'url' => $url,
                              'strategy' => $job['strategy']]);
                } else {
                    echo "    ⏳  429 rate-limited ({$job['strategy']}) $url — retrying in 15 s …\n";
                }
                sleep(15);
                $job['retried'] = true;
                $enqueue($job);
                continue;
            }

            $doneJobs++;
            if ($info['result'] !== CURLE_OK || $body === false || $body === '') {
                $msg = $err ?: "HTTP $code";
                foreach (['perf','a11y','bp','seo','fcp','lcp','tbt','cls','speed','tti'] as $k) {
                    $results[$url]["{$prefix}_{$k}"] = null;
                }
                $results[$url]["{$prefix}_error"] = $msg;
                if (!$onEvent) {
                    echo "  [$doneJobs/$totalJobs] ✗ [{$job['strategy']}] $url — $msg\n";
                }
            } else {
                $parsed = parse_psi_response($body, $prefix);
                $results[$url] = array_merge($results[$url], $parsed);
                if ($code === 200 && ($parsed["{$prefix}_error"] ?? null) === null) {
                    psi_cache_put($cacheDir, $url, $job['strategy'], $body);
                }
                if (!$onEvent) {
                    $score = $parsed["{$prefix}_perf"] ?? '—';
                    $scoreStr = $score === null ? 'ERR' : $score;
                    echo "  [$doneJobs/$totalJobs] ✓ [{$job['strategy']}] perf=$scoreStr  $url\n";
                }
            }

            if ($onEvent) {
                $perr = $results[$url]["{$prefix}_error"] ?? null;
                $onEvent([
                    'phase'    => 'job',
                    'done'     => $doneJobs,
                    'total'    => $totalJobs,
                    'url'      => $url,
                    'strategy' => $job['strategy'],
                    'ok'       => $perr === null,
                    'perf'     => $results[$url]["{$prefix}_perf"]  ?? null,
                    'a11y'     => $results[$url]["{$prefix}_a11y"]  ?? null,
                    'seo'      => $results[$url]["{$prefix}_seo"]   ?? null,
                    'bp'       => $results[$url]["{$prefix}_bp"]    ?? null,
                    'error'    => $perr,
                ]);
            }

            // Feed the pool
            if ($queue) {
                $enqueue(array_shift($queue));
            }
        }
    } while ($running || $active || $queue);

    curl_multi_close($mh);
    return $results;
}

/**
 * Local Lighthouse engine (--engine=local): audits every url×strategy with
 * lighthouse-runner.js in a locally launched headless Chromium. No API key,
 * no quota, reaches private/staging sites. Sequential per strategy — parallel
 * Lighthouse runs contend for CPU and skew TBT/LCP.
 * Emits the exact result-row shape scan_all() produces.
 */
function scan_local(array $urls, array $strategies, array $args,
                    ?callable $onEvent = null,
                    ?string $cacheDir = null, int $cacheTtl = 86400): array {
    $results = [];
    foreach ($urls as $url) {
        $results[$url] = ['url' => $url];
    }

    $totalJobs = count($urls) * count($strategies);
    $doneJobs  = 0;

    if (!$onEvent) {
        echo "⚡  Local Lighthouse: " . count($urls) . " URLs × " . count($strategies)
           . " strategies = $totalJobs runs, sequential …\n\n";
    } else {
        $onEvent(['phase' => 'scan-start', 'total' => $totalJobs,
                  'urls' => count($urls), 'workers' => 1, 'strategies' => $strategies]);
    }

    $emit = function (string $url, string $strategy, string $prefix, array $parsed, bool $cached = false)
            use (&$doneJobs, $totalJobs, $onEvent): void {
        $doneJobs++;
        $err = $parsed["{$prefix}_error"] ?? null;
        if ($onEvent) {
            $onEvent([
                'phase' => 'job', 'done' => $doneJobs, 'total' => $totalJobs,
                'url' => $url, 'strategy' => $strategy, 'ok' => $err === null,
                'cached' => $cached,
                'perf' => $parsed["{$prefix}_perf"] ?? null,
                'a11y' => $parsed["{$prefix}_a11y"] ?? null,
                'seo'  => $parsed["{$prefix}_seo"]  ?? null,
                'bp'   => $parsed["{$prefix}_bp"]   ?? null,
                'error' => $err,
            ]);
        } else {
            $score = $parsed["{$prefix}_perf"] ?? null;
            $tag   = $cached ? '  (cached)' : '';
            echo $err === null
                ? "  [$doneJobs/$totalJobs] ✓ [$strategy] perf=" . ($score ?? '—') . "  $url$tag\n"
                : "  [$doneJobs/$totalJobs] ✗ [$strategy] $url — $err\n";
        }
    };

    $errorRow = function (string $prefix, string $msg): array {
        $row = [];
        foreach (['perf','a11y','bp','seo','fcp','lcp','tbt','cls','speed','tti'] as $k) {
            $row["{$prefix}_{$k}"] = null;
        }
        $row["{$prefix}_error"] = $msg;
        return $row;
    };

    foreach ($strategies as $strategy) {
        $prefix = strtoupper($strategy[0]);

        // Cache replay first (shared format with the PSI engine, separate key space)
        $pending = [];
        foreach ($urls as $url) {
            $body = psi_cache_get($cacheDir, $cacheTtl, $url, $strategy, 'local');
            if ($body !== null) {
                $parsed = parse_psi_response($body, $prefix);
                $results[$url] = array_merge($results[$url], $parsed);
                $emit($url, $strategy, $prefix, $parsed, cached: true);
            } else {
                $pending[] = $url;
            }
        }
        if (!$pending) {
            continue;
        }

        if (!is_file($args['lh-runner'])) {
            foreach ($pending as $url) {
                $parsed = $errorRow($prefix, 'lighthouse-runner.js not found: ' . $args['lh-runner']);
                $results[$url] = array_merge($results[$url], $parsed);
                $emit($url, $strategy, $prefix, $parsed);
            }
            continue;
        }

        $cmd = escapeshellarg($args['node']) . ' ' . escapeshellarg($args['lh-runner'])
             . ' --strategy=' . escapeshellarg($strategy);
        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        if (!is_resource($proc)) {
            foreach ($pending as $url) {
                $parsed = $errorRow($prefix, 'Could not start Node runner');
                $results[$url] = array_merge($results[$url], $parsed);
                $emit($url, $strategy, $prefix, $parsed);
            }
            continue;
        }

        fwrite($pipes[0], implode("\n", $pending) . "\n");
        fclose($pipes[0]);

        $seen = [];
        while (($line = fgets($pipes[1])) !== false) {
            $row = json_decode(trim($line), true);
            if (!is_array($row) || empty($row['url'])) {
                continue;
            }
            $url = $row['url'];
            $seen[$url] = true;

            if (isset($row['lighthouseResult'])) {
                $body   = json_encode(['lighthouseResult' => $row['lighthouseResult']]);
                $parsed = parse_psi_response($body, $prefix);
                if (($parsed["{$prefix}_error"] ?? null) === null) {
                    psi_cache_put($cacheDir, $url, $strategy, $body, 'local');
                }
            } else {
                $parsed = $errorRow($prefix, (string)($row['error'] ?? 'Lighthouse failed'));
            }
            $results[$url] = array_merge($results[$url], $parsed);
            $emit($url, $strategy, $prefix, $parsed);
        }

        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        // Anything the runner never reported (crash mid-run) becomes an error row
        foreach ($pending as $url) {
            if (isset($seen[$url])) continue;
            $msg = trim((string)$stderr) !== '' ? trim($stderr) : "Runner exited ($exit) before auditing this URL";
            $parsed = $errorRow($prefix, $msg);
            $results[$url] = array_merge($results[$url], $parsed);
            $emit($url, $strategy, $prefix, $parsed);
        }
    }

    return $results;
}

// ─────────────────────────────────────────────────────────────────────────────
//  HTML report
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Extract failing accessibility audits from a PSI response (WAVE-style checks:
 * missing alt text, contrast errors, missing labels, ARIA problems, etc.).
 * Returns a list of ['id','title','count'] — count = number of failing elements.
 */
function extract_a11y_issues(array $json): array {
    $audits  = $json['lighthouseResult']['audits'] ?? [];
    $a11yRefs = [];
    foreach (($json['lighthouseResult']['categories']['accessibility']['auditRefs'] ?? []) as $ref) {
        $a11yRefs[$ref['id']] = true;
    }

    $issues = [];
    foreach ($audits as $id => $audit) {
        if (!isset($a11yRefs[$id])) continue;

        $score = $audit['score'] ?? null;
        $mode  = $audit['scoreDisplayMode'] ?? '';
        // Failing binary/numeric audits only; skip manual/informative/notApplicable
        if ($score === null || $score >= 0.9) continue;
        if (in_array($mode, ['manual', 'informative', 'notApplicable'], true)) continue;

        // Number of offending elements, when Lighthouse provides them
        $count = isset($audit['details']['items']) && is_array($audit['details']['items'])
            ? count($audit['details']['items'])
            : 1;

        $issues[] = [
            'id'    => $id,
            'title' => $audit['title'] ?? $id,
            'count' => $count,
        ];
    }

    // Most offending elements first; tie-break on title
    usort($issues, function ($a, $b) {
        return $b['count'] <=> $a['count'] ?: strcmp($a['title'], $b['title']);
    });
    return $issues;
}

/**
 * Aggregated site-wide optimization table: which issues affect the most
 * pages and carry the largest total estimated savings.
 */
function optimization_summary(array $results, array $strategies): string {
    $html = '';
    foreach ($strategies as $strategy) {
        $p = strtoupper($strategy[0]);
        // Aggregate: id => [title, pages affected, total savings ms]
        $agg = [];
        foreach ($results as $r) {
            foreach (($r["{$p}_opps"] ?? []) as $o) {
                $id = $o['id'];
                if (!isset($agg[$id])) {
                    $agg[$id] = ['title' => $o['title'], 'pages' => 0, 'savings' => 0.0];
                }
                $agg[$id]['pages']++;
                $agg[$id]['savings'] += $o['savings_ms'];
            }
        }
        if (!$agg) continue;

        // Sort: most pages affected first, then by total savings
        uasort($agg, function ($a, $b) {
            return $b['pages'] <=> $a['pages'] ?: $b['savings'] <=> $a['savings'];
        });
        $agg = array_slice($agg, 0, 15, true);   // top 15

        $totalPages = count($results);
        $icon = $strategy === 'mobile' ? '📱' : '🖥';
        $rows = '';
        foreach ($agg as $a) {
            $titleEsc = htmlspecialchars($a['title']);
            $pct      = round($a['pages'] / max(1, $totalPages) * 100);
            $savings  = $a['savings'] >= 1000
                ? round($a['savings'] / 1000, 1) . ' s'
                : ($a['savings'] > 0 ? round($a['savings']) . ' ms' : '—');
            $barW = max(2, $pct);
            $rows .= "<tr><td class=\"opp-title\">$titleEsc</td>"
                   . "<td><div class=\"pgbar\"><div class=\"pgfill\" style=\"width:{$barW}%\"></div>"
                   . "<span class=\"pgtext\">{$a['pages']}/{$totalPages} ({$pct}%)</span></div></td>"
                   . "<td>$savings</td></tr>";
        }

        $sCap = ucfirst($strategy);
        $html .= <<<HTML

<div class="section-title">Top Optimizations — $sCap</div>
<div class="table-wrap" style="margin-top:10px">
  <table>
    <thead>
      <tr><th style="text-align:left">Optimization</th>
          <th>Pages affected</th><th>Total est. savings</th></tr>
    </thead>
    <tbody>$rows</tbody>
  </table>
</div>
HTML;
    }
    return $html;
}

/**
 * Build the per-page list of top optimization opportunities and accessibility
 * issues. Rendered inside each page's collapsible row in the Full Results table.
 * Returns '' when the page has nothing to show.
 */
function page_opts_body(array $r, array $strategies): string {
    $body = '';
    foreach ($strategies as $strategy) {
        $p    = strtoupper($strategy[0]);
        $opps = array_slice($r["{$p}_opps"] ?? [], 0, 6);
        if (!$opps) continue;
        $icon = $strategy === 'mobile' ? '📱' : '🖥';
        $body .= "<div class=\"opp-strategy\">" . ucfirst($strategy) . '</div><ul class="opp-list">';
        foreach ($opps as $o) {
            $titleEsc = htmlspecialchars($o['title']);
            $dispEsc  = htmlspecialchars($o['display']);
            $disp     = $dispEsc !== '' ? " <span class=\"opp-savings\">$dispEsc</span>" : '';
            $body    .= "<li>$titleEsc$disp</li>";
        }
        $body .= '</ul>';
    }

    // Accessibility issues (DOM-based — shown once, from first strategy)
    $pa = strtoupper($strategies[0][0]);
    $a11y = array_slice($r["{$pa}_a11y_issues"] ?? [], 0, 8);
    if ($a11y) {
        $body .= '<div class="opp-strategy">♿ Accessibility</div><ul class="opp-list">';
        foreach ($a11y as $iss) {
            $titleEsc = htmlspecialchars($iss['title']);
            $cnt = $iss['count'] > 1
                ? " <span class=\"opp-savings\">{$iss['count']} elements</span>" : '';
            $body .= "<li>$titleEsc$cnt</li>";
        }
        $body .= '</ul>';
    }
    return $body;
}

/**
 * Site-wide accessibility issue table (WAVE-style). A11y issues are DOM-based
 * and nearly identical across strategies, so we use one strategy's data.
 */
function a11y_summary(array $results, array $strategies): string {
    $p = in_array('mobile', $strategies, true) ? 'M' : 'D';

    // Aggregate: id => [title, pages affected, total element count]
    $agg = [];
    foreach ($results as $r) {
        foreach (($r["{$p}_a11y_issues"] ?? []) as $iss) {
            $id = $iss['id'];
            if (!isset($agg[$id])) {
                $agg[$id] = ['title' => $iss['title'], 'pages' => 0, 'elements' => 0];
            }
            $agg[$id]['pages']++;
            $agg[$id]['elements'] += $iss['count'];
        }
    }
    if (!$agg) {
        return '<div class="section-title">Accessibility Issues</div>'
             . '<p style="font-size:0.85rem;color:#2e9e5b">'
             . 'No automated accessibility failures detected. '
             . 'Note: manual testing is still required for full WCAG/ADA coverage.</p>';
    }

    uasort($agg, function ($a, $b) {
        return $b['pages'] <=> $a['pages'] ?: $b['elements'] <=> $a['elements'];
    });

    $totalPages = count($results);
    $rows = '';
    foreach ($agg as $a) {
        $titleEsc = htmlspecialchars($a['title']);
        $pct  = round($a['pages'] / max(1, $totalPages) * 100);
        $barW = max(2, $pct);
        $rows .= "<tr><td class=\"opp-title\">$titleEsc</td>"
               . "<td><div class=\"pgbar\"><div class=\"pgfill a11yfill\" style=\"width:{$barW}%\"></div>"
               . "<span class=\"pgtext\">{$a['pages']}/{$totalPages} ({$pct}%)</span></div></td>"
               . "<td>{$a['elements']}</td></tr>";
    }

    return <<<HTML

<div class="section-title">Accessibility Issues (WCAG, automated checks)</div>
<div class="table-wrap" style="margin-top:10px">
  <table>
    <thead>
      <tr><th style="text-align:left">Issue</th>
          <th>Pages affected</th><th>Total elements</th></tr>
    </thead>
    <tbody>$rows</tbody>
  </table>
</div>
<p style="font-size:0.72rem;color:#888888;margin-top:8px">
  ⚠ Automated checks catch only ~30–40% of WCAG issues. Full ADA compliance also
  requires manual keyboard, screen-reader, and focus-order testing.
</p>
HTML;
}

function score_color(?int $score): string {
    if ($score === null) return '#9a958c';
    if ($score >= 90)    return '#2e9e5b';
    if ($score >= 50)    return '#d99a2b';
    return '#cf4a3a';
}

function score_badge(?int $score): string {
    $c     = score_color($score);
    $label = $score === null ? 'ERR' : (string)$score;
    return "<span class=\"badge\" style=\"background:$c;color:#fff;padding:2px 8px;"
         . "border-radius:12px;font-weight:700;font-size:0.78rem\">$label</span>";
}

function score_avg(array $values): string {
    $nums = array_filter($values, 'is_int');
    return $nums ? (string)round(array_sum($nums) / count($nums)) : '—';
}

function summary_section(array $results, string $prefix, string $label): string {
    $cats = [
        ['Performance', 'perf'], ['Accessibility', 'a11y'],
        ['Best Practices', 'bp'], ['SEO', 'seo'],
    ];
    $cards = '';
    foreach ($cats as [$catLabel, $cat]) {
        $scores = array_map(fn($r) => $r["{$prefix}_{$cat}"] ?? null, $results);
        $a    = score_avg($scores);
        $poor = count(array_filter($scores, fn($s) => is_int($s) && $s < 50));
        $warn = count(array_filter($scores, fn($s) => is_int($s) && $s >= 50 && $s < 90));
        $color = score_color($a !== '—' ? (int)$a : null);
        $cards .= <<<CARD

      <div class="card">
        <div class="card-label">$catLabel</div>
        <div class="card-score" style="color:$color">$a</div>
        <div class="card-sub">🔴 $poor poor &nbsp; 🟡 $warn needs work</div>
      </div>
CARD;
    }
    return "<div class=\"section-title\">$label</div><div class=\"cards\">$cards</div>";
}

function group_breakdown(array $results, array $urlToGroup, array $strategies): string {
    $groups = [];
    foreach ($results as $r) {
        $g = $urlToGroup[$r['url']] ?? 'Other';
        $groups[$g][] = $r;
    }
    if (count($groups) <= 1) return '';
    ksort($groups);

    $rows = '';
    foreach ($groups as $g => $gResults) {
        foreach ($strategies as $strategy) {
            $p = strtoupper($strategy[0]);
            $scores = array_filter(
                array_map(fn($r) => $r["{$p}_perf"] ?? null, $gResults),
                'is_int'
            );
            $a     = score_avg($scores);
            $color = score_color($a !== '—' ? (int)$a : null);
            $icon  = $strategy === 'mobile' ? '📱' : '🖥';
            $good  = count(array_filter($scores, fn($s) => $s >= 90));
            $warn  = count(array_filter($scores, fn($s) => $s >= 50 && $s < 90));
            $poor  = count(array_filter($scores, fn($s) => $s < 50));
            $n     = count($gResults);
            $gEsc  = htmlspecialchars($g);
            $sCap  = ucfirst($strategy);
            $avgV  = $a === '—' ? '' : $a;
            $rows .= "<tr>"
                   . "<td class=\"gname\" data-col=\"0\" data-v=\"$gEsc\">$gEsc</td>"
                   . "<td data-col=\"1\" data-v=\"$sCap\">$sCap</td>"
                   . "<td data-col=\"2\" data-v=\"$n\">$n</td>"
                   . "<td data-col=\"3\" data-v=\"$avgV\"><span style=\"color:$color;font-weight:700\">$a</span></td>"
                   . "<td data-col=\"4\" data-v=\"$good\">$good</td>"
                   . "<td data-col=\"5\" data-v=\"$warn\">$warn</td>"
                   . "<td data-col=\"6\" data-v=\"$poor\">$poor</td></tr>";
        }
    }

    return <<<HTML

<div class="section-title">By Sitemap Group</div>
<p class="table-hint">Click a column header to sort</p>
<div class="table-wrap" style="margin-top:10px">
  <table class="sortable">
    <thead>
      <tr>
        <th data-col="0" data-type="text">Sitemap group</th>
        <th data-col="1" data-type="text">Strategy</th>
        <th data-col="2" data-type="num">Pages</th>
        <th data-col="3" data-type="num">Avg Perf</th>
        <th data-col="4" data-type="num">✅ Good</th>
        <th data-col="5" data-type="num">⚠ Warn</th>
        <th data-col="6" data-type="num">❌ Poor</th>
      </tr>
    </thead>
    <tbody>$rows</tbody>
  </table>
</div>
HTML;
}

/** Parse a metric display string ("1.2 s", "350 ms", "0.05") into a numeric
 *  sort value normalised to milliseconds (unitless values pass through). */
function metric_sort_val(string $disp): string {
    if (!preg_match('/([\d.]+)/', $disp, $m)) return '';
    $n = (float)$m[1];
    if (strpos($disp, 'ms') !== false)      { /* already ms */ }
    elseif (strpos($disp, 's') !== false)   { $n *= 1000; }
    return (string)$n;
}

function metric_cols(string $p, array $r, int $startCol): string {
    $err = $r["{$p}_error"] ?? null;
    if ($err) {
        $errEsc = htmlspecialchars(mb_substr($err, 0, 120));
        return "<td colspan=\"10\" style=\"color:#cf4a3a;font-size:0.72rem\">⚠ $errEsc</td>";
    }
    $cols = '';
    $c = $startCol;
    foreach (['perf','a11y','bp','seo'] as $cat) {
        $v  = $r["{$p}_{$cat}"] ?? null;
        $sv = $v === null ? '' : (string)$v;
        $cols .= "<td data-col=\"$c\" data-v=\"$sv\">" . score_badge($v) . '</td>';
        $c++;
    }
    foreach (['fcp','lcp','tbt','cls','speed','tti'] as $m) {
        $raw = $r["{$p}_{$m}"] ?? '—';
        $val = htmlspecialchars($raw);
        $sv  = metric_sort_val((string)$raw);
        $cols .= "<td data-col=\"$c\" data-v=\"$sv\">$val</td>";
        $c++;
    }
    return $cols;
}

/** Column labels for one strategy's 10-metric block, tagged with body-column
 *  indexes so the client-side sorter knows which cell to read. */
function metric_head(int $start): string {
    $labels = ['Perf','A11y','Best P.','SEO','FCP','LCP','TBT','CLS','Speed Idx','TTI'];
    $h = '';
    $c = $start;
    foreach ($labels as $lab) {
        $h .= "<th data-col=\"$c\" data-type=\"num\">$lab</th>";
        $c++;
    }
    return $h;
}

function detail_table(array $results, array $urlToGroup, array $strategies): string {
    $hasM = in_array('mobile', $strategies, true);
    $hasD = in_array('desktop', $strategies, true);
    $hasGroups = count(array_unique(array_values($urlToGroup))) > 1;

    // Body column layout: # (0), URL (1), [Group (2)], then Mobile / Desktop blocks.
    $base    = 2 + ($hasGroups ? 1 : 0);
    $mStart  = $base;
    $dStart  = $base + ($hasM ? 10 : 0);
    $total   = $base + ($hasM ? 10 : 0) + ($hasD ? 10 : 0);

    $groupCol = $hasGroups ? '<th data-col="2" data-type="text">Group</th>' : '';
    $thead = "<tr><th data-col=\"0\" data-type=\"num\">#</th>"
           . "<th data-col=\"1\" data-type=\"text\">URL</th>$groupCol";
    if ($hasM) $thead .= '<th colspan="10" style="background:#2560a8;color:#fff">📱 Mobile</th>';
    if ($hasD) $thead .= '<th colspan="10" style="background:#24824a;color:#fff">🖥 Desktop</th>';
    $thead .= '</tr><tr><th></th><th></th>' . ($hasGroups ? '<th></th>' : '');
    if ($hasM) $thead .= metric_head($mStart);
    if ($hasD) $thead .= metric_head($dStart);
    $thead .= '</tr>';

    $rows = '';
    $i = 0;
    foreach ($results as $r) {
        $i++;
        $url   = $r['url'];
        $urlEsc = htmlspecialchars($url);
        $short = htmlspecialchars(preg_replace('#^https?://#', '', $url));
        $group = htmlspecialchars($urlToGroup[$url] ?? '');
        $gc    = $hasGroups ? "<td class=\"gname\" data-col=\"2\" data-v=\"$group\">$group</td>" : '';
        $mCols = $hasM ? metric_cols('M', $r, $mStart) : '';
        $dCols = $hasD ? metric_cols('D', $r, $dStart) : '';

        $detail = page_opts_body($r, $strategies);
        $hasDetail = $detail !== '';
        $caret   = $hasDetail ? '<span class="caret">▸</span>' : '';
        $trClass = $hasDetail ? 'page-row has-detail' : 'page-row';

        $rows .= "<tr class=\"$trClass\">"
               . "<td class=\"num\" data-col=\"0\" data-v=\"$i\">$i</td>"
               . "<td class=\"url-cell\" data-col=\"1\" data-v=\"$short\">$caret"
               . "<a href=\"$urlEsc\" target=\"_blank\" rel=\"noopener\">$short</a></td>"
               . "$gc$mCols$dCols</tr>";
        if ($hasDetail) {
            $rows .= "<tr class=\"detail-row\" hidden>"
                   . "<td colspan=\"$total\"><div class=\"opp-body\">$detail</div></td></tr>";
        }
    }

    return <<<HTML

<div class="section-title">Full Results</div>
<p class="table-hint">Click a column header to sort · click a row to see its optimizations</p>
<div class="table-wrap">
  <table class="sortable">
    <thead>$thead</thead>
    <tbody>$rows</tbody>
  </table>
</div>
HTML;
}

function build_html(array $results, array $urlToGroup, array $strategies,
                    string $sitemapUrl, string $generatedAt): string {
    $summary = '';
    if (in_array('mobile', $strategies, true)) {
        $summary .= summary_section($results, 'M', '📱 Mobile — Averages');
    }
    if (in_array('desktop', $strategies, true)) {
        $summary .= summary_section($results, 'D', '🖥 Desktop — Averages');
    }
    $groupHtml  = group_breakdown($results, $urlToGroup, $strategies);
    $optHtml    = optimization_summary($results, $strategies);
    $a11yHtml   = a11y_summary($results, $strategies);
    $detailHtml = detail_table($results, $urlToGroup, $strategies);
    $sitemapEsc = htmlspecialchars($sitemapUrl);
    $count      = count($results);

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>PageSpeed Report — $generatedAt</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=IBM+Plex+Sans:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
  /* ── Website Health Check report theme (teal) ── */
  :root {
    --ink: #0F1E33; --body: #33415C; --muted: #64748B; --soft: #94A3B8;
    --line: #E6EAF1; --line-strong: #C9D4E5; --bg: #ffffff; --bg-soft: #F5F7FA;
    --accent: #0D8A7E; --accent-tint: #E6F4F2; --accent-line: #BFE3DE;
    --good: #1F9D5B; --warn: #E3A11F; --bad: #D64541;
  }
  *, *::before, *::after { box-sizing: border-box; }
  body  { font-family: 'IBM Plex Sans', system-ui, Helvetica, Arial, sans-serif;
          background: var(--bg-soft); color: var(--body); margin: 0; padding: 0 28px 40px;
          line-height: 1.55; }
  .brandbar { display: flex; align-items: center; gap: 14px; padding: 18px 0 16px;
              margin-bottom: 24px; border-bottom: 1px solid var(--line); flex-wrap: wrap; }
  .brandbar .logo { width: 30px; height: 30px; border-radius: 50%; flex: none;
    background: conic-gradient(var(--good) 0 76%, var(--line) 76% 100%);
    display: grid; place-items: center; }
  .brandbar .logo::before { content: ''; width: 20px; height: 20px; border-radius: 50%;
    background: var(--bg-soft); }
  .brandbar .brandname { font-family: 'Space Grotesk', sans-serif; font-weight: 700;
    font-size: 17px; color: var(--ink); }
  .brandbar .brandctx { color: var(--soft); font-size: 13px; }
  .brandbar .sp { flex: 1; }
  h1    { font-family: 'Space Grotesk', sans-serif; font-size: 1.6rem; margin: 6px 0 4px; color: var(--ink); }
  .meta { font-size: 0.8rem; color: var(--muted); margin-bottom: 28px; }
  .meta strong { color: var(--ink); }
  .section-title { font-family: 'Space Grotesk', sans-serif; font-size: 0.8rem; font-weight: 700;
                   color: var(--muted); text-transform: uppercase; letter-spacing: .1em;
                   margin: 32px 0 10px; }
  .cards { display: flex; flex-wrap: wrap; gap: 12px; }
  .card  { background: var(--bg); border: 1px solid var(--line); border-radius: 12px;
           padding: 16px 22px; min-width: 148px; flex: 1;
           box-shadow: 0 1px 2px rgba(15, 23, 42, .04); }
  .card-label { font-size: 0.72rem; color: var(--muted); text-transform: uppercase;
                letter-spacing: .06em; }
  .card-score { font-family: 'Space Grotesk', sans-serif; font-size: 2.4rem; font-weight: 700;
                line-height: 1.1; margin: 4px 0; color: var(--ink); }
  .card-sub   { font-size: 0.7rem; color: var(--soft); }
  .table-wrap { overflow-x: auto; border: 1px solid var(--line); border-radius: 12px;
                background: var(--bg); margin-top: 4px; }
  table  { width: 100%; border-collapse: collapse; font-size: 0.77rem; color: var(--body); }
  th, td { padding: 8px 10px; text-align: center; border-bottom: 1px solid var(--line); }
  th     { background: var(--bg-soft); color: var(--muted); font-weight: 600;
           text-transform: uppercase; letter-spacing: .05em; white-space: nowrap; }
  td.url-cell { text-align: left; max-width: 280px; overflow: hidden;
                text-overflow: ellipsis; white-space: nowrap; }
  td.url-cell a { color: var(--accent); text-decoration: none; }
  td.url-cell a:hover { text-decoration: underline; }
  td.gname { text-align: left; font-size: 0.72rem; color: var(--muted); white-space: nowrap; }
  td.num   { color: var(--soft); width: 32px; }
  tr:hover td { background: var(--accent-tint); }
  .table-hint { font-size: 0.72rem; color: var(--soft); margin: 0 0 6px; }
  /* Sortable headers */
  th[data-col] { cursor: pointer; user-select: none; }
  th[data-col]::after { content: ' \\2195'; opacity: .35; font-size: 0.85em; }
  th[data-col][data-dir="asc"]::after  { content: ' \\2191'; opacity: 1; }
  th[data-col][data-dir="desc"]::after { content: ' \\2193'; opacity: 1; }
  /* Expandable Full Results rows */
  tr.has-detail { cursor: pointer; }
  .caret { display: inline-block; margin-right: 5px; color: var(--accent);
           font-size: 0.7rem; transition: transform .15s; }
  tr.has-detail.open .caret { transform: rotate(90deg); }
  tr.detail-row > td { text-align: left; background: var(--bg-soft); padding: 12px 16px; }
  tr.detail-row:hover > td { background: var(--bg-soft); }
  .legend { margin-top: 20px; font-size: 0.72rem; color: var(--muted); }
  .dot    { display:inline-block; width:9px; height:9px; border-radius:50%;
            margin-right:4px; vertical-align:middle; }
  /* Optimizations */
  td.opp-title { text-align: left; }
  .pgbar  { position: relative; background: var(--bg-soft); border: 1px solid var(--line);
            border-radius: 6px; height: 18px; min-width: 160px; overflow: hidden; }
  .pgfill { background: var(--accent); height: 100%; border-radius: 6px; opacity: .7; }
  .a11yfill { background: #a855f7; }
  .pgtext { position: absolute; inset: 0; display: flex; align-items: center;
            justify-content: center; font-size: 0.7rem; color: var(--ink); }
  .opp-body     { margin-top: 4px; }
  .opp-strategy { font-size: 0.72rem; font-weight: 700; color: var(--muted);
                  margin-top: 8px; text-transform: uppercase; letter-spacing: .05em; }
  .opp-list     { margin: 4px 0 0; padding-left: 18px; font-size: 0.78rem; }
  .opp-list li  { margin: 3px 0; }
  .opp-savings  { color: var(--warn); font-size: 0.72rem; }
  /* PDF export: drop the grey page background so the print is clean white,
     and reveal every collapsed per-page detail so nothing is hidden. */
  @media print {
    body { background: #ffffff; padding: 0; }
    tr.detail-row, tr.detail-row[hidden] { display: table-row !important; }
    .caret, .table-hint, th[data-col]::after { display: none; }
    /* Densify tables so every score/metric column fits on the (landscape) page
       instead of being clipped off the right edge. */
    .table-wrap { overflow: visible; border: none; }
    table { font-size: 0.6rem; }
    th, td { padding: 2px 4px; letter-spacing: 0; }
    th { white-space: normal; }
    td.gname { white-space: normal; }
    td.url-cell { max-width: 150px; }
    .badge { padding: 1px 4px !important; font-size: 0.6rem !important; }
  }
</style>
</head>
<body>
<header class="brandbar">
  <span class="logo"></span>
  <span class="brandname">Website Health Check</span>
  <span class="sp"></span>
  <span class="brandctx">PageSpeed report · powered by 2create</span>
</header>
<h1>PageSpeed Bulk Report</h1>
<div class="meta">
  Sitemap: <strong>$sitemapEsc</strong> &nbsp;|&nbsp;
  Pages tested: <strong>$count</strong> &nbsp;|&nbsp;
  Generated: <strong>$generatedAt</strong>
</div>

$summary
$groupHtml
$optHtml
$a11yHtml
$detailHtml

<div class="legend">
  <span class="dot" style="background:#2e9e5b"></span> Good (90–100) &nbsp;
  <span class="dot" style="background:#d99a2b"></span> Needs improvement (50–89) &nbsp;
  <span class="dot" style="background:#cf4a3a"></span> Poor (0–49)
</div>
<script>
(function () {
  // ── Expand/collapse per-page detail rows in Full Results ──────────────────
  document.querySelectorAll('tr.has-detail').forEach(function (row) {
    row.addEventListener('click', function (e) {
      if (e.target.closest('a')) return;            // let links open normally
      var d = row.nextElementSibling;
      if (!d || !d.classList.contains('detail-row')) return;
      var show = d.hasAttribute('hidden');
      if (show) d.removeAttribute('hidden'); else d.setAttribute('hidden', '');
      row.classList.toggle('open', show);
    });
  });

  // ── Click-to-sort tables ──────────────────────────────────────────────────
  function cellValue(row, col) {
    var td = row.querySelector('td[data-col="' + col + '"]');
    if (!td) return '';
    var v = td.getAttribute('data-v');
    return v !== null ? v : td.textContent.trim();
  }

  function compare(a, b, type, dir) {
    if (type === 'num') {
      var na = parseFloat(a), nb = parseFloat(b);
      var aN = isNaN(na), bN = isNaN(nb);
      if (aN && bN) return 0;
      if (aN) return 1;                             // blanks always last
      if (bN) return -1;
      return dir === 'asc' ? na - nb : nb - na;
    }
    a = (a || '').toLowerCase();
    b = (b || '').toLowerCase();
    if (a < b) return dir === 'asc' ? -1 : 1;
    if (a > b) return dir === 'asc' ? 1 : -1;
    return 0;
  }

  function sortTable(table, th) {
    var col  = th.getAttribute('data-col');
    var type = th.getAttribute('data-type') || 'text';
    var dir  = th.getAttribute('data-dir') === 'asc' ? 'desc' : 'asc';
    table.querySelectorAll('th[data-col]').forEach(function (h) {
      h.removeAttribute('data-dir');
    });
    th.setAttribute('data-dir', dir);

    var tbody = table.tBodies[0];
    // Group each main row with any trailing detail row so they move together.
    var groups = [], cur = null;
    Array.prototype.forEach.call(tbody.rows, function (tr) {
      if (tr.classList.contains('detail-row')) {
        if (cur) cur.extra.push(tr);
      } else {
        cur = { main: tr, extra: [] };
        groups.push(cur);
      }
    });

    groups.sort(function (g1, g2) {
      return compare(cellValue(g1.main, col), cellValue(g2.main, col), type, dir);
    });

    var frag = document.createDocumentFragment();
    groups.forEach(function (g) {
      frag.appendChild(g.main);
      g.extra.forEach(function (e) { frag.appendChild(e); });
    });
    tbody.appendChild(frag);
  }

  document.querySelectorAll('table.sortable').forEach(function (table) {
    table.querySelectorAll('th[data-col]').forEach(function (th) {
      th.addEventListener('click', function () { sortTable(table, th); });
    });
  });
})();
</script>
</body>
</html>
HTML;
}

// ─────────────────────────────────────────────────────────────────────────────
//  CSV export
// ─────────────────────────────────────────────────────────────────────────────

function build_csv(array $results, array $urlToGroup, array $strategies): string {
    $prefixes = [];
    if (in_array('mobile', $strategies, true))  $prefixes[] = ['M', 'mobile'];
    if (in_array('desktop', $strategies, true)) $prefixes[] = ['D', 'desktop'];

    $metricKeys = ['perf','a11y','bp','seo','fcp','lcp','tbt','cls','speed','tti','error'];

    $fields = ['url', 'sitemap_group'];
    foreach ($prefixes as [, $label]) {
        foreach ($metricKeys as $k) {
            $fields[] = "{$label}_{$k}";
        }
        $fields[] = "{$label}_top_optimizations";
        $fields[] = "{$label}_a11y_issues";
    }

    $fh = fopen('php://temp', 'r+');
    // Explicit escape: PHP 8.4 deprecates omitting it (default is being removed).
    fputcsv($fh, $fields, escape: '\\');
    foreach ($results as $r) {
        $row = [$r['url'], $urlToGroup[$r['url']] ?? ''];
        foreach ($prefixes as [$p, ]) {
            foreach ($metricKeys as $k) {
                $row[] = $r["{$p}_{$k}"] ?? '';
            }
            // Top 5 opportunities, "Title (savings)" joined with " | "
            $opps = array_slice($r["{$p}_opps"] ?? [], 0, 5);
            $row[] = implode(' | ', array_map(function ($o) {
                return $o['display'] !== ''
                    ? "{$o['title']} ({$o['display']})"
                    : $o['title'];
            }, $opps));
            // Accessibility issues, "Title (N elements)" joined with " | "
            $a11y = $r["{$p}_a11y_issues"] ?? [];
            $row[] = implode(' | ', array_map(function ($i) {
                return $i['count'] > 1
                    ? "{$i['title']} ({$i['count']} elements)"
                    : $i['title'];
            }, $a11y));
        }
        fputcsv($fh, $row, escape: '\\');
    }
    rewind($fh);
    $csv = stream_get_contents($fh);
    fclose($fh);
    return $csv;
}

// ─────────────────────────────────────────────────────────────────────────────
//  Console summary
// ─────────────────────────────────────────────────────────────────────────────

function print_summary(array $results, array $urlToGroup, array $strategies): void {
    echo "\n─── Performance Summary ─────────────────────────────────\n";
    foreach ($strategies as $strategy) {
        $p = strtoupper($strategy[0]);
        $scores = array_filter(
            array_map(fn($r) => $r["{$p}_perf"] ?? null, $results),
            'is_int'
        );
        if ($scores) {
            $good = count(array_filter($scores, fn($s) => $s >= 90));
            $warn = count(array_filter($scores, fn($s) => $s >= 50 && $s < 90));
            $poor = count(array_filter($scores, fn($s) => $s < 50));
            $avg  = round(array_sum($scores) / count($scores));
            printf("  %-8s avg=%3d  ✅ %d good  ⚠ %d warn  ❌ %d poor\n",
                   ucfirst($strategy), $avg, $good, $warn, $poor);
        }
    }

    // By group (mobile performance)
    $groups = [];
    foreach ($results as $r) {
        $groups[$urlToGroup[$r['url']] ?? 'Other'][] = $r;
    }
    if (count($groups) > 1) {
        ksort($groups);
        echo "\n  By group (mobile performance):\n";
        foreach ($groups as $g => $gr) {
            $scores = array_filter(
                array_map(fn($r) => $r['M_perf'] ?? null, $gr),
                'is_int'
            );
            $a = $scores ? (int)round(array_sum($scores) / count($scores)) : 0;
            $bar = str_repeat('█', intdiv($a, 10)) . str_repeat('░', 10 - intdiv($a, 10));
            printf("    %-25s %s %3d\n", $g, $bar, $a);
        }
    }
    // Top site-wide optimizations (mobile)
    $agg = [];
    foreach ($results as $r) {
        foreach (($r['M_opps'] ?? []) as $o) {
            $id = $o['id'];
            if (!isset($agg[$id])) $agg[$id] = ['title' => $o['title'], 'pages' => 0];
            $agg[$id]['pages']++;
        }
    }
    if ($agg) {
        uasort($agg, fn($a, $b) => $b['pages'] <=> $a['pages']);
        echo "\n  Top optimizations needed (mobile, by pages affected):\n";
        foreach (array_slice($agg, 0, 8) as $a) {
            printf("    %3d pages  %s\n", $a['pages'], $a['title']);
        }
    }
    // Top accessibility issues (DOM-based, strategy-independent)
    $pa = strtoupper($strategies[0][0]);
    $aggA = [];
    foreach ($results as $r) {
        foreach (($r["{$pa}_a11y_issues"] ?? []) as $iss) {
            $id = $iss['id'];
            if (!isset($aggA[$id])) $aggA[$id] = ['title' => $iss['title'], 'pages' => 0];
            $aggA[$id]['pages']++;
        }
    }
    if ($aggA) {
        uasort($aggA, fn($a, $b) => $b['pages'] <=> $a['pages']);
        echo "\n  Top accessibility issues (by pages affected):\n";
        foreach (array_slice($aggA, 0, 8) as $a) {
            printf("    %3d pages  %s\n", $a['pages'], $a['title']);
        }
    }
    echo "─────────────────────────────────────────────────────────\n\n";
}

// ─────────────────────────────────────────────────────────────────────────────
//  PDF export (optional — renders the finished HTML report via headless Chromium)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Why this is missing-friendly: the scan itself is pure PHP. Node/Playwright are
 * only needed for the optional PDF, so we check on demand and return a message
 * instead of aborting — callers warn and skip, keeping HTML/CSV intact.
 * Returns null when PDF rendering is possible, or a human-readable reason.
 */
function pdf_preflight_problem(array $args): ?string {
    $ver = trim((string)@shell_exec(escapeshellarg($args['node']) . ' --version 2>/dev/null'));
    if ($ver === '') {
        return "Node.js not found (looked for '{$args['node']}'). "
             . "Install Node 18+ for PDF export, or pass --node=/path/to/node.";
    }
    if (!is_file($args['runner'])) {
        return "PDF helper not found at {$args['runner']} (use --runner=PATH).";
    }
    $nm = dirname($args['runner']) . '/node_modules/playwright';
    if (!is_dir($nm)) {
        return "Node dependencies missing for PDF export. In " . dirname($args['runner']) . " run:\n"
             . "  npm install\n"
             . "  npx playwright install chromium";
    }
    return null;
}

/**
 * Render an already-written HTML report file to PDF using the Node helper
 * (html-to-pdf.js). Returns true on success; on failure it forwards the helper's
 * stderr and returns false (the HTML/CSV outputs are unaffected).
 */
function render_pdf(string $htmlPath, string $pdfPath, string $node, string $script): bool {
    if (!is_file($script)) {
        fwrite(STDERR, "⚠  PDF helper not found at $script\n");
        return false;
    }
    $cmd = implode(' ', array_map('escapeshellarg', [$node, $script, $htmlPath, $pdfPath]));
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($proc)) {
        fwrite(STDERR, "⚠  Could not launch Node to render the PDF.\n");
        return false;
    }
    stream_get_contents($pipes[1]);            // drain stdout
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    if ($code !== 0 || !is_file($pdfPath)) {
        if ($err !== '') fwrite(STDERR, $err);
        return false;
    }
    return true;
}

// ─────────────────────────────────────────────────────────────────────────────
//  Main
// ─────────────────────────────────────────────────────────────────────────────

function main(array $argv): void {
    $args = parse_args($argv);
    $strategies = $args['strategy'] === 'both'
        ? ['mobile', 'desktop']
        : [$args['strategy']];

    if (!$args['api-key'] && $args['engine'] === 'psi') {
        echo "⚠  No --api-key provided. Anonymous quota is ~2 req/min and may fail\n"
           . "   on large sitemaps. Get a free key:\n"
           . "   https://console.cloud.google.com/apis/library/pagespeedonline.googleapis.com\n"
           . "   (or use --engine=local to run Lighthouse without the API)\n\n";
    }

    // 1 — Collect URLs
    [$urls, $urlToGroup] = collect_urls($args['sitemap'], $args['max-urls']);
    if (!$urls) {
        fwrite(STDERR, "❌  No page URLs found. Verify the sitemap URL is accessible.\n");
        exit(1);
    }

    // 2 — Scan in parallel
    $resultsMap = $args['engine'] === 'local'
        ? scan_local($urls, $strategies, $args, null, $args['cache-dir'], $args['cache-ttl'])
        : scan_all($urls, $strategies, $args['api-key'], $args['workers'],
                   null, $args['cache-dir'], $args['cache-ttl']);

    // Preserve sitemap order
    $results = [];
    foreach ($urls as $u) {
        if (isset($resultsMap[$u])) $results[] = $resultsMap[$u];
    }

    // 3 — HTML report
    $generatedAt = date('Y-m-d H:i');
    file_put_contents($args['output'],
        build_html($results, $urlToGroup, $strategies, $args['sitemap'], $generatedAt));
    echo "\n✅  HTML report → {$args['output']}\n";

    // 4 — CSV
    file_put_contents($args['csv'], build_csv($results, $urlToGroup, $strategies));
    echo "✅  CSV export  → {$args['csv']}\n";

    // 4b — PDF (optional, rendered from the HTML report). Never fatal: the scan
    // itself is pure PHP, so a missing Node/Playwright only skips the PDF.
    if ($args['pdf']) {
        $problem = pdf_preflight_problem($args);
        if ($problem !== null) {
            fwrite(STDERR, "⚠  Skipping PDF export — " . str_replace("\n", "\n   ", $problem) . "\n");
        } else {
            echo "🖨  Rendering PDF …\n";
            if (render_pdf($args['output'], $args['pdf'], $args['node'], $args['runner'])) {
                echo "✅  PDF export  → {$args['pdf']}\n";
            } else {
                fwrite(STDERR, "⚠  PDF export failed; HTML and CSV were still written.\n");
            }
        }
    }

    // 5 — Console summary
    print_summary($results, $urlToGroup, $strategies);
}

// Only auto-run when executed directly from the CLI. When this file is
// included (e.g. by the web UI in index.php) we expose the functions only.
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    main($argv);
}
