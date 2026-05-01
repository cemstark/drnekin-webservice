<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

if (current_user() !== null) {
    redirect_to('index.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    if (login_user($username, $password)) {
        redirect_to('index.php');
    }
    $error = 'Kullanici adi veya sifre hatali.';
}
?>
<!doctype html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Giris - <?= e(panel_config('app_name')) ?></title>
  <link rel="stylesheet" href="<?= e(panel_url('assets/panel.css')) ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body class="login-page">
  <main class="login-shell">
    <form class="login-card" method="post" action="<?= e(panel_url('login.php')) ?>">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <div class="brand-mark">DRN</div>
      <h1>Servis Paneline Giris</h1>
      <p class="lead">Filo &amp; sigorta operasyonlarinizi tek panelden yonetin.</p>

      <?php if ($error !== ''): ?>
        <div class="banner is-danger" style="margin-bottom:0">
          <span class="ico"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg></span>
          <div><h3>Giris yapilamadi</h3><p><?= e($error) ?></p></div>
        </div>
      <?php endif; ?>

      <label>
        Kullanici adi
        <input name="username" autocomplete="username" required>
      </label>
      <label>
        Sifre
        <input type="password" name="password" autocomplete="current-password" required>
      </label>
      <button type="submit">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
        Giris yap
      </button>

      <div class="foot">DRN Servis &middot; Filo &amp; Sigorta Operasyonu</div>
    </form>
  </main>
</body>
</html>
