<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/policy_runner.php';
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

/**
 * @param array<string,mixed> $record
 * @return array{label:string,badge:string,dot:string}
 */
function vehicle_status_meta(array $record): array
{
    $raw = strtolower((string)($record['repair_status'] ?? ''));

    if (!empty($record['service_exit_date'])) {
        return ['label' => 'Cikis Yapildi', 'badge' => 'border-slate-200 bg-slate-100 text-slate-700', 'dot' => 'bg-slate-400'];
    }
    if (str_contains($raw, 'parca')) {
        return ['label' => 'Parca Bekliyor', 'badge' => 'border-amber-200 bg-amber-50 text-amber-700', 'dot' => 'bg-amber-500'];
    }
    if (str_contains($raw, 'hazir') || str_contains($raw, 'teslim')) {
        return ['label' => 'Cikisa Hazir', 'badge' => 'border-emerald-200 bg-emerald-50 text-emerald-700', 'dot' => 'bg-emerald-500'];
    }
    if ((string)($record['service_entry_date'] ?? '') === date('Y-m-d')) {
        return ['label' => 'Giris Yapti', 'badge' => 'border-blue-200 bg-blue-50 text-blue-700', 'dot' => 'bg-blue-500'];
    }

    return ['label' => 'Serviste', 'badge' => 'border-cyan-200 bg-cyan-50 text-cyan-700', 'dot' => 'bg-cyan-500'];
}

