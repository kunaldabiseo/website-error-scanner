<?php
/**
 * index.php — SiteScope dashboard UI.
 * Pure PHP + vanilla JS front-end. All scanning happens in api/scan.php.
 */
$curlOk = function_exists('curl_init');
$domOk  = class_exists('DOMDocument');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>SiteScope — Website Error &amp; Health Scanner</title>
<link rel="preconnect" href="https://cdn.jsdelivr.net">
<link rel="stylesheet" href="style.css">
</head>
<body>
<div class="app">

  <!-- Sidebar -->
  <aside class="sidebar">
    <div class="brand">
      <div class="brand-mark">◍</div>
      <div class="brand-text">
        <strong>SiteScope</strong>
        <span>Website Error CRM</span>
      </div>
    </div>
    <nav class="nav">
      <a class="nav-item active" data-view="dashboard"><span>◫</span> Dashboard</a>
      <a class="nav-item" data-view="issues"><span>⚠</span> All Issues</a>
      <a class="nav-item" data-view="owners"><span>◑</span> By Owner</a>
      <a class="nav-item" data-view="catalog"><span>▤</span> Catalog</a>
    </nav>
    <div class="sidebar-foot">
      <div class="legend">
        <span><i class="dot critical"></i> Critical</span>
        <span><i class="dot high"></i> High</span>
        <span><i class="dot medium"></i> Medium</span>
        <span><i class="dot low"></i> Low</span>
      </div>
      <p class="muted">Checks HTTP · SSL · Security · SEO · Performance · Accessibility · WordPress · Analytics</p>
    </div>
  </aside>

  <!-- Main -->
  <main class="main">
    <header class="topbar">
      <form id="scanForm" class="scanbar" autocomplete="off">
        <span class="scanbar-icon">🔍</span>
        <input id="urlInput" type="text" placeholder="Enter a website URL, e.g. https://www.steelandstud.com/" required>
        <button id="scanBtn" type="submit">Scan site</button>
        <button id="optBtn" type="button" class="ghost" title="Scan options">⚙</button>
      </form>
      <div id="optionsPanel" class="options hidden">
        <label>Max pages
          <input id="maxPages" type="number" min="1" max="200" value="40">
        </label>
        <label>Timeout (s)
          <input id="timeout" type="number" min="5" max="30" value="20">
        </label>
        <div class="cats">
          <label><input type="checkbox" class="cat" value="seo" checked> SEO</label>
          <label><input type="checkbox" class="cat" value="technical" checked> Technical / HTTP / SSL</label>
          <label><input type="checkbox" class="cat" value="performance" checked> Performance</label>
          <label><input type="checkbox" class="cat" value="accessibility" checked> Accessibility / UI</label>
          <label><input type="checkbox" class="cat" value="analytics" checked> Analytics</label>
        </div>
      </div>
    </header>

    <?php if (!$curlOk || !$domOk): ?>
      <div class="banner error">
        Missing PHP extensions:
        <?php if (!$curlOk) echo ' ext-curl'; ?>
        <?php if (!$domOk) echo ' ext-dom/xml'; ?>.
        Enable them in php.ini and restart.
      </div>
    <?php endif; ?>

    <!-- Idle / welcome -->
    <section id="welcome" class="welcome">
      <div class="welcome-card">
        <h1>Scan any website for 40+ real issues</h1>
        <p>Enter a URL above. SiteScope crawls the site server-side and reports every detectable
           problem — broken links, redirects, SSL, security headers, SEO, performance, accessibility,
           WordPress hardening and more — mapped to severity and the developer who owns the fix.</p>
        <div class="samples">
          <span>Try:</span>
          <button class="chip sample" data-url="https://www.steelandstud.com/">steelandstud.com</button>
          <button class="chip sample" data-url="https://example.com/">example.com</button>
        </div>
      </div>
    </section>

    <!-- Progress -->
    <section id="progress" class="progress hidden">
      <div class="progress-head">
        <h2 id="progressTitle">Scanning…</h2>
        <button id="cancelBtn" class="ghost small">Stop</button>
      </div>
      <div class="bar"><div id="barFill" class="bar-fill"></div></div>
      <div class="progress-stats">
        <span id="pgPages">0 pages</span>
        <span id="pgQueued">0 queued</span>
        <span id="pgFindings">0 issues</span>
      </div>
      <ul id="crawlLog" class="crawl-log"></ul>
    </section>

    <!-- Results -->
    <section id="results" class="results hidden">

      <!-- DASHBOARD VIEW -->
      <div id="view-dashboard" class="view active">
        <div class="scoreboard">
          <div class="score-card">
            <div class="gauge"><svg viewBox="0 0 120 120"><circle class="g-bg" cx="60" cy="60" r="52"/><circle id="gaugeArc" class="g-arc" cx="60" cy="60" r="52"/></svg>
              <div class="gauge-num"><span id="scoreNum">0</span><small>/100</small></div>
            </div>
            <div class="score-meta">
              <div class="grade" id="gradeBadge">–</div>
              <div class="score-sub">Health score</div>
              <div class="scanned-url" id="scannedUrl"></div>
              <div class="muted" id="scannedMeta"></div>
            </div>
          </div>
          <div class="stat-cards" id="sevCards"></div>
        </div>

        <div class="charts">
          <div class="panel">
            <h3>Issues by group</h3>
            <canvas id="groupChart" height="220"></canvas>
          </div>
          <div class="panel">
            <h3>Severity mix</h3>
            <canvas id="sevChart" height="220"></canvas>
          </div>
        </div>

        <div class="panel">
          <div class="panel-head">
            <h3>Top priorities</h3>
            <span class="muted">Highest-severity issues first</span>
          </div>
          <div id="topIssues" class="issue-list"></div>
        </div>
      </div>

      <!-- ALL ISSUES VIEW -->
      <div id="view-issues" class="view">
        <div class="filters">
          <input id="search" type="text" placeholder="Search issues, pages, categories…">
          <select id="fSeverity"><option value="">All severities</option><option>Critical</option><option>High</option><option>Medium</option><option>Low</option></select>
          <select id="fGroup"><option value="">All groups</option></select>
          <select id="fOwner"><option value="">All owners</option></select>
          <div class="spacer"></div>
          <button id="exportCsv" class="ghost small">Export CSV</button>
          <button id="exportJson" class="ghost small">Export JSON</button>
        </div>
        <div id="issueTableWrap"></div>
      </div>

      <!-- OWNERS VIEW -->
      <div id="view-owners" class="view">
        <div id="ownerBoard" class="owner-board"></div>
      </div>

      <!-- CATALOG VIEW -->
      <div id="view-catalog" class="view">
        <div class="panel">
          <div class="panel-head">
            <h3>Detection catalog</h3>
            <span class="muted">Every check SiteScope runs, mapped to the issue catalog</span>
          </div>
          <div id="catalogTable"></div>
        </div>
      </div>

    </section>
  </main>
</div>

<div id="toast" class="toast hidden"></div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script src="app.js"></script>
</body>
</html>
