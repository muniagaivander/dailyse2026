<?php
require __DIR__ . '/layout.php';
$user = require_login();
ensure_completion_status_table();

$fields = status_fields();
$statusColors = ['#2563eb', '#16a34a', '#dc2626', '#f59e0b', '#0f766e'];
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
        $stmt = db()->prepare("SELECT DISTINCT ms.pengawas_email value, ms.pengawas_email label
            FROM master_subsls ms
            JOIN master_sls sl ON sl.id=ms.sls_id
            WHERE sl.desa_id=? AND ms.pengawas_email IS NOT NULL AND ms.pengawas_email <> ''
            ORDER BY ms.pengawas_email");
        $stmt->execute([$filters['desa_id']]);
        $out['pengawas'] = $stmt->fetchAll();
    }

    if ($user['role'] === 'pengawas') {
        $stmt = db()->prepare("SELECT DISTINCT pencacah_email value, pencacah_email label
            FROM master_subsls
            WHERE pengawas_email=? AND pencacah_email IS NOT NULL AND pencacah_email <> ''
            ORDER BY pencacah_email");
        $stmt->execute([$user['email']]);
        $out['pencacah'] = $stmt->fetchAll();
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
        $stmt = db()->prepare("SELECT DISTINCT ms.pencacah_email value, ms.pencacah_email label
            FROM master_subsls ms
            JOIN master_sls sl ON sl.id=ms.sls_id
            JOIN master_desa d ON d.id=sl.desa_id
            JOIN master_kec kc ON kc.id=d.kec_id
            JOIN master_kab k ON k.id=kc.kab_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY ms.pencacah_email");
        $stmt->execute($params);
        $out['pencacah'] = $stmt->fetchAll();
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
    return $stmt->fetchAll();
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
        + (int)($row['draft_count'] ?? 0)
        + (int)($row['approved_by_pengawas'] ?? 0);
}

function dashboard_datetime_label(?string $datetime): string
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
    $time = strtotime($datetime);
    if (!$time) {
        return '-';
    }
    return date('d', $time) . ' ' . $months[date('m', $time)] . ' ' . date('Y H:i', $time) . ' WITA';
}

function dashboard_latest_daily_status_label(): string
{
    $stmt = db()->query("SELECT MAX(updated_at) FROM daily_status");
    return dashboard_datetime_label($stmt->fetchColumn() ?: null);
}

function dashboard_rank_badge(int $rank): string
{
    return match ($rank) {
        1 => '<span class="rank-badge rank-1"><i class="fas fa-trophy mr-1"></i>Peringkat 1</span>',
        2 => '<span class="rank-badge rank-2"><i class="fas fa-medal mr-1"></i>Peringkat 2</span>',
        3 => '<span class="rank-badge rank-3"><i class="fas fa-award mr-1"></i>Peringkat 3</span>',
        default => '<span class="rank-badge">#' . $rank . '</span>',
    };
}

function performance_rows(string $roleField, string $kabId, string $direction): array
{
    $order = $direction === 'desc' ? 'DESC' : 'ASC';
    $limit = $direction === 'desc' ? 'LIMIT 10' : '';
    $whereKab = $kabId === '6400' ? '' : 'kc.kab_id=? AND';
    $stmt = db()->prepare("SELECT ms.$roleField email,
            COALESCE(SUM(ss.target),0) target,
            COALESCE(SUM(ss.submitted_by_pencacah),0) submitted_by_pencacah,
            COALESCE(SUM(ss.rejected_by_pengawas),0) rejected_by_pengawas,
            COALESCE(SUM(ss.draft_count),0) draft_count,
            COALESCE(SUM(ss.approved_by_pengawas),0) approved_by_pengawas,
            COUNT(ms.id) subsls_total,
            COALESCE(SUM(CASE WHEN cs.status_selesai='Selesai' THEN 1 ELSE 0 END),0) selesai_count,
            CASE WHEN COALESCE(SUM(ss.target),0)>0
                THEN ROUND((COALESCE(SUM(ss.submitted_by_pencacah),0)+COALESCE(SUM(ss.rejected_by_pengawas),0)+COALESCE(SUM(ss.draft_count),0)+COALESCE(SUM(ss.approved_by_pengawas),0))/COALESCE(SUM(ss.target),0)*100,2)
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
        WHERE $whereKab ms.$roleField IS NOT NULL AND ms.$roleField <> ''
        GROUP BY ms.$roleField
        ORDER BY submit_approve_pct $order, selesai_pct $order, email ASC
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
        $headers = ['label', 'target', 'open', 'submit', 'reject', 'pending', 'approved', 'open_pct', 'submit_pct', 'reject_pct', 'pending_pct', 'approved_pct'];
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
            $pending = (float)($row['draft_count'] ?? 0);
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

if (($_GET['action'] ?? '') === 'export_attention' && $canSeePerformance) {
    $kabId = (string)($_GET['kab_id'] ?? '');
    $type = ($_GET['type'] ?? '') === 'pencacah' ? 'pencacah' : 'pengawas';
    $format = ($_GET['format'] ?? 'csv') === 'xlsx' ? 'xlsx' : 'csv';
    if (!$kabId || !dashboard_can_access_kab($user, $kabId)) {
        http_response_code(403);
        exit('Akses ditolak');
    }
    $threshold = performance_attention_threshold();
    $roleField = $type === 'pencacah' ? 'pencacah_email' : 'pengawas_email';
    $rows = performance_attention_rows($roleField, $kabId, (float)$threshold['pct']);
    $exportRows = [];
    foreach ($rows as $row) {
        $exportRows[] = [
            $row['email'],
            $row['submit_approve_pct'],
            $row['selesai_pct'],
            $threshold['pct'],
            $threshold['date'],
            $row['target'],
            $row['submitted_by_pencacah'],
            $row['rejected_by_pengawas'],
            $row['draft_count'],
            $row['approved_by_pengawas'],
            $row['subsls_total'],
            $row['selesai_count'],
        ];
    }
    dashboard_export_rows(
        ['email', 'progress_pendataan_pct', 'selesai_subsls_pct', 'threshold_selesai_pct', 'batas_tanggal', 'target', 'submitted_by_pencacah', 'rejected_by_pengawas', 'draft_count', 'approved_by_pengawas', 'subsls_total', 'selesai_count'],
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
$latestDailyStatusLabel = dashboard_latest_daily_status_label();
$completionPct = $totals['subsls_total'] > 0 ? round($totals['selesai_count'] / $totals['subsls_total'] * 100, 2) : 0;
$submitApproveCount = dashboard_pendataan_count($totals);
$submitApprovePct = $totals['target'] > 0 ? round($submitApproveCount / (int)$totals['target'] * 100, 2) : 0;
$performanceKabOptions = $canSeePerformance ? dashboard_kab_options_for_performance($user) : [];

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
.dashboard-table-pct {
  color: #2563eb;
  font-weight: 700;
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
      ['label' => 'Pending', 'value' => dashboard_count_pct_text((int)$totals['draft_count'], $targetTotal ? (int)$totals['draft_count'] / $targetTotal * 100 : 0)],
      ['label' => 'Approve', 'value' => dashboard_count_pct_text((int)$totals['approved_by_pengawas'], $targetTotal ? (int)$totals['approved_by_pengawas'] / $targetTotal * 100 : 0)],
      ['label' => 'Progress Pendataan', 'value' => dashboard_count_pct_text($submitApproveCount, $submitApprovePct)],
      ['label' => 'Selesai', 'value' => dashboard_count_pct_text((int)$totals['selesai_count'], $completionPct)],
      ['label' => 'Total SubSLS', 'value' => dashboard_count_only_text((int)$totals['subsls_total'])],
  ];
?>
<div class="row">
  <?php foreach ($dashboardCards as $card): ?>
    <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6">
      <div class="small-box bg-light">
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
            <td class="text-right"><?= dashboard_table_count_pct_text((int)$row['submitted_by_pencacah'], $rowTarget) ?></td>
            <td class="text-right"><?= number_format((int)$row['rejected_by_pengawas'], 0, ',', '.') ?></td>
            <td class="text-right"><?= number_format((int)$row['draft_count'], 0, ',', '.') ?></td>
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
          <td class="text-right"><?= dashboard_table_count_pct_text((int)$totals['submitted_by_pencacah'], $totalTarget) ?></td>
          <td class="text-right"><?= number_format((int)$totals['rejected_by_pengawas'], 0, ',', '.') ?></td>
          <td class="text-right"><?= number_format((int)$totals['draft_count'], 0, ',', '.') ?></td>
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
function pctColor(value) {
  if (value < 20) return '#dc2626';
  if (value < 40) return '#f59e0b';
  if (value < 75) return '#2563eb';
  return '#16a34a';
}
const percentRows = rows.map(r => {
  const target = Number(r.target || 0);
  const submitApprove = target ? Math.round((Number(r.submitted_by_pencacah || 0) + Number(r.rejected_by_pengawas || 0) + Number(r.draft_count || 0) + Number(r.approved_by_pengawas || 0)) / target * 10000) / 100 : 0;
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
      options: { animation:false, maintainAspectRatio:false, responsive:true, scales:{ y:{min:0,max:100,ticks:{callback:v=>v+'%'}} } }
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
<?php $roleField = $activeTab === 'performa_pengawas' ? 'pengawas_email' : 'pencacah_email'; $labelRole = $activeTab === 'performa_pengawas' ? 'Pengawas' : 'Pencacah'; ?>
<?php $attentionThreshold = performance_attention_threshold(); $attentionType = $activeTab === 'performa_pengawas' ? 'pengawas' : 'pencacah'; ?>
<div class="card card-body py-2 mb-3">
  <div><strong><em>Progress Pendataan = Submit+Reject+Pending+Approve</em></strong></div>
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
        <?php $topRows = performance_rows($roleField, $kab['value'], 'desc'); $attentionRows = performance_attention_rows($roleField, $kab['value'], (float)$attentionThreshold['pct']); ?>
        <div class="tab-pane fade <?= $i===0?'show active':'' ?>" id="kab-<?= e($kab['value']) ?>" role="tabpanel">
          <h5>10 <?= e($labelRole) ?> Terbaik</h5>
          <div class="table-responsive mb-4">
            <table class="table table-sm table-bordered table-striped mb-0">
              <thead><tr><th>Peringkat</th><th>Email</th><th>Progress Pendataan</th><th>Selesai SubSLS</th><th>Target</th><th>Total SubSLS</th></tr></thead>
              <tbody>
              <?php foreach ($topRows as $rankIndex => $r): ?>
                <tr><td><?= dashboard_rank_badge($rankIndex + 1) ?></td><td><?= e($r['email']) ?></td><td><?= number_format((float)$r['submit_approve_pct'],2,',','.') ?>%</td><td><?= number_format((float)$r['selesai_pct'],2,',','.') ?>%</td><td><?= number_format((int)$r['target'],0,',','.') ?></td><td><?= number_format((int)$r['subsls_total'],0,',','.') ?></td></tr>
              <?php endforeach; ?>
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
              <thead><tr><th>Email</th><th>Progress Pendataan</th><th>Selesai SubSLS</th><th>Target</th><th>Total SubSLS</th></tr></thead>
              <tbody>
              <?php foreach ($attentionRows as $r): ?>
                <tr class="attention-row"><td><?= e($r['email']) ?></td><td><?= number_format((float)$r['submit_approve_pct'],2,',','.') ?>%</td><td><?= number_format((float)$r['selesai_pct'],2,',','.') ?>%</td><td><?= number_format((int)$r['target'],0,',','.') ?></td><td><?= number_format((int)$r['subsls_total'],0,',','.') ?></td></tr>
              <?php endforeach; ?>
              <?php if (!$attentionRows): ?>
                <tr><td colspan="5" class="text-center text-muted">Tidak ada <?= e(strtolower($labelRole)) ?> yang masuk kategori perlu perhatian.</td></tr>
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
