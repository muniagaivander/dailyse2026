<?php
require __DIR__ . '/layout.php';

$user = require_role(['admin_kab','superadmin','viewer_prov','viewer_kab']);
$defaultWeekStart = '2026-06-15';
$monthOptions = [
    '2026-06' => 'Juni',
    '2026-07' => 'Juli',
    '2026-08' => 'Agustus',
    '2026-09' => 'September',
];
$filters = [
    'period_mode' => $_GET['period_mode'] ?? 'daily',
    'date_start' => $_GET['date_start'] ?? date('Y-m-d', strtotime('-7 days')),
    'date_end' => $_GET['date_end'] ?? date('Y-m-d'),
    'week_start' => $_GET['week_start'] ?? '1',
    'week_end' => $_GET['week_end'] ?? '',
    'month_start' => $_GET['month_start'] ?? '2026-06',
    'month_end' => $_GET['month_end'] ?? '2026-08',
    'kab_id' => $_GET['kab_id'] ?? '',
    'kec_id' => $_GET['kec_id'] ?? '',
    'desa_id' => $_GET['desa_id'] ?? '',
];
if (!in_array($filters['period_mode'], ['daily', 'weekly', 'monthly'], true)) {
    $filters['period_mode'] = 'daily';
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['date_start'])) {
    $filters['date_start'] = date('Y-m-d', strtotime('-7 days'));
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['date_end'])) {
    $filters['date_end'] = date('Y-m-d');
}
if ($filters['date_start'] > $filters['date_end']) {
    [$filters['date_start'], $filters['date_end']] = [$filters['date_end'], $filters['date_start']];
}
if (in_array($user['role'], ['admin_kab', 'viewer_kab'], true)) {
    $filters['kab_id'] = $user['kab_id'];
}

