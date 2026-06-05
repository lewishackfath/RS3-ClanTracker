<?php
require_once __DIR__ . '/includes/config.php';
$brand = tracker_brand_config();
$publicConfig = tracker_public_js_config();
$pageTitle = trim((string)$brand['name']) !== '' ? (string)$brand['name'] : 'Clan Tracker';
?>
<!doctype html>
<html lang="en-AU">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
  <link rel="stylesheet" href="./styles.css" />
</head>
<body>
  <?php
$menu_title = $brand['name'];
$menu_subtitle = $brand['subtitle'];
$menu_active = 'home';
include __DIR__ . '/includes/menu.php';
?>

  <main class="container main">
    <!-- Configuration fallback -->
    <section class="card" id="landingCard">
      <h1 class="h1">Clan Tracker Setup</h1>
      <p class="muted">
        Set <code>TRACKER_CLAN_ID</code> in your <code>.env</code> file to make this page open directly to your configured clan overview.
      </p>
      <div class="panel" style="margin-top:14px;">
        <h2 class="h2">Character search</h2>
        <p class="muted">Once configured, this page becomes the clan overview. The top search bar can still be used to open an individual player profile.</p>
      </div>
      <div id="notice" class="notice" role="status" aria-live="polite"></div>
    </section>

    <!-- Clan view -->
    <section class="card hidden" id="viewClan">
      <div class="row">
        <div>
          <h2 class="h2">Clan overview</h2>
          <p class="muted" id="clanSubheading"></p>
        </div>
        <button class="button secondary" type="button" id="backFromClan">Back</button>
      </div>

      <div class="statsGrid" id="clanStats">
        <div class="statCard">
          <div class="statLabel">Active members</div>
          <div class="statValue" id="statActive">—</div>
        </div>
        <div class="statCard">
          <div class="statLabel">Private profiles</div>
          <div class="statValue" id="statPrivate">—</div>
        </div>
        <div class="statCard">
          <div class="statLabel">Capped</div>
          <div class="statValue" id="statCapped">—</div>
        </div>
        <div class="statCard">
          <div class="statLabel">Uncapped</div>
          <div class="statValue" id="statUncapped">—</div>
        </div>
        <div class="statCard">
          <div class="statLabel">% capped</div>
          <div class="statValue" id="statPercent">—</div>
        </div>
      </div>

      <div class="panel" style="margin-top:14px;">
        <div class="row" style="align-items:center;">
          <div style="display:flex; flex-direction:column; gap:8px;">
            <h3 class="h2" style="margin:0;">Clan XP</h3>
            <div class="segmented xpTabs" id="clanXpTabs" role="tablist" aria-label="Clan XP view">
              <button class="segBtn active" type="button" data-xptab="total" role="tab" aria-selected="true">Total Clan XP</button>
              <button class="segBtn" type="button" data-xptab="leaders" role="tab" aria-selected="false">Top earners</button>
            </div>
          </div>
          <div style="min-width:220px;">
            <select id="clanXpPeriod" class="select"></select>
          </div>
        </div>
        <div class="muted" id="clanXpMeta" style="margin-top:6px;"></div>
        <div class="skillsLeadersGrid" id="clanSkillLeaders"></div>
      </div>

      <div class="toolbar">
        <input id="memberSearch" class="input" type="text" autocomplete="off" placeholder="Search members…" />
        <select id="rankFilter" class="select" aria-label="Rank filter">
          <option value="all">All ranks</option>
        </select>
        <div class="segmented">
          <button class="segBtn active" data-filter="all" type="button">All</button>
          <button class="segBtn" data-filter="capped" type="button">Capped</button>
          <button class="segBtn" data-filter="uncapped" type="button">Uncapped</button>
          <button class="segBtn" data-filter="visited_only" type="button">Visited only</button>
          <button class="segBtn" data-filter="private" type="button">Private</button>
          <button class="segBtn" data-filter="guests" type="button">Guests</button>
        </div>
      </div>

      <div class="muted" id="clanStatus" style="margin-top:10px;"></div>
      <div class="memberList" id="memberList"></div>

      <div class="lastPull muted" id="clanLastPull"></div>
    </section>

    <!-- Player view -->
    <section class="card hidden" id="viewPlayer">
      <div class="row">
        <div>
          <h2 class="h2">Player</h2>
          <p class="muted" id="playerSubheading"></p>
        </div>
        <button class="button secondary" type="button" id="backFromPlayer">Back</button>
      </div>

      <div class="playerHeader">
        <div class="playerNameRow">
          <img id="playerAvatar" class="playerAvatar hidden" alt="" />
          <div class="playerName" id="playerName">—</div>
        </div>
        <div class="playerMeta" id="playerMeta">—</div>
      </div>

      <div class="statsGridPlayer">
        <div class="statCard">
          <div class="statLabel">Cap (this week)</div>
          <div class="statValue" id="pCap">—</div>
        </div>
        <div class="statCard">
          <div class="statLabel">Citadel visit (this week)</div>
          <div class="statValue" id="pVisit">—</div>
        </div>
        <div class="statCard">
          <div class="statLabel">XP gained</div>
          <div class="statValue" id="pXpGained">—</div>
        </div>
        <div class="statCard">
          <div class="statLabel">XP period</div>
          <div class="statValue">
            <select id="xpPeriod" class="select"></select>
          </div>
        </div>
      </div>

      <div class="twoCol">
        <div class="panel">
          <h3 class="h2" style="margin-bottom:8px;">Current skills</h3>
          <div class="skillsGrid" id="skillsGrid"></div>
        </div>

        <div class="panel">
          <h3 class="h2" style="margin-bottom:8px;">Top XP skills</h3>
          <div class="skillList" id="skillList"></div>
        </div>

        <div class="panel" id="activityPanel">
          <h3 class="h2" style="margin-bottom:8px;">Recent activity</h3>
          <div class="muted" id="activityStatus"></div>
          <div class="activityList" id="activityList"></div>
        </div>



        <div class="panel" id="questsPanel">
          <h3 class="h2" style="margin-bottom:8px;">Quests</h3>
          <div class="muted" id="questMeta">—</div>
          <div class="muted" id="questStatus" style="margin-top:6px;"></div>
          <div class="skillList" id="questList" style="margin-top:8px;"></div>
        </div>
      </div>

      <div class="muted" id="playerError" style="margin-top:10px;"></div>
      <div class="lastPull muted" id="playerLastPull"></div>
    </section>
  </main>

  <?php include __DIR__ . '/includes/footer.php'; ?>

  <script>
    window.TRACKER_CONFIG = <?= json_encode($publicConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
  </script>
  <script src="./config/skills.js?v=202606050001"></script>
  <script src="./app.js?v=202606050001"></script>
</body>
</html>
