<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_login();

$pdo = db();
$month = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['month'] ?? '')) ? (string)$_GET['month'] : '';
$status = trim((string)($_GET['status'] ?? ''));
$insurance = trim((string)($_GET['insurance'] ?? ''));
$q = trim((string)($_GET['q'] ?? ''));

$where = [];
$params = [];
if ($month !== '') {
    $where[] = 'service_month = ?';
    $params[] = $month;
}
if ($status !== '') {
    $where[] = 'repair_status = ?';
    $params[] = $status;
}
if ($insurance !== '') {
    $where[] = 'insurance_company = ?';
    $params[] = $insurance;
}
if ($q !== '') {
    $where[] = '(record_no LIKE ? OR plate LIKE ? OR customer_name LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like);
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$stmt = $pdo->prepare("SELECT * FROM service_records $whereSql ORDER BY service_entry_date DESC, updated_at DESC LIMIT 500");
$stmt->execute($params);
$records = $stmt->fetchAll();

$months = $pdo->query('SELECT service_month, COUNT(*) total FROM service_records GROUP BY service_month ORDER BY service_month DESC LIMIT 24')->fetchAll();
$statuses = $pdo->query('SELECT repair_status FROM service_records WHERE repair_status <> "" GROUP BY repair_status ORDER BY repair_status')->fetchAll(PDO::FETCH_COLUMN);
$insurances = $pdo->query('SELECT insurance_company FROM service_records WHERE insurance_company <> "" GROUP BY insurance_company ORDER BY insurance_company')->fetchAll(PDO::FETCH_COLUMN);
$summary = $pdo->query('SELECT COUNT(*) total, SUM(service_exit_date IS NULL) open_count, SUM(mini_repair_has = 1) mini_count FROM service_records')->fetch();
$lastImport = $pdo->query('SELECT * FROM import_logs ORDER BY created_at DESC LIMIT 1')->fetch();
?>
<!doctype html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e(panel_config('app_name')) ?></title>
  <link rel="stylesheet" href="<?= e(panel_url('assets/panel.css')) ?>">
</head>
<body>
  <header class="topbar">
    <div>
      <div class="eyebrow">DRN</div>
      <h1>Servis Paneli</h1>
    </div>
    <nav>
      <span><?= e(current_user()['full_name'] ?: current_user()['username']) ?></span>
      <a href="<?= e(panel_url('import.php')) ?>">Excel yukle</a>
      <a href="<?= e(panel_url('logout.php')) ?>">Cikis</a>
    </nav>
  </header>

  <main class="layout">
    <section class="metrics">
      <div><strong><?= e((int)($summary['total'] ?? 0)) ?></strong><span>Toplam kayit</span></div>
      <div><strong><?= e((int)($summary['open_count'] ?? 0)) ?></strong><span>Serviste</span></div>
      <div><strong><?= e((int)($summary['mini_count'] ?? 0)) ?></strong><span>Mini onarim</span></div>
      <div><strong><?= e($lastImport['created_at'] ?? '-') ?></strong><span>Son senkron</span></div>
    </section>

    <form class="filters" method="get">
      <input name="q" value="<?= e($q) ?>" placeholder="Plaka, kayit no veya isim ara">
      <select name="month">
        <option value="">Tum aylar</option>
        <?php foreach ($months as $item): ?>
          <option value="<?= e($item['service_month']) ?>" <?= $month === $item['service_month'] ? 'selected' : '' ?>>
            <?= e($item['service_month']) ?> (<?= e($item['total']) ?>)
          </option>
        <?php endforeach; ?>
      </select>
      <select name="status">
        <option value="">Tum durumlar</option>
        <?php foreach ($statuses as $item): ?>
          <option value="<?= e($item) ?>" <?= $status === $item ? 'selected' : '' ?>><?= e($item) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="insurance">
        <option value="">Tum sigortalar</option>
        <?php foreach ($insurances as $item): ?>
          <option value="<?= e($item) ?>" <?= $insurance === $item ? 'selected' : '' ?>><?= e($item) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit">Filtrele</button>
      <a class="ghost" href="<?= e(panel_url('index.php')) ?>">Temizle</a>
    </form>

    <section class="table-card">
      <div class="table-head">
        <h2>Arac kayitlari</h2>
        <span><?= count($records) ?> kayit gosteriliyor</span>
      </div>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Kayit No</th>
              <th>Plaka</th>
              <th>Ad Soyad</th>
              <th>Sigorta</th>
              <th>Tamir Durumu</th>
              <th>Mini Onarim</th>
              <th>Giris</th>
              <th>Cikis</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($records as $record): ?>
              <tr>
                <td><?= e($record['record_no']) ?></td>
                <td><strong><?= e($record['plate']) ?></strong></td>
                <td><?= e($record['customer_name']) ?></td>
                <td><?= e($record['insurance_company'] ?: '-') ?></td>
                <td><span class="pill"><?= e($record['repair_status']) ?></span></td>
                <td><?= ((int)$record['mini_repair_has'] === 1) ? e($record['mini_repair_part'] ?: 'Var') : 'Yok' ?></td>
                <td><?= e($record['service_entry_date']) ?></td>
                <td><?= e($record['service_exit_date'] ?: '-') ?></td>
                <td><a href="<?= e(panel_url('edit.php?id=' . (int)$record['id'])) ?>">Duzenle</a></td>
              </tr>
            <?php endforeach; ?>
            <?php if ($records === []): ?>
              <tr><td colspan="9" class="empty">Filtreye uygun kayit bulunamadi.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>
  </main>
</body>
</html>
