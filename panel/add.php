<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/attachments.php';
require_once __DIR__ . '/includes/options.php';
require_login();

ensure_excel_updates_table();

$error = '';
$created = false;
$newRecordId = 0;
$uploadWarnings = [];

function add_date_value(mixed $value): ?string
{
    $value = trim((string)$value);
    return $value === '' ? null : $value;
}

function add_record_no(string $plate, ?string $entryDate): string
{
    $plateKey = preg_replace('/[^A-Z0-9]+/', '-', strtoupper($plate)) ?: 'ARAC';
    return 'manual-' . date('YmdHis') . '-' . strtolower(trim($plateKey, '-')) . '-' . substr((string)($entryDate ?: 'tarihsiz'), 0, 10);
}

function add_insurance_type_column_exists(): bool
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

$fields = [
    'plate' => '',
    'customer_name' => '',
    'insurance_type' => 'kasko',
    'insurance_company' => '',
    'repair_status' => 'Giris Yapti',
    'mini_repair_has' => 0,
    'mini_repair_part' => '',
    'service_entry_date' => date('Y-m-d'),
    'service_exit_date' => '',
    'policy_start_date' => '',
    'policy_end_date' => '',
];

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
        'service_entry_date' => add_date_value($_POST['service_entry_date'] ?? ''),
        'service_exit_date' => add_date_value($_POST['service_exit_date'] ?? ''),
        'policy_start_date' => add_date_value($_POST['policy_start_date'] ?? ''),
        'policy_end_date' => add_date_value($_POST['policy_end_date'] ?? ''),
    ];

    if ($fields['plate'] === '' || $fields['customer_name'] === '' || $fields['service_entry_date'] === null) {
        $error = 'Plaka, ad soyad ve giris tarihi zorunludur.';
    } else {
        try {
        $fields['repair_status'] = normalize_repair_status($fields['repair_status']);
        $recordNo = add_record_no($fields['plate'], $fields['service_entry_date']);
        $hasInsuranceType = add_insurance_type_column_exists();
        $insert = db()->prepare(
            'INSERT INTO service_records
             (record_no, plate, customer_name, ' . ($hasInsuranceType ? 'insurance_type, ' : '') . 'insurance_company, repair_status, mini_repair_has, mini_repair_part, service_entry_date, service_exit_date, policy_start_date, policy_end_date, service_month, updated_at)
             VALUES
             (:record_no, :plate, :customer_name, ' . ($hasInsuranceType ? ':insurance_type, ' : '') . ':insurance_company, :repair_status, :mini_repair_has, :mini_repair_part, :service_entry_date, :service_exit_date, :policy_start_date, :policy_end_date, :service_month, NOW())'
        );
        $insertParams = [
            ':record_no' => $recordNo,
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
        ];
        if ($hasInsuranceType) {
            $insertParams[':insurance_type'] = $fields['insurance_type'];
        }
        $insert->execute($insertParams);
        $newRecordId = (int)db()->lastInsertId();

        $queuedFields = $fields;
        $queuedFields['_action'] = 'append';
        $queue = db()->prepare('INSERT INTO pending_excel_updates (record_no, fields_json, created_by) VALUES (?, ?, ?)');
        $queue->execute([
            $recordNo,
            json_encode($queuedFields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            current_user()['id'] ?? null,
        ]);

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
                    attachment_save($newRecordId, $file, $cat, current_user()['id'] ?? null);
                } catch (Throwable $e) {
                    $uploadWarnings[] = ($file['name'] ?: 'dosya') . ': ' . $e->getMessage();
                }
            }
        }

        $created = true;
        $fields = [
            'plate' => '',
            'customer_name' => '',
            'insurance_type' => 'kasko',
            'insurance_company' => '',
            'repair_status' => 'Giris Yapti',
            'mini_repair_has' => 0,
            'mini_repair_part' => '',
            'service_entry_date' => date('Y-m-d'),
            'service_exit_date' => '',
            'policy_start_date' => '',
            'policy_end_date' => '',
        ];
        } catch (PDOException $e) {
            if ((string)$e->getCode() === '23000') {
                $error = 'Bu plaka, musteri ve giris tarihiyle zaten bir kayit var.';
            } else {
                throw $e;
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
  <title>Arac Ekle - <?= e(panel_config('app_name')) ?></title>
  <?php render_panel_head_assets(); ?>
</head>
<body>
  <header class="topbar">
    <div class="topbar-brand">
      <div class="brand-logo">DRN</div>
      <span class="brand-name">Arac Ekle</span>
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
          <div class="kicker">Manuel kayit</div>
          <h2>Yeni arac bilgisi</h2>
        </div>
      </div>
      <div class="form-card-body">
        <?php if ($created): ?>
          <div class="success">
            Arac eklendi. Excel'e yeni satir olarak yazilmak uzere kuyruga alindi.
            <?php if ($newRecordId > 0): ?>
              <a href="<?= e(panel_url('view.php?id=' . $newRecordId)) ?>">Detay sayfasina git</a>
            <?php endif; ?>
          </div>
        <?php endif; ?>
        <?php if ($uploadWarnings): ?>
          <div class="alert">Bazi dosyalar yuklenemedi:
            <ul style="margin:6px 0 0 18px">
              <?php foreach ($uploadWarnings as $w): ?><li><?= e($w) ?></li><?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
          <div class="alert"><?= e($error) ?></div>
        <?php endif; ?>

        <form class="form-grid" method="post" enctype="multipart/form-data">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <label>Plaka <input name="plate" value="<?= e($fields['plate']) ?>" required></label>
          <label>Ad Soyad <input name="customer_name" value="<?= e($fields['customer_name']) ?>" required></label>
          <label>Arac Filtresi
            <select name="insurance_type">
              <?php foreach (insurance_type_options() as $key => $label): ?>
                <option value="<?= e($key) ?>" <?= $fields['insurance_type'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Sigorta <input name="insurance_company" value="<?= e($fields['insurance_company']) ?>"></label>
          <label>Tamir Durumu
            <select name="repair_status">
              <?php foreach (repair_status_options() as $key => $label): ?>
                <option value="<?= e($key) ?>" <?= $fields['repair_status'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="check-row">
            <input type="checkbox" name="mini_repair_has" <?= (int)$fields['mini_repair_has'] === 1 ? 'checked' : '' ?>>
            <span>Mini onarim var</span>
          </label>
          <label>Mini Onarim Parca <input name="mini_repair_part" value="<?= e($fields['mini_repair_part']) ?>"></label>
          <label>Giris Tarihi <input type="date" name="service_entry_date" value="<?= e($fields['service_entry_date']) ?>" required></label>
          <label>Cikis Tarihi <input type="date" name="service_exit_date" value="<?= e($fields['service_exit_date']) ?>"></label>
          <label>Police Baslangic <input type="date" name="policy_start_date" value="<?= e($fields['policy_start_date']) ?>"></label>
          <label>Police Bitis <input type="date" name="policy_end_date" value="<?= e($fields['policy_end_date']) ?>"></label>

          <fieldset class="edit-upload" style="grid-column:1/-1">
            <legend>Belge &amp; Fotograf Yukleme (opsiyonel)</legend>
            <p>Maks. 10 MB / dosya. PDF, JPG, PNG, WebP, DOCX, XLSX desteklenir.</p>
            <div id="attach-rows"></div>
            <button type="button" id="attach-add-row" class="btn-secondary attach-add">+ Satir ekle</button>
          </fieldset>

          <div class="form-actions" style="grid-column:1/-1">
            <a class="btn-secondary" href="<?= e(panel_url('index.php')) ?>">Vazgec</a>
            <button class="btn-primary" type="submit">Araci ekle</button>
          </div>
        </form>
      </div>
    </div>
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
        <button type="button" class="btn-secondary" style="height:36px;font-size:12px">Kaldir</button>
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
