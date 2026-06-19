<?php
require_once __DIR__ . '/bootstrap.php';
ensure_completion_status_table();

$code = $_GET['code'] ?? '';
if (!preg_match('/^64(00|01|02|03|04|05|09|11|71|72|74)$/', $code)) {
    http_response_code(404);
    exit('Dashboard publik tidak ditemukan.');
}

$fields = status_fields();
$statusColors = ['#2563eb', '#16a34a', '#dc2626', '#f59e0b', '#0f766e'];
$rangeColors = [
    ['label' => '< 20%', 'color' => '#dc2626'],
    ['label' => '20% - < 40%', 'color' => '#f59e0b'],
    ['label' => '40% - < 75%', 'color' => '#2563eb'],
    ['label' => '75% - 100%', 'color' => '#16a34a'],
];

function public_count_pct_text(int $count, float $pct): string
{
    return '<span class="d-block">' . number_format($count, 0, ',', '.') . '</span><span class="d-block text-muted small">(' . number_format($pct, 2, ',', '.') . '%)</span>';
}

function public_dashboard_context(string $code): array
{
    if ($code === '6400') {
        return [
            'title' => 'Dashboard Publik SE 2026',
            'subtitle' => 'Provinsi Kalimantan Timur',
            'group_label' => 'Kabupaten',
            'where' => '',
            'params' => [],
            'label_expr' => "CONCAT(k.id,' - ',k.nmkab)",
            'group_expr' => 'k.id, k.nmkab',
            'order_expr' => 'k.id',
        ];
    }

    $stmt = db()->prepare("SELECT id, nmkab FROM master_kab WHERE id=?");
    $stmt->execute([$code]);
    $kab = $stmt->fetch();
    if (!$kab) {
        http_response_code(404);
        exit('Dashboard publik tidak ditemukan.');
    }

    return [
        'title' => 'Dashboard Publik SE 2026',
        'subtitle' => $kab['id'] . ' - ' . $kab['nmkab'],
        'group_label' => 'Kecamatan',
        'where' => 'WHERE k.id=?',
        'params' => [$code],
        'label_expr' => "CONCAT(kc.kdkec,' - ',kc.nmkec)",
        'group_expr' => 'kc.id, kc.kdkec, kc.nmkec',
        'order_expr' => 'kc.kdkec, kc.nmkec',
    ];
}

