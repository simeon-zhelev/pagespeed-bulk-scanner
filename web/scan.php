<?php
/**
 * Scan API for the PageSpeed web frontend — job-based, so any number of scans
 * run simultaneously and survive browser disconnects.
 *
 *   scan.php?action=start   (POST)
 *       Validates the form parameters, creates jobs/<id>/, spawns
 *       scan-worker.php as a DETACHED background process, and returns
 *       {"job": "<id>"} as JSON. The HTTP request ends immediately.
 *
 *   scan.php?action=stream&job=<id>   (SSE)
 *       Tails jobs/<id>/events.ndjson — written live by the worker — and
 *       relays each line as a Server-Sent Event. Honors Last-Event-ID, so a
 *       dropped/reconnected EventSource resumes where it left off instead of
 *       replaying (or losing) progress. Closing the stream never affects the
 *       running scan.
 *
 * For simultaneous scans under PHP's built-in server, start it with worker
 * processes (each SSE stream occupies one while open):
 *
 *   PHP_CLI_SERVER_WORKERS=32 php -S 127.0.0.1:8082 -t web
 */

const JOBS_DIR    = __DIR__ . '/jobs';
const JOB_MAX_AGE = 172800;   // GC job dirs older than 48 h
const STREAM_IDLE_LIMIT = 900; // give up if the worker writes nothing for 15 min

$action = $_GET['action'] ?? 'start';

// ── helpers ──────────────────────────────────────────────────────────────────

function json_out(int $code, array $payload): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

/** Best-effort cleanup of finished job directories older than JOB_MAX_AGE. */
function gc_jobs(): void {
    $cutoff = time() - JOB_MAX_AGE;
    foreach (glob(JOBS_DIR . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
        if (filemtime($dir) >= $cutoff) continue;
        foreach (glob($dir . '/*') ?: [] as $f) @unlink($f);
        @rmdir($dir);
    }
}

// ── action=start ─────────────────────────────────────────────────────────────

if ($action === 'start') {
    $in = $_POST + $_GET;   // form posts; GET kept for curl-friendliness

    $mode   = (($in['mode'] ?? 'sitemap') === 'url') ? 'url' : 'sitemap';
    $target = trim((string)($in['sitemap'] ?? ''));
    if ($target === '') {
        json_out(422, ['error' => $mode === 'url'
            ? 'Please provide a page URL to scan.'
            : 'Please provide a website or sitemap URL.']);
    }
    if (!preg_match('#^https?://#i', $target)) {
        $target = 'https://' . ltrim($target, '/');
    }
    if (!filter_var($target, FILTER_VALIDATE_URL)) {
        json_out(422, ['error' => 'Please provide a valid URL (http or https).']);
    }

    $strategy = (string)($in['strategy'] ?? 'both');
    if (!in_array($strategy, ['mobile', 'desktop', 'both'], true)) {
        $strategy = 'both';
    }

    $params = [
        'mode'       => $mode,
        'target'     => $target,
        'api_key'    => trim((string)($in['api_key'] ?? '')),
        'strategy'   => $strategy,
        'workers'    => max(1, min(25, (int)($in['workers'] ?? 15))),
        'max_urls'   => (isset($in['max_urls']) && $in['max_urls'] !== '')
                            ? max(1, (int)$in['max_urls']) : null,
        // Shared per-key API budget (req/min); not in the form on purpose —
        // override via query string if your key has a raised Google quota.
        'rate_limit' => max(0, (int)($in['rate_limit'] ?? 240)),
    ];

    if (!is_dir(JOBS_DIR) && !@mkdir(JOBS_DIR, 0777, true)) {
        json_out(500, ['error' => 'Could not create the jobs directory.']);
    }
    gc_jobs();

    $jobId  = date('Ymd-His') . '-' . bin2hex(random_bytes(4));
    $jobDir = JOBS_DIR . '/' . $jobId;
    if (!@mkdir($jobDir, 0777, true)) {
        json_out(500, ['error' => 'Could not create the job directory.']);
    }
    file_put_contents($jobDir . '/params.json', json_encode($params));

    // Spawn the worker fully detached: it survives this request and any
    // number of stream connects/disconnects.
    $cmd = sprintf('%s %s %s > /dev/null 2>&1 &',
        escapeshellarg(PHP_BINARY),
        escapeshellarg(__DIR__ . '/scan-worker.php'),
        escapeshellarg($jobId));
    exec($cmd);

    json_out(200, ['job' => $jobId]);
}

// ── action=stream ────────────────────────────────────────────────────────────

if ($action !== 'stream') {
    json_out(404, ['error' => 'Unknown action.']);
}

$jobId = (string)($_GET['job'] ?? '');
if (!preg_match('/^[A-Za-z0-9-]{1,64}$/', $jobId) || !is_dir(JOBS_DIR . '/' . $jobId)) {
    json_out(404, ['error' => 'Unknown job.']);
}
$eventsFile = JOBS_DIR . '/' . $jobId . '/events.ndjson';

@ini_set('zlib.output_compression', '0');
@ini_set('output_buffering', '0');
while (ob_get_level() > 0) ob_end_flush();
set_time_limit(0);

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-transform');
header('Connection: keep-alive');
header('X-Accel-Buffering: no'); // disable nginx buffering if proxied

// EventSource reconnects send the id of the last line received — resume there.
$afterLine = (int)($_SERVER['HTTP_LAST_EVENT_ID'] ?? 0);

$line       = 0;        // 1-based line counter == SSE event id
$offset     = 0;        // byte offset consumed so far
$buffer     = '';
$lastData   = time();   // last time the worker produced anything
$lastPing   = time();

while (true) {
    clearstatcache(false, $eventsFile);
    $size = is_file($eventsFile) ? (int)filesize($eventsFile) : 0;

    if ($size > $offset) {
        $fh = fopen($eventsFile, 'r');
        fseek($fh, $offset);
        $buffer .= stream_get_contents($fh);
        $offset = ftell($fh);
        fclose($fh);
        $lastData = time();

        // Process only complete lines; a partial write stays buffered.
        while (($nl = strpos($buffer, "\n")) !== false) {
            $raw    = substr($buffer, 0, $nl);
            $buffer = substr($buffer, $nl + 1);
            $line++;
            if ($line <= $afterLine || $raw === '') continue;

            $ev = json_decode($raw, true);
            if (!is_array($ev) || !isset($ev['event'])) continue;

            echo "id: {$line}\n";
            echo "event: {$ev['event']}\n";
            echo 'data: ' . json_encode($ev['data'] ?? []) . "\n\n";
            @flush();

            if ($ev['event'] === 'done' || $ev['event'] === 'error') {
                exit;   // terminal — the scan is over
            }
        }
    } else {
        if (connection_aborted()) {
            exit;       // viewer left; the worker keeps running
        }
        if (time() - $lastData > STREAM_IDLE_LIMIT) {
            echo "event: error\n";
            echo 'data: ' . json_encode(['message' =>
                'No progress from the scan worker for 15 minutes — it may have died. '
                . 'Check the server console.']) . "\n\n";
            @flush();
            exit;
        }
        if (time() - $lastPing >= 15) {
            echo ": ping\n\n";  // SSE comment keepalive
            @flush();
            $lastPing = time();
        }
        usleep(250000);
    }
}
