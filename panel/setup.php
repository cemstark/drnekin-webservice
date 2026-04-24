<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

$error = '';
$created = false;

try {
    $count = (int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
} catch (Throwable $e) {
    http_response_code(500);
    exit('Veritabani hazir degil. Once install/schema.sql dosyasini phpMyAdmin uzerinden calistirin.');
}

if ($count > 0) {
    redirect_to('login.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $username = trim((string)($_POST['username'] ?? ''));
    $fullName = trim((string)($_POST['full_name'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $passwordConfirm = (string)($_POST['password_confirm'] ?? '');

    if ($username === '' || $password === '') {
        $error = 'Kullanici adi ve sifre zorunludur.';
    } elseif (strlen($password) < 10) {
        $error = 'Sifre en az 10 karakter olmalidir.';
    } elseif ($password !== $passwordConfirm) {
        $error = 'Sifre tekrari eslesmiyor.';
    } else {
        $stmt = db()->prepare('INSERT INTO users (username, password_hash, full_name, role) VALUES (?, ?, ?, ?)');
        $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), $fullName, 'admin']);
        $created = true;
    }
}
?>
<!doctype html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Kurulum - <?= e(panel_config('app_name')) ?></title>
  <link rel="stylesheet" href="<?= e(panel_url('assets/panel.css')) ?>">
</head>
<body class="login-page">
  <main class="login-shell">
    <form class="login-card" method="post" action="<?= e(panel_url('setup.php')) ?>">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <div class="brand">DRN</div>
      <h1>Ilk Kurulum</h1>
      <?php if ($created): ?>
        <div class="success">Admin kullanicisi olusturuldu. <a href="<?= e(panel_url('login.php')) ?>">Giris yapin</a>.</div>
      <?php else: ?>
        <?php if ($error !== ''): ?>
          <div class="alert"><?= e($error) ?></div>
        <?php endif; ?>
        <label>
          Kullanici adi
          <input name="username" value="admin" autocomplete="username" required>
        </label>
        <label>
          Ad soyad
          <input name="full_name" autocomplete="name">
        </label>
        <label>
          Sifre
          <input type="password" name="password" autocomplete="new-password" required>
        </label>
        <label>
          Sifre tekrar
          <input type="password" name="password_confirm" autocomplete="new-password" required>
        </label>
        <button type="submit">Admin olustur</button>
      <?php endif; ?>
    </form>
  </main>
</body>
</html>
