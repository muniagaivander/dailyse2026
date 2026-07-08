<?php
require __DIR__ . '/layout.php';
require_once __DIR__ . '/performance_cache.php';
$user = require_login();
ensure_completion_status_table();

$fields = status_fields();
$statusColors = ['#2563eb', '#f59e0b', '#16a34a', '#dc2626', '#7c3aed', '#0f766e'];
$rangeColors = [
    ['label' => '< 20%', 'color' => '#dc2626'],
    ['label' => '20% - < 40%', 'color' => '#f59e0b'],
    ['label' => '40% - < 75%', 'color' => '#2563eb'],
    ['label' => '75% - 100%', 'color' => '#16a34a'],
];
$activeTab = $_GET['tab'] ?? 'submit_approve';
$allowedTabs = ['submit_approve', 'status', 'selesai'];
$canSeePerformance = in_array($user['role'], ['superadmin', 'admin_kab', 'viewer_prov', 'viewer_kab'], true);
if ($canSeePerformance) {
    $allowedTabs[] = 'performa_pengawas';
    $allowedTabs[] = 'performa_pencacah';
}
if (!in_array($activeTab, $allowedTabs, true)) {
    $activeTab = 'submit_approve';
}

$filters = [
    'kab_id' => $_GET['kab_id'] ?? '',
    'kec_id' => $_GET['kec_id'] ?? '',
    'desa_id' => $_GET['desa_id'] ?? '',
    'pengawas_email' => normalize_email($_GET['pengawas_email'] ?? ''),
    'pencacah_email' => normalize_email($_GET['pencacah_email'] ?? ''),
];
if (in_array($user['role'], ['admin_kab', 'viewer_kab'], true)) {
    $filters['kab_id'] = $user['kab_id'];
}
if ($user['role'] === 'pengawas') {
    $filters['pengawas_email'] = $user['email'];
}
if ($user['role'] === 'pencacah') {
    $filters['pencacah_email'] = $user['email'];
}

