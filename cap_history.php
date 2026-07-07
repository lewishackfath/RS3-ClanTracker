<?php
require_once __DIR__ . '/includes/config.php';
$brand = tracker_brand_config();
$publicConfig = tracker_public_js_config();
$pageTitle = trim((string)$brand['name']) !== '' ? (string)$brand['name'] . ' Cap History' : 'Clan Tracker Cap History';
?>
<!doctype html>
<html lang="en-AU">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
  <link rel="stylesheet" href="./styles.css?v=202607070420" />
</head>
<body<?= tracker_body_style_attr($brand) ?>>
  <?php
$menu_title = $brand['name'];
$menu_subtitle = 'Citadel visit and cap history';
$menu_active = 'cap_history';
include __DIR__ . '/includes/menu.php';
?>

  <main class="container main">
    <section class="card capHistoryPage" id="capHistoryPage">
      <div class="row capHistoryHeaderRow">
        <div>
          <h1 class="h1">Cap History</h1>
          <p class="muted" id="capHistorySubheading">Active clan member citadel history by rank.</p>
        </div>
        <a class="navA" href="index">
          <button class="button secondary" type="button">Clan Overview</button>
        </a>
      </div>

      <div class="statsGrid capHistoryStatsGrid" aria-label="Cap history summary">
        <div class="statCard summaryStatCard">
          <div class="statLabel">Active Members</div>
          <div class="statValue" id="capHistoryActiveMembers">—</div>
        </div>
        <div class="statCard summaryStatCard">
          <div class="statLabel">Total Visits</div>
          <div class="statValue" id="capHistoryTotalVisits">—</div>
        </div>
        <div class="statCard summaryStatCard">
          <div class="statLabel">Total Caps</div>
          <div class="statValue" id="capHistoryTotalCaps">—</div>
        </div>
      </div>

      <div id="capHistoryCapsChart" class="capHistoryCapsChartMount" aria-live="polite"></div>

      <div class="toolbar capHistoryToolbar">
        <input id="capHistorySearch" class="input" type="text" autocomplete="off" placeholder="Search members…" />
        <select id="capHistoryRankFilter" class="select" aria-label="Rank filter">
          <option value="all">All ranks</option>
        </select>
      </div>

      <div class="muted" id="capHistoryStatus" style="margin-top:10px;">Loading cap history…</div>
      <div class="capHistoryGroups" id="capHistoryGroups"></div>
      <div class="lastPull muted" id="capHistoryGenerated"></div>
    </section>
  </main>

  <?php include __DIR__ . '/includes/footer.php'; ?>

  <script>
    window.TRACKER_CONFIG = <?= json_encode($publicConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
  </script>
  <script src="./config/skills.js?v=202606050001"></script>
  <script src="./app.js?v=202607070420"></script>
  <script src="./cap_history.js?v=202607070420"></script>
</body>
</html>
