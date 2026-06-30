<?php /* PageSpeed Bulk Scanner — web frontend. Run with: php -S 127.0.0.1:8001 -t web */ ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>PageSpeed Bulk Scanner</title>
<style>
  :root {
    --bg:#f8fafc; --panel:#ffffff; --ink:#1e293b; --muted:#64748b;
    --line:#e2e8f0; --accent:#2563eb; --accent-ink:#fff;
    --good:#10b981; --warn:#f59e0b; --poor:#ef4444; --none:#94a3b8;
    --radius:12px; --shadow:none;
  }
  * { box-sizing:border-box; }
  body {
    margin:0; font:16px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
    color:var(--ink);
    background:var(--bg);
    min-height:100vh;
  }
  .wrap { max-width:980px; margin:0 auto; padding:48px 20px 80px; }
  header.hero { text-align:center; color:var(--ink); margin-bottom:32px; }
  header.hero h1 { font-size:34px; margin:0 0 10px; letter-spacing:-.02em; color:#0f172a; }
  header.hero p { margin:0 auto; max-width:620px; color:#475569; }
  header.hero .eyebrow {
    display:inline-block; font-size:12px; letter-spacing:.14em; text-transform:uppercase;
    color:#2563eb; background:#eff6ff; border:1px solid #dbeafe; padding:5px 12px; border-radius:999px; margin-bottom:16px;
  }
  .card { background:var(--panel); border:1px solid var(--line); border-radius:var(--radius); box-shadow:var(--shadow); padding:28px; }
  form .grid { display:grid; grid-template-columns:1fr 1fr; gap:18px 20px; }
  .field { display:flex; flex-direction:column; gap:6px; }
  .field.full { grid-column:1 / -1; }
  label { font-weight:600; font-size:14px; }
  label .hint { font-weight:400; color:var(--muted); font-size:13px; }
  input[type=text], input[type=url], input[type=number], select {
    font:inherit; padding:11px 13px; border:1px solid #cbd5e1; border-radius:10px; background:#fff; color:var(--ink);
    transition:border-color .15s, box-shadow .15s;
  }
  input:focus, select:focus { outline:none; border-color:var(--accent); box-shadow:0 0 0 3px rgba(37,99,235,.15); }
  /* segmented control */
  .seg { display:flex; border:1px solid #cbd5e1; border-radius:10px; overflow:hidden; }
  .seg label { flex:1; margin:0; text-align:center; padding:10px; cursor:pointer; font-weight:600; font-size:14px; background:#fff; transition:background .12s, color .12s; }
  .seg label:not(:last-child) { border-right:1px solid #cbd5e1; }
  .seg input { display:none; }
  .seg label:has(input:checked) { background:var(--accent); color:#fff; }
  .actions { margin-top:24px; display:flex; gap:12px; align-items:center; flex-wrap:wrap; }
  button.primary {
    font:inherit; font-weight:600; background:var(--accent); color:var(--accent-ink);
    border:0; padding:13px 26px; border-radius:10px; cursor:pointer; transition:transform .05s, background .15s;
  }
  button.primary:hover { background:#1d4ed8; }
  button.primary:active { transform:translateY(1px); }
  button.primary:disabled { opacity:.55; cursor:not-allowed; }
  .note { color:var(--muted); font-size:13px; }
  .hidden { display:none !important; }

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
  #statusMsg { font-weight:600; }
  .bar { height:10px; background:var(--line); border-radius:999px; overflow:hidden; }
  .bar > i { display:block; height:100%; width:0; background:linear-gradient(90deg,#3b82f6,#2563eb); transition:width .25s; }
  .counter { font-size:13px; color:var(--muted); margin-top:8px; }
  .log {
    margin-top:16px; max-height:260px; overflow:auto; border:1px solid var(--line); border-radius:10px;
    font:13px/1.5 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace; background:#f8fafc;
  }
  .log .row { display:flex; gap:10px; padding:6px 12px; border-bottom:1px solid #eef2f7; align-items:center; }
  .log .row:last-child { border-bottom:0; }
  .log .badge { flex:none; width:9px; height:9px; border-radius:50%; }
  .log .strat { flex:none; font-size:11px; color:var(--muted); width:54px; }
  .log .url { color:#334155; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; flex:1; }
  .log .num { flex:none; color:var(--muted); }

  .summary { display:grid; grid-template-columns:repeat(auto-fit,minmax(120px,1fr)); gap:12px; margin:6px 0 18px; }
  .stat { background:#f8fafc; border:1px solid var(--line); border-radius:12px; padding:14px; text-align:center; }
  .stat .n { font-size:26px; font-weight:700; line-height:1.1; }
  .stat .l { font-size:12px; color:var(--muted); margin-top:4px; }
  .stat.good .n { color:var(--good); } .stat.warn .n { color:var(--warn); }
  .stat.poor .n { color:var(--poor); } .stat.none .n { color:var(--none); }
  .resultActions { display:flex; gap:12px; flex-wrap:wrap; margin-bottom:18px; }
  a.btn {
    text-decoration:none; font-weight:600; font-size:14px; padding:10px 18px; border-radius:10px;
    border:1px solid var(--line); color:var(--ink); background:#fff;
  }
  a.btn.solid { background:var(--accent); color:#fff; border-color:var(--accent); }
  iframe.report { width:100%; height:680px; border:1px solid var(--line); border-radius:12px; background:#fff; }
  .errbox { background:#fef2f2; border:1px solid #fecaca; color:#991b1b; padding:14px 16px; border-radius:10px; white-space:pre-wrap; }
  footer { text-align:center; color:#64748b; font-size:13px; margin-top:34px; }
  footer a { color:#2563eb; }
  @media (max-width:640px) { form .grid { grid-template-columns:1fr; } }
</style>
</head>
<body>
<div class="wrap">
  <header class="hero">
    <span class="eyebrow">Google PageSpeed Insights · Lighthouse</span>
    <h1>PageSpeed Bulk Scanner</h1>
    <p>Audit every page of a website through the PageSpeed Insights API, driven by its XML sitemap. Enter a website address — the sitemap is found automatically — or paste a sitemap URL directly, then watch the scores appear live.</p>
  </header>

  <div class="card">
    <form id="form">
      <div class="grid">
        <div class="field full">
          <label>Scan mode</label>
          <div class="seg" id="modeSeg">
            <label><input type="radio" name="mode" value="sitemap" checked>Whole site (sitemap)</label>
            <label><input type="radio" name="mode" value="url">Single page</label>
          </div>
        </div>

        <div class="field full">
          <label for="sitemap" id="targetLabel">Website or sitemap URL <span class="hint" id="targetHint">— enter a site address to auto-find its sitemap, or paste a sitemap URL</span></label>
          <input type="text" id="sitemap" name="sitemap" required
                 placeholder="example.com  —  or  https://example.com/sitemap_index.xml" autocomplete="off" spellcheck="false">
        </div>

        <div class="field full">
          <label for="api_key">Google API key <span class="hint">— strongly recommended; anonymous quota is tiny</span></label>
          <input type="text" id="api_key" name="api_key" placeholder="AIza…" autocomplete="off" spellcheck="false">
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
          <input type="number" id="workers" name="workers" min="1" max="25" value="5">
        </div>

        <div class="field full" id="maxUrlsField">
          <label for="max_urls">Max pages <span class="hint">— blank = all</span></label>
          <input type="number" id="max_urls" name="max_urls" min="1" placeholder="all (e.g. 20 for a trial)">
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

function applyMode() {
  const mode = form.mode.value;
  if (mode === 'url') {
    targetLabel.childNodes[0].nodeValue = 'Page URL ';
    targetHint.textContent = '— the single page to scan';
    targetInput.placeholder = 'https://example.com/pricing';
    maxUrlsField.classList.add('hidden');
    submitBtn.textContent = 'Scan page';
  } else {
    targetLabel.childNodes[0].nodeValue = 'Website or sitemap URL ';
    targetHint.textContent = '— enter a site address to auto-find its sitemap, or paste a sitemap URL';
    targetInput.placeholder = 'example.com  —  or  https://example.com/sitemap_index.xml';
    maxUrlsField.classList.remove('hidden');
    submitBtn.textContent = 'Scan website';
  }
}
form.querySelectorAll('input[name="mode"]').forEach(r => r.addEventListener('change', applyMode));
applyMode();

function scoreColor(v) {
  if (v === null || v === undefined) return '#94a3b8';
  if (v >= 90) return '#10b981';
  if (v >= 50) return '#f59e0b';
  return '#ef4444';
}
function scoreClass(v) {
  if (v === null || v === undefined) return 'none';
  if (v >= 90) return 'good';
  if (v >= 50) return 'warn';
  return 'poor';
}

let es = null;
let total = 0;

form.addEventListener('submit', (e) => {
  e.preventDefault();
  if (es) es.close();

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

  // build query string
  const data = new FormData(form);
  const params = new URLSearchParams();
  for (const [k, v] of data.entries()) {
    if (v !== '') params.set(k, v);
  }

  es = new EventSource('scan.php?' + params.toString());

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
    total = d.total || total;
    barFill.style.width = (100 * d.done / d.total).toFixed(1) + '%';
    counter.textContent = `${d.done} / ${d.total} requests`;
    addLogRow(d);
  });

  es.addEventListener('done', (ev) => {
    const d = JSON.parse(ev.data);
    finish();
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
  html += `<a class="btn" href="${d.csvUrl}" download>Download CSV</a>`;
  resultActions.innerHTML = html;
}

function showError(msg) {
  finish();
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
