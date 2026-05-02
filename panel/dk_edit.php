<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_login();

$pdo = db();
$id = (int)($_GET['id'] ?? 0);
$isNew = $id <= 0;

if ($isNew) {
    $dk = [
        'id' => 0,
        'plaka' => '', 'adi_soyadi' => '', 'tel' => '', 'sigorta' => '',
        'hasar_tarihi' => null, 'police_no' => '', 'dosya_no' => '',
        'fatura_tarihi' => null, 'eksper' => '',
        'teminat' => null, 'fatura_tutari' => null, 'yatma_parasi' => null,
        'takip' => '', 'durum' => '', 'acente' => '', 'aciklama' => ''
    ];
} else {
    $stmt = $pdo->prepare('SELECT * FROM deger_kaybi_records WHERE id = ?');
    $stmt->execute([$id]);
    $dk = $stmt->fetch();
    if (!$dk) {
        http_response_code(404);
        exit('Deger kaybi kaydi bulunamadi.');
    }
}

$error = '';
$saved = false;

function dk_post_str(string $key): string {
    return trim((string)($_POST[$key] ?? ''));
}
function dk_post_date(string $key): ?string {
    $v = trim((string)($_POST[$key] ?? ''));
    return $v === '' ? null : $v;
}
function dk_post_money(string $key): ?float {
    $v = trim((string)($_POST[$key] ?? ''));
    if ($v === '') return null;
    $v = str_replace(['.', ' '], '', $v);
    $v = str_replace(',', '.', $v);
    return is_numeric($v) ? (float)$v : null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'plaka'         => mb_strtoupper(dk_post_str('plaka'), 'UTF-8'),
        'adi_soyadi'    => dk_post_str('adi_soyadi'),
        'tel'           => dk_post_str('tel'),
        'sigorta'       => dk_post_str('sigorta'),
        'hasar_tarihi'  => dk_post_date('hasar_tarihi'),
        'police_no'     => dk_post_str('police_no'),
        'dosya_no'      => dk_post_str('dosya_no'),
        'fatura_tarihi' => dk_post_date('fatura_tarihi'),
        'eksper'        => dk_post_str('eksper'),
        'teminat'       => dk_post_money('teminat'),
        'fatura_tutari' => dk_post_money('fatura_tutari'),
        'yatma_parasi'  => dk_post_money('yatma_parasi'),
        'takip'         => dk_post_str('takip'),
        'durum'         => dk_post_str('durum'),
        'acente'        => dk_post_str('acente'),
        'aciklama'      => dk_post_str('aciklama'),
    ];

    if ($data['plaka'] === '' || $data['adi_soyadi'] === '') {
        $error = 'Plaka ve Adi Soyadi alanlari zorunludur.';
    } else {
        try {
            if ($isNew) {
                $cols = array_keys($data);
                $sql = 'INSERT INTO deger_kaybi_records (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
                $pdo->prepare($sql)->execute(array_values($data));
                $newId = (int)$pdo->lastInsertId();
                header('Location: ' . panel_url('dk_view.php?id=' . $newId));
                exit;
            } else {
                $sets = implode(',', array_map(fn ($k) => "$k = ?", array_keys($data)));
                $sql = "UPDATE deger_kaybi_records SET $sets WHERE id = ?";
                $pdo->prepare($sql)->execute([...array_values($data), $id]);
                $saved = true;
                $dk = array_merge($dk, $data);
            }
        } catch (Throwable $e) {
            $error = 'Kaydetme hatasi: ' . $e->getMessage();
        }
    }
}

$durumOptions = ['Aktif', 'Yatma/Kombine', 'Beklemede', 'Dava', 'Kapali'];
?>
<!DOCTYPE html>
<html lang="tr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $isNew ? 'Yeni Deger Kaybi Dosyasi' : 'DK Duzenle - ' . e($dk['plaka']) ?></title>
  <?php render_panel_head_assets(); ?>
  <link rel="stylesheet" href="../css/widgets.css?v=4">
