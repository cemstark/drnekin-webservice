<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/policy_runner.php';
require_once __DIR__ . '/includes/options.php';
require_login();
fire_policy_reminder_async();

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

function index_insurance_type_column_exists(PDO $pdo): bool
{
    static $exists = null;
    if ($exists !== null) {
        return $exists;
    }

    try {
        $pdo->query('SELECT insurance_type FROM service_records LIMIT 0');
        $exists = true;
    } catch (Throwable $e) {
        $exists = false;
    }

    return $exists;
}

$pdo = db();
$month = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['month'] ?? '')) ? (string)$_GET['month'] : '';
$status = trim((string)($_GET['status'] ?? ''));
$type = valid_insurance_type($_GET['type'] ?? null) ? (string)$_GET['type'] : '';
$insurance = trim((string)($_GET['insurance'] ?? ''));
$q = trim((string)($_GET['q'] ?? ''));
$hasInsuranceType = index_insurance_type_column_exists($pdo);
if (!$hasInsuranceType) {
    $type = '';
}

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
if ($hasInsuranceType && $type !== '') {
    $where[] = 'insurance_type = ?';
    $params[] = $type;
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
$statusesFromDb = $pdo->query('SELECT repair_status FROM service_records WHERE repair_status <> "" GROUP BY repair_status ORDER BY repair_status')->fetchAll(PDO::FETCH_COLUMN);
$statuses = array_values(array_unique(array_merge(array_keys(repair_status_options()), $statusesFromDb)));
if ($hasInsuranceType && $type !== '') {
    $insuranceStmt = $pdo->prepare('SELECT insurance_company, COUNT(*) total FROM service_records WHERE insurance_company <> "" AND insurance_type = ? GROUP BY insurance_company ORDER BY insurance_company');
    $insuranceStmt->execute([$type]);
    $insurances = $insuranceStmt->fetchAll();
} else {
    $insurances = $pdo->query('SELECT insurance_company, COUNT(*) total FROM service_records WHERE insurance_company <> "" GROUP BY insurance_company ORDER BY insurance_company')->fetchAll();
}
$summary = $pdo->query('SELECT COUNT(*) total, SUM(service_exit_date IS NULL) open_count, SUM(mini_repair_has = 1) mini_count FROM service_records')->fetch();
$lastImport = $pdo->query('SELECT * FROM import_logs ORDER BY created_at DESC LIMIT 1')->fetch();

// Police bitisi yaklasanlar (migration calistirilmadiysa sessizce atla)
$policyExpiringSoon = [];
try {
    $pq = $pdo->query(
        "SELECT id, plate, customer_name, insurance_company, policy_end_date,
                DATEDIFF(policy_end_date, CURDATE()) AS days_left
         FROM service_records
         WHERE policy_end_date IS NOT NULL
           AND DATEDIFF(policy_end_date, CURDATE()) BETWEEN 0 AND 30
         ORDER BY policy_end_date ASC
         LIMIT 50"
    );
    $policyExpiringSoon = $pq ? $pq->fetchAll() : [];
} catch (Throwable $e) {
    $policyExpiringSoon = [];
}
?>
<!doctype html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e(panel_config('app_name')) ?></title>
  <link rel="stylesheet" href="<?= e(panel_url('assets/panel.css')) ?>">
  <script src="https://cdn.tailwindcss.com"></script>
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
      <a href="<?= e(panel_url('install/migrate.php')) ?>">Migration</a>
      <a href="<?= e(panel_url('logout.php')) ?>">Cikis</a>
    </nav>
  </header>

  <main class="mx-auto w-full max-w-[1420px] px-4 py-6 sm:px-6 lg:px-8">
    <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
      <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">Toplam arac sayisi</span>
        <strong class="mt-2 block text-3xl font-bold text-slate-950"><?= e((int)($summary['total'] ?? 0)) ?></strong>
      </div>
      <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">Serviste</span>
        <strong class="mt-2 block text-3xl font-bold text-slate-950"><?= e((int)($summary['open_count'] ?? 0)) ?></strong>
      </div>
      <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">Mini onarim</span>
        <strong class="mt-2 block text-3xl font-bold text-slate-950"><?= e((int)($summary['mini_count'] ?? 0)) ?></strong>
      </div>
      <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">Son senkron</span>
        <strong class="mt-2 block text-xl font-bold text-slate-950"><?= e(format_tr_datetime($lastImport['created_at'] ?? null)) ?></strong>
      </div>
    </section>

    <?php if ($policyExpiringSoon !== []): ?>
      <section class="mt-5 overflow-hidden rounded-xl border border-amber-200 bg-amber-50 shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-amber-200 px-4 py-3">
          <div>
            <h2 class="text-sm font-bold text-amber-900">Police bitisi yaklasan araclar</h2>
            <p class="text-xs text-amber-800">Bugun ile 30 gun arasinda biten policeler.</p>
          </div>
          <span class="rounded-full bg-amber-200 px-3 py-1 text-xs font-bold text-amber-900"><?= count($policyExpiringSoon) ?> arac</span>
        </div>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="text-left text-xs uppercase text-amber-900">
                <th class="px-4 py-2">Plaka</th>
                <th class="px-4 py-2">Musteri</th>
                <th class="px-4 py-2">Sigorta</th>
                <th class="px-4 py-2">Bitis</th>
                <th class="px-4 py-2">Kalan</th>
                <th class="px-4 py-2"></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($policyExpiringSoon as $p):
                $d = (int)$p['days_left'];
                $rowClass = $d <= 7 ? 'text-red-700 font-semibold' : 'text-amber-900';
              ?>
                <tr class="border-t border-amber-100 <?= $rowClass ?>">
                  <td class="px-4 py-2 font-bold"><?= e($p['plate']) ?></td>
                  <td class="px-4 py-2"><?= e($p['customer_name']) ?></td>
                  <td class="px-4 py-2"><?= e($p['insurance_company'] ?: '-') ?></td>
                  <td class="px-4 py-2"><?= e(format_tr_date($p['policy_end_date'])) ?></td>
                  <td class="px-4 py-2"><?= e($d) ?> gun</td>
                  <td class="px-4 py-2 text-right">
                    <a class="rounded-md border border-amber-300 bg-white px-2 py-1 text-xs font-semibold text-amber-900 hover:bg-amber-100" href="<?= e(panel_url('view.php?id=' . (int)$p['id'])) ?>">Detay</a>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>
    <?php endif; ?>

    <section class="mt-5 rounded-xl border border-slate-200 bg-white p-4 shadow-sm" aria-label="Arac filtreleri">
      <div class="mb-3 flex items-center justify-between gap-3">
        <div>
          <h2 class="text-sm font-semibold text-slate-950">Arac filtreleri</h2>
          <p class="text-xs text-slate-500">Kasko, trafik, filo ve sigorta sirketine gore hizli filtrele</p>
        </div>
        <?php if ($insurance !== '' || $type !== ''): ?>
          <a class="rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-50" href="<?= e(index_url(['insurance' => null, 'type' => null])) ?>">Filtreyi kaldir</a>
        <?php endif; ?>
      </div>
      <div class="flex flex-wrap gap-2 border-b border-slate-100 pb-3">
        <a class="<?= $type === '' && $insurance === '' ? 'border-blue-200 bg-blue-50 text-blue-700' : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50' ?> inline-flex items-center gap-2 rounded-full border px-3 py-2 text-sm font-semibold" href="<?= e(index_url(['type' => null, 'insurance' => null])) ?>">
          Tum araclar
        </a>
        <?php foreach (insurance_type_options() as $key => $label): ?>
          <a class="<?= $type === $key ? 'border-blue-200 bg-blue-50 text-blue-700' : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50' ?> inline-flex items-center gap-2 rounded-full border px-3 py-2 text-sm font-semibold" href="<?= e(index_url(['type' => $key, 'insurance' => null])) ?>">
            <?= e($label) ?>
          </a>
        <?php endforeach; ?>
      </div>
      <div class="mt-3 flex flex-wrap gap-2">
        <?php foreach ($insurances as $item): ?>
          <a class="<?= $insurance === $item['insurance_company'] ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50' ?> inline-flex items-center gap-2 rounded-full border px-3 py-2 text-sm font-semibold" href="<?= e(index_url(['insurance' => $item['insurance_company']])) ?>">
            <?= e($item['insurance_company']) ?>
            <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-500"><?= e($item['total']) ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    </section>

    <form class="mt-4 grid gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm lg:grid-cols-[minmax(260px,1.4fr)_minmax(180px,1fr)_minmax(180px,1fr)_auto_auto]" method="get">
      <?php if ($insurance !== ''): ?>
        <input type="hidden" name="insurance" value="<?= e($insurance) ?>">
      <?php endif; ?>
      <?php if ($type !== ''): ?>
        <input type="hidden" name="type" value="<?= e($type) ?>">
      <?php endif; ?>
      <input class="h-11 rounded-lg border border-slate-200 bg-white px-3 text-sm outline-none transition focus:border-blue-500 focus:ring-4 focus:ring-blue-100" name="q" value="<?= e($q) ?>" placeholder="Plaka veya isim ara">
      <select class="h-11 rounded-lg border border-slate-200 bg-white px-3 text-sm outline-none transition focus:border-blue-500 focus:ring-4 focus:ring-blue-100" name="month">
        <option value="">Tum aylar</option>
        <?php foreach ($months as $item): ?>
          <option value="<?= e($item['service_month']) ?>" <?= $month === $item['service_month'] ? 'selected' : '' ?>>
            <?= e(month_label($item['service_month'])) ?> (<?= e($item['total']) ?>)
          </option>
        <?php endforeach; ?>
      </select>
      <select class="h-11 rounded-lg border border-slate-200 bg-white px-3 text-sm outline-none transition focus:border-blue-500 focus:ring-4 focus:ring-blue-100" name="status">
        <option value="">Tum durumlar</option>
        <?php foreach ($statuses as $item): ?>
          <option value="<?= e($item) ?>" <?= $status === $item ? 'selected' : '' ?>><?= e($item) ?></option>
        <?php endforeach; ?>
      </select>
      <button class="h-11 rounded-lg bg-blue-600 px-6 text-sm font-bold text-white transition hover:bg-blue-700" type="submit">Filtrele</button>
      <a class="inline-flex h-11 items-center justify-center rounded-lg border border-slate-200 px-4 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" href="<?= e(panel_url('index.php')) ?>">Temizle</a>
    </form>

    <section class="table-card mt-4">
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
              <th>Arac Filtresi</th>
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
                <td><span class="type-badge"><?= e(insurance_type_label($record['insurance_type'] ?? 'kasko')) ?></span></td>
                <td><?= e($record['insurance_company'] ?: '-') ?></td>
                <td><span class="pill <?= e(repair_status_tone((string)$record['repair_status'])) ?>"><?= e($record['repair_status']) ?></span></td>
                <td><?= ((int)$record['mini_repair_has'] === 1) ? e($record['mini_repair_part'] ?: 'Var') : 'Yok' ?></td>
                <td><?= e(format_tr_date($record['service_entry_date'])) ?></td>
                <td><?= e(format_tr_date($record['service_exit_date'] ?? null)) ?></td>
                <td class="whitespace-nowrap">
                  <a class="inline-flex items-center rounded-md border border-slate-200 bg-white px-2 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-50" href="<?= e(panel_url('view.php?id=' . (int)$record['id'])) ?>">Detay</a>
                  <a class="ml-1 inline-flex items-center rounded-md border border-blue-200 bg-blue-50 px-2 py-1 text-xs font-semibold text-blue-700 hover:bg-blue-100" href="<?= e(panel_url('edit.php?id=' . (int)$record['id'])) ?>">Duzenle</a>
                </td>
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
