<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/options.php';
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
$insurance = '';
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
if ($q !== '') {
    $where[] = '(plate LIKE ? OR customer_name LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like);
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$perPageOptions = [50, 100, 250];
$perPage = (int)($_GET['per_page'] ?? 50);
if (!in_array($perPage, $perPageOptions, true)) {
    $perPage = 50;
}
$page = max(1, (int)($_GET['page'] ?? 1));

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM service_records $whereSql");
$countStmt->execute($params);
$totalRecords = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRecords / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare(
    "SELECT id, plate, customer_name, insurance_type, insurance_company, repair_status,
            mini_repair_has, mini_repair_part, service_entry_date, service_exit_date, updated_at
     FROM service_records $whereSql
     ORDER BY service_entry_date DESC, updated_at DESC
     LIMIT $perPage OFFSET $offset"
);
$stmt->execute($params);
$records = $stmt->fetchAll();
$showingStart = $totalRecords === 0 ? 0 : $offset + 1;
$showingEnd = $offset + count($records);

$months = $pdo->query('SELECT service_month, COUNT(*) total FROM service_records GROUP BY service_month ORDER BY service_month DESC LIMIT 24')->fetchAll();
$statusesFromDb = $pdo->query('SELECT repair_status FROM service_records WHERE repair_status <> "" GROUP BY repair_status ORDER BY repair_status')->fetchAll(PDO::FETCH_COLUMN);
$statuses = array_values(array_unique(array_merge(array_keys(repair_status_options()), $statusesFromDb)));
$summary = $pdo->query('SELECT COUNT(*) total, SUM(service_exit_date IS NULL) open_count, SUM(mini_repair_has = 1) mini_count FROM service_records')->fetch();
$lastImport = $pdo->query('SELECT created_at FROM import_logs ORDER BY created_at DESC LIMIT 1')->fetch();

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
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title><?= e(panel_config('app_name')) ?></title>
  <?php render_panel_head_assets(); ?>
</head>
<body>
  <header class="topbar">
    <div class="topbar-brand">
      <div class="brand-logo">DRN</div>
      <span class="brand-name">Servis Paneli</span>
    </div>
    <nav>
      <?php render_current_user_badge(); ?>
      <a href="<?= e(panel_url('add.php')) ?>">Arac ekle</a>
      <a href="<?= e(panel_url('import.php')) ?>">Excel yukle</a>
      <a href="<?= e(panel_url('install/migrate.php')) ?>">Migration</a>
      <a href="<?= e(panel_url('logout.php')) ?>">Cikis</a>
    </nav>
  </header>

  <main class="layout">
    <section class="metrics">
      <div class="metric-card">
        <div class="metric-label">Toplam arac sayisi</div>
        <strong class="metric-value"><?= e((int)($summary['total'] ?? 0)) ?></strong>
      </div>
      <div class="metric-card">
        <div class="metric-label">Serviste</div>
        <strong class="metric-value" style="color:var(--amber)"><?= e((int)($summary['open_count'] ?? 0)) ?></strong>
      </div>
      <div class="metric-card">
        <div class="metric-label">Mini onarim</div>
        <strong class="metric-value" style="color:var(--purple)"><?= e((int)($summary['mini_count'] ?? 0)) ?></strong>
      </div>
      <div class="metric-card">
        <div class="metric-label">Son senkron</div>
        <strong class="metric-value" style="font-size:18px;letter-spacing:-0.01em"><?= e(format_tr_datetime($lastImport['created_at'] ?? null)) ?></strong>
      </div>
    </section>

    <?php if ($policyExpiringSoon !== []): ?>
      <section class="policy-warning">
        <div class="policy-warning-head">
          <span style="font-size:15px">&#9888;&#65039;</span>
          <span>Police bitisi yaklasan araclar</span>
          <span class="policy-count"><?= count($policyExpiringSoon) ?> arac</span>
          <span style="font-size:12px;color:#92400e;margin-left:4px;font-weight:500">Bugun ile 30 gun arasinda biten policeler.</span>
        </div>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Plaka</th>
                <th>Musteri</th>
                <th>Sigorta</th>
                <th>Bitis</th>
                <th>Kalan</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($policyExpiringSoon as $p):
                $d = (int)$p['days_left'];
                $tone = $d <= 7 ? 'status-red' : 'status-yellow';
              ?>
                <tr>
                  <td data-label="Plaka"><strong><?= e($p['plate']) ?></strong></td>
                  <td data-label="Musteri"><?= e($p['customer_name']) ?></td>
                  <td data-label="Sigorta" style="color:var(--muted)"><?= e($p['insurance_company'] ?: '-') ?></td>
                  <td data-label="Bitis" style="color:var(--muted)"><?= e(format_tr_date($p['policy_end_date'])) ?></td>
                  <td data-label="Kalan"><span class="pill <?= $tone ?>"><?= e($d) ?> gun</span></td>
                  <td data-label="Islem" style="text-align:right">
                    <a class="btn-soft" href="<?= e(panel_url('view.php?id=' . (int)$p['id'])) ?>">Detay</a>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>
    <?php endif; ?>

    <section class="filter-panel" aria-label="Arac filtreleri">
      <div class="filter-panel-header">
        <div>
          <div class="filter-title">Arac filtreleri</div>
          <div class="filter-subtitle">Kayitlari hizmet tipine gore hizli filtrele</div>
        </div>
        <?php if ($type !== ''): ?>
          <a class="btn-secondary" href="<?= e(index_url(['type' => null, 'insurance' => null, 'page' => null])) ?>">Filtreyi kaldir</a>
        <?php endif; ?>
      </div>
      <div class="filter-pills">
        <a class="<?= $type === '' ? 'filter-pill active' : 'filter-pill' ?>" href="<?= e(index_url(['type' => null, 'insurance' => null, 'page' => null])) ?>">Tum araclar</a>
        <?php foreach (insurance_type_options() as $key => $label): ?>
          <a class="<?= $type === $key ? 'filter-pill active' : 'filter-pill' ?>" href="<?= e(index_url(['type' => $key, 'insurance' => null, 'page' => null])) ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
      </div>
    </section>

    <form class="filters" method="get">
      <?php if ($type !== ''): ?>
        <input type="hidden" name="type" value="<?= e($type) ?>">
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
      <a class="btn-secondary" href="<?= e(panel_url('index.php')) ?>">Temizle</a>
    </form>

    <section class="table-card">
      <div class="table-head">
        <h2>Arac kayitlari</h2>
        <div class="table-head-tools">
          <span><?= e($showingStart) ?>-<?= e($showingEnd) ?> / <?= e($totalRecords) ?> kayit</span>
          <div class="per-page">
            <?php foreach ($perPageOptions as $option): ?>
              <a class="<?= $perPage === $option ? 'active' : '' ?>" href="<?= e(index_url(['per_page' => $option, 'page' => null])) ?>"><?= e($option) ?></a>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
      <?php if ($records === []): ?>
        <div class="empty-state">
          <strong>Arac kaydi bulunamadi</strong>
          <span>Secili filtrelerde kayit yok. Tum araclari gormek icin filtreyi temizleyebilirsiniz.</span>
        </div>
      <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th data-col="0">Plaka</th>
              <th data-col="1">Ad Soyad</th>
              <th data-col="2">Arac Filtresi</th>
              <th data-col="3">Sigorta</th>
              <th data-col="4">Tamir Durumu</th>
              <th data-col="5">Mini Onarim</th>
              <th data-col="6">Giris</th>
              <th data-col="7">Cikis</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($records as $record): ?>
              <tr>
                <td data-label="Plaka"><strong><?= e($record['plate']) ?></strong></td>
                <td data-label="Ad Soyad" style="font-weight:600"><?= e($record['customer_name']) ?></td>
                <td data-label="Arac Filtresi"><span class="type-badge"><?= e(insurance_type_label($record['insurance_type'] ?? 'kasko')) ?></span></td>
                <td data-label="Sigorta" style="color:var(--muted)"><?= e($record['insurance_company'] ?: '-') ?></td>
                <td data-label="Tamir Durumu"><span class="pill <?= e(repair_status_tone((string)$record['repair_status'])) ?>"><?= e($record['repair_status']) ?></span></td>
                <td data-label="Mini Onarim"><?= ((int)$record['mini_repair_has'] === 1) ? e($record['mini_repair_part'] ?: 'Var') : '<span style="color:var(--muted-2)">Yok</span>' ?></td>
                <td data-label="Giris" style="color:var(--muted);font-family:'IBM Plex Mono',monospace;font-size:12px"><?= e(format_tr_date($record['service_entry_date'])) ?></td>
                <td data-label="Cikis" style="font-family:'IBM Plex Mono',monospace;font-size:12px;<?= $record['service_exit_date'] ? 'color:var(--muted)' : 'color:var(--amber);font-weight:600' ?>"><?= $record['service_exit_date'] ? e(format_tr_date($record['service_exit_date'])) : 'Acik' ?></td>
                <td data-label="Islem" style="text-align:right;white-space:nowrap">
                  <a class="btn-table" href="<?= e(panel_url('view.php?id=' . (int)$record['id'])) ?>">Detay</a>
                  <a class="btn-table-primary" href="<?= e(panel_url('edit.php?id=' . (int)$record['id'])) ?>">Duzenle</a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
      <?php if ($totalPages > 1): ?>
        <nav class="pagination" aria-label="Sayfalama">
          <a class="<?= $page <= 1 ? 'disabled' : '' ?>" href="<?= e($page <= 1 ? '#' : index_url(['page' => $page - 1])) ?>">Onceki</a>
          <span>Sayfa <?= e($page) ?> / <?= e($totalPages) ?></span>
          <a class="<?= $page >= $totalPages ? 'disabled' : '' ?>" href="<?= e($page >= $totalPages ? '#' : index_url(['page' => $page + 1])) ?>">Sonraki</a>
        </nav>
      <?php endif; ?>
    </section>
  </main>
  <script>
  (function () {
    var table = document.querySelector('.table-card .table-wrap table');
    if (!table) return;
    var filterHeaders = Array.from(table.querySelectorAll('thead th[data-col]'));
    var tbody = table.querySelector('tbody');
    if (!filterHeaders.length || !tbody) return;

    var activeFilters = {};
    var currentCol = -1;

    var popup = document.createElement('div');
    popup.id = 'cfp';
    popup.innerHTML =
      '<div class="cfp-head"><span class="cfp-title"></span><button type="button" class="cfp-clear-btn">Temizle</button></div>' +
      '<input type="text" class="cfp-search" placeholder="Ara...">' +
      '<div class="cfp-list"></div>' +
      '<div class="cfp-foot"><button type="button" class="cfp-apply">Uygula</button></div>';
    popup.style.display = 'none';
    document.body.appendChild(popup);

    var popupTitle = popup.querySelector('.cfp-title');
    var popupSearch = popup.querySelector('.cfp-search');
    var popupList = popup.querySelector('.cfp-list');

    popup.querySelector('.cfp-clear-btn').addEventListener('click', function () {
      popupList.querySelectorAll('input[type="checkbox"]').forEach(function (cb) { cb.checked = false; });
      popupSearch.value = '';
      filterItems('');
    });

    popupSearch.addEventListener('input', function () { filterItems(this.value); });

    function filterItems(q) {
      var lq = q.toLowerCase();
      popupList.querySelectorAll('.cfp-item').forEach(function (item) {
        item.style.display = (!lq || item.dataset.val.toLowerCase().indexOf(lq) !== -1) ? '' : 'none';
      });
    }

    popup.querySelector('.cfp-apply').addEventListener('click', applyAndClose);

    document.addEventListener('mousedown', function (e) {
      if (popup.style.display !== 'none' && !popup.contains(e.target) && !e.target.closest('.cfp-btn')) {
        closePopup();
      }
    });

    filterHeaders.forEach(function (th) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'cfp-btn';
      btn.title = 'Filtrele';
      btn.innerHTML = '<svg width="10" height="10" viewBox="0 0 10 10" fill="none"><path d="M1 2.5h8M2.5 5h5M4 7.5h2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>';
      th.appendChild(btn);

      btn.addEventListener('click', function (e) {
        e.stopPropagation();
        var col = parseInt(th.dataset.col);
        if (popup.style.display !== 'none' && currentCol === col) { closePopup(); return; }
        openPopup(btn, col, th.childNodes[0].textContent.trim());
      });
    });

    function getColValues(col) {
      var vals = new Set();
      Array.from(tbody.querySelectorAll('tr')).forEach(function (tr) {
        var td = tr.cells[col];
        if (td) vals.add(td.textContent.trim().replace(/\s+/g, ' '));
      });
      return Array.from(vals).sort();
    }

    function openPopup(btn, col, title) {
      currentCol = col;
      popupTitle.textContent = title;
      popupSearch.value = '';
      var selected = activeFilters[col] || new Set();
      var vals = getColValues(col);
      popupList.innerHTML = '';
      if (!vals.length) {
        popupList.innerHTML = '<span class="cfp-empty">Veri yok</span>';
      } else {
        vals.forEach(function (v) {
          var label = document.createElement('label');
          label.className = 'cfp-item';
          label.dataset.val = v;
          var cb = document.createElement('input');
          cb.type = 'checkbox';
          cb.value = v;
          cb.checked = selected.has(v);
          var sp = document.createElement('span');
          sp.textContent = v || '(Bos)';
          label.appendChild(cb);
          label.appendChild(sp);
          popupList.appendChild(label);
        });
      }
      popup.style.display = 'block';
      var rect = btn.getBoundingClientRect();
      var sy = window.pageYOffset, sx = window.pageXOffset, pw = 230;
      var top = rect.bottom + sy + 4;
      var left = Math.min(rect.left + sx, window.innerWidth + sx - pw - 8);
      popup.style.top = top + 'px';
      popup.style.left = left + 'px';
    }

    function closePopup() { popup.style.display = 'none'; currentCol = -1; }

    function applyAndClose() {
      var col = currentCol;
      var checked = Array.from(popupList.querySelectorAll('input[type="checkbox"]:checked'));
      if (!checked.length) { delete activeFilters[col]; }
      else { activeFilters[col] = new Set(checked.map(function (cb) { return cb.value; })); }
      closePopup();
      applyFilters();
      updateHeaders();
    }

    function applyFilters() {
      var hasFilters = Object.keys(activeFilters).length > 0;
      Array.from(tbody.querySelectorAll('tr')).forEach(function (tr) {
        if (!hasFilters) { tr.style.display = ''; return; }
        var show = true;
        for (var col in activeFilters) {
          var td = tr.cells[parseInt(col)];
          if (!td) continue;
          if (!activeFilters[col].has(td.textContent.trim().replace(/\s+/g, ' '))) { show = false; break; }
        }
        tr.style.display = show ? '' : 'none';
      });
    }

    function updateHeaders() {
      filterHeaders.forEach(function (th) {
        var col = parseInt(th.dataset.col);
        var active = activeFilters[col] && activeFilters[col].size > 0;
        th.classList.toggle('cfp-active', active);
      });
    }
  })();
  </script>
</body>
</html>
