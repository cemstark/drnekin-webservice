<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM service_records WHERE id = ?');
$stmt->execute([$id]);
$record = $stmt->fetch();
if (!$record) {
    http_response_code(404);
    exit('Kayit bulunamadi.');
}

$today = new DateTimeImmutable('today');
$policyEnd = !empty($record['policy_end_date']) ? new DateTimeImmutable($record['policy_end_date']) : null;
$daysToPolicyEnd = $policyEnd ? (int)$today->diff($policyEnd)->format('%r%a') : null;
?>
<!doctype html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Detay <?= e($record['plate']) ?> - <?= e(panel_config('app_name')) ?></title>
  <link rel="stylesheet" href="<?= e(panel_url('assets/panel.css')) ?>">
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body>
  <header class="topbar">
    <div>
      <div class="eyebrow">DRN</div>
      <h1>Arac Detayi</h1>
    </div>
    <nav>
      <a href="<?= e(panel_url('index.php')) ?>">Panele don</a>
      <a href="<?= e(panel_url('logout.php')) ?>">Cikis</a>
    </nav>
  </header>

  <main class="mx-auto w-full max-w-5xl px-4 py-8 sm:px-6 lg:px-8">
    <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
      <div class="border-b border-slate-200 bg-slate-50 px-6 py-5 flex flex-wrap items-center justify-between gap-3">
        <div>
          <p class="text-xs font-semibold uppercase tracking-wide text-blue-700">Detayli goruntuleme</p>
          <h2 class="mt-1 text-xl font-bold text-slate-950"><?= e($record['plate']) ?> &mdash; <?= e($record['customer_name']) ?></h2>
          <p class="mt-1 text-xs text-slate-500">Kayit no: <?= e($record['record_no']) ?></p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
          <a class="inline-flex h-10 items-center justify-center rounded-lg bg-blue-600 px-4 text-sm font-semibold text-white hover:bg-blue-700" href="<?= e(panel_url('edit.php?id=' . $id)) ?>">Duzenle</a>
          <button type="button" onclick="window.print()" class="inline-flex h-10 items-center justify-center rounded-lg border border-slate-200 bg-white px-4 text-sm font-semibold text-slate-700 hover:bg-slate-50">Yazdir</button>
        </div>
      </div>
      <dl class="grid gap-4 px-6 py-6 sm:grid-cols-2 lg:grid-cols-3 text-sm">
        <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Sigorta</dt><dd class="mt-1 text-slate-950"><?= e($record['insurance_company'] ?: '-') ?></dd></div>
        <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Tamir Durumu</dt><dd class="mt-1 text-slate-950"><?= e($record['repair_status']) ?></dd></div>
        <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Mini Onarim</dt><dd class="mt-1 text-slate-950"><?= ((int)$record['mini_repair_has'] === 1) ? e($record['mini_repair_part'] ?: 'Var') : 'Yok' ?></dd></div>
        <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Servis Giris</dt><dd class="mt-1 text-slate-950"><?= e($record['service_entry_date'] ?: '-') ?></dd></div>
        <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Servis Cikis</dt><dd class="mt-1 text-slate-950"><?= e($record['service_exit_date'] ?: '-') ?></dd></div>
        <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Servis Ay</dt><dd class="mt-1 text-slate-950"><?= e($record['service_month']) ?></dd></div>
        <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Police Baslangic</dt><dd class="mt-1 text-slate-950"><?= e($record['policy_start_date'] ?: '-') ?></dd></div>
        <div>
          <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Police Bitis</dt>
          <dd class="mt-1 text-slate-950">
            <?= e($record['policy_end_date'] ?: '-') ?>
            <?php if ($daysToPolicyEnd !== null): ?>
              <?php if ($daysToPolicyEnd < 0): ?>
                <span class="ml-2 rounded-full bg-red-100 px-2 py-0.5 text-xs font-semibold text-red-700"><?= e(abs($daysToPolicyEnd)) ?> gun gecti</span>
              <?php elseif ($daysToPolicyEnd <= 30): ?>
                <span class="ml-2 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-800"><?= e($daysToPolicyEnd) ?> gun kaldi</span>
              <?php else: ?>
                <span class="ml-2 rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-700"><?= e($daysToPolicyEnd) ?> gun kaldi</span>
              <?php endif; ?>
            <?php endif; ?>
          </dd>
        </div>
        <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Olusturuldu</dt><dd class="mt-1 text-slate-950"><?= e($record['created_at']) ?></dd></div>
        <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Guncellendi</dt><dd class="mt-1 text-slate-950"><?= e($record['updated_at']) ?></dd></div>
      </dl>
    </section>
  </main>
</body>
</html>
