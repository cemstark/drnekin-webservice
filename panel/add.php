<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_login();

ensure_excel_updates_table();

$error = '';
$created = false;

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
    ];

    if ($fields['plate'] === '' || $fields['customer_name'] === '' || $fields['service_entry_date'] === null) {
        $error = 'Plaka, ad soyad ve giris tarihi zorunludur.';
    } else {
        $fields['repair_status'] = $fields['repair_status'] ?: 'Belirtilmedi';
        $recordNo = add_record_no($fields['plate'], $fields['service_entry_date']);
        $insert = db()->prepare(
            'INSERT INTO service_records
             (record_no, plate, customer_name, insurance_company, repair_status, mini_repair_has, mini_repair_part, service_entry_date, service_exit_date, service_month, updated_at)
             VALUES
             (:record_no, :plate, :customer_name, :insurance_company, :repair_status, :mini_repair_has, :mini_repair_part, :service_entry_date, :service_exit_date, :service_month, NOW())'
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
            ':service_month' => substr((string)$fields['service_entry_date'], 0, 7),
        ]);

        $queuedFields = $fields;
        $queuedFields['_action'] = 'append';
        $queue = db()->prepare('INSERT INTO pending_excel_updates (record_no, fields_json, created_by) VALUES (?, ?, ?)');
        $queue->execute([
            $recordNo,
            json_encode($queuedFields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            current_user()['id'] ?? null,
        ]);

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

  <main class="layout narrow">
    <section class="table-card form-card">
      <h2>Yeni arac bilgisi</h2>
      <?php if ($created): ?>
        <div class="success">Arac eklendi. Excel'e yeni satir olarak yazilmak uzere kuyruga alindi.</div>
      <?php endif; ?>
      <?php if ($error !== ''): ?>
        <div class="alert"><?= e($error) ?></div>
      <?php endif; ?>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <label>Plaka <input name="plate" value="<?= e($fields['plate']) ?>" required></label>
        <label>Ad Soyad <input name="customer_name" value="<?= e($fields['customer_name']) ?>" required></label>
        <label>Sigorta <input name="insurance_company" value="<?= e($fields['insurance_company']) ?>"></label>
        <label>Tamir Durumu <input name="repair_status" value="<?= e($fields['repair_status']) ?>"></label>
        <label class="check-row"><input type="checkbox" name="mini_repair_has" <?= (int)$fields['mini_repair_has'] === 1 ? 'checked' : '' ?>> Mini onarim var</label>
        <label>Mini Onarim Parca <input name="mini_repair_part" value="<?= e($fields['mini_repair_part']) ?>"></label>
        <label>Giris Tarihi <input type="date" name="service_entry_date" value="<?= e($fields['service_entry_date']) ?>" required></label>
        <label>Cikis Tarihi <input type="date" name="service_exit_date" value="<?= e($fields['service_exit_date']) ?>"></label>
        <button type="submit">Araci ekle</button>
      </form>
    </section>
  </main>
</body>
</html>
