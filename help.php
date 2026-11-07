<?php
require_once __DIR__ . '/includes/config.php';
$brand = tracker_brand_config();
$publicConfig = tracker_public_js_config();
$pageTitle = trim((string)$brand['name']) !== '' ? (string)$brand['name'] . ' Help' : 'Clan Tracker Help';
?>
<!doctype html>
<html lang="en-AU">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
  <link rel="stylesheet" href="./styles.css?v=202607070730" />
</head>
<body<?= tracker_body_style_attr($brand) ?>>
  <?php
$menu_title = $brand['name'] . ' - Help';
$menu_subtitle = 'Documentation & Help Guide';
$menu_active = 'help';
include __DIR__ . '/includes/menu.php';
?>
  <main class="container main">
    <!-- Landing -->
    <section class="card" id="landingCard">
      <h1 class="h1">Welcome</h1>
      <p class="muted">
        Recent activities, skill levels and XP come from the Adventurer’s Log. Hiscores supplies rankings and activity scores.
        The old RuneMetrics profile privacy setting no longer controls these API responses.
        Activity recording requires active membership and the relevant in-game event settings.
        Only the latest 20 events are available from RuneScape; the tracker keeps events it has already collected.
      </p>
      <div class="grid">
        <div class="panel">
          <h2 class="h2">When your data updates</h2>
          <p>RuneScape syncs skills and XP periodically during play, when you log out and when you change worlds.
             Events and skill updates can take up to ten more minutes to reach the API. The tracker picks them up on its next poll or player refresh.
             Changing event settings affects future events only.</p>
        </div>
        <div class="panel">
          <h2 class="h2">Enable activity recording</h2>
          <ol>
            <li>
              Run the Jagex Launcher and Login to RuneScape.
            </li>
            <li>
              Open Settings > Gameplay > Chat & Social > RuneMetrics Event Log.
            </li>
            <li>
              Tick all the boxes under the RuneMetrics Event Log Settings. This will enable all the required logs.
              <img src="assets/help/rmevents-step3.png" style="display:block;width:100%"/>
            </li>
          </ol>
        </div>
      </div>
    </section>
  </main>
  <?php include __DIR__ . '/includes/footer.php'; ?>

  <script>
    window.TRACKER_CONFIG = <?= json_encode($publicConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
  </script>
  <script src="./config/skills.js?v=202606050001"></script>
  <script src="./app.js?v=202607081152"></script>
</body>
</html>
