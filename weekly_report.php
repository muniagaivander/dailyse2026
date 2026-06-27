<?php
require __DIR__ . '/layout.php';
$user = require_role(['superadmin', 'admin_kab']);
ensure_completion_status_table();

function wr_date_label(string $date): string
{
    $months = ['01'=>'Januari','02'=>'Februari','03'=>'Maret','04'=>'April','05'=>'Mei','06'=>'Juni','07'=>'Juli','08'=>'Agustus','09'=>'September','10'=>'Oktober','11'=>'November','12'=>'Desember'];
    $time = strtotime($date);
    return date('d', $time) . ' ' . $months[date('m', $time)] . ' ' . date('Y', $time);
}

function wr_pct(int $count, int $target): float
{
    return $target > 0 ? round($count / $target * 100, 2) : 0.0;
}

function wr_pendataan_count(array $row): int
{
    return (int)($row['submitted_by_pencacah'] ?? 0)
        + (int)($row['rejected_by_pengawas'] ?? 0)
        + (int)($row['pending_count'] ?? 0)
        + (int)($row['approved_by_pengawas'] ?? 0);
}

function wr_scope(array $user): array
{
    if ($user['role'] === 'admin_kab') {
        $stmt = db()->prepare("SELECT nmkab FROM master_kab WHERE id=?");
        $stmt->execute([$user['kab_id']]);
        $kabName = (string)($stmt->fetchColumn() ?: $user['kab_id']);
        return [
            'title' => 'Weekly Report Kabupaten',
            'subtitle' => $user['kab_id'] . ' - ' . $kabName,
            'group_label' => 'Kecamatan',
            'label_expr' => "CONCAT(kc.kdkec,' - ',kc.nmkec)",
            'group_expr' => 'kc.id, kc.kdkec, kc.nmkec',
            'order_expr' => 'kc.kdkec, kc.nmkec',
            'where' => 'WHERE k.id=?',
            'params' => [$user['kab_id']],
        ];
    }
    return [
        'title' => 'Weekly Report Provinsi',
        'subtitle' => 'Provinsi Kalimantan Timur',
        'group_label' => 'Kabupaten',
        'label_expr' => "CONCAT(k.id,' - ',k.nmkab)",
        'group_expr' => 'k.id, k.nmkab',
        'order_expr' => 'k.id',
        'where' => '',
        'params' => [],
    ];
}

