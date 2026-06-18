<?php
require_once __DIR__ . '/bootstrap.php';
ensure_completion_status_table();

$code = $_GET['code'] ?? '';
if ($code !== '6400') {
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
    return number_format($count, 0, ',', '.') . ' (' . number_format($pct, 2, ',', '.') . '%)';
}

function public_dashboard_rows(array $fields): array
{
    $selects = [];
    foreach (array_keys($fields) as $field) {
        $selects[] = "COALESCE(SUM(ss.$field),0) $field";
    }
    $stmt = db()->query("SELECT CONCAT(k.id,' - ',k.nmkab) label,
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
        GROUP BY k.id, k.nmkab
        ORDER BY k.id");
    return $stmt->fetchAll();
}

$rows = public_dashboard_rows($fields);
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
    ['label' => 'Count Open', 'value' => public_count_pct_text((int)$totals['open_count'], $targetTotal ? (int)$totals['open_count'] / $targetTotal * 100 : 0)],
    ['label' => 'Count Submit', 'value' => public_count_pct_text((int)$totals['submitted_by_pencacah'], $targetTotal ? (int)$totals['submitted_by_pencacah'] / $targetTotal * 100 : 0)],
    ['label' => 'Count Reject', 'value' => public_count_pct_text((int)$totals['rejected_by_pengawas'], $targetTotal ? (int)$totals['rejected_by_pengawas'] / $targetTotal * 100 : 0)],
    ['label' => 'Count Pending', 'value' => public_count_pct_text((int)$totals['draft_count'], $targetTotal ? (int)$totals['draft_count'] / $targetTotal * 100 : 0)],
    ['label' => 'Count Approve', 'value' => public_count_pct_text((int)$totals['approved_by_pengawas'], $targetTotal ? (int)$totals['approved_by_pengawas'] / $targetTotal * 100 : 0)],
    ['label' => 'Persen Submit+Approve', 'value' => number_format($submitApprovePct, 2, ',', '.') . '%'],
    ['label' => 'Count Selesai', 'value' => public_count_pct_text((int)$totals['selesai_count'], $completionPct)],
    ['label' => 'Total SubSLS', 'value' => number_format((int)$totals['subsls_total'], 0, ',', '.')],
];
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Dashboard Publik SE 2026</title>
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
    @media (max-width: 767.98px) {
      .public-logo-bps { max-width: 210px; }
      .public-logo-se { max-width: 125px; }
      .content-wrap { padding: 12px; }
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
    <h1>Dashboard Publik SE 2026</h1>
    <span>Provinsi Kalimantan Timur</span>
  </div>
</header>

<main class="content-wrap">
  <div class="row">
    <?php foreach ($cards as $card): ?>
      <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6">
        <div class="small-box bg-white">
          <div class="inner">
            <h4 class="mb-1"><?= e($card['value']) ?></h4>
            <p><?= e($card['label']) ?></p>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="range-legend">
    <?php foreach ($rangeColors as $item): ?><span><i style="background:<?= e($item['color']) ?>"></i><?= e($item['label']) ?></span><?php endforeach; ?>
  </div>

  <div class="row">
    <div class="col-lg-6">
      <div class="card">
        <div class="card-header"><strong>Progress Submit+Approve per Kabupaten</strong></div>
        <div class="card-body"><canvas id="submitApproveChart" height="180"></canvas></div>
      </div>
    </div>
    <div class="col-lg-6">
      <div class="card">
        <div class="card-header"><strong>Progress Selesai SubSLS per Kabupaten</strong></div>
        <div class="card-body"><canvas id="completionChart" height="180"></canvas></div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><strong>Progress By Status per Kabupaten</strong></div>
    <div class="card-body"><canvas id="statusChart" height="140"></canvas></div>
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
  options: { responsive: true, scales: { y: { min: 0, max: 100, ticks: { callback: value => value + '%' } } } }
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
  options: { responsive: true, scales: { y: { min: 0, max: 100, ticks: { callback: value => value + '%' } } } }
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