</head>
<body>
  <header class="topbar">
    <div class="topbar-brand">
      <div class="brand-logo">DRN</div>
      <span class="brand-name"><?= $isNew ? 'Yeni Deger Kaybi Dosyasi' : 'Deger Kaybi Duzenle' ?></span>
    </div>
    <nav>
      <?php render_current_user_badge(); ?>
      <a href="<?= e(panel_url('index.php')) ?>">&larr; Geri</a>
      <?php if (!$isNew): ?>
        <a href="<?= e(panel_url('dk_view.php?id=' . (int)$dk['id'])) ?>">Detay</a>
      <?php endif; ?>
    </nav>
  </header>

  <main class="layout">
    <section class="table-card" style="padding:24px;max-width:920px;margin:0 auto">
      <h2 style="margin:0 0 18px"><?= $isNew ? 'Yeni Dosya' : 'Kayit #' . (int)$dk['id'] . ' - ' . e($dk['plaka']) ?></h2>

      <?php if ($error): ?>
        <div style="padding:12px 16px;background:#fff0f0;border:1px solid #fcc;border-radius:8px;color:#c92a3a;margin-bottom:16px;font-size:13px"><?= e($error) ?></div>
      <?php endif; ?>
      <?php if ($saved): ?>
        <div style="padding:12px 16px;background:#f0fff4;border:1px solid #bbf7c5;border-radius:8px;color:#16a34a;margin-bottom:16px;font-size:13px">&#10004; Kayit guncellendi</div>
      <?php endif; ?>

      <form method="post" class="dk-form">
        <div class="dk-form-grid">
          <label><span>Plaka *</span><input name="plaka" value="<?= e($dk['plaka']) ?>" required></label>
          <label><span>Adi Soyadi *</span><input name="adi_soyadi" value="<?= e($dk['adi_soyadi']) ?>" required></label>
          <label><span>Telefon</span><input name="tel" value="<?= e($dk['tel']) ?>"></label>
          <label><span>Sigorta</span><input name="sigorta" value="<?= e($dk['sigorta']) ?>"></label>
          <label><span>Hasar Tarihi</span><input type="date" name="hasar_tarihi" value="<?= e($dk['hasar_tarihi']) ?>"></label>
          <label><span>Police No</span><input name="police_no" value="<?= e($dk['police_no']) ?>"></label>
          <label><span>Dosya No</span><input name="dosya_no" value="<?= e($dk['dosya_no']) ?>"></label>
          <label><span>Fatura Tarihi</span><input type="date" name="fatura_tarihi" value="<?= e($dk['fatura_tarihi']) ?>"></label>
          <label><span>Eksper</span><input name="eksper" value="<?= e($dk['eksper']) ?>"></label>
          <label><span>Teminat (TL)</span><input name="teminat" value="<?= $dk['teminat'] !== null ? e(number_format((float)$dk['teminat'], 2, ',', '.')) : '' ?>" inputmode="decimal"></label>
          <label><span>Fatura Tutari (TL)</span><input name="fatura_tutari" value="<?= $dk['fatura_tutari'] !== null ? e(number_format((float)$dk['fatura_tutari'], 2, ',', '.')) : '' ?>" inputmode="decimal"></label>
          <label><span>Yatma/Hak Mahrum (TL)</span><input name="yatma_parasi" value="<?= $dk['yatma_parasi'] !== null ? e(number_format((float)$dk['yatma_parasi'], 2, ',', '.')) : '' ?>" inputmode="decimal"></label>
          <label><span>Takip</span><input name="takip" value="<?= e($dk['takip']) ?>"></label>
          <label><span>Durum</span>
            <select name="durum">
              <option value="">-</option>
              <?php foreach ($durumOptions as $opt): ?>
                <option value="<?= e($opt) ?>" <?= $dk['durum'] === $opt ? 'selected' : '' ?>><?= e($opt) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label><span>Acente</span><input name="acente" value="<?= e($dk['acente']) ?>"></label>
          <label class="dk-form-full"><span>Aciklama</span><textarea name="aciklama" rows="4"><?= e($dk['aciklama']) ?></textarea></label>
        </div>

        <div style="display:flex;gap:8px;margin-top:18px">
          <button type="submit" class="btn-table-primary" style="padding:10px 20px;font-size:14px"><?= $isNew ? 'Olustur' : 'Guncelle' ?></button>
          <a class="btn-table" href="<?= e(panel_url('index.php')) ?>" style="padding:10px 20px;font-size:14px">Vazgec</a>
        </div>
      </form>
    </section>
  </main>

  <style>
    .dk-form-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(260px,1fr)); gap:14px; }
    .dk-form-full { grid-column: 1 / -1; }
    .dk-form label { display:flex; flex-direction:column; gap:6px; font-size:12px; font-weight:600; color:#666; }
    .dk-form input, .dk-form select, .dk-form textarea {
      padding:10px 12px; border:1px solid #d8d8df; border-radius:8px;
      font-size:14px; font-family:inherit; background:#fff;
    }
    .dk-form input:focus, .dk-form select:focus, .dk-form textarea:focus {
      outline:none; border-color:#e63946; box-shadow:0 0 0 3px rgba(230,57,70,.12);
    }
  </style>
</body>
</html>