function wr_rows(array $scope, string $asOfDate): array
{
    $selects = [];
    foreach (array_keys(status_fields()) as $field) {
        $selects[] = "COALESCE(SUM(ds.$field),0) $field";
    }
    $stmt = db()->prepare("SELECT {$scope['label_expr']} label,
            COUNT(ms.id) subsls_total,
            COALESCE(SUM(ds.target),0) target,
            " . implode(',', $selects) . ",
            COALESCE(SUM(CASE WHEN cs.status_selesai='Selesai' THEN 1 ELSE 0 END),0) selesai_count
        FROM master_subsls ms
        JOIN master_sls sl ON sl.id=ms.sls_id
        JOIN master_desa d ON d.id=sl.desa_id
        JOIN master_kec kc ON kc.id=d.kec_id
        JOIN master_kab k ON k.id=kc.kab_id
        LEFT JOIN (
            SELECT ds1.*
            FROM daily_status ds1
            JOIN (
                SELECT subsls_id, MAX(tanggal) max_tanggal
                FROM daily_status
                WHERE tanggal <= ?
                GROUP BY subsls_id
            ) latest ON latest.subsls_id=ds1.subsls_id AND latest.max_tanggal=ds1.tanggal
        ) ds ON ds.subsls_id=ms.id
        LEFT JOIN subsls_completion_status cs ON cs.subsls_id=ms.id
        {$scope['where']}
        GROUP BY {$scope['group_expr']}
        ORDER BY {$scope['order_expr']}");
    $stmt->execute(array_merge([$asOfDate], $scope['params']));
    return $stmt->fetchAll();
}

function wr_staff_rows(array $user, string $roleField, string $asOfDate): array
{
    if ($user['role'] !== 'admin_kab' || !in_array($roleField, ['pengawas_email', 'pencacah_email'], true)) {
        return [];
    }
    $selects = [];
    foreach (array_keys(status_fields()) as $field) {
        $selects[] = "COALESCE(SUM(ds.$field),0) $field";
    }
    $stmt = db()->prepare("SELECT ms.$roleField label,
            COUNT(ms.id) subsls_total,
            COALESCE(SUM(ds.target),0) target,
            " . implode(',', $selects) . ",
            COALESCE(SUM(CASE WHEN cs.status_selesai='Selesai' THEN 1 ELSE 0 END),0) selesai_count
        FROM master_subsls ms
        JOIN master_sls sl ON sl.id=ms.sls_id
        JOIN master_desa d ON d.id=sl.desa_id
        JOIN master_kec kc ON kc.id=d.kec_id
        LEFT JOIN (
            SELECT ds1.*
            FROM daily_status ds1
            JOIN (
                SELECT subsls_id, MAX(tanggal) max_tanggal
                FROM daily_status
                WHERE tanggal <= ?
                GROUP BY subsls_id
            ) latest ON latest.subsls_id=ds1.subsls_id AND latest.max_tanggal=ds1.tanggal
        ) ds ON ds.subsls_id=ms.id
        LEFT JOIN subsls_completion_status cs ON cs.subsls_id=ms.id
        WHERE kc.kab_id=? AND ms.$roleField IS NOT NULL AND ms.$roleField <> ''
        GROUP BY ms.$roleField
        ORDER BY ms.$roleField");
    $stmt->execute([$asOfDate, $user['kab_id']]);
    return $stmt->fetchAll();
}

function wr_totals(array $rows): array
{
    $totals = array_fill_keys(array_merge(['target', 'subsls_total', 'selesai_count'], array_keys(status_fields())), 0);
    foreach ($rows as $row) {
        foreach ($totals as $key => $_) {
            $totals[$key] += (int)($row[$key] ?? 0);
        }
    }
    return $totals;
}

function wr_rows_with_delta(array $endRows, array $baseRows): array
{
    $baseByLabel = [];
    foreach ($baseRows as $row) {
        $baseByLabel[$row['label']] = $row;
    }
    foreach ($endRows as &$row) {
        $submitApprove = wr_pendataan_count($row);
        $base = $baseByLabel[$row['label']] ?? [];
        $baseSubmitApprove = wr_pendataan_count($base);
        $row['submit_approve_count'] = $submitApprove;
        $row['submit_approve_pct'] = wr_pct($submitApprove, (int)$row['target']);
        $row['weekly_delta_pct'] = round($row['submit_approve_pct'] - wr_pct($baseSubmitApprove, (int)($base['target'] ?? 0)), 2);
        $row['selesai_pct'] = wr_pct((int)$row['selesai_count'], (int)$row['subsls_total']);
    }
    unset($row);
    return $endRows;
}

function wr_rank_rows(array $rows, string $direction): array
{
    usort($rows, function ($a, $b) use ($direction) {
        $cmp = $b['submit_approve_pct'] <=> $a['submit_approve_pct'];
        if ($direction === 'asc') {
            $cmp = $a['submit_approve_pct'] <=> $b['submit_approve_pct'];
        }
        return $cmp ?: strcmp($a['label'], $b['label']);
    });
    return array_slice($rows, 0, 5);
}

function wr_card_html(string $label, string $value, string $sub = ''): string
{
    return '<div class="wr-card"><strong>' . e($value) . '</strong><span>' . e($sub) . '</span><p>' . e($label) . '</p></div>';
}

function wr_email_html(string $email): string
{
    if (!str_contains($email, '@')) {
        return e($email);
    }
    [$name, $domain] = explode('@', $email, 2);
    return e($name) . '<span class="email-at"></span>' . e($domain);
}

function wr_rank_table_html(string $title, string $labelHeader, array $rows): string
{
    $html = '<div class="card"><h2>' . e($title) . '</h2>';
    $html .= '<table><thead><tr><th>' . e($labelHeader) . '</th><th class="right">Progress Pendataan</th><th class="right">Kenaikan dari Minggu Lalu</th><th class="right">SubSLS Selesai</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        $html .= '<tr><td>' . wr_email_html((string)$row['label']) . '</td>'
            . '<td class="right">' . number_format((float)$row['submit_approve_pct'], 2, ',', '.') . '%</td>'
            . '<td class="right">' . number_format((float)$row['weekly_delta_pct'], 2, ',', '.') . ' poin</td>'
            . '<td class="right">' . number_format((float)$row['selesai_pct'], 2, ',', '.') . '%</td></tr>';
    }
    if (!$rows) {
        $html .= '<tr><td colspan="4" class="muted">Belum ada data.</td></tr>';
    }
    $html .= '</tbody></table></div>';
    return $html;
}

function wr_report_dir(): string
{
    return __DIR__ . '/reports';
}

function wr_report_url(string $filename): string
{
    return 'reports/' . rawurlencode($filename);
}

function wr_build_html(array $user, string $referenceDate): string
{
    $periodStart = date('Y-m-d', strtotime($referenceDate . ' -7 days'));
    $periodEnd = date('Y-m-d', strtotime($referenceDate . ' -1 day'));
    $baselineDate = date('Y-m-d', strtotime($periodStart . ' -1 day'));
    $scope = wr_scope($user);
    $endRows = wr_rows($scope, $periodEnd);
    $baseRows = wr_rows($scope, $baselineDate);
    $rows = wr_rows_with_delta($endRows, $baseRows);
    $totals = wr_totals($endRows);
    $baseTotals = wr_totals($baseRows);
    $submitApproveTotal = wr_pendataan_count($totals);
    $baseSubmitApproveTotal = wr_pendataan_count($baseTotals);
    $submitApprovePct = wr_pct($submitApproveTotal, (int)$totals['target']);
    $weeklyDeltaPct = round($submitApprovePct - wr_pct($baseSubmitApproveTotal, (int)$baseTotals['target']), 2);
    $selesaiPct = wr_pct((int)$totals['selesai_count'], (int)$totals['subsls_total']);
    $targetDate = '2026-08-31';
    $remainingPct = max(0, 100 - $submitApprovePct);
    $weeksLeft = max(0.1, (strtotime($targetDate) - strtotime($periodEnd)) / (7 * 86400));
    $requiredWeeklyPct = round($remainingPct / $weeksLeft, 2);
    $projectedText = $weeklyDeltaPct <= 0
        ? 'Dengan kenaikan minggu ini yang belum positif, proyeksi penyelesaian perlu perhatian khusus.'
        : (($weeklyDeltaPct >= $requiredWeeklyPct)
            ? 'Dengan ritme minggu ini, progress berpeluang mencapai 100% sebelum/sekitar 31 Agustus 2026.'
            : 'Dengan ritme minggu ini, progress belum cukup untuk mencapai 100% pada 31 Agustus 2026.');

    $trendLabels = [];
    $trendMap = [];
    for ($i = 0; $i < 7; $i++) {
        $day = date('Y-m-d', strtotime($periodStart . " +{$i} days"));
        $trendLabels[] = $day;
        foreach (wr_rows_with_delta(wr_rows($scope, $day), wr_rows($scope, date('Y-m-d', strtotime($day . ' -7 days')))) as $row) {
            if (!isset($trendMap[$row['label']])) {
                $trendMap[$row['label']] = [];
            }
            $trendMap[$row['label']][] = $row['submit_approve_pct'];
        }
    }
    $colors = ['#2563eb','#16a34a','#dc2626','#f59e0b','#0f766e','#7c3aed','#0891b2','#be123c','#4d7c0f','#9333ea','#64748b','#ea580c'];
    $datasets = [];
    $colorIndex = 0;
    foreach ($trendMap as $label => $values) {
        $color = $colors[$colorIndex % count($colors)];
        $datasets[] = ['label' => $label, 'data' => $values, 'borderColor' => $color, 'backgroundColor' => $color, 'tension' => .25];
        $colorIndex++;
    }
    $topRows = wr_rank_rows($rows, 'desc');
    $attentionRows = wr_rank_rows($rows, 'asc');
    $topPengawasRows = [];
    $attentionPengawasRows = [];
    $topPencacahRows = [];
    $attentionPencacahRows = [];
    if ($user['role'] === 'admin_kab') {
        $pengawasRows = wr_rows_with_delta(
            wr_staff_rows($user, 'pengawas_email', $periodEnd),
            wr_staff_rows($user, 'pengawas_email', $baselineDate)
        );
        $pencacahRows = wr_rows_with_delta(
            wr_staff_rows($user, 'pencacah_email', $periodEnd),
            wr_staff_rows($user, 'pencacah_email', $baselineDate)
        );
        $topPengawasRows = wr_rank_rows($pengawasRows, 'desc');
        $attentionPengawasRows = wr_rank_rows($pengawasRows, 'asc');
        $topPencacahRows = wr_rank_rows($pencacahRows, 'desc');
        $attentionPencacahRows = wr_rank_rows($pencacahRows, 'asc');
    }
    $submitApprovePcts = array_map(fn($row) => (float)$row['submit_approve_pct'], $rows);
    $maxSubmitApprovePct = $submitApprovePcts ? max($submitApprovePcts) : null;
    $minSubmitApprovePct = $submitApprovePcts ? min($submitApprovePcts) : null;

    ob_start();
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($scope['title']) ?> | <?= e($periodStart) ?> - <?= e($periodEnd) ?></title>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
  <style>
    body { background:#f3f4f6; color:#111827; font-family: Arial, sans-serif; margin:0; }
    .wrap { margin:0 auto; max-width:1180px; padding:22px; }
    .toolbar { margin-bottom:14px; text-align:right; }
    .toolbar button { background:#2563eb; border:0; border-radius:4px; color:#fff; cursor:pointer; font-weight:700; padding:9px 14px; }
    .cover, .card { background:#fff; border:1px solid #e5e7eb; border-radius:6px; margin-bottom:14px; padding:16px; }
    h1 { font-size:24px; margin:0 0 4px; }
    h2 { font-size:17px; margin:0 0 10px; }
    .muted { color:#6b7280; }
    .cards { display:grid; gap:10px; grid-template-columns: repeat(4, minmax(0, 1fr)); margin-bottom:14px; }
    .wr-card { background:#fff; border:1px solid #e5e7eb; border-radius:6px; padding:14px; }
    .wr-card strong { display:block; font-size:22px; line-height:1.1; }
    .wr-card span { color:#2563eb; display:block; font-weight:700; min-height:18px; }
    .wr-card p { color:#4b5563; margin:5px 0 0; }
    .grid2 { display:grid; gap:14px; grid-template-columns:1fr 1fr; }
    .chart { height:340px; position:relative; }
    table { border-collapse:collapse; font-size:12px; width:100%; }
    th, td { border:1px solid #d1d5db; padding:6px 7px; white-space:nowrap; }
    th { background:#f9fafb; text-align:left; }
    .right { text-align:right; }
    .summary { line-height:1.55; margin:0; }
    .best-progress { color:#2563eb; font-weight:800; }
    .low-progress { color:#dc2626; font-weight:800; }
    .email-at::before { content:"\0040"; }
    @page {
      size: A4 landscape;
      margin: 10mm;
    }
    @media print {
      body { background:#fff; }
      .toolbar { display:none; }
      .wrap { max-width:none; padding:0; }
      .card, .cover, .wr-card { break-inside:avoid; }
    }
  </style>
</head>
<body>
<div class="wrap">
  <div class="toolbar"><button onclick="window.print()">Simpan sebagai PDF</button></div>
  <div class="cover">
    <h1><?= e($scope['title']) ?></h1>
    <div><strong><?= e($scope['subtitle']) ?></strong></div>
    <div class="muted">Periode <?= e(wr_date_label($periodStart)) ?> - <?= e(wr_date_label($periodEnd)) ?></div>
  </div>
  <div class="cards">
    <?= wr_card_html('Target', number_format((int)$totals['target'], 0, ',', '.')) ?>
    <?= wr_card_html('Open', number_format((int)$totals['open_count'], 0, ',', '.'), number_format(wr_pct((int)$totals['open_count'], (int)$totals['target']), 2, ',', '.') . '%') ?>
    <?= wr_card_html('Draft', number_format((int)$totals['draft_count'], 0, ',', '.'), number_format(wr_pct((int)$totals['draft_count'], (int)$totals['target']), 2, ',', '.') . '%') ?>
    <?= wr_card_html('Submit', number_format((int)$totals['submitted_by_pencacah'], 0, ',', '.'), number_format(wr_pct((int)$totals['submitted_by_pencacah'], (int)$totals['target']), 2, ',', '.') . '%') ?>
    <?= wr_card_html('Reject', number_format((int)$totals['rejected_by_pengawas'], 0, ',', '.'), number_format(wr_pct((int)$totals['rejected_by_pengawas'], (int)$totals['target']), 2, ',', '.') . '%') ?>
    <?= wr_card_html('Pending', number_format((int)$totals['pending_count'], 0, ',', '.'), number_format(wr_pct((int)$totals['pending_count'], (int)$totals['target']), 2, ',', '.') . '%') ?>
    <?= wr_card_html('Approved', number_format((int)$totals['approved_by_pengawas'], 0, ',', '.'), number_format(wr_pct((int)$totals['approved_by_pengawas'], (int)$totals['target']), 2, ',', '.') . '%') ?>
    <?= wr_card_html('Total SubSLS', number_format((int)$totals['subsls_total'], 0, ',', '.')) ?>
    <?= wr_card_html('SubSLS Selesai', number_format((int)$totals['selesai_count'], 0, ',', '.'), number_format($selesaiPct, 2, ',', '.') . '%') ?>
  </div>
  <div class="card">
    <strong><em>Progress Pendataan = Submit+Reject+Pending+Approve</em></strong>
  </div>
  <div class="card">
    <h2>Summary</h2>
    <p class="summary">
      Progress Pendataan sampai akhir minggu ini mencapai <strong><?= number_format($submitApprovePct, 2, ',', '.') ?>%</strong>,
      naik <strong><?= number_format($weeklyDeltaPct, 2, ',', '.') ?> poin</strong> dibanding akhir minggu sebelumnya.
      Untuk mencapai 100% pada 31 Agustus 2026, rata-rata kenaikan yang dibutuhkan sekitar
      <strong><?= number_format($requiredWeeklyPct, 2, ',', '.') ?> poin per minggu</strong>.
      <?= e($projectedText) ?>
      Wilayah dengan progress terendah perlu diprioritaskan untuk percepatan dan evaluasi hambatan lapangan.
    </p>
  </div>
  <div class="grid2">
    <div class="card">
      <h2>5 <?= e($scope['group_label']) ?> Tertinggi</h2>
      <table><thead><tr><th><?= e($scope['group_label']) ?></th><th class="right">Progress Pendataan</th><th class="right">Kenaikan dari Minggu Lalu</th><th class="right">SubSLS Selesai</th></tr></thead><tbody>
      <?php foreach ($topRows as $row): ?><tr><td><?= e($row['label']) ?></td><td class="right"><?= number_format((float)$row['submit_approve_pct'], 2, ',', '.') ?>%</td><td class="right"><?= number_format((float)$row['weekly_delta_pct'], 2, ',', '.') ?> poin</td><td class="right"><?= number_format((float)$row['selesai_pct'], 2, ',', '.') ?>%</td></tr><?php endforeach; ?>
      </tbody></table>
    </div>
    <div class="card">
      <h2>5 <?= e($scope['group_label']) ?> Perlu Perhatian</h2>
      <table><thead><tr><th><?= e($scope['group_label']) ?></th><th class="right">Progress Pendataan</th><th class="right">Kenaikan dari Minggu Lalu</th><th class="right">SubSLS Selesai</th></tr></thead><tbody>
      <?php foreach ($attentionRows as $row): ?><tr><td><?= e($row['label']) ?></td><td class="right"><?= number_format((float)$row['submit_approve_pct'], 2, ',', '.') ?>%</td><td class="right"><?= number_format((float)$row['weekly_delta_pct'], 2, ',', '.') ?> poin</td><td class="right"><?= number_format((float)$row['selesai_pct'], 2, ',', '.') ?>%</td></tr><?php endforeach; ?>
      </tbody></table>
    </div>
  </div>
  <?php if ($user['role'] === 'admin_kab'): ?>
    <div class="grid2">
      <?= wr_rank_table_html('5 Pengawas Tertinggi', 'Pengawas', $topPengawasRows) ?>
      <?= wr_rank_table_html('5 Pengawas Perlu Perhatian', 'Pengawas', $attentionPengawasRows) ?>
    </div>
    <div class="grid2">
      <?= wr_rank_table_html('5 Pencacah Tertinggi', 'Pencacah', $topPencacahRows) ?>
      <?= wr_rank_table_html('5 Pencacah Perlu Perhatian', 'Pencacah', $attentionPencacahRows) ?>
    </div>
  <?php endif; ?>
  <div class="card">
    <h2>Progress Pendataan Harian per <?= e($scope['group_label']) ?></h2>
    <div class="chart"><canvas id="trendChart"></canvas></div>
  </div>
  <div class="card">
    <h2>Ringkasan Per <?= e($scope['group_label']) ?></h2>
    <table><thead><tr><th><?= e($scope['group_label']) ?></th><th class="right">Target</th><th class="right">Open</th><th class="right">Draft</th><th class="right">Submit</th><th class="right">Reject</th><th class="right">Pending</th><th class="right">Approved</th><th class="right">Progress Pendataan</th><th class="right">Kenaikan</th><th class="right">SubSLS Selesai</th></tr></thead><tbody>
    <?php foreach ($rows as $row): ?><?php
      $submitClass = '';
      if ($maxSubmitApprovePct !== null && abs((float)$row['submit_approve_pct'] - $maxSubmitApprovePct) < 0.001) {
          $submitClass = ' best-progress';
      }
      if ($minSubmitApprovePct !== null && abs((float)$row['submit_approve_pct'] - $minSubmitApprovePct) < 0.001) {
          $submitClass = ' low-progress';
      }
    ?><tr><td><?= e($row['label']) ?></td><td class="right"><?= number_format((int)$row['target'], 0, ',', '.') ?></td><td class="right"><?= number_format((int)$row['open_count'], 0, ',', '.') ?></td><td class="right"><?= number_format((int)$row['draft_count'], 0, ',', '.') ?></td><td class="right"><?= number_format((int)$row['submitted_by_pencacah'], 0, ',', '.') ?></td><td class="right"><?= number_format((int)$row['rejected_by_pengawas'], 0, ',', '.') ?></td><td class="right"><?= number_format((int)$row['pending_count'], 0, ',', '.') ?></td><td class="right"><?= number_format((int)$row['approved_by_pengawas'], 0, ',', '.') ?></td><td class="right<?= e($submitClass) ?>"><?= number_format((int)$row['submit_approve_count'], 0, ',', '.') ?> (<?= number_format((float)$row['submit_approve_pct'], 2, ',', '.') ?>%)</td><td class="right"><?= number_format((float)$row['weekly_delta_pct'], 2, ',', '.') ?> poin</td><td class="right"><?= number_format((int)$row['selesai_count'], 0, ',', '.') ?> (<?= number_format((float)$row['selesai_pct'], 2, ',', '.') ?>%)</td></tr><?php endforeach; ?>
    </tbody></table>
    <p class="muted" style="font-size:12px;margin:8px 0 0;">Progress Pendataan = submit+reject+pending+approve</p>
  </div>
</div>
<script>
new Chart(document.getElementById('trendChart'), {
  type: 'line',
  data: { labels: <?= json_encode($trendLabels) ?>, datasets: <?= json_encode($datasets) ?> },
  options: {
    animation:false,
    maintainAspectRatio:false,
    responsive:true,
    plugins:{legend:{position:'bottom'}},
    scales:{y:{min:0,suggestedMax:chartYMax(<?= json_encode($datasets) ?>),ticks:{callback:v=>v+'%'}}}
  }
});
function chartYMax(datasets) {
  const maxValue = Math.max(0, ...datasets.flatMap(dataset => dataset.data.map(Number)));
  if (maxValue <= 10) return 10;
  if (maxValue <= 25) return 25;
  if (maxValue <= 50) return 50;
  return 100;
}
</script>
</body>
</html>
<?php
    return (string)ob_get_clean();
}

$referenceDate = $_POST['tanggal'] ?? $_GET['tanggal'] ?? today();
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $referenceDate) || $referenceDate > today()) {
    $referenceDate = today();
}
$periodStart = date('Y-m-d', strtotime($referenceDate . ' -7 days'));
$periodEnd = date('Y-m-d', strtotime($referenceDate . ' -1 day'));
$generatedFile = $_GET['file'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dir = wr_report_dir();
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        flash('error', 'Folder reports gagal dibuat.');
        redirect('weekly_report.php');
    }
    $scope = wr_scope($user);
    $prefix = $user['role'] === 'admin_kab' ? $user['kab_id'] : 'provinsi';
    $filename = 'weekly_report_' . $prefix . '_' . $periodStart . '_' . $periodEnd . '_' . date('YmdHis') . '.html';
    file_put_contents($dir . '/' . $filename, wr_build_html($user, $referenceDate), LOCK_EX);
    flash('success', 'Weekly report berhasil dibuat.');
    redirect('weekly_report.php?tanggal=' . urlencode($referenceDate) . '&file=' . urlencode($filename));
}

render_header('Weekly Report');
?>
<form class="card card-body mb-3" method="post" data-progress-submit data-progress-title="Generate Weekly Report..." data-progress-text="Sistem sedang menyusun report mingguan.">
  <div class="form-row align-items-end">
    <div class="form-group col-md-3">
      <label>Tanggal Acuan</label>
      <input class="form-control" type="date" name="tanggal" max="<?= e(today()) ?>" value="<?= e($referenceDate) ?>">
    </div>
    <div class="form-group col-md-5">
      <label>Periode</label>
      <input class="form-control" value="<?= e(wr_date_label($periodStart) . ' - ' . wr_date_label($periodEnd)) ?>" disabled>
    </div>
    <div class="form-group col-md-4">
      <button class="btn btn-primary" type="submit"><i class="fas fa-file-pdf mr-1"></i>Generate Weekly Report</button>
    </div>
  </div>
  <div class="text-muted small">Jika dibuka pada 21 Juni, periode otomatis 14 Juni sampai 20 Juni.</div>
</form>

<?php if ($generatedFile && preg_match('/^weekly_report_[a-zA-Z0-9_-]+_\d{4}-\d{2}-\d{2}_\d{4}-\d{2}-\d{2}_\d{14}\.html$/', $generatedFile) && is_file(wr_report_dir() . '/' . $generatedFile)): ?>
  <div class="card card-body">
    <h5 class="mb-2">Report Siap</h5>
    <p class="text-muted mb-3">File report sudah disimpan dengan layout cetak A4 landscape.</p>
    <a class="btn btn-success" download href="<?= e(wr_report_url($generatedFile)) ?>"><i class="fas fa-download mr-1"></i>Download Weekly Report</a>
  </div>
<?php endif; ?>
<?php render_footer(); ?>
