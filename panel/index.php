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

function status_class(string $s): string
{
    $s = trim($s);
    if ($s === '' || strcasecmp($s, 'Belirtilmedi') === 0) return 'is-muted';
    $low = function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
    if (preg_match('/(haz[ıi]r|tamam|teslim|c[ıi]k|çık)/u', $low)) return 'is-ok';
    if (preg_match('/(par[çc]a|bekl)/u', $low)) return 'is-warn';
    if (preg_match('/(serv|onar|tamir)/u', $low)) return 'is-info';
    if (preg_match('/(yeni|giri[şs]|kabul)/u', $low)) return 'is-info';
    if (preg_match('/(iptal|sorun|hata)/u', $low)) return 'is-danger';
    return 'is-muted';
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

$user = current_user();
$userName = $user['full_name'] ?: $user['username'];
$userInitial = mb_strtoupper(mb_substr($userName, 0, 1, 'UTF-8'), 'UTF-8');
$activePage = 'panel';
include __DIR__ . '/includes/_layout_head.php';
?>
  <main class="content">
    <header class="page-head">
      <div class="title-block">
        <div class="eyebrow">Komuta Merkezi</div>
        <h1>Servis &amp; Filo Paneli</h1>
        <p class="sub">Anlik arac durumlari, sigorta hareketleri ve police bitis takibi</p>
      </div>
      <div class="actions">
        <a class="btn-ghost btn-sm" href="<?= e(panel_url('import.php')) ?>">
          <svg class="ico" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
          Excel yukle
        </a>
        <a class="btn" href="<?= e(panel_url('add.php')) ?>">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Arac Ekle
        </a>
      </div>
    </header>

    <section class="bento" aria-label="Genel ozet">
      <div class="b-card b-1">
        <div class="b-top">
          <span class="b-label">Toplam Arac</span>
          <span class="b-icon">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 16H9m10 0h3v-3.15a1 1 0 0 0-.84-.99L16 11l-2.7-3.6a1 1 0 0 0-.8-.4H5.24a2 2 0 0 0-1.8 1.1l-.8 1.63A6 6 0 0 0 2 12.42V16h2"/><circle cx="6.5" cy="16.5" r="2.5"/><circle cx="16.5" cy="16.5" r="2.5"/></svg>
          </span>
        </div>
        <div class="b-value"><?= e((int)($summary['total'] ?? 0)) ?></div>
        <div class="b-trend">Sistemdeki tum kayitlar</div>
      </div>

      <div class="b-card b-2">
        <div class="b-top">
          <span class="b-label">Iceride / Serviste</span>
          <span class="b-icon is-warn">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>
          </span>
        </div>
        <div class="b-value"><?= e((int)($summary['open_count'] ?? 0)) ?></div>
        <div class="b-trend is-warn">Cikis tarihi bos olan arac</div>
      </div>

      <div class="b-card b-3">
        <div class="b-top">
          <span class="b-label">Mini Onarim</span>
          <span class="b-icon is-ok">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
          </span>
        </div>
        <div class="b-value"><?= e((int)($summary['mini_count'] ?? 0)) ?></div>
        <div class="b-trend is-ok">Aktif mini onarim kaydi</div>
      </div>

      <div class="b-card b-4">
        <div class="b-top">
          <span class="b-label">Son Senkron</span>
          <span class="b-icon is-muted">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
          </span>
        </div>
        <div class="b-value small"><?= e($lastImport['created_at'] ?? '-') ?></div>
        <div class="b-trend">Son Excel aktarimi</div>
      </div>
    </section>

    <?php if ($policyExpiringSoon !== []): ?>
      <section class="card mb-4" style="margin-bottom:18px;overflow:hidden">
        <div class="card-head" style="background:linear-gradient(135deg,#fffbeb,#fef3c7);border-bottom-color:var(--warn-border)">
          <div style="display:flex;align-items:center;gap:12px">
            <span class="b-icon is-warn" style="width:38px;height:38px">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
            </span>
            <div>
              <h2 style="color:#78350f">Police bitisi yaklasan araclar</h2>
              <div class="sub" style="color:#92400e">Bugunden itibaren 30 gun icinde biten policeler</div>
            </div>
          </div>
          <span class="status-pill is-warn"><?= count($policyExpiringSoon) ?> arac</span>
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
                $pillClass = $d <= 7 ? 'is-danger' : ($d <= 14 ? 'is-warn' : 'is-info');
              ?>
                <tr>
                  <td><strong><?= e($p['plate']) ?></strong></td>
                  <td><?= e($p['customer_name']) ?></td>
                  <td><?= e($p['insurance_company'] ?: '-') ?></td>
                  <td><?= e($p['policy_end_date']) ?></td>
                  <td><span class="status-pill <?= $pillClass ?>"><?= e($d) ?> gun</span></td>
                  <td style="text-align:right">
                    <a class="btn-ghost btn-xs" href="<?= e(panel_url('view.php?id=' . (int)$p['id'])) ?>">Detay</a>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>
    <?php endif; ?>

    <section class="card" style="margin-bottom:14px;overflow:hidden" aria-label="Sigorta filtreleri">
      <div class="card-head">
        <div>
          <h2>Sigorta Sirketleri</h2>
          <div class="sub">Sigorta sirketine gore hizli filtrele</div>
        </div>
        <?php if ($insurance !== ''): ?>
          <a class="btn-ghost btn-sm" href="<?= e(index_url(['insurance' => null])) ?>">Filtreyi kaldir</a>
        <?php endif; ?>
      </div>
      <div class="card-pad">
        <div class="chip-row">
          <a class="chip <?= $insurance === '' ? 'active' : '' ?>" href="<?= e(index_url(['insurance' => null])) ?>">Tumu</a>
          <?php foreach ($insurances as $item): ?>
            <a class="chip <?= $insurance === $item['insurance_company'] ? 'active' : '' ?>" href="<?= e(index_url(['insurance' => $item['insurance_company']])) ?>">
              <?= e($item['insurance_company']) ?>
              <span class="count"><?= e($item['total']) ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    </section>

    <form class="filters" method="get">
      <?php if ($insurance !== ''): ?>
        <input type="hidden" name="insurance" value="<?= e($insurance) ?>">
      <?php endif; ?>
      <input name="q" value="<?= e($q) ?>" placeholder="Plaka veya musteri ara">
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
      <a class="btn-ghost" href="<?= e(panel_url('index.php')) ?>">Temizle</a>
    </form>

    <section class="table-card">
      <div class="table-head">
        <div>
          <h2>Arac Kayitlari</h2>
          <span><?= count($records) ?> kayit gosteriliyor</span>
        </div>
      </div>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Plaka</th>
              <th>Musteri / Filo</th>
              <th>Sigorta</th>
              <th>Tamir Durumu</th>
              <th>Mini Onarim</th>
              <th>Giris</th>
              <th>Cikis</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($records as $record):
              $cls = status_class((string)$record['repair_status']);
              $statusLabel = $record['repair_status'] !== '' ? $record['repair_status'] : 'Belirtilmedi';
            ?>
              <tr>
                <td><strong><?= e($record['plate']) ?></strong></td>
                <td><?= e($record['customer_name']) ?></td>
                <td><?= e($record['insurance_company'] ?: '-') ?></td>
                <td><span class="status-pill <?= $cls ?>"><?= e($statusLabel) ?></span></td>
                <td><?= ((int)$record['mini_repair_has'] === 1) ? '<span class="status-pill is-ok">' . e($record['mini_repair_part'] ?: 'Var') . '</span>' : '<span class="status-pill is-muted">Yok</span>' ?></td>
                <td><?= e($record['service_entry_date']) ?></td>
                <td><?= e($record['service_exit_date'] ?: '-') ?></td>
                <td style="white-space:nowrap;text-align:right">
                  <a class="btn-ghost btn-xs" href="<?= e(panel_url('view.php?id=' . (int)$record['id'])) ?>">Detay</a>
                  <a class="btn-soft btn-xs" style="margin-left:4px" href="<?= e(panel_url('edit.php?id=' . (int)$record['id'])) ?>">Duzenle</a>
                </td>
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
<?php include __DIR__ . '/includes/_layout_foot.php'; ?>
