<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_login();

$pdo = db();
$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM deger_kaybi_records WHERE id = ?');
$stmt->execute([$id]);
$dk = $stmt->fetch();
if (!$dk) {
    http_response_code(404);
    exit('Deger kaybi kaydi bulunamadi.');
}

$tone = function_exists('dk_durum_tone') ? dk_durum_tone((string)($dk['durum'] ?? '')) : 'normal';
$money = function_exists('dk_money') ? 'dk_money' : function ($v) { return $v === null ? '-' : number_format((float)$v, 2, ',', '.') . ' TL'; };
$dateFmt = function_exists('format_tr_date') ? 'format_tr_date' : function ($v) { return $v ? date('d.m.Y', strtotime($v)) : '-'; };
?>
<!DOCTYPE html>
<html lang="tr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>DK Detay — <?= e($dk['plaka']) ?></title>
  <?php render_panel_head_assets(); ?>
  <link rel="stylesheet" href="../css/widgets.css?v=4">
</head>
<body>
  <header class="topbar">
    <div class="topbar-brand">
      <div class="brand-logo">DRN</div>
      <span class="brand-name">Deger Kaybi Detay</span>
    </div>
    <nav>
      <?php render_current_user_badge(); ?>
      <a href="<?= e(panel_url('index.php')) ?>">&larr; Geri</a>
      <a href="<?= e(panel_url('dk_edit.php?id=' . (int)$dk['id'])) ?>" class="nav-cta">Duzenle</a>
    </nav>
  </header>

  <main class="layout">
    <section class="table-card" style="padding:24px">
      <div class="table-head" style="margin-bottom:18px">
        <h2><?= e($dk['plaka']) ?> &middot; <?= e($dk['adi_soyadi']) ?></h2>
        <span class="pill dk-pill-<?= e($tone) ?>"><?= e($dk['durum'] ?: 'Belirsiz') ?></span>
      </div>

      <div class="dk-detail-grid">
        <?php
        $rows = [
          ['Plaka',          $dk['plaka']],
          ['Adi Soyadi',     $dk['adi_soyadi']],
          ['Telefon',        $dk['tel'] ?: '-'],
          ['Sigorta',        $dk['sigorta'] ?: '-'],
          ['Hasar Tarihi',   $dateFmt($dk['hasar_tarihi'])],
          ['Police No',      $dk['police_no'] ?: '-'],
          ['Dosya No',       $dk['dosya_no'] ?: '-'],
          ['Fatura Tarihi',  $dateFmt($dk['fatura_tarihi'])],
          ['Eksper',         $dk['eksper'] ?: '-'],
          ['Teminat',        $money(isset($dk['teminat']) ? $dk['teminat'] : null)],
          ['Fatura Tutari',  $money(isset($dk['fatura_tutari']) ? $dk['fatura_tutari'] : null)],
          ['Yatma/Hak Mahrum', $money(isset($dk['yatma_parasi']) ? $dk['yatma_parasi'] : null)],
          ['Takip',          $dk['takip'] ?: '-'],
          ['Durum',          $dk['durum'] ?: '-'],
          ['Acente',         $dk['acente'] ?: '-'],
        ];
        foreach ($rows as [$label, $val]): ?>
          <div class="dk-detail-row">
            <div class="dk-detail-label"><?= e($label) ?></div>
            <div class="dk-detail-value"><?= e($val) ?></div>
          </div>
        <?php endforeach; ?>
      </div>

      <?php if (!empty($dk['aciklama'])): ?>
        <div style="margin-top:18px">
          <div class="dk-detail-label" style="margin-bottom:6px">Aciklama</div>
          <div style="padding:12px 16px;background:#fafafd;border:1px solid #efeff3;border-radius:8px;white-space:pre-wrap;font-size:13px;color:#333"><?= e($dk['aciklama']) ?></div>
        </div>
      <?php endif; ?>

      <div style="margin-top:24px;padding-top:16px;border-top:1px solid #efeff3;color:#888;font-size:12px;display:flex;gap:24px">
        <div>Olusturuldu: <strong><?= e($dateFmt($dk['created_at'])) ?></strong></div>
        <div>Guncellendi: <strong><?= e($dateFmt($dk['updated_at'])) ?></strong></div>
      </div>
    </section>
  </main>

  <style>
    .dk-detail-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(260px,1fr)); gap:12px; }
    .dk-detail-row { padding:12px 14px; background:#fafafd; border:1px solid #efeff3; border-radius:8px; }
    .dk-detail-label { font-size:11px; font-weight:600; color:#888; text-transform:uppercase; letter-spacing:.6px; margin-bottom:4px; }
    .dk-detail-value { font-size:14px; color:#1a1a2e; font-weight:500; word-break:break-word; }
  </style>
</body>
</html>