function progress_area_date_label(string $date): string
{
    $months = [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    $ts = strtotime($date);
    return (int)date('j', $ts) . ' ' . $months[(int)date('n', $ts)];
}

function progress_area_week_options(string $startDate): array
{
    $options = [];
    $endLimit = strtotime(date('Y-m-d'));
    $start = strtotime($startDate);
    $week = 1;
    for ($cursor = $start; $cursor <= $endLimit; $cursor = strtotime('+7 days', $cursor), $week++) {
        $end = strtotime('+6 days', $cursor);
        $options[(string)$week] = 'Minggu-' . $week . ' (' . progress_area_date_label(date('Y-m-d', $cursor)) . '-' . progress_area_date_label(date('Y-m-d', $end)) . ')';
    }
    return $options;
}

function progress_area_current_week(string $startDate): string
{
    $diff = max(0, (int)floor((strtotime(date('Y-m-d')) - strtotime($startDate)) / 86400));
    return (string)((int)floor($diff / 7) + 1);
}

function progress_area_week_footnote(string $weekNumber, string $startDate): string
{
    $week = max(1, (int)$weekNumber);
    $start = date('Y-m-d', strtotime('+' . (($week - 1) * 7) . ' days', strtotime($startDate)));
    $end = date('Y-m-d', strtotime('+6 days', strtotime($start)));
    return 'Minggu-' . $week . ' = Tanggal ' . progress_area_date_label($start) . ' - Tanggal ' . progress_area_date_label($end);
}

$weekOptions = progress_area_week_options($defaultWeekStart);
if ($filters['week_end'] === '') {
    $filters['week_end'] = progress_area_current_week($defaultWeekStart);
}
if (!isset($weekOptions[$filters['week_start']])) {
    $filters['week_start'] = '1';
}
if (!isset($weekOptions[$filters['week_end']])) {
    $filters['week_end'] = array_key_last($weekOptions);
}
if ((int)$filters['week_start'] > (int)$filters['week_end']) {
    [$filters['week_start'], $filters['week_end']] = [$filters['week_end'], $filters['week_start']];
}
if (!isset($monthOptions[$filters['month_start']])) {
    $filters['month_start'] = '2026-06';
}
if (!isset($monthOptions[$filters['month_end']])) {
    $filters['month_end'] = '2026-08';
}
if ($filters['month_start'] > $filters['month_end']) {
    [$filters['month_start'], $filters['month_end']] = [$filters['month_end'], $filters['month_start']];
}

function progress_area_period_bounds(array $filters, string $weekBase): array
{
    if ($filters['period_mode'] === 'weekly') {
        $start = date('Y-m-d', strtotime('+' . (((int)$filters['week_start'] - 1) * 7) . ' days', strtotime($weekBase)));
        $end = date('Y-m-d', strtotime('+' . ((((int)$filters['week_end'] - 1) * 7) + 6) . ' days', strtotime($weekBase)));
        return [$start, $end];
    }
    if ($filters['period_mode'] === 'monthly') {
        $start = $filters['month_start'] . '-01';
        $end = date('Y-m-t', strtotime($filters['month_end'] . '-01'));
        return [$start, $end];
    }
    return [$filters['date_start'], $filters['date_end']];
}

function progress_area_period_select(array $filters, string $weekBase): array
{
    if ($filters['period_mode'] === 'weekly') {
        return [
            "FLOOR(DATEDIFF(ds.tanggal, '{$weekBase}') / 7) + 1",
            "CONCAT('Minggu-', FLOOR(DATEDIFF(ds.tanggal, '{$weekBase}') / 7) + 1)",
            "FLOOR(DATEDIFF(ds.tanggal, '{$weekBase}') / 7) + 1",
        ];
    }
    if ($filters['period_mode'] === 'monthly') {
        return [
            "DATE_FORMAT(ds.tanggal, '%Y-%m')",
            "CASE DATE_FORMAT(ds.tanggal, '%Y-%m') WHEN '2026-06' THEN 'Juni' WHEN '2026-07' THEN 'Juli' WHEN '2026-08' THEN 'Agustus' WHEN '2026-09' THEN 'September' ELSE DATE_FORMAT(ds.tanggal, '%Y-%m') END",
            "DATE_FORMAT(ds.tanggal, '%Y-%m')",
        ];
    }
    return ['ds.tanggal', 'ds.tanggal', 'ds.tanggal'];
}

function progress_area_kabupaten_options(array $user): array
{
    if (in_array($user['role'], ['admin_kab', 'viewer_kab'], true)) {
        $stmt = db()->prepare("SELECT id value, CONCAT(id,' - ',nmkab) label FROM master_kab WHERE id=? ORDER BY id");
        $stmt->execute([$user['kab_id']]);
        return $stmt->fetchAll();
    }
    return db()->query("SELECT id value, CONCAT(id,' - ',nmkab) label FROM master_kab ORDER BY id")->fetchAll();
}

function progress_area_filter_options(array $user, array $filters): array
{
    $out = ['kabupaten' => progress_area_kabupaten_options($user), 'kecamatan' => [], 'desa' => []];
    if (empty($filters['kab_id'])) {
        return $out;
    }

    $stmt = db()->prepare("SELECT id value, CONCAT(kdkec,' - ',nmkec) label FROM master_kec WHERE kab_id=? ORDER BY kdkec, nmkec");
    $stmt->execute([$filters['kab_id']]);
    $out['kecamatan'] = $stmt->fetchAll();

    if (!empty($filters['kec_id'])) {
        $stmt = db()->prepare("SELECT id value, CONCAT(kddesa,' - ',nmdesa) label FROM master_desa WHERE kec_id=? ORDER BY kddesa, nmdesa");
        $stmt->execute([$filters['kec_id']]);
        $out['desa'] = $stmt->fetchAll();
    }

    return $out;
}

function progress_area_grouping(array $user, array $filters): array
{
    if (!empty($filters['desa_id'])) {
        return [
            'ms.id',
            "CONCAT(k.id,kc.kdkec,d.kddesa,sl.kdsls,ms.kdsubsls)",
            "CONCAT(sl.nmsls,' - ',ms.kdsubsls)",
            "CONCAT(sl.nmsls,' - ',ms.kdsubsls)",
            'sl.kdsls, ms.kdsubsls, ms.id',
            'SubSLS',
        ];
    }
    if (!empty($filters['kec_id'])) {
        return [
            'd.id',
            "CONCAT(k.id,kc.kdkec,d.kddesa)",
            'd.nmdesa',
            "CONCAT(d.kddesa,' - ',d.nmdesa)",
            'd.kddesa, d.nmdesa',
            'Desa',
        ];
    }
    if (!empty($filters['kab_id']) || in_array($user['role'], ['admin_kab', 'viewer_kab'], true)) {
        return [
            'kc.id',
            "CONCAT(k.id,kc.kdkec)",
            'kc.nmkec',
            "CONCAT(kc.kdkec,' - ',kc.nmkec)",
            'kc.kdkec, kc.nmkec',
            'Kecamatan',
        ];
    }
    return [
        'k.id',
        'k.id',
        'k.nmkab',
        "CONCAT(k.id,' - ',k.nmkab)",
        'k.id',
        'Kabupaten',
    ];
}

function progress_area_where(array $user, array $filters): array
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
    [$dateStart, $dateEnd] = progress_area_period_bounds($filters, '2026-06-15');
    $where[] = 'ds.tanggal BETWEEN ? AND ?';
    $params[] = $dateStart;
    $params[] = $dateEnd;
    foreach (['kec_id' => 'kc.id', 'desa_id' => 'd.id'] as $key => $col) {
        if (!empty($filters[$key])) {
            $where[] = "{$col}=?";
            $params[] = $filters[$key];
        }
    }
    return [$where, $params];
}

