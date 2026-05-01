<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/attachments.php';
require_login();

ensure_excel_updates_table();

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
$uploadWarnings = [];

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

        // Police bitis tarihi degistiyse hatirlatma flag'ini sifirla
        $resetReminder = ((string)($record['policy_end_date'] ?? '') !== (string)($fields['policy_end_date'] ?? ''));

        $sql = 'UPDATE service_records SET
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
                 updated_at = NOW()'
                . ($resetReminder ? ', policy_reminder_sent_at = NULL' : '') .
                ' WHERE id = :id';
        $update = db()->prepare($sql);
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

        // Yeni dosyalar varsa ekle
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
                attachment_save($id, $file, $cat, current_user()['id'] ?? null);
            }
        }

        $stmt->execute([$id]);
        $record = $stmt->fetch();
        $saved = true;
    }
}

$categoryFilter = trim((string)($_GET['cat'] ?? ''));
if ($categoryFilter !== '' && !attachment_category_valid($categoryFilter)) {
    $categoryFilter = '';
}
$attachments = attachment_fetch_for_record($id, $categoryFilter !== '' ? $categoryFilter : null);
$categories = attachment_categories();
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

  <main class="mx-auto w-full max-w-5xl px-4 py-8 sm:px-6 lg:px-8">
    <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
      <div class="border-b border-slate-200 bg-slate-50 px-6 py-5 flex items-center justify-between">
        <div>
          <p class="text-xs font-semibold uppercase tracking-wide text-blue-700">Duzenleme</p>
          <h2 class="mt-1 text-xl font-bold text-slate-950"><?= e($record['plate']) ?> &mdash; <?= e($record['customer_name']) ?></h2>
        </div>
        <a class="inline-flex h-10 items-center justify-center rounded-lg border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-700 hover:bg-slate-50" href="<?= e(panel_url('view.php?id=' . $id)) ?>">Detayli goruntule</a>
      </div>
      <div class="px-6 py-6">
      <?php if ($saved): ?>
        <div class="mb-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700">Kayit guncellendi. Excel senkron kuyruguna eklendi.</div>
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
        <label class="grid gap-2 text-sm font-semibold text-slate-700">Plaka
          <input class="h-11 rounded-lg border border-slate-200 px-3 text-sm font-normal" name="plate" value="<?= e($record['plate']) ?>" required>
        </label>
        <label class="grid gap-2 text-sm font-semibold text-slate-700">Ad Soyad
          <input class="h-11 rounded-lg border border-slate-200 px-3 text-sm font-normal" name="customer_name" value="<?= e($record['customer_name']) ?>" required>
        </label>
        <label class="grid gap-2 text-sm font-semibold text-slate-700">Sigorta
          <input class="h-11 rounded-lg border border-slate-200 px-3 text-sm font-normal" name="insurance_company" value="<?= e($record['insurance_company']) ?>">
        </label>
        <label class="grid gap-2 text-sm font-semibold text-slate-700">Tamir Durumu
          <input class="h-11 rounded-lg border border-slate-200 px-3 text-sm font-normal" name="repair_status" value="<?= e($record['repair_status']) ?>">
        </label>
        <label class="flex min-h-11 items-center gap-3 rounded-lg border border-slate-200 bg-slate-50 px-3 text-sm font-semibold text-slate-700">
          <input type="checkbox" class="h-4 w-4 rounded border-slate-300 text-blue-600" name="mini_repair_has" <?= (int)$record['mini_repair_has'] === 1 ? 'checked' : '' ?>> Mini onarim var
        </label>
        <label class="grid gap-2 text-sm font-semibold text-slate-700">Mini Onarim Parca
          <input class="h-11 rounded-lg border border-slate-200 px-3 text-sm font-normal" name="mini_repair_part" value="<?= e($record['mini_repair_part']) ?>">
        </label>
        <label class="grid gap-2 text-sm font-semibold text-slate-700">Giris Tarihi
          <input type="date" class="h-11 rounded-lg border border-slate-200 px-3 text-sm font-normal" name="service_entry_date" value="<?= e($record['service_entry_date']) ?>" required>
        </label>
        <label class="grid gap-2 text-sm font-semibold text-slate-700">Cikis Tarihi
          <input type="date" class="h-11 rounded-lg border border-slate-200 px-3 text-sm font-normal" name="service_exit_date" value="<?= e($record['service_exit_date']) ?>">
        </label>
        <label class="grid gap-2 text-sm font-semibold text-slate-700">Police Baslangic Tarihi
          <input type="date" class="h-11 rounded-lg border border-slate-200 px-3 text-sm font-normal" name="policy_start_date" value="<?= e($record['policy_start_date']) ?>">
        </label>
        <label class="grid gap-2 text-sm font-semibold text-slate-700">Police Bitis Tarihi
          <input type="date" class="h-11 rounded-lg border border-slate-200 px-3 text-sm font-normal" name="policy_end_date" value="<?= e($record['policy_end_date']) ?>">
          <?php if (!empty($record['policy_reminder_sent_at'])): ?>
            <span class="text-xs font-normal text-slate-500">Son hatirlatma: <?= e($record['policy_reminder_sent_at']) ?></span>
          <?php endif; ?>
        </label>

        <fieldset class="md:col-span-2 grid gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4">
          <legend class="px-2 text-sm font-bold text-slate-800">Yeni belge / fotograf ekle</legend>
          <p class="text-xs text-slate-500">Maks. 10 MB / dosya.</p>
          <div id="attach-rows" class="grid gap-3"></div>
          <div>
            <button type="button" id="attach-add-row" class="inline-flex h-9 items-center justify-center rounded-lg border border-slate-300 bg-white px-3 text-xs font-semibold text-slate-700 hover:bg-slate-100">+ Satir ekle</button>
          </div>
        </fieldset>

        <div class="md:col-span-2 flex flex-wrap items-center justify-end gap-3 border-t border-slate-200 pt-5">
          <a class="inline-flex h-11 items-center justify-center rounded-lg border border-slate-200 px-4 text-sm font-semibold text-slate-700" href="<?= e(panel_url('index.php')) ?>">Vazgec</a>
          <button class="inline-flex h-11 items-center justify-center rounded-lg bg-blue-600 px-6 text-sm font-bold text-white" type="submit">Kaydet</button>
        </div>
      </form>
      </div>
    </section>

    <section class="mt-6 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
      <div class="border-b border-slate-200 bg-slate-50 px-6 py-4 flex items-center justify-between">
        <h3 class="text-lg font-bold text-slate-950">Mevcut belgeler & fotograflar</h3>
        <span class="text-xs text-slate-500"><?= count($attachments) ?> dosya</span>
      </div>
      <div class="px-6 py-4">
        <div class="mb-4 flex flex-wrap gap-2">
          <a href="<?= e(panel_url('edit.php?id=' . $id)) ?>" class="<?= $categoryFilter === '' ? 'border-blue-200 bg-blue-50 text-blue-700' : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50' ?> inline-flex items-center gap-2 rounded-full border px-3 py-1.5 text-xs font-semibold">Tumu</a>
          <?php foreach ($categories as $key => $label): ?>
            <a href="<?= e(panel_url('edit.php?id=' . $id . '&cat=' . $key)) ?>" class="<?= $categoryFilter === $key ? 'border-blue-200 bg-blue-50 text-blue-700' : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50' ?> inline-flex items-center gap-2 rounded-full border px-3 py-1.5 text-xs font-semibold"><?= e($label) ?></a>
          <?php endforeach; ?>
        </div>
        <?php if ($attachments === []): ?>
          <p class="text-sm text-slate-500">Bu kayit icin yuklenmis dosya yok.</p>
        <?php else: ?>
          <table class="w-full text-sm">
            <thead><tr class="text-left text-xs uppercase text-slate-500"><th class="py-2">Kategori</th><th>Dosya</th><th>Boyut</th><th>Yuklendi</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($attachments as $att): ?>
                <tr class="border-t border-slate-100">
                  <td class="py-2"><span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-700"><?= e(attachment_category_label($att['category'])) ?></span></td>
                  <td class="py-2"><?= e($att['original_name']) ?></td>
                  <td class="py-2"><?= e(attachment_format_size((int)$att['file_size'])) ?></td>
                  <td class="py-2 text-xs text-slate-500"><?= e($att['uploaded_at']) ?></td>
                  <td class="py-2 text-right">
                    <a class="text-blue-700 hover:underline" href="<?= e(panel_url('download_attachment.php?id=' . (int)$att['id'])) ?>">Indir</a>
                    <form method="post" action="<?= e(panel_url('delete_attachment.php')) ?>" class="inline" onsubmit="return confirm('Dosyayi sil?');">
                      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                      <input type="hidden" name="id" value="<?= (int)$att['id'] ?>">
                      <input type="hidden" name="record_id" value="<?= (int)$id ?>">
                      <input type="hidden" name="redirect" value="edit">
                      <button class="ml-3 text-red-600 hover:underline" type="submit">Sil</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </section>
  </main>

  <script>
  (function() {
    const wrap = document.getElementById('attach-rows');
    const btn = document.getElementById('attach-add-row');
    const cats = <?= json_encode($categories, JSON_UNESCAPED_UNICODE) ?>;
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
    row();
  })();
  </script>
</body>
</html>
