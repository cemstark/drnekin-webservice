<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/attachments.php';
require_once __DIR__ . '/includes/options.php';
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

function edit_policy_reminder_column_exists(): bool
{
    static $exists = null;
    if ($exists !== null) {
        return $exists;
    }

    try {
        db()->query('SELECT policy_reminder_sent_at FROM service_records LIMIT 0');
        $exists = true;
    } catch (Throwable $e) {
        $exists = false;
    }

    return $exists;
}

function edit_insurance_type_column_exists(): bool
{
    static $exists = null;
    if ($exists !== null) {
        return $exists;
    }

    try {
        db()->query('SELECT insurance_type FROM service_records LIMIT 0');
        $exists = true;
    } catch (Throwable $e) {
        $exists = false;
    }

    return $exists;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $fields = [
        'plate' => trim((string)($_POST['plate'] ?? '')),
        'customer_name' => trim((string)($_POST['customer_name'] ?? '')),
        'insurance_type' => valid_insurance_type($_POST['insurance_type'] ?? null) ? (string)$_POST['insurance_type'] : 'kasko',
        'insurance_company' => trim((string)($_POST['insurance_company'] ?? '')),
        'repair_status' => normalize_repair_status((string)($_POST['repair_status'] ?? '')),
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
        $fields['repair_status'] = normalize_repair_status($fields['repair_status']);
        $resetPolicyReminder = edit_policy_reminder_column_exists()
            && ((string)($record['policy_end_date'] ?? '') !== (string)($fields['policy_end_date'] ?? ''));
        $hasInsuranceType = edit_insurance_type_column_exists();
        $sql = 'UPDATE service_records SET
             plate = :plate,
             customer_name = :customer_name,
             ' . ($hasInsuranceType ? 'insurance_type = :insurance_type,' : '') . '
             insurance_company = :insurance_company,
             repair_status = :repair_status,
             mini_repair_has = :mini_repair_has,
             mini_repair_part = :mini_repair_part,
             service_entry_date = :service_entry_date,
             service_exit_date = :service_exit_date,
             policy_start_date = :policy_start_date,
             policy_end_date = :policy_end_date,
             service_month = :service_month,
             updated_at = NOW()'
             . ($resetPolicyReminder ? ', policy_reminder_sent_at = NULL' : '')
             . ' WHERE id = :id';
        $update = db()->prepare($sql);
        $updateParams = [
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
        ];
        if ($hasInsuranceType) {
            $updateParams[':insurance_type'] = $fields['insurance_type'];
        }
        $update->execute($updateParams);

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
      <?php render_current_user_badge(); ?>
      <a href="<?= e(panel_url('view.php?id=' . $id)) ?>">Detay</a>
      <a href="<?= e(panel_url('index.php')) ?>">Panele don</a>
      <a href="<?= e(panel_url('logout.php')) ?>">Cikis</a>
    </nav>
  </header>

  <main class="edit-shell mx-auto w-full max-w-5xl px-4 py-8 sm:px-6 lg:px-8">
    <section class="edit-card table-card form-card">
      <div class="edit-hero">
        <div>
          <p class="section-kicker">Kayit yonetimi</p>
          <h2><?= e($record['plate']) ?> - <?= e($record['customer_name']) ?></h2>
          <span>Kayit no: <?= e($record['record_no']) ?></span>
        </div>
        <a class="btn-secondary" href="<?= e(panel_url('view.php?id=' . $id)) ?>">Detaya don</a>
      </div>
      <?php if ($saved): ?>
        <div class="success">Kayit guncellendi. Excel senkron kuyruguna eklendi.</div>
      <?php endif; ?>
      <?php if ($uploadWarnings): ?>
        <div class="alert">Bazi dosyalar yuklenemedi:
          <ul class="message-list">
            <?php foreach ($uploadWarnings as $w): ?><li><?= e($w) ?></li><?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>
      <?php if ($error !== ''): ?>
        <div class="alert"><?= e($error) ?></div>
      <?php endif; ?>
      <form method="post" enctype="multipart/form-data" class="edit-form">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <div class="form-grid">
        <label>Plaka <input name="plate" value="<?= e($record['plate']) ?>" required></label>
        <label>Ad Soyad <input name="customer_name" value="<?= e($record['customer_name']) ?>" required></label>
        <label>Arac Filtresi
          <select name="insurance_type">
            <?php $selectedType = (string)($record['insurance_type'] ?? 'kasko'); ?>
            <?php foreach (insurance_type_options() as $key => $label): ?>
              <option value="<?= e($key) ?>" <?= $selectedType === $key ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Sigorta <input name="insurance_company" value="<?= e($record['insurance_company']) ?>"></label>
        <label>Tamir Durumu
          <select name="repair_status">
            <?php if (!array_key_exists((string)$record['repair_status'], repair_status_options())): ?>
              <option value="<?= e($record['repair_status']) ?>" selected><?= e($record['repair_status']) ?></option>
            <?php endif; ?>
            <?php foreach (repair_status_options() as $key => $label): ?>
              <option value="<?= e($key) ?>" <?= (string)$record['repair_status'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="check-row"><input type="checkbox" name="mini_repair_has" <?= (int)$record['mini_repair_has'] === 1 ? 'checked' : '' ?>> Mini onarim var</label>
        <label>Mini Onarim Parca <input name="mini_repair_part" value="<?= e($record['mini_repair_part']) ?>"></label>
        <label>Giris Tarihi <input type="date" name="service_entry_date" value="<?= e($record['service_entry_date']) ?>" required></label>
        <label>Cikis Tarihi <input type="date" name="service_exit_date" value="<?= e($record['service_exit_date']) ?>"></label>
        <label>Police Baslangic Tarihi <input type="date" name="policy_start_date" value="<?= e($record['policy_start_date'] ?? '') ?>"></label>
        <label>Police Bitis Tarihi <input type="date" name="policy_end_date" value="<?= e($record['policy_end_date'] ?? '') ?>"></label>
        </div>

        <fieldset class="upload-panel edit-upload">
          <legend>Belge / Fotograf ekle (opsiyonel)</legend>
          <p>Maks. 10 MB / dosya. PDF, JPG, PNG, WebP, DOCX, XLSX desteklenir.</p>
          <div id="attach-rows"></div>
          <button type="button" id="attach-add-row" class="btn-secondary attach-add">+ Satir ekle</button>
        </fieldset>

        <div class="form-actions">
          <a class="btn-secondary" href="<?= e(panel_url('view.php?id=' . $id)) ?>">Vazgec</a>
          <button class="btn-primary" type="submit">Kaydet</button>
        </div>
      </form>

      <?php if ($attachmentsAvailable && $attachments !== []): ?>
        <section class="edit-attachments">
          <div class="edit-section-head">
            <h3>Mevcut belgeler &amp; fotograflar</h3>
            <a class="btn-soft" href="<?= e(panel_url('download_all.php?id=' . $id)) ?>">Tumunu ZIP indir</a>
          </div>
          <div class="table-wrap compact-table">
          <table>
            <thead><tr>
              <th>Kategori</th><th>Dosya</th><th>Boyut</th><th>Yuklendi</th><th></th>
            </tr></thead>
            <tbody>
              <?php foreach ($attachments as $att): ?>
                <tr>
                  <td><span class="type-badge"><?= e(attachment_category_label($att['category'])) ?></span></td>
                  <td><?= e($att['original_name']) ?></td>
                  <td><?= e(attachment_format_size((int)$att['file_size'])) ?></td>
                  <td class="muted-cell"><?= e(format_tr_datetime($att['uploaded_at'] ?? null)) ?></td>
                  <td class="row-actions">
                    <?php if (attachment_can_preview($att['mime_type'])): ?>
                      <a href="<?= e(panel_url('download_attachment.php?id=' . (int)$att['id'] . '&inline=1')) ?>" target="_blank" rel="noopener">Goruntule</a>
                    <?php endif; ?>
                    <a href="<?= e(panel_url('download_attachment.php?id=' . (int)$att['id'])) ?>">Indir</a>
                    <form method="post" action="<?= e(panel_url('delete_attachment.php')) ?>" onsubmit="return confirm('Dosyayi sil?');">
                      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                      <input type="hidden" name="id" value="<?= (int)$att['id'] ?>">
                      <input type="hidden" name="record_id" value="<?= (int)$id ?>">
                      <button class="link-danger" type="submit">Sil</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          </div>
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
      div.className = 'attach-row';
      let opts = '';
      for (const k in cats) opts += `<option value="${k}">${cats[k]}</option>`;
      div.innerHTML = `
        <select name="attachment_categories[]">${opts}</select>
        <input type="file" name="attachments[]">
        <button type="button" class="btn-secondary">Kaldir</button>
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
