<?php
/**
 * Detached scan worker for the web frontend.
 *
 * Started by scan.php?action=start as a background CLI process:
 *
 *   php scan-worker.php <job-id>
 *
 * Reads  jobs/<id>/params.json  (written by scan.php), runs the scan, and
 * appends progress events to  jobs/<id>/events.ndjson  — one JSON object per
 * line: {"event": "...", "data": {...}}. scan.php?action=stream tails that
 * file and relays the lines to the browser as Server-Sent Events.
 *
 * Because the worker is its own process, the scan is independent of the HTTP
 * connection: a closed tab or dropped SSE stream never kills a running scan,
 * and any number of scans run simultaneously. Reports are written to
 * ./reports/ exactly as before.
 *
 * Event types (mirrored 1:1 to SSE event types by the stream endpoint):
 *   status   {message}
 *   meta     {total, urls, workers, strategies}
 *   page     {done, total, url, strategy, ok, perf, error}
 *   done     {summary, reportUrl, csvUrl, pdfUrl}
 *   error    {message}
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// The scanner file carries a shebang line; buffer the include to discard it.
ob_start();
require __DIR__ . '/../pagespeed_scanner.php';
ob_end_clean();

$jobId = $argv[1] ?? '';
if (!preg_match('/^[A-Za-z0-9-]{1,64}$/', $jobId)) {
    fwrite(STDERR, "Usage: php scan-worker.php <job-id>\n");
    exit(1);
}
$jobDir     = __DIR__ . '/jobs/' . $jobId;
$eventsFile = $jobDir . '/events.ndjson';
$paramsFile = $jobDir . '/params.json';

if (!is_dir($jobDir) || !is_file($paramsFile)) {
    fwrite(STDERR, "Job dir/params not found: $jobDir\n");
    exit(1);
}

/** Append one event line for the SSE stream endpoint to pick up. */
function emit(string $event, array $data): void {
    global $eventsFile;
    file_put_contents($eventsFile,
        json_encode(['event' => $event, 'data' => $data]) . "\n",
        FILE_APPEND | LOCK_EX);
}

function fail(string $message): void {
    $GLOBALS['psbs_finished'] = true;   // this IS the terminal event
    emit('error', ['message' => $message]);
    exit(0);
}

// If the worker dies without a terminal event (fatal error, kill), make sure
// the stream endpoint — and the user — still learn about it.
$GLOBALS['psbs_finished'] = false;
register_shutdown_function(function (): void {
    if (!$GLOBALS['psbs_finished']) {
        emit('error', ['message' =>
            'The scan worker stopped unexpectedly. Check the server console for details.']);
    }
});

// ── Job parameters (validated by scan.php before spawning) ───────────────────
$p = json_decode((string)file_get_contents($paramsFile), true);
if (!is_array($p)) {
    fail('Could not read the job parameters.');
}

$mode       = ($p['mode'] ?? 'sitemap') === 'url' ? 'url' : 'sitemap';
$target     = (string)($p['target'] ?? '');
$apiKey     = (string)($p['api_key'] ?? '');
$strategy   = (string)($p['strategy'] ?? 'both');
$strategies = $strategy === 'both' ? ['mobile', 'desktop'] : [$strategy];
$workers    = max(1, min(25, (int)($p['workers'] ?? 15)));
$maxUrls    = isset($p['max_urls']) && $p['max_urls'] !== null ? max(1, (int)$p['max_urls']) : null;
$rateLimit  = max(0, (int)($p['rate_limit'] ?? 240));

if (!$apiKey) {
    emit('status', ['message' => 'No API key — anonymous quota is small and may rate-limit.']);
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
        emit('status', ['message' => 'Looking for the sitemap…']);
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
        emit('status', ['message' => 'Crawling the sitemap…']);
        // collect_urls() prints crawl progress to stdout; keep it out of the log.
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
emit('status', ['message' => "Found {$pageCount} page" . ($pageCount === 1 ? '' : 's')
    . '. Querying PageSpeed Insights…']);

// ── Run the scan ─────────────────────────────────────────────────────────────
$resultsMap = scan_all($urls, $strategies, $apiKey ?: null, $workers, function (array $ev) {
    $phase = $ev['phase'] ?? '';
    if ($phase === 'scan-start') {
        emit('meta', [
            'total'      => $ev['total'],
            'urls'       => $ev['urls'],
            'workers'    => $ev['workers'],
            'strategies' => $ev['strategies'],
        ]);
    } elseif ($phase === 'job') {
        emit('page', [
            'done'     => $ev['done'],
            'total'    => $ev['total'],
            'url'      => $ev['url'],
            'strategy' => $ev['strategy'],
            'ok'       => $ev['ok'],
            'perf'     => $ev['perf'],
            'error'    => $ev['error'],
        ]);
    } elseif ($phase === 'retry') {
        $code = $ev['code'] ?? 0;
        $why  = $code === 429 ? 'Rate-limited (429)'
              : ($code === 'lighthouse' ? 'Page load timed out' : 'Transient error');
        emit('status', ['message' =>
            "{$why} on {$ev['url']} — retry #{$ev['attempt']} in {$ev['delay']} s (scan continues)…"]);
    }
}, null, 86400, $rateLimit);

// Preserve sitemap order
$results = [];
foreach ($urls as $u) {
    if (isset($resultsMap[$u])) $results[] = $resultsMap[$u];
}
if (!$results) {
    fail('No results returned from the scan.');
}

// ── Build the report + CSV and write them where the browser can fetch them ────
emit('status', ['message' => 'Building the report…']);
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
    emit('status', ['message' => 'Rendering the PDF…']);
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
        $pfx = strtoupper($s[0]);
        $v = $r["{$pfx}_perf"] ?? null;
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

emit('done', [
    'reportUrl' => $htmlRel,
    'csvUrl'    => $csvRel,
    'pdfUrl'    => $pdfOk ? $pdfRel : null,
    'summary'   => $summary,
]);
$GLOBALS['psbs_finished'] = true;