function public_dashboard_rows(array $fields, array $context): array
{
    $selects = [];
    foreach (array_keys($fields) as $field) {
        $selects[] = "COALESCE(SUM(ss.$field),0) $field";
    }
    $stmt = db()->prepare("SELECT {$context['label_expr']} label,
            COALESCE(SUM(ss.target),0) target,
            " . implode(',', $selects) . ",
            COUNT(ms.id) subsls_total,
            COALESCE(SUM(CASE WHEN cs.status_selesai='Selesai' THEN 1 ELSE 0 END),0) selesai_count
        FROM master_subsls ms
        JOIN master_sls sl ON sl.id=ms.sls_id
        JOIN master_desa d ON d.id=sl.desa_id
        JOIN master_kec kc ON kc.id=d.kec_id
        JOIN master_kab k ON k.id=kc.kab_id
        LEFT JOIN subsls_status ss ON ss.subsls_id=ms.id
        LEFT JOIN subsls_completion_status cs ON cs.subsls_id=ms.id
        {$context['where']}
        GROUP BY {$context['group_expr']}
        ORDER BY {$context['order_expr']}");
    $stmt->execute($context['params']);
    return $stmt->fetchAll();
}

$context = public_dashboard_context($code);
$rows = public_dashboard_rows($fields, $context);
$totals = array_fill_keys(array_merge(['target', 'subsls_total', 'selesai_count'], array_keys($fields)), 0);
foreach ($rows as $row) {
    foreach ($totals as $key => $_) {
        $totals[$key] += (int)($row[$key] ?? 0);
    }
}

$targetTotal = (int)$totals['target'];
$submitApprovePct = $targetTotal > 0
    ? round(((int)$totals['submitted_by_pencacah'] + (int)$totals['approved_by_pengawas']) / $targetTotal * 100, 2)
    : 0;
$completionPct = (int)$totals['subsls_total'] > 0
    ? round((int)$totals['selesai_count'] / (int)$totals['subsls_total'] * 100, 2)
    : 0;

$cards = [
    ['label' => 'Target', 'value' => number_format($targetTotal, 0, ',', '.')],
    ['label' => 'Open', 'value' => public_count_pct_text((int)$totals['open_count'], $targetTotal ? (int)$totals['open_count'] / $targetTotal * 100 : 0)],
    ['label' => 'Submit', 'value' => public_count_pct_text((int)$totals['submitted_by_pencacah'], $targetTotal ? (int)$totals['submitted_by_pencacah'] / $targetTotal * 100 : 0)],
    ['label' => 'Reject', 'value' => public_count_pct_text((int)$totals['rejected_by_pengawas'], $targetTotal ? (int)$totals['rejected_by_pengawas'] / $targetTotal * 100 : 0)],
    ['label' => 'Pending', 'value' => public_count_pct_text((int)$totals['draft_count'], $targetTotal ? (int)$totals['draft_count'] / $targetTotal * 100 : 0)],
    ['label' => 'Approve', 'value' => public_count_pct_text((int)$totals['approved_by_pengawas'], $targetTotal ? (int)$totals['approved_by_pengawas'] / $targetTotal * 100 : 0)],
    ['label' => 'Persen Submit+Approve', 'value' => number_format($submitApprovePct, 2, ',', '.') . '%'],
    ['label' => 'Selesai', 'value' => public_count_pct_text((int)$totals['selesai_count'], $completionPct)],
    ['label' => 'Total SubSLS', 'value' => number_format((int)$totals['subsls_total'], 0, ',', '.')],
];
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($context['title']) ?> | <?= e($context['subtitle']) ?></title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.2/css/all.min.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
  <style>
    body { background: #f3f4f6; }
    .public-header {
      align-items: center;
      background: #fff;
      border-bottom: 1px solid #e5e7eb;
      display: flex;
      flex-wrap: wrap;
      gap: 14px;
      justify-content: space-between;
      padding: 14px 18px;
    }
    .public-logo-group {
      align-items: center;
      display: flex;
      flex-wrap: wrap;
      gap: 14px;
    }
    .public-logo-bps { max-height: 48px; max-width: 300px; object-fit: contain; }
    .public-logo-se { max-height: 54px; max-width: 180px; object-fit: contain; }
    .public-title h1 {
      font-size: 1.25rem;
      margin: 0;
    }
    .public-title span { color: #6b7280; font-size: .9rem; }
    .content-wrap { margin: 0 auto; max-width: 1380px; padding: 18px; }
    .small-box .inner h4 { font-weight: 700; }
    .range-legend {
      display: flex;
      flex-wrap: wrap;
      gap: 12px;
      margin-bottom: 12px;
    }
    .range-legend span {
      align-items: center;
      display: inline-flex;
      font-size: .9rem;
      gap: 6px;
    }
    .range-legend i {
      border-radius: 999px;
      display: inline-block;
      height: 10px;
      width: 10px;
    }
    .public-chart-wrap {
      height: 380px;
      position: relative;
    }
    .public-chart-wrap.public-chart-wide { height: 430px; }
    @media (max-width: 767.98px) {
      .public-logo-bps { max-width: 210px; }
      .public-logo-se { max-width: 125px; }
      .content-wrap { padding: 12px; }
      .public-chart-wrap,
      .public-chart-wrap.public-chart-wide { height: 330px; }
    }
  </style>
</head>
<body>
<header class="public-header">
  <div class="public-logo-group">
    <img class="public-logo-bps" src="assets/img/logo-bps-kaltim.png" alt="BPS Provinsi Kalimantan Timur">
    <img class="public-logo-se" src="assets/img/logo_Sensus_Ekonomi_2026.png" alt="Sensus Ekonomi 2026">
  </div>
  <div class="public-title text-md-right">
    <h1><?= e($context['title']) ?></h1>
    <span><?= e($context['subtitle']) ?></span>
  </div>
</header>

<main class="content-wrap">
  <div class="row">
    <?php foreach ($cards as $card): ?>
      <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6">
        <div class="small-box bg-white">
          <div class="inner">
            <h4 class="mb-1"><?= $card['value'] ?></h4>
            <p><?= e($card['label']) ?></p>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="range-legend">
    <?php foreach ($rangeColors as $item): ?><span><i style="background:<?= e($item['color']) ?>"></i><?= e($item['label']) ?></span><?php endforeach; ?>
  </div>

  <div class="card">
    <div class="card-header"><strong>Progress By Status per <?= e($context['group_label']) ?></strong></div>
    <div class="card-body"><div class="public-chart-wrap public-chart-wide"><canvas id="statusChart"></canvas></div></div>
  </div>

  <div class="row">
    <div class="col-lg-6">
      <div class="card">
        <div class="card-header"><strong>Progress Submit+Approve per <?= e($context['group_label']) ?></strong></div>
        <div class="card-body"><div class="public-chart-wrap"><canvas id="submitApproveChart"></canvas></div></div>
      </div>
    </div>
    <div class="col-lg-6">
      <div class="card">
        <div class="card-header"><strong>Progress Selesai SubSLS per <?= e($context['group_label']) ?></strong></div>
        <div class="card-body"><div class="public-chart-wrap"><canvas id="completionChart"></canvas></div></div>
      </div>
    </div>
  </div>
</main>

<script>
const rows = <?= json_encode($rows) ?>;
const fields = <?= json_encode(array_keys($fields)) ?>;
const labels = <?= json_encode(array_values($fields)) ?>;
const statusColors = <?= json_encode($statusColors) ?>;

function pctColor(value) {
  if (value < 20) return '#dc2626';
  if (value < 40) return '#f59e0b';
  if (value < 75) return '#2563eb';
  return '#16a34a';
}

const chartLabels = rows.map(row => row.label || '-');
const submitApprove = rows.map(row => {
  const target = Number(row.target || 0);
  return target ? Math.round((Number(row.submitted_by_pencacah || 0) + Number(row.approved_by_pengawas || 0)) / target * 10000) / 100 : 0;
});
const completion = rows.map(row => {
  const total = Number(row.subsls_total || 0);
  return total ? Math.round(Number(row.selesai_count || 0) / total * 10000) / 100 : 0;
});

new Chart(document.getElementById('submitApproveChart'), {
  type: 'bar',
  data: {
    labels: chartLabels,
    datasets: [{
      label: 'Persen Submit+Approve',
      data: submitApprove,
      backgroundColor: submitApprove.map(pctColor)
    }]
  },
  options: { animation: false, maintainAspectRatio: false, responsive: true, scales: { y: { min: 0, max: 100, ticks: { callback: value => value + '%' } } } }
});

new Chart(document.getElementById('completionChart'), {
  type: 'bar',
  data: {
    labels: chartLabels,
    datasets: [{
      label: 'Persen Selesai SubSLS',
      data: completion,
      backgroundColor: completion.map(pctColor)
    }]
  },
  options: { animation: false, maintainAspectRatio: false, responsive: true, scales: { y: { min: 0, max: 100, ticks: { callback: value => value + '%' } } } }
});

new Chart(document.getElementById('statusChart'), {
  type: 'bar',
  data: {
    labels: chartLabels,
    datasets: fields.map((field, index) => ({
      label: labels[index],
      data: rows.map(row => Number(row.target || 0) ? Math.round(Number(row[field] || 0) / Number(row.target || 0) * 10000) / 100 : 0),
      backgroundColor: statusColors[index]
    }))
  },
  options: {
    animation: false,
    maintainAspectRatio: false,
    responsive: true,
    scales: {
      x: { stacked: true },
      y: { stacked: true, min: 0, max: 100, ticks: { callback: value => value + '%' } }
    }
  }
});
</script>
</body>
</html>
