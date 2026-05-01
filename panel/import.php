<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/importer.php';
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

$activePage = 'raporlar';
$pageTitle = 'Excel Yukle';
include __DIR__ . '/includes/_layout_head.php';
?>
  <main class="content">
    <header class="page-head">
      <div class="title-block">
        <div class="eyebrow">Veri Aktarimi</div>
        <h1>Excel Yukle &amp; Raporlar</h1>
        <p class="sub">Hasar Excel dosyasini yukleyerek toplu kayit aktarimi yapin.</p>
      </div>
      <div class="actions">
        <a class="btn-ghost" href="<?= e(panel_url('index.php')) ?>">Panele don</a>
      </div>
    </header>

    <section class="card" style="overflow:hidden;max-width:780px">
      <div class="card-head">
        <h2>Hasar Excel Dosyasi (.xlsx)</h2>
      </div>
      <div class="card-pad-lg">
        <form method="post" enctype="multipart/form-data" style="display:grid;gap:14px">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <label class="field">
            <span class="label">.xlsx dosyasi sec</span>
            <input type="file" name="excel" accept=".xlsx" required>
          </label>
          <div>
            <button type="submit">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
              Yukle ve aktar
            </button>
          </div>
        </form>

        <?php if ($error !== ''): ?>
          <div class="banner is-danger" style="margin-top:18px">
            <span class="ico"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg></span>
            <div><h3>Hata</h3><p><?= e($error) ?></p></div>
          </div>
        <?php endif; ?>

        <?php if (is_array($result)): ?>
          <div class="banner <?= $result['status'] === 'failed' ? 'is-danger' : 'is-ok' ?>" style="margin-top:18px">
            <span class="ico">
              <?php if ($result['status'] === 'failed'): ?>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
              <?php else: ?>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
              <?php endif; ?>
            </span>
            <div>
              <h3>Durum: <?= e($result['status']) ?></h3>
              <p>Aktarilan: <strong><?= e($result['imported']) ?></strong> &nbsp;&middot;&nbsp; Atlanan: <strong><?= e($result['skipped']) ?></strong></p>
            </div>
          </div>
          <?php if (!empty($result['errors'])): ?>
            <pre class="error-list" style="margin-top:12px"><?= e(implode("\n", array_slice($result['errors'], 0, 20))) ?></pre>
          <?php endif; ?>
        <?php endif; ?>

        <?php if ($lastLog): ?>
          <div style="margin-top:24px;padding-top:18px;border-top:1px solid var(--border)">
            <h3 style="margin:0 0 10px;font-size:13px;font-weight:800;color:var(--text-2);text-transform:uppercase;letter-spacing:.08em">Son Aktarim Loglari</h3>
            <div style="display:grid;gap:6px;font-size:13px;color:var(--text-2)">
              <div><span style="color:var(--muted)">Zaman:</span> <strong style="color:var(--text)"><?= e($lastLog['created_at']) ?></strong></div>
              <div><span style="color:var(--muted)">Durum:</span> <span class="status-pill <?= ($lastLog['status'] === 'failed') ? 'is-danger' : 'is-ok' ?>"><?= e($lastLog['status']) ?></span></div>
              <div><span style="color:var(--muted)">Aktarilan:</span> <strong style="color:var(--text)"><?= e($lastLog['imported_count']) ?></strong> &nbsp;&middot;&nbsp; <span style="color:var(--muted)">Atlanan:</span> <strong style="color:var(--text)"><?= e($lastLog['skipped_count']) ?></strong></div>
            </div>
            <?php if (!empty($lastLog['error_summary'])): ?>
              <pre class="error-list" style="margin-top:12px"><?= e($lastLog['error_summary']) ?></pre>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
    </section>
  </main>
<?php include __DIR__ . '/includes/_layout_foot.php'; ?>
