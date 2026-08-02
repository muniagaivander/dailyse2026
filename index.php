<?php
require __DIR__ . '/layout.php';
require_once __DIR__ . '/performance_cache.php';
$user = require_login();
ensure_completion_status_table();

$fields = status_fields();
$statusColors = ['#2563eb', '#f59e0b', '#16a34a', '#dc2626', '#7c3aed', '#0f766e'];
$rangeColors = [
    ['label' => '< 20%', 'color' => '#b91c1c'],
    ['label' => '20% - < 40%', 'color' => '#f87171'],
    ['label' => '40% - < 60%', 'color' => '#d97706'],
    ['label' => '60% - < 75%', 'color' => '#facc15'],
    ['label' => '75% - < 85%', 'color' => '#22c55e'],
    ['label' => '85% - < 100%', 'color' => '#15803d'],
];
$activeTab = $_GET['tab'] ?? 'submit_approve';
$allowedTabs = ['submit_approve', 'status', 'selesai'];
$canSeePerformance = in_array($user['role'], ['superadmin', 'admin_kab', 'viewer_prov', 'viewer_kab', 'pengawas', 'pencacah'], true);
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
    'subsls_id' => $_GET['subsls_id'] ?? '',
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
    $out = ['kabupaten' => [], 'kecamatan' => [], 'desa' => [], 'subsls' => [], 'pengawas' => [], 'pencacah' => []];
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
    if (!empty($filters['pencacah_email'])) {
        $where = ['ms.pencacah_email=?'];
        $params = [$filters['pencacah_email']];
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
        if (!empty($filters['pengawas_email'])) {
            $where[] = 'ms.pengawas_email=?';
            $params[] = $filters['pengawas_email'];
        }
        $stmt = db()->prepare("SELECT ms.id value, CONCAT(sl.kdsls, ms.kdsubsls, ' - ', sl.nmsls, ' - ', ms.kdsubsls) label
            FROM master_subsls ms
            JOIN master_sls sl ON sl.id=ms.sls_id
            JOIN master_desa d ON d.id=sl.desa_id
            JOIN master_kec kc ON kc.id=d.kec_id
            JOIN master_kab k ON k.id=kc.kab_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY sl.kdsls, ms.kdsubsls");
        $stmt->execute($params);
        $out['subsls'] = $stmt->fetchAll();
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
    if (!empty($filters['subsls_id'])) {
        $where[] = 'ms.id=?';
        $params[] = $filters['subsls_id'];
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
    if (!empty($filters['subsls_id']) || !empty($filters['desa_id']) || $user['role'] === 'pencacah') {
        return ['ms.id', "CONCAT(sl.kdsls, ms.kdsubsls)"];
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

function dashboard_map_grouping(array $filters): array
{
    if (!empty($filters['desa_id'])) {
        return ['subsls', 'ms.id', "CONCAT(sl.kdsls, ms.kdsubsls)"];
    }
    if (!empty($filters['kec_id'])) {
        return ['desa', 'd.id', "CONCAT(d.kddesa,' - ',d.nmdesa)"];
    }
    if (!empty($filters['kab_id'])) {
        return ['kecamatan', 'kc.id', "CONCAT(kc.kdkec,' - ',kc.nmkec)"];
    }
    return ['kabupaten', 'k.id', "CONCAT(k.id,' - ',k.nmkab)"];
}

function dashboard_map_rows(array $user, array $filters, array $fields): array
{
    [$sqlWhere, $params] = dashboard_where($user, $filters);
    [$level, $codeExpr, $labelExpr] = dashboard_map_grouping($filters);
    $selects = [];
    foreach (array_keys($fields) as $f) {
        $selects[] = "COALESCE(SUM(ss.$f),0) $f";
    }
    $petugasSelect = $level === 'subsls'
        ? ", MAX(up.name) pengawas_name, MAX(ms.pengawas_email) pengawas_email, MAX(uc.name) pencacah_name, MAX(ms.pencacah_email) pencacah_email"
        : "";
    $petugasJoin = $level === 'subsls'
        ? "LEFT JOIN users up ON up.email=ms.pengawas_email
        LEFT JOIN users uc ON uc.email=ms.pencacah_email"
        : "";
    $stmt = db()->prepare("SELECT $codeExpr code, $labelExpr label, COALESCE(SUM(ss.target),0) target, " . implode(',', $selects) . ",
            COUNT(ms.id) subsls_total
            $petugasSelect
        FROM master_subsls ms
        JOIN master_sls sl ON sl.id=ms.sls_id
        JOIN master_desa d ON d.id=sl.desa_id
        JOIN master_kec kc ON kc.id=d.kec_id
        JOIN master_kab k ON k.id=kc.kab_id
        JOIN master_prov p ON p.id=k.prov_id
        LEFT JOIN subsls_status ss ON ss.subsls_id=ms.id
        $petugasJoin
        $sqlWhere
        GROUP BY $codeExpr, label
        ORDER BY $codeExpr");
    $stmt->execute($params);
    return ['level' => $level, 'rows' => $stmt->fetchAll()];
}

function dashboard_area_breadcrumb(array $user, array $filters): string
{
    return implode(' >> ', array_map(fn($item) => $item['label'], dashboard_area_breadcrumb_items($user, $filters)));
}

function dashboard_area_breadcrumb_items(array $user, array $filters): array
{
    $parts = [];
    $base = ['tab' => 'submit_approve'];
    $kabId = in_array($user['role'], ['admin_kab', 'viewer_kab'], true) ? (string)$user['kab_id'] : (string)($filters['kab_id'] ?? '');
    $parts[] = [
        'label' => 'Kaltim',
        'href' => in_array($user['role'], ['superadmin', 'viewer_prov'], true) ? '?' . http_build_query($base) : null,
    ];
    if ($kabId !== '') {
        $stmt = db()->prepare("SELECT nmkab FROM master_kab WHERE id=?");
        $stmt->execute([$kabId]);
        $parts[] = [
            'label' => (string)($stmt->fetchColumn() ?: $kabId),
            'href' => '?' . http_build_query($base + ['kab_id' => $kabId]),
        ];
    }

    if (!empty($filters['kec_id'])) {
        $stmt = db()->prepare("SELECT kdkec, nmkec FROM master_kec WHERE id=?");
        $stmt->execute([$filters['kec_id']]);
        $row = $stmt->fetch() ?: [];
        $parts[] = [
            'label' => ($row ? (string)$row['kdkec'] . '-' . (string)$row['nmkec'] : (string)$filters['kec_id']),
            'href' => '?' . http_build_query($base + ['kab_id' => $kabId, 'kec_id' => $filters['kec_id']]),
        ];
    }
    if (!empty($filters['desa_id'])) {
        $stmt = db()->prepare("SELECT kddesa, nmdesa FROM master_desa WHERE id=?");
        $stmt->execute([$filters['desa_id']]);
        $row = $stmt->fetch() ?: [];
        $parts[] = [
            'label' => ($row ? (string)$row['kddesa'] . '-' . (string)$row['nmdesa'] : (string)$filters['desa_id']),
            'href' => '?' . http_build_query($base + ['kab_id' => $kabId, 'kec_id' => $filters['kec_id'], 'desa_id' => $filters['desa_id']]),
        ];
    }
    if (!empty($filters['subsls_id'])) {
        $stmt = db()->prepare("SELECT CONCAT(sl.kdsls, '-', ms.kdsubsls, ' ', sl.nmsls, ' - Sub ', ms.kdsubsls)
            FROM master_subsls ms
            JOIN master_sls sl ON sl.id=ms.sls_id
            WHERE ms.id=?");
        $stmt->execute([$filters['subsls_id']]);
        $parts[] = [
            'label' => (string)($stmt->fetchColumn() ?: $filters['subsls_id']),
            'href' => '?' . http_build_query($base + ['kab_id' => $kabId, 'kec_id' => $filters['kec_id'], 'desa_id' => $filters['desa_id'], 'subsls_id' => $filters['subsls_id']]),
        ];
    }

    return array_values(array_filter($parts, fn($part) => trim((string)$part['label']) !== ''));
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

function dashboard_petugas_counts(array $user, array $filters): array
{
    [$sqlWhere, $params] = dashboard_where($user, $filters);
    $stmt = db()->prepare("SELECT
            COUNT(DISTINCT NULLIF(ms.pencacah_email,'')) pencacah_total,
            COUNT(DISTINCT NULLIF(ms.pengawas_email,'')) pengawas_total
        FROM master_subsls ms
        JOIN master_sls sl ON sl.id=ms.sls_id
        JOIN master_desa d ON d.id=sl.desa_id
        JOIN master_kec kc ON kc.id=d.kec_id
        JOIN master_kab k ON k.id=kc.kab_id
        JOIN master_prov p ON p.id=k.prov_id
        LEFT JOIN subsls_status ss ON ss.subsls_id=ms.id
        LEFT JOIN subsls_completion_status cs ON cs.subsls_id=ms.id
        $sqlWhere");
    $stmt->execute($params);
    $row = $stmt->fetch() ?: [];
    return [
        'pcl' => (int)($row['pencacah_total'] ?? 0),
        'pml' => (int)($row['pengawas_total'] ?? 0),
    ];
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
        'projected_finish_iso' => $completionDate ?: $projectedFinishDate,
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
    if (in_array($user['role'], ['pengawas', 'pencacah'], true)) {
        $field = $user['role'] === 'pengawas' ? 'ms.pengawas_email' : 'ms.pencacah_email';
        $stmt = db()->prepare("SELECT DISTINCT k.id value, CONCAT(k.id,' - ',k.nmkab) label
            FROM master_subsls ms
            JOIN master_sls sl ON sl.id=ms.sls_id
            JOIN master_desa d ON d.id=sl.desa_id
            JOIN master_kec kc ON kc.id=d.kec_id
            JOIN master_kab k ON k.id=kc.kab_id
            WHERE {$field}=?
            ORDER BY k.id");
        $stmt->execute([$user['email']]);
        return $stmt->fetchAll();
    }
    $rows = db()->query("SELECT id value, CONCAT(id,' - ',nmkab) label FROM master_kab ORDER BY id")->fetchAll();
    array_unshift($rows, ['value' => '6400', 'label' => '6400 - Kalimantan Timur']);
    return $rows;
}

function dashboard_performance_related_emails(array $user, string $type, string $kabId): array
{
    $email = normalize_email((string)($user['email'] ?? ''));
    if ($email === '' || !in_array($user['role'], ['pengawas', 'pencacah'], true)) {
        return [];
    }
    if ($user['role'] === 'pengawas') {
        if ($type === 'pengawas') {
            return [$email => true];
        }
        $stmt = db()->prepare("SELECT DISTINCT ms.pencacah_email email
            FROM master_subsls ms
            JOIN master_sls sl ON sl.id=ms.sls_id
            JOIN master_desa d ON d.id=sl.desa_id
            JOIN master_kec kc ON kc.id=d.kec_id
            WHERE kc.kab_id=? AND ms.pengawas_email=? AND ms.pencacah_email IS NOT NULL AND ms.pencacah_email <> ''");
        $stmt->execute([$kabId, $email]);
        return array_fill_keys(array_map(fn($row) => normalize_email((string)$row['email']), $stmt->fetchAll()), true);
    }

    $stmt = db()->prepare("SELECT DISTINCT ms.pengawas_email email
        FROM master_subsls ms
        JOIN master_sls sl ON sl.id=ms.sls_id
        JOIN master_desa d ON d.id=sl.desa_id
        JOIN master_kec kc ON kc.id=d.kec_id
        WHERE kc.kab_id=? AND ms.pencacah_email=? AND ms.pengawas_email IS NOT NULL AND ms.pengawas_email <> ''");
    $stmt->execute([$kabId, $email]);
    $pengawasEmails = array_map(fn($row) => normalize_email((string)$row['email']), $stmt->fetchAll());
    if ($type === 'pengawas') {
        return array_fill_keys($pengawasEmails, true);
    }
    if (!$pengawasEmails) {
        return [$email => true];
    }
    $placeholders = implode(',', array_fill(0, count($pengawasEmails), '?'));
    $params = array_merge([$kabId], $pengawasEmails);
    $stmt = db()->prepare("SELECT DISTINCT ms.pencacah_email email
        FROM master_subsls ms
        JOIN master_sls sl ON sl.id=ms.sls_id
        JOIN master_desa d ON d.id=sl.desa_id
        JOIN master_kec kc ON kc.id=d.kec_id
        WHERE kc.kab_id=? AND ms.pengawas_email IN ({$placeholders}) AND ms.pencacah_email IS NOT NULL AND ms.pencacah_email <> ''");
    $stmt->execute($params);
    return array_fill_keys(array_map(fn($row) => normalize_email((string)$row['email']), $stmt->fetchAll()), true);
}

function dashboard_performance_visible_rows(array $rows, array $relatedEmails, int $topLimit = 10): array
{
    $visible = [];
    $seen = [];
    foreach ($rows as $index => $row) {
        if ($index >= $topLimit && empty($relatedEmails[normalize_email((string)($row['email'] ?? ''))])) {
            continue;
        }
        $email = normalize_email((string)($row['email'] ?? ''));
        if (isset($seen[$email])) {
            continue;
        }
        $row['_rank'] = $index + 1;
        $row['_highlight'] = !empty($relatedEmails[$email]);
        $visible[] = $row;
        $seen[$email] = true;
    }
    return $visible;
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

function dashboard_xlsx_numeric_value($value): ?string
{
    if (is_int($value) || is_float($value)) {
        return (string)(0 + $value);
    }
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    $value = str_replace('%', '', $value);
    if (preg_match('/^-?\d{1,3}(\.\d{3})*(,\d+)?$/', $value)) {
        $value = str_replace('.', '', $value);
        $value = str_replace(',', '.', $value);
    } elseif (preg_match('/^-?\d+(,\d+)?$/', $value)) {
        $value = str_replace(',', '.', $value);
    }
    return is_numeric($value) ? (string)(0 + $value) : null;
}

function dashboard_xlsx_header_is_numeric(string $header): bool
{
    $header = strtolower($header);
    foreach (['kode', 'id', 'email', 'nama', 'petugas', 'kabupaten', 'kecamatan', 'desa', 'wilayah', 'status', 'tanggal', 'prediksi'] as $textPart) {
        if (str_contains($header, $textPart)) {
            return false;
        }
    }
    foreach (['rank', 'target', 'open', 'draft', 'submit', 'reject', 'pending', 'approved', 'approve', 'progress', 'count', 'persen', 'pct', 'selesai', 'subsls', 'rata', 'capaian', 'skor', 'deviasi', 'konsistensi', 'momentum'] as $numericPart) {
        if (str_contains($header, $numericPart)) {
            return true;
        }
    }
    return false;
}

function dashboard_xlsx_header_is_pct(string $header): bool
{
    $header = strtolower($header);
    return str_contains($header, 'pct') || str_contains($header, 'persen') || str_contains($header, 'percent');
}

function dashboard_xlsx_cell($value, int $row, int $col, bool $numeric = false, int $style = 0): string
{
    $ref = dashboard_xlsx_col($col) . $row;
    if ($numeric) {
        $number = dashboard_xlsx_numeric_value($value);
        if ($number !== null) {
            return '<c r="' . $ref . '" s="' . $style . '"><v>' . htmlspecialchars($number, ENT_XML1) . '</v></c>';
        }
    }
    $value = (string)$value;
    return '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is><t>' . htmlspecialchars($value, ENT_XML1) . '</t></is></c>';
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
  <cellXfs count="2">
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
    <xf numFmtId="2" fontId="0" fillId="0" borderId="0" xfId="0"/>
  </cellXfs>
</styleSheet>');
    $sheet = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
    foreach ($sheetRows as $rIndex => $values) {
        $rowNumber = $rIndex + 1;
        $sheet .= '<row r="' . $rowNumber . '">';
        foreach ($values as $cIndex => $value) {
            $header = (string)($headers[$cIndex] ?? '');
            $numeric = $rowNumber > 1 && dashboard_xlsx_header_is_numeric($header);
            $style = $rowNumber > 1 && dashboard_xlsx_header_is_pct($header) ? 1 : 0;
            $sheet .= dashboard_xlsx_cell($value, $rowNumber, $cIndex + 1, $numeric, $style);
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
                $line[] = dashboard_export_pct_value($target > 0 ? ((float)($row[$field] ?? 0)) / $target * 100 : 0);
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
                dashboard_export_pct_value($total > 0 ? ((float)($row['selesai_count'] ?? 0)) / $total * 100 : 0),
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
                dashboard_export_pct_value($target > 0 ? $pendataan / $target * 100 : 0),
            ];
        }
    }
    return [$headers, $out];
}

function dashboard_export_pct_value(float $pct): string
{
    return number_format($pct, 2, '.', '');
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
            round($progressPct, 2),
            (int)ceil((float)$row['average_per_day']),
            (int)$row['yesterday_achievement'],
            round((float)$row['stddev'], 2),
            round((float)$row['consistency_score'], 2),
            $row['projected_finish_iso'] ?? $row['projected_finish'],
            $row['performance_status'],
            round((float)$row['performance_score'], 2),
        ];
    }
    dashboard_export_rows(
        ['rank', 'petugas', 'kode_kab', 'kecamatan', 'wilayah_kerja', 'target', 'progress_count', 'progress_persen', 'rata_rata_per_hari', 'capaian_kemarin_assignment', 'standar_deviasi', 'konsistensi_persen', 'prediksi_selesai', 'status', 'skor'],
        $exportRows,
        'performa_sementara_' . $type . '_' . $scopeId . '_' . date('Ymd_His'),
        'xlsx'
    );
}

if (($_GET['action'] ?? '') === 'export_attention'
    && in_array($user['role'], ['superadmin', 'admin_kab', 'viewer_prov', 'viewer_kab'], true)) {
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
        $target = (int)$row['target'];
        $draftCount = (int)$row['draft_count'];
        $progressCount = dashboard_pendataan_count($row);
        $exportRows[] = [
            petugas_label($row['email'], $row['petugas_name'] ?? ''),
            $draftCount,
            $target > 0 ? round($draftCount / $target * 100, 2) : 0,
            $progressCount,
            $target > 0 ? round($progressCount / $target * 100, 2) : 0,
            $row['selesai_pct'],
            $threshold['pct'],
            $threshold['date'],
            $target,
            $row['submitted_by_pencacah'],
            $row['rejected_by_pengawas'],
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
        ['petugas', 'draft_count', 'draft_persen', 'progress_pendataan_count', 'progress_pendataan_persen', 'selesai_subsls_pct', 'threshold_selesai_pct', 'batas_tanggal', 'target', 'submitted_by_pencacah', 'rejected_by_pengawas', 'pending_count', 'approved_by_pengawas', 'kode_kab', 'kecamatan', 'wilayah_kerja', 'subsls_total', 'selesai_count'],
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
$dashboardMap = dashboard_map_rows($user, $filters, $fields);
$totals = dashboard_totals($chartRows, $fields);
$petugasCounts = dashboard_petugas_counts($user, $filters);
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

function dashboard_table_pct_only_text(float $pct): string
{
    return '<span class="dashboard-table-pct">' . e(number_format($pct, 2, ',', '.')) . '%</span>';
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

$EXTRA_HEAD = ($EXTRA_HEAD ?? '') . '
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css">
  <script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js"></script>';
$HIDE_PAGE_TITLE = true;

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
.performance-related-row td {
  font-weight: 700;
}
.performance-related-row .performance-staff-email,
.performance-related-row .performance-work-area {
  font-weight: 700;
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
.dashboard-summary-table th {
  text-align: center;
}
.dashboard-summary-table tfoot td {
  font-weight: 800;
}
.dashboard-summary-table .summary-head-blue,
.attention-table .summary-head-blue {
  background: #dbeafe !important;
  color: #1e3a8a;
}
.dashboard-summary-table .summary-head-yellow,
.attention-table .summary-head-yellow {
  background: #fef3c7 !important;
  color: #78350f;
}
.dashboard-summary-table .summary-head-light-green,
.attention-table .summary-head-light-green {
  background: #dcfce7 !important;
  color: #14532d;
}
.dashboard-summary-table .summary-head-red,
.attention-table .summary-head-red {
  background: #fee2e2 !important;
  color: #b91c1c;
}
.dashboard-summary-table .summary-head-dark-green,
.attention-table .summary-head-dark-green {
  background: #bbf7d0 !important;
  color: #064e3b;
}
.dashboard-summary-table .summary-search-input,
.dashboard-summary-table .summary-sort-select {
  border-radius: 4px;
  font-size: .72rem;
  height: 24px;
  margin-top: 5px;
  min-width: 84px;
  padding: 1px 5px;
}
.dashboard-summary-table .summary-search-input {
  min-width: 150px;
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
  background: #fff7ed !important;
  border: 1px solid #f0b35c;
  border-left: 5px solid #f59e0b;
  border-radius: 8px;
  box-shadow: 0 4px 12px rgba(15, 23, 42, .06);
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
.dashboard-stat-card.card-orange {
  background: #fff7ed !important;
  border-color: #f0b35c;
  border-left-color: #f59e0b;
}
.dashboard-stat-card.card-orange p { color: #92400e; }
.dashboard-stat-card.card-progress {
  background: #d1fae5 !important;
  border-color: #34d399;
  border-left-color: #047857;
}
.dashboard-stat-card.card-progress p { color: #065f46; }
.dashboard-stat-card.card-light-green {
  background: #dcfce7 !important;
  border-color: #86efac;
  border-left-color: #22c55e;
}
.dashboard-stat-card.card-light-green p { color: #166534; }
.dashboard-stat-card.card-blue {
  background: #dbeafe !important;
  border-color: #93c5fd;
  border-left-color: #2563eb;
}
.dashboard-stat-card.card-blue p { color: #1e40af; }
.dashboard-stat-card.card-yellow {
  background: #fef3c7 !important;
  border-color: #fcd34d;
  border-left-color: #f59e0b;
}
.dashboard-stat-card.card-yellow p { color: #92400e; }
.dashboard-stat-card.card-red {
  background: #fee2e2 !important;
  border-color: #fca5a5;
  border-left-color: #dc2626;
}
.dashboard-stat-card.card-red p { color: #991b1b; }
.dashboard-map-summary {
  display: grid;
  grid-template-columns: minmax(0, 1.35fr) minmax(320px, .65fr);
  gap: 16px;
  margin-bottom: 16px;
}
.dashboard-map-card {
  border: 1px solid #e5e7eb;
  border-radius: 8px;
  overflow: hidden;
}
.dashboard-map-header {
  align-items: center;
  background: #fb923c;
  border-bottom: 1px solid #ea580c;
  display: flex;
  gap: 10px;
  justify-content: space-between;
  padding: 10px 12px;
}
.dashboard-map-title {
  color: #111827;
  font-size: .92rem;
  font-weight: 800;
  line-height: 1.15;
}
.dashboard-map-subtitle {
  color: #111827;
  font-size: .72rem;
  line-height: 1.15;
}
.dashboard-map-header .btn {
  background: #2563eb;
  border-color: #1d4ed8;
  color: #ffffff;
  font-weight: 800;
}
.dashboard-map-header .btn i {
  color: #ffffff;
}
.dashboard-map-header .btn:hover {
  background: #1d4ed8;
  border-color: #1e40af;
  color: #ffffff;
}
.dashboard-map-breadcrumb {
  background: #ffffff;
  border: 1px solid #fdba74;
  border-radius: 999px;
  color: #1f2937;
  font-size: .95rem;
  font-weight: 800;
  margin: 10px 12px 12px;
  overflow: hidden;
  padding: 7px 14px;
  text-align: center;
  text-overflow: ellipsis;
  white-space: normal;
}
.dashboard-map-breadcrumb a {
  color: #1d4ed8;
  text-decoration: none;
}
.dashboard-map-breadcrumb a:hover {
  color: #ea580c;
  text-decoration: underline;
}
.dashboard-map-breadcrumb .breadcrumb-separator {
  color: #9ca3af;
  margin: 0 7px;
}
#dashboardProgressMap {
  background: #f8fafc;
  height: 520px;
  width: 100%;
}
.dashboard-map-loading {
  align-items: center;
  color: #6b7280;
  display: flex;
  height: 100%;
  justify-content: center;
}
.dashboard-map-popup {
  font-size: .82rem;
  line-height: 1.45;
}
.dashboard-map-popup strong {
  color: #111827;
}
.dashboard-map-tooltip {
  background: rgba(255, 255, 255, .95);
  border: 1px solid rgba(148, 163, 184, .85);
  border-radius: 6px;
  box-shadow: 0 4px 14px rgba(15, 23, 42, .18);
  color: #111827;
  font-size: 11px;
  line-height: 1.35;
  padding: 7px 8px;
}
.dashboard-map-tooltip .map-tooltip-title {
  font-weight: 800;
  margin-bottom: 4px;
}
.dashboard-map-tooltip .map-tooltip-row {
  display: flex;
  gap: 12px;
  justify-content: space-between;
  min-width: 155px;
}
.dashboard-map-tooltip hr {
  border: 0;
  border-top: 1px solid #e5e7eb;
  margin: 5px 0;
}
.dashboard-map-area-label {
  background: transparent;
  border: 0;
  color: #111827;
  font-size: 10px;
  font-weight: 800;
  line-height: 1;
  pointer-events: none;
  text-align: center;
  text-shadow:
    -1px -1px 0 #ffffff,
    0 -1px 0 #ffffff,
    1px -1px 0 #ffffff,
    -1px 0 0 #ffffff,
    1px 0 0 #ffffff,
    -1px 1px 0 #ffffff,
    0 1px 0 #ffffff,
    1px 1px 0 #ffffff,
    0 2px 3px rgba(255, 255, 255, .9);
  white-space: nowrap;
}
.dashboard-map-legend {
  background: rgba(255, 255, 255, .92);
  border: 1px solid rgba(148, 163, 184, .7);
  border-radius: 6px;
  box-shadow: 0 6px 18px rgba(15, 23, 42, .16);
  color: #111827;
  font-size: 10px;
  line-height: 1.15;
  padding: 6px 7px;
}
.dashboard-map-legend-row {
  align-items: center;
  display: flex;
  gap: 5px;
  white-space: nowrap;
}
.dashboard-map-legend-row + .dashboard-map-legend-row {
  margin-top: 3px;
}
.dashboard-map-legend-swatch {
  border-radius: 999px;
  display: inline-block;
  height: 8px;
  width: 8px;
}
.dashboard-map-opacity-control {
  background: rgba(255, 255, 255, .94);
  border: 1px solid rgba(148, 163, 184, .7);
  border-radius: 6px;
  box-shadow: 0 4px 12px rgba(15, 23, 42, .16);
  color: #111827;
  font-size: 10px;
  font-weight: 800;
  padding: 6px 7px;
  width: 38px;
}
.dashboard-map-opacity-control input[type="range"] {
  display: block;
  height: 86px;
  margin: 0 auto;
  writing-mode: vertical-lr;
  direction: rtl;
  accent-color: #ea580c;
}
.dashboard-map-label-toggle {
  background: #2563eb;
  border: 1px solid #1d4ed8;
  border-radius: 5px;
  color: #ffffff;
  display: block;
  font-size: 8px;
  font-weight: 800;
  line-height: .95;
  margin: 7px auto 0;
  padding: 4px 1px;
  text-align: center;
  width: 28px;
}
.dashboard-map-label-toggle.is-off {
  background: #6b7280;
  border-color: #4b5563;
}
.dashboard-side-card-grid {
  display: grid;
  gap: 10px;
  grid-template-columns: repeat(2, minmax(0, 1fr));
}
.dashboard-side-card-grid .dashboard-stat-card {
  margin-bottom: 0;
}
.dashboard-side-card-grid .dashboard-stat-card .inner {
  padding: 11px;
}
.dashboard-side-card-grid .dashboard-stat-card h4 {
  font-size: 1.12rem;
}
.dashboard-side-card-grid .dashboard-stat-card p {
  font-size: .78rem;
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
  .dashboard-map-summary {
    grid-template-columns: 1fr;
  }
  #dashboardProgressMap {
    height: 380px;
  }
}
</style>

<div class="dashboard-tabs">
  <a class="dashboard-tab <?= $activeTab==='submit_approve'?'active':'' ?>" href="?<?= e(http_build_query(array_merge($_GET, ['tab' => 'submit_approve']))) ?>">Pendataan</a>
  <a class="dashboard-tab <?= $activeTab==='status'?'active':'' ?>" href="?<?= e(http_build_query(array_merge($_GET, ['tab' => 'status']))) ?>">Status</a>
  <a class="dashboard-tab <?= $activeTab==='selesai'?'active':'' ?>" href="?<?= e(http_build_query(array_merge($_GET, ['tab' => 'selesai']))) ?>">Selesai SubSLS</a>
  <?php if ($canSeePerformance): ?>
    <a class="dashboard-tab <?= $activeTab==='performa_pengawas'?'active':'' ?>" href="?tab=performa_pengawas">Performa PML</a>
    <a class="dashboard-tab <?= $activeTab==='performa_pencacah'?'active':'' ?>" href="?tab=performa_pencacah">Performa PCL</a>
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
<?php
  $targetTotal = (int)$totals['target'];
  $dashboardCards = [
      ['label' => 'Target', 'value' => dashboard_count_only_text($targetTotal), 'variant' => 'card-orange'],
      ['label' => 'Progress Pendataan', 'value' => dashboard_count_pct_text($submitApproveCount, $submitApprovePct), 'variant' => 'card-progress'],
      ['label' => 'Approve', 'value' => dashboard_count_pct_text((int)$totals['approved_by_pengawas'], $targetTotal ? (int)$totals['approved_by_pengawas'] / $targetTotal * 100 : 0), 'variant' => 'card-light-green'],
      ['label' => 'Submit', 'value' => dashboard_count_pct_text((int)$totals['submitted_by_pencacah'], $targetTotal ? (int)$totals['submitted_by_pencacah'] / $targetTotal * 100 : 0), 'variant' => 'card-blue'],
      ['label' => 'Open', 'value' => dashboard_count_pct_text((int)$totals['open_count'], $targetTotal ? (int)$totals['open_count'] / $targetTotal * 100 : 0), 'variant' => 'card-orange'],
      ['label' => 'Draft', 'value' => dashboard_count_pct_text((int)$totals['draft_count'], $targetTotal ? (int)$totals['draft_count'] / $targetTotal * 100 : 0), 'variant' => 'card-yellow'],
      ['label' => 'Reject', 'value' => dashboard_count_pct_text((int)$totals['rejected_by_pengawas'], $targetTotal ? (int)$totals['rejected_by_pengawas'] / $targetTotal * 100 : 0), 'variant' => 'card-red'],
      ['label' => 'Pending', 'value' => dashboard_count_pct_text((int)$totals['pending_count'], $targetTotal ? (int)$totals['pending_count'] / $targetTotal * 100 : 0), 'variant' => 'card-yellow'],
      ['label' => 'SubSLS Selesai', 'value' => dashboard_count_pct_text((int)$totals['selesai_count'], $completionPct), 'variant' => 'card-orange'],
      ['label' => 'Total SubSLS', 'value' => dashboard_count_only_text((int)$totals['subsls_total']), 'variant' => 'card-orange'],
      ['label' => 'PCL', 'value' => dashboard_count_only_text((int)$petugasCounts['pcl']), 'variant' => 'card-orange'],
      ['label' => 'PML', 'value' => dashboard_count_only_text((int)$petugasCounts['pml']), 'variant' => 'card-orange'],
  ];
?>
<?php if ($activeTab === 'submit_approve'): ?>
  <?php
    $mapLevelLabels = [
        'kabupaten' => 'Kabupaten',
        'kecamatan' => 'Kecamatan',
        'desa' => 'Desa',
        'subsls' => 'SubSLS',
    ];
    $mapTitle = 'Progres per ' . ($mapLevelLabels[$dashboardMap['level']] ?? 'Wilayah');
    $mapBreadcrumb = dashboard_area_breadcrumb($user, $filters);
    $mapBreadcrumbItems = dashboard_area_breadcrumb_items($user, $filters);
    $mapBackLabel = null;
    $mapBackQuery = $_GET;
    $mapBackQuery['tab'] = 'submit_approve';
    if ($dashboardMap['level'] === 'kecamatan') {
        unset($mapBackQuery['kab_id'], $mapBackQuery['kec_id'], $mapBackQuery['desa_id'], $mapBackQuery['subsls_id'], $mapBackQuery['pengawas_email'], $mapBackQuery['pencacah_email']);
        $mapBackLabel = 'Kembali';
    } elseif ($dashboardMap['level'] === 'desa') {
        unset($mapBackQuery['kec_id'], $mapBackQuery['desa_id'], $mapBackQuery['subsls_id'], $mapBackQuery['pengawas_email'], $mapBackQuery['pencacah_email']);
        $mapBackLabel = 'Kembali';
    } elseif ($dashboardMap['level'] === 'subsls') {
        unset($mapBackQuery['desa_id'], $mapBackQuery['subsls_id'], $mapBackQuery['pengawas_email'], $mapBackQuery['pencacah_email']);
        $mapBackLabel = 'Kembali';
    }
  ?>
  <div class="dashboard-map-summary">
    <div class="dashboard-map-card">
      <div class="dashboard-map-header">
        <div>
          <div class="dashboard-map-title"><?= e($mapTitle) ?></div>
          <div class="dashboard-map-subtitle">Klik untuk menyesuaikan filter</div>
        </div>
        <?php if ($mapBackLabel): ?>
          <a class="btn btn-outline-secondary btn-sm" href="?<?= e(http_build_query($mapBackQuery)) ?>"><i class="fas fa-arrow-left mr-1"></i><?= e($mapBackLabel) ?></a>
        <?php endif; ?>
      </div>
      <div id="dashboardProgressMap"><div class="dashboard-map-loading">Memuat peta...</div></div>
      <div class="dashboard-map-breadcrumb" title="<?= e($mapBreadcrumb) ?>">
        <?php foreach ($mapBreadcrumbItems as $i => $item): ?>
          <?php if ($i > 0): ?><span class="breadcrumb-separator">&gt;&gt;</span><?php endif; ?>
          <?php if (!empty($item['href'])): ?>
            <a href="<?= e($item['href']) ?>"><?= e($item['label']) ?></a>
          <?php else: ?>
            <span><?= e($item['label']) ?></span>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="dashboard-side-card-grid">
      <?php foreach ($dashboardCards as $card): ?>
        <div class="small-box dashboard-stat-card <?= e($card['variant'] ?? 'card-orange') ?>">
          <div class="inner">
            <h4 class="mb-1"><?= $card['value'] ?></h4>
            <p><?= e($card['label']) ?></p>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
<?php else: ?>
  <div class="row">
    <?php foreach ($dashboardCards as $card): ?>
      <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6">
        <div class="small-box dashboard-stat-card <?= e($card['variant'] ?? 'card-orange') ?>">
          <div class="inner">
            <h4 class="mb-1"><?= $card['value'] ?></h4>
            <p><?= e($card['label']) ?></p>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if (in_array($activeTab, ['submit_approve', 'selesai'], true)): ?>
  <div class="range-legend">
    <?php foreach ($rangeColors as $item): ?><span><i style="background:<?= e($item['color']) ?>"></i><?= e($item['label']) ?></span><?php endforeach; ?>
  </div>
<?php endif; ?>

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
      <div class="form-group col-md-2">
        <label>PML</label>
        <select class="form-control" name="pengawas_email" id="pengawas_email" <?= $filters['desa_id'] ? '' : 'disabled' ?>>
          <option value=""><?= $filters['desa_id'] ? 'Semua PML' : 'Pilih desa dulu' ?></option>
          <?php foreach ($opts['pengawas'] as $o): ?><option value="<?= e($o['value']) ?>" <?= $filters['pengawas_email']===$o['value']?'selected':'' ?>><?= e($o['label']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-group col-md-2">
        <label>PCL</label>
        <select class="form-control" name="pencacah_email" id="pencacah_email" <?= $filters['pengawas_email'] ? '' : 'disabled' ?>>
          <option value=""><?= $filters['pengawas_email'] ? 'Semua PCL' : 'Pilih PML dulu' ?></option>
          <?php foreach ($opts['pencacah'] as $o): ?><option value="<?= e($o['value']) ?>" <?= $filters['pencacah_email']===$o['value']?'selected':'' ?>><?= e($o['label']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-group col-md-2">
        <label>SubSLS</label>
        <select class="form-control" name="subsls_id" id="subsls_id" <?= $filters['pencacah_email'] ? '' : 'disabled' ?>>
          <option value=""><?= $filters['pencacah_email'] ? 'Semua SubSLS' : 'Pilih PCL dulu' ?></option>
          <?php foreach ($opts['subsls'] as $o): ?><option value="<?= e($o['value']) ?>" <?= $filters['subsls_id']===$o['value']?'selected':'' ?>><?= e($o['label']) ?></option><?php endforeach; ?>
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
    <table class="table table-sm table-bordered table-striped mb-0 dashboard-summary-table" id="dashboardSummaryTable">
      <thead>
        <tr>
          <th>
            <div>Kelompok</div>
            <input class="form-control form-control-sm summary-search-input" type="search" placeholder="Cari kelompok" data-summary-search>
          </th>
          <?php
            $summaryHeaders = [
                ['label' => 'Target', 'class' => 'summary-head-blue'],
                ['label' => 'Progress<br>(Count)', 'class' => 'summary-head-light-green'],
                ['label' => 'Progress<br>(Persen %)', 'class' => 'summary-head-light-green'],
                ['label' => 'Approve<br>(Count)', 'class' => 'summary-head-dark-green'],
                ['label' => 'Approve<br>(Persen %)', 'class' => 'summary-head-dark-green'],
                ['label' => 'Submit<br>(Count)', 'class' => 'summary-head-light-green'],
                ['label' => 'Submit<br>(Persen %)', 'class' => 'summary-head-light-green'],
                ['label' => 'Draft<br>(Count)', 'class' => 'summary-head-yellow'],
                ['label' => 'Draft<br>(Persen %)', 'class' => 'summary-head-yellow'],
                ['label' => 'Open', 'class' => 'summary-head-blue'],
                ['label' => 'Reject', 'class' => 'summary-head-red'],
                ['label' => 'Pending', 'class' => 'summary-head-red'],
                ['label' => 'Jumlah<br>SubSLS', 'class' => 'summary-head-blue'],
            ];
          ?>
          <?php foreach ($summaryHeaders as $index => $header): ?>
            <th class="text-right <?= e($header['class']) ?>">
              <div><?= $header['label'] ?></div>
              <select class="form-control form-control-sm summary-sort-select" data-summary-sort-col="<?= $index + 1 ?>">
                <option value="">Sort</option>
                <option value="asc">Ascending</option>
                <option value="desc">Descending</option>
                <option value="clear">Clear</option>
              </select>
            </th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($chartRows as $rowIndex => $row): ?>
          <?php
            $rowTarget = (int)$row['target'];
            $submitApproveCount = dashboard_pendataan_count($row);
            $pendataanPct = $rowTarget > 0 ? $submitApproveCount / $rowTarget * 100 : 0;
            $draftPct = $rowTarget > 0 ? (int)$row['draft_count'] / $rowTarget * 100 : 0;
            $submitPct = $rowTarget > 0 ? (int)$row['submitted_by_pencacah'] / $rowTarget * 100 : 0;
            $approvePct = $rowTarget > 0 ? (int)$row['approved_by_pengawas'] / $rowTarget * 100 : 0;
            $pendataanClass = '';
            if ($samePendataanPct || ($maxPendataanPct !== null && abs($pendataanPct - $maxPendataanPct) < 0.001)) {
                $pendataanClass = ' best-progress';
            } elseif ($minPendataanPct !== null && abs($pendataanPct - $minPendataanPct) < 0.001) {
                $pendataanClass = ' low-progress';
            }
          ?>
          <tr data-original-index="<?= (int)$rowIndex ?>">
            <td><?= e($row['label']) ?></td>
            <td class="text-right" data-sort-value="<?= $rowTarget ?>"><?= number_format($rowTarget, 0, ',', '.') ?></td>
            <td class="text-right" data-sort-value="<?= $submitApproveCount ?>"><?= number_format($submitApproveCount, 0, ',', '.') ?></td>
            <td class="text-right<?= e($pendataanClass) ?>" data-sort-value="<?= e((string)$pendataanPct) ?>"><?= dashboard_table_pct_only_text($pendataanPct) ?></td>
            <td class="text-right" data-sort-value="<?= (int)$row['approved_by_pengawas'] ?>"><?= number_format((int)$row['approved_by_pengawas'], 0, ',', '.') ?></td>
            <td class="text-right" data-sort-value="<?= e((string)$approvePct) ?>"><?= dashboard_table_pct_only_text($approvePct) ?></td>
            <td class="text-right" data-sort-value="<?= (int)$row['submitted_by_pencacah'] ?>"><?= number_format((int)$row['submitted_by_pencacah'], 0, ',', '.') ?></td>
            <td class="text-right" data-sort-value="<?= e((string)$submitPct) ?>"><?= dashboard_table_pct_only_text($submitPct) ?></td>
            <td class="text-right" data-sort-value="<?= (int)$row['draft_count'] ?>"><?= number_format((int)$row['draft_count'], 0, ',', '.') ?></td>
            <td class="text-right" data-sort-value="<?= e((string)$draftPct) ?>"><?= dashboard_table_pct_only_text($draftPct) ?></td>
            <td class="text-right" data-sort-value="<?= (int)$row['open_count'] ?>"><?= number_format((int)$row['open_count'], 0, ',', '.') ?></td>
            <td class="text-right" data-sort-value="<?= (int)$row['rejected_by_pengawas'] ?>"><?= number_format((int)$row['rejected_by_pengawas'], 0, ',', '.') ?></td>
            <td class="text-right" data-sort-value="<?= (int)$row['pending_count'] ?>"><?= number_format((int)$row['pending_count'], 0, ',', '.') ?></td>
            <td class="text-right" data-sort-value="<?= (int)$row['subsls_total'] ?>"><?= number_format((int)$row['subsls_total'], 0, ',', '.') ?></td>
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
          <td class="text-right"><?= number_format($totalSubmitApprove, 0, ',', '.') ?></td>
          <td class="text-right"><?= dashboard_table_pct_only_text($totalTarget > 0 ? $totalSubmitApprove / $totalTarget * 100 : 0) ?></td>
          <td class="text-right"><?= number_format((int)$totals['approved_by_pengawas'], 0, ',', '.') ?></td>
          <td class="text-right"><?= dashboard_table_pct_only_text($totalTarget > 0 ? (int)$totals['approved_by_pengawas'] / $totalTarget * 100 : 0) ?></td>
          <td class="text-right"><?= number_format((int)$totals['submitted_by_pencacah'], 0, ',', '.') ?></td>
          <td class="text-right"><?= dashboard_table_pct_only_text($totalTarget > 0 ? (int)$totals['submitted_by_pencacah'] / $totalTarget * 100 : 0) ?></td>
          <td class="text-right"><?= number_format((int)$totals['draft_count'], 0, ',', '.') ?></td>
          <td class="text-right"><?= dashboard_table_pct_only_text($totalTarget > 0 ? (int)$totals['draft_count'] / $totalTarget * 100 : 0) ?></td>
          <td class="text-right"><?= number_format((int)$totals['open_count'], 0, ',', '.') ?></td>
          <td class="text-right"><?= number_format((int)$totals['rejected_by_pengawas'], 0, ',', '.') ?></td>
          <td class="text-right"><?= number_format((int)$totals['pending_count'], 0, ',', '.') ?></td>
          <td class="text-right"><?= number_format((int)$totals['subsls_total'], 0, ',', '.') ?></td>
        </tr>
      </tfoot>
    </table>
  </div>
  <div class="card-footer text-muted small">Progress Pendataan = submit+reject+pending+approve</div>
</div>

<script>
document.querySelectorAll('#dashboardSummaryTable').forEach(function (table) {
  const tbody = table.querySelector('tbody');
  if (!tbody) return;
  const search = table.querySelector('[data-summary-search]');
  const sortSelects = table.querySelectorAll('[data-summary-sort-col]');
  function rows() {
    return Array.from(tbody.querySelectorAll('tr')).filter(function (row) {
      return row.querySelectorAll('td').length > 1;
    });
  }
  function applySearch() {
    const term = search ? (search.value || '').trim().toLowerCase() : '';
    rows().forEach(function (row) {
      const label = row.children[0] ? row.children[0].textContent.toLowerCase() : '';
      row.style.display = term === '' || label.indexOf(term) !== -1 ? '' : 'none';
    });
  }
  if (search) {
    search.addEventListener('input', applySearch);
  }
  sortSelects.forEach(function (select) {
    select.addEventListener('change', function () {
      const direction = select.value;
      sortSelects.forEach(function (other) {
        if (other !== select) other.value = '';
      });
      const tableRows = rows();
      if (direction === '' || direction === 'clear') {
        tableRows.sort(function (a, b) {
          return Number(a.dataset.originalIndex || 0) - Number(b.dataset.originalIndex || 0);
        });
        select.value = '';
      } else {
        const col = Number(select.dataset.summarySortCol || 0);
        tableRows.sort(function (a, b) {
          const av = Number((a.children[col] && a.children[col].dataset.sortValue) || 0);
          const bv = Number((b.children[col] && b.children[col].dataset.sortValue) || 0);
          return direction === 'asc' ? av - bv : bv - av;
        });
      }
      tableRows.forEach(function (row) { tbody.appendChild(row); });
      applySearch();
    });
  });
  applySearch();
});
</script>

<script>
const rows = <?= json_encode($chartRows) ?>;
const fields = <?= json_encode(array_keys($fields)) ?>;
const labels = <?= json_encode(array_values($fields)) ?>;
const statusColors = <?= json_encode($statusColors) ?>;
const activeTab = <?= json_encode($activeTab) ?>;
const dashboardFilters = <?= json_encode($filters) ?>;
const dashboardMapPayload = <?= json_encode($dashboardMap) ?>;
const dashboardMapLegendRanges = <?= json_encode($rangeColors) ?>;
if (window.ChartDataLabels) {
  Chart.register(ChartDataLabels);
  Chart.defaults.set('plugins.datalabels', { display: false });
}
function pctColor(value) {
  if (value < 20) return '#b91c1c';
  if (value < 40) return '#f87171';
  if (value < 60) return '#d97706';
  if (value < 75) return '#facc15';
  if (value < 85) return '#22c55e';
  return '#15803d';
}
function pctLabel(value) {
  return Number(value || 0).toLocaleString('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + '%';
}
function formatCount(value) {
  return Number(value || 0).toLocaleString('id-ID');
}
function mapTooltipRow(label, value) {
  return '<div class="map-tooltip-row"><span>' + label + '</span><strong>' + value + '</strong></div>';
}
function mapTooltipBlueRow(label, value) {
  return '<div class="map-tooltip-row" style="color:#2563eb;font-weight:800"><span>' + label + '</span><strong style="color:#2563eb">' + value + '</strong></div>';
}
function mapTooltipPctValue(count, pct) {
  return formatCount(count) + ' <span style="color:' + mapProgressColor(pct) + ';font-weight:800">(' + pctLabel(pct) + ')</span>';
}
function mapProgressColor(value) {
  if (value < 20) return '#b91c1c';
  if (value < 40) return '#f87171';
  if (value < 60) return '#d97706';
  if (value < 75) return '#facc15';
  if (value < 85) return '#22c55e';
  return '#15803d';
}
function dashboardMapPropId(props, level) {
  if (level === 'kabupaten') return String(props.idkab || ((props.kdprov || '') + (props.kdkab || '')));
  if (level === 'kecamatan') return String(props.idkec || ((props.kdprov || '') + (props.kdkab || '') + (props.kdkec || '')));
  if (level === 'desa') return String(props.iddesa || ((props.kdprov || '') + (props.kdkab || '') + (props.kdkec || '') + (props.kddesa || '')));
  return String(props.idsubsls || '');
}
function dashboardMapCode(feature, level) {
  const props = feature && feature.properties ? feature.properties : {};
  return dashboardMapPropId(props, level);
}
function dashboardMapName(feature, level) {
  const props = feature && feature.properties ? feature.properties : {};
  if (level === 'kabupaten') {
    const rawCode = String(props.kdkab || props.idkab || '');
    const code = rawCode ? rawCode.slice(-2) : '';
    const name = props.nmkab || props.namadaerah || props.idkab || '-';
    return (code ? code + '-' : '') + name;
  }
  if (level === 'kecamatan') {
    const rawCode = String(props.kdkec || props.idkec || '');
    const code = rawCode ? rawCode.slice(-3) : '';
    const name = props.nmkec || props.namadaerah || props.idkec || '-';
    return (code ? code + '-' : '') + name;
  }
  if (level === 'desa') {
    const rawCode = String(props.kddesa || props.iddesa || '');
    const code = rawCode ? rawCode.slice(-3) : '';
    const name = props.nmdesa || props.namadaerah || props.iddesa || '-';
    return (code ? code + '-' : '') + name;
  }
  const slsCode = String(props.kdsls || (props.idsls ? String(props.idsls).slice(-4) : '') || '');
  const subslsCode = String(props.kdsubsls || (props.idsubsls ? String(props.idsubsls).slice(-2) : '') || '');
  const slsName = props.nmsls || '-';
  if (slsCode || subslsCode || props.nmsls) {
    return (slsCode || '-') + '-' + (subslsCode || '-') + ' ' + slsName + ' - Sub ' + (subslsCode || '-');
  }
  return String(props.idsubsls || '-');
}
function dashboardMapSubslsRtLabel(feature) {
  const props = feature && feature.properties ? feature.properties : {};
  const rtName = String(props.nmsls || '').trim();
  const rawSubslsCode = String(props.kdsubsls || props.idsubsls || '');
  const subslsCode = rawSubslsCode ? rawSubslsCode.slice(-2) : '';
  if (rtName && subslsCode) return rtName + '-' + subslsCode;
  return rtName || subslsCode || '-';
}
function dashboardMapAreaLabel(feature, level) {
  const props = feature && feature.properties ? feature.properties : {};
  if (level === 'kabupaten') return String(props.nmkab || props.namadaerah || '').trim() || '-';
  if (level === 'kecamatan') return String(props.nmkec || props.namadaerah || '').trim() || '-';
  if (level === 'desa') return String(props.nmdesa || props.namadaerah || '').trim() || '-';
  return dashboardMapSubslsRtLabel(feature);
}
function dashboardMapEscapeHtml(value) {
  return String(value || '').replace(/[&<>"']/g, function (char) {
    return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[char];
  });
}
function dashboardMapFeatureAllowed(feature, level) {
  const props = feature && feature.properties ? feature.properties : {};
  if (level === 'kecamatan' && dashboardFilters.kab_id && dashboardMapPropId(props, 'kabupaten') !== String(dashboardFilters.kab_id)) return false;
  if (level === 'desa' && dashboardFilters.kec_id && dashboardMapPropId(props, 'kecamatan') !== String(dashboardFilters.kec_id)) return false;
  if (level === 'subsls' && dashboardFilters.desa_id && dashboardMapPropId(props, 'desa') !== String(dashboardFilters.desa_id)) return false;
  if (level === 'subsls' && dashboardFilters.subsls_id && dashboardMapPropId(props, 'subsls') !== String(dashboardFilters.subsls_id)) return false;
  return true;
}
function dashboardMapUrl(level) {
  const params = new URLSearchParams();
  params.set('level', level);
  if (dashboardFilters.kab_id) params.set('kab_id', dashboardFilters.kab_id);
  if (dashboardFilters.kec_id) params.set('kec_id', dashboardFilters.kec_id);
  if (dashboardFilters.desa_id) params.set('desa_id', dashboardFilters.desa_id);
  if (dashboardFilters.subsls_id) params.set('subsls_id', dashboardFilters.subsls_id);
  return 'map_geojson.php?' + params.toString();
}
function dashboardMapDrill(feature, level) {
  const props = feature && feature.properties ? feature.properties : {};
  const params = new URLSearchParams(window.location.search);
  params.set('tab', 'submit_approve');
  if (level === 'kabupaten') {
    const code = dashboardMapPropId(props, 'kabupaten');
    if (!code) return;
    params.set('kab_id', code);
    params.delete('kec_id');
    params.delete('desa_id');
    params.delete('subsls_id');
    params.delete('pengawas_email');
    params.delete('pencacah_email');
  } else if (level === 'kecamatan') {
    const code = dashboardMapPropId(props, 'kecamatan');
    if (!code) return;
    params.set('kab_id', dashboardMapPropId(props, 'kabupaten') || code.substring(0, 4));
    params.set('kec_id', code);
    params.delete('desa_id');
    params.delete('subsls_id');
    params.delete('pengawas_email');
    params.delete('pencacah_email');
  } else if (level === 'desa') {
    const code = dashboardMapPropId(props, 'desa');
    if (!code) return;
    params.set('kab_id', dashboardMapPropId(props, 'kabupaten') || code.substring(0, 4));
    params.set('kec_id', dashboardMapPropId(props, 'kecamatan') || code.substring(0, 7));
    params.set('desa_id', code);
    params.delete('subsls_id');
    params.delete('pengawas_email');
    params.delete('pencacah_email');
  } else {
    return;
  }
  window.location.search = params.toString();
}
function initDashboardProgressMap() {
  const el = document.getElementById('dashboardProgressMap');
  if (!el || !window.L || !dashboardMapPayload || !dashboardMapPayload.level) return;
  const level = dashboardMapPayload.level;
  let dashboardMapFillOpacity = 0.65;
  const rowMap = new Map((dashboardMapPayload.rows || []).map(function (row) {
    const target = Number(row.target || 0);
    const progress = Number(row.submitted_by_pencacah || 0) + Number(row.rejected_by_pengawas || 0) + Number(row.pending_count || 0) + Number(row.approved_by_pengawas || 0);
    const approved = Number(row.approved_by_pengawas || 0);
    const draft = Number(row.draft_count || 0);
    return [String(row.code || ''), {
      label: row.label || '',
      target: target,
      progress: progress,
      pct: target ? Math.round(progress / target * 10000) / 100 : 0,
      approvedPct: target ? Math.round(approved / target * 10000) / 100 : 0,
      draftPct: target ? Math.round(draft / target * 10000) / 100 : 0,
      pengawasName: row.pengawas_name || '',
      pengawasEmail: row.pengawas_email || '',
      pencacahName: row.pencacah_name || '',
      pencacahEmail: row.pencacah_email || '',
      open: Number(row.open_count || 0),
      draft: draft,
      submitted: Number(row.submitted_by_pencacah || 0),
      rejected: Number(row.rejected_by_pengawas || 0),
      approved: approved,
      subsls: Number(row.subsls_total || 0)
    }];
  }));
  el.innerHTML = '';
  const map = L.map(el, { preferCanvas: true, zoomControl: true, attributionControl: false });
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19
  }).addTo(map);
  const legend = L.control({ position: 'bottomright' });
  legend.onAdd = function () {
    const div = L.DomUtil.create('div', 'dashboard-map-legend');
    div.innerHTML = dashboardMapLegendRanges.map(function (item) {
      return '<div class="dashboard-map-legend-row"><span class="dashboard-map-legend-swatch" style="background:' + item.color + '"></span><span>' + item.label + '</span></div>';
    }).join('');
    L.DomEvent.disableClickPropagation(div);
    L.DomEvent.disableScrollPropagation(div);
    return div;
  };
  legend.addTo(map);
  const opacityControl = L.control({ position: 'topleft' });
  opacityControl.onAdd = function () {
    const div = L.DomUtil.create('div', 'dashboard-map-opacity-control');
    div.innerHTML = '<input type="range" min="20" max="90" step="5" value="65" aria-label="Opacity peta"><button type="button" class="dashboard-map-label-toggle is-off" aria-pressed="false">Label<br>OFF</button>';
    L.DomEvent.disableClickPropagation(div);
    L.DomEvent.disableScrollPropagation(div);
    return div;
  };
  opacityControl.addTo(map);
  const emptyStyle = { color: '#94a3b8', weight: 1, fillColor: '#e5e7eb', fillOpacity: 0.55 };
  let layer = null;
  const labelLayer = L.layerGroup();
  let labelsVisible = false;
  const opacityInput = el.querySelector('.dashboard-map-opacity-control input');
  const labelToggle = el.querySelector('.dashboard-map-label-toggle');
  if (opacityInput) {
    opacityInput.addEventListener('input', function () {
      dashboardMapFillOpacity = Number(this.value || 65) / 100;
      if (!layer) return;
      layer.eachLayer(function (item) {
        const code = dashboardMapCode(item.feature, level);
        const hasData = rowMap.has(code);
        item.setStyle({ fillOpacity: hasData ? dashboardMapFillOpacity : Math.max(0.25, dashboardMapFillOpacity - 0.1) });
      });
    });
  }
  if (labelToggle) {
    labelToggle.addEventListener('click', function () {
      labelsVisible = !labelsVisible;
      this.classList.toggle('is-off', !labelsVisible);
      this.setAttribute('aria-pressed', labelsVisible ? 'true' : 'false');
      this.innerHTML = labelsVisible ? 'Label<br>ON' : 'Label<br>OFF';
      if (labelsVisible) {
        labelLayer.addTo(map);
      } else {
        map.removeLayer(labelLayer);
      }
    });
  }
  fetch(dashboardMapUrl(level))
    .then(function (response) {
      if (!response.ok) {
        return response.text().then(function (text) {
          let message = text || 'Peta tidak ditemukan';
          try {
            const parsed = JSON.parse(text);
            message = parsed.error || message;
          } catch (e) {}
          throw new Error('HTTP ' + response.status + ' - ' + message);
        });
      }
      return response.json();
    })
    .then(function (geojson) {
      layer = L.geoJSON(geojson, {
        filter: function (feature) {
          return dashboardMapFeatureAllowed(feature, level);
        },
        style: function (feature) {
          const code = dashboardMapCode(feature, level);
          const row = rowMap.get(code);
          if (!row) return emptyStyle;
          return {
            color: '#ffffff',
            fillColor: mapProgressColor(row.pct),
            fillOpacity: dashboardMapFillOpacity,
            weight: 1.2
          };
        },
        onEachFeature: function (feature, layerItem) {
          const code = dashboardMapCode(feature, level);
          const row = rowMap.get(code) || { target: 0, progress: 0, pct: 0, approvedPct: 0, draftPct: 0, open: 0, draft: 0, submitted: 0, rejected: 0, approved: 0, subsls: 0 };
          const name = dashboardMapName(feature, level);
          const tooltipHtml = '<div class="map-tooltip-title">' + name + '</div>' +
            mapTooltipRow('Target', formatCount(row.target)) +
            mapTooltipRow('Progress', mapTooltipPctValue(row.progress, row.pct)) +
            (level === 'subsls'
              ? mapTooltipRow('PML', row.pengawasName || row.pengawasEmail || '-') +
                mapTooltipBlueRow('PCL', row.pencacahName || row.pencacahEmail || '-')
              : '') +
            '<hr>' +
            mapTooltipRow('Open', formatCount(row.open)) +
            mapTooltipRow('Draft', formatCount(row.draft) + ' (' + pctLabel(row.draftPct) + ')') +
            mapTooltipRow('Submitted', formatCount(row.submitted)) +
            mapTooltipRow('Rejected', formatCount(row.rejected)) +
            mapTooltipRow('Approved', mapTooltipPctValue(row.approved, row.approvedPct));
          layerItem.bindTooltip(tooltipHtml, { className: 'dashboard-map-tooltip', sticky: true });
          layerItem.on({
            mouseover: function () {
              layerItem.setStyle({ weight: 2.4, color: '#111827', fillOpacity: 0.82 });
              layerItem.bringToFront();
            },
            mouseout: function () {
              layer.resetStyle(layerItem);
              const code = dashboardMapCode(feature, level);
              layerItem.setStyle({ fillOpacity: rowMap.has(code) ? dashboardMapFillOpacity : Math.max(0.25, dashboardMapFillOpacity - 0.1) });
            },
            click: function () {
              dashboardMapDrill(feature, level);
            }
          });
        }
      }).addTo(map);
      layer.eachLayer(function (item) {
        if (!item.getBounds || !item.feature) return;
        const label = dashboardMapAreaLabel(item.feature, level);
        if (!label || label === '-') return;
        const center = item.getBounds().getCenter();
        L.marker(center, {
          interactive: false,
          keyboard: false,
          icon: L.divIcon({
            className: 'dashboard-map-area-label',
            html: dashboardMapEscapeHtml(label),
            iconSize: null
          })
        }).addTo(labelLayer);
      });
      const bounds = layer.getBounds();
      if (bounds.isValid()) {
        map.fitBounds(bounds, { padding: [12, 12] });
      } else {
        map.setView([0, 117], 6);
      }
    })
    .catch(function (error) {
      el.innerHTML = '<div class="dashboard-map-loading">Gagal memuat peta: ' + error.message + '</div>';
    });
}
initDashboardProgressMap();
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
    const kec = document.getElementById('kec_id');
    const desa = document.getElementById('desa_id');
    const pengawas = document.getElementById('pengawas_email');
    const pencacah = document.getElementById('pencacah_email');
    const subsls = document.getElementById('subsls_id');
    if (kec) kec.value = '';
    if (desa) desa.value = '';
    if (pengawas) pengawas.value = '';
    if (pencacah) pencacah.value = '';
    if (subsls) subsls.value = '';
    this.form.submit();
  });
}
const kecamatan = document.getElementById('kec_id');
if (kecamatan) {
  kecamatan.addEventListener('change', function () {
    const desa = document.getElementById('desa_id');
    const pengawas = document.getElementById('pengawas_email');
    const pencacah = document.getElementById('pencacah_email');
    const subsls = document.getElementById('subsls_id');
    if (desa) desa.value = '';
    if (pengawas) pengawas.value = '';
    if (pencacah) pencacah.value = '';
    if (subsls) subsls.value = '';
    this.form.submit();
  });
}
const desa = document.getElementById('desa_id');
if (desa) {
desa.addEventListener('change', function () {
    const pengawas = document.getElementById('pengawas_email');
    const pencacah = document.getElementById('pencacah_email');
    const subsls = document.getElementById('subsls_id');
    if (pengawas) pengawas.value = '';
    if (pencacah) pencacah.value = '';
    if (subsls) subsls.value = '';
    this.form.submit();
  });
}
const pengawas = document.getElementById('pengawas_email');
if (pengawas) {
  pengawas.addEventListener('change', function () {
    const pencacah = document.getElementById('pencacah_email');
    const subsls = document.getElementById('subsls_id');
    if (pencacah) pencacah.value = '';
    if (subsls) subsls.value = '';
    this.form.submit();
  });
}
const pencacah = document.getElementById('pencacah_email');
if (pencacah) {
  pencacah.addEventListener('change', function () {
    const subsls = document.getElementById('subsls_id');
    if (subsls) subsls.value = '';
    this.form.submit();
  });
}
const subsls = document.getElementById('subsls_id');
if (subsls) {
  subsls.addEventListener('change', function () {
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
          $relatedEmails = dashboard_performance_related_emails($user, $attentionType, (string)$kab['value']);
          $topRows = dashboard_performance_visible_rows($performanceMetricData['overall'][$kab['value']] ?? [], $relatedEmails, 10);
          $weeklyRows = dashboard_performance_visible_rows($performanceMetricData['weekly'][$kab['value']] ?? [], $relatedEmails, 10);
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
                  <th class="text-right">Progress<br>Count</th>
                  <th class="text-right">Progress<br>(%)</th>
                  <th class="performance-compact-header">Rata-rata/<br>Hari<br>(Assignment)</th>
                  <th class="performance-compact-header">Capaian<br>Hari Ini<br>dibanding<br>Kemarin<br>(Assignment)</th>
                  <th>Konsistensi (%)</th>
                  <th>Prediksi Selesai</th>
                  <th>Status</th>
                  <th>Skor</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($topRows as $r): ?>
                <tr class="<?= !empty($r['_highlight']) ? 'performance-related-row' : '' ?>">
                  <td><?= dashboard_rank_badge((int)$r['_rank']) ?></td>
                  <td><?= performance_petugas_html((string)$r['email'], (string)($r['petugas_name'] ?? '')) ?></td>
                  <td><?= e($r['kab_codes'] ?: '-') ?></td>
                  <td class="performance-work-area"><?= e($r['wilayah_kerja_kecamatan'] ?: '-') ?></td>
                  <td class="performance-work-area"><?= performance_work_area_html((string)($r['wilayah_kerja'] ?? '')) ?></td>
                  <td class="text-right"><?= number_format((int)$r['target'],0,',','.') ?></td>
                  <td class="text-right"><?= number_format((int)$r['progress_count'],0,',','.') ?></td>
                  <td class="text-right"><?= number_format((int)$r['target'] > 0 ? (int)$r['progress_count'] / (int)$r['target'] * 100 : 0,2,',','.') ?></td>
                  <td class="text-right"><?= number_format((int)ceil((float)$r['average_per_day']),0,',','.') ?></td>
                  <td class="text-right"><?= number_format((int)$r['yesterday_achievement'],0,',','.') ?></td>
                  <td class="text-right"><?= number_format((float)$r['consistency_score'],2,',','.') ?></td>
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
                  <th>Konsistensi (%)</th>
                  <th>Kemampuan Mengejar (%)</th>
                  <th>Skor</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($weeklyRows as $r): ?>
                <tr class="<?= !empty($r['_highlight']) ? 'performance-related-row' : '' ?>">
                  <td><?= dashboard_rank_badge((int)$r['_rank']) ?></td>
                  <td><?= performance_petugas_html((string)$r['email'], (string)($r['petugas_name'] ?? '')) ?></td>
                  <td><?= e($r['kab_codes'] ?: '-') ?></td>
                  <td class="performance-work-area"><?= e($r['wilayah_kerja_kecamatan'] ?: '-') ?></td>
                  <td class="performance-work-area"><?= performance_work_area_html((string)($r['wilayah_kerja'] ?? '')) ?></td>
                  <td class="text-right"><?= number_format((int)$r['target'],0,',','.') ?></td>
                  <td class="text-right"><?= number_format((int)$r['progress_before'],0,',','.') ?></td>
                  <td class="text-right"><?= number_format((int)$r['weekly_count'],0,',','.') ?></td>
                  <td class="text-right"><?= number_format((float)$r['weekly_target'],2,',','.') ?></td>
                  <td class="text-right"><?= number_format((int)ceil((float)$r['average_per_day']),0,',','.') ?></td>
                  <td class="text-right"><?= number_format((float)$r['consistency_score'],2,',','.') ?></td>
                  <td class="text-right"><?= number_format((float)$r['momentum_score'],2,',','.') ?></td>
                  <td class="text-right font-weight-bold"><?= number_format((float)$r['performance_score'],2,',','.') ?></td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$weeklyRows): ?>
                <tr><td colspan="13" class="text-center text-muted"><?= $weeklyPeriod ? 'Belum ada data petugas aktif pada periode ini.' : 'Performa mingguan akan tampil setelah minggu pertama selesai.' ?></td></tr>
              <?php endif; ?>
              </tbody>
            </table>
          </div>
          <?php if (in_array($user['role'], ['superadmin', 'admin_kab', 'viewer_prov', 'viewer_kab'], true)): ?>
          <div class="d-flex flex-wrap justify-content-between align-items-center mb-2">
            <div>
              <h5 class="mb-1"><?= e($labelRole) ?> Perlu Perhatian</h5>
              <div class="text-muted small">Rule aktif: sampai <?= e(date('d/m/Y', strtotime($attentionThreshold['date']))) ?>, yang selesai SubSLS masih di bawah <?= e($attentionThreshold['pct']) ?>% masuk tabel ini.</div>
            </div>
            <div class="mt-2 mt-md-0">
              <?php
                $attentionExportQuery = [
                    'action' => 'export_attention',
                    'type' => $attentionType,
                    'kab_id' => $kab['value'],
                ];
              ?>
              <a class="btn btn-outline-success btn-sm mr-2" href="?<?= e(http_build_query($attentionExportQuery + ['format' => 'csv'])) ?>"><i class="fas fa-file-csv mr-1"></i>Export CSV</a>
              <a class="btn btn-outline-success btn-sm" href="?<?= e(http_build_query($attentionExportQuery + ['format' => 'xlsx'])) ?>"><i class="fas fa-file-excel mr-1"></i>Export Excel</a>
            </div>
          </div>
          <div class="table-responsive">
            <table class="table table-sm table-bordered table-striped mb-0 attention-table" data-page-size="25">
              <thead>
                <tr>
                  <th>
                    <div>Nama (email)</div>
                  </th>
                  <th>Kode Kab</th>
                  <th>
                    <div>Kecamatan</div>
                  </th>
                  <th>
                    <div>Wilayah Kerja</div>
                  </th>
                  <?php
                    $attentionHeaders = [
                        ['label' => 'Draft<br>(Count)', 'class' => 'summary-head-yellow'],
                        ['label' => 'Draft<br>(%)', 'class' => 'summary-head-yellow'],
                        ['label' => 'Progress Pendataan<br>Count', 'class' => 'summary-head-light-green'],
                        ['label' => 'Progress Pendataan<br>(%)', 'class' => 'summary-head-light-green'],
                        ['label' => 'Selesai SubSLS', 'class' => 'summary-head-dark-green'],
                        ['label' => 'Target', 'class' => 'summary-head-blue'],
                        ['label' => 'Total SubSLS', 'class' => 'summary-head-blue'],
                    ];
                  ?>
                  <?php foreach ($attentionHeaders as $hIndex => $header): ?>
                    <th class="text-right <?= e($header['class']) ?>">
                      <div><?= $header['label'] ?></div>
                    </th>
                  <?php endforeach; ?>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($attentionRows as $attentionIndex => $r): ?>
                <?php
                  $attentionTarget = (int)$r['target'];
                  $attentionDraft = (int)$r['draft_count'];
                  $attentionProgress = dashboard_pendataan_count($r);
                  $attentionDraftPct = $attentionTarget > 0 ? $attentionDraft / $attentionTarget * 100 : 0;
                  $attentionProgressPct = $attentionTarget > 0 ? $attentionProgress / $attentionTarget * 100 : 0;
                ?>
                <tr class="attention-row" data-original-index="<?= (int)$attentionIndex ?>">
                  <td><?= e(petugas_label($r['email'], $r['petugas_name'] ?? '')) ?></td>
                  <td><?= e($r['kab_codes'] ?: '-') ?></td>
                  <td class="performance-work-area"><?= e($r['wilayah_kerja_kecamatan'] ?: '-') ?></td>
                  <td class="performance-work-area"><?= e($r['wilayah_kerja'] ?: '-') ?></td>
                  <td class="text-right" data-sort-value="<?= $attentionDraft ?>"><?= number_format($attentionDraft,0,',','.') ?></td>
                  <td class="text-right" data-sort-value="<?= e((string)$attentionDraftPct) ?>"><?= number_format($attentionDraftPct,2,',','.') ?></td>
                  <td class="text-right" data-sort-value="<?= $attentionProgress ?>"><?= number_format($attentionProgress,0,',','.') ?></td>
                  <td class="text-right" data-sort-value="<?= e((string)$attentionProgressPct) ?>"><?= number_format($attentionProgressPct,2,',','.') ?></td>
                  <td class="text-right" data-sort-value="<?= e((string)(float)$r['selesai_pct']) ?>"><?= number_format((float)$r['selesai_pct'],2,',','.') ?>%</td>
                  <td class="text-right" data-sort-value="<?= (int)$r['target'] ?>"><?= number_format((int)$r['target'],0,',','.') ?></td>
                  <td class="text-right" data-sort-value="<?= (int)$r['subsls_total'] ?>"><?= number_format((int)$r['subsls_total'],0,',','.') ?></td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$attentionRows): ?>
                <tr><td colspan="11" class="text-center text-muted">Tidak ada <?= e(strtolower($labelRole)) ?> yang masuk kategori perlu perhatian.</td></tr>
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
  const tbody = table.querySelector('tbody');
  const rows = Array.from(table.querySelectorAll('tbody tr.attention-row'));
  const pageSize = Number(table.getAttribute('data-page-size') || 25);

  let page = 1;
  const pane = table.closest('.tab-pane');
  const pager = pane ? pane.querySelector('.attention-pagination') : null;
  const prev = pager ? pager.querySelector('.attention-prev') : null;
  const next = pager ? pager.querySelector('.attention-next') : null;
  const info = pager ? pager.querySelector('.attention-info') : null;

  function filteredRows() {
    return rows;
  }

  function render() {
    const visibleRows = filteredRows();
    const totalPages = Math.max(1, Math.ceil(visibleRows.length / pageSize));
    page = Math.min(Math.max(1, page), totalPages);
    rows.forEach(function (row) {
      row.style.display = 'none';
    });
    visibleRows.forEach(function (row, index) {
      row.style.display = index >= (page - 1) * pageSize && index < page * pageSize ? '' : 'none';
    });
    if (info) info.textContent = visibleRows.length ? 'Halaman ' + page + ' dari ' + totalPages + ' (' + visibleRows.length + ' row)' : 'Tidak ada data';
    if (prev) prev.disabled = page <= 1;
    if (next) next.disabled = page >= totalPages;
  }

  if (prev) prev.addEventListener('click', function () { if (page > 1) { page--; render(); } });
  if (next) next.addEventListener('click', function () { page++; render(); });
  render();
});
</script>

<?php render_footer(); ?>