function progress_area_current_cards(array $user, array $filters): array
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
    foreach (['kec_id' => 'kc.id', 'desa_id' => 'd.id'] as $key => $col) {
        if (!empty($filters[$key])) {
            $where[] = "{$col}=?";
            $params[] = $filters[$key];
        }
    }
    $sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $stmt = db()->prepare("SELECT COALESCE(SUM(ss.target),0) target,
            COALESCE(SUM(ss.open_count),0) open_count,
            COALESCE(SUM(ss.draft_count),0) draft_count,
            COALESCE(SUM(ss.submitted_by_pencacah),0) submitted_by_pencacah,
            COALESCE(SUM(ss.approved_by_pengawas),0) approved_by_pengawas,
            COALESCE(SUM(ss.rejected_by_pengawas),0) rejected_by_pengawas,
            COALESCE(SUM(ss.pending_count),0) pending_count
        FROM master_subsls ms
        JOIN master_sls sl ON sl.id=ms.sls_id
        JOIN master_desa d ON d.id=sl.desa_id
        JOIN master_kec kc ON kc.id=d.kec_id
        JOIN master_kab k ON k.id=kc.kab_id
        LEFT JOIN subsls_status ss ON ss.subsls_id=ms.id
        {$sqlWhere}");
    $stmt->execute($params);
    $row = $stmt->fetch() ?: [];
    $cards = array_fill_keys(array_merge(['target'], array_keys(status_fields())), 0);
    foreach ($cards as $key => $_) {
        $cards[$key] = (int)($row[$key] ?? 0);
    }
    return $cards;
}

function progress_area_card_value(array $cards, string $field): string
{
    $count = (int)($cards[$field] ?? 0);
    $target = (int)($cards['target'] ?? 0);
    if ($field === 'target') {
        return '<span class="d-block">' . number_format($count, 0, ',', '.') . '</span><span class="d-block">&nbsp;</span>';
    }
    $pct = $target > 0 ? $count / $target * 100 : 0;
    return '<span class="d-block">' . number_format($count, 0, ',', '.') . '</span><span class="d-block">(' . number_format($pct, 2, ',', '.') . '%)</span>';
}

function progress_area_xlsx_col(int $index): string
{
    $name = '';
    while ($index > 0) {
        $mod = ($index - 1) % 26;
        $name = chr(65 + $mod) . $name;
        $index = intdiv($index - $mod - 1, 26);
    }
    return $name;
}

function progress_area_xlsx_cell($value, int $row, int $col, int $style = 0, bool $numeric = false): string
{
    $ref = progress_area_xlsx_col($col) . $row;
    if ($numeric && is_numeric($value)) {
        return '<c r="' . $ref . '" s="' . $style . '"><v>' . htmlspecialchars((string)(0 + $value), ENT_XML1 | ENT_COMPAT, 'UTF-8') . '</v></c>';
    }
    return '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is><t>' . htmlspecialchars((string)$value, ENT_XML1 | ENT_COMPAT, 'UTF-8') . '</t></is></c>';
}

function progress_area_export_header_style(string $header, string $finalLabel): int
{
    if (str_starts_with($header, 'Total Assignment ' . $finalLabel)) {
        return 1;
    }
    if (str_starts_with($header, 'Total ' . $finalLabel) || str_starts_with($header, 'Submit Count sd ' . $finalLabel) || str_starts_with($header, 'Submit Persen sd ' . $finalLabel)) {
        return 2;
    }
    if (str_starts_with($header, 'Total ') || str_starts_with($header, 'Submit Count sd ') || str_starts_with($header, 'Submit Persen sd ')) {
        return 3;
    }
    if ($header === 'Total PCL' || $header === 'Total PML') {
        return 4;
    }
    if ($header === 'Total SubSLS') {
        return 5;
    }
    return 6;
}

