<?php
$code = $_GET['code'] ?? '';
if (!preg_match('/^64(00|01|02|03|04|05|09|11|71|72|74)$/', $code)) {
    http_response_code(404);
    exit('Dashboard publik tidak ditemukan.');
}

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function public_dashboard_cache_path(): string
{
    return __DIR__ . '/cache/public_dashboard.json';
}

function public_count_pct_text(int $count, float $pct): string
{
    return '<span class="d-block">' . number_format($count, 0, ',', '.') . '</span><span class="d-block">(' . number_format($pct, 2, ',', '.') . '%)</span>';
}

function public_count_only_text(int $count): string
{
    return '<span class="d-block">' . number_format($count, 0, ',', '.') . '</span><span class="d-block">&nbsp;</span>';
}

function public_table_count_pct_text(int $count, int $target): string
{
    $pct = $target > 0 ? $count / $target * 100 : 0;
    return e(number_format($count, 0, ',', '.')) . ' <span class="public-table-pct">(' . e(number_format($pct, 2, ',', '.')) . '%)</span>';
}

function public_pendataan_count(array $row): int
{
    return (int)($row['submitted_by_pencacah'] ?? 0)
        + (int)($row['rejected_by_pengawas'] ?? 0)
        + (int)($row['draft_count'] ?? 0)
        + (int)($row['approved_by_pengawas'] ?? 0);
}

$cache = is_file(public_dashboard_cache_path())
    ? json_decode((string)file_get_contents(public_dashboard_cache_path()), true)
    : null;

if (!$cache || empty($cache['dashboards'][$code])) {
    http_response_code(503);
    $context = [
        'title' => 'Dashboard Publik SE 2026',
        'subtitle' => $code === '6400' ? '6400 - Provinsi Kalimantan Timur' : $code,
        'group_label' => $code === '6400' ? 'Kabupaten' : 'Kecamatan',
        'table_label' => $code === '6400' ? 'Kabupaten' : 'Kecamatan',
        'total_label' => 'Total',
    ];
    $cacheMissing = true;
    $fields = [];
    $statusColors = [];
    $rangeColors = [];
    $rows = [];
    $totals = [];
} else {
    $cacheMissing = false;
    $dashboard = $cache['dashboards'][$code];
    $context = $dashboard['context'];
    $rows = $dashboard['rows'];
    $totals = $dashboard['totals'];
    $fields = $cache['fields'];
    $statusColors = $cache['status_colors'];
    $rangeColors = $cache['range_colors'];
}

$targetTotal = (int)($totals['target'] ?? 0);
$submitApproveCount = public_pendataan_count($totals);
$submitApprovePct = $targetTotal > 0
    ? round($submitApproveCount / $targetTotal * 100, 2)
    : 0;
$completionPct = (int)($totals['subsls_total'] ?? 0) > 0
    ? round((int)($totals['selesai_count'] ?? 0) / (int)$totals['subsls_total'] * 100, 2)
    : 0;

$cards = [
    ['label' => 'Target', 'value' => public_count_only_text($targetTotal)],
    ['label' => 'Open', 'value' => public_count_pct_text((int)($totals['open_count'] ?? 0), $targetTotal ? (int)($totals['open_count'] ?? 0) / $targetTotal * 100 : 0)],
    ['label' => 'Submit', 'value' => public_count_pct_text((int)($totals['submitted_by_pencacah'] ?? 0), $targetTotal ? (int)($totals['submitted_by_pencacah'] ?? 0) / $targetTotal * 100 : 0)],
    ['label' => 'Reject', 'value' => public_count_pct_text((int)($totals['rejected_by_pengawas'] ?? 0), $targetTotal ? (int)($totals['rejected_by_pengawas'] ?? 0) / $targetTotal * 100 : 0)],
    ['label' => 'Pending', 'value' => public_count_pct_text((int)($totals['draft_count'] ?? 0), $targetTotal ? (int)($totals['draft_count'] ?? 0) / $targetTotal * 100 : 0)],
    ['label' => 'Approve', 'value' => public_count_pct_text((int)($totals['approved_by_pengawas'] ?? 0), $targetTotal ? (int)($totals['approved_by_pengawas'] ?? 0) / $targetTotal * 100 : 0)],
    ['label' => 'Progress Pendataan', 'value' => public_count_pct_text($submitApproveCount, $submitApprovePct)],
    ['label' => 'Selesai', 'value' => public_count_pct_text((int)($totals['selesai_count'] ?? 0), $completionPct)],
    ['label' => 'Total SubSLS', 'value' => public_count_only_text((int)($totals['subsls_total'] ?? 0))],
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
      display: grid;
      gap: 12px;
      grid-template-columns: minmax(190px, 1fr) auto minmax(220px, 1fr);
      padding: 14px 18px;
    }
    .public-logo-group {
      align-items: center;
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      justify-content: flex-end;
    }
    .public-logo-bps { max-height: 34px; max-width: 220px; object-fit: contain; }
    .public-logo-se { max-height: 38px; max-width: 125px; object-fit: contain; }
    .public-title {
      min-width: 260px;
      text-align: center;
    }
    .public-header > h1 {
      color: #111827;
      font-size: 1rem;
      font-weight: 700;
      justify-self: start;
      margin: 0;
      text-align: left;
    }
    .public-title span {
      color: #111827;
      display: block;
      font-size: 1.45rem;
      font-weight: 800;
      line-height: 1.15;
    }
    .public-updated-at {
      color: #4b5563;
      font-size: .86rem;
      font-weight: 600;
      margin-top: 2px;
    }
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
    .public-summary-table th,
    .public-summary-table td {
      vertical-align: middle;
      white-space: nowrap;
    }
    .public-summary-table tfoot td {
      font-weight: 800;
    }
    .public-table-pct {
      color: #2563eb;
      font-weight: 700;
    }
    @media (max-width: 767.98px) {
      .public-header {
        grid-template-columns: 1fr;
        text-align: center;
      }
      .public-logo-bps { max-width: 210px; }
      .public-logo-se { max-width: 125px; }
      .public-logo-group { justify-content: center; }
      .public-title { min-width: 0; }
      .public-header > h1 { justify-self: center; text-align: center; }
      .content-wrap { padding: 12px; }
      .public-title span { font-size: 1.35rem; }
      .public-chart-wrap,
      .public-chart-wrap.public-chart-wide { height: 330px; }
    }
  </style>
