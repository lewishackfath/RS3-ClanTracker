<?php
// includes/menu.php
declare(strict_types=1);

// On each page set:
// $menu_title = 'Tracker';
// $menu_subtitle = 'Clan & member lookup';
// $menu_active = 'home'; // home|help|bingo

$menu_title = $menu_title ?? 'Tracker';
$menu_subtitle = $menu_subtitle ?? '';
$menu_active = $menu_active ?? '';

function menu_btn_class(string $key, string $active): string {
  return 'button secondary' . (($key !== '' && $key === $active) ? ' isActive' : '');
}

// Update these if your paths differ
$links = [
  'home' => ['label' => 'Home', 'href' => 'index', 'target' => ''],
  'help' => ['label' => 'Help & Documentation', 'href' => 'help', 'target' => '_blank'],
  'bingo' => ['label' => 'Clan Bingo', 'href' => 'https://bingo.24krs.com.au', 'target' => '_blank'],
];
?>
<header class="topbar">
  <div class="container">
    <div class="brand brandBar">
      <a class="brandLogo" href="https://tracker.24krs.com.au" target="_blank" rel="noopener">
        <img class="logoImg" src="https://24krs.com.au/wp-content/uploads/2025/12/24K-Logo.gif" alt="24K" />
      </a>

      <div class="brandText">
        <div class="title"><?= htmlspecialchars((string)$menu_title, ENT_QUOTES, 'UTF-8') ?></div>
        <?php if (trim((string)$menu_subtitle) !== ''): ?>
          <div class="subtitle"><?= htmlspecialchars((string)$menu_subtitle, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
      </div>

      <!-- Desktop nav -->
      <nav class="navLinks" aria-label="Primary">
        <?php foreach ($links as $key => $l): ?>
          <a href="<?= htmlspecialchars((string)$l['href'], ENT_QUOTES, 'UTF-8') ?>"
             <?= !empty($l['target']) ? 'target="'.htmlspecialchars((string)$l['target'], ENT_QUOTES, 'UTF-8').'" rel="noopener"' : '' ?>
             class="navA">
            <button class="<?= menu_btn_class((string)$key, (string)$menu_active) ?>" type="button">
              <?= htmlspecialchars((string)$l['label'], ENT_QUOTES, 'UTF-8') ?>
            </button>
          </a>
        <?php endforeach; ?>
      </nav>

      <!-- Mobile hamburger -->
      <button class="hamburgerBtn" type="button"
              aria-label="Open menu" aria-expanded="false" aria-controls="mobileMenu"
              onclick="toggleMobileMenu()">
        <span class="hamburgerIcon" aria-hidden="true"></span>
      </button>
    </div>

    <!-- Mobile menu panel -->
    <div id="mobileMenu" class="mobileMenu" hidden>
      <div class="mobileMenuInner">
        <?php foreach ($links as $key => $l): ?>
          <a class="mobileMenuLink"
             href="<?= htmlspecialchars((string)$l['href'], ENT_QUOTES, 'UTF-8') ?>"
             <?= !empty($l['target']) ? 'target="'.htmlspecialchars((string)$l['target'], ENT_QUOTES, 'UTF-8').'" rel="noopener"' : '' ?>
             onclick="closeMobileMenu()">
            <?= htmlspecialchars((string)$l['label'], ENT_QUOTES, 'UTF-8') ?>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</header>

<script>
  function toggleMobileMenu(){
    const panel = document.getElementById('mobileMenu');
    const btn = document.querySelector('.hamburgerBtn');
    if (!panel || !btn) return;

    const isOpen = !panel.hasAttribute('hidden');
    if (isOpen) return closeMobileMenu();

    panel.removeAttribute('hidden');
    panel.classList.add('open');
    btn.setAttribute('aria-expanded', 'true');
  }

  function closeMobileMenu(){
    const panel = document.getElementById('mobileMenu');
    const btn = document.querySelector('.hamburgerBtn');
    if (!panel || !btn) return;

    panel.classList.remove('open');
    panel.setAttribute('hidden', '');
    btn.setAttribute('aria-expanded', 'false');
  }

  // Close on escape + click outside
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeMobileMenu();
  });

  document.addEventListener('click', (e) => {
    const panel = document.getElementById('mobileMenu');
    const btn = document.querySelector('.hamburgerBtn');
    if (!panel || !btn || panel.hasAttribute('hidden')) return;
    if (panel.contains(e.target) || btn.contains(e.target)) return;
    closeMobileMenu();
  });
</script>