function progress_area_export_value_style(string $header, $value): int
{
    if (!str_starts_with($header, 'Submit Persen sd ') || !is_numeric($value)) {
        return 0;
    }
    $pct = (float)$value;
    if ($pct < 20) {
        return 7;
    }
    if ($pct < 40) {
        return 8;
    }
    if ($pct < 75) {
        return 9;
    }
    return 10;
}

function progress_area_export_payload(array $trend, string $groupLabel): array
{
    $periods = [];
    $groups = [];
    foreach ($trend as $row) {
        $periodKey = (string)($row['period_key'] ?? '');
        $periodLabel = (string)($row['period_label'] ?? $periodKey);
        if ($periodKey !== '' && !isset($periods[$periodKey])) {
            $periods[$periodKey] = $periodLabel;
        }
        $code = (string)($row['code'] ?? $row['label'] ?? '-');
        if (!isset($groups[$code])) {
            $groups[$code] = [
                'code' => $code,
                'name' => (string)($row['name'] ?? $row['label'] ?? '-'),
                'periods' => [],
                'total_pcl' => 0,
                'total_pml' => 0,
                'total_subsls' => 0,
            ];
        }
        $target = (int)($row['target'] ?? 0);
        $submit = (int)($row['submitted_by_pencacah'] ?? 0) + (int)($row['rejected_by_pengawas'] ?? 0) + (int)($row['pending_count'] ?? 0) + (int)($row['approved_by_pengawas'] ?? 0);
        $groups[$code]['periods'][$periodLabel] = [
            'target' => $target,
            'submit' => $submit,
            'pct' => $target > 0 ? round($submit / $target * 100, 2) : 0,
        ];
        $groups[$code]['total_pcl'] = max($groups[$code]['total_pcl'], (int)($row['total_pcl'] ?? 0));
        $groups[$code]['total_pml'] = max($groups[$code]['total_pml'], (int)($row['total_pml'] ?? 0));
        $groups[$code]['total_subsls'] = max($groups[$code]['total_subsls'], (int)($row['total_subsls'] ?? 0));
    }
    $periodLabels = array_values($periods);
    $finalLabel = end($periodLabels) ?: 'Periode Akhir';
    $nameHeader = 'Nama ' . $groupLabel;
    $codeHeader = $groupLabel === 'Kabupaten' ? 'Kode Kab' : 'Kode ' . $groupLabel;
    $headers = [
        $codeHeader,
        $nameHeader,
        'Total Assignment ' . $finalLabel,
    ];
    foreach ($periodLabels as $label) {
        $headers[] = 'Total ' . $label;
        $headers[] = 'Submit Count sd ' . $label;
        $headers[] = 'Submit Persen sd ' . $label;
    }
    array_push($headers, 'Total PCL', 'Total PML', 'Total SubSLS');

    $rows = [];
    foreach ($groups as $group) {
        $final = $group['periods'][$finalLabel] ?? ['target' => 0, 'submit' => 0, 'pct' => 0];
        $row = [$group['code'], $group['name'], $final['target']];
        foreach ($periodLabels as $label) {
            $period = $group['periods'][$label] ?? ['target' => 0, 'submit' => 0, 'pct' => 0];
            $row[] = $period['target'];
            $row[] = $period['submit'];
            $row[] = $period['pct'];
        }
        $row[] = $group['total_pcl'];
        $row[] = $group['total_pml'];
        $row[] = $group['total_subsls'];
        $rows[] = $row;
    }
    return [$headers, $rows, $finalLabel];
}

