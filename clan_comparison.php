<?php
require_once __DIR__ . '/includes/config.php';
$brand = tracker_brand_config();
$publicConfig = tracker_public_js_config();
$pageTitle = trim((string)$brand['name']) !== '' ? (string)$brand['name'] . ' Clan Comparison' : 'Clan Tracker Clan Comparison';
?>
<!doctype html>
<html lang="en-AU">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
  <link rel="stylesheet" href="./styles.css?v=202607020401" />
</head>
<body<?= tracker_body_style_attr($brand) ?>>
  <?php
$menu_title = $brand['name'];
$menu_subtitle = 'Clan member comparison';
$menu_active = 'clan_comparison';
include __DIR__ . '/includes/menu.php';
?>

  <main class="container main">
    <section class="card clanComparisonPage" id="clanComparisonPage">
      <div class="row capHistoryHeaderRow">
        <div>
          <h1 class="h1">Clan Comparison</h1>
          <p class="muted" id="clanComparisonSubheading">Single-page overview of active clan members by rank.</p>
        </div>
        <a class="navA" href="index">
          <button class="button secondary" type="button">Clan Overview</button>
        </a>
      </div>

      <div class="statsGrid capHistoryStatsGrid" aria-label="Clan comparison summary">
        <div class="statCard summaryStatCard">
          <div class="statLabel">Active Members</div>
          <div class="statValue" id="comparisonActiveMembers">—</div>
        </div>
        <div class="statCard summaryStatCard">
          <div class="statLabel">Private Profiles</div>
          <div class="statValue" id="comparisonPrivateProfiles">—</div>
        </div>
      </div>

      <div class="toolbar capHistoryToolbar">
        <input id="comparisonSearch" class="input" type="text" autocomplete="off" placeholder="Search members or skills…" />
        <select id="comparisonRankFilter" class="select" aria-label="Rank filter">
          <option value="all">All ranks</option>
        </select>
      </div>

      <div class="muted" id="comparisonStatus" style="margin-top:10px;">Loading clan comparison…</div>
      <div class="comparisonTableWrap" id="comparisonTableWrap"></div>
      <div class="lastPull muted" id="comparisonGenerated"></div>
    </section>
  </main>

  <?php include __DIR__ . '/includes/footer.php'; ?>

  <script>
    window.TRACKER_CONFIG = <?= json_encode($publicConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
  </script>
  <script src="./config/skills.js?v=202606050001"></script>
  <script src="./app.js?v=202607020401"></script>
  <script src="./clan_comparison.js?v=202607020401"></script>
</body>
</html>