function safe_count(PDO $pdo, string $sql): int
{
    try {
        return (int)$pdo->query($sql)->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
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
    $where[] = '(plate LIKE ? OR customer_name LIKE ? OR insurance_company LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like);
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

$todayIncoming = safe_count($pdo, "SELECT COUNT(*) FROM service_records WHERE service_entry_date = CURDATE()");
$todayOutgoing = safe_count($pdo, "SELECT COUNT(*) FROM service_records WHERE service_exit_date = CURDATE()");
$approvalPending = safe_count($pdo, "SELECT COUNT(*) FROM service_records WHERE service_exit_date IS NULL AND (LOWER(insurance_company) LIKE '%axa%' OR LOWER(insurance_company) LIKE '%orient%' OR LOWER(repair_status) LIKE '%onay%')");
$readyForExit = safe_count($pdo, "SELECT COUNT(*) FROM service_records WHERE service_exit_date IS NULL AND (LOWER(repair_status) LIKE '%hazir%' OR LOWER(repair_status) LIKE '%teslim%')");

$policyExpiringSoon = [];
try {
    $pq = $pdo->query(
        "SELECT id, plate, customer_name, insurance_company, policy_end_date,
                DATEDIFF(policy_end_date, CURDATE()) AS days_left
         FROM service_records
         WHERE policy_end_date IS NOT NULL
           AND DATEDIFF(policy_end_date, CURDATE()) BETWEEN 0 AND 30
         ORDER BY policy_end_date ASC
         LIMIT 8"
    );
    $policyExpiringSoon = $pq ? $pq->fetchAll() : [];
} catch (Throwable $e) {
    $policyExpiringSoon = [];
}

$galleryItems = [];
try {
    $gallery = $pdo->query(
        "SELECT a.id, a.original_name, a.category, a.mime_type, a.uploaded_at,
                r.id AS record_id, r.plate, r.customer_name
         FROM service_attachments a
         INNER JOIN service_records r ON r.id = a.record_id
         WHERE a.mime_type LIKE 'image/%'
         ORDER BY a.uploaded_at DESC
         LIMIT 6"
    );
    $galleryItems = $gallery ? $gallery->fetchAll() : [];
} catch (Throwable $e) {
    $galleryItems = [];
}

$currentUser = current_user();
?>
<!doctype html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e(panel_config('app_name')) ?></title>
  <link rel="stylesheet" href="<?= e(panel_url('assets/panel.css')) ?>">
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="min-h-screen bg-[#eef3f8] text-slate-950">
  <div class="fixed inset-0 -z-10 bg-[radial-gradient(circle_at_top_left,rgba(14,165,233,.20),transparent_34%),radial-gradient(circle_at_85%_10%,rgba(16,185,129,.14),transparent_28%),linear-gradient(135deg,#f8fafc_0%,#e8eef6_48%,#f6f8fb_100%)]"></div>

  <div class="min-h-screen lg:grid lg:grid-cols-[280px_1fr]">
    <aside class="glass-panel sticky top-0 z-30 flex flex-col border-b border-white/50 px-4 py-4 lg:h-screen lg:border-b-0 lg:border-r lg:px-5 lg:py-6">
      <div class="flex items-center justify-between gap-3">
        <a href="<?= e(panel_url('index.php')) ?>" class="flex items-center gap-3">
          <span class="grid h-11 w-11 place-items-center rounded-2xl bg-slate-950 text-sm font-black text-white shadow-lg shadow-slate-900/20">DRN</span>
          <span>
            <span class="block text-sm font-black tracking-tight text-slate-950">Servis Paneli</span>
            <span class="block text-xs font-semibold text-slate-500">Filo & Sigorta Operasyon</span>
          </span>
        </a>
        <a class="lg:hidden rounded-xl border border-white/70 bg-white/70 px-3 py-2 text-xs font-bold text-slate-700" href="<?= e(panel_url('logout.php')) ?>">Cikis</a>
      </div>

      <nav class="mt-7 grid gap-2">
        <a class="nav-item active" href="<?= e(panel_url('index.php')) ?>"><i data-lucide="layout-dashboard"></i><span>Dashboard</span></a>
        <a class="nav-item" href="<?= e(panel_url('add.php')) ?>"><i data-lucide="car-front"></i><span>Arac Kabul</span></a>
        <a class="nav-item" href="<?= e(index_url(['insurance' => 'Axa'])) ?>"><i data-lucide="shield-check"></i><span>Filo/Sigorta Paneli</span></a>
        <a class="nav-item" href="<?= e(panel_url('import.php')) ?>"><i data-lucide="file-spreadsheet"></i><span>Raporlar</span></a>
        <a class="nav-item" href="<?= e(panel_url('install/migrate.php')) ?>"><i data-lucide="settings"></i><span>Ayarlar</span></a>
      </nav>

      <div class="mt-7 rounded-3xl border border-white/70 bg-white/50 p-4 shadow-sm">
        <div class="flex items-center gap-3">
          <span class="grid h-10 w-10 place-items-center rounded-2xl bg-blue-600 text-sm font-black text-white">
            <?= e(strtoupper(substr((string)($currentUser['full_name'] ?: $currentUser['username'] ?: 'A'), 0, 1))) ?>
          </span>
          <div class="min-w-0">
            <p class="truncate text-sm font-bold text-slate-900"><?= e($currentUser['full_name'] ?: $currentUser['username']) ?></p>
            <p class="text-xs font-semibold text-slate-500">Aktif oturum</p>
          </div>
        </div>
      </div>

      <div class="mt-auto hidden pt-6 lg:block">
        <a class="nav-item" href="<?= e(panel_url('logout.php')) ?>"><i data-lucide="log-out"></i><span>Cikis</span></a>
      </div>
    </aside>

    <main class="px-4 py-5 sm:px-6 lg:px-8 lg:py-7">
      <header class="flex flex-col gap-5 xl:flex-row xl:items-end xl:justify-between">
        <div>
          <div class="inline-flex items-center gap-2 rounded-full border border-white/70 bg-white/70 px-3 py-1 text-xs font-bold text-blue-700 shadow-sm backdrop-blur">
            <i class="h-3.5 w-3.5" data-lucide="sparkles"></i>
            Kurumsal servis operasyon merkezi
          </div>
          <h1 class="mt-4 text-3xl font-black tracking-tight text-slate-950 sm:text-4xl">Filo ve servis takip paneli</h1>
          <p class="mt-2 max-w-3xl text-sm font-medium leading-6 text-slate-600">Arac kabul, sigorta onaylari, eksper gorselleri ve servis sureci tek ekranda izlenir.</p>
        </div>

        <form class="grid gap-2 rounded-3xl border border-white/70 bg-white/70 p-2 shadow-sm backdrop-blur md:grid-cols-[minmax(220px,1fr)_160px_160px_auto_auto]" method="get">
          <?php if ($insurance !== ''): ?>
            <input type="hidden" name="insurance" value="<?= e($insurance) ?>">
          <?php endif; ?>
          <div class="relative">
            <i class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" data-lucide="search"></i>
            <input class="h-11 w-full rounded-2xl border border-slate-200 bg-white/85 pl-9 pr-3 text-sm font-semibold outline-none transition focus:border-blue-500 focus:ring-4 focus:ring-blue-100" name="q" value="<?= e($q) ?>" placeholder="Plaka, musteri, sigorta ara">
          </div>
          <select class="h-11 rounded-2xl border border-slate-200 bg-white/85 px-3 text-sm font-semibold outline-none transition focus:border-blue-500 focus:ring-4 focus:ring-blue-100" name="month">
            <option value="">Tum aylar</option>
            <?php foreach ($months as $item): ?>
              <option value="<?= e($item['service_month']) ?>" <?= $month === $item['service_month'] ? 'selected' : '' ?>>
                <?= e(month_label($item['service_month'])) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <select class="h-11 rounded-2xl border border-slate-200 bg-white/85 px-3 text-sm font-semibold outline-none transition focus:border-blue-500 focus:ring-4 focus:ring-blue-100" name="status">
            <option value="">Tum durumlar</option>
            <?php foreach ($statuses as $item): ?>
              <option value="<?= e($item) ?>" <?= $status === $item ? 'selected' : '' ?>><?= e($item) ?></option>
            <?php endforeach; ?>
          </select>
          <button class="inline-flex h-11 items-center justify-center gap-2 rounded-2xl bg-slate-950 px-5 text-sm font-black text-white transition hover:bg-slate-800" type="submit">
            <i class="h-4 w-4" data-lucide="sliders-horizontal"></i>
            Filtrele
          </button>
          <a class="inline-flex h-11 items-center justify-center rounded-2xl border border-slate-200 bg-white px-4 text-sm font-bold text-slate-700 transition hover:bg-slate-50" href="<?= e(panel_url('index.php')) ?>">Temizle</a>
        </form>
      </header>

      <section class="mt-7 grid gap-4 xl:grid-cols-12">
        <article class="bento-card xl:col-span-3">
          <div class="flex items-start justify-between">
            <div>
              <p class="text-xs font-black uppercase tracking-wide text-slate-500">Icerideki arac</p>
              <strong class="mt-3 block text-4xl font-black tracking-tight text-slate-950"><?= e((int)($summary['open_count'] ?? 0)) ?></strong>
            </div>
            <span class="metric-icon bg-blue-50 text-blue-700"><i data-lucide="warehouse"></i></span>
          </div>
          <p class="mt-5 text-sm font-semibold text-slate-500">Toplam <?= e((int)($summary['total'] ?? 0)) ?> kayit icinde aktif servis operasyonu.</p>
        </article>

        <article class="bento-card xl:col-span-3">
          <div class="flex items-start justify-between">
            <div>
              <p class="text-xs font-black uppercase tracking-wide text-slate-500">Axa, Orient onay bekleyenler</p>
              <strong class="mt-3 block text-4xl font-black tracking-tight text-slate-950"><?= e($approvalPending) ?></strong>
            </div>
            <span class="metric-icon bg-amber-50 text-amber-700"><i data-lucide="badge-alert"></i></span>
          </div>
          <p class="mt-5 text-sm font-semibold text-slate-500">Sigorta/fleet onayi veya onay bekleyen aktif dosyalar.</p>
        </article>

        <article class="bento-card xl:col-span-3">
          <div class="flex items-start justify-between">
            <div>
              <p class="text-xs font-black uppercase tracking-wide text-slate-500">Bugunku giris</p>
              <strong class="mt-3 block text-4xl font-black tracking-tight text-slate-950"><?= e($todayIncoming) ?></strong>
            </div>
            <span class="metric-icon bg-emerald-50 text-emerald-700"><i data-lucide="log-in"></i></span>
          </div>
          <p class="mt-5 text-sm font-semibold text-slate-500">Bugun servise kabul edilen arac sayisi.</p>
        </article>

        <article class="bento-card xl:col-span-3">
          <div class="flex items-start justify-between">
            <div>
              <p class="text-xs font-black uppercase tracking-wide text-slate-500">Bugunku cikis</p>
              <strong class="mt-3 block text-4xl font-black tracking-tight text-slate-950"><?= e($todayOutgoing) ?></strong>
            </div>
            <span class="metric-icon bg-rose-50 text-rose-700"><i data-lucide="log-out"></i></span>
          </div>
          <p class="mt-5 text-sm font-semibold text-slate-500"><?= e($readyForExit) ?> arac cikisa hazir olarak izleniyor.</p>
        </article>
      </section>

      <section class="mt-4 grid gap-4 xl:grid-cols-12">
        <article class="bento-card min-h-[460px] xl:col-span-8">
          <div class="flex flex-col gap-4 border-b border-slate-200/70 pb-5 md:flex-row md:items-center md:justify-between">
            <div>
              <h2 class="text-xl font-black tracking-tight text-slate-950">Arac takip akisi</h2>
              <p class="mt-1 text-sm font-semibold text-slate-500"><?= count($records) ?> kayit gosteriliyor</p>
            </div>
            <a class="inline-flex h-10 items-center justify-center gap-2 rounded-2xl bg-blue-600 px-4 text-sm font-black text-white shadow-lg shadow-blue-600/20 transition hover:bg-blue-700" href="<?= e(panel_url('add.php')) ?>">
              <i class="h-4 w-4" data-lucide="plus"></i>
              Arac kabul
            </a>
          </div>

          <div class="mt-4 grid gap-3">
            <?php foreach (array_slice($records, 0, 12) as $record):
              $meta = vehicle_status_meta($record);
            ?>
              <article class="vehicle-row">
                <div class="min-w-0">
                  <div class="flex flex-wrap items-center gap-2">
                    <span class="rounded-2xl bg-slate-950 px-3 py-1.5 text-sm font-black tracking-wide text-white"><?= e($record['plate']) ?></span>
                    <span class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-black <?= e($meta['badge']) ?>">
                      <span class="h-2 w-2 rounded-full <?= e($meta['dot']) ?>"></span>
                      <?= e($meta['label']) ?>
                    </span>
                  </div>
                  <div class="mt-3 grid gap-3 text-sm sm:grid-cols-3">
                    <div>
                      <p class="text-xs font-black uppercase tracking-wide text-slate-400">Arac modeli</p>
                      <p class="mt-1 font-bold text-slate-700">Model bilgisi yok</p>
                    </div>
                    <div>
                      <p class="text-xs font-black uppercase tracking-wide text-slate-400">Musteri/Filo adi</p>
                      <p class="mt-1 truncate font-bold text-slate-900"><?= e($record['customer_name']) ?></p>
                    </div>
                    <div>
                      <p class="text-xs font-black uppercase tracking-wide text-slate-400">Sigorta</p>
                      <p class="mt-1 truncate font-bold text-slate-700"><?= e($record['insurance_company'] ?: '-') ?></p>
                    </div>
                  </div>
                </div>
                <div class="flex shrink-0 items-center gap-2">
                  <a class="icon-action" title="Detay" href="<?= e(panel_url('view.php?id=' . (int)$record['id'])) ?>"><i data-lucide="eye"></i></a>
                  <a class="icon-action blue" title="Duzenle" href="<?= e(panel_url('edit.php?id=' . (int)$record['id'])) ?>"><i data-lucide="pen-line"></i></a>
                </div>
              </article>
            <?php endforeach; ?>
            <?php if ($records === []): ?>
              <div class="rounded-3xl border border-dashed border-slate-300 bg-slate-50/80 p-10 text-center">
                <i class="mx-auto h-10 w-10 text-slate-400" data-lucide="search-x"></i>
                <p class="mt-3 text-sm font-bold text-slate-600">Filtreye uygun kayit bulunamadi.</p>
              </div>
            <?php endif; ?>
          </div>
        </article>

        <aside class="grid gap-4 xl:col-span-4">
          <section class="bento-card">
            <div class="flex items-center justify-between gap-3">
              <div>
                <h2 class="text-lg font-black tracking-tight text-slate-950">Filo/Sigorta paneli</h2>
                <p class="mt-1 text-sm font-semibold text-slate-500">Sigorta sirketine gore hizli filtre</p>
              </div>
              <span class="metric-icon bg-slate-100 text-slate-700"><i data-lucide="building-2"></i></span>
            </div>
            <div class="mt-4 flex flex-wrap gap-2">
              <a class="<?= $insurance === '' ? 'chip active' : 'chip' ?>" href="<?= e(index_url(['insurance' => null])) ?>">Tum sigortalar</a>
              <?php foreach (array_slice($insurances, 0, 10) as $item): ?>
                <a class="<?= $insurance === $item['insurance_company'] ? 'chip active' : 'chip' ?>" href="<?= e(index_url(['insurance' => $item['insurance_company']])) ?>">
                  <?= e($item['insurance_company']) ?>
                  <span><?= e($item['total']) ?></span>
                </a>
              <?php endforeach; ?>
            </div>
          </section>

          <section class="bento-card">
            <div class="flex items-center justify-between gap-3">
              <div>
                <h2 class="text-lg font-black tracking-tight text-slate-950">Police bitis alarmi</h2>
                <p class="mt-1 text-sm font-semibold text-slate-500">30 gun icinde biten policeler</p>
              </div>
              <span class="metric-icon bg-amber-50 text-amber-700"><i data-lucide="bell-ring"></i></span>
            </div>
            <div class="mt-4 grid gap-2">
              <?php foreach ($policyExpiringSoon as $p):
                $daysLeft = (int)$p['days_left'];
                $tone = $daysLeft <= 7 ? 'bg-red-50 text-red-700 border-red-100' : 'bg-amber-50 text-amber-800 border-amber-100';
              ?>
                <a class="flex items-center justify-between gap-3 rounded-2xl border <?= e($tone) ?> px-3 py-2.5" href="<?= e(panel_url('view.php?id=' . (int)$p['id'])) ?>">
                  <span class="min-w-0">
                    <span class="block truncate text-sm font-black"><?= e($p['plate']) ?> - <?= e($p['customer_name']) ?></span>
                    <span class="block text-xs font-semibold opacity-80"><?= e($p['policy_end_date']) ?></span>
                  </span>
                  <strong class="shrink-0 text-sm"><?= e($daysLeft) ?> gun</strong>
                </a>
              <?php endforeach; ?>
              <?php if ($policyExpiringSoon === []): ?>
                <div class="rounded-2xl border border-emerald-100 bg-emerald-50 px-3 py-3 text-sm font-bold text-emerald-700">Yaklasan police bitisi yok.</div>
              <?php endif; ?>
            </div>
          </section>
        </aside>
      </section>

      <section class="mt-4 bento-card">
        <div class="flex flex-col gap-2 md:flex-row md:items-end md:justify-between">
          <div>
            <h2 class="text-xl font-black tracking-tight text-slate-950">Hasar ve eksper gorselleri</h2>
            <p class="mt-1 text-sm font-semibold text-slate-500">Son yuklenen servis fotograflari ve dosya onizlemeleri</p>
          </div>
          <span class="inline-flex items-center gap-2 rounded-full bg-slate-100 px-3 py-1.5 text-xs font-black text-slate-600">
            <i class="h-3.5 w-3.5" data-lucide="images"></i>
            <?= count($galleryItems) ?> gorsel
          </span>
        </div>

        <div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          <?php foreach ($galleryItems as $item):
            $src = panel_url('download_attachment.php?id=' . (int)$item['id'] . '&inline=1');
          ?>
            <button type="button" class="gallery-tile group" data-gallery-src="<?= e($src) ?>" data-gallery-title="<?= e($item['plate'] . ' - ' . $item['customer_name']) ?>">
              <img src="<?= e($src) ?>" alt="<?= e($item['original_name']) ?>" class="h-full w-full object-cover transition duration-500 group-hover:scale-105">
              <span class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-slate-950/90 to-transparent p-4 text-left">
                <span class="block text-sm font-black text-white"><?= e($item['plate']) ?></span>
                <span class="block truncate text-xs font-semibold text-white/75"><?= e($item['customer_name']) ?></span>
              </span>
            </button>
          <?php endforeach; ?>
          <?php if ($galleryItems === []): ?>
            <div class="col-span-full rounded-3xl border border-dashed border-slate-300 bg-slate-50/80 p-10 text-center">
              <i class="mx-auto h-10 w-10 text-slate-400" data-lucide="image-off"></i>
              <p class="mt-3 text-sm font-bold text-slate-600">Henuz gorsel yuklenmemis.</p>
            </div>
          <?php endif; ?>
        </div>
      </section>
    </main>
  </div>

  <div id="gallery-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/60 p-4 backdrop-blur-xl">
    <div class="relative w-full max-w-5xl overflow-hidden rounded-[2rem] border border-white/20 bg-white/15 shadow-2xl">
      <button type="button" id="gallery-close" class="absolute right-4 top-4 z-10 grid h-11 w-11 place-items-center rounded-full border border-white/30 bg-white/20 text-white backdrop-blur transition hover:bg-white/30">
        <i class="h-5 w-5" data-lucide="x"></i>
      </button>
      <img id="gallery-image" src="" alt="" class="max-h-[78vh] w-full object-contain bg-slate-950/40">
      <div class="border-t border-white/15 px-5 py-4 text-white">
        <p id="gallery-title" class="text-sm font-black"></p>
        <p class="text-xs font-semibold text-white/70">Hasar ve eksper gorseli</p>
      </div>
    </div>
  </div>

  <script>
    if (window.lucide) {
      window.lucide.createIcons();
    }

    (function () {
      const modal = document.getElementById('gallery-modal');
      const image = document.getElementById('gallery-image');
      const title = document.getElementById('gallery-title');
      const close = document.getElementById('gallery-close');

      document.querySelectorAll('[data-gallery-src]').forEach((button) => {
        button.addEventListener('click', () => {
          image.src = button.dataset.gallerySrc || '';
          title.textContent = button.dataset.galleryTitle || '';
          modal.classList.remove('hidden');
          modal.classList.add('flex');
        });
      });

      function hideModal() {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
        image.src = '';
      }

      close.addEventListener('click', hideModal);
      modal.addEventListener('click', (event) => {
        if (event.target === modal) hideModal();
      });
      document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !modal.classList.contains('hidden')) hideModal();
      });
    })();
  </script>
</body>
</html>
