<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

$error = '';
$created = false;
$schemaInstalled = false;

function setup_ensure_schema(): bool
{
    try {
        db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
        return false;
    } catch (Throwable $e) {
        $schemaFile = __DIR__ . '/install/schema.sql';
        if (!is_file($schemaFile)) {
            throw new RuntimeException('Kurulum SQL dosyasi bulunamadi.');
        }

        $sql = (string)file_get_contents($schemaFile);
        $statements = preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [];
        foreach ($statements as $statement) {
            $statement = trim($statement);
            if ($statement !== '') {
                db()->exec($statement);
            }
        }

        db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
        return true;
    }
}

try {
    if (!is_file(__DIR__ . '/config.php')) {
        throw new RuntimeException('panel/config.php dosyasi bulunamadi. Once config.example.php dosyasini config.php olarak kopyalayip veritabani bilgilerini doldurun.');
    }

    $schemaInstalled = setup_ensure_schema();
    $count = (int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
} catch (Throwable $e) {
    http_response_code(500);
    exit('Kurulum hazir degil: ' . e($e->getMessage()));
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
  <link rel="stylesheet" href="<?= e(panel_asset_url('assets/panel.css')) ?>">
</head>
<body class="login-page">
  <main class="login-shell">
    <form class="login-card" method="post" action="<?= e(panel_url('setup.php')) ?>">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <div class="brand">DRN</div>
      <h1>Ilk Kurulum</h1>
      <?php if ($schemaInstalled): ?>
        <div class="success">Veritabani tablolari otomatik olusturuldu.</div>
      <?php endif; ?>
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
