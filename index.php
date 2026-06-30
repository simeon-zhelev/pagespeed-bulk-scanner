<?php
/**
 * PageSpeed Bulk Scanner — Web UI
 * ------------------------------------------------
 * A single-file front-end for pagespeed_scanner.php.
 *
 *   php -S localhost:8000        # then open http://localhost:8000
 *
 * Routes (all on this one file):
 *   GET  /                       → the form
 *   GET  /?action=scan&...       → Server-Sent Events stream (live progress)
 *   GET  /?report=<file>         → serve a generated HTML report
 */

// The scanner starts with a `#!/usr/bin/env php` shebang (for CLI use) which
// would otherwise be emitted as text on include — buffer it away.
ob_start();
require_once __DIR__ . '/pagespeed_scanner.php';
ob_end_clean();

const REPORTS_DIR = __DIR__ . '/reports';

// ─────────────────────────────────────────────────────────────────────────────
//  Route: serve a previously generated report
// ─────────────────────────────────────────────────────────────────────────────
if (isset($_GET['report'])) {
    // Prevent path traversal: only allow plain report file names we generate.
    $name = basename($_GET['report']);
    $path = REPORTS_DIR . '/' . $name;
    if (preg_match('/^report-[\w.-]+\.(html|csv)$/', $name) && is_file($path)) {
        $isCsv = str_ends_with($name, '.csv');
        header('Content-Type: ' . ($isCsv ? 'text/csv' : 'text/html') . '; charset=utf-8');
        if ($isCsv) header('Content-Disposition: attachment; filename="' . $name . '"');
        readfile($path);
    } else {
        http_response_code(404);
        echo 'Report not found.';
    }
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
//  Route: run a scan and stream progress over Server-Sent Events
// ─────────────────────────────────────────────────────────────────────────────
if (($_GET['action'] ?? '') === 'scan') {
    run_scan_stream();
    exit;
}

/**
 * Stream a scan as SSE. Reuses the scanner's library functions and captures
 * its echoed progress lines, forwarding each as an SSE `log` event. On
 * completion sends a `done` event carrying the generated report URLs.
 */
function run_scan_stream(): void {
    // SSE headers — disable any buffering between us and the browser.
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no');
    @ini_set('zlib.output_compression', '0');
    @ini_set('output_buffering', '0');
    @ini_set('implicit_flush', '1');
    set_time_limit(0);
    ignore_user_abort(true);
    while (ob_get_level() > 0) ob_end_flush();
    ob_implicit_flush(true);

    // Build a single SSE frame string (one event, multi-line safe).
    $frame = function (string $event, $data): string {
        if (!is_string($data)) $data = json_encode($data);
        $out = "event: $event\n";
        foreach (explode("\n", $data) as $line) {
            $out .= 'data: ' . $line . "\n";
        }
        return $out . "\n";
    };
    // Emit a frame straight to the client (only call when no capture buffer is active).
    $sse = function (string $event, $data) use ($frame): void {
        echo $frame($event, $data);
        @flush();
    };
    // Write a line to the server terminal (stderr). The PHP built-in server and
    // most SAPIs surface error_log() output on the console where it was started,
    // so the operator sees errors live while a scan runs.
    $term = function (string $level, string $msg): void {
        error_log('[pagespeed-scanner] ' . strtoupper($level) . ': ' . $msg);
    };
    // Report an error to BOTH the browser (SSE) and the terminal.
    $fail = function (string $msg) use ($sse, $term): void {
        $term('error', $msg);
        $sse('error', $msg);
    };

    // ── Validate / normalise parameters ─────────────────────────────────────
    $mode     = ($_GET['mode'] ?? 'sitemap') === 'url' ? 'url' : 'sitemap';
    $sitemap  = trim($_GET['sitemap'] ?? '');
    $apiKey   = trim($_GET['api_key'] ?? '');
    $strategy = $_GET['strategy'] ?? 'both';
    $workers  = max(1, min(25, (int)($_GET['workers'] ?? 5)));
    $maxUrls  = ($_GET['max_urls'] ?? '') !== '' ? max(1, (int)$_GET['max_urls']) : null;

    if (!filter_var($sitemap, FILTER_VALIDATE_URL)) {
        $fail($mode === 'url'
            ? 'Please provide a valid page URL (including https://).'
            : 'Please provide a valid sitemap URL (including https://).');
        return;
    }
    if (!in_array($strategy, ['mobile', 'desktop', 'both'], true)) {
        $strategy = 'both';
    }
    $strategies = $strategy === 'both' ? ['mobile', 'desktop'] : [$strategy];
    $term('info', "scan started — mode=$mode strategy=$strategy workers=$workers target=$sitemap");

    if (!$apiKey) {
        $sse('log', '⚠  No API key — anonymous quota is ~2 req/min and may fail on large sitemaps.');
    }

    // Capture the scanner's echo() progress and forward it line-by-line as SSE.
    // Pure handler: accumulate, return one `log` frame per complete line.
    $buffer = '';
    $handler = function (string $chunk) use (&$buffer, $frame, $term): string {
        $buffer .= $chunk;
        $out = '';
        while (($nl = strpos($buffer, "\n")) !== false) {
            $line = rtrim(substr($buffer, 0, $nl), "\r");
            $buffer = substr($buffer, $nl + 1);
            if ($line === '') continue;
            $out .= $frame('log', $line);
            // Mirror failures/warnings from the scanner to the terminal too, so
            // per-URL problems are visible live, not only in the browser.
            if (preg_match('/✗|❌|error/iu', $line))      $term('error', $line);
            elseif (preg_match('/⚠|⏳/u', $line))          $term('warn', $line);
        }
        return $out;
    };

    try {
        // 1 — Collect URLs: either expand a sitemap or scan a single page
        if ($mode === 'url') {
            $urls       = [$sitemap];
            $urlToGroup = [$sitemap => 'Page'];
            $sse('log', "🔗 Single page mode — scanning $sitemap");
        } else {
            // echoes captured → streamed as log
            ob_start($handler, 1);
            [$urls, $urlToGroup] = collect_urls($sitemap, $maxUrls);
            ob_end_flush();   // flush captured collect_urls progress
            @flush();
        }

        if (!$urls) {
            $fail('No page URLs found. Verify the sitemap URL is accessible and returns XML.');
            return;
        }
        $sse('count', (string)count($urls));

        // 2 — Scan in parallel (echoes per-job progress lines, captured → log)
        ob_start($handler, 1);
        $resultsMap = scan_all($urls, $strategies, $apiKey ?: null, $workers);
        ob_end_flush();
        @flush();

        // Preserve sitemap order
        $results = [];
        foreach ($urls as $u) {
            if (isset($resultsMap[$u])) $results[] = $resultsMap[$u];
        }

        // 3 — Write report files
        if (!is_dir(REPORTS_DIR)) @mkdir(REPORTS_DIR, 0777, true);
        $stamp    = date('Ymd-His');
        $htmlName = "report-$stamp.html";
        $csvName  = "report-$stamp.csv";
        $genAt    = date('Y-m-d H:i');

        file_put_contents(REPORTS_DIR . "/$htmlName",
            build_html($results, $urlToGroup, $strategies, $sitemap, $genAt));
        file_put_contents(REPORTS_DIR . "/$csvName",
            build_csv($results, $urlToGroup, $strategies));

        $term('info', "scan complete — $sitemap (" . count($results) . " pages) → $htmlName");
        $sse('done', json_encode([
            'pages'  => count($results),
            'report' => '?report=' . rawurlencode($htmlName),
            'csv'    => '?report=' . rawurlencode($csvName),
        ]));
    } catch (\Throwable $e) {
        if (ob_get_level() > 0) ob_end_flush();
        // Full detail (with file:line) to the terminal; concise message to browser.
        $term('error', 'Scan failed: ' . $e->getMessage()
            . ' @ ' . $e->getFile() . ':' . $e->getLine());
        $sse('error', 'Scan failed: ' . $e->getMessage());
    }
}

// ─────────────────────────────────────────────────────────────────────────────
//  Route: the form (default)
// ─────────────────────────────────────────────────────────────────────────────
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>PageSpeed Bulk Scanner</title>
<style>
  :root {
    --bg: #0f1116; --panel: #181b23; --panel-2: #1f232d; --border: #2b303c;
    --text: #e6e8ee; --muted: #9aa3b2; --accent: #4f8cff; --accent-2: #6ee7b7;
    --danger: #ff6b6b; --radius: 12px;
  }
  * { box-sizing: border-box; }
  body {
    margin: 0; background: var(--bg); color: var(--text);
    font: 15px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
  }
  .wrap { max-width: 920px; margin: 0 auto; padding: 40px 20px 80px; }
  header h1 { font-size: 26px; margin: 0 0 4px; }
  header p { color: var(--muted); margin: 0 0 28px; }
  header .logo { font-size: 30px; margin-right: 8px; }
  .card {
    background: var(--panel); border: 1px solid var(--border);
    border-radius: var(--radius); padding: 24px;
  }
  .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }
  .field { display: flex; flex-direction: column; gap: 6px; }
  .field.full { grid-column: 1 / -1; }
  label { font-weight: 600; font-size: 13px; }
  label .hint { font-weight: 400; color: var(--muted); margin-left: 6px; }
  input, select {
    background: var(--panel-2); border: 1px solid var(--border); color: var(--text);
    border-radius: 8px; padding: 10px 12px; font-size: 14px; width: 100%;
  }
  input:focus, select:focus { outline: 2px solid var(--accent); border-color: transparent; }
  .seg { display: flex; gap: 0; border: 1px solid var(--border); border-radius: 8px; overflow: hidden; }
  .seg label {
    flex: 1; text-align: center; padding: 10px; cursor: pointer; font-weight: 600;
    background: var(--panel-2); transition: background .12s;
  }
  .seg input { display: none; }
  .seg input:checked + span { color: #fff; }
  .seg label:has(input:checked) { background: var(--accent); }
  .seg label:not(:last-child) { border-right: 1px solid var(--border); }
  .actions { margin-top: 22px; display: flex; gap: 12px; align-items: center; }
  button {
    background: var(--accent); color: #fff; border: 0; border-radius: 8px;
    padding: 12px 22px; font-size: 15px; font-weight: 600; cursor: pointer;
  }
  button:disabled { opacity: .5; cursor: not-allowed; }
  button.ghost { background: transparent; border: 1px solid var(--border); color: var(--text); }
  .hidden { display: none !important; }

  /* progress / results */
  #progress { margin-top: 24px; }
  .statusbar { display: flex; align-items: center; gap: 12px; margin-bottom: 14px; }
  .spinner {
    width: 18px; height: 18px; border: 3px solid var(--border);
    border-top-color: var(--accent); border-radius: 50%; animation: spin .8s linear infinite;
  }
  @keyframes spin { to { transform: rotate(360deg); } }
  .status-text { font-weight: 600; }
  .bar { height: 8px; background: var(--panel-2); border-radius: 99px; overflow: hidden; margin-bottom: 14px; }
  .bar > div { height: 100%; width: 0; background: linear-gradient(90deg, var(--accent), var(--accent-2)); transition: width .25s; }
  .log {
    background: #0a0c10; border: 1px solid var(--border); border-radius: 8px;
    font: 12.5px/1.55 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    padding: 14px; max-height: 320px; overflow: auto; white-space: pre-wrap; word-break: break-all;
    color: #c7cddb;
  }
  .log .ok { color: var(--accent-2); }
  .log .err { color: var(--danger); }
  .log .warn { color: #f2c14e; }
  .banner { padding: 12px 16px; border-radius: 8px; margin-bottom: 14px; font-weight: 600; }
  .banner.error { background: rgba(255,107,107,.12); border: 1px solid var(--danger); color: var(--danger); }
  .banner.success { background: rgba(110,231,183,.1); border: 1px solid var(--accent-2); color: var(--accent-2); }
  #resultActions { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 18px; }
  iframe { width: 100%; height: 70vh; border: 1px solid var(--border); border-radius: var(--radius); background: #fff; }
  a.btnlink { text-decoration: none; }
  .note { color: var(--muted); font-size: 13px; margin-top: 18px; }
  .note a { color: var(--accent); }
  @media (max-width: 640px) { .grid { grid-template-columns: 1fr; } }
</style>
</head>
<body>
<div class="wrap">
  <header>
    <h1><span class="logo">⚡</span>PageSpeed Bulk Scanner</h1>
    <p>Scan every page of a site through the Google PageSpeed Insights API and get a full dashboard.</p>
  </header>

  <form id="form" class="card">
    <div class="grid">
      <div class="field full">
        <label>Scan mode</label>
        <div class="seg" id="modeSeg">
          <label><input type="radio" name="mode" value="sitemap" checked><span>Whole site (sitemap)</span></label>
          <label><input type="radio" name="mode" value="url"><span>Single page</span></label>
        </div>
      </div>

      <div class="field full">
        <label for="sitemap" id="urlLabel">Sitemap URL <span class="hint" id="urlHint">required — sitemap_index.xml or any child sitemap</span></label>
        <input type="url" id="sitemap" name="sitemap" placeholder="https://example.com/sitemap_index.xml" required>
      </div>

      <div class="field full">
        <label for="api_key">Google API key <span class="hint">strongly recommended — anonymous quota is tiny</span></label>
        <input type="text" id="api_key" name="api_key" placeholder="AIza…" autocomplete="off" spellcheck="false">
      </div>

      <div class="field">
        <label>Strategy</label>
        <div class="seg">
          <label><input type="radio" name="strategy" value="mobile"><span>Mobile</span></label>
          <label><input type="radio" name="strategy" value="desktop"><span>Desktop</span></label>
          <label><input type="radio" name="strategy" value="both" checked><span>Both</span></label>
        </div>
      </div>

      <div class="field">
        <label for="workers">Parallel workers <span class="hint">1–25</span></label>
        <input type="number" id="workers" name="workers" value="5" min="1" max="25">
      </div>

      <div class="field full" id="maxUrlsField">
        <label for="max_urls">Max URLs <span class="hint">optional — leave blank to scan all; cap for a quick trial</span></label>
        <input type="number" id="max_urls" name="max_urls" placeholder="e.g. 20" min="1">
      </div>
    </div>

    <div class="actions">
      <button type="submit" id="runBtn">Run scan</button>
      <button type="button" id="cancelBtn" class="ghost hidden">Cancel</button>
    </div>
  </form>

  <section id="progress" class="card hidden">
    <div id="banner"></div>
    <div class="statusbar">
      <div class="spinner" id="spinner"></div>
      <span class="status-text" id="statusText">Starting…</span>
    </div>
    <div class="bar"><div id="barFill"></div></div>
    <div class="log" id="log"></div>

    <div id="results" class="hidden">
      <div id="resultActions"></div>
      <iframe id="reportFrame" title="PageSpeed report"></iframe>
    </div>
  </section>

  <p class="note">
    Need a key? Enable the
    <a href="https://console.cloud.google.com/apis/library/pagespeedonline.googleapis.com" target="_blank" rel="noopener">PageSpeed Insights API</a>
    in Google Cloud and create an API key. Your key is sent only to this local server.
  </p>
</div>

<script>
const form       = document.getElementById('form');
const runBtn     = document.getElementById('runBtn');
const cancelBtn  = document.getElementById('cancelBtn');
const progress   = document.getElementById('progress');
const banner     = document.getElementById('banner');
const spinner    = document.getElementById('spinner');
const statusText = document.getElementById('statusText');
const barFill    = document.getElementById('barFill');
const logEl      = document.getElementById('log');
const results    = document.getElementById('results');
const resultActions = document.getElementById('resultActions');
const reportFrame   = document.getElementById('reportFrame');

let es = null;
let totalJobs = 0, doneJobs = 0;

// Adapt the form to the selected scan mode (sitemap vs single page).
const sitemapInput = document.getElementById('sitemap');
const urlLabel     = document.getElementById('urlLabel');
const urlHint      = document.getElementById('urlHint');
const maxUrlsField = document.getElementById('maxUrlsField');

function applyMode() {
  const mode = form.mode.value;
  if (mode === 'url') {
    urlLabel.childNodes[0].nodeValue = 'Page URL ';
    urlHint.textContent = 'required — the single page to scan';
    sitemapInput.placeholder = 'https://example.com/pricing';
    maxUrlsField.classList.add('hidden');
  } else {
    urlLabel.childNodes[0].nodeValue = 'Sitemap URL ';
    urlHint.textContent = 'required — sitemap_index.xml or any child sitemap';
    sitemapInput.placeholder = 'https://example.com/sitemap_index.xml';
    maxUrlsField.classList.remove('hidden');
  }
}
form.querySelectorAll('input[name="mode"]').forEach(r => r.addEventListener('change', applyMode));
applyMode();

function addLog(line) {
  const div = document.createElement('div');
  if (/✓|✅/.test(line)) div.className = 'ok';
  else if (/✗|❌|error/i.test(line)) div.className = 'err';
  else if (/⚠|⏳/.test(line)) div.className = 'warn';
  div.textContent = line;
  logEl.appendChild(div);
  logEl.scrollTop = logEl.scrollHeight;
}

function resetView() {
  banner.className = ''; banner.textContent = '';
  logEl.innerHTML = ''; results.classList.add('hidden');
  resultActions.innerHTML = ''; reportFrame.src = 'about:blank';
  barFill.style.width = '0%'; spinner.classList.remove('hidden');
  statusText.textContent = 'Starting…';
  totalJobs = 0; doneJobs = 0;
}

function finish() {
  spinner.classList.add('hidden');
  runBtn.disabled = false;
  cancelBtn.classList.add('hidden');
  if (es) { es.close(); es = null; }
}

form.addEventListener('submit', (e) => {
  e.preventDefault();
  resetView();
  progress.classList.remove('hidden');
  runBtn.disabled = true;
  cancelBtn.classList.remove('hidden');

  const params = new URLSearchParams(new FormData(form));
  params.set('action', 'scan');
  es = new EventSource('?' + params.toString());

  es.addEventListener('count', (ev) => {
    totalJobs = parseInt(ev.data, 10);
    const strat = form.strategy.value;
    const mult = strat === 'both' ? 2 : 1;
    totalJobs *= mult;
    statusText.textContent = `Scanning ${ev.data} URLs…`;
  });

  es.addEventListener('log', (ev) => {
    addLog(ev.data);
    const m = ev.data.match(/\[(\d+)\/(\d+)\]/);
    if (m) {
      doneJobs = parseInt(m[1], 10);
      const total = parseInt(m[2], 10) || totalJobs;
      const pct = total ? Math.round(doneJobs / total * 100) : 0;
      barFill.style.width = pct + '%';
      statusText.textContent = `Scanning… ${doneJobs}/${total} requests (${pct}%)`;
    }
  });

  es.addEventListener('done', (ev) => {
    const data = JSON.parse(ev.data);
    barFill.style.width = '100%';
    statusText.textContent = `Done — ${data.pages} pages scanned`;
    banner.className = 'banner success';
    banner.textContent = `✅ Scan complete — ${data.pages} pages scanned.`;
    resultActions.innerHTML =
      `<a class="btnlink" href="${data.report}" target="_blank" rel="noopener"><button type="button">Open full report ↗</button></a>
       <a class="btnlink" href="${data.csv}"><button type="button" class="ghost">Download CSV</button></a>`;
    reportFrame.src = data.report;
    results.classList.remove('hidden');
    finish();
  });

  es.addEventListener('error', (ev) => {
    // ev.data is present for our explicit error events; absent for transport drops.
    if (ev.data) {
      banner.className = 'banner error';
      banner.textContent = '❌ ' + ev.data;
      statusText.textContent = 'Failed';
    } else if (es && es.readyState === EventSource.CLOSED) {
      if (!results.classList.contains('hidden')) return; // already finished cleanly
      banner.className = 'banner error';
      banner.textContent = '❌ Connection to the scanner was lost.';
      statusText.textContent = 'Disconnected';
    }
    finish();
  });
});

cancelBtn.addEventListener('click', () => {
  addLog('— cancelled by user —');
  statusText.textContent = 'Cancelled';
  banner.className = 'banner error';
  banner.textContent = 'Scan cancelled.';
  finish();
});
</script>
</body>
</html>
