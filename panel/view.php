<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/attachments.php';
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

$activePage = 'panel';
$pageTitle = 'Detay ' . $record['plate'];
include __DIR__ . '/includes/_layout_head.php';
?>
  <main class="content">
    <header class="page-head">
      <div class="title-block">
        <div class="eyebrow">Arac Detayi</div>
        <h1><?= e($record['plate']) ?> <span style="color:var(--muted);font-weight:600">&middot;</span> <span style="color:var(--text-2);font-weight:700"><?= e($record['customer_name']) ?></span></h1>
        <p class="sub">Kayit no: <?= e($record['record_no']) ?></p>
      </div>
      <div class="actions">
        <a class="btn-ghost" href="<?= e(panel_url('index.php')) ?>">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
          Panele don
        </a>
        <button type="button" onclick="window.print()" class="btn-ghost">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
          Yazdir
        </button>
        <a class="btn" href="<?= e(panel_url('edit.php?id=' . $id)) ?>">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
          Duzenle
        </a>
      </div>
    </header>

    <section class="card" style="overflow:hidden;margin-bottom:18px">
      <div class="card-head">
        <h2>Arac &amp; Police Bilgileri</h2>
        <span class="sub">Kayit guncellenme: <?= e($record['updated_at']) ?></span>
      </div>
      <dl class="detail-grid">
        <div><div class="dt">Sigorta</div><div class="dd"><?= e($record['insurance_company'] ?: '-') ?></div></div>
        <div><div class="dt">Tamir Durumu</div><div class="dd"><?= e($record['repair_status'] ?: '-') ?></div></div>
        <div><div class="dt">Mini Onarim</div><div class="dd"><?= ((int)$record['mini_repair_has'] === 1) ? e($record['mini_repair_part'] ?: 'Var') : 'Yok' ?></div></div>
        <div><div class="dt">Servis Giris</div><div class="dd"><?= e($record['service_entry_date'] ?: '-') ?></div></div>
        <div><div class="dt">Servis Cikis</div><div class="dd"><?= e($record['service_exit_date'] ?: '-') ?></div></div>
        <div><div class="dt">Servis Ay</div><div class="dd"><?= e($record['service_month'] ?: '-') ?></div></div>
        <div><div class="dt">Police Baslangic</div><div class="dd"><?= e($record['policy_start_date'] ?: '-') ?></div></div>
        <div>
          <div class="dt">Police Bitis</div>
          <div class="dd">
            <?= e($record['policy_end_date'] ?: '-') ?>
            <?php if ($daysToPolicyEnd !== null): ?>
              <?php if ($daysToPolicyEnd < 0): ?>
                <span class="status-pill is-danger" style="margin-left:6px"><?= e(abs($daysToPolicyEnd)) ?> gun gecti</span>
              <?php elseif ($daysToPolicyEnd <= 30): ?>
                <span class="status-pill is-warn" style="margin-left:6px"><?= e($daysToPolicyEnd) ?> gun kaldi</span>
              <?php else: ?>
                <span class="status-pill is-ok" style="margin-left:6px"><?= e($daysToPolicyEnd) ?> gun</span>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        </div>
        <div><div class="dt">Olusturuldu</div><div class="dd"><?= e($record['created_at']) ?></div></div>
      </dl>
    </section>

    <section class="card" style="overflow:hidden">
      <div class="card-head">
        <div>
          <h2>Belgeler &amp; Hasar / Eksper Gorselleri</h2>
          <div class="sub"><?= count($attachments) ?> dosya</div>
        </div>
        <?php if ($attachmentsAvailable && count($attachments) > 0): ?>
          <a href="<?= e(panel_url('download_all.php?id=' . $id . ($categoryFilter !== '' ? '&cat=' . $categoryFilter : ''))) ?>" class="btn-soft">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            Tumunu ZIP indir
          </a>
        <?php endif; ?>
      </div>

      <div class="card-pad">
        <?php if (!$attachmentsAvailable): ?>
          <div class="banner is-warn">
            <span class="ico"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg></span>
            <div>
              <h3>Belge tablosu hazir degil</h3>
              <p>Once <a href="<?= e(panel_url('install/migrate.php')) ?>">Migration</a> sayfasini calistirin.</p>
            </div>
          </div>
        <?php else: ?>
          <?php if ($flashError !== ''): ?>
            <div class="banner is-danger">
              <span class="ico"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg></span>
              <div><h3>Yukleme hatasi</h3><p><?= e($flashError) ?></p></div>
            </div>
          <?php endif; ?>

          <form method="post" action="<?= e(panel_url('upload_attachment.php')) ?>" enctype="multipart/form-data" style="display:grid;grid-template-columns:200px 1fr auto;gap:10px;align-items:center;padding:14px;border:1px dashed #cbd5e1;border-radius:var(--radius);background:var(--surface-2);margin-bottom:18px">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="record_id" value="<?= (int)$id ?>">
            <select name="category">
              <?php foreach ($categories as $key => $label): ?>
                <option value="<?= e($key) ?>"><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
            <input type="file" name="file" required>
            <button type="submit">Yukle</button>
          </form>

          <div class="chip-row" style="margin-bottom:16px">
            <a class="chip <?= $categoryFilter === '' ? 'active' : '' ?>" href="<?= e(panel_url('view.php?id=' . $id)) ?>">Tumu</a>
            <?php foreach ($categories as $key => $label): ?>
              <a class="chip <?= $categoryFilter === $key ? 'active' : '' ?>" href="<?= e(panel_url('view.php?id=' . $id . '&cat=' . $key)) ?>"><?= e($label) ?></a>
            <?php endforeach; ?>
          </div>

          <?php if ($attachments === []): ?>
            <p class="empty">Bu kategori icin dosya yok.</p>
          <?php else: ?>
            <div class="gallery">
              <?php foreach ($attachments as $att):
                $isImage = attachment_is_image($att['mime_type']);
                $canPreview = attachment_can_preview($att['mime_type']);
                $previewUrl = panel_url('download_attachment.php?id=' . (int)$att['id'] . '&inline=1');
              ?>
                <div class="g-card">
                  <div class="g-thumb">
                    <?php if ($isImage): ?>
                      <img src="<?= e($previewUrl) ?>" alt="<?= e($att['original_name']) ?>">
                    <?php else: ?>
                      <svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="color:#94a3b8"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="15" y2="17"/></svg>
                    <?php endif; ?>
                  </div>
                  <div class="g-body">
                    <div style="display:flex;align-items:center;justify-content:space-between;gap:8px">
                      <span class="status-pill is-muted"><?= e(attachment_category_label($att['category'])) ?></span>
                      <span style="font-size:11px;color:var(--muted)"><?= e(attachment_format_size((int)$att['file_size'])) ?></span>
                    </div>
                    <p style="margin:8px 0 2px;font-size:13.5px;font-weight:700;color:var(--text);overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= e($att['original_name']) ?>"><?= e($att['original_name']) ?></p>
                    <p style="margin:0;font-size:11px;color:var(--muted)"><?= e($att['uploaded_at']) ?><?= !empty($att['uploaded_by_name']) ? ' &middot; ' . e($att['uploaded_by_name']) : '' ?></p>
                    <div style="margin-top:10px;display:flex;align-items:center;justify-content:space-between;gap:8px">
                      <div style="display:flex;gap:12px">
                        <?php if ($canPreview): ?>
                          <a style="font-size:12.5px;font-weight:700;color:var(--text-2)" href="<?= e($previewUrl) ?>" target="_blank" rel="noopener">Goruntule</a>
                        <?php endif; ?>
                        <a style="font-size:12.5px;font-weight:700" href="<?= e(panel_url('download_attachment.php?id=' . (int)$att['id'])) ?>">Indir</a>
                      </div>
                      <form method="post" action="<?= e(panel_url('delete_attachment.php')) ?>" onsubmit="return confirm('Dosyayi sil?');" style="margin:0">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="id" value="<?= (int)$att['id'] ?>">
                        <input type="hidden" name="record_id" value="<?= (int)$id ?>">
                        <button class="btn-xs" type="submit" style="background:transparent;border:1px solid var(--danger-border);color:var(--danger);box-shadow:none;font-weight:700">Sil</button>
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
<?php include __DIR__ . '/includes/_layout_foot.php'; ?>
