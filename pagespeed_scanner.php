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
    ];
    $opts = getopt('', [
        'sitemap:', 'api-key:', 'strategy:', 'max-urls:',
        'workers:', 'output:', 'csv:', 'help',
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

    if (!in_array($args['strategy'], ['mobile', 'desktop', 'both'], true)) {
        fwrite(STDERR, "❌  --strategy must be mobile, desktop, or both\n");
        exit(1);
    }
    return $args;
}

// ─────────────────────────────────────────────────────────────────────────────
//  HTTP helper
// ─────────────────────────────────────────────────────────────────────────────

function http_get(string $url, int $timeout = 30): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_USERAGENT      => 'PageSpeedBulkScanner-PHP/1.0',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_ENCODING       => '',   // accept + auto-decode gzip/deflate transfer encoding
    ]);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

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

/**
 * Run all (url × strategy) jobs through a rolling curl_multi pool.
 * Returns: map  pageUrl => result-row (merged across strategies).
 */
function scan_all(array $urls, array $strategies, ?string $apiKey, int $workers): array {
    // Build the full job list: one HTTP request per url+strategy pair
    $jobs = [];
    foreach ($urls as $url) {
        foreach ($strategies as $strategy) {
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

    $totalJobs = count($jobs);
    $doneJobs  = 0;
    echo "⚡  Scanning " . count($urls) . " URLs × " . count($strategies)
       . " strategies = $totalJobs requests, $workers parallel workers …\n\n";

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
            curl_close($ch);

            $url    = $job['url'];
            $prefix = $job['prefix'];

            if ($code === 429 && !$job['retried']) {
                // Rate-limited: requeue once after a pause
                echo "    ⏳  429 rate-limited ({$job['strategy']}) $url — retrying in 15 s …\n";
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
                echo "  [$doneJobs/$totalJobs] ✗ [{$job['strategy']}] $url — $msg\n";
            } else {
                $parsed = parse_psi_response($body, $prefix);
                $results[$url] = array_merge($results[$url], $parsed);
                $score = $parsed["{$prefix}_perf"] ?? '—';
                $scoreStr = $score === null ? 'ERR' : $score;
                echo "  [$doneJobs/$totalJobs] ✓ [{$job['strategy']}] perf=$scoreStr  $url\n";
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

<div class="section-title">🔧 Top Optimizations — $icon $sCap</div>
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
 * Per-page collapsible list of the top issues for that page.
 */
function per_page_optimizations(array $results, array $strategies): string {
    $items = '';
    foreach ($results as $r) {
        $url   = $r['url'];
        $short = htmlspecialchars(preg_replace('#^https?://#', '', $url));
        $urlEsc = htmlspecialchars($url);

        $body = '';
        foreach ($strategies as $strategy) {
            $p    = strtoupper($strategy[0]);
            $opps = array_slice($r["{$p}_opps"] ?? [], 0, 6);
            if (!$opps) continue;
            $icon = $strategy === 'mobile' ? '📱' : '🖥';
            $body .= "<div class=\"opp-strategy\">$icon " . ucfirst($strategy) . '</div><ul class="opp-list">';
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
        if ($body === '') continue;

        $perfM = $r['M_perf'] ?? null;
        $perfD = $r['D_perf'] ?? null;
        $badges = '';
        if ($perfM !== null) $badges .= ' 📱' . score_badge($perfM);
        if ($perfD !== null) $badges .= ' 🖥' . score_badge($perfD);

        $items .= <<<HTML

  <details class="opp-details">
    <summary><a href="$urlEsc" target="_blank" rel="noopener">$short</a>$badges</summary>
    <div class="opp-body">$body</div>
  </details>
HTML;
    }

    if ($items === '') return '';
    return <<<HTML

<div class="section-title">📝 Optimizations Per Page</div>
<div class="opp-container">$items
</div>
HTML;
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
        return '<div class="section-title">♿ Accessibility Issues</div>'
             . '<p style="font-size:0.85rem;color:#22c55e">'
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

<div class="section-title">♿ Accessibility Issues (WCAG, automated checks)</div>
<div class="table-wrap" style="margin-top:10px">
  <table>
    <thead>
      <tr><th style="text-align:left">Issue</th>
          <th>Pages affected</th><th>Total elements</th></tr>
    </thead>
    <tbody>$rows</tbody>
  </table>
</div>
<p style="font-size:0.72rem;color:#64748b;margin-top:8px">
  ⚠ Automated checks catch only ~30–40% of WCAG issues. Full ADA compliance also
  requires manual keyboard, screen-reader, and focus-order testing.
</p>
HTML;
}

function score_color(?int $score): string {
    if ($score === null) return '#9ca3af';
    if ($score >= 90)    return '#22c55e';
    if ($score >= 50)    return '#f59e0b';
    return '#ef4444';
}

function score_badge(?int $score): string {
    $c     = score_color($score);
    $label = $score === null ? 'ERR' : (string)$score;
    return "<span style=\"background:$c;color:#fff;padding:2px 8px;"
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
            $rows .= "<tr><td class=\"gname\">$gEsc</td>"
                   . "<td>$icon $sCap</td><td>$n</td>"
                   . "<td><span style=\"color:$color;font-weight:700\">$a</span></td>"
                   . "<td>$good</td><td>$warn</td><td>$poor</td></tr>";
        }
    }

    return <<<HTML

<div class="section-title">📂 By Sitemap Group</div>
<div class="table-wrap" style="margin-top:10px">
  <table>
    <thead>
      <tr>
        <th>Sitemap group</th><th>Strategy</th><th>Pages</th>
        <th>Avg Perf</th><th>✅ Good</th><th>⚠ Warn</th><th>❌ Poor</th>
      </tr>
    </thead>
    <tbody>$rows</tbody>
  </table>
</div>
HTML;
}

function metric_cols(string $p, array $r): string {
    $err = $r["{$p}_error"] ?? null;
    if ($err) {
        $errEsc = htmlspecialchars(mb_substr($err, 0, 120));
        return "<td colspan=\"10\" style=\"color:#ef4444;font-size:0.72rem\">⚠ $errEsc</td>";
    }
    $cols = '';
    foreach (['perf','a11y','bp','seo'] as $c) {
        $cols .= '<td>' . score_badge($r["{$p}_{$c}"] ?? null) . '</td>';
    }
    foreach (['fcp','lcp','tbt','cls','speed','tti'] as $m) {
        $val = htmlspecialchars($r["{$p}_{$m}"] ?? '—');
        $cols .= "<td>$val</td>";
    }
    return $cols;
}

function detail_table(array $results, array $urlToGroup, array $strategies): string {
    $hasM = in_array('mobile', $strategies, true);
    $hasD = in_array('desktop', $strategies, true);
    $hasGroups = count(array_unique(array_values($urlToGroup))) > 1;

    $scoreHeads  = '<th>Perf</th><th>A11y</th><th>Best P.</th><th>SEO</th>';
    $metricHeads = '<th>FCP</th><th>LCP</th><th>TBT</th><th>CLS</th><th>Speed Idx</th><th>TTI</th>';
    $groupHead   = $scoreHeads . $metricHeads;

    $groupCol = $hasGroups ? '<th>Group</th>' : '';
    $thead = "<tr><th>#</th><th>URL</th>$groupCol";
    if ($hasM) $thead .= '<th colspan="10" style="background:#1e40af;color:#fff">📱 Mobile</th>';
    if ($hasD) $thead .= '<th colspan="10" style="background:#065f46;color:#fff">🖥 Desktop</th>';
    $thead .= '</tr><tr><th></th><th></th>' . ($hasGroups ? '<th></th>' : '');
    if ($hasM) $thead .= $groupHead;
    if ($hasD) $thead .= $groupHead;
    $thead .= '</tr>';

    $rows = '';
    $i = 0;
    foreach ($results as $r) {
        $i++;
        $url   = $r['url'];
        $urlEsc = htmlspecialchars($url);
        $short = htmlspecialchars(preg_replace('#^https?://#', '', $url));
        $group = htmlspecialchars($urlToGroup[$url] ?? '');
        $gc    = $hasGroups ? "<td class=\"gname\">$group</td>" : '';
        $mCols = $hasM ? metric_cols('M', $r) : '';
        $dCols = $hasD ? metric_cols('D', $r) : '';
        $rows .= "<tr><td class=\"num\">$i</td>"
               . "<td class=\"url-cell\"><a href=\"$urlEsc\" target=\"_blank\" rel=\"noopener\">$short</a></td>"
               . "$gc$mCols$dCols</tr>";
    }

    return <<<HTML

<div class="section-title">📋 Full Results</div>
<div class="table-wrap">
  <table>
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
    $perPageHtml= per_page_optimizations($results, $strategies);
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
<style>
  *, *::before, *::after { box-sizing: border-box; }
  body  { font-family: system-ui, -apple-system, sans-serif;
          background: #0f172a; color: #e2e8f0; margin: 0; padding: 24px 28px; }
  h1    { font-size: 1.6rem; margin-bottom: 4px; color: #f8fafc; }
  .meta { font-size: 0.8rem; color: #64748b; margin-bottom: 28px; }
  .section-title { font-size: 0.8rem; font-weight: 700; color: #64748b;
                   text-transform: uppercase; letter-spacing: .1em;
                   margin: 32px 0 10px; }
  .cards { display: flex; flex-wrap: wrap; gap: 12px; }
  .card  { background: #1e293b; border-radius: 10px; padding: 16px 22px;
           min-width: 148px; flex: 1; }
  .card-label { font-size: 0.72rem; color: #94a3b8; text-transform: uppercase;
                letter-spacing: .06em; }
  .card-score { font-size: 2.4rem; font-weight: 700; line-height: 1.1; margin: 4px 0; }
  .card-sub   { font-size: 0.7rem; color: #64748b; }
  .table-wrap { overflow-x: auto; border-radius: 10px; background: #1e293b; margin-top: 4px; }
  table  { width: 100%; border-collapse: collapse; font-size: 0.77rem; }
  th, td { padding: 8px 10px; text-align: center; border-bottom: 1px solid #334155; }
  th     { background: #0f172a; color: #94a3b8; font-weight: 600;
           text-transform: uppercase; letter-spacing: .05em; white-space: nowrap; }
  td.url-cell { text-align: left; max-width: 280px; overflow: hidden;
                text-overflow: ellipsis; white-space: nowrap; }
  td.url-cell a { color: #93c5fd; text-decoration: none; }
  td.url-cell a:hover { text-decoration: underline; }
  td.gname { text-align: left; font-size: 0.72rem; color: #94a3b8; white-space: nowrap; }
  td.num   { color: #475569; width: 32px; }
  tr:hover td { background: #263045; }
  .legend { margin-top: 20px; font-size: 0.72rem; color: #64748b; }
  .dot    { display:inline-block; width:9px; height:9px; border-radius:50%;
            margin-right:4px; vertical-align:middle; }
  /* Optimizations */
  td.opp-title { text-align: left; }
  .pgbar  { position: relative; background: #0f172a; border-radius: 6px;
            height: 18px; min-width: 160px; overflow: hidden; }
  .pgfill { background: #3b82f6; height: 100%; border-radius: 6px; opacity: .55; }
  .a11yfill { background: #a855f7; }
  .pgtext { position: absolute; inset: 0; display: flex; align-items: center;
            justify-content: center; font-size: 0.7rem; color: #e2e8f0; }
  .opp-container { display: flex; flex-direction: column; gap: 6px; }
  .opp-details   { background: #1e293b; border-radius: 8px; padding: 10px 14px; }
  .opp-details summary { cursor: pointer; font-size: 0.82rem; }
  .opp-details summary a { color: #93c5fd; text-decoration: none; }
  .opp-details summary a:hover { text-decoration: underline; }
  .opp-body     { margin-top: 8px; }
  .opp-strategy { font-size: 0.72rem; font-weight: 700; color: #94a3b8;
                  margin-top: 8px; text-transform: uppercase; letter-spacing: .05em; }
  .opp-list     { margin: 4px 0 0; padding-left: 18px; font-size: 0.78rem; }
  .opp-list li  { margin: 3px 0; }
  .opp-savings  { color: #f59e0b; font-size: 0.72rem; }
</style>
</head>
<body>
<h1>🚀 PageSpeed Bulk Report</h1>
<div class="meta">
  Sitemap: <strong>$sitemapEsc</strong> &nbsp;|&nbsp;
  Pages tested: <strong>$count</strong> &nbsp;|&nbsp;
  Generated: <strong>$generatedAt</strong>
</div>

$summary
$groupHtml
$optHtml
$a11yHtml
$perPageHtml
$detailHtml

<div class="legend">
  <span class="dot" style="background:#22c55e"></span> Good (90–100) &nbsp;
  <span class="dot" style="background:#f59e0b"></span> Needs improvement (50–89) &nbsp;
  <span class="dot" style="background:#ef4444"></span> Poor (0–49)
</div>
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
    fputcsv($fh, $fields);
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
        fputcsv($fh, $row);
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
//  Main
// ─────────────────────────────────────────────────────────────────────────────

function main(array $argv): void {
    $args = parse_args($argv);
    $strategies = $args['strategy'] === 'both'
        ? ['mobile', 'desktop']
        : [$args['strategy']];

    if (!$args['api-key']) {
        echo "⚠  No --api-key provided. Anonymous quota is ~2 req/min and may fail\n"
           . "   on large sitemaps. Get a free key:\n"
           . "   https://console.cloud.google.com/apis/library/pagespeedonline.googleapis.com\n\n";
    }

    // 1 — Collect URLs
    [$urls, $urlToGroup] = collect_urls($args['sitemap'], $args['max-urls']);
    if (!$urls) {
        fwrite(STDERR, "❌  No page URLs found. Verify the sitemap URL is accessible.\n");
        exit(1);
    }

    // 2 — Scan in parallel
    $resultsMap = scan_all($urls, $strategies, $args['api-key'], $args['workers']);

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

    // 5 — Console summary
    print_summary($results, $urlToGroup, $strategies);
}

main($argv);
