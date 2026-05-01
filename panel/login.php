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
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title>Giris - <?= e(panel_config('app_name')) ?></title>
  <?php render_panel_head_assets(); ?>
</head>
<body class="login-page">
  <div class="login-shell">
    <form class="login-card" method="post" action="<?= e(panel_url('login.php')) ?>">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <div class="brand">DRN</div>
      <h1>Servis Paneli</h1>
      <?php if ($error !== ''): ?>
        <div class="alert"><?= e($error) ?></div>
      <?php endif; ?>
      <label>
        Kullanici adi
        <input name="username" autocomplete="username" required>
      </label>
      <label>
        Sifre
        <input type="password" name="password" autocomplete="current-password" required>
      </label>
      <button type="submit">Giris yap</button>
    </form>
  </div>
</body>
</html>
