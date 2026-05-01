<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/attachments.php';
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

$fields = [
    'plate' => '',
    'customer_name' => '',
    'insurance_company' => '',
    'repair_status' => '',
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
        'insurance_company' => trim((string)($_POST['insurance_company'] ?? '')),
        'repair_status' => trim((string)($_POST['repair_status'] ?? '')),
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
        $fields['repair_status'] = $fields['repair_status'] ?: 'Belirtilmedi';
        $recordNo = add_record_no($fields['plate'], $fields['service_entry_date']);
        $insert = db()->prepare(
            'INSERT INTO service_records
             (record_no, plate, customer_name, insurance_company, repair_status, mini_repair_has, mini_repair_part, service_entry_date, service_exit_date, policy_start_date, policy_end_date, service_month, updated_at)
             VALUES
             (:record_no, :plate, :customer_name, :insurance_company, :repair_status, :mini_repair_has, :mini_repair_part, :service_entry_date, :service_exit_date, :policy_start_date, :policy_end_date, :service_month, NOW())'
        );
        $insert->execute([
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
        ]);
        $newRecordId = (int)db()->lastInsertId();

        $queuedFields = $fields;
        $queuedFields['_action'] = 'append';
        $queue = db()->prepare('INSERT INTO pending_excel_updates (record_no, fields_json, created_by) VALUES (?, ?, ?)');
        $queue->execute([
            $recordNo,
            json_encode($queuedFields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            current_user()['id'] ?? null,
        ]);

        // Ek dosyalar (opsiyonel)
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
            'insurance_company' => '',
            'repair_status' => '',
            'mini_repair_has' => 0,
            'mini_repair_part' => '',
            'service_entry_date' => date('Y-m-d'),
            'service_exit_date' => '',
            'policy_start_date' => '',
            'policy_end_date' => '',
        ];
    }
}

$activePage = 'arac-kabul';
$pageTitle = 'Arac Kabul';
include __DIR__ . '/includes/_layout_head.php';
?>
  <main class="content">
    <header class="page-head">
      <div class="title-block">
        <div class="eyebrow">Manuel Kayit</div>
        <h1>Arac Kabul</h1>
        <p class="sub">Yeni arac bilgisini sisteme ekleyin; Excel kuyruguna otomatik yazilir.</p>
      </div>
      <div class="actions">
        <a class="btn-ghost" href="<?= e(panel_url('index.php')) ?>">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
          Panele don
        </a>
      </div>
    </header>

    <section class="card" style="overflow:hidden">
      <div class="card-pad-lg">
        <?php if ($created): ?>
          <div class="banner is-ok">
            <span class="ico"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>
            <div>
              <h3>Arac eklendi</h3>
              <p>Excel'e yeni satir olarak yazilmak uzere kuyruga alindi. <?php if ($newRecordId > 0): ?><a href="<?= e(panel_url('view.php?id=' . $newRecordId)) ?>">Detay sayfasi</a><?php endif; ?></p>
            </div>
          </div>
        <?php endif; ?>
        <?php if ($uploadWarnings): ?>
          <div class="banner is-warn">
            <span class="ico"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg></span>
            <div><h3>Bazi dosyalar yuklenemedi</h3><ul style="margin:6px 0 0 16px;font-size:13px;font-weight:500"><?php foreach ($uploadWarnings as $w): ?><li><?= e($w) ?></li><?php endforeach; ?></ul></div>
          </div>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
          <div class="banner is-danger">
            <span class="ico"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg></span>
            <div><h3>Eksik bilgi</h3><p><?= e($error) ?></p></div>
          </div>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data" class="form-grid">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

          <fieldset class="form-section full" style="display:grid;gap:14px;grid-template-columns:repeat(2,minmax(0,1fr))">
            <legend>Arac &amp; Musteri</legend>
            <label class="field"><span class="label">Plaka</span><input name="plate" value="<?= e($fields['plate']) ?>" required></label>
            <label class="field"><span class="label">Ad Soyad</span><input name="customer_name" value="<?= e($fields['customer_name']) ?>" required></label>
            <label class="field full" style="grid-column:1/-1"><span class="label">Sigorta Sirketi</span><input name="insurance_company" value="<?= e($fields['insurance_company']) ?>"></label>
          </fieldset>

          <fieldset class="form-section full" style="display:grid;gap:14px;grid-template-columns:repeat(2,minmax(0,1fr))">
            <legend>Servis Durumu</legend>
            <label class="field"><span class="label">Tamir Durumu</span><input name="repair_status" value="<?= e($fields['repair_status']) ?>"></label>
            <label class="check-row"><input type="checkbox" name="mini_repair_has" <?= (int)$fields['mini_repair_has'] === 1 ? 'checked' : '' ?>> Mini onarim var</label>
            <label class="field full" style="grid-column:1/-1"><span class="label">Mini Onarim Parca</span><input name="mini_repair_part" value="<?= e($fields['mini_repair_part']) ?>"></label>
            <label class="field"><span class="label">Giris Tarihi</span><input type="date" name="service_entry_date" value="<?= e($fields['service_entry_date']) ?>" required></label>
            <label class="field"><span class="label">Cikis Tarihi</span><input type="date" name="service_exit_date" value="<?= e($fields['service_exit_date']) ?>"></label>
          </fieldset>

          <fieldset class="form-section full" style="display:grid;gap:14px;grid-template-columns:repeat(2,minmax(0,1fr))">
            <legend>Police</legend>
            <label class="field"><span class="label">Police Baslangic</span><input type="date" name="policy_start_date" value="<?= e($fields['policy_start_date']) ?>"></label>
            <label class="field"><span class="label">Police Bitis</span><input type="date" name="policy_end_date" value="<?= e($fields['policy_end_date']) ?>"></label>
          </fieldset>

          <fieldset class="form-section full">
            <legend>Belge &amp; Fotograf (opsiyonel)</legend>
            <p class="hint" style="margin:0 0 10px">Maks. 10 MB / dosya. PDF, JPG, PNG, WebP, DOCX, XLSX desteklenir.</p>
            <div id="attach-rows" style="display:grid;gap:10px"></div>
            <button type="button" id="attach-add-row" class="btn-ghost btn-sm" style="margin-top:10px">+ Satir ekle</button>
          </fieldset>

          <div class="full" style="display:flex;justify-content:flex-end;gap:10px;padding-top:8px">
            <a class="btn-ghost" href="<?= e(panel_url('index.php')) ?>">Vazgec</a>
            <button type="submit">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
              Araci Ekle
            </button>
          </div>
        </form>
      </div>
    </section>
  </main>

  <script>
  (function() {
    const wrap = document.getElementById('attach-rows');
    const btn = document.getElementById('attach-add-row');
    const cats = <?= json_encode(attachment_categories(), JSON_UNESCAPED_UNICODE) ?>;
    function row() {
      const div = document.createElement('div');
      div.style.cssText = 'display:grid;grid-template-columns:200px 1fr auto;gap:10px;align-items:center';
      let opts = '';
      for (const k in cats) opts += `<option value="${k}">${cats[k]}</option>`;
      div.innerHTML = `
        <select name="attachment_categories[]">${opts}</select>
        <input type="file" name="attachments[]">
        <button type="button" class="btn-ghost btn-sm">Kaldir</button>
      `;
      div.querySelector('button').addEventListener('click', () => div.remove());
      wrap.appendChild(div);
    }
    btn.addEventListener('click', row);
    row(); row(); row();
  })();
  </script>
<?php include __DIR__ . '/includes/_layout_foot.php'; ?>
