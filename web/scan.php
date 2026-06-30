<?php
/**
 * Server-Sent Events endpoint for the PageSpeed web frontend.
 *
 * Reuses the scanner functions from ../pagespeed_scanner.php, runs a scan with
 * the parameters from the query string, streams live progress to the browser,
 * then writes the standalone HTML report + CSV into ./reports/ and emits their
 * URLs.
 *
 * Events emitted (SSE `event:` types):
 *   status   {message}
 *   meta     {total, urls, workers, strategies}
 *   page     {done, total, url, strategy, ok, perf, error}
 *   done     {summary, reportUrl, csvUrl}
 *   error    {message}
 */

// The scanner file carries a `#!/usr/bin/env php` shebang for direct CLI
// execution; outside the CLI that line is echoed as text. Buffer the include
// and discard any such stray output before we send SSE headers.
ob_start();
require __DIR__ . '/../pagespeed_scanner.php';
ob_end_clean();

// ── SSE plumbing ─────────────────────────────────────────────────────────────
@ini_set('zlib.output_compression', '0');
@ini_set('output_buffering', '0');
while (ob_get_level() > 0) ob_end_flush();
set_time_limit(0);
ignore_user_abort(true);

// STDERR is only predefined under the CLI SAPI; some scanner paths write there.
if (!defined('STDERR')) {
    define('STDERR', fopen('php://stderr', 'w'));
}

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-transform');
header('Connection: keep-alive');
header('X-Accel-Buffering: no'); // disable nginx buffering if proxied

function sse(string $event, array $data): void {
    echo "event: {$event}\n";
    echo 'data: ' . json_encode($data) . "\n\n";
    @flush();
}

function fail(string $message): void {
    sse('error', ['message' => $message]);
    exit;
}

// ── Build the scan arguments from the query string ───────────────────────────
$mode = (($_GET['mode'] ?? 'sitemap') === 'url') ? 'url' : 'sitemap';

// Accepts a sitemap URL, a plain site URL/domain (auto-discovered), or — in
// single-page mode — a page URL. All arrive in the same `sitemap` field.
$target = trim((string)($_GET['sitemap'] ?? ''));
if ($target === '') {
    fail($mode === 'url'
        ? 'Please provide a page URL to scan.'
        : 'Please provide a website or sitemap URL.');
}
if (!preg_match('#^https?://#i', $target)) {
    $target = 'https://' . ltrim($target, '/');
}
if (!filter_var($target, FILTER_VALIDATE_URL)) {
    fail('Please provide a valid URL (http or https).');
}

$apiKey   = trim((string)($_GET['api_key'] ?? ''));
$strategy = (string)($_GET['strategy'] ?? 'both');
if (!in_array($strategy, ['mobile', 'desktop', 'both'], true)) {
    $strategy = 'both';
}
$strategies = $strategy === 'both' ? ['mobile', 'desktop'] : [$strategy];

$workers = max(1, min(25, (int)($_GET['workers'] ?? 5)));
$maxUrls = (isset($_GET['max_urls']) && $_GET['max_urls'] !== '')
    ? max(1, (int)$_GET['max_urls']) : null;

if (!$apiKey) {
    sse('status', ['message' => 'No API key — anonymous quota is small and may rate-limit.']);
}

// ── Resolve URLs to scan ─────────────────────────────────────────────────────
if ($mode === 'url') {
    // Single-page mode: scan exactly the URL provided.
    $sitemapUrl = $target;
    $urls       = [$target];
    $urlToGroup = [$target => 'Page'];
} else {
    // Whole-site mode: resolve a sitemap (direct URL or auto-discover), crawl it.
    if (!looks_like_sitemap($target)) {
        sse('status', ['message' => 'Looking for the sitemap…']);
    }
    try {
        ob_start();
        $resolved = discover_sitemap($target);
        ob_end_clean();
    } catch (Throwable $e) {
        if (ob_get_level() > 0) ob_end_clean();
        $resolved = null;
    }
    if ($resolved === null) {
        fail("Could not find a sitemap for '{$target}'. "
           . 'Try entering the sitemap URL directly (e.g. https://example.com/sitemap_index.xml).');
    }
    $sitemapUrl = $resolved;

    try {
        sse('status', ['message' => 'Crawling the sitemap…']);
        // collect_urls() prints crawl progress to stdout; keep it out of the stream.
        ob_start();
        [$urls, $urlToGroup] = collect_urls($sitemapUrl, $maxUrls);
        ob_end_clean();
    } catch (Throwable $e) {
        if (ob_get_level() > 0) ob_end_clean();
        fail('Could not read the sitemap: ' . $e->getMessage());
    }
    if (!$urls) {
        fail('No page URLs found. Verify the sitemap URL is reachable and valid.');
    }
}

