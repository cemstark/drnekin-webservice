<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
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

        $stmt->execute([$id]);
        $record = $stmt->fetch();
        $saved = true;
    }
}
?>
<!doctype html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Kayit Duzenle - <?= e(panel_config('app_name')) ?></title>
  <link rel="stylesheet" href="<?= e(panel_url('assets/panel.css')) ?>">
</head>
<body>
  <header class="topbar">
    <div>
      <div class="eyebrow">DRN</div>
      <h1>Kayit Duzenle</h1>
    </div>
    <nav>
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
      <?php if ($error !== ''): ?>
        <div class="alert"><?= e($error) ?></div>
      <?php endif; ?>
      <form method="post">
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
        <button type="submit">Kaydet</button>
      </form>
    </section>
  </main>
</body>
</html>
