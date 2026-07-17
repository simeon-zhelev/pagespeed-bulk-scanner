<?php /* PageSpeed Bulk Scanner — web frontend. Run with: php -S 127.0.0.1:8082 -t web */ ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>PageSpeed Bulk Scanner</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@500;600;700&family=Source+Sans+3:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
  :root {
    --ink:#0F1E33; --body:#33415C; --muted:#64748B; --soft:#94A3B8;
    --line:#E6EAF1; --line-strong:#C9D4E5; --panel:#ffffff; --bg:#EEF1F5;
    --accent:#0D8A7E; --accent-ink:#fff; --accent-hover:#0B7468;
    --accent-tint:#E6F4F2; --accent-line:#BFE3DE;
    --good:#1F9D5B; --warn:#E3A11F; --poor:#D64541; --none:#94A3B8;
    --radius:20px; --radius-sm:12px;
    --shadow:0 1px 2px rgba(15,30,51,.04), 0 12px 30px rgba(15,30,51,.05);
  }
  * { box-sizing:border-box; }
  body {
    margin:0; font-family:'Source Sans 3', system-ui, -apple-system, Helvetica, Arial, sans-serif;
    font-size:16px; line-height:1.55; color:var(--body); background:var(--bg); min-height:100vh;
    -webkit-font-smoothing:antialiased;
  }
  .wrap { max-width:820px; margin:0 auto; padding:56px 20px 80px; }

  header.hero { text-align:center; margin-bottom:32px; }
  header.hero::before {
    content:"✦"; display:flex; align-items:center; justify-content:center;
    width:64px; height:64px; margin:0 auto 22px; font-size:28px; color:var(--accent);
    background:linear-gradient(160deg,#EAF6F4,#D8EEEA); border-radius:20px;
    box-shadow:0 8px 20px rgba(13,138,126,.18);
  }
  header.hero h1 {
    font-family:'Poppins', sans-serif; font-weight:700; font-size:clamp(30px, 6.5vw, 44px);
    margin:0 0 14px; letter-spacing:-.02em; color:var(--ink); line-height:1.05;
  }
  header.hero p { margin:0 auto; max-width:560px; font-size:clamp(16px, 3.6vw, 18px); color:var(--muted); }
  header.hero .eyebrow {
    display:inline-block; font-family:'Poppins', sans-serif; font-size:12px; font-weight:600;
    letter-spacing:.14em; text-transform:uppercase; color:var(--accent);
    background:var(--accent-tint); border:1px solid var(--accent-line);
    padding:6px 14px; border-radius:999px; margin-bottom:18px;
  }

  .card { background:var(--panel); border:1px solid var(--line); border-radius:var(--radius); box-shadow:var(--shadow); padding:30px; }
  form .grid { display:grid; grid-template-columns:1fr 1fr; gap:20px 22px; }
  .field { display:flex; flex-direction:column; gap:7px; }
  .field.full { grid-column:1 / -1; }
  label { font-family:'Poppins', sans-serif; font-weight:600; font-size:14px; color:var(--ink); }
  label .hint { font-family:'Source Sans 3', sans-serif; font-weight:400; color:var(--muted); font-size:13px; }
  input[type=text], input[type=url], input[type=number], select {
    font:inherit; padding:13px 15px; border:1px solid var(--line-strong); border-radius:var(--radius-sm);
    background:#fff; color:var(--ink); transition:border-color .15s, box-shadow .15s;
  }
  input::placeholder { color:var(--soft); }
  #sitemap { padding:16px 18px; border-radius:14px; font-size:16px; }
  input:focus, select:focus { outline:none; border-color:var(--accent); box-shadow:0 0 0 3px rgba(13,138,126,.15); }

  /* segmented control */
  .seg { display:flex; border:1px solid var(--line-strong); border-radius:var(--radius-sm); overflow:hidden; background:#fff; }
  .seg label {
    flex:1; margin:0; text-align:center; padding:11px 8px; cursor:pointer;
    font-family:'Poppins', sans-serif; font-weight:600; font-size:14px;
    background:#fff; transition:background .12s, color .12s;
  }
  .seg label:not(:last-child) { border-right:1px solid var(--line-strong); }
  .seg input { display:none; }
  .seg label:has(input:checked) { background:var(--accent); color:#fff; }

  .check {
    flex-direction:row; align-items:center; gap:12px;
    border:1px solid var(--line); border-radius:var(--radius-sm); padding:14px 16px; background:#fff;
    transition:border-color .15s, background .15s;
  }
  .check:hover { border-color:var(--accent-line); background:var(--accent-tint); }
  .check input { width:18px; height:18px; accent-color:var(--accent); flex:none; }
  .check label { font-family:'Source Sans 3', sans-serif; font-weight:400; color:var(--body); cursor:pointer; }
  .check .hint { display:block; margin-top:2px; }

  .actions { margin-top:26px; display:flex; gap:14px; align-items:center; flex-wrap:wrap; }
  button.primary {
    font-family:'Poppins', sans-serif; font-weight:700; font-size:16px;
    background:var(--accent); color:var(--accent-ink);
    border:0; padding:15px 30px; border-radius:999px; cursor:pointer;
    transition:transform .05s, background .15s, box-shadow .15s;
    box-shadow:0 8px 18px rgba(13,138,126,.22);
  }
  button.primary:hover { background:var(--accent-hover); }
  button.primary:active { transform:translateY(1px); }
  button.primary:disabled { opacity:.55; cursor:not-allowed; box-shadow:none; }
  .note { color:var(--muted); font-size:13px; }
  .hidden { display:none !important; }

  /* collapsed form — shown while a scan runs and after it finishes */
  .formSummary { display:none; align-items:center; gap:12px; }
  form.minimized .grid, form.minimized .actions { display:none; }
  form.minimized .formSummary { display:flex; }
  .formSummary .label { flex:none; font-family:'Poppins', sans-serif; font-weight:600; font-size:14px; color:var(--ink); }
  .formSummary .target {
    flex:1; min-width:0; color:var(--body);
    font-family:'IBM Plex Mono', ui-monospace, monospace; font-size:13px;
    overflow:hidden; text-overflow:ellipsis; white-space:nowrap;
  }
  button.editBtn {
    margin-left:auto; flex:none; cursor:pointer;
    font-family:'Poppins', sans-serif; font-weight:600; font-size:13px;
    background:#fff; color:var(--accent); border:1px solid var(--accent-line);
    padding:9px 18px; border-radius:999px; transition:background .15s, border-color .15s;
  }
  button.editBtn:hover { background:var(--accent-tint); border-color:var(--accent); }

  /* progress + results */
  #run { display:none; margin-top:26px; }
  #run.active { display:block; }
  .statusbar { display:flex; align-items:center; gap:12px; margin-bottom:14px; }
  .spinner {
    width:18px; height:18px; border:2px solid var(--line); border-top-color:var(--accent);
    border-radius:50%; animation:spin .8s linear infinite; flex:none;
  }
  .spinner.hidden { display:none; }
  @keyframes spin { to { transform:rotate(360deg); } }
  #statusMsg { font-family:'Poppins', sans-serif; font-weight:600; color:var(--ink); }
  .bar { height:10px; background:var(--line); border-radius:999px; overflow:hidden; }
  .bar > i { display:block; height:100%; width:0; background:linear-gradient(90deg,#22B3A2,var(--accent)); transition:width .25s; }
  .counter { font-size:13px; color:var(--muted); margin-top:8px; }
  .log {
    margin-top:16px; max-height:230px; overflow:auto; border:1px solid var(--line); border-radius:var(--radius-sm);
    font:13px/1.5 'IBM Plex Mono', ui-monospace,SFMono-Regular,Menlo,Consolas,monospace; background:var(--bg);
  }
  .log .row { display:flex; gap:10px; padding:7px 14px; border-bottom:1px solid var(--line); align-items:center; }
  .log .row:last-child { border-bottom:0; }
  .log .badge { flex:none; width:9px; height:9px; border-radius:50%; }
  .log .strat { flex:none; font-size:11px; color:var(--muted); width:54px; }
  .log .url { color:var(--body); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; flex:1; }
  .log .num { flex:none; color:var(--soft); }

  .summary { display:grid; grid-template-columns:repeat(auto-fit,minmax(120px,1fr)); gap:12px; margin:6px 0 18px; }
  .stat { background:var(--bg); border:1px solid var(--line); border-radius:var(--radius-sm); padding:16px; text-align:center; }
  .stat .n { font-family:'Poppins', sans-serif; font-size:28px; font-weight:700; line-height:1.1; color:var(--ink); }
  .stat .l { font-size:12px; color:var(--muted); margin-top:4px; }
  .stat.good .n { color:var(--good); } .stat.warn .n { color:var(--warn); }
  .stat.poor .n { color:var(--poor); } .stat.none .n { color:var(--none); }
  .resultActions { display:flex; gap:12px; flex-wrap:wrap; margin-bottom:18px; }
  a.btn {
    text-decoration:none; font-family:'Poppins', sans-serif; font-weight:600; font-size:14px;
    padding:11px 20px; border-radius:999px; border:1px solid var(--line-strong); color:var(--ink); background:#fff;
    transition:border-color .15s, background .15s;
  }
  a.btn:hover { border-color:var(--accent-line); background:var(--accent-tint); }
  a.btn.solid { background:var(--accent); color:#fff; border-color:var(--accent); box-shadow:0 8px 18px rgba(13,138,126,.22); }
  a.btn.solid:hover { background:var(--accent-hover); }
  iframe.report { width:100%; height:680px; border:1px solid var(--line); border-radius:var(--radius-sm); background:#fff; }
  .errbox { background:#FBEEEB; border:1px solid #E7C3BC; color:#8A2E20; padding:14px 16px; border-radius:var(--radius-sm); white-space:pre-wrap; }
  footer { text-align:center; color:var(--muted); font-size:13px; margin-top:34px; }
  footer a { color:var(--accent); }

  /* ── Mobile ──────────────────────────────────────────────────────────────── */
  @media (max-width:640px) {
    .wrap { padding:36px 14px 56px; }
    header.hero { margin-bottom:24px; }
    header.hero::before { width:56px; height:56px; margin-bottom:18px; font-size:26px; }
    header.hero .eyebrow { margin-bottom:14px; }

    .card { padding:20px; border-radius:16px; }
    form .grid { grid-template-columns:1fr; gap:16px; }
    #sitemap { padding:14px 15px; }
    .seg label { padding:10px 5px; font-size:13px; }

    .actions { margin-top:20px; flex-direction:column; align-items:stretch; gap:12px; }
    .actions .note { text-align:center; }
    button.primary { width:100%; padding:15px 20px; }

    .formSummary { flex-wrap:wrap; }
    .formSummary .target { flex-basis:100%; order:3; }

    .statusbar { flex-wrap:wrap; }
    .summary { grid-template-columns:1fr 1fr; }
    .resultActions { flex-direction:column; }
    .resultActions a.btn { text-align:center; }
    iframe.report { height:70vh; min-height:420px; }
  }

  @media (max-width:340px) {
    .seg { flex-direction:column; }
    .seg label:not(:last-child) { border-right:0; border-bottom:1px solid var(--line-strong); }
    .summary { grid-template-columns:1fr; }
  }
</style>
</head>
<body>
<div class="wrap">
  <header class="hero">
    <span class="eyebrow">Google PageSpeed Insights · Lighthouse</span>
    <h1>PageSpeed Bulk Scanner</h1>
    <p>Audit every page of a website through the PageSpeed Insights API. Enter a website address — its sitemap is found automatically, or the scanner follows same-site links when there is no sitemap — then watch the scores appear live.</p>
  </header>

  <div class="card">
    <form id="form">
      <div class="formSummary" id="formSummary">
        <span class="label" id="scanLabel">Scanning</span>
        <span class="target" id="scanTarget"></span>
        <button type="button" class="editBtn" id="editBtn">Edit &amp; rescan</button>
      </div>

      <div class="grid">
        <div class="field full">
          <label>Scan mode</label>
          <div class="seg" id="modeSeg">
            <label><input type="radio" name="mode" value="sitemap" checked>Whole site (sitemap)</label>
            <label><input type="radio" name="mode" value="url">Single page</label>
          </div>
        </div>

        <div class="field full">
          <label for="sitemap" id="targetLabel">Website or sitemap URL <span class="hint" id="targetHint">— the sitemap is auto-discovered, with a site crawl as fallback</span></label>
          <input type="text" id="sitemap" name="sitemap" required
                 placeholder="example.com  —  or  https://example.com/sitemap_index.xml" autocomplete="off" spellcheck="false">
        </div>

        <div class="field full">
          <label for="api_key">Google API key <span class="hint">— strongly recommended; anonymous quota is tiny</span></label>
          <input type="text" id="api_key" name="api_key" placeholder="AIza…" autocomplete="off" spellcheck="false">
        </div>

        <div class="field check full">
          <input type="checkbox" id="remember_key" checked>
          <label for="remember_key">Remember the API key on this browser
            <span class="hint">Stored only in this browser's local storage</span>
          </label>
        </div>

        <div class="field">
          <label>Strategy</label>
          <div class="seg">
            <label><input type="radio" name="strategy" value="mobile">Mobile</label>
            <label><input type="radio" name="strategy" value="desktop">Desktop</label>
            <label><input type="radio" name="strategy" value="both" checked>Both</label>
          </div>
        </div>

        <div class="field">
          <label for="workers">Parallel workers <span class="hint">— 1–25</span></label>
          <input type="number" id="workers" name="workers" min="1" max="25" value="15">
        </div>

        <div class="field full" id="maxUrlsField">
          <label for="max_urls">Max pages <span class="hint">— blank = all</span></label>
          <input type="number" id="max_urls" name="max_urls" min="1" placeholder="all (e.g. 20 for a trial)">
        </div>

        <div class="field check full" id="crawlField">
          <input type="checkbox" id="crawl" name="crawl">
          <label for="crawl">Crawl the site directly
            <span class="hint">Follow same-site links instead of the sitemap; used automatically when no sitemap is found</span>
          </label>
        </div>
      </div>

      <div class="actions">
        <button type="submit" class="primary" id="submitBtn">Scan website</button>
        <span class="note">Each page runs a full Lighthouse audit — budget several seconds per page.</span>
      </div>
    </form>

    <section id="run">
      <div class="statusbar">
        <div class="spinner" id="spinner"></div>
        <span id="statusMsg">Starting…</span>
      </div>
      <div class="bar"><i id="barFill"></i></div>
      <div class="counter" id="counter"></div>
      <div class="log" id="log" hidden></div>

      <div id="result" hidden>
        <div class="summary" id="summary"></div>
        <div class="resultActions" id="resultActions"></div>
        <iframe class="report" id="reportFrame" title="PageSpeed report"></iframe>
      </div>

      <div class="errbox" id="error" hidden></div>
    </section>
  </div>

  <footer>
    Powered by the <a href="https://developers.google.com/speed/docs/insights/v5/get-started" target="_blank" rel="noopener">PageSpeed Insights API</a>.
    Your key is sent only to this local server.
  </footer>
</div>

<script>
const form = document.getElementById('form');
const runEl = document.getElementById('run');
const submitBtn = document.getElementById('submitBtn');
const editBtn = document.getElementById('editBtn');
const scanLabel = document.getElementById('scanLabel');
const scanTarget = document.getElementById('scanTarget');
const spinner = document.getElementById('spinner');
const statusMsg = document.getElementById('statusMsg');
const barFill = document.getElementById('barFill');
const counter = document.getElementById('counter');
const logEl = document.getElementById('log');
const resultEl = document.getElementById('result');
const summaryEl = document.getElementById('summary');
const resultActions = document.getElementById('resultActions');
const reportFrame = document.getElementById('reportFrame');
const errorEl = document.getElementById('error');

// Adapt the form to the selected scan mode (sitemap vs single page).
const targetInput = document.getElementById('sitemap');
const targetLabel = document.getElementById('targetLabel');
const targetHint  = document.getElementById('targetHint');
const maxUrlsField = document.getElementById('maxUrlsField');
const crawlField = document.getElementById('crawlField');

function applyMode() {
  const mode = form.mode.value;
  if (mode === 'url') {
    targetLabel.childNodes[0].nodeValue = 'Page URL ';
    targetHint.textContent = '— the single page to scan';
    targetInput.placeholder = 'https://example.com/pricing';
    maxUrlsField.classList.add('hidden');
    crawlField.classList.add('hidden');
    submitBtn.textContent = 'Scan page';
  } else {
    targetLabel.childNodes[0].nodeValue = 'Website or sitemap URL ';
    targetHint.textContent = '— the sitemap is auto-discovered, with a site crawl as fallback';
    targetInput.placeholder = 'example.com  —  or  https://example.com/sitemap_index.xml';
    maxUrlsField.classList.remove('hidden');
    crawlField.classList.remove('hidden');
    submitBtn.textContent = 'Scan website';
  }
}
form.querySelectorAll('input[name="mode"]').forEach(r => r.addEventListener('change', applyMode));
applyMode();

// Persist the Google API key in this browser's local storage so it survives
// reloads. Guarded for private-mode/disabled storage.
const API_KEY_STORE = 'psbs.apiKey';
const apiKeyInput = document.getElementById('api_key');
const rememberKey = document.getElementById('remember_key');

function storeApiKey() {
  try {
    if (rememberKey.checked && apiKeyInput.value.trim() !== '') {
      localStorage.setItem(API_KEY_STORE, apiKeyInput.value.trim());
    } else {
      localStorage.removeItem(API_KEY_STORE);
    }
  } catch (_) { /* storage unavailable — ignore */ }
}

(function loadApiKey() {
  try {
    const saved = localStorage.getItem(API_KEY_STORE);
    if (saved) { apiKeyInput.value = saved; rememberKey.checked = true; }
  } catch (_) { /* storage unavailable — ignore */ }
})();

apiKeyInput.addEventListener('input', storeApiKey);
rememberKey.addEventListener('change', storeApiKey);

function scoreColor(v) {
  if (v === null || v === undefined) return '#94A3B8';
  if (v >= 90) return '#1F9D5B';
  if (v >= 50) return '#E3A11F';
  return '#D64541';
}
function scoreClass(v) {
  if (v === null || v === undefined) return 'none';
  if (v >= 90) return 'good';
  if (v >= 50) return 'warn';
  return 'poor';
}

let es = null;
let total = 0;
let lastDone = 0;

form.addEventListener('submit', async (e) => {
  e.preventDefault();
  if (es) es.close();

  // Collapse the settings so live progress becomes the focus of the card.
  scanLabel.textContent = 'Scanning';
  scanTarget.textContent = targetInput.value.trim();
  form.classList.add('minimized');

  // reset UI
  runEl.classList.add('active');
  spinner.classList.remove('hidden');
  statusMsg.textContent = 'Starting…';
  barFill.style.width = '0';
  counter.textContent = '';
  logEl.innerHTML = ''; logEl.hidden = true;
  resultEl.hidden = true;
  errorEl.hidden = true;
  submitBtn.disabled = true;
  reportFrame.removeAttribute('src');
  total = 0;
  lastDone = 0;

  // 1 — start the scan as a background job; the scan itself is detached from
  //     this page, so a reload or dropped connection won't kill it.
  let job;
  try {
    const resp = await fetch('scan.php?action=start', { method: 'POST', body: new FormData(form) });
    const j = await resp.json();
    if (!resp.ok || j.error) throw new Error(j.error || ('HTTP ' + resp.status));
    job = j.job;
  } catch (err) {
    showError('Could not start the scan: ' + err.message);
    return;
  }

  // 2 — follow its live progress (SSE resumes automatically on reconnect)
  es = new EventSource('scan.php?action=stream&job=' + encodeURIComponent(job));

  es.addEventListener('status', (ev) => {
    statusMsg.textContent = JSON.parse(ev.data).message;
  });

  es.addEventListener('meta', (ev) => {
    const d = JSON.parse(ev.data);
    total = d.total;
    statusMsg.textContent = `Scanning ${d.urls} page${d.urls === 1 ? '' : 's'}`
      + ` × ${d.strategies.length} (${d.workers} workers)…`;
    counter.textContent = `0 / ${d.total} requests`;
    logEl.hidden = false;
  });

  es.addEventListener('page', (ev) => {
    const d = JSON.parse(ev.data);
    if (d.done <= lastDone) return;   // duplicate after a stream replay
    lastDone = d.done;
    total = d.total || total;
    barFill.style.width = (100 * d.done / d.total).toFixed(1) + '%';
    counter.textContent = `${d.done} / ${d.total} requests`;
    addLogRow(d);
  });

  es.addEventListener('done', (ev) => {
    const d = JSON.parse(ev.data);
    finish();
    scanLabel.textContent = 'Scanned';
    barFill.style.width = '100%';
    statusMsg.textContent = 'Scan complete.';
    renderSummary(d.summary);
    renderActions(d);
    reportFrame.src = d.reportUrl;
    resultEl.hidden = false;
  });

  es.addEventListener('error', (ev) => {
    // SSE network errors have no data; our server-sent errors do.
    if (ev.data) {
      try { showError(JSON.parse(ev.data).message); return; } catch (_) {}
    }
    if (es.readyState === EventSource.CLOSED) {
      if (!resultEl.hidden) return; // finished cleanly
      showError('The connection to the scanner was lost. Check the server console for details.');
    }
  });
});

// Reveal the preserved settings for another run without clearing the result.
editBtn.addEventListener('click', () => {
  form.classList.remove('minimized');
  targetInput.focus();
});

function addLogRow(d) {
  const row = document.createElement('div');
  row.className = 'row';
  const badge = document.createElement('span');
  badge.className = 'badge';
  let label;
  if (!d.ok) {
    badge.style.background = '#94a3b8';
    label = '✗ ' + (d.error || 'error');
  } else {
    badge.style.background = scoreColor(d.perf);
    label = d.perf === null ? '—' : ('perf ' + d.perf);
  }
  row.innerHTML = `<span class="num">[${d.done}]</span>`;
  row.appendChild(badge);
  const strat = document.createElement('span');
  strat.className = 'strat'; strat.textContent = d.strategy || '';
  row.appendChild(strat);
  const url = document.createElement('span');
  url.className = 'url'; url.textContent = d.url; url.title = d.url;
  row.appendChild(url);
  const lab = document.createElement('span');
  lab.className = 'num'; lab.textContent = label;
  row.appendChild(lab);
  logEl.appendChild(row);
  logEl.scrollTop = logEl.scrollHeight;
}

function renderSummary(s) {
  const cards = [
    { n:s.pages, l:'Pages scanned' },
  ];
  if (s.mobilePerf !== null && s.mobilePerf !== undefined)
    cards.push({ n:s.mobilePerf, l:'Mobile perf', cls:scoreClass(s.mobilePerf) });
  if (s.deskPerf !== null && s.deskPerf !== undefined)
    cards.push({ n:s.deskPerf, l:'Desktop perf', cls:scoreClass(s.deskPerf) });
  cards.push({ n:fmt(s.a11y), l:'Accessibility', cls:scoreClass(s.a11y) });
  cards.push({ n:fmt(s.bp),   l:'Best practices', cls:scoreClass(s.bp) });
  cards.push({ n:fmt(s.seo),  l:'SEO', cls:scoreClass(s.seo) });
  cards.push({ n:s.poorPages, l:'Poor pages (<50)', cls:s.poorPages ? 'poor' : 'good' });
  if (s.errorPages) cards.push({ n:s.errorPages, l:'Pages errored', cls:'none' });
  summaryEl.innerHTML = cards.map(c =>
    `<div class="stat ${c.cls || ''}"><div class="n">${c.n}</div><div class="l">${c.l}</div></div>`
  ).join('');
}
function fmt(v) { return (v === null || v === undefined) ? '—' : v; }

function renderActions(d) {
  let html =
    `<a class="btn solid" href="${d.reportUrl}" target="_blank" rel="noopener">Open full report ↗</a>`;
  if (d.pdfUrl) html += `<a class="btn" href="${d.pdfUrl}" download>Download PDF</a>`;
  resultActions.innerHTML = html;
}

function showError(msg) {
  finish();
  scanLabel.textContent = 'Scan failed';
  statusMsg.textContent = 'Scan failed.';
  errorEl.textContent = msg;
  errorEl.hidden = false;
}

function finish() {
  spinner.classList.add('hidden');
  submitBtn.disabled = false;
  if (es) es.close();
}
</script>
</body>
</html>