function dashboard_filter_options(array $user, array $filters): array
{
    $out = ['kabupaten' => [], 'kecamatan' => [], 'desa' => [], 'pengawas' => [], 'pencacah' => []];
    if (in_array($user['role'], ['superadmin', 'viewer_prov'], true)) {
        $out['kabupaten'] = db()->query("SELECT id value, CONCAT(id,' - ',nmkab) label FROM master_kab ORDER BY id")->fetchAll();
    } elseif (in_array($user['role'], ['admin_kab', 'viewer_kab'], true)) {
        $stmt = db()->prepare("SELECT id value, CONCAT(id,' - ',nmkab) label FROM master_kab WHERE id=?");
        $stmt->execute([$user['kab_id']]);
        $out['kabupaten'] = $stmt->fetchAll();
    }

    if (!empty($filters['kab_id'])) {
        $stmt = db()->prepare("SELECT id value, CONCAT(kdkec,' - ',nmkec) label FROM master_kec WHERE kab_id=? ORDER BY kdkec, nmkec");
        $stmt->execute([$filters['kab_id']]);
        $out['kecamatan'] = $stmt->fetchAll();
    }
    if (!empty($filters['kec_id'])) {
        $stmt = db()->prepare("SELECT id value, CONCAT(kddesa,' - ',nmdesa) label FROM master_desa WHERE kec_id=? ORDER BY kddesa, nmdesa");
        $stmt->execute([$filters['kec_id']]);
        $out['desa'] = $stmt->fetchAll();
    }
    if (!empty($filters['desa_id'])) {
        $stmt = db()->prepare("SELECT DISTINCT ms.pengawas_email value, up.name
            FROM master_subsls ms
            JOIN master_sls sl ON sl.id=ms.sls_id
            LEFT JOIN users up ON up.email=ms.pengawas_email
            WHERE sl.desa_id=? AND ms.pengawas_email IS NOT NULL AND ms.pengawas_email <> ''
            ORDER BY up.name, ms.pengawas_email");
        $stmt->execute([$filters['desa_id']]);
        $out['pengawas'] = array_map(fn($row) => [
            'value' => $row['value'],
            'label' => petugas_label($row['value'], $row['name'] ?? ''),
        ], $stmt->fetchAll());
    }

    if ($user['role'] === 'pengawas') {
        $stmt = db()->prepare("SELECT DISTINCT ms.pencacah_email value, uc.name
            FROM master_subsls ms
            LEFT JOIN users uc ON uc.email=ms.pencacah_email
            WHERE ms.pengawas_email=? AND ms.pencacah_email IS NOT NULL AND ms.pencacah_email <> ''
            ORDER BY uc.name, ms.pencacah_email");
        $stmt->execute([$user['email']]);
        $out['pencacah'] = array_map(fn($row) => [
            'value' => $row['value'],
            'label' => petugas_label($row['value'], $row['name'] ?? ''),
        ], $stmt->fetchAll());
    } elseif (!empty($filters['pengawas_email'])) {
        $where = ['ms.pengawas_email=?', "ms.pencacah_email IS NOT NULL", "ms.pencacah_email <> ''"];
        $params = [$filters['pengawas_email']];
        if (!empty($filters['kab_id'])) {
            $where[] = 'k.id=?';
            $params[] = $filters['kab_id'];
        }
        if (!empty($filters['kec_id'])) {
            $where[] = 'kc.id=?';
            $params[] = $filters['kec_id'];
        }
        if (!empty($filters['desa_id'])) {
            $where[] = 'd.id=?';
            $params[] = $filters['desa_id'];
        }
        $stmt = db()->prepare("SELECT DISTINCT ms.pencacah_email value, uc.name
            FROM master_subsls ms
            JOIN master_sls sl ON sl.id=ms.sls_id
            JOIN master_desa d ON d.id=sl.desa_id
            JOIN master_kec kc ON kc.id=d.kec_id
            JOIN master_kab k ON k.id=kc.kab_id
            LEFT JOIN users uc ON uc.email=ms.pencacah_email
            WHERE " . implode(' AND ', $where) . "
            ORDER BY uc.name, ms.pencacah_email");
        $stmt->execute($params);
        $out['pencacah'] = array_map(fn($row) => [
            'value' => $row['value'],
            'label' => petugas_label($row['value'], $row['name'] ?? ''),
        ], $stmt->fetchAll());
    }
    return $out;
}

function dashboard_where(array $user, array $filters): array
{
    $where = [];
    $params = [];
    if (in_array($user['role'], ['admin_kab', 'viewer_kab'], true)) {
        $where[] = 'k.id=?';
        $params[] = $user['kab_id'];
    } elseif (!empty($filters['kab_id'])) {
        $where[] = 'k.id=?';
        $params[] = $filters['kab_id'];
    }
    if (!empty($filters['kec_id'])) {
        $where[] = 'kc.id=?';
        $params[] = $filters['kec_id'];
    }
    if (!empty($filters['desa_id'])) {
        $where[] = 'd.id=?';
        $params[] = $filters['desa_id'];
    }
    if ($user['role'] === 'pengawas') {
        $where[] = 'ms.pengawas_email=?';
        $params[] = $user['email'];
    } elseif (!empty($filters['pengawas_email'])) {
        $where[] = 'ms.pengawas_email=?';
        $params[] = $filters['pengawas_email'];
    }
    if ($user['role'] === 'pencacah') {
        $where[] = 'ms.pencacah_email=?';
        $params[] = $user['email'];
    } elseif (!empty($filters['pencacah_email'])) {
        $where[] = 'ms.pencacah_email=?';
        $params[] = $filters['pencacah_email'];
    }
    return [$where ? 'WHERE ' . implode(' AND ', $where) : '', $params];
}

function dashboard_grouping(array $user, array $filters): array
{
    if ($user['role'] === 'pencacah') {
        return ['ms.id', "CONCAT(sl.nmsls,' - ',ms.kdsubsls)"];
    }
    if (!empty($filters['pencacah_email'])) {
        return ['ms.id', "CONCAT(sl.nmsls,' - ',ms.kdsubsls)"];
    }
    if ($user['role'] === 'pengawas' || !empty($filters['pengawas_email'])) {
        return ['ms.pencacah_email', 'ms.pencacah_email'];
    }
    if (!empty($filters['desa_id'])) {
        return ['ms.pengawas_email', 'ms.pengawas_email'];
    }
    if (!empty($filters['kec_id'])) {
        return ['d.id', "CONCAT(d.kddesa,' - ',d.nmdesa)"];
    }
    if (!empty($filters['kab_id'])) {
        return ['kc.id', "CONCAT(kc.kdkec,' - ',kc.nmkec)"];
    }
    return ['k.id', "CONCAT(k.id,' - ',k.nmkab)"];
}

function dashboard_rows(array $user, array $filters, array $fields): array
{
    [$sqlWhere, $params] = dashboard_where($user, $filters);
    [$groupExpr, $labelExpr] = dashboard_grouping($user, $filters);
    $selects = [];
    foreach (array_keys($fields) as $f) {
        $selects[] = "COALESCE(SUM(ss.$f),0) $f";
    }
    $stmt = db()->prepare("SELECT $labelExpr label, COALESCE(SUM(ss.target),0) target, " . implode(',', $selects) . ",
            COUNT(ms.id) subsls_total,
            COALESCE(SUM(CASE WHEN cs.status_selesai='Selesai' THEN 1 ELSE 0 END),0) selesai_count
        FROM master_subsls ms
        JOIN master_sls sl ON sl.id=ms.sls_id
        JOIN master_desa d ON d.id=sl.desa_id
        JOIN master_kec kc ON kc.id=d.kec_id
        JOIN master_kab k ON k.id=kc.kab_id
        JOIN master_prov p ON p.id=k.prov_id
        LEFT JOIN subsls_status ss ON ss.subsls_id=ms.id
        LEFT JOIN subsls_completion_status cs ON cs.subsls_id=ms.id
        $sqlWhere
        GROUP BY $groupExpr, label
        ORDER BY label");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    if ($groupExpr === 'ms.pencacah_email' || $groupExpr === 'ms.pengawas_email') {
        foreach ($rows as &$row) {
            $row['label'] = petugas_label_by_email($row['label']);
        }
        unset($row);
    }
    return $rows;
}

function dashboard_totals(array $rows, array $fields): array
{
    $totals = array_fill_keys(array_merge(['target', 'subsls_total', 'selesai_count'], array_keys($fields)), 0);
    foreach ($rows as $row) {
        foreach ($totals as $key => $_) {
            $totals[$key] += (int)($row[$key] ?? 0);
        }
    }
    return $totals;
}

function dashboard_pendataan_count(array $row): int
{
    return (int)($row['submitted_by_pencacah'] ?? 0)
        + (int)($row['rejected_by_pengawas'] ?? 0)
        + (int)($row['pending_count'] ?? 0)
        + (int)($row['approved_by_pengawas'] ?? 0);
}

function dashboard_datetime_label(?string $datetime): string
{
    global $APP_TIMEZONE, $DB_TIMEZONE;
    if (!$datetime) {
        return '-';
    }
    $months = [
        '01' => 'Januari',
        '02' => 'Februari',
        '03' => 'Maret',
        '04' => 'April',
        '05' => 'Mei',
        '06' => 'Juni',
        '07' => 'Juli',
        '08' => 'Agustus',
        '09' => 'September',
        '10' => 'Oktober',
        '11' => 'November',
        '12' => 'Desember',
    ];
    try {
        $sourceTimezone = new DateTimeZone($DB_TIMEZONE ?: 'UTC');
        $targetTimezone = new DateTimeZone($APP_TIMEZONE ?: 'Asia/Makassar');
        $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $datetime, $sourceTimezone);
        if (!$date) {
            $date = new DateTimeImmutable($datetime, $sourceTimezone);
        }
        $date = $date->setTimezone($targetTimezone);
    } catch (Throwable $e) {
        return '-';
    }
    return $date->format('d') . ' ' . $months[$date->format('m')] . ' ' . $date->format('Y H:i') . ' WITA';
}

function dashboard_wita_datetime_label(?string $datetime): string
{
    if (!$datetime) {
        return '-';
    }
    $months = [
        '01' => 'Januari',
        '02' => 'Februari',
        '03' => 'Maret',
        '04' => 'April',
        '05' => 'Mei',
        '06' => 'Juni',
        '07' => 'Juli',
        '08' => 'Agustus',
        '09' => 'September',
        '10' => 'Oktober',
        '11' => 'November',
        '12' => 'Desember',
    ];
    try {
        $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $datetime, new DateTimeZone('Asia/Makassar'));
        if (!$date) {
            $date = new DateTimeImmutable($datetime, new DateTimeZone('Asia/Makassar'));
        }
    } catch (Throwable $e) {
        return '-';
    }
    return $date->format('d') . ' ' . $months[$date->format('m')] . ' ' . $date->format('Y H:i') . ' WITA';
}

function dashboard_latest_status_label(array $user, array $filters): string
{
    [$sqlWhere, $params] = dashboard_where($user, $filters);
    $stmt = db()->prepare("SELECT MAX(ss.last_update)
        FROM master_subsls ms
        JOIN master_sls sl ON sl.id=ms.sls_id
        JOIN master_desa d ON d.id=sl.desa_id
        JOIN master_kec kc ON kc.id=d.kec_id
        JOIN master_kab k ON k.id=kc.kab_id
        LEFT JOIN subsls_status ss ON ss.subsls_id=ms.id
        $sqlWhere");
    $stmt->execute($params);
    return dashboard_wita_datetime_label($stmt->fetchColumn() ?: null);
}

function dashboard_rank_badge(int $rank): string
{
    return match ($rank) {
        1 => '<span class="rank-badge rank-1"><i class="fas fa-trophy mr-1"></i>Rank 1</span>',
        2 => '<span class="rank-badge rank-2"><i class="fas fa-medal mr-1"></i>Rank 2</span>',
        3 => '<span class="rank-badge rank-3"><i class="fas fa-award mr-1"></i>Rank 3</span>',
        default => '<span class="rank-badge">Rank ' . $rank . '</span>',
    };
}

function performance_rows(string $roleField, string $kabId, string $direction): array
{
    $order = $direction === 'desc' ? 'DESC' : 'ASC';
    $limit = $direction === 'desc' ? 'LIMIT 10' : '';
    $whereKab = $kabId === '6400' ? '' : 'kc.kab_id=? AND';
        $stmt = db()->prepare("SELECT ms.$roleField email,
            u.name petugas_name,
            GROUP_CONCAT(DISTINCT kc.kab_id ORDER BY kc.kab_id SEPARATOR ', ') kab_codes,
            GROUP_CONCAT(DISTINCT kc.nmkec ORDER BY kc.kab_id, kc.kdkec SEPARATOR ', ') wilayah_kerja_kecamatan,
            GROUP_CONCAT(DISTINCT d.nmdesa ORDER BY kc.kdkec, d.kddesa SEPARATOR ', ') wilayah_kerja,
            COALESCE(SUM(ss.target),0) target,
            COALESCE(SUM(ss.submitted_by_pencacah),0) submitted_by_pencacah,
            COALESCE(SUM(ss.rejected_by_pengawas),0) rejected_by_pengawas,
            COALESCE(SUM(ss.draft_count),0) draft_count,
            COALESCE(SUM(ss.pending_count),0) pending_count,
            COALESCE(SUM(ss.approved_by_pengawas),0) approved_by_pengawas,
            COUNT(ms.id) subsls_total,
            COALESCE(SUM(CASE WHEN cs.status_selesai='Selesai' THEN 1 ELSE 0 END),0) selesai_count,
            CASE WHEN COALESCE(SUM(ss.target),0)>0
                THEN ROUND((COALESCE(SUM(ss.submitted_by_pencacah),0)+COALESCE(SUM(ss.rejected_by_pengawas),0)+COALESCE(SUM(ss.pending_count),0)+COALESCE(SUM(ss.approved_by_pengawas),0))/COALESCE(SUM(ss.target),0)*100,2)
                ELSE 0 END submit_approve_pct,
            CASE WHEN COUNT(ms.id)>0
                THEN ROUND(COALESCE(SUM(CASE WHEN cs.status_selesai='Selesai' THEN 1 ELSE 0 END),0)/COUNT(ms.id)*100,2)
                ELSE 0 END selesai_pct
        FROM master_subsls ms
        JOIN master_sls sl ON sl.id=ms.sls_id
        JOIN master_desa d ON d.id=sl.desa_id
        JOIN master_kec kc ON kc.id=d.kec_id
        LEFT JOIN subsls_status ss ON ss.subsls_id=ms.id
        LEFT JOIN subsls_completion_status cs ON cs.subsls_id=ms.id
        LEFT JOIN users u ON u.email=ms.$roleField
        WHERE $whereKab ms.$roleField IS NOT NULL AND ms.$roleField <> ''
        GROUP BY ms.$roleField, u.name
        ORDER BY submit_approve_pct $order, selesai_pct $order, petugas_name ASC, email ASC
        $limit");
    $stmt->execute($kabId === '6400' ? [] : [$kabId]);
    return $stmt->fetchAll();
}

function performance_attention_threshold(): array
{
    $today = today();
    if ($today <= '2026-07-15') {
        return ['date' => '2026-07-15', 'pct' => 40];
    }
    if ($today <= '2026-07-30') {
        return ['date' => '2026-07-30', 'pct' => 65];
    }
    return ['date' => '2026-08-15', 'pct' => 85];
}

function performance_attention_rows(string $roleField, string $kabId, float $threshold): array
{
    $rows = performance_rows($roleField, $kabId, 'asc');
    return array_values(array_filter($rows, fn($row) => (float)$row['selesai_pct'] < $threshold));
}

function performance_date_label(string $date): string
{
    static $months = [
        '01' => 'Januari', '02' => 'Februari', '03' => 'Maret', '04' => 'April',
        '05' => 'Mei', '06' => 'Juni', '07' => 'Juli', '08' => 'Agustus',
        '09' => 'September', '10' => 'Oktober', '11' => 'November', '12' => 'Desember',
    ];
    [$year, $month, $day] = explode('-', $date);
    return (int)$day . ' ' . ($months[$month] ?? $month) . ' ' . $year;
}

function performance_date_count(string $start, string $end): int
{
    if ($end < $start) {
        return 0;
    }
    return (int)((strtotime($end) - strtotime($start)) / 86400) + 1;
}

function performance_date_add(string $date, int $days): string
{
    return date('Y-m-d', strtotime($date . ' ' . ($days >= 0 ? '+' : '') . $days . ' days'));
}

function performance_period_overlap_days(string $start, string $end, string $rangeStart, string $rangeEnd): int
{
    $overlapStart = max($start, $rangeStart);
    $overlapEnd = min($end, $rangeEnd);
    return performance_date_count($overlapStart, $overlapEnd);
}

function performance_latest_completed_week(string $currentDate): ?array
{
    $campaignStart = '2026-06-15';
    $campaignEnd = '2026-08-31';
    $cursor = $campaignStart;
    $latest = null;
    $week = 1;
    while ($cursor <= $campaignEnd) {
        $end = min(performance_date_add($cursor, 6), $campaignEnd);
        if ($end >= $currentDate) {
            break;
        }
        $latest = ['number' => $week, 'start' => $cursor, 'end' => $end];
        $cursor = performance_date_add($end, 1);
        $week++;
    }
    return $latest;
}

function performance_series(string $start, string $end, array $daily): array
{
    $series = [];
    if ($end < $start) {
        return $series;
    }
    for ($date = $start; $date <= $end; $date = performance_date_add($date, 1)) {
        $series[$date] = (float)($daily[$date] ?? 0);
    }
    return $series;
}

function performance_standard_deviation(array $values): float
{
    if (!$values) {
        return 0;
    }
    $mean = array_sum($values) / count($values);
    $variance = 0.0;
    foreach ($values as $value) {
        $variance += ($value - $mean) ** 2;
    }
    return sqrt($variance / count($values));
}

function performance_consistency_score(array $values): array
{
    if (!$values) {
        return ['average' => 0.0, 'stddev' => 0.0, 'score' => 0.0];
    }
    $average = array_sum($values) / count($values);
    $stddev = performance_standard_deviation($values);
    $score = $average > 0 ? 100 / (1 + ($stddev / $average)) : 0;
    return ['average' => $average, 'stddev' => $stddev, 'score' => $score];
}

function performance_completion_date(array $daily, int $target, string $start, string $end): ?string
{
    if ($target <= 0) {
        return null;
    }
    $cumulative = 0.0;
    foreach (performance_series($start, $end, $daily) as $date => $delta) {
        $cumulative += $delta;
        if ($cumulative >= $target) {
            return $date;
        }
    }
    return null;
}

function performance_projected_finish_date(int $progress, int $target, float $recentAverage, string $asOf): ?string
{
    if ($target <= 0 || $progress >= $target || $recentAverage <= 0) {
        return null;
    }
    $days = (int)ceil(($target - $progress) / $recentAverage);
    return performance_date_add($asOf, max(1, $days));
}

function performance_projected_finish(int $progress, int $target, float $recentAverage, string $asOf): string
{
    if ($target <= 0) {
        return '-';
    }
    if ($progress >= $target) {
        return 'Selesai';
    }
    $projectedDate = performance_projected_finish_date($progress, $target, $recentAverage, $asOf);
    return $projectedDate ? performance_date_label($projectedDate) : 'Belum dapat diproyeksikan';
}

function performance_metric_row(array $meta, array $daily, string $asOf): array
{
    $campaignStart = '2026-06-15';
    $internalDeadline = '2026-08-15';
    $campaignEnd = '2026-08-31';
    $target = (int)$meta['target'];
    $progress = min($target, max(0, (int)$meta['progress_count']));
    $planEnd = min($asOf, $internalDeadline);
    $elapsedPlanDays = performance_date_count($campaignStart, $planEnd);
    $totalPlanDays = performance_date_count($campaignStart, $internalDeadline);
    $expected = $totalPlanDays > 0 ? $target * $elapsedPlanDays / $totalPlanDays : 0;
    $pace = $expected > 0 ? min(120, $progress / $expected * 100) : 0;

    $completionDate = performance_completion_date($daily, $target, $campaignStart, $asOf);
    $observationEnd = $completionDate ?: $asOf;
    $observationDays = max(1, performance_date_count($campaignStart, $observationEnd));
    $outputs = array_map(fn($value) => max(0, $value), array_values(performance_series($campaignStart, $observationEnd, $daily)));
    $consistency = performance_consistency_score($outputs);
    $averagePerDay = $progress / $observationDays;
    $reliability = min(1, $observationDays / 7);

    $recentStart = max($campaignStart, performance_date_add($asOf, -6));
    $recentOutputs = array_map(fn($value) => max(0, $value), array_values(performance_series($recentStart, $asOf, $daily)));
    $recentAverage = $recentOutputs ? array_sum($recentOutputs) / count($recentOutputs) : 0;
    $remaining = max(0, $target - $progress);
    $paceDeadline = $asOf <= $internalDeadline ? $internalDeadline : $campaignEnd;
    $remainingDays = max(1, performance_date_count(performance_date_add($asOf, 1), $paceDeadline));
    $requiredDaily = $remaining / $remainingDays;
    $momentum = $remaining <= 0 ? 120 : ($requiredDaily > 0 ? min(120, $recentAverage / $requiredDaily * 100) : 0);
    $requiredDailyTarget = $remaining <= 0
        ? 0
        : ($asOf > $internalDeadline ? null : (int)ceil($requiredDaily));
    $yesterdayAchievement = (int)round($daily[$asOf] ?? 0);
    $projectedFinishDate = performance_projected_finish_date($progress, $target, $recentAverage, $asOf);

    $score = min(100, ($pace * 0.50 + $consistency['score'] * 0.30 + $momentum * 0.20) * $reliability);
    if ($progress >= $target && $target > 0) {
        $status = 'Selesai';
    } elseif ($target <= 0) {
        $status = 'Tidak Ada Target';
    } elseif (!$projectedFinishDate) {
        $status = 'Tidak Ada Momentum';
    } elseif ($projectedFinishDate <= $internalDeadline) {
        $status = 'On Track';
    } elseif ($projectedFinishDate <= $campaignEnd) {
        $status = 'Perlu Didorong';
    } else {
        $status = 'Tertinggal';
    }

    return $meta + [
        'progress_count' => $progress,
        'expected_count' => $expected,
        'pace_score' => $pace,
        'average_per_day' => $averagePerDay,
        'yesterday_achievement' => $yesterdayAchievement,
        'required_daily_target' => $requiredDailyTarget,
        'stddev' => $consistency['stddev'],
        'consistency_score' => $consistency['score'],
        'momentum_score' => $momentum,
        'projected_finish' => $completionDate
            ? performance_date_label($completionDate)
            : ($projectedFinishDate ? performance_date_label($projectedFinishDate) : performance_projected_finish($progress, $target, $recentAverage, $asOf)),
        'performance_score' => $score,
        'performance_status' => $status,
        'observation_days' => $observationDays,
    ];
}

function performance_weekly_metric_row(array $meta, array $daily, array $period): ?array
{
    $campaignStart = '2026-06-15';
    $internalDeadline = '2026-08-15';
    $campaignEnd = '2026-08-31';
    $target = (int)$meta['target'];
    $progressBefore = 0.0;
    foreach ($daily as $date => $delta) {
        if ($date < $period['start']) {
            $progressBefore += $delta;
        }
    }
    $progressBefore = min($target, max(0, $progressBefore));
    $remainingAtStart = max(0, $target - $progressBefore);
    if ($target <= 0 || $remainingAtStart <= 0) {
        return null;
    }

    $weeklySeries = performance_series($period['start'], $period['end'], $daily);
    $weeklyOutputs = array_map(fn($value) => max(0, $value), array_values($weeklySeries));
    $weeklyCount = max(0, array_sum($weeklySeries));
    $weeklyDays = max(1, count($weeklySeries));
    $weeklyAverage = $weeklyCount / $weeklyDays;
    $consistency = performance_consistency_score($weeklyOutputs);

    $plannedDays = performance_period_overlap_days($period['start'], $period['end'], $campaignStart, $internalDeadline);
    if ($plannedDays > 0) {
        $weeklyTarget = $target / performance_date_count($campaignStart, $internalDeadline) * $plannedDays;
        $paceDeadline = $internalDeadline;
    } else {
        $recoveryDays = max(1, performance_date_count($period['start'], $campaignEnd));
        $weeklyTarget = $remainingAtStart / $recoveryDays * $weeklyDays;
        $paceDeadline = $campaignEnd;
    }
    $pace = $weeklyTarget > 0 ? min(120, $weeklyCount / $weeklyTarget * 100) : 0;
    $requiredDays = max(1, performance_date_count($period['start'], $paceDeadline));
    $requiredDaily = $remainingAtStart / $requiredDays;
    $momentum = $requiredDaily > 0 ? min(120, $weeklyAverage / $requiredDaily * 100) : 120;
    $score = min(100, $pace * 0.50 + $consistency['score'] * 0.30 + $momentum * 0.20);

    return $meta + [
        'progress_before' => (int)round($progressBefore),
        'weekly_count' => (int)round($weeklyCount),
        'weekly_target' => $weeklyTarget,
        'average_per_day' => $weeklyAverage,
        'stddev' => $consistency['stddev'],
        'consistency_score' => $consistency['score'],
        'momentum_score' => $momentum,
        'performance_score' => $score,
    ];
}

function performance_metric_dataset(string $roleField, array $user, bool $limitTop = true): array
{
    if (!in_array($roleField, ['pengawas_email', 'pencacah_email'], true)) {
        throw new InvalidArgumentException('Role petugas tidak valid.');
    }
    $campaignStart = '2026-06-15';
    $asOf = min(max(today(), $campaignStart), '2026-08-31');
    $weekPeriod = performance_latest_completed_week(today());
    $restrictKab = in_array($user['role'], ['admin_kab', 'viewer_kab'], true);
    $kabWhere = $restrictKab ? ' AND k.id=?' : '';
    $metaParams = $restrictKab ? [$user['kab_id']] : [];

    $stmt = db()->prepare("SELECT
            k.id kab_id,
            ms.$roleField email,
            u.name petugas_name,
            GROUP_CONCAT(DISTINCT kc.nmkec ORDER BY kc.kdkec SEPARATOR ', ') wilayah_kerja_kecamatan,
            GROUP_CONCAT(DISTINCT d.nmdesa ORDER BY kc.kdkec, d.kddesa SEPARATOR ', ') wilayah_kerja,
            COUNT(ms.id) subsls_total,
            COALESCE(SUM(ss.target),0) target,
            COALESCE(SUM(ss.submitted_by_pencacah + ss.rejected_by_pengawas + ss.pending_count + ss.approved_by_pengawas),0) progress_count
        FROM master_subsls ms
        JOIN master_sls sl ON sl.id=ms.sls_id
        JOIN master_desa d ON d.id=sl.desa_id
        JOIN master_kec kc ON kc.id=d.kec_id
        JOIN master_kab k ON k.id=kc.kab_id
        LEFT JOIN users u ON u.email=ms.$roleField
        LEFT JOIN subsls_status ss ON ss.subsls_id=ms.id
        WHERE ms.$roleField IS NOT NULL AND ms.$roleField <> '' $kabWhere
        GROUP BY k.id, ms.$roleField, u.name
        ORDER BY k.id, u.name, ms.$roleField");
    $stmt->execute($metaParams);
    $metaRows = $stmt->fetchAll();

    $scopes = [];
    foreach ($metaRows as $row) {
        $email = normalize_email((string)$row['email']);
        $kabId = (string)$row['kab_id'];
        $meta = [
            'email' => $email,
            'petugas_name' => $row['petugas_name'] ?? '',
            'kab_codes' => $kabId,
            'wilayah_kerja_kecamatan' => $row['wilayah_kerja_kecamatan'] ?? '',
            'wilayah_kerja' => $row['wilayah_kerja'] ?? '',
            'subsls_total' => (int)$row['subsls_total'],
            'target' => (int)$row['target'],
            'progress_count' => (int)$row['progress_count'],
        ];
        $scopes[$kabId][$email] = $meta;
        if (!$restrictKab) {
            if (!isset($scopes['6400'][$email])) {
                $scopes['6400'][$email] = $meta;
            } else {
                $province =& $scopes['6400'][$email];
                $province['kab_codes'] .= ', ' . $kabId;
                $province['wilayah_kerja_kecamatan'] .= ($province['wilayah_kerja_kecamatan'] !== '' && $row['wilayah_kerja_kecamatan'] !== '' ? ', ' : '') . ($row['wilayah_kerja_kecamatan'] ?? '');
                $province['wilayah_kerja'] .= ($province['wilayah_kerja'] !== '' && $row['wilayah_kerja'] !== '' ? ', ' : '') . ($row['wilayah_kerja'] ?? '');
                $province['subsls_total'] += (int)$row['subsls_total'];
                $province['target'] += (int)$row['target'];
                $province['progress_count'] += (int)$row['progress_count'];
                unset($province);
            }
        }
    }
    foreach ($scopes as &$petugasRows) {
        foreach ($petugasRows as &$meta) {
            $wilayah = trim((string)$meta['wilayah_kerja']);
            $jumlahSubSls = number_format((int)$meta['subsls_total'], 0, ',', '.');
            $meta['wilayah_kerja'] = ($wilayah !== '' ? $wilayah . ' ' : '') . '(' . $jumlahSubSls . ' SubSLS)';
        }
        unset($meta);
    }
    unset($petugasRows);

    $dailyKabFilter = $restrictKab ? ' AND ds.kab_id=?' : '';
    $dailyParams = [$campaignStart, $asOf];
    if ($restrictKab) {
        $dailyParams[] = $user['kab_id'];
    }
    $stmt = db()->prepare("WITH status_history AS (
            SELECT
                ds.kab_id,
                ds.$roleField email,
                ds.tanggal,
                (
                    ds.submitted_by_pencacah + ds.rejected_by_pengawas + ds.pending_count + ds.approved_by_pengawas
                ) - LAG(
                    ds.submitted_by_pencacah + ds.rejected_by_pengawas + ds.pending_count + ds.approved_by_pengawas,
                    1,
                    0
                ) OVER (PARTITION BY ds.subsls_id ORDER BY ds.tanggal, ds.id) daily_delta
            FROM daily_status ds
            WHERE ds.tanggal BETWEEN ? AND ?
              AND ds.$roleField IS NOT NULL
              AND ds.$roleField <> ''
              $dailyKabFilter
        )
        SELECT kab_id, email, tanggal, SUM(daily_delta) daily_delta
        FROM status_history
        GROUP BY kab_id, email, tanggal
        ORDER BY tanggal, kab_id, email");
    $stmt->execute($dailyParams);
    $dailyScopes = [];
    foreach ($stmt->fetchAll() as $row) {
        $kabId = (string)$row['kab_id'];
        $email = normalize_email((string)$row['email']);
        $date = (string)$row['tanggal'];
        $delta = (float)$row['daily_delta'];
        $dailyScopes[$kabId][$email][$date] = ($dailyScopes[$kabId][$email][$date] ?? 0) + $delta;
        if (!$restrictKab) {
            $dailyScopes['6400'][$email][$date] = ($dailyScopes['6400'][$email][$date] ?? 0) + $delta;
        }
    }

    $overall = [];
    $weekly = [];
    foreach ($scopes as $scope => $petugasRows) {
        foreach ($petugasRows as $email => $meta) {
            $daily = $dailyScopes[$scope][$email] ?? [];
            $overall[$scope][] = performance_metric_row($meta, $daily, $asOf);
            if ($weekPeriod) {
                $weeklyRow = performance_weekly_metric_row($meta, $daily, $weekPeriod);
                if ($weeklyRow) {
                    $weekly[$scope][] = $weeklyRow;
                }
            }
        }
        usort($overall[$scope], fn($a, $b) =>
            ($b['performance_score'] <=> $a['performance_score'])
            ?: ($b['consistency_score'] <=> $a['consistency_score'])
            ?: ($b['average_per_day'] <=> $a['average_per_day'])
            ?: strcmp($a['email'], $b['email'])
        );
        if ($limitTop) {
            $overall[$scope] = array_slice($overall[$scope], 0, 10);
        }
        if (isset($weekly[$scope])) {
            usort($weekly[$scope], fn($a, $b) =>
                ($b['performance_score'] <=> $a['performance_score'])
                ?: ($b['consistency_score'] <=> $a['consistency_score'])
                ?: ($b['weekly_count'] <=> $a['weekly_count'])
                ?: strcmp($a['email'], $b['email'])
            );
            if ($limitTop) {
                $weekly[$scope] = array_slice($weekly[$scope], 0, 10);
            }
        }
    }

    return [
        'as_of' => $asOf,
        'week_period' => $weekPeriod,
        'overall' => $overall,
        'weekly' => $weekly,
    ];
}

function dashboard_kab_options_for_performance(array $user): array
{
    if (in_array($user['role'], ['admin_kab', 'viewer_kab'], true)) {
        $stmt = db()->prepare("SELECT id value, CONCAT(id,' - ',nmkab) label FROM master_kab WHERE id=?");
        $stmt->execute([$user['kab_id']]);
        return $stmt->fetchAll();
    }
    $rows = db()->query("SELECT id value, CONCAT(id,' - ',nmkab) label FROM master_kab ORDER BY id")->fetchAll();
    array_unshift($rows, ['value' => '6400', 'label' => '6400 - Kalimantan Timur']);
    return $rows;
}

function dashboard_can_access_kab(array $user, string $kabId): bool
{
    if (in_array($user['role'], ['admin_kab', 'viewer_kab'], true)) {
        return $kabId === $user['kab_id'];
    }
    return in_array($user['role'], ['superadmin', 'viewer_prov'], true);
}

function dashboard_xlsx_col(int $index): string
{
    $name = '';
    while ($index > 0) {
        $index--;
        $name = chr(65 + ($index % 26)) . $name;
        $index = intdiv($index, 26);
    }
    return $name;
}

function dashboard_xlsx_cell(string $value, int $row, int $col): string
{
    $ref = dashboard_xlsx_col($col) . $row;
    return '<c r="' . $ref . '" t="inlineStr"><is><t>' . htmlspecialchars($value, ENT_XML1) . '</t></is></c>';
}

function dashboard_export_rows(array $headers, array $rows, string $filename, string $format): void
{
    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, $headers);
        foreach ($rows as $row) {
            fputcsv($out, $row);
        }
        fclose($out);
        exit;
    }

    $sheetRows = array_merge([$headers], $rows);
    $tmp = tempnam(sys_get_temp_dir(), 'dash_export_');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
  <Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
</Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
</Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets><sheet name="export" sheetId="1" r:id="rId1"/></sheets>
</workbook>');
    $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>
  <fills count="1"><fill><patternFill patternType="none"/></fill></fills>
  <borders count="1"><border/></borders>
  <cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
  <cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/></cellXfs>
</styleSheet>');
    $sheet = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
    foreach ($sheetRows as $rIndex => $values) {
        $rowNumber = $rIndex + 1;
        $sheet .= '<row r="' . $rowNumber . '">';
        foreach ($values as $cIndex => $value) {
            $sheet .= dashboard_xlsx_cell((string)$value, $rowNumber, $cIndex + 1);
        }
        $sheet .= '</row>';
    }
    $sheet .= '</sheetData></worksheet>';
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
    $zip->close();

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '.xlsx"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    unlink($tmp);
    exit;
}

function dashboard_chart_export_payload(array $rows, array $fields, string $tab): array
{
    if ($tab === 'status') {
    $headers = ['label', 'target', 'open', 'draft', 'submit', 'reject', 'pending', 'approved', 'open_pct', 'draft_pct', 'submit_pct', 'reject_pct', 'pending_pct', 'approved_pct'];
        $out = [];
        foreach ($rows as $row) {
            $target = (float)($row['target'] ?? 0);
            $line = [$row['label'] ?? '-', $row['target'] ?? 0];
            foreach (array_keys($fields) as $field) {
                $line[] = $row[$field] ?? 0;
            }
            foreach (array_keys($fields) as $field) {
                $line[] = $target > 0 ? round(((float)($row[$field] ?? 0)) / $target * 100, 2) : 0;
            }
            $out[] = $line;
        }
        return [$headers, $out];
    }

    $headers = $tab === 'selesai'
        ? ['label', 'subsls_total', 'selesai_count', 'selesai_subsls_pct']
        : ['label', 'target', 'submit', 'reject', 'pending', 'approved', 'progress_pendataan_pct'];
    $out = [];
    foreach ($rows as $row) {
        if ($tab === 'selesai') {
            $total = (float)($row['subsls_total'] ?? 0);
            $out[] = [
                $row['label'] ?? '-',
                $row['subsls_total'] ?? 0,
                $row['selesai_count'] ?? 0,
                $total > 0 ? round(((float)($row['selesai_count'] ?? 0)) / $total * 100, 2) : 0,
            ];
        } else {
            $target = (float)($row['target'] ?? 0);
            $submit = (float)($row['submitted_by_pencacah'] ?? 0);
            $reject = (float)($row['rejected_by_pengawas'] ?? 0);
            $pending = (float)($row['pending_count'] ?? 0);
            $approved = (float)($row['approved_by_pengawas'] ?? 0);
            $pendataan = $submit + $reject + $pending + $approved;
            $out[] = [
                $row['label'] ?? '-',
                $row['target'] ?? 0,
                $submit,
                $reject,
                $pending,
                $approved,
                $target > 0 ? round($pendataan / $target * 100, 2) : 0,
            ];
        }
    }
    return [$headers, $out];
}

if (($_GET['action'] ?? '') === 'generate_performance_cache'
    && $_SERVER['REQUEST_METHOD'] === 'POST'
    && $user['role'] === 'superadmin') {
    @set_time_limit(0);
    $pdo = db();
    try {
        $snapshotAt = date('c');
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->beginTransaction();

        $roleConfigs = [
            'pengawas' => 'pengawas_email',
            'pencacah' => 'pencacah_email',
        ];
        $roles = [];
        $threshold = performance_attention_threshold();
        foreach ($roleConfigs as $type => $roleField) {
            $dataset = performance_metric_dataset($roleField, $user, false);
            foreach ($dataset['weekly'] as $scopeId => $weeklyRows) {
                $dataset['weekly'][$scopeId] = array_slice($weeklyRows, 0, 10);
            }
            $attention = [];
            foreach (array_keys($dataset['overall']) as $scopeId) {
                $attention[$scopeId] = performance_attention_rows($roleField, $scopeId, (float)$threshold['pct']);
            }
            $dataset['attention'] = $attention;
            $roles[$type] = $dataset;
        }
        $pdo->commit();

        $weekPeriod = $roles['pengawas']['week_period'] ?? null;
        $weekLabel = $weekPeriod
            ? 'Minggu ' . $weekPeriod['number'] . ': '
                . performance_date_label($weekPeriod['start']) . ' - '
                . performance_date_label($weekPeriod['end'])
            : 'Belum ada minggu yang selesai';
        $payload = [
            'version' => 6,
            'generated_at' => $snapshotAt,
            'generated_by' => $user['email'],
            'week_label' => $weekLabel,
            'attention_threshold' => $threshold,
            'summary' => [
                'pengawas' => count($roles['pengawas']['overall']['6400'] ?? []),
                'pencacah' => count($roles['pencacah']['overall']['6400'] ?? []),
            ],
            'roles' => $roles,
        ];
        performance_cache_write($payload);
        flash(
            'success',
            'Data performa berhasil diperbarui. Pengawas: '
            . number_format($payload['summary']['pengawas'], 0, ',', '.')
            . ', Pencacah: '
            . number_format($payload['summary']['pencacah'], 0, ',', '.')
            . '. Periode mingguan: ' . $weekLabel . '.'
        );
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('error', 'Update data performa gagal: ' . $e->getMessage());
    }
    redirect('performance_update.php');
}

if (($_GET['action'] ?? '') === 'export_performance_temporary'
    && in_array($user['role'], ['superadmin', 'admin_kab', 'viewer_prov', 'viewer_kab'], true)) {
    $type = ($_GET['type'] ?? '') === 'pencacah' ? 'pencacah' : 'pengawas';
    $scopeId = in_array($user['role'], ['superadmin', 'viewer_prov'], true)
        ? '6400'
        : (string)$user['kab_id'];
    $cache = performance_cache_read();
    if (!$cache || (int)($cache['version'] ?? 0) < 6) {
        http_response_code(503);
        exit('Data performa belum tersedia atau memakai format lama. Superadmin perlu menjalankan Update Data Performa.');
    }
    $rows = $cache['roles'][$type]['overall'][$scopeId] ?? [];
    $exportRows = [];
    foreach ($rows as $rankIndex => $row) {
        $progressCount = (int)$row['progress_count'];
        $target = (int)$row['target'];
        $progressPct = $target > 0 ? $progressCount / $target * 100 : 0;
        $exportRows[] = [
            $rankIndex + 1,
            petugas_label($row['email'], $row['petugas_name'] ?? ''),
            $row['kab_codes'] ?? '',
            $row['wilayah_kerja_kecamatan'] ?? '',
            $row['wilayah_kerja'] ?? '',
            $target,
            $progressCount,
            number_format($progressPct, 2, ',', '.') . '%',
            (int)ceil((float)$row['average_per_day']),
            (int)$row['yesterday_achievement'],
            $row['required_daily_target'] === null ? 'Lewat Target' : (int)$row['required_daily_target'],
            round((float)$row['stddev'], 2),
            round((float)$row['consistency_score'], 2),
            $row['projected_finish'],
            $row['performance_status'],
            round((float)$row['performance_score'], 2),
        ];
    }
    dashboard_export_rows(
        ['rank', 'petugas', 'kode_kab', 'kecamatan', 'wilayah_kerja', 'target', 'progress_count', 'progress_persen', 'rata_rata_per_hari', 'capaian_kemarin_assignment', 'target_hari_ini_assignment', 'standar_deviasi', 'konsistensi_pct', 'prediksi_selesai', 'status', 'skor'],
        $exportRows,
        'performa_sementara_' . $type . '_' . $scopeId . '_' . date('Ymd_His'),
        'xlsx'
    );
}

if (($_GET['action'] ?? '') === 'export_attention' && $canSeePerformance) {
    $kabId = (string)($_GET['kab_id'] ?? '');
    $type = ($_GET['type'] ?? '') === 'pencacah' ? 'pencacah' : 'pengawas';
    $format = ($_GET['format'] ?? 'csv') === 'xlsx' ? 'xlsx' : 'csv';
    if (!$kabId || !dashboard_can_access_kab($user, $kabId)) {
        http_response_code(403);
        exit('Akses ditolak');
    }
    $cache = performance_cache_read();
    if (!$cache || (int)($cache['version'] ?? 0) < 6) {
        http_response_code(503);
        exit('Data performa belum tersedia atau memakai format lama. Superadmin perlu menjalankan Update Data Performa.');
    }
    $threshold = $cache['attention_threshold'] ?? performance_attention_threshold();
    $rows = $cache['roles'][$type]['attention'][$kabId] ?? [];
    $exportRows = [];
    foreach ($rows as $row) {
        $exportRows[] = [
            petugas_label($row['email'], $row['petugas_name'] ?? ''),
            $row['submit_approve_pct'],
            $row['selesai_pct'],
            $threshold['pct'],
            $threshold['date'],
            $row['target'],
            $row['submitted_by_pencacah'],
            $row['rejected_by_pengawas'],
            $row['draft_count'],
            $row['pending_count'],
            $row['approved_by_pengawas'],
            $row['kab_codes'] ?? '',
            $row['wilayah_kerja_kecamatan'] ?? '',
            $row['wilayah_kerja'] ?? '',
            $row['subsls_total'],
            $row['selesai_count'],
        ];
    }
    dashboard_export_rows(
        ['petugas', 'progress_pendataan_pct', 'selesai_subsls_pct', 'threshold_selesai_pct', 'batas_tanggal', 'target', 'submitted_by_pencacah', 'rejected_by_pengawas', 'draft_count', 'pending_count', 'approved_by_pengawas', 'kode_kab', 'kecamatan', 'wilayah_kerja', 'subsls_total', 'selesai_count'],
        $exportRows,
        'perlu_perhatian_' . $type . '_' . $kabId . '_' . date('Ymd'),
        $format
    );
}

if (($_GET['action'] ?? '') === 'export_dashboard') {
    $exportTab = $_GET['tab'] ?? 'submit_approve';
    if (!in_array($exportTab, ['submit_approve', 'status', 'selesai'], true)) {
        $exportTab = 'submit_approve';
    }
    $format = ($_GET['format'] ?? 'csv') === 'xlsx' ? 'xlsx' : 'csv';
    $exportRowsSource = dashboard_rows($user, $filters, $fields);
    [$headers, $exportRows] = dashboard_chart_export_payload($exportRowsSource, $fields, $exportTab);
    $exportNameTab = $exportTab === 'submit_approve' ? 'progress_pendataan' : $exportTab;
    dashboard_export_rows($headers, $exportRows, 'dashboard_' . $exportNameTab . '_' . date('Ymd'), $format);
}

$opts = dashboard_filter_options($user, $filters);
$chartRows = dashboard_rows($user, $filters, $fields);
$totals = dashboard_totals($chartRows, $fields);
$latestDailyStatusLabel = dashboard_latest_status_label($user, $filters);
$completionPct = $totals['subsls_total'] > 0 ? round($totals['selesai_count'] / $totals['subsls_total'] * 100, 2) : 0;
$submitApproveCount = dashboard_pendataan_count($totals);
$submitApprovePct = $totals['target'] > 0 ? round($submitApproveCount / (int)$totals['target'] * 100, 2) : 0;
$performanceKabOptions = $canSeePerformance ? dashboard_kab_options_for_performance($user) : [];
$performanceCache = null;
$performanceMetricData = null;
if ($canSeePerformance && in_array($activeTab, ['performa_pengawas', 'performa_pencacah'], true)) {
    $performanceCache = performance_cache_read();
    $metricType = $activeTab === 'performa_pengawas' ? 'pengawas' : 'pencacah';
    if ((int)($performanceCache['version'] ?? 0) >= 6) {
        $performanceMetricData = $performanceCache['roles'][$metricType] ?? null;
    }
}

function dashboard_count_pct_text(int $count, float $pct): string
{
    return '<span class="d-block">' . number_format($count, 0, ',', '.') . '</span><span class="d-block">(' . number_format($pct, 2, ',', '.') . '%)</span>';
}

function dashboard_count_only_text(int $count): string
{
    return '<span class="d-block">' . number_format($count, 0, ',', '.') . '</span><span class="d-block">&nbsp;</span>';
}

function dashboard_table_count_pct_text(int $count, int $target): string
{
    $pct = $target > 0 ? $count / $target * 100 : 0;
    return e(number_format($count, 0, ',', '.')) . ' <span class="dashboard-table-pct">(' . e(number_format($pct, 2, ',', '.')) . '%)</span>';
}

function performance_work_area_html(string $value): string
{
    $value = trim($value);
    if (preg_match('/^(.*?)(?:\s+)?\(([\d.]+ SubSLS)\)$/u', $value, $matches)) {
        $area = trim($matches[1]);
        return ($area !== '' ? e($area) . ' ' : '')
            . '<strong class="performance-subsls-total">(' . e($matches[2]) . ')</strong>';
    }
    return e($value ?: '-');
}

function performance_petugas_html(string $email, string $name): string
{
    $email = trim($email);
    $name = trim($name);
    if ($name === '' || strcasecmp($name, $email) === 0) {
        return '<span class="performance-staff-email">' . e($email ?: '-') . '</span>';
    }
    return e($name) . ' <span class="performance-staff-email">(' . e($email) . ')</span>';
}

render_header($user['role'] === 'pengawas' ? 'Dashboard Pengawas' : ($user['role'] === 'pencacah' ? 'Dashboard Pencacah' : 'Dashboard'));
?>
<style>
.dashboard-tabs {
  border-bottom: 1px solid #d1d5db;
  display: flex;
  flex-wrap: wrap;
  gap: 2px;
  margin-bottom: 16px;
  overflow-x: visible;
}
.dashboard-tabs .dashboard-tab {
  background: #f3f4f6;
  border: 1px solid #d1d5db;
  border-bottom: 0;
  border-radius: 8px 8px 0 0;
  color: #111827;
  font-weight: 600;
  padding: 10px 14px;
  white-space: nowrap;
}
.dashboard-tabs .dashboard-tab.active {
  background: #2563eb;
  border-color: #2563eb;
  color: #fff;
  position: relative;
  top: 1px;
}
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
.performance-tabs .nav-link {
  border: 1px solid #86efac;
  color: #111827;
  margin: 0 6px 6px 0;
}
.performance-tabs .nav-link.active {
  background: #dcfce7;
  border-color: #22c55e;
  color: #111827;
}
.rank-badge {
  align-items: center;
  border-radius: 999px;
  display: inline-flex;
  font-weight: 800;
  gap: 2px;
  justify-content: center;
  min-width: 74px;
  padding: 3px 8px;
}
.rank-1 { background: #fef3c7; color: #92400e; }
.rank-2 { background: #e5e7eb; color: #374151; }
.rank-3 { background: #ffedd5; color: #9a3412; }
.performance-section-title {
  align-items: center;
  background: #eff6ff;
  border-left: 5px solid #2563eb;
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  justify-content: space-between;
  margin: 0 0 10px;
  padding: 9px 12px;
}
.performance-status {
  background: #e5e7eb;
  border-radius: 999px;
  color: #374151;
  display: inline-block;
  font-size: .78rem;
  font-weight: 700;
  padding: 2px 8px;
  white-space: nowrap;
}
.data-update-dot {
  background: #22c55e;
  border-radius: 999px;
  box-shadow: 0 0 0 4px rgba(34, 197, 94, .16);
  display: inline-block;
  height: 10px;
  margin-right: 8px;
  width: 10px;
}
.attention-pagination {
  align-items: center;
  display: flex;
  gap: 8px;
  justify-content: flex-end;
  margin-top: 10px;
}
.dashboard-chart-wrap {
  height: 420px;
  position: relative;
}
.dashboard-summary-table th,
.dashboard-summary-table td {
  vertical-align: middle;
  white-space: nowrap;
}
.dashboard-summary-table tfoot td {
  font-weight: 800;
}
.dashboard-summary-table th.performance-compact-header {
  line-height: 1.2;
  min-width: 92px;
  text-align: left;
  vertical-align: middle;
  white-space: normal;
}
.dashboard-summary-table td.performance-progress-cell {
  min-width: 118px;
  white-space: nowrap !important;
}
.performance-subsls-total {
  color: #111827;
  font-weight: 800;
}
.performance-staff-email,
.performance-work-area {
  font-size: 9pt;
}
.dashboard-table-pct {
  color: #2563eb;
  font-weight: 700;
}
.dashboard-stat-card {
  background: linear-gradient(180deg, #fff3df 0%, #fffaf2 64%) !important;
  border: 1px solid #f0b35c;
  border-left: 5px solid #f59e0b;
  border-radius: 8px;
  box-shadow: 0 8px 18px rgba(180, 83, 9, .12);
  color: #374151;
}
.dashboard-stat-card .inner {
  padding: 14px;
}
.dashboard-stat-card h4 {
  color: #111827;
  font-weight: 800;
}
.dashboard-stat-card p {
  color: #92400e;
  font-weight: 700;
  margin-bottom: 0;
}
.best-progress {
  color: #16a34a;
  font-weight: 800;
}
.low-progress {
  color: #dc2626;
  font-weight: 800;
}
.best-progress .dashboard-table-pct,
.low-progress .dashboard-table-pct {
  color: inherit;
}
@media (max-width: 767.98px) {
  .dashboard-chart-wrap { height: 340px; }
}
</style>

<div class="dashboard-tabs">
  <a class="dashboard-tab <?= $activeTab==='submit_approve'?'active':'' ?>" href="?<?= e(http_build_query(array_merge($_GET, ['tab' => 'submit_approve']))) ?>">Progress Pendataan</a>
  <a class="dashboard-tab <?= $activeTab==='status'?'active':'' ?>" href="?<?= e(http_build_query(array_merge($_GET, ['tab' => 'status']))) ?>">Progress By Status</a>
  <a class="dashboard-tab <?= $activeTab==='selesai'?'active':'' ?>" href="?<?= e(http_build_query(array_merge($_GET, ['tab' => 'selesai']))) ?>">Progress Selesai SubSLS</a>
  <?php if ($canSeePerformance): ?>
    <a class="dashboard-tab <?= $activeTab==='performa_pengawas'?'active':'' ?>" href="?tab=performa_pengawas">Performa Pengawas</a>
    <a class="dashboard-tab <?= $activeTab==='performa_pencacah'?'active':'' ?>" href="?tab=performa_pencacah">Performa Pencacah</a>
  <?php endif; ?>
</div>

<?php if (in_array($activeTab, ['submit_approve', 'status', 'selesai'], true)): ?>
<?php
  $exportQuery = $_GET;
  $exportQuery['action'] = 'export_dashboard';
  $exportQuery['tab'] = $activeTab;
  $exportCsvQuery = array_merge($exportQuery, ['format' => 'csv']);
  $exportXlsxQuery = array_merge($exportQuery, ['format' => 'xlsx']);
?>
<div class="d-flex justify-content-end mb-2">
  <a class="btn btn-outline-success btn-sm mr-2" href="?<?= e(http_build_query($exportCsvQuery)) ?>"><i class="fas fa-file-csv mr-1"></i>Export CSV</a>
  <a class="btn btn-outline-success btn-sm" href="?<?= e(http_build_query($exportXlsxQuery)) ?>"><i class="fas fa-file-excel mr-1"></i>Export Excel</a>
</div>
<form class="card card-body mb-3" method="get">
  <input type="hidden" name="tab" value="<?= e($activeTab) ?>">
  <div class="form-row align-items-end">
    <?php if (in_array($user['role'], ['superadmin', 'viewer_prov'], true)): ?>
      <div class="form-group col-md-2">
        <label>Kabupaten</label>
        <select class="form-control" name="kab_id" id="kab_id">
          <option value="">Semua Kabupaten</option>
          <?php foreach ($opts['kabupaten'] as $o): ?><option value="<?= e($o['value']) ?>" <?= $filters['kab_id']===$o['value']?'selected':'' ?>><?= e($o['label']) ?></option><?php endforeach; ?>
        </select>
      </div>
    <?php elseif (in_array($user['role'], ['admin_kab', 'viewer_kab'], true)): ?>
      <input type="hidden" name="kab_id" value="<?= e($filters['kab_id']) ?>">
    <?php endif; ?>

    <?php if (!in_array($user['role'], ['pengawas', 'pencacah'], true)): ?>
      <div class="form-group col-md-2">
        <label>Kecamatan</label>
        <select class="form-control" name="kec_id" id="kec_id" <?= $filters['kab_id'] ? '' : 'disabled' ?>>
          <option value=""><?= $filters['kab_id'] ? 'Semua Kecamatan' : 'Pilih kabupaten dulu' ?></option>
          <?php foreach ($opts['kecamatan'] as $o): ?><option value="<?= e($o['value']) ?>" <?= $filters['kec_id']===$o['value']?'selected':'' ?>><?= e($o['label']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-group col-md-2">
        <label>Desa</label>
        <select class="form-control" name="desa_id" id="desa_id" <?= $filters['kec_id'] ? '' : 'disabled' ?>>
          <option value=""><?= $filters['kec_id'] ? 'Semua Desa' : 'Pilih kecamatan dulu' ?></option>
          <?php foreach ($opts['desa'] as $o): ?><option value="<?= e($o['value']) ?>" <?= $filters['desa_id']===$o['value']?'selected':'' ?>><?= e($o['label']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-group col-md-3">
        <label>Pengawas</label>
        <select class="form-control" name="pengawas_email" id="pengawas_email" <?= $filters['desa_id'] ? '' : 'disabled' ?>>
          <option value=""><?= $filters['desa_id'] ? 'Semua Pengawas' : 'Pilih desa dulu' ?></option>
          <?php foreach ($opts['pengawas'] as $o): ?><option value="<?= e($o['value']) ?>" <?= $filters['pengawas_email']===$o['value']?'selected':'' ?>><?= e($o['label']) ?></option><?php endforeach; ?>
        </select>
      </div>
    <?php endif; ?>

    <?php if ($user['role'] !== 'pencacah'): ?>
      <div class="form-group col-md-3">
        <label>Pencacah</label>
        <select class="form-control" name="pencacah_email" id="pencacah_email" <?= ($user['role'] === 'pengawas' || $filters['pengawas_email']) ? '' : 'disabled' ?>>
          <option value=""><?= ($user['role'] === 'pengawas' || $filters['pengawas_email']) ? 'Semua Pencacah' : 'Pilih pengawas dulu' ?></option>
          <?php foreach ($opts['pencacah'] as $o): ?><option value="<?= e($o['value']) ?>" <?= $filters['pencacah_email']===$o['value']?'selected':'' ?>><?= e($o['label']) ?></option><?php endforeach; ?>
        </select>
      </div>
    <?php endif; ?>

    <div class="form-group col-md-1"><button class="btn btn-primary">Filter</button></div>
  </div>
</form>

<?php if ($activeTab === 'submit_approve'): ?>
  <div class="card card-body py-2 mb-3">
    <div class="mb-1"><span class="data-update-dot"></span><strong>Terakhir Update Data:</strong> <?= e($latestDailyStatusLabel) ?></div>
    <div><strong><em>Progress Pendataan = Submit+Reject+Pending+Approve</em></strong></div>
  </div>
<?php endif; ?>

<?php
  $targetTotal = (int)$totals['target'];
  $dashboardCards = [
      ['label' => 'Target', 'value' => dashboard_count_only_text($targetTotal)],
      ['label' => 'Open', 'value' => dashboard_count_pct_text((int)$totals['open_count'], $targetTotal ? (int)$totals['open_count'] / $targetTotal * 100 : 0)],
      ['label' => 'Submit', 'value' => dashboard_count_pct_text((int)$totals['submitted_by_pencacah'], $targetTotal ? (int)$totals['submitted_by_pencacah'] / $targetTotal * 100 : 0)],
      ['label' => 'Reject', 'value' => dashboard_count_pct_text((int)$totals['rejected_by_pengawas'], $targetTotal ? (int)$totals['rejected_by_pengawas'] / $targetTotal * 100 : 0)],
      ['label' => 'Draft', 'value' => dashboard_count_pct_text((int)$totals['draft_count'], $targetTotal ? (int)$totals['draft_count'] / $targetTotal * 100 : 0)],
      ['label' => 'Pending', 'value' => dashboard_count_pct_text((int)$totals['pending_count'], $targetTotal ? (int)$totals['pending_count'] / $targetTotal * 100 : 0)],
      ['label' => 'Approve', 'value' => dashboard_count_pct_text((int)$totals['approved_by_pengawas'], $targetTotal ? (int)$totals['approved_by_pengawas'] / $targetTotal * 100 : 0)],
      ['label' => 'Progress Pendataan', 'value' => dashboard_count_pct_text($submitApproveCount, $submitApprovePct)],
      ['label' => 'SubSLS Selesai', 'value' => dashboard_count_pct_text((int)$totals['selesai_count'], $completionPct)],
      ['label' => 'Total SubSLS', 'value' => dashboard_count_only_text((int)$totals['subsls_total'])],
  ];
?>
<div class="row">
  <?php foreach ($dashboardCards as $card): ?>
    <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6">
      <div class="small-box dashboard-stat-card">
        <div class="inner">
          <h4 class="mb-1"><?= $card['value'] ?></h4>
          <p><?= e($card['label']) ?></p>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<?php if (in_array($activeTab, ['submit_approve', 'selesai'], true)): ?>
  <div class="range-legend">
    <?php foreach ($rangeColors as $item): ?><span><i style="background:<?= e($item['color']) ?>"></i><?= e($item['label']) ?></span><?php endforeach; ?>
  </div>
<?php endif; ?>

<div class="card"><div class="card-body"><div class="dashboard-chart-wrap"><canvas id="dashboardChart"></canvas></div></div></div>

<div class="card">
  <div class="card-header"><strong>Tabel Ringkasan Sesuai Filter</strong></div>
  <div class="card-body table-responsive p-0">
    <?php
      $pendataanPcts = array_map(function ($row) {
          $target = (int)$row['target'];
          return $target > 0 ? dashboard_pendataan_count($row) / $target * 100 : 0;
      }, $chartRows);
      $maxPendataanPct = $pendataanPcts ? max($pendataanPcts) : null;
      $minPendataanPct = $pendataanPcts ? min($pendataanPcts) : null;
      $samePendataanPct = $maxPendataanPct !== null && $minPendataanPct !== null && abs($maxPendataanPct - $minPendataanPct) < 0.001;
    ?>
    <table class="table table-sm table-bordered table-striped mb-0 dashboard-summary-table">
      <thead>
        <tr>
          <th>Kelompok</th>
          <th class="text-right">Target</th>
          <th class="text-right">Open</th>
          <th class="text-right">Draft</th>
          <th class="text-right">Submit</th>
          <th class="text-right">Reject</th>
          <th class="text-right">Pending</th>
          <th class="text-right">Approve</th>
          <th class="text-right">Progress Pendataan</th>
          <th class="text-right">Jumlah SubSLS Selesai</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($chartRows as $row): ?>
          <?php
            $rowTarget = (int)$row['target'];
            $submitApproveCount = dashboard_pendataan_count($row);
            $pendataanPct = $rowTarget > 0 ? $submitApproveCount / $rowTarget * 100 : 0;
            $pendataanClass = '';
            if ($samePendataanPct || ($maxPendataanPct !== null && abs($pendataanPct - $maxPendataanPct) < 0.001)) {
                $pendataanClass = ' best-progress';
            } elseif ($minPendataanPct !== null && abs($pendataanPct - $minPendataanPct) < 0.001) {
                $pendataanClass = ' low-progress';
            }
          ?>
          <tr>
            <td><?= e($row['label']) ?></td>
            <td class="text-right"><?= number_format($rowTarget, 0, ',', '.') ?></td>
            <td class="text-right"><?= number_format((int)$row['open_count'], 0, ',', '.') ?></td>
            <td class="text-right"><?= dashboard_table_count_pct_text((int)$row['draft_count'], $rowTarget) ?></td>
            <td class="text-right"><?= dashboard_table_count_pct_text((int)$row['submitted_by_pencacah'], $rowTarget) ?></td>
            <td class="text-right"><?= number_format((int)$row['rejected_by_pengawas'], 0, ',', '.') ?></td>
            <td class="text-right"><?= number_format((int)$row['pending_count'], 0, ',', '.') ?></td>
            <td class="text-right"><?= dashboard_table_count_pct_text((int)$row['approved_by_pengawas'], $rowTarget) ?></td>
            <td class="text-right<?= e($pendataanClass) ?>"><?= dashboard_table_count_pct_text($submitApproveCount, $rowTarget) ?></td>
            <td class="text-right"><?= number_format((int)$row['selesai_count'], 0, ',', '.') ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <?php
          $totalTarget = (int)$totals['target'];
          $totalSubmitApprove = dashboard_pendataan_count($totals);
        ?>
        <tr>
          <td>Total</td>
          <td class="text-right"><?= number_format($totalTarget, 0, ',', '.') ?></td>
          <td class="text-right"><?= number_format((int)$totals['open_count'], 0, ',', '.') ?></td>
          <td class="text-right"><?= dashboard_table_count_pct_text((int)$totals['draft_count'], $totalTarget) ?></td>
          <td class="text-right"><?= dashboard_table_count_pct_text((int)$totals['submitted_by_pencacah'], $totalTarget) ?></td>
          <td class="text-right"><?= number_format((int)$totals['rejected_by_pengawas'], 0, ',', '.') ?></td>
          <td class="text-right"><?= number_format((int)$totals['pending_count'], 0, ',', '.') ?></td>
          <td class="text-right"><?= dashboard_table_count_pct_text((int)$totals['approved_by_pengawas'], $totalTarget) ?></td>
          <td class="text-right"><?= dashboard_table_count_pct_text($totalSubmitApprove, $totalTarget) ?></td>
          <td class="text-right"><?= number_format((int)$totals['selesai_count'], 0, ',', '.') ?></td>
        </tr>
      </tfoot>
    </table>
  </div>
  <div class="card-footer text-muted small">Progress Pendataan = submit+reject+pending+approve</div>
</div>

<script>
const rows = <?= json_encode($chartRows) ?>;
const fields = <?= json_encode(array_keys($fields)) ?>;
const labels = <?= json_encode(array_values($fields)) ?>;
const statusColors = <?= json_encode($statusColors) ?>;
const activeTab = <?= json_encode($activeTab) ?>;
if (window.ChartDataLabels) {
  Chart.register(ChartDataLabels);
  Chart.defaults.set('plugins.datalabels', { display: false });
}
function pctColor(value) {
  if (value < 20) return '#dc2626';
  if (value < 40) return '#f59e0b';
  if (value < 75) return '#2563eb';
  return '#16a34a';
}
function pctLabel(value) {
  return Number(value || 0).toLocaleString('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + '%';
}
const percentRows = rows.map(r => {
  const target = Number(r.target || 0);
  const submitApprove = target ? Math.round((Number(r.submitted_by_pencacah || 0) + Number(r.rejected_by_pengawas || 0) + Number(r.pending_count || 0) + Number(r.approved_by_pengawas || 0)) / target * 10000) / 100 : 0;
  const selesai = Number(r.subsls_total || 0) ? Math.round(Number(r.selesai_count || 0) / Number(r.subsls_total || 0) * 10000) / 100 : 0;
  return { label: r.label || '-', submitApprove, selesai };
});
const config = activeTab === 'status'
  ? {
      type: 'bar',
      data: {
        labels: rows.map(r => r.label || '-'),
        datasets: fields.map((f, i) => ({
          label: labels[i],
          data: rows.map(r => Number(r.target) ? Math.round(Number(r[f] || 0) / Number(r.target) * 10000) / 100 : 0),
          backgroundColor: statusColors[i]
        }))
      },
      options: { animation:false, maintainAspectRatio:false, responsive:true, scales:{ x:{stacked:true}, y:{stacked:true, min:0, max:100, ticks:{callback:v=>v+'%'}} } }
    }
  : {
      type: 'bar',
      data: {
        labels: percentRows.map(r => r.label),
        datasets: [{
          label: activeTab === 'selesai' ? 'Persen Selesai SubSLS' : 'Persen Progress Pendataan',
          data: percentRows.map(r => activeTab === 'selesai' ? r.selesai : r.submitApprove),
          backgroundColor: percentRows.map(r => pctColor(activeTab === 'selesai' ? r.selesai : r.submitApprove))
        }]
      },
      options: {
        animation:false,
        maintainAspectRatio:false,
        responsive:true,
        plugins: {
          datalabels: {
            display: activeTab === 'submit_approve',
            anchor: 'end',
            align: 'start',
            clamp: true,
            color: '#fff',
            font: { weight: '700' },
            formatter: pctLabel
          }
        },
        scales:{ y:{min:0,max:100,ticks:{callback:v=>v+'%'}} }
      }
    };
new Chart(document.getElementById('dashboardChart'), config);

const kabupaten = document.getElementById('kab_id');
if (kabupaten) {
  kabupaten.addEventListener('change', function () {
    document.getElementById('kec_id').value = '';
    document.getElementById('desa_id').value = '';
    document.getElementById('pengawas_email').value = '';
    const pencacah = document.getElementById('pencacah_email');
    if (pencacah) pencacah.value = '';
    this.form.submit();
  });
}
const kecamatan = document.getElementById('kec_id');
if (kecamatan) {
  kecamatan.addEventListener('change', function () {
    document.getElementById('desa_id').value = '';
    document.getElementById('pengawas_email').value = '';
    const pencacah = document.getElementById('pencacah_email');
    if (pencacah) pencacah.value = '';
    this.form.submit();
  });
}
const desa = document.getElementById('desa_id');
if (desa) {
  desa.addEventListener('change', function () {
    document.getElementById('pengawas_email').value = '';
    const pencacah = document.getElementById('pencacah_email');
    if (pencacah) pencacah.value = '';
    this.form.submit();
  });
}
const pengawas = document.getElementById('pengawas_email');
if (pengawas) {
  pengawas.addEventListener('change', function () {
    const pencacah = document.getElementById('pencacah_email');
    if (pencacah) pencacah.value = '';
    this.form.submit();
  });
}
</script>
<?php endif; ?>

<?php if (in_array($activeTab, ['performa_pengawas', 'performa_pencacah'], true) && $canSeePerformance): ?>
<?php $labelRole = $activeTab === 'performa_pengawas' ? 'Pengawas' : 'Pencacah'; ?>
<?php $attentionThreshold = $performanceCache['attention_threshold'] ?? performance_attention_threshold(); $attentionType = $activeTab === 'performa_pengawas' ? 'pengawas' : 'pencacah'; ?>
<div class="card card-body py-2 mb-3">
  <div><strong><em>Progress Pendataan = Submit+Reject+Pending+Approve</em></strong></div>
</div>
<?php if (!$performanceMetricData): ?>
  <div class="alert alert-warning">
    Data performa belum tersedia. Superadmin perlu menjalankan menu <strong>Update Data Performa</strong>.
    <?php if ($user['role'] === 'superadmin'): ?>
      <a class="btn btn-sm btn-primary ml-2" href="performance_update.php">Buka Menu Update</a>
    <?php endif; ?>
  </div>
<?php else: ?>
<div class="alert <?= performance_cache_is_today($performanceCache) ? 'alert-info' : 'alert-warning' ?> py-2">
  <span class="data-update-dot"></span>
  Data Performa Terakhir Diperbarui: <strong><?= e(performance_cache_generated_label($performanceCache)) ?></strong>.
  <?php if (!performance_cache_is_today($performanceCache)): ?> Data performa belum diperbarui hari ini.<?php endif; ?>
</div>
<div class="card">
  <div class="card-header p-2">
    <ul class="nav nav-pills performance-tabs" role="tablist">
      <?php foreach ($performanceKabOptions as $i => $kab): ?>
        <li class="nav-item"><a class="nav-link <?= $i===0?'active':'' ?>" data-toggle="tab" href="#kab-<?= e($kab['value']) ?>" role="tab"><?= e($kab['label']) ?></a></li>
      <?php endforeach; ?>
    </ul>
  </div>
  <div class="card-body">
    <div class="tab-content">
      <?php foreach ($performanceKabOptions as $i => $kab): ?>
        <?php
          $topRows = array_slice($performanceMetricData['overall'][$kab['value']] ?? [], 0, 10);
          $weeklyRows = array_slice($performanceMetricData['weekly'][$kab['value']] ?? [], 0, 10);
          $weeklyPeriod = $performanceMetricData['week_period'] ?? null;
          $attentionRows = $performanceMetricData['attention'][$kab['value']] ?? [];
        ?>
        <div class="tab-pane fade <?= $i===0?'show active':'' ?>" id="kab-<?= e($kab['value']) ?>" role="tabpanel">
          <h5 class="performance-section-title">
            <span>
              10 Performa Sementara <?= e($labelRole) ?>
              <small class="text-muted ml-2">Data sampai <?= e(performance_date_label($performanceMetricData['as_of'])) ?></small>
            </span>
            <?php if (in_array($user['role'], ['superadmin', 'admin_kab', 'viewer_prov', 'viewer_kab'], true)): ?>
              <a class="btn btn-success btn-sm" href="?action=export_performance_temporary&type=<?= e($attentionType) ?>">
                <i class="fas fa-file-excel mr-1"></i>Download Semua <?= e($labelRole) ?>
              </a>
            <?php endif; ?>
          </h5>
          <div class="table-responsive mb-4">
            <table class="table table-sm table-bordered table-striped mb-0">
              <thead>
                <tr>
                  <th>Rank</th>
                  <th>Petugas</th>
                  <th>Kode Kab</th>
                  <th>Kecamatan</th>
                  <th>Wilayah Kerja</th>
                  <th>Target</th>
                  <th>Progress</th>
                  <th class="performance-compact-header">Rata-rata/<br>Hari<br>(Assignment)</th>
                  <th class="performance-compact-header">Capaian<br>Hari Ini<br>dibanding<br>Kemarin<br>(Assignment)</th>
                  <th class="performance-compact-header">Target<br>Hari Ini<br>(Assignment)</th>
                  <th>Standar Deviasi</th>
                  <th>Konsistensi</th>
                  <th>Prediksi Selesai</th>
                  <th>Status</th>
                  <th>Skor</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($topRows as $rankIndex => $r): ?>
                <tr>
                  <td><?= dashboard_rank_badge($rankIndex + 1) ?></td>
                  <td><?= performance_petugas_html((string)$r['email'], (string)($r['petugas_name'] ?? '')) ?></td>
                  <td><?= e($r['kab_codes'] ?: '-') ?></td>
                  <td class="performance-work-area"><?= e($r['wilayah_kerja_kecamatan'] ?: '-') ?></td>
                  <td class="performance-work-area"><?= performance_work_area_html((string)($r['wilayah_kerja'] ?? '')) ?></td>
                  <td class="text-right"><?= number_format((int)$r['target'],0,',','.') ?></td>
                  <td class="text-right performance-progress-cell"><?= dashboard_table_count_pct_text((int)$r['progress_count'], (int)$r['target']) ?></td>
                  <td class="text-right"><?= number_format((int)ceil((float)$r['average_per_day']),0,',','.') ?></td>
                  <td class="text-right"><?= number_format((int)$r['yesterday_achievement'],0,',','.') ?></td>
                  <td class="text-right"><?= $r['required_daily_target'] === null ? 'Lewat Target' : number_format((int)$r['required_daily_target'],0,',','.') ?></td>
                  <td class="text-right"><?= number_format((float)$r['stddev'],2,',','.') ?></td>
                  <td class="text-right"><?= number_format((float)$r['consistency_score'],2,',','.') ?>%</td>
                  <td><?= e($r['projected_finish']) ?></td>
                  <td><span class="performance-status"><?= e($r['performance_status']) ?></span></td>
                  <td class="text-right font-weight-bold"><?= number_format((float)$r['performance_score'],2,',','.') ?></td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$topRows): ?>
                <tr><td colspan="15" class="text-center text-muted">Belum ada data performa sementara.</td></tr>
              <?php endif; ?>
              </tbody>
            </table>
          </div>
          <h5 class="performance-section-title">
            <span>10 Performa Mingguan <?= e($labelRole) ?></span>
            <?php if ($weeklyPeriod): ?>
              <small class="text-muted">Minggu <?= (int)$weeklyPeriod['number'] ?>: <?= e(performance_date_label($weeklyPeriod['start'])) ?> - <?= e(performance_date_label($weeklyPeriod['end'])) ?></small>
            <?php else: ?>
              <small class="text-muted">Belum ada periode mingguan yang selesai.</small>
            <?php endif; ?>
          </h5>
          <div class="table-responsive mb-4">
            <table class="table table-sm table-bordered table-striped mb-0">
              <thead>
                <tr>
                  <th>Rank</th>
                  <th>Petugas</th>
                  <th>Kode Kab</th>
                  <th>Kecamatan</th>
                  <th>Wilayah Kerja</th>
                  <th>Target</th>
                  <th>Progress Awal Minggu</th>
                  <th>Tambahan Mingguan</th>
                  <th>Target Mingguan</th>
                  <th>Rata-rata/Hari</th>
                  <th>Standar Deviasi</th>
                  <th>Konsistensi</th>
                  <th>Kemampuan Mengejar</th>
                  <th>Skor</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($weeklyRows as $rankIndex => $r): ?>
                <tr>
                  <td><?= dashboard_rank_badge($rankIndex + 1) ?></td>
                  <td><?= performance_petugas_html((string)$r['email'], (string)($r['petugas_name'] ?? '')) ?></td>
                  <td><?= e($r['kab_codes'] ?: '-') ?></td>
                  <td class="performance-work-area"><?= e($r['wilayah_kerja_kecamatan'] ?: '-') ?></td>
                  <td class="performance-work-area"><?= performance_work_area_html((string)($r['wilayah_kerja'] ?? '')) ?></td>
                  <td class="text-right"><?= number_format((int)$r['target'],0,',','.') ?></td>
                  <td class="text-right"><?= number_format((int)$r['progress_before'],0,',','.') ?></td>
                  <td class="text-right"><?= number_format((int)$r['weekly_count'],0,',','.') ?></td>
                  <td class="text-right"><?= number_format((float)$r['weekly_target'],2,',','.') ?></td>
                  <td class="text-right"><?= number_format((int)ceil((float)$r['average_per_day']),0,',','.') ?></td>
                  <td class="text-right"><?= number_format((float)$r['stddev'],2,',','.') ?></td>
                  <td class="text-right"><?= number_format((float)$r['consistency_score'],2,',','.') ?>%</td>
                  <td class="text-right"><?= number_format((float)$r['momentum_score'],2,',','.') ?>%</td>
                  <td class="text-right font-weight-bold"><?= number_format((float)$r['performance_score'],2,',','.') ?></td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$weeklyRows): ?>
                <tr><td colspan="14" class="text-center text-muted"><?= $weeklyPeriod ? 'Belum ada data petugas aktif pada periode ini.' : 'Performa mingguan akan tampil setelah minggu pertama selesai.' ?></td></tr>
              <?php endif; ?>
              </tbody>
            </table>
          </div>
          <div class="d-flex flex-wrap justify-content-between align-items-center mb-2">
            <div>
              <h5 class="mb-1"><?= e($labelRole) ?> Perlu Perhatian</h5>
              <div class="text-muted small">Rule aktif: sampai <?= e(date('d/m/Y', strtotime($attentionThreshold['date']))) ?>, yang selesai SubSLS masih di bawah <?= e($attentionThreshold['pct']) ?>% masuk tabel ini.</div>
            </div>
            <div class="mt-2 mt-md-0">
              <a class="btn btn-outline-success btn-sm mr-2" href="?action=export_attention&type=<?= e($attentionType) ?>&kab_id=<?= e($kab['value']) ?>&format=csv"><i class="fas fa-file-csv mr-1"></i>Export CSV</a>
              <a class="btn btn-outline-success btn-sm" href="?action=export_attention&type=<?= e($attentionType) ?>&kab_id=<?= e($kab['value']) ?>&format=xlsx"><i class="fas fa-file-excel mr-1"></i>Export Excel</a>
            </div>
          </div>
          <div class="table-responsive">
            <table class="table table-sm table-bordered table-striped mb-0 attention-table" data-page-size="25">
              <thead><tr><th>Email</th><th>Kode Kab</th><th>Kecamatan</th><th>Wilayah Kerja</th><th>Draft</th><th>Progress Pendataan</th><th>Selesai SubSLS</th><th>Target</th><th>Total SubSLS</th></tr></thead>
              <tbody>
              <?php foreach ($attentionRows as $r): ?>
                <tr class="attention-row">
                  <td><?= e(petugas_label($r['email'], $r['petugas_name'] ?? '')) ?></td>
                  <td><?= e($r['kab_codes'] ?: '-') ?></td>
                  <td class="performance-work-area"><?= e($r['wilayah_kerja_kecamatan'] ?: '-') ?></td>
                  <td class="performance-work-area"><?= e($r['wilayah_kerja'] ?: '-') ?></td>
                  <td class="text-right"><?= dashboard_table_count_pct_text((int)$r['draft_count'], (int)$r['target']) ?></td>
                  <td class="text-right"><?= dashboard_table_count_pct_text(dashboard_pendataan_count($r), (int)$r['target']) ?></td>
                  <td class="text-right"><?= number_format((float)$r['selesai_pct'],2,',','.') ?>%</td>
                  <td class="text-right"><?= number_format((int)$r['target'],0,',','.') ?></td>
                  <td class="text-right"><?= number_format((int)$r['subsls_total'],0,',','.') ?></td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$attentionRows): ?>
                <tr><td colspan="9" class="text-center text-muted">Tidak ada <?= e(strtolower($labelRole)) ?> yang masuk kategori perlu perhatian.</td></tr>
              <?php endif; ?>
              </tbody>
            </table>
          </div>
          <?php if (count($attentionRows) > 25): ?>
            <div class="attention-pagination" data-table-target="kab-<?= e($kab['value']) ?>">
              <button class="btn btn-outline-secondary btn-sm attention-prev" type="button">Prev</button>
              <span class="small text-muted attention-info"></span>
              <button class="btn btn-outline-secondary btn-sm attention-next" type="button">Next</button>
            </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>
<?php endif; ?>

<script>
document.querySelectorAll('.attention-table').forEach(function (table) {
  const rows = Array.from(table.querySelectorAll('tbody tr.attention-row'));
  const pageSize = Number(table.getAttribute('data-page-size') || 25);
  if (rows.length <= pageSize) return;

  let page = 1;
  const pane = table.closest('.tab-pane');
  const pager = pane ? pane.querySelector('.attention-pagination') : null;
  const prev = pager ? pager.querySelector('.attention-prev') : null;
  const next = pager ? pager.querySelector('.attention-next') : null;
  const info = pager ? pager.querySelector('.attention-info') : null;
  const totalPages = Math.ceil(rows.length / pageSize);

  function render() {
    rows.forEach(function (row, index) {
      row.style.display = index >= (page - 1) * pageSize && index < page * pageSize ? '' : 'none';
    });
    if (info) info.textContent = 'Halaman ' + page + ' dari ' + totalPages + ' (' + rows.length + ' row)';
    if (prev) prev.disabled = page <= 1;
    if (next) next.disabled = page >= totalPages;
  }

  if (prev) prev.addEventListener('click', function () { if (page > 1) { page--; render(); } });
  if (next) next.addEventListener('click', function () { if (page < totalPages) { page++; render(); } });
  render();
});
</script>

<?php render_footer(); ?>
