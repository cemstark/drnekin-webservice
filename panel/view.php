<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/attachments.php';
require_once __DIR__ . '/includes/options.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM service_records WHERE id = ?');
$stmt->execute([$id]);
$record = $stmt->fetch();
if (!$record) {
    http_response_code(404);
    exit('Kayit bulunamadi.');
}

$today = new DateTimeImmutable('today');
$policyEnd = !empty($record['policy_end_date']) ? new DateTimeImmutable($record['policy_end_date']) : null;
$daysToPolicyEnd = $policyEnd ? (int)$today->diff($policyEnd)->format('%r%a') : null;

$categoryFilter = trim((string)($_GET['cat'] ?? ''));
if ($categoryFilter !== '' && !attachment_category_valid($categoryFilter)) {
    $categoryFilter = '';
}

// service_attachments tablosu yoksa (migration calistirilmadi) bos liste don
$attachments = [];
$attachmentsAvailable = true;
try {
    $attachments = attachment_fetch_for_record($id, $categoryFilter !== '' ? $categoryFilter : null);
} catch (Throwable $e) {
    $attachmentsAvailable = false;
}
$categories = attachment_categories();
$flashError = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_error']);
?>
<!doctype html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Detay <?= e($record['plate']) ?> - <?= e(panel_config('app_name')) ?></title>
  <link rel="stylesheet" href="<?= e(panel_url('assets/panel.css')) ?>">
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body>
  <header class="topbar">
    <div>
      <div class="eyebrow">DRN</div>
      <h1>Arac Detayi</h1>
    </div>
    <nav>
      <?php render_current_user_badge(); ?>
      <a href="<?= e(panel_url('index.php')) ?>">Panele don</a>
      <a href="<?= e(panel_url('logout.php')) ?>">Cikis</a>
    </nav>
  </header>

  <main class="detail-shell mx-auto w-full max-w-5xl px-4 py-8 sm:px-6 lg:px-8">
    <section class="detail-card overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
      <div class="detail-hero border-b border-slate-200 bg-slate-50 px-6 py-5 flex flex-wrap items-center justify-between gap-3">
        <div>
          <p class="section-kicker text-xs font-semibold uppercase tracking-wide text-blue-700">Detayli goruntuleme</p>
          <h2 class="mt-1 text-xl font-bold text-slate-950"><?= e($record['plate']) ?> &mdash; <?= e($record['customer_name']) ?></h2>
          <p class="mt-1 text-xs text-slate-500">Kayit no: <?= e($record['record_no']) ?></p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
          <a class="btn-primary inline-flex h-10 items-center justify-center rounded-lg bg-blue-600 px-4 text-sm font-semibold text-white hover:bg-blue-700" href="<?= e(panel_url('edit.php?id=' . $id)) ?>">Duzenle</a>
          <button type="button" onclick="window.print()" class="btn-secondary inline-flex h-10 items-center justify-center rounded-lg border border-slate-200 bg-white px-4 text-sm font-semibold text-slate-700 hover:bg-slate-50">Yazdir</button>
        </div>
      </div>
      <dl class="detail-grid grid gap-4 px-6 py-6 sm:grid-cols-2 lg:grid-cols-3 text-sm">
        <div class="detail-item"><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Arac Filtresi</dt><dd class="mt-1 text-slate-950"><span class="type-badge"><?= e(insurance_type_label($record['insurance_type'] ?? 'kasko')) ?></span></dd></div>
        <div class="detail-item"><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Sigorta</dt><dd class="mt-1 text-slate-950"><?= e($record['insurance_company'] ?: '-') ?></dd></div>
        <div class="detail-item"><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Tamir Durumu</dt><dd class="mt-1 text-slate-950"><span class="pill <?= e(repair_status_tone((string)$record['repair_status'])) ?>"><?= e($record['repair_status']) ?></span></dd></div>
        <div class="detail-item"><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Mini Onarim</dt><dd class="mt-1 text-slate-950"><?= ((int)$record['mini_repair_has'] === 1) ? e($record['mini_repair_part'] ?: 'Var') : 'Yok' ?></dd></div>
        <div class="detail-item"><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Servis Giris</dt><dd class="mt-1 text-slate-950"><?= e(format_tr_date($record['service_entry_date'])) ?></dd></div>
        <div class="detail-item"><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Servis Cikis</dt><dd class="mt-1 text-slate-950"><?= e(format_tr_date($record['service_exit_date'] ?? null)) ?></dd></div>
        <div class="detail-item"><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Servis Ay</dt><dd class="mt-1 text-slate-950"><?= e($record['service_month']) ?></dd></div>
        <div class="detail-item"><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Police Baslangic</dt><dd class="mt-1 text-slate-950"><?= e(format_tr_date($record['policy_start_date'] ?? null)) ?></dd></div>
        <div class="detail-item">
          <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Police Bitis</dt>
          <dd class="mt-1 text-slate-950">
            <?= e(format_tr_date($record['policy_end_date'] ?? null)) ?>
            <?php if ($daysToPolicyEnd !== null): ?>
              <?php if ($daysToPolicyEnd < 0): ?>
                <span class="ml-2 rounded-full bg-red-100 px-2 py-0.5 text-xs font-semibold text-red-700"><?= e(abs($daysToPolicyEnd)) ?> gun gecti</span>
              <?php elseif ($daysToPolicyEnd <= 30): ?>
                <span class="ml-2 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-800"><?= e($daysToPolicyEnd) ?> gun kaldi</span>
              <?php else: ?>
                <span class="ml-2 rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-700"><?= e($daysToPolicyEnd) ?> gun kaldi</span>
              <?php endif; ?>
            <?php endif; ?>
          </dd>
        </div>
        <div class="detail-item"><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Olusturuldu</dt><dd class="mt-1 text-slate-950"><?= e(format_tr_datetime($record['created_at'] ?? null)) ?></dd></div>
        <div class="detail-item"><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Guncellendi</dt><dd class="mt-1 text-slate-950"><?= e(format_tr_datetime($record['updated_at'] ?? null)) ?></dd></div>
      </dl>
    </section>

    <section class="detail-card mt-6 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
      <div class="detail-section-head border-b border-slate-200 bg-slate-50 px-6 py-4 flex flex-wrap items-center justify-between gap-3">
        <h3 class="text-lg font-bold text-slate-950">Belgeler &amp; Fotograflar</h3>
        <div class="flex items-center gap-3">
          <span class="text-xs text-slate-500"><?= count($attachments) ?> dosya</span>
          <?php if ($attachmentsAvailable && count($attachments) > 0): ?>
            <a href="<?= e(panel_url('download_all.php?id=' . $id . ($categoryFilter !== '' ? '&cat=' . $categoryFilter : ''))) ?>" class="btn-soft inline-flex h-9 items-center justify-center rounded-lg border border-blue-200 bg-blue-50 px-3 text-xs font-bold text-blue-700 hover:bg-blue-100">
              Tumunu ZIP indir
            </a>
          <?php endif; ?>
        </div>
      </div>

      <div class="px-6 py-5">
        <?php if (!$attachmentsAvailable): ?>
          <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-800">
            Belge tablosu henuz olusturulmamis. Once <a class="underline" href="<?= e(panel_url('install/migrate.php')) ?>">Migration</a> sayfasini calistirin.
          </div>
        <?php else: ?>
          <?php if ($flashError !== ''): ?>
            <div class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-700"><?= e($flashError) ?></div>
          <?php endif; ?>

          <form method="post" action="<?= e(panel_url('upload_attachment.php')) ?>" enctype="multipart/form-data" class="upload-panel mb-5 grid gap-2 rounded-xl border border-dashed border-slate-300 bg-slate-50 p-4 sm:grid-cols-[180px_1fr_auto] sm:items-center">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="record_id" value="<?= (int)$id ?>">
            <select name="category" class="h-10 rounded-lg border border-slate-200 bg-white px-2 text-sm">
              <?php foreach ($categories as $key => $label): ?>
                <option value="<?= e($key) ?>"><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
            <input type="file" name="file" required class="h-10 rounded-lg border border-slate-200 bg-white px-2 text-sm">
            <button class="btn-primary h-10 rounded-lg bg-blue-600 px-4 text-sm font-bold text-white hover:bg-blue-700" type="submit">Yukle</button>
          </form>

          <div class="category-tabs mb-4 flex flex-wrap gap-2">
            <a href="<?= e(panel_url('view.php?id=' . $id)) ?>" class="<?= $categoryFilter === '' ? 'active' : '' ?> inline-flex items-center gap-2 rounded-full border px-3 py-1.5 text-xs font-semibold">Tumu</a>
            <?php foreach ($categories as $key => $label): ?>
              <a href="<?= e(panel_url('view.php?id=' . $id . '&cat=' . $key)) ?>" class="<?= $categoryFilter === $key ? 'active' : '' ?> inline-flex items-center gap-2 rounded-full border px-3 py-1.5 text-xs font-semibold"><?= e($label) ?></a>
            <?php endforeach; ?>
          </div>

          <?php if ($attachments === []): ?>
            <p class="text-sm text-slate-500">Bu kategori icin dosya yok.</p>
          <?php else: ?>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
              <?php foreach ($attachments as $att):
                $isImage = attachment_is_image($att['mime_type']);
                $canPreview = attachment_can_preview($att['mime_type']);
                $previewUrl = panel_url('download_attachment.php?id=' . (int)$att['id'] . '&inline=1');
              ?>
                <div class="attachment-card overflow-hidden rounded-xl border border-slate-200 bg-white">
                  <div class="aspect-[4/3] bg-slate-100 flex items-center justify-center overflow-hidden">
                    <?php if ($isImage): ?>
                      <img src="<?= e($previewUrl) ?>" alt="<?= e($att['original_name']) ?>" class="h-full w-full object-cover">
                    <?php else: ?>
                      <svg xmlns="http://www.w3.org/2000/svg" class="h-12 w-12 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h7l5 5v11a2 2 0 01-2 2z"/></svg>
                    <?php endif; ?>
                  </div>
                  <div class="p-3">
                    <div class="flex items-center justify-between gap-2">
                      <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-700"><?= e(attachment_category_label($att['category'])) ?></span>
                      <span class="text-xs text-slate-500"><?= e(attachment_format_size((int)$att['file_size'])) ?></span>
                    </div>
                    <p class="mt-2 truncate text-sm font-semibold text-slate-900" title="<?= e($att['original_name']) ?>"><?= e($att['original_name']) ?></p>
                    <p class="text-xs text-slate-500"><?= e(format_tr_datetime($att['uploaded_at'] ?? null)) ?><?= !empty($att['uploaded_by_name']) ? ' &mdash; ' . e($att['uploaded_by_name']) : '' ?></p>
                    <div class="mt-3 flex items-center justify-between gap-2">
                      <div class="flex items-center gap-3">
                        <?php if ($canPreview): ?>
                          <a class="text-sm font-semibold text-slate-700 hover:underline" href="<?= e($previewUrl) ?>" target="_blank" rel="noopener">Goruntule</a>
                        <?php endif; ?>
                        <a class="text-sm font-semibold text-blue-700 hover:underline" href="<?= e(panel_url('download_attachment.php?id=' . (int)$att['id'])) ?>">Indir</a>
                      </div>
                      <form method="post" action="<?= e(panel_url('delete_attachment.php')) ?>" onsubmit="return confirm('Dosyayi sil?');">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="id" value="<?= (int)$att['id'] ?>">
                        <input type="hidden" name="record_id" value="<?= (int)$id ?>">
                        <button class="link-danger" type="submit">Sil</button>
                      </form>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </section>
  </main>
</body>
</html>
