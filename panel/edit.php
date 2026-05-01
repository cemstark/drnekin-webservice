<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/attachments.php';
require_login();

ensure_excel_updates_table();

$uploadWarnings = [];

$id = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM service_records WHERE id = ?');
$stmt->execute([$id]);
$record = $stmt->fetch();
if (!$record) {
    http_response_code(404);
    exit('Kayit bulunamadi.');
}

$error = '';
$saved = false;

function edit_date_value(mixed $value): ?string
{
    $value = trim((string)$value);
    return $value === '' ? null : $value;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $fields = [
        'plate' => trim((string)($_POST['plate'] ?? '')),
        'customer_name' => trim((string)($_POST['customer_name'] ?? '')),
        'insurance_company' => trim((string)($_POST['insurance_company'] ?? '')),
        'repair_status' => trim((string)($_POST['repair_status'] ?? '')),
        'mini_repair_has' => isset($_POST['mini_repair_has']) ? 1 : 0,
        'mini_repair_part' => trim((string)($_POST['mini_repair_part'] ?? '')),
        'service_entry_date' => edit_date_value($_POST['service_entry_date'] ?? ''),
        'service_exit_date' => edit_date_value($_POST['service_exit_date'] ?? ''),
        'policy_start_date' => edit_date_value($_POST['policy_start_date'] ?? ''),
        'policy_end_date' => edit_date_value($_POST['policy_end_date'] ?? ''),
    ];

    if ($fields['plate'] === '' || $fields['customer_name'] === '' || $fields['service_entry_date'] === null) {
        $error = 'Plaka, ad soyad ve giris tarihi zorunludur.';
    } else {
        $fields['repair_status'] = $fields['repair_status'] ?: 'Belirtilmedi';
        $update = db()->prepare(
            'UPDATE service_records SET
             plate = :plate,
             customer_name = :customer_name,
             insurance_company = :insurance_company,
             repair_status = :repair_status,
             mini_repair_has = :mini_repair_has,
             mini_repair_part = :mini_repair_part,
             service_entry_date = :service_entry_date,
             service_exit_date = :service_exit_date,
             policy_start_date = :policy_start_date,
             policy_end_date = :policy_end_date,
             service_month = :service_month,
             updated_at = NOW()
             WHERE id = :id'
        );
        $update->execute([
            ':plate' => $fields['plate'],
            ':customer_name' => $fields['customer_name'],
            ':insurance_company' => $fields['insurance_company'],
            ':repair_status' => $fields['repair_status'],
            ':mini_repair_has' => $fields['mini_repair_has'],
            ':mini_repair_part' => $fields['mini_repair_part'],
            ':service_entry_date' => $fields['service_entry_date'],
            ':service_exit_date' => $fields['service_exit_date'],
            ':policy_start_date' => $fields['policy_start_date'],
            ':policy_end_date' => $fields['policy_end_date'],
            ':service_month' => substr((string)$fields['service_entry_date'], 0, 7),
            ':id' => $id,
        ]);

        $queue = db()->prepare('INSERT INTO pending_excel_updates (record_no, fields_json, created_by) VALUES (?, ?, ?)');
        $queue->execute([
            $record['record_no'],
            json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            current_user()['id'] ?? null,
        ]);

        // Yeni dosyalar (opsiyonel)
        if (!empty($_FILES['attachments']) && is_array($_FILES['attachments']['name'])) {
            $count = count($_FILES['attachments']['name']);
            $cats = $_POST['attachment_categories'] ?? [];
            for ($i = 0; $i < $count; $i++) {
                $file = [
                    'name'     => $_FILES['attachments']['name'][$i] ?? '',
                    'type'     => $_FILES['attachments']['type'][$i] ?? '',
                    'tmp_name' => $_FILES['attachments']['tmp_name'][$i] ?? '',
                    'error'    => $_FILES['attachments']['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                    'size'     => $_FILES['attachments']['size'][$i] ?? 0,
                ];
                if ((int)$file['error'] === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                $errs = attachment_validate_upload($file);
                if ($errs) {
                    $uploadWarnings[] = ($file['name'] ?: 'dosya') . ': ' . implode(' ', $errs);
                    continue;
                }
                $cat = (string)($cats[$i] ?? 'diger');
                try {
                    attachment_save($id, $file, $cat, current_user()['id'] ?? null);
                } catch (Throwable $e) {
                    $uploadWarnings[] = ($file['name'] ?: 'dosya') . ': ' . $e->getMessage();
                }
            }
        }

        $stmt->execute([$id]);
        $record = $stmt->fetch();
        $saved = true;
    }
}

$attachments = [];
$attachmentsAvailable = true;
try {
    $attachments = attachment_fetch_for_record($id);
} catch (Throwable $e) {
    $attachmentsAvailable = false;
}
?>
<!doctype html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Kayit Duzenle - <?= e(panel_config('app_name')) ?></title>
  <link rel="stylesheet" href="<?= e(panel_url('assets/panel.css')) ?>">
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body>
  <header class="topbar">
    <div>
      <div class="eyebrow">DRN</div>
      <h1>Kayit Duzenle</h1>
    </div>
    <nav>
      <a href="<?= e(panel_url('view.php?id=' . $id)) ?>">Detay</a>
      <a href="<?= e(panel_url('index.php')) ?>">Panele don</a>
      <a href="<?= e(panel_url('logout.php')) ?>">Cikis</a>
    </nav>
  </header>

  <main class="layout narrow">
    <section class="table-card form-card">
      <h2><?= e($record['plate']) ?> - <?= e($record['customer_name']) ?></h2>
      <?php if ($saved): ?>
        <div class="success">Kayit guncellendi. Excel senkron kuyruguna eklendi.</div>
      <?php endif; ?>
      <?php if ($uploadWarnings): ?>
        <div class="alert">Bazi dosyalar yuklenemedi:
          <ul style="margin:6px 0 0 18px;font-weight:normal">
            <?php foreach ($uploadWarnings as $w): ?><li><?= e($w) ?></li><?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>
      <?php if ($error !== ''): ?>
        <div class="alert"><?= e($error) ?></div>
      <?php endif; ?>
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <label>Plaka <input name="plate" value="<?= e($record['plate']) ?>" required></label>
        <label>Ad Soyad <input name="customer_name" value="<?= e($record['customer_name']) ?>" required></label>
        <label>Sigorta <input name="insurance_company" value="<?= e($record['insurance_company']) ?>"></label>
        <label>Tamir Durumu <input name="repair_status" value="<?= e($record['repair_status']) ?>"></label>
        <label class="check-row"><input type="checkbox" name="mini_repair_has" <?= (int)$record['mini_repair_has'] === 1 ? 'checked' : '' ?>> Mini onarim var</label>
        <label>Mini Onarim Parca <input name="mini_repair_part" value="<?= e($record['mini_repair_part']) ?>"></label>
        <label>Giris Tarihi <input type="date" name="service_entry_date" value="<?= e($record['service_entry_date']) ?>" required></label>
        <label>Cikis Tarihi <input type="date" name="service_exit_date" value="<?= e($record['service_exit_date']) ?>"></label>
        <label>Police Baslangic Tarihi <input type="date" name="policy_start_date" value="<?= e($record['policy_start_date'] ?? '') ?>"></label>
        <label>Police Bitis Tarihi <input type="date" name="policy_end_date" value="<?= e($record['policy_end_date'] ?? '') ?>"></label>

        <fieldset style="margin-top:14px;padding:12px;border:1px solid #e5e7eb;border-radius:8px;background:#f8fafc">
          <legend style="font-weight:600;padding:0 6px">Belge / Fotograf ekle (opsiyonel)</legend>
          <p style="font-size:12px;color:#64748b;margin:0 0 8px">Maks. 10 MB / dosya. PDF, JPG, PNG, WebP, DOCX, XLSX desteklenir.</p>
          <div id="attach-rows"></div>
          <button type="button" id="attach-add-row" style="margin-top:6px;padding:6px 10px;font-size:12px;border:1px solid #cbd5e1;background:#fff;border-radius:6px;cursor:pointer">+ Satir ekle</button>
        </fieldset>

        <button type="submit">Kaydet</button>
      </form>

      <?php if ($attachmentsAvailable && $attachments !== []): ?>
        <section style="margin-top:24px">
          <h3 style="margin:0 0 10px;font-size:16px;font-weight:700">Mevcut belgeler &amp; fotograflar</h3>
          <table style="width:100%;border-collapse:collapse;font-size:14px">
            <thead><tr style="text-align:left;color:#64748b;font-size:12px;text-transform:uppercase">
              <th style="padding:6px 4px">Kategori</th><th>Dosya</th><th>Boyut</th><th>Yuklendi</th><th></th>
            </tr></thead>
            <tbody>
              <?php foreach ($attachments as $att): ?>
                <tr style="border-top:1px solid #e2e8f0">
                  <td style="padding:6px 4px"><span style="background:#e2e8f0;border-radius:9999px;padding:2px 8px;font-size:11px;font-weight:600"><?= e(attachment_category_label($att['category'])) ?></span></td>
                  <td style="padding:6px 4px"><?= e($att['original_name']) ?></td>
                  <td style="padding:6px 4px"><?= e(attachment_format_size((int)$att['file_size'])) ?></td>
                  <td style="padding:6px 4px;font-size:12px;color:#64748b"><?= e($att['uploaded_at']) ?></td>
                  <td style="padding:6px 4px;text-align:right;white-space:nowrap">
                    <a href="<?= e(panel_url('download_attachment.php?id=' . (int)$att['id'])) ?>" style="color:#2563eb">Indir</a>
                    <form method="post" action="<?= e(panel_url('delete_attachment.php')) ?>" style="display:inline;margin-left:8px" onsubmit="return confirm('Dosyayi sil?');">
                      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                      <input type="hidden" name="id" value="<?= (int)$att['id'] ?>">
                      <input type="hidden" name="record_id" value="<?= (int)$id ?>">
                      <button type="submit" style="color:#dc2626;background:none;border:none;cursor:pointer;font-size:14px">Sil</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </section>
      <?php endif; ?>
    </section>
  </main>

  <script>
  (function() {
    const wrap = document.getElementById('attach-rows');
    const btn = document.getElementById('attach-add-row');
    const cats = <?= json_encode(attachment_categories(), JSON_UNESCAPED_UNICODE) ?>;
    function row() {
      const div = document.createElement('div');
      div.style.cssText = 'display:flex;gap:8px;align-items:center;margin-top:6px';
      let opts = '';
      for (const k in cats) opts += `<option value="${k}">${cats[k]}</option>`;
      div.innerHTML = `
        <select name="attachment_categories[]" style="height:32px;padding:0 8px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px">${opts}</select>
        <input type="file" name="attachments[]" style="flex:1;height:32px;padding:0 6px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px">
        <button type="button" style="height:30px;padding:0 10px;font-size:12px;border:1px solid #cbd5e1;background:#fff;border-radius:6px;cursor:pointer">Kaldir</button>
      `;
      div.querySelector('button').addEventListener('click', () => div.remove());
      wrap.appendChild(div);
    }
    btn.addEventListener('click', row);
    row();
  })();
  </script>
</body>
</html>
