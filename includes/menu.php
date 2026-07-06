<?php
// includes/menu.php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

// On each page set:
// $menu_title = 'Tracker';
// $menu_subtitle = 'Clan & member lookup';
// $menu_active = 'home'; // home|clan_comparison|cap_history|help|bingo

$brand = tracker_brand_config();
$menu_title = $menu_title ?? $brand['name'];
$menu_subtitle = $menu_subtitle ?? $brand['subtitle'];
$menu_active = $menu_active ?? '';

function menu_btn_class(string $key, string $active): string {
  return 'button secondary' . (($key !== '' && $key === $active) ? ' isActive' : '');
}

$links = [
  'home' => ['label' => 'Clan Overview', 'href' => 'index', 'target' => ''],
  'clan_comparison' => ['label' => 'Clan Comparison', 'href' => 'clan_comparison', 'target' => ''],
  'cap_history' => ['label' => 'Cap History', 'href' => 'cap_history', 'target' => ''],
  'help' => ['label' => 'Help', 'href' => 'help', 'target' => '_blank'],
];

if (!empty($brand['show_bingo_link']) && trim((string)$brand['bingo_url']) !== '') {
  $links['bingo'] = ['label' => 'Clan Bingo', 'href' => (string)$brand['bingo_url'], 'target' => '_blank'];
}
?>
<header class="topbar">
  <div class="container">
    <div class="brand brandBar">
      <a class="brandLogo" href="<?= htmlspecialchars((string)$brand['home_url'], ENT_QUOTES, 'UTF-8') ?>" rel="noopener">
        <img class="logoImg" src="<?= htmlspecialchars((string)$brand['logo_url'], ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars((string)$brand['short_name'], ENT_QUOTES, 'UTF-8') ?>" />
      </a>

      <div class="brandText">
        <div class="title"><?= htmlspecialchars((string)$menu_title, ENT_QUOTES, 'UTF-8') ?></div>
        <?php if (trim((string)$menu_subtitle) !== ''): ?>
          <div class="subtitle"><?= htmlspecialchars((string)$menu_subtitle, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
      </div>

      <div class="topCharacterSearch" role="search" aria-label="Character search">
        <div class="typeahead topTypeahead">
          <input id="topPlayerRsn" class="input topSearchInput" type="text" autocomplete="off"
                 placeholder="Search character…" aria-expanded="false" aria-controls="topPlayerList" />
          <div id="topPlayerList" class="dropdown hidden" role="listbox" aria-label="Character results"></div>
        </div>
      </div>

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

      <button class="hamburgerBtn" type="button"
              aria-label="Open menu" aria-expanded="false" aria-controls="mobileMenu"
              onclick="toggleMobileMenu()">
        <span class="hamburgerIcon" aria-hidden="true"></span>
      </button>
    </div>

    <div id="mobileMenu" class="mobileMenu" hidden>
      <div class="mobileMenuInner">
        <div class="mobileSearchWrap" role="search" aria-label="Character search">
          <div class="typeahead">
            <input id="mobilePlayerRsn" class="input" type="text" autocomplete="off"
                   placeholder="Search character…" aria-expanded="false" aria-controls="mobilePlayerList" />
            <div id="mobilePlayerList" class="dropdown hidden" role="listbox" aria-label="Character results"></div>
          </div>
        </div>

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
