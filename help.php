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
        If a RuneMetrics Profile is set to private the app is unable to access XP/Activity Data.<br>
        This prevents us from knowing if you have visited or capped at the citadel, we cannot track your XP stats, 
        and are therefore unable to include your profile in any competitions or giveaways that use this date. We respect 
        your right to have your profile as private if you wish. The guide below shows you how to set your profile to public 
        and include all the required data in your activity logs.
      </p>


      <div class="grid">
        <div class="panel">
          <h2 class="h2">RuneMetrics Public/Private Profile</h2>
          <ol>
            <li>
              Login to your Jagex account at <a style="text-decoration:none;color:gold" href="https://account.runescape.com" target="_blank">account.runescape.com</a>
            </li>
            <li>
              Select the Character you wish to edit the profile of:<br>
              <img src="assets/help/rmprofile-step2.png"/>
            </li>
            <li>
              Click "My Account" in the top Menu Bar
              <img src="assets/help/rmprofile-step3.png"/>
            </li>
            <li>
              From the Account Management Screen Select the Characters Button.
            </li>
            <li>
              On the Character Page for the Character who's profile you wish to change click "Manage" and then select "RuneMetrics Profile"
              <img src="assets/help/rmprofile-step5.png"/>
            </li>
            <li>
              On the RuneMetrics Profile Page, select "Public" and Press "Change Settings"
              <img src="assets/help/rmprofile-step6.png"/>
            </li>
          </ol>
        </div>

        <div class="panel">
          <h2 class="h2">RuneMetrics Additional Data</h2>
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
  <script src="./app.js?v=202606050001"></script>
</body>
</html>