function progress_area_export(array $headers, array $rows, string $finalLabel, string $format, string $footnote = ''): void
{
    $filename = 'progress_by_daerah_' . date('Ymd_His');
    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, $headers);
        foreach ($rows as $row) {
            fputcsv($out, $row);
        }
        if ($footnote !== '') {
            fputcsv($out, []);
            fputcsv($out, [$footnote]);
        }
        fclose($out);
        exit;
    }

    $tmp = tempnam(sys_get_temp_dir(), 'progress_area_');
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Gagal membuat file Excel.');
    }
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="progress_by_daerah" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
    $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="6"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FF111827"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFB91C1C"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFEAB308"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FF2563EB"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FF16A34A"/><name val="Calibri"/></font></fonts><fills count="7"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFFFEDD5"/><bgColor indexed="64"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFDBEAFE"/><bgColor indexed="64"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFDCFCE7"/><bgColor indexed="64"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFF5DEB3"/><bgColor indexed="64"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFEDE9FE"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="2"><border/><border><left style="thin"><color rgb="FFCBD5E1"/></left><right style="thin"><color rgb="FFCBD5E1"/></right><top style="thin"><color rgb="FFCBD5E1"/></top><bottom style="thin"><color rgb="FFCBD5E1"/></bottom></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="11"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFill="1" applyAlignment="1"><alignment horizontal="left" vertical="center" wrapText="1"/></xf><xf numFmtId="0" fontId="1" fillId="3" borderId="1" xfId="0" applyFill="1" applyAlignment="1"><alignment horizontal="left" vertical="center" wrapText="1"/></xf><xf numFmtId="0" fontId="1" fillId="4" borderId="1" xfId="0" applyFill="1" applyAlignment="1"><alignment horizontal="left" vertical="center" wrapText="1"/></xf><xf numFmtId="0" fontId="1" fillId="5" borderId="1" xfId="0" applyFill="1" applyAlignment="1"><alignment horizontal="left" vertical="center" wrapText="1"/></xf><xf numFmtId="0" fontId="1" fillId="6" borderId="1" xfId="0" applyFill="1" applyAlignment="1"><alignment horizontal="left" vertical="center" wrapText="1"/></xf><xf numFmtId="0" fontId="1" fillId="0" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="left" vertical="center" wrapText="1"/></xf><xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="4" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="5" fillId="0" borderId="0" xfId="0"/></cellXfs></styleSheet>');
    $sheet = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
    foreach (array_merge([$headers], $rows) as $rIndex => $row) {
        $rowNumber = $rIndex + 1;
        $sheet .= '<row r="' . $rowNumber . '">';
        foreach ($row as $cIndex => $value) {
            $header = (string)($headers[$cIndex] ?? '');
            $style = $rowNumber === 1 ? progress_area_export_header_style($header, $finalLabel) : 0;
            if ($rowNumber > 1) {
                $style = progress_area_export_value_style($header, $value);
            }
            $numeric = $rowNumber > 1 && $cIndex > 1;
            $sheet .= progress_area_xlsx_cell($value, $rowNumber, $cIndex + 1, $style, $numeric);
        }
        $sheet .= '</row>';
    }
    if ($footnote !== '') {
        $footnoteRow = count($rows) + 3;
        $sheet .= '<row r="' . $footnoteRow . '">';
        $sheet .= progress_area_xlsx_cell($footnote, $footnoteRow, 1, 6, false);
        $sheet .= '</row>';
    }
    $sheet .= '</sheetData></worksheet>';
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
    $zip->close();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '.xlsx"');
    readfile($tmp);
    unlink($tmp);
    exit;
}

$options = progress_area_filter_options($user, $filters);
if ($filters['kec_id'] && !in_array($filters['kec_id'], array_column($options['kecamatan'], 'value'), true)) {
    $filters['kec_id'] = '';
    $filters['desa_id'] = '';
}
if ($filters['desa_id'] && !in_array($filters['desa_id'], array_column($options['desa'], 'value'), true)) {
    $filters['desa_id'] = '';
}

