<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/importer.php';
require_login();

$result = null;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (empty($_FILES['excel']) || !is_uploaded_file($_FILES['excel']['tmp_name'])) {
        $error = 'Lutfen .xlsx dosyasi secin.';
    } else {
        $file = $_FILES['excel'];
        $name = (string)($file['name'] ?? 'excel.xlsx');
        $lowerName = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            $error = 'Dosya yukleme hatasi: ' . (int)$file['error'];
        } elseif (!str_ends_with($lowerName, '.xlsx')) {
            $error = 'Sadece .xlsx dosyalari desteklenir.';
        } else {
            try {
                $result = import_excel_file((string)$file['tmp_name'], $name);
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }
    }
}
?>
<!doctype html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Excel Yukle - <?= e(panel_config('app_name')) ?></title>
  <link rel="stylesheet" href="<?= e(panel_url('assets/panel.css')) ?>">
</head>
<body>
  <header class="topbar">
    <div>
      <div class="eyebrow">DRN</div>
      <h1>Excel Yukle</h1>
    </div>
    <nav>
      <a href="<?= e(panel_url('index.php')) ?>">Panele don</a>
      <a href="<?= e(panel_url('logout.php')) ?>">Cikis</a>
    </nav>
  </header>

  <main class="layout narrow">
    <section class="table-card form-card">
      <h2>Hasar Excel dosyasi</h2>
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <label>
          .xlsx dosyasi
          <input type="file" name="excel" accept=".xlsx" required>
        </label>
        <button type="submit">Yukle ve aktar</button>
      </form>

      <?php if ($error !== ''): ?>
        <div class="alert"><?= e($error) ?></div>
      <?php endif; ?>

      <?php if (is_array($result)): ?>
        <div class="<?= $result['status'] === 'failed' ? 'alert' : 'success' ?>">
          Durum: <?= e($result['status']) ?>,
          aktarilan: <?= e($result['imported']) ?>,
          atlanan: <?= e($result['skipped']) ?>.
        </div>
        <?php if (!empty($result['errors'])): ?>
          <pre class="error-list"><?= e(implode("\n", array_slice($result['errors'], 0, 20))) ?></pre>
        <?php endif; ?>
      <?php endif; ?>
    </section>
  </main>
</body>
</html>
