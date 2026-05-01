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
?>
<!doctype html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Arac Ekle - <?= e(panel_config('app_name')) ?></title>
  <link rel="stylesheet" href="<?= e(panel_url('assets/panel.css')) ?>">
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body>
  <header class="topbar">
    <div>
      <div class="eyebrow">DRN</div>
      <h1>Arac Ekle</h1>
    </div>
    <nav>
      <a href="<?= e(panel_url('index.php')) ?>">Panele don</a>
      <a href="<?= e(panel_url('logout.php')) ?>">Cikis</a>
    </nav>
  </header>

  <main class="mx-auto w-full max-w-5xl px-4 py-8 sm:px-6 lg:px-8">
    <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
      <div class="border-b border-slate-200 bg-slate-50 px-6 py-5">
        <p class="text-xs font-semibold uppercase tracking-wide text-blue-700">Manuel kayit</p>
        <h2 class="mt-1 text-xl font-bold text-slate-950">Yeni arac bilgisi</h2>
      </div>
      <div class="px-6 py-6">
      <?php if ($created): ?>
        <div class="mb-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700">
          Arac eklendi. Excel'e yeni satir olarak yazilmak uzere kuyruga alindi.
          <?php if ($newRecordId > 0): ?>
            <a class="ml-2 underline" href="<?= e(panel_url('view.php?id=' . $newRecordId)) ?>">Detay sayfasina git</a>
          <?php endif; ?>
        </div>
      <?php endif; ?>
      <?php if ($uploadWarnings): ?>
        <div class="mb-5 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-800">
          Bazi dosyalar yuklenemedi:
          <ul class="mt-1 list-disc pl-5 font-normal">
            <?php foreach ($uploadWarnings as $w): ?><li><?= e($w) ?></li><?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>
      <?php if ($error !== ''): ?>
        <div class="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-700"><?= e($error) ?></div>
      <?php endif; ?>
      <form class="grid gap-5 md:grid-cols-2" method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <label class="grid gap-2 text-sm font-semibold text-slate-700">
          Plaka
          <input class="h-11 rounded-lg border border-slate-200 px-3 text-sm font-normal outline-none transition focus:border-blue-500 focus:ring-4 focus:ring-blue-100" name="plate" value="<?= e($fields['plate']) ?>" required>
        </label>
        <label class="grid gap-2 text-sm font-semibold text-slate-700">
          Ad Soyad
          <input class="h-11 rounded-lg border border-slate-200 px-3 text-sm font-normal outline-none transition focus:border-blue-500 focus:ring-4 focus:ring-blue-100" name="customer_name" value="<?= e($fields['customer_name']) ?>" required>
        </label>
        <label class="grid gap-2 text-sm font-semibold text-slate-700">
          Sigorta
          <input class="h-11 rounded-lg border border-slate-200 px-3 text-sm font-normal outline-none transition focus:border-blue-500 focus:ring-4 focus:ring-blue-100" name="insurance_company" value="<?= e($fields['insurance_company']) ?>">
        </label>
        <label class="grid gap-2 text-sm font-semibold text-slate-700">
          Tamir Durumu
          <input class="h-11 rounded-lg border border-slate-200 px-3 text-sm font-normal outline-none transition focus:border-blue-500 focus:ring-4 focus:ring-blue-100" name="repair_status" value="<?= e($fields['repair_status']) ?>">
        </label>
        <label class="flex min-h-11 items-center gap-3 rounded-lg border border-slate-200 bg-slate-50 px-3 text-sm font-semibold text-slate-700">
          <input class="h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500" type="checkbox" name="mini_repair_has" <?= (int)$fields['mini_repair_has'] === 1 ? 'checked' : '' ?>>
          Mini onarim var
        </label>
        <label class="grid gap-2 text-sm font-semibold text-slate-700">
          Mini Onarim Parca
          <input class="h-11 rounded-lg border border-slate-200 px-3 text-sm font-normal outline-none transition focus:border-blue-500 focus:ring-4 focus:ring-blue-100" name="mini_repair_part" value="<?= e($fields['mini_repair_part']) ?>">
        </label>
        <label class="grid gap-2 text-sm font-semibold text-slate-700">
          Giris Tarihi
          <input class="h-11 rounded-lg border border-slate-200 px-3 text-sm font-normal outline-none transition focus:border-blue-500 focus:ring-4 focus:ring-blue-100" type="date" name="service_entry_date" value="<?= e($fields['service_entry_date']) ?>" required>
        </label>
        <label class="grid gap-2 text-sm font-semibold text-slate-700">
          Cikis Tarihi
          <input class="h-11 rounded-lg border border-slate-200 px-3 text-sm font-normal outline-none transition focus:border-blue-500 focus:ring-4 focus:ring-blue-100" type="date" name="service_exit_date" value="<?= e($fields['service_exit_date']) ?>">
        </label>
        <label class="grid gap-2 text-sm font-semibold text-slate-700">
          Police Baslangic Tarihi
          <input class="h-11 rounded-lg border border-slate-200 px-3 text-sm font-normal outline-none transition focus:border-blue-500 focus:ring-4 focus:ring-blue-100" type="date" name="policy_start_date" value="<?= e($fields['policy_start_date']) ?>">
        </label>
        <label class="grid gap-2 text-sm font-semibold text-slate-700">
          Police Bitis Tarihi
          <input class="h-11 rounded-lg border border-slate-200 px-3 text-sm font-normal outline-none transition focus:border-blue-500 focus:ring-4 focus:ring-blue-100" type="date" name="policy_end_date" value="<?= e($fields['policy_end_date']) ?>">
        </label>
        <fieldset class="md:col-span-2 grid gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4">
          <legend class="px-2 text-sm font-bold text-slate-800">Belge &amp; Fotograf Yukleme (opsiyonel)</legend>
          <p class="text-xs text-slate-500">Maks. 10 MB / dosya. PDF, JPG, PNG, WebP, DOCX, XLSX desteklenir.</p>
          <div id="attach-rows" class="grid gap-3"></div>
          <div>
            <button type="button" id="attach-add-row" class="inline-flex h-9 items-center justify-center rounded-lg border border-slate-300 bg-white px-3 text-xs font-semibold text-slate-700 hover:bg-slate-100">+ Satir ekle</button>
          </div>
        </fieldset>

        <div class="md:col-span-2 flex flex-wrap items-center justify-end gap-3 border-t border-slate-200 pt-5">
          <a class="inline-flex h-11 items-center justify-center rounded-lg border border-slate-200 px-4 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" href="<?= e(panel_url('index.php')) ?>">Vazgec</a>
          <button class="inline-flex h-11 items-center justify-center rounded-lg bg-blue-600 px-6 text-sm font-bold text-white transition hover:bg-blue-700" type="submit">Araci ekle</button>
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
      div.className = 'grid gap-2 sm:grid-cols-[180px_1fr_auto] items-center';
      let opts = '';
      for (const k in cats) opts += `<option value="${k}">${cats[k]}</option>`;
      div.innerHTML = `
        <select name="attachment_categories[]" class="h-10 rounded-lg border border-slate-200 bg-white px-2 text-sm">${opts}</select>
        <input type="file" name="attachments[]" class="h-10 rounded-lg border border-slate-200 bg-white px-2 text-sm">
        <button type="button" class="h-9 rounded-lg border border-slate-200 px-3 text-xs text-slate-600 hover:bg-slate-100">Kaldir</button>
      `;
      div.querySelector('button').addEventListener('click', () => div.remove());
      wrap.appendChild(div);
    }
    btn.addEventListener('click', row);
    row(); row(); row();
  })();
  </script>
</body>
</html>