$pageCount = count($urls);
sse('status', ['message' => "Found {$pageCount} page" . ($pageCount === 1 ? '' : 's')
    . '. Querying PageSpeed Insights…']);

// ── Run the scan ─────────────────────────────────────────────────────────────
$resultsMap = scan_all($urls, $strategies, $apiKey ?: null, $workers, function (array $ev) use ($pageCount) {
    $phase = $ev['phase'] ?? '';
    if ($phase === 'scan-start') {
        sse('meta', [
            'total'      => $ev['total'],
            'urls'       => $ev['urls'],
            'workers'    => $ev['workers'],
            'strategies' => $ev['strategies'],
        ]);
    } elseif ($phase === 'job') {
        sse('page', [
            'done'     => $ev['done'],
            'total'    => $ev['total'],
            'url'      => $ev['url'],
            'strategy' => $ev['strategy'],
            'ok'       => $ev['ok'],
            'perf'     => $ev['perf'],
            'error'    => $ev['error'],
        ]);
    } elseif ($phase === 'rate-limit') {
        sse('status', ['message' => "Rate-limited on {$ev['url']} — pausing 15 s…"]);
    }
});

// Preserve sitemap order
$results = [];
foreach ($urls as $u) {
    if (isset($resultsMap[$u])) $results[] = $resultsMap[$u];
}
if (!$results) {
    fail('No results returned from the scan.');
}

// ── Build the report + CSV and write them where the browser can fetch them ────
sse('status', ['message' => 'Building the report…']);
$generatedAt = date('Y-m-d H:i');

if (!is_dir(__DIR__ . '/reports')) @mkdir(__DIR__ . '/reports', 0777, true);
$id      = date('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 6);
$htmlRel = "reports/{$id}.html";
$csvRel  = "reports/{$id}.csv";
$pdfRel  = "reports/{$id}.pdf";

file_put_contents(__DIR__ . '/' . $htmlRel,
    build_html($results, $urlToGroup, $strategies, $sitemapUrl, $generatedAt));
file_put_contents(__DIR__ . '/' . $csvRel,
    build_csv($results, $urlToGroup, $strategies));

// Render a PDF from the HTML report (best-effort; the report still works without
// it, and the PDF engine — Node/Playwright — is optional for this tool).
$pdfArgs = ['node' => 'node', 'runner' => dirname(__DIR__) . '/html-to-pdf.js'];
$pdfOk = false;
if (pdf_preflight_problem($pdfArgs) === null) {
    sse('status', ['message' => 'Rendering the PDF…']);
    $pdfOk = render_pdf(
        __DIR__ . '/' . $htmlRel,
        __DIR__ . '/' . $pdfRel,
        $pdfArgs['node'],
        $pdfArgs['runner']
    );
}

// ── Summarise for the result cards ───────────────────────────────────────────
$avg = function (string $prefix, string $cat) use ($results) {
    $nums = [];
    foreach ($results as $r) {
        $v = $r["{$prefix}_{$cat}"] ?? null;
        if (is_int($v)) $nums[] = $v;
    }
    return $nums ? (int)round(array_sum($nums) / count($nums)) : null;
};
// Prefix used for category scores that are strategy-independent (a11y/bp/seo):
// prefer mobile data when present.
$catP = in_array('mobile', $strategies, true) ? 'M' : 'D';

// Count pages by mobile-or-desktop performance band, and pages that errored.
$poor = $errorPages = 0;
foreach ($results as $r) {
    $perfs = [];
    foreach ($strategies as $s) {
        $p = strtoupper($s[0]);
        $v = $r["{$p}_perf"] ?? null;
        if (is_int($v)) $perfs[] = $v;
    }
    if (!$perfs) { $errorPages++; continue; }
    if (min($perfs) < 50) $poor++;
}

$summary = [
    'pages'      => count($results),
    'mobilePerf' => in_array('mobile', $strategies, true)  ? $avg('M', 'perf') : null,
    'deskPerf'   => in_array('desktop', $strategies, true) ? $avg('D', 'perf') : null,
    'a11y'       => $avg($catP, 'a11y'),
    'seo'        => $avg($catP, 'seo'),
    'bp'         => $avg($catP, 'bp'),
    'poorPages'  => $poor,
    'errorPages' => $errorPages,
];

sse('done', [
    'reportUrl' => $htmlRel,
    'csvUrl'    => $csvRel,
    'pdfUrl'    => $pdfOk ? $pdfRel : null,
    'summary'   => $summary,
]);
