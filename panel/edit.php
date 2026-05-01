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
        $resetPolicyReminder = edit_policy_reminder_column_exists()
            && ((string)($record['policy_end_date'] ?? '') !== (string)($fields['policy_end_date'] ?? ''));
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
             . ($resetPolicyReminder ? ', policy_reminder_sent_at = NULL' : '')
             . ' WHERE id = :id';
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

$activePage = 'panel';
$pageTitle = 'Duzenle ' . $record['plate'];
include __DIR__ . '/includes/_layout_head.php';
?>
  <main class="content">
    <header class="page-head">
      <div class="title-block">
        <div class="eyebrow">Kayit Duzenleme</div>
        <h1><?= e($record['plate']) ?> <span style="color:var(--muted);font-weight:600">&middot;</span> <span style="color:var(--text-2);font-weight:700"><?= e($record['customer_name']) ?></span></h1>
        <p class="sub">Kayit guncellemeleri Excel kuyruguna yazilir.</p>
      </div>
      <div class="actions">
        <a class="btn-ghost" href="<?= e(panel_url('view.php?id=' . $id)) ?>">Detay</a>
        <a class="btn-ghost" href="<?= e(panel_url('index.php')) ?>">Panele don</a>
      </div>
    </header>

    <section class="card" style="overflow:hidden">
      <div class="card-pad-lg">
        <?php if ($saved): ?>
          <div class="banner is-ok">
            <span class="ico"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>
            <div><h3>Kayit guncellendi</h3><p>Excel senkron kuyruguna eklendi.</p></div>
          </div>
        <?php endif; ?>
        <?php if ($uploadWarnings): ?>
          <div class="banner is-warn">
            <span class="ico"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg></span>
            <div>
              <h3>Bazi dosyalar yuklenemedi</h3>
              <ul style="margin:6px 0 0 16px;font-size:13px;font-weight:500"><?php foreach ($uploadWarnings as $w): ?><li><?= e($w) ?></li><?php endforeach; ?></ul>
            </div>
          </div>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
          <div class="banner is-danger">
            <span class="ico"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg></span>
            <div><h3>Hata</h3><p><?= e($error) ?></p></div>
          </div>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data" class="form-grid" style="margin-top:8px">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

          <fieldset class="form-section full" style="display:grid;gap:14px;grid-template-columns:repeat(2,minmax(0,1fr))">
            <legend>Arac &amp; Musteri</legend>
            <label class="field"><span class="label">Plaka</span><input name="plate" value="<?= e($record['plate']) ?>" required></label>
            <label class="field"><span class="label">Ad Soyad</span><input name="customer_name" value="<?= e($record['customer_name']) ?>" required></label>
            <label class="field full" style="grid-column:1/-1"><span class="label">Sigorta Sirketi</span><input name="insurance_company" value="<?= e($record['insurance_company']) ?>"></label>
          </fieldset>

          <fieldset class="form-section full" style="display:grid;gap:14px;grid-template-columns:repeat(2,minmax(0,1fr))">
            <legend>Servis Durumu</legend>
            <label class="field"><span class="label">Tamir Durumu</span><input name="repair_status" value="<?= e($record['repair_status']) ?>"></label>
            <label class="check-row"><input type="checkbox" name="mini_repair_has" <?= (int)$record['mini_repair_has'] === 1 ? 'checked' : '' ?>> Mini onarim var</label>
            <label class="field full" style="grid-column:1/-1"><span class="label">Mini Onarim Parca</span><input name="mini_repair_part" value="<?= e($record['mini_repair_part']) ?>"></label>
            <label class="field"><span class="label">Giris Tarihi</span><input type="date" name="service_entry_date" value="<?= e($record['service_entry_date']) ?>" required></label>
            <label class="field"><span class="label">Cikis Tarihi</span><input type="date" name="service_exit_date" value="<?= e($record['service_exit_date']) ?>"></label>
          </fieldset>

          <fieldset class="form-section full" style="display:grid;gap:14px;grid-template-columns:repeat(2,minmax(0,1fr))">
            <legend>Police</legend>
            <label class="field"><span class="label">Police Baslangic</span><input type="date" name="policy_start_date" value="<?= e($record['policy_start_date'] ?? '') ?>"></label>
            <label class="field"><span class="label">Police Bitis</span><input type="date" name="policy_end_date" value="<?= e($record['policy_end_date'] ?? '') ?>"></label>
          </fieldset>

          <fieldset class="form-section full">
            <legend>Belge / Fotograf Ekle (opsiyonel)</legend>
            <p class="hint" style="margin:0 0 10px">Maks. 10 MB / dosya. PDF, JPG, PNG, WebP, DOCX, XLSX desteklenir.</p>
            <div id="attach-rows" style="display:grid;gap:10px"></div>
            <button type="button" id="attach-add-row" class="btn-ghost btn-sm" style="margin-top:10px">+ Satir ekle</button>
          </fieldset>

          <div class="full" style="display:flex;justify-content:flex-end;gap:10px;padding-top:8px">
            <a class="btn-ghost" href="<?= e(panel_url('view.php?id=' . $id)) ?>">Vazgec</a>
            <button type="submit">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
              Kaydet
            </button>
          </div>
        </form>

        <?php if ($attachmentsAvailable && $attachments !== []): ?>
          <section style="margin-top:28px">
            <div class="card-head" style="padding:0 0 14px;border-bottom:1px solid var(--border)">
              <h2>Mevcut Belgeler &amp; Fotograflar</h2>
              <a class="btn-soft" href="<?= e(panel_url('download_all.php?id=' . $id)) ?>">Tumunu ZIP indir</a>
            </div>
            <div class="table-wrap" style="margin-top:8px">
              <table>
                <thead><tr><th>Kategori</th><th>Dosya</th><th>Boyut</th><th>Yuklendi</th><th></th></tr></thead>
                <tbody>
                  <?php foreach ($attachments as $att): ?>
                    <tr>
                      <td><span class="status-pill is-muted"><?= e(attachment_category_label($att['category'])) ?></span></td>
                      <td><?= e($att['original_name']) ?></td>
                      <td><?= e(attachment_format_size((int)$att['file_size'])) ?></td>
                      <td style="color:var(--muted);font-size:12.5px"><?= e($att['uploaded_at']) ?></td>
                      <td style="text-align:right;white-space:nowrap">
                        <?php if (attachment_can_preview($att['mime_type'])): ?>
                          <a style="color:var(--text-2);font-weight:700;font-size:12.5px;margin-right:10px" href="<?= e(panel_url('download_attachment.php?id=' . (int)$att['id'] . '&inline=1')) ?>" target="_blank" rel="noopener">Goruntule</a>
                        <?php endif; ?>
                        <a style="color:var(--brand-2);font-weight:700;font-size:12.5px" href="<?= e(panel_url('download_attachment.php?id=' . (int)$att['id'])) ?>">Indir</a>
                        <form method="post" action="<?= e(panel_url('delete_attachment.php')) ?>" style="display:inline;margin-left:10px" onsubmit="return confirm('Dosyayi sil?');">
                          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                          <input type="hidden" name="id" value="<?= (int)$att['id'] ?>">
                          <input type="hidden" name="record_id" value="<?= (int)$id ?>">
                          <button type="submit" style="color:var(--danger);background:transparent;border:none;cursor:pointer;font-size:12.5px;font-weight:700;box-shadow:none;padding:0;min-height:auto">Sil</button>
                        </form>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </section>
        <?php endif; ?>
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
    row();
  })();
  </script>
<?php include __DIR__ . '/includes/_layout_foot.php'; ?>
