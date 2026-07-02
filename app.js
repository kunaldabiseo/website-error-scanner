/* SiteScope front-end. Talks to scan.php over SSE, renders dashboard. */
(function () {
  'use strict';

  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
  const SEV_ORDER = { Critical: 0, High: 1, Medium: 2, Low: 3 };
  const SEV_COLOR = { Critical: '#ff5d6c', High: '#ff9f43', Medium: '#ffd23f', Low: '#54c7ec' };

  let state = { data: null, filtered: [], source: null, charts: {} };

  // ---- Elements ----
  const form = $('#scanForm'), urlInput = $('#urlInput'), scanBtn = $('#scanBtn');
  const welcome = $('#welcome'), progress = $('#progress'), results = $('#results');

  // ---- Navigation ----
  $$('.nav-item').forEach(item => item.addEventListener('click', () => {
    $$('.nav-item').forEach(n => n.classList.remove('active'));
    item.classList.add('active');
    const view = item.dataset.view;
    $$('.view').forEach(v => v.classList.remove('active'));
    $('#view-' + view).classList.add('active');
    if (view === 'catalog') renderCatalog();
  }));

  // ---- Options toggle ----
  $('#optBtn').addEventListener('click', () => $('#optionsPanel').classList.toggle('hidden'));
  $$('.sample').forEach(b => b.addEventListener('click', () => { urlInput.value = b.dataset.url; form.requestSubmit(); }));

  // ---- Submit ----
  form.addEventListener('submit', e => { e.preventDefault(); startScan(); });
  $('#cancelBtn').addEventListener('click', stopScan);

  function opts() {
    const cats = $$('.cat:checked').map(c => c.value).join(',');
    return {
      max_pages: $('#maxPages').value || 40,
      timeout: $('#timeout').value || 20,
      cats
    };
  }

  function startScan() {
    let url = urlInput.value.trim();
    if (!url) return;
    stopScan();
    welcome.classList.add('hidden');
    results.classList.add('hidden');
    progress.classList.remove('hidden');
    scanBtn.disabled = true;
    $('#crawlLog').innerHTML = '';
    $('#progressTitle').textContent = 'Scanning ' + url + ' …';
    setBar(4);
    $('#pgPages').textContent = '0 pages';
    $('#pgQueued').textContent = '0 queued';
    $('#pgFindings').textContent = '0 issues';

    const o = opts();
    const qs = new URLSearchParams({ url, stream: '1', max_pages: o.max_pages, timeout: o.timeout, cats: o.cats });
    const maxPages = parseInt(o.max_pages, 10) || 40;
    let liveFindings = 0;

    const es = new EventSource('scan.php?' + qs.toString());
    state.source = es;

    es.addEventListener('start', () => setBar(6));
    es.addEventListener('site', e => {
      const d = JSON.parse(e.data); liveFindings += d.findings || 0;
      $('#pgFindings').textContent = liveFindings + ' issues';
      logLine('SITE', 'ok', 'Site-wide checks (robots, sitemap, SSL, redirects)');
    });
    es.addEventListener('page', e => {
      const d = JSON.parse(e.data);
      liveFindings += d.findings || 0;
      const pct = Math.min(92, 8 + (d.crawled / maxPages) * 80);
      setBar(pct);
      $('#pgPages').textContent = d.crawled + ' pages';
      $('#pgQueued').textContent = d.queued + ' queued';
      $('#pgFindings').textContent = liveFindings + ' issues';
      const cls = d.status >= 500 || d.status === 0 ? 'bad' : (d.status >= 400 ? 'bad' : (d.status >= 300 ? 'warn' : 'ok'));
      logLine(d.status || 'ERR', cls, d.url + '  ·  ' + (d.ttfb || 0) + 'ms');
    });
    es.addEventListener('linkcheck_start', e => {
      const d = JSON.parse(e.data);
      logLine('LINK', 'warn', 'Checking ' + d.links + ' unique links for breakage…');
      setBar(94);
    });
    es.addEventListener('linkcheck_done', e => {
      const d = JSON.parse(e.data);
      logLine('LINK', d.broken ? 'bad' : 'ok', d.checked + ' links checked, ' + d.broken + ' broken');
    });
    es.addEventListener('complete', () => setBar(98));
    es.addEventListener('result', e => {
      const data = JSON.parse(e.data);
      setBar(100);
      state.data = data;
      setTimeout(() => renderAll(data), 350);
    });
    es.addEventListener('error', e => {
      if (e.data) { try { const d = JSON.parse(e.data); toast(d.message || 'Scan error'); } catch (_) {} }
    });
    es.addEventListener('end', () => stopScan());
    es.onerror = () => {
      // EventSource fires onerror when the stream closes; only treat as failure if we have no data.
      if (!state.data) { /* keep waiting a beat */ }
      stopScan();
    };
  }

  function stopScan() {
    if (state.source) { state.source.close(); state.source = null; }
    scanBtn.disabled = false;
  }

  function setBar(pct) { $('#barFill').style.width = pct + '%'; }

  function logLine(status, cls, text) {
    const li = document.createElement('li');
    li.innerHTML = '<span class="st ' + cls + '">' + status + '</span><span class="u">' + escapeHtml(text) + '</span>';
    const log = $('#crawlLog');
    log.prepend(li);
    while (log.children.length > 200) log.removeChild(log.lastChild);
  }

  // ================= RENDER =================
  function renderAll(data) {
    progress.classList.add('hidden');
    results.classList.remove('hidden');
    const s = data.summary;

    // Score gauge
    const arc = $('#gaugeArc'), circ = 327;
    arc.style.strokeDashoffset = circ - (circ * s.score / 100);
    arc.style.stroke = s.score >= 80 ? '#31d0aa' : (s.score >= 55 ? '#ffd23f' : '#ff5d6c');
    animateNum($('#scoreNum'), s.score);
    $('#gradeBadge').textContent = s.grade;
    $('#gradeBadge').style.color = s.score >= 80 ? '#31d0aa' : (s.score >= 55 ? '#ffd23f' : '#ff5d6c');
    $('#scannedUrl').textContent = data.start_url;
    $('#scannedMeta').textContent = data.pages_crawled + ' pages crawled · ' + s.total +
      ' issues · ' + new Date(data.scanned_at).toLocaleString();

    // Severity stat cards
    const sev = s.by_severity;
    $('#sevCards').innerHTML =
      statCard('total', s.total, 'Total issues') +
      statCard('critical', sev.Critical || 0, 'Critical') +
      statCard('high', sev.High || 0, 'High') +
      statCard('medium', (sev.Medium || 0) + (sev.Low || 0), 'Medium + Low');

    drawGroupChart(s.by_group);
    drawSevChart(sev);

    // Top priorities
    const top = [...data.findings].sort(sortBySeverity).slice(0, 8);
    $('#topIssues').innerHTML = top.map(issueCard).join('') || emptyMsg('No issues found. ');
    bindIssueCards($('#topIssues'));

    // Filters
    buildFilterOptions(data.findings);
    applyFilters();

    // Owners
    renderOwners(data.findings);

    // reset to dashboard view
    $$('.nav-item').forEach(n => n.classList.remove('active'));
    $('.nav-item[data-view="dashboard"]').classList.add('active');
    $$('.view').forEach(v => v.classList.remove('active'));
    $('#view-dashboard').classList.add('active');
  }

  function statCard(cls, n, label) {
    return '<div class="stat ' + cls + '"><div class="n">' + n + '</div><div class="l">' + label + '</div></div>';
  }
  function emptyMsg(t) { return '<div class="empty">' + t + '</div>'; }

  function issueCard(f) {
    return '<div class="issue" data-id="' + f.id + '">' +
      '<div class="issue-top">' +
        '<span class="sev-tag sev-' + f.severity + '">' + f.severity + '</span>' +
        '<span class="issue-title"><b>' + escapeHtml(f.issue) + '</b>' +
          '<span class="cat">' + escapeHtml(f.group) + ' · ' + escapeHtml(f.category) + '</span></span>' +
        '<span class="issue-meta">' + escapeHtml(shortUrl(f.page)) + '</span>' +
      '</div>' +
      '<div class="issue-body">' +
        row('Affected', linkify(f.page)) +
        row('Evidence', escapeHtml(f.detail)) +
        row('Why it matters', escapeHtml(f.meaning)) +
        row('How to fix', escapeHtml(f.fix)) +
        row('Owner', '<span class="owner-pill">' + escapeHtml(f.owner) + '</span>') +
        row('Detect with', escapeHtml(f.detect)) +
      '</div>' +
    '</div>';
  }
  function row(k, v) { return '<div class="row"><div class="k">' + k + '</div><div class="v">' + v + '</div></div>'; }

  function bindIssueCards(root) {
    $$('.issue-top', root).forEach(t => t.addEventListener('click', () => t.parentElement.classList.toggle('open')));
  }

  // ---- Charts ----
  function drawGroupChart(byGroup) {
    const labels = Object.keys(byGroup), values = Object.values(byGroup);
    const ctx = $('#groupChart');
    if (state.charts.group) state.charts.group.destroy();
    state.charts.group = new Chart(ctx, {
      type: 'bar',
      data: { labels, datasets: [{ data: values, backgroundColor: '#6ea8fe', borderRadius: 6, maxBarThickness: 26 }] },
      options: {
        indexAxis: 'y', plugins: { legend: { display: false } },
        scales: {
          x: { grid: { color: '#243049' }, ticks: { color: '#8a97b1' } },
          y: { grid: { display: false }, ticks: { color: '#c7d0e5', font: { size: 11 } } }
        }
      }
    });
  }
  function drawSevChart(sev) {
    const labels = ['Critical', 'High', 'Medium', 'Low'];
    const values = labels.map(l => sev[l] || 0);
    const ctx = $('#sevChart');
    if (state.charts.sev) state.charts.sev.destroy();
    state.charts.sev = new Chart(ctx, {
      type: 'doughnut',
      data: { labels, datasets: [{ data: values, backgroundColor: labels.map(l => SEV_COLOR[l]), borderColor: '#151c2c', borderWidth: 3 }] },
      options: { cutout: '62%', plugins: { legend: { position: 'bottom', labels: { color: '#c7d0e5', padding: 14, usePointStyle: true } } } }
    });
  }

  // ---- Filters / table ----
  function buildFilterOptions(findings) {
    const groups = [...new Set(findings.map(f => f.group))].sort();
    const owners = [...new Set(findings.map(f => f.owner))].sort();
    $('#fGroup').innerHTML = '<option value="">All groups</option>' + groups.map(g => '<option>' + escapeHtml(g) + '</option>').join('');
    $('#fOwner').innerHTML = '<option value="">All owners</option>' + owners.map(o => '<option>' + escapeHtml(o) + '</option>').join('');
  }
  ['#search', '#fSeverity', '#fGroup', '#fOwner'].forEach(sel => {
    const el = $(sel); if (el) el.addEventListener('input', applyFilters);
  });
  function applyFilters() {
    if (!state.data) return;
    const q = $('#search').value.toLowerCase();
    const sv = $('#fSeverity').value, gr = $('#fGroup').value, ow = $('#fOwner').value;
    state.filtered = state.data.findings.filter(f =>
      (!sv || f.severity === sv) && (!gr || f.group === gr) && (!ow || f.owner === ow) &&
      (!q || (f.issue + f.category + f.page + f.detail + f.group).toLowerCase().includes(q))
    ).sort(sortBySeverity);
    renderTable(state.filtered);
  }
  function renderTable(rows) {
    if (!rows.length) { $('#issueTableWrap').innerHTML = emptyMsg('No issues match these filters.'); return; }
    let html = '<table class="issues"><thead><tr>' +
      '<th>Severity</th><th>Issue</th><th>Group</th><th>Page</th><th>Owner</th><th>Detail</th>' +
      '</tr></thead><tbody>';
    rows.forEach(f => {
      html += '<tr>' +
        '<td><span class="sev-tag sev-' + f.severity + '">' + f.severity + '</span></td>' +
        '<td><b>' + escapeHtml(f.issue) + '</b><br><span class="muted">' + escapeHtml(f.category) + '</span></td>' +
        '<td>' + escapeHtml(f.group) + '</td>' +
        '<td class="page-cell">' + linkify(f.page) + '</td>' +
        '<td><span class="owner-pill">' + escapeHtml(f.owner) + '</span></td>' +
        '<td class="muted">' + escapeHtml(f.detail) + '</td>' +
      '</tr>';
    });
    html += '</tbody></table>';
    $('#issueTableWrap').innerHTML = html;
  }

  // ---- Owners board ----
  function renderOwners(findings) {
    const byOwner = {};
    findings.forEach(f => { (byOwner[f.owner] = byOwner[f.owner] || []).push(f); });
    const owners = Object.keys(byOwner).sort((a, b) => byOwner[b].length - byOwner[a].length);
    $('#ownerBoard').innerHTML = owners.map(o => {
      const list = byOwner[o].sort(sortBySeverity);
      return '<div class="owner-col"><div class="panel">' +
        '<h3>' + escapeHtml(o) + ' <span class="count">' + list.length + '</span></h3>' +
        '<div class="issue-list">' + list.map(issueCard).join('') + '</div></div></div>';
    }).join('') || emptyMsg('No issues.');
    bindIssueCards($('#ownerBoard'));
  }

  // ---- Catalog view ----
  function renderCatalog() {
    if (!state.data) { $('#catalogTable').innerHTML = emptyMsg('Run a scan to populate the catalog with live results.'); return; }
    const seen = {};
    state.data.findings.forEach(f => { seen[f.key] = f; });
    const rows = Object.values(seen).sort(sortBySeverity);
    let html = '<table class="issues"><thead><tr><th>Severity</th><th>Issue</th><th>Group / Category</th><th>Owner</th><th>Fix</th></tr></thead><tbody>';
    rows.forEach(f => {
      html += '<tr><td><span class="sev-tag sev-' + f.severity + '">' + f.severity + '</span></td>' +
        '<td><b>' + escapeHtml(f.issue) + '</b></td>' +
        '<td>' + escapeHtml(f.group) + '<br><span class="muted">' + escapeHtml(f.category) + '</span></td>' +
        '<td><span class="owner-pill">' + escapeHtml(f.owner) + '</span></td>' +
        '<td class="muted">' + escapeHtml(f.fix) + '</td></tr>';
    });
    html += '</tbody></table>';
    $('#catalogTable').innerHTML = html;
  }

  // ---- Exports ----
  $('#exportCsv').addEventListener('click', () => {
    if (!state.data) return;
    const cols = ['severity', 'group', 'category', 'issue', 'page', 'detail', 'owner', 'meaning', 'fix', 'detect'];
    const esc = v => '"' + String(v == null ? '' : v).replace(/"/g, '""') + '"';
    const rows = [cols.join(',')].concat(
      state.filtered.length ? state.filtered.map(f => cols.map(c => esc(f[c])).join(','))
        : state.data.findings.map(f => cols.map(c => esc(f[c])).join(',')));
    download('sitescope-issues.csv', rows.join('\n'), 'text/csv');
  });
  $('#exportJson').addEventListener('click', () => {
    if (!state.data) return;
    download('sitescope-report.json', JSON.stringify(state.data, null, 2), 'application/json');
  });
  function download(name, content, type) {
    const a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([content], { type }));
    a.download = name; a.click(); URL.revokeObjectURL(a.href);
  }

  // ---- utils ----
  function sortBySeverity(a, b) {
    const d = SEV_ORDER[a.severity] - SEV_ORDER[b.severity];
    return d !== 0 ? d : a.group.localeCompare(b.group);
  }
  function animateNum(el, target) {
    let cur = 0; const step = Math.max(1, Math.round(target / 30));
    const t = setInterval(() => { cur += step; if (cur >= target) { cur = target; clearInterval(t); } el.textContent = cur; }, 20);
  }
  function shortUrl(u) { try { const x = new URL(u); return x.pathname === '/' ? x.hostname : x.pathname; } catch (_) { return u; } }
  function linkify(u) { const s = escapeHtml(u); return /^https?:/.test(u) ? '<a href="' + s + '" target="_blank" rel="noopener">' + s + '</a>' : s; }
  function escapeHtml(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])); }
  function toast(msg) { const t = $('#toast'); t.textContent = msg; t.classList.remove('hidden'); setTimeout(() => t.classList.add('hidden'), 4000); }
})();