</head>
<body>
<header class="public-header">
  <h1><?= e($context['title']) ?></h1>
  <div class="public-title">
    <span><?= e($context['subtitle']) ?></span>
    <div class="public-updated-at">
      Update terakhir: <?= e($cache['generated_at_label'] ?? 'belum tersedia') ?>
    </div>
  </div>
  <div class="public-logo-group">
    <img class="public-logo-bps" src="assets/img/logo-bps-kaltim.png" alt="BPS Provinsi Kalimantan Timur">
    <img class="public-logo-se" src="assets/img/logo_Sensus_Ekonomi_2026.png" alt="Sensus Ekonomi 2026">
  </div>
</header>

<main class="content-wrap">
<?php if ($cacheMissing): ?>
  <div class="alert alert-warning mb-0">
    Dashboard publik belum tersedia. Superadmin perlu membuka menu <strong>Update Dashboard Publik</strong> dan menekan tombol update terlebih dahulu.
  </div>
<?php else: ?>
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
        <div class="card-header"><strong>Progress Pendataan per <?= e($context['group_label']) ?> (submit+reject+pending+approve)</strong></div>
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

  <div class="card">
    <div class="card-header"><strong>Tabel Ringkasan per <?= e($context['group_label']) ?></strong></div>
    <div class="card-body table-responsive p-0">
      <table class="table table-sm table-bordered table-striped mb-0 public-summary-table">
        <thead>
          <tr>
            <th><?= e($context['table_label']) ?></th>
            <th class="text-right">Target</th>
            <th class="text-right">Open</th>
            <th class="text-right">Submit</th>
            <th class="text-right">Reject</th>
            <th class="text-right">Pending</th>
            <th class="text-right">Approve</th>
            <th class="text-right">Progress Pendataan</th>
            <th class="text-right">Jumlah SubSLS Selesai</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $row): ?>
            <?php
              $rowTarget = (int)$row['target'];
              $submitApproveCount = public_pendataan_count($row);
            ?>
            <tr>
              <td><?= e($row['label']) ?></td>
              <td class="text-right"><?= number_format((int)$row['target'], 0, ',', '.') ?></td>
              <td class="text-right"><?= number_format((int)$row['open_count'], 0, ',', '.') ?></td>
              <td class="text-right"><?= public_table_count_pct_text((int)$row['submitted_by_pencacah'], $rowTarget) ?></td>
              <td class="text-right"><?= number_format((int)$row['rejected_by_pengawas'], 0, ',', '.') ?></td>
              <td class="text-right"><?= number_format((int)$row['draft_count'], 0, ',', '.') ?></td>
              <td class="text-right"><?= public_table_count_pct_text((int)$row['approved_by_pengawas'], $rowTarget) ?></td>
              <td class="text-right"><?= public_table_count_pct_text($submitApproveCount, $rowTarget) ?></td>
              <td class="text-right"><?= number_format((int)$row['selesai_count'], 0, ',', '.') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <?php
            $totalTarget = (int)$totals['target'];
            $totalSubmitApprove = public_pendataan_count($totals);
          ?>
          <tr>
            <td><?= e($context['total_label']) ?></td>
            <td class="text-right"><?= number_format((int)$totals['target'], 0, ',', '.') ?></td>
            <td class="text-right"><?= number_format((int)$totals['open_count'], 0, ',', '.') ?></td>
            <td class="text-right"><?= public_table_count_pct_text((int)$totals['submitted_by_pencacah'], $totalTarget) ?></td>
            <td class="text-right"><?= number_format((int)$totals['rejected_by_pengawas'], 0, ',', '.') ?></td>
            <td class="text-right"><?= number_format((int)$totals['draft_count'], 0, ',', '.') ?></td>
            <td class="text-right"><?= public_table_count_pct_text((int)$totals['approved_by_pengawas'], $totalTarget) ?></td>
            <td class="text-right"><?= public_table_count_pct_text($totalSubmitApprove, $totalTarget) ?></td>
            <td class="text-right"><?= number_format((int)$totals['selesai_count'], 0, ',', '.') ?></td>
          </tr>
        </tfoot>
    </table>
  </div>
  <div class="card-footer text-muted small">Progress Pendataan = submit+reject+pending+approve</div>
</div>
<?php endif; ?>
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
  return target ? Math.round((Number(row.submitted_by_pencacah || 0) + Number(row.rejected_by_pengawas || 0) + Number(row.draft_count || 0) + Number(row.approved_by_pengawas || 0)) / target * 10000) / 100 : 0;
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
      label: 'Persen Progress Pendataan',
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
