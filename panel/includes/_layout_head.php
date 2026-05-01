<?php
/**
 * Ortak panel layout basligi: <head>, sidebar, app-shell acilis.
 * Cagiran sayfa $activePage ('panel'|'arac-kabul'|'filo'|'raporlar'|'ayarlar') ve
 * $pageTitle (opsiyonel) tanimlayabilir.
 */

if (!isset($activePage)) { $activePage = ''; }
if (!isset($pageTitle)) { $pageTitle = panel_config('app_name'); }

$user = $user ?? current_user();
$userName = $userName ?? (($user['full_name'] ?? '') ?: ($user['username'] ?? ''));
$userInitial = $userInitial ?? mb_strtoupper(mb_substr((string)$userName, 0, 1, 'UTF-8'), 'UTF-8');

if (!function_exists('_nav_class')) {
    function _nav_class(string $key, string $active): string {
        return 'nav-item' . ($key === $active ? ' active' : '');
    }
}
?><!doctype html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($pageTitle) ?> - <?= e(panel_config('app_name')) ?></title>
  <link rel="stylesheet" href="<?= e(panel_url('assets/panel.css')) ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body>
  <div class="app-shell">
    <aside class="sidebar" id="appSidebar">
      <div class="brand-block">
        <div class="brand-mark">DRN</div>
        <div class="brand-text">
          <span class="t1">Servis Paneli</span>
          <span class="t2">Filo &amp; Sigorta</span>
        </div>
      </div>

      <div class="nav-section-label">Menu</div>
      <nav>
        <a class="<?= e(_nav_class('panel', $activePage)) ?>" href="<?= e(panel_url('index.php')) ?>">
          <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="9"/><rect x="14" y="3" width="7" height="5"/><rect x="14" y="12" width="7" height="9"/><rect x="3" y="16" width="7" height="5"/></svg>
          Panel
        </a>
        <a class="<?= e(_nav_class('arac-kabul', $activePage)) ?>" href="<?= e(panel_url('add.php')) ?>">
          <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 16H9m10 0h3v-3.15a1 1 0 0 0-.84-.99L16 11l-2.7-3.6a1 1 0 0 0-.8-.4H5.24a2 2 0 0 0-1.8 1.1l-.8 1.63A6 6 0 0 0 2 12.42V16h2"/><circle cx="6.5" cy="16.5" r="2.5"/><circle cx="16.5" cy="16.5" r="2.5"/></svg>
          Arac Kabul
        </a>
        <a class="<?= e(_nav_class('filo', $activePage)) ?>" href="<?= e(panel_url('index.php')) ?>?filo=1">
          <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
          Filo / Sigorta
        </a>
        <a class="<?= e(_nav_class('raporlar', $activePage)) ?>" href="<?= e(panel_url('import.php')) ?>">
          <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="M7 14l4-4 4 4 6-6"/></svg>
          Raporlar
        </a>
        <a class="<?= e(_nav_class('ayarlar', $activePage)) ?>" href="<?= e(panel_url('install/migrate.php')) ?>">
          <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09a1.65 1.65 0 0 0 1.51-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33h.01a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
          Ayarlar
        </a>
      </nav>

      <div class="spacer"></div>

      <div class="user-card">
        <div class="avatar"><?= e($userInitial ?: 'U') ?></div>
        <div class="who">
          <span class="n"><?= e($userName ?: 'Kullanici') ?></span>
          <span class="r">Yonetici</span>
        </div>
        <a class="out" title="Cikis" href="<?= e(panel_url('logout.php')) ?>" aria-label="Cikis">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
        </a>
      </div>
    </aside>
