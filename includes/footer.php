<?php
// includes/footer.php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
$brand = tracker_brand_config();
?>
<footer class="footer">
  <div class="footerGrid container">
    <div class="footerCol footerColLeft">
      <div class="footerTitle"><?= htmlspecialchars((string)$brand['footer_title'], ENT_QUOTES, 'UTF-8') ?></div>
      <div class="footerLink"><?= htmlspecialchars((string)$brand['domain'], ENT_QUOTES, 'UTF-8') ?></div>
    </div>

    <div class="footerCol footerColCenter">
      <div class="footerDisclaimer">
        This application is an independent RuneScape clan and experience tracking tool.
        It is not affiliated with, endorsed by, or connected to Jagex Ltd, RuneScape,
        or any related intellectual property. All RuneScape-related assets and names
        are the property of their respective owners and are used for informational
        purposes only.
      </div>
    </div>

    <div class="footerCol footerColRight">
      <div class="footerCreditTop">Application Designed &amp; Developed by:</div>
      <?php if (trim((string)$brand['developer_logo_url']) !== ''): ?>
        <img
          src="<?= htmlspecialchars((string)$brand['developer_logo_url'], ENT_QUOTES, 'UTF-8') ?>"
          alt="<?= htmlspecialchars((string)$brand['developer_name'], ENT_QUOTES, 'UTF-8') ?>"
          class="footerLogo"
        />
      <?php else: ?>
        <div class="footerTitle"><?= htmlspecialchars((string)$brand['developer_name'], ENT_QUOTES, 'UTF-8') ?></div>
      <?php endif; ?>
      <div class="footerCreditBottom">Copyright © 2026</div>
    </div>
  </div>
</footer>
