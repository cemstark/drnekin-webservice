<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_login();

function month_label(?string $month): string
{
    if (!is_string($month) || !preg_match('/^(\d{4})-(\d{2})$/', $month, $matches)) {
        return '-';
    }

    $names = [
        '01' => 'Ocak', '02' => 'Subat', '03' => 'Mart', '04' => 'Nisan',
        '05' => 'Mayis', '06' => 'Haziran', '07' => 'Temmuz', '08' => 'Agustos',
        '09' => 'Eylul', '10' => 'Ekim', '11' => 'Kasim', '12' => 'Aralik',
    ];

    return ($names[$matches[2]] ?? $matches[2]) . ' ' . $matches[1];
}

function index_url(array $overrides = []): string
{
    $params = array_merge($_GET, $overrides);
    foreach ($params as $key => $value) {
        if ($value === '' || $value === null) {
            unset($params[$key]);
        }
    }

    $query = http_build_query($params);
    return panel_url('index.php' . ($query !== '' ? '?' . $query : ''));
}

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
    $where[] = '(plate LIKE ? OR customer_name LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like);
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$stmt = $pdo->prepare("SELECT * FROM service_records $whereSql ORDER BY service_entry_date DESC, updated_at DESC LIMIT 500");
$stmt->execute($params);
$records = $stmt->fetchAll();

$months = $pdo->query('SELECT service_month, COUNT(*) total FROM service_records GROUP BY service_month ORDER BY service_month DESC LIMIT 24')->fetchAll();
$statuses = $pdo->query('SELECT repair_status FROM service_records WHERE repair_status <> "" GROUP BY repair_status ORDER BY repair_status')->fetchAll(PDO::FETCH_COLUMN);
$insurances = $pdo->query('SELECT insurance_company, COUNT(*) total FROM service_records WHERE insurance_company <> "" GROUP BY insurance_company ORDER BY insurance_company')->fetchAll();
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
      <a href="<?= e(panel_url('add.php')) ?>">Arac ekle</a>
      <a href="<?= e(panel_url('import.php')) ?>">Excel yukle</a>
      <a href="<?= e(panel_url('logout.php')) ?>">Cikis</a>
    </nav>
  </header>

  <main class="layout">
    <section class="metrics">
      <div><strong><?= e((int)($summary['total'] ?? 0)) ?></strong><span>Toplam arac sayisi</span></div>
      <div><strong><?= e((int)($summary['open_count'] ?? 0)) ?></strong><span>Serviste</span></div>
      <div><strong><?= e((int)($summary['mini_count'] ?? 0)) ?></strong><span>Mini onarim</span></div>
      <div><strong><?= e($lastImport['created_at'] ?? '-') ?></strong><span>Son senkron</span></div>
    </section>

    <section class="insurance-tabs" aria-label="Sigorta filtreleri">
      <a class="<?= $insurance === '' ? 'active' : '' ?>" href="<?= e(index_url(['insurance' => null])) ?>">Tum sigortalar</a>
      <?php foreach ($insurances as $item): ?>
        <a class="<?= $insurance === $item['insurance_company'] ? 'active' : '' ?>" href="<?= e(index_url(['insurance' => $item['insurance_company']])) ?>">
          <?= e($item['insurance_company']) ?>
          <span><?= e($item['total']) ?></span>
        </a>
      <?php endforeach; ?>
    </section>

    <form class="filters" method="get">
      <?php if ($insurance !== ''): ?>
        <input type="hidden" name="insurance" value="<?= e($insurance) ?>">
      <?php endif; ?>
      <input name="q" value="<?= e($q) ?>" placeholder="Plaka veya isim ara">
      <select name="month">
        <option value="">Tum aylar</option>
        <?php foreach ($months as $item): ?>
          <option value="<?= e($item['service_month']) ?>" <?= $month === $item['service_month'] ? 'selected' : '' ?>>
            <?= e(month_label($item['service_month'])) ?> (<?= e($item['total']) ?>)
          </option>
        <?php endforeach; ?>
      </select>
      <select name="status">
        <option value="">Tum durumlar</option>
        <?php foreach ($statuses as $item): ?>
          <option value="<?= e($item) ?>" <?= $status === $item ? 'selected' : '' ?>><?= e($item) ?></option>
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
              <tr><td colspan="8" class="empty">Filtreye uygun kayit bulunamadi.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>
  </main>
</body>
</html>