$showProgress = in_array(($_GET['action'] ?? ''), ['filter', 'export'], true);
$trend = [];
$groupLabel = 'Wilayah';
$periodFootnote = $filters['period_mode'] === 'weekly' ? progress_area_week_footnote($filters['week_end'], $defaultWeekStart) : '';
$cards = array_fill_keys(array_merge(['target'], array_keys(status_fields())), 0);
if ($showProgress) {
    [$where, $params] = progress_area_where($user, $filters);
    [$groupExpr, $codeExpr, $nameExpr, $labelExpr, $orderExpr, $groupLabel] = progress_area_grouping($user, $filters);
    [$periodExpr, $periodLabelExpr, $periodOrderExpr] = progress_area_period_select($filters, $defaultWeekStart);
    $sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $stmt = db()->prepare("SELECT {$periodExpr} period_key, {$periodLabelExpr} period_label,
            {$codeExpr} code, {$nameExpr} name, $labelExpr label,
            SUM(ds.target) target,
            SUM(ds.submitted_by_pencacah) submitted_by_pencacah,
            SUM(ds.approved_by_pengawas) approved_by_pengawas,
            SUM(ds.rejected_by_pengawas) rejected_by_pengawas,
            SUM(ds.draft_count) draft_count,
            SUM(ds.pending_count) pending_count,
            COUNT(DISTINCT NULLIF(ms.pencacah_email, '')) total_pcl,
            COUNT(DISTINCT NULLIF(ms.pengawas_email, '')) total_pml,
            COUNT(DISTINCT ms.id) total_subsls
        FROM daily_status ds
        JOIN master_subsls ms ON ms.id=ds.subsls_id
        JOIN master_sls sl ON sl.id=ms.sls_id
        JOIN master_desa d ON d.id=sl.desa_id
        JOIN master_kec kc ON kc.id=d.kec_id
        JOIN master_kab k ON k.id=kc.kab_id
        {$sqlWhere}
        GROUP BY period_key, period_label, $groupExpr, code, name, label
        ORDER BY {$periodOrderExpr}, $orderExpr");
    $stmt->execute($params);
    $trend = $stmt->fetchAll();
    $cards = progress_area_current_cards($user, $filters);
    if (($_GET['action'] ?? '') === 'export') {
        [$exportHeaders, $exportRows, $exportFinalLabel] = progress_area_export_payload($trend, $groupLabel);
        $format = ($_GET['format'] ?? 'csv') === 'xlsx' ? 'xlsx' : 'csv';
        progress_area_export($exportHeaders, $exportRows, $exportFinalLabel, $format, $periodFootnote);
    }
}

render_header('Progress By Daerah');
?>
<style>
  .progress-chart-wrap {
    height: 380px;
    position: relative;
    width: 100%;
  }
  .progress-stat-card {
    background: linear-gradient(180deg, #fff3df 0%, #fffaf2 64%) !important;
    border: 1px solid #f0b35c;
    border-left: 5px solid #f59e0b;
    border-radius: 8px;
    box-shadow: 0 8px 18px rgba(180, 83, 9, .12);
  }
  .progress-stat-card h3 { color: #111827; font-weight: 800; }
  .progress-stat-card p { color: #92400e; font-weight: 700; }
  @media (max-width: 767.98px) {
    .progress-chart-wrap { height: 320px; }
  }
</style>
<form class="card card-body mb-3" method="get" id="areaProgressFilterForm">
  <input type="hidden" name="action" id="areaProgressAction" value="">
  <div class="form-row align-items-end">
    <div class="form-group col-md-2">
      <label>Mode Periode</label>
      <select class="form-control" name="period_mode" id="period_mode">
        <option value="daily" <?= $filters['period_mode']==='daily'?'selected':'' ?>>Harian</option>
        <option value="weekly" <?= $filters['period_mode']==='weekly'?'selected':'' ?>>Mingguan</option>
        <option value="monthly" <?= $filters['period_mode']==='monthly'?'selected':'' ?>>Bulanan</option>
      </select>
    </div>

    <div class="form-group col-md-2 period-daily">
      <label>Tanggal Awal</label>
      <input class="form-control" type="date" name="date_start" id="date_start" value="<?= e($filters['date_start']) ?>">
    </div>

    <div class="form-group col-md-2 period-daily">
      <label>Tanggal Akhir</label>
      <input class="form-control" type="date" name="date_end" id="date_end" value="<?= e($filters['date_end']) ?>">
    </div>

    <div class="form-group col-md-2 period-weekly d-none">
      <label>Minggu Awal</label>
      <select class="form-control" name="week_start" id="week_start">
        <?php foreach ($weekOptions as $value => $label): ?><option value="<?= e((string)$value) ?>" <?= $filters['week_start']===(string)$value?'selected':'' ?>><?= e($label) ?></option><?php endforeach; ?>
      </select>
    </div>

    <div class="form-group col-md-2 period-weekly d-none">
      <label>Minggu Akhir</label>
      <select class="form-control" name="week_end" id="week_end">
        <?php foreach ($weekOptions as $value => $label): ?><option value="<?= e((string)$value) ?>" <?= $filters['week_end']===(string)$value?'selected':'' ?>><?= e($label) ?></option><?php endforeach; ?>
      </select>
    </div>

    <div class="form-group col-md-2 period-monthly d-none">
      <label>Bulan Awal</label>
      <select class="form-control" name="month_start" id="month_start">
        <?php foreach ($monthOptions as $value => $label): ?><option value="<?= e($value) ?>" <?= $filters['month_start']===$value?'selected':'' ?>><?= e($label) ?></option><?php endforeach; ?>
      </select>
    </div>

    <div class="form-group col-md-2 period-monthly d-none">
      <label>Bulan Akhir</label>
      <select class="form-control" name="month_end" id="month_end">
        <?php foreach ($monthOptions as $value => $label): ?><option value="<?= e($value) ?>" <?= $filters['month_end']===$value?'selected':'' ?>><?= e($label) ?></option><?php endforeach; ?>
      </select>
    </div>
  </div>
  <div class="form-row align-items-end">
    <?php if (in_array($user['role'], ['superadmin','viewer_prov'], true)): ?>
      <div class="form-group col-md-2">
        <label>Kabupaten</label>
        <select class="form-control" name="kab_id" id="kab_id">
          <option value="">Semua Kabupaten</option>
          <?php foreach ($options['kabupaten'] as $o): ?><option value="<?= e($o['value']) ?>" <?= $filters['kab_id']===$o['value']?'selected':'' ?>><?= e($o['label']) ?></option><?php endforeach; ?>
        </select>
      </div>
    <?php else: ?>
      <input type="hidden" name="kab_id" value="<?= e($filters['kab_id']) ?>">
    <?php endif; ?>

    <div class="form-group col-md-2">
      <label>Kecamatan</label>
      <select class="form-control" name="kec_id" id="kec_id" <?= $filters['kab_id'] ? '' : 'disabled' ?>>
        <option value=""><?= $filters['kab_id'] ? 'Semua Kecamatan' : 'Pilih kabupaten dulu' ?></option>
        <?php foreach ($options['kecamatan'] as $o): ?><option value="<?= e($o['value']) ?>" <?= $filters['kec_id']===$o['value']?'selected':'' ?>><?= e($o['label']) ?></option><?php endforeach; ?>
      </select>
    </div>

    <div class="form-group col-md-3">
      <label>Desa</label>
      <select class="form-control" name="desa_id" id="desa_id" <?= $filters['kec_id'] ? '' : 'disabled' ?>>
        <option value=""><?= $filters['kec_id'] ? 'Semua Desa' : 'Pilih kecamatan dulu' ?></option>
        <?php foreach ($options['desa'] as $o): ?><option value="<?= e($o['value']) ?>" <?= $filters['desa_id']===$o['value']?'selected':'' ?>><?= e($o['label']) ?></option><?php endforeach; ?>
      </select>
    </div>
  </div>
  <div class="form-row">
    <div class="form-group col-md-2 mb-0">
      <button class="btn btn-primary btn-block" type="submit" id="areaProgressFilterButton">Filter</button>
    </div>
  </div>
</form>

<?php if (!$showProgress): ?>
  <div class="alert alert-info">Atur filter wilayah, lalu klik tombol Filter untuk menampilkan progress.</div>
<?php else: ?>
  <div class="row"><?php foreach (array_merge(['target'=>'Target'], status_fields()) as $field=>$label): ?><div class="col-md"><div class="small-box progress-stat-card"><div class="inner"><h3><?= progress_area_card_value($cards, $field) ?></h3><p><?= e($label) ?></p></div></div></div><?php endforeach; ?></div>
  <div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
      <strong>Progress Pendataan per <?= e($groupLabel) ?></strong>
      <div>
        <?php
          $exportParams = $_GET;
          $exportParams['action'] = 'export';
          $exportParams['format'] = 'csv';
          $csvUrl = '?' . http_build_query($exportParams);
          $exportParams['format'] = 'xlsx';
          $xlsxUrl = '?' . http_build_query($exportParams);
        ?>
        <a class="btn btn-sm btn-outline-secondary" href="<?= e($csvUrl) ?>">Export CSV</a>
        <a class="btn btn-sm btn-success" href="<?= e($xlsxUrl) ?>">Export XLSX</a>
      </div>
    </div>
    <div class="card-body">
      <div class="progress-chart-wrap"><canvas id="lineChart"></canvas></div>
      <?php if ($periodFootnote !== ''): ?>
        <div class="small text-muted mt-2"><?= e($periodFootnote) ?></div>
      <?php endif; ?>
    </div>
  </div>
  <script>
  const rows = <?= json_encode($trend) ?>;
  const colors = ['#2563eb','#16a34a','#dc2626','#f59e0b','#0f766e','#7c3aed','#0891b2','#be123c','#4d7c0f','#9333ea','#64748b','#ea580c','#0ea5e9','#84cc16','#f43f5e'];
  const periods = [...new Map(rows.map(r => [String(r.period_key), r.period_label || r.period_key])).values()];
  const seriesLabels = [...new Set(rows.map(r => r.label || '-'))];
  const valueMap = {};
  let maxPct = 0;
  let minPct = null;
  rows.forEach(row => {
    const target = Number(row.target || 0);
    const pendataan = Number(row.submitted_by_pencacah || 0) + Number(row.rejected_by_pengawas || 0) + Number(row.pending_count || 0) + Number(row.approved_by_pengawas || 0);
    const pct = target ? Math.round(pendataan / target * 10000) / 100 : 0;
    maxPct = Math.max(maxPct, pct);
    minPct = minPct === null ? pct : Math.min(minPct, pct);
    valueMap[(row.label || '-') + '|' + (row.period_label || row.period_key)] = pct;
  });
  function chartYMin(value) {
    if (value === null || value <= 0) return 0;
    return Math.max(0, Math.floor(value / 5) * 5);
  }
  function chartYMax(value) {
    if (value <= 0) return 10;
    return Math.min(100, (Math.floor(value / 5) + 1) * 5);
  }
  new Chart(document.getElementById('lineChart'), {
    type:'line',
    data:{
      labels: periods,
      datasets: seriesLabels.map((label, i)=>({
        label,
        data: periods.map(period => valueMap[label + '|' + period] ?? null),
        borderColor: colors[i % colors.length],
        backgroundColor: colors[i % colors.length],
        tension:.2,
        spanGaps:true
      }))
    },
    options:{
      responsive:true,
      maintainAspectRatio:false,
      plugins:{legend:{position:'bottom'}},
      scales:{ y:{min:chartYMin(minPct),max:chartYMax(maxPct),ticks:{callback:v=>v+'%'}} }
    }
  });
  </script>
<?php endif; ?>

<script>
const kabSelect = document.getElementById('kab_id');
const kecSelect = document.getElementById('kec_id');
const desaSelect = document.getElementById('desa_id');
const areaProgressForm = document.getElementById('areaProgressFilterForm');
const areaProgressAction = document.getElementById('areaProgressAction');
const areaProgressFilterButton = document.getElementById('areaProgressFilterButton');
const periodMode = document.getElementById('period_mode');

function reloadAreaOptions() {
  areaProgressAction.value = 'options';
  areaProgressForm.submit();
}

function togglePeriodInputs() {
  const mode = periodMode ? periodMode.value : 'daily';
  document.querySelectorAll('.period-daily').forEach(el => el.classList.toggle('d-none', mode !== 'daily'));
  document.querySelectorAll('.period-weekly').forEach(el => el.classList.toggle('d-none', mode !== 'weekly'));
  document.querySelectorAll('.period-monthly').forEach(el => el.classList.toggle('d-none', mode !== 'monthly'));
}

if (periodMode) {
  periodMode.addEventListener('change', togglePeriodInputs);
  togglePeriodInputs();
}

areaProgressFilterButton.addEventListener('click', function () {
  areaProgressAction.value = 'filter';
});

if (kabSelect) {
  kabSelect.addEventListener('change', function () {
    kecSelect.value = '';
    desaSelect.value = '';
    kecSelect.disabled = !this.value;
    kecSelect.options[0].textContent = this.value ? 'Semua Kecamatan' : 'Pilih kabupaten dulu';
    desaSelect.disabled = true;
    desaSelect.options[0].textContent = 'Pilih kecamatan dulu';
    reloadAreaOptions();
  });
}
kecSelect.addEventListener('change', function () {
  desaSelect.value = '';
  desaSelect.disabled = !this.value;
  desaSelect.options[0].textContent = this.value ? 'Semua Desa' : 'Pilih kecamatan dulu';
  reloadAreaOptions();
});
</script>
<?php render_footer(); ?>
