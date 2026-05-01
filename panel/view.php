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

$returnCount = (int)($record['return_count'] ?? 1);
$lastReturnAt = $record['last_return_at'] ?? null;
$isReturning = $returnCount > 1;

$today = new DateTimeImmutable('today');
$policyEnd = !empty($record['policy_end_date']) ? new DateTimeImmutable($record['policy_end_date']) : null;
$daysToPolicyEnd = $policyEnd ? (int)$today->diff($policyEnd)->format('%r%a') : null;

$categoryFilter = trim((string)($_GET['cat'] ?? ''));
if ($categoryFilter !== '' && !attachment_category_valid($categoryFilter)) {
    $categoryFilter = '';
}

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
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title>Detay <?= e($record['plate']) ?> - <?= e(panel_config('app_name')) ?></title>
  <?php render_panel_head_assets(); ?>
</head>
<body>
  <header class="topbar">
    <div class="topbar-brand">
      <div class="brand-logo">DRN</div>
      <span class="brand-name">Arac Detayi</span>
    </div>
    <nav>
      <?php render_current_user_badge(); ?>
      <a href="<?= e(panel_url('index.php')) ?>">Panele don</a>
      <a href="<?= e(panel_url('logout.php')) ?>">Cikis</a>
    </nav>
  </header>

  <main class="layout narrow">
    <div class="detail-card">
      <div class="detail-hero">
        <div>
          <div class="kicker">Detayli goruntuleme</div>
          <h2>
            <?= e($record['plate']) ?> &mdash; <?= e($record['customer_name']) ?>
            <?php if ($isReturning): ?>
              <span class="pill status-yellow" style="margin-left:8px;vertical-align:middle" title="Bu arac daha once de servise gelmis"><?= e($returnCount) ?>. ziyaret</span>
            <?php endif; ?>
          </h2>
          <p style="font-size:12px;color:var(--muted);margin-top:3px;font-family:'IBM Plex Mono',monospace">Kayit no: <?= e($record['record_no']) ?></p>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
          <a class="btn-primary" href="<?= e(panel_url('edit.php?id=' . $id)) ?>">Duzenle</a>
          <button type="button" onclick="window.print()" class="btn-secondary">Yazdir</button>
        </div>
      </div>
      <dl class="detail-grid">
        <div class="detail-item"><dt>Arac Filtresi</dt><dd><span class="type-badge"><?= e(insurance_type_label($record['insurance_type'] ?? 'kasko')) ?></span></dd></div>
        <div class="detail-item"><dt>Sigorta</dt><dd><?= e($record['insurance_company'] ?: '-') ?></dd></div>
        <div class="detail-item"><dt>Tamir Durumu</dt><dd><span class="pill <?= e(repair_status_tone((string)$record['repair_status'])) ?>"><?= e($record['repair_status']) ?></span></dd></div>
        <div class="detail-item"><dt>Mini Onarim</dt><dd><?= ((int)$record['mini_repair_has'] === 1) ? e($record['mini_repair_part'] ?: 'Var') : 'Yok' ?></dd></div>
        <div class="detail-item"><dt>Servis Giris</dt><dd><?= e(format_tr_date($record['service_entry_date'])) ?></dd></div>
        <div class="detail-item"><dt>Servis Cikis</dt><dd><?= e(format_tr_date($record['service_exit_date'] ?? null)) ?></dd></div>
        <div class="detail-item"><dt>Servis Ay</dt><dd><?= e($record['service_month']) ?></dd></div>
        <div class="detail-item"><dt>Police Baslangic</dt><dd><?= e(format_tr_date($record['policy_start_date'] ?? null)) ?></dd></div>
        <div class="detail-item">
          <dt>Police Bitis</dt>
          <dd>
            <?= e(format_tr_date($record['policy_end_date'] ?? null)) ?>
            <?php if ($daysToPolicyEnd !== null): ?>
              <?php if ($daysToPolicyEnd < 0): ?>
                <span class="pill status-red" style="margin-left:6px"><?= e(abs($daysToPolicyEnd)) ?> gun gecti</span>
              <?php elseif ($daysToPolicyEnd <= 30): ?>
                <span class="pill status-yellow" style="margin-left:6px"><?= e($daysToPolicyEnd) ?> gun kaldi</span>
              <?php else: ?>
                <span class="pill status-green" style="margin-left:6px"><?= e($daysToPolicyEnd) ?> gun kaldi</span>
              <?php endif; ?>
            <?php endif; ?>
          </dd>
        </div>
        <div class="detail-item">
          <dt>Tekrar Gelis</dt>
          <dd>
            <?php if ($isReturning): ?>
              <span class="pill status-yellow"><?= e($returnCount) ?> kez gelmis</span>
              <?php if ($lastReturnAt): ?>
                <span style="margin-left:6px;font-size:12px;color:var(--muted)">Son: <?= e(format_tr_datetime($lastReturnAt)) ?></span>
              <?php endif; ?>
            <?php else: ?>
              <span class="pill status-green">Ilk gelis</span>
            <?php endif; ?>
          </dd>
        </div>
        <div class="detail-item"><dt>Olusturuldu</dt><dd><?= e(format_tr_datetime($record['created_at'] ?? null)) ?></dd></div>
        <div class="detail-item"><dt>Guncellendi</dt><dd><?= e(format_tr_datetime($record['updated_at'] ?? null)) ?></dd></div>
      </dl>
    </div>

    <div class="detail-card">
      <div class="detail-section-head">
        <h3>Belgeler &amp; Fotograflar</h3>
        <div style="display:flex;align-items:center;gap:10px">
          <span style="font-size:12px;color:var(--muted)"><?= count($attachments) ?> dosya</span>
          <?php if ($attachmentsAvailable && count($attachments) > 0): ?>
            <a class="btn-soft" href="<?= e(panel_url('download_all.php?id=' . $id . ($categoryFilter !== '' ? '&cat=' . $categoryFilter : ''))) ?>">Tumunu ZIP indir</a>
          <?php endif; ?>
        </div>
      </div>
      <div style="padding:16px 20px">
        <?php if (!$attachmentsAvailable): ?>
          <div class="alert">Belge tablosu henuz olusturulmamis. Once <a href="<?= e(panel_url('install/migrate.php')) ?>">Migration</a> sayfasini calistirin.</div>
        <?php else: ?>
          <?php if ($flashError !== ''): ?>
            <div class="alert"><?= e($flashError) ?></div>
          <?php endif; ?>

          <form method="post" action="<?= e(panel_url('upload_attachment.php')) ?>" enctype="multipart/form-data"
            class="upload-panel" style="display:grid;grid-template-columns:180px 1fr auto;gap:8px;align-items:center;margin-bottom:14px">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="record_id" value="<?= (int)$id ?>">
            <select name="category" style="height:36px;font-size:13px">
              <?php foreach ($categories as $key => $label): ?>
                <option value="<?= e($key) ?>"><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
            <input type="file" name="file" required style="height:36px;font-size:13px">
            <button type="submit" style="height:36px;font-size:13px">Yukle</button>
          </form>

          <div class="category-tabs">
            <a href="<?= e(panel_url('view.php?id=' . $id)) ?>" class="<?= $categoryFilter === '' ? 'active' : '' ?>">Tumu</a>
            <?php foreach ($categories as $key => $label): ?>
              <a href="<?= e(panel_url('view.php?id=' . $id . '&cat=' . $key)) ?>" class="<?= $categoryFilter === $key ? 'active' : '' ?>"><?= e($label) ?></a>
            <?php endforeach; ?>
          </div>

          <?php if ($attachments === []): ?>
            <p style="font-size:13px;color:var(--muted)">Bu kategori icin dosya yok.</p>
          <?php else: ?>
            <div class="attachment-grid" style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px">
              <?php foreach ($attachments as $att):
                $isImage = attachment_is_image($att['mime_type']);
                $hasFastThumb = $isImage && attachment_thumbnail_supported((string)$att['mime_type']);
                $canPreview = attachment_can_preview($att['mime_type']);
                $previewUrl = panel_url('download_attachment.php?id=' . (int)$att['id'] . '&inline=1');
                $thumbUrl = $hasFastThumb ? panel_url('download_attachment.php?id=' . (int)$att['id'] . '&inline=1&thumb=1') : $previewUrl;
              ?>
                <div class="attachment-card">
                  <div style="aspect-ratio:4/3;background:var(--surface-2);display:flex;align-items:center;justify-content:center;overflow:hidden">
                    <?php if ($hasFastThumb): ?>
                      <img src="<?= e($thumbUrl) ?>" alt="<?= e($att['original_name']) ?>" loading="lazy" decoding="async" style="width:100%;height:100%;object-fit:cover">
                    <?php else: ?>
                      <svg xmlns="http://www.w3.org/2000/svg" style="width:48px;height:48px;color:var(--muted-2)" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h7l5 5v11a2 2 0 01-2 2z"/></svg>
                    <?php endif; ?>
                  </div>
                  <div style="padding:10px 12px">
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px">
                      <span class="type-badge"><?= e(attachment_category_label($att['category'])) ?></span>
                      <span style="font-size:11px;color:var(--muted)"><?= e(attachment_format_size((int)$att['file_size'])) ?></span>
                    </div>
                    <p style="font-size:13px;font-weight:600;margin-bottom:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= e($att['original_name']) ?>"><?= e($att['original_name']) ?></p>
                    <p style="font-size:11px;color:var(--muted)"><?= e(format_tr_datetime($att['uploaded_at'] ?? null)) ?><?= !empty($att['uploaded_by_name']) ? ' &mdash; ' . e($att['uploaded_by_name']) : '' ?></p>
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-top:10px">
                      <div style="display:flex;gap:12px">
                        <?php if ($canPreview): ?>
                          <a style="font-size:12px;font-weight:600;color:var(--text-2)" href="<?= e($previewUrl) ?>" target="_blank" rel="noopener">Goruntule</a>
                        <?php endif; ?>
                        <a style="font-size:12px;font-weight:600;color:var(--accent-dk)" href="<?= e(panel_url('download_attachment.php?id=' . (int)$att['id'])) ?>">Indir</a>
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
    </div>
  </main>
</body>
</html>
