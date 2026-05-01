<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/importer.php';
require_once __DIR__ . '/includes/options.php';
require_login();

$result = null;
$error = '';
$lastLog = db()->query('SELECT * FROM import_logs ORDER BY created_at DESC LIMIT 1')->fetch();

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
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title>Excel Yukle - <?= e(panel_config('app_name')) ?></title>
  <?php render_panel_head_assets(); ?>
</head>
<body>
  <header class="topbar">
    <div class="topbar-brand">
      <div class="brand-logo">DRN</div>
      <span class="brand-name">Excel Yukle</span>
    </div>
    <nav>
      <?php render_current_user_badge(); ?>
      <a href="<?= e(panel_url('index.php')) ?>">Panele don</a>
      <a href="<?= e(panel_url('logout.php')) ?>">Cikis</a>
    </nav>
  </header>

  <main class="layout narrow">
    <div class="form-card">
      <div class="form-card-header">
        <div>
          <div class="kicker">Senkronizasyon</div>
          <h2>Hasar Excel dosyasi</h2>
          <p>Yalnizca .xlsx formati desteklenir</p>
        </div>
      </div>
      <div class="form-card-body">
        <form method="post" enctype="multipart/form-data" style="display:grid;gap:14px">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <label style="display:grid;gap:6px;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:var(--muted)">
            .xlsx dosyasi
            <input type="file" name="excel" accept=".xlsx" required>
          </label>
          <div>
            <button type="submit">Yukle ve aktar</button>
          </div>
        </form>

        <?php if ($error !== ''): ?>
          <div class="alert" style="margin-top:16px"><?= e($error) ?></div>
        <?php endif; ?>

        <?php if (is_array($result)): ?>
          <div class="<?= $result['status'] === 'failed' ? 'alert' : 'success' ?>" style="margin-top:16px">
            Durum: <?= e($result['status']) ?>, aktarilan: <?= e($result['imported']) ?>, atlanan: <?= e($result['skipped']) ?>.
          </div>
          <?php if (!empty($result['errors'])): ?>
            <pre class="error-list"><?= e(implode("\n", array_slice($result['errors'], 0, 20))) ?></pre>
          <?php endif; ?>
        <?php endif; ?>

        <?php if ($lastLog): ?>
          <div class="log-note">
            Son log: <?= e(format_tr_datetime($lastLog['created_at'] ?? null)) ?>,
            durum <?= e($lastLog['status']) ?>,
            aktarilan <?= e($lastLog['imported_count']) ?>,
            atlanan <?= e($lastLog['skipped_count']) ?>.
            <?php if (!empty($lastLog['error_summary'])): ?>
              <pre class="error-list"><?= e($lastLog['error_summary']) ?></pre>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </main>
</body>
</html>
