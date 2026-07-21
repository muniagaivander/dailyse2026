<?php
require __DIR__ . '/layout.php';
$user = require_role(['superadmin', 'admin_kab', 'viewer_prov', 'viewer_kab']);

$filters = [
    'tanggal' => (string)($_GET['tanggal'] ?? date('Y-m-d')),
    'petugas_type' => ($_GET['petugas_type'] ?? 'pcl') === 'pml' ? 'pml' : 'pcl',
    'kab_id' => (string)($_GET['kab_id'] ?? ''),
    'kec_id' => (string)($_GET['kec_id'] ?? ''),
    'desa_id' => (string)($_GET['desa_id'] ?? ''),
    'search_nama' => trim((string)($_GET['search_nama'] ?? '')),
    'search_pml' => trim((string)($_GET['search_pml'] ?? '')),
    'sort_col' => preg_match('/^\d+$/', (string)($_GET['sort_col'] ?? '')) ? (string)$_GET['sort_col'] : '',
    'sort_dir' => ($_GET['sort_dir'] ?? '') === 'desc' ? 'desc' : (($_GET['sort_dir'] ?? '') === 'asc' ? 'asc' : ''),
];
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['tanggal'])) {
    $filters['tanggal'] = date('Y-m-d');
}
if (in_array($user['role'], ['admin_kab', 'viewer_kab'], true)) {
    $filters['kab_id'] = (string)$user['kab_id'];
}
$hasFiltered = isset($_GET['filter']) || isset($_GET['action']);

function rekap_weekly_filter_options(array $user, array $filters): array
{
    $out = ['kabupaten' => [], 'kecamatan' => [], 'desa' => []];
    if (in_array($user['role'], ['admin_kab', 'viewer_kab'], true)) {
        $stmt = db()->prepare("SELECT id value, CONCAT(id,' - ',nmkab) label FROM master_kab WHERE id=? ORDER BY id");
        $stmt->execute([$user['kab_id']]);
    } else {
        $stmt = db()->query("SELECT id value, CONCAT(id,' - ',nmkab) label FROM master_kab ORDER BY id");
    }
    $out['kabupaten'] = $stmt->fetchAll();

    if ($filters['kab_id'] !== '') {
        $stmt = db()->prepare("SELECT id value, CONCAT(kdkec,' - ',nmkec) label FROM master_kec WHERE kab_id=? ORDER BY kdkec, nmkec");
        $stmt->execute([$filters['kab_id']]);
        $out['kecamatan'] = $stmt->fetchAll();
    }
    if ($filters['kec_id'] !== '') {
        $stmt = db()->prepare("SELECT id value, CONCAT(kddesa,' - ',nmdesa) label FROM master_desa WHERE kec_id=? ORDER BY kddesa, nmdesa");
        $stmt->execute([$filters['kec_id']]);
        $out['desa'] = $stmt->fetchAll();
    }
    return $out;
}

function rekap_weekly_area_where(array $user, array $filters): array
{
    $where = [];
    $params = [];
    if (in_array($user['role'], ['admin_kab', 'viewer_kab'], true)) {
        $where[] = 'k.id=?';
        $params[] = $user['kab_id'];
    } elseif ($filters['kab_id'] !== '') {
        $where[] = 'k.id=?';
        $params[] = $filters['kab_id'];
    }
    if ($filters['kec_id'] !== '') {
        $where[] = 'kc.id=?';
        $params[] = $filters['kec_id'];
    }
    if ($filters['desa_id'] !== '') {
        $where[] = 'd.id=?';
        $params[] = $filters['desa_id'];
    }
    return [$where, $params];
}

function rekap_weekly_petugas_rows(array $user, array $filters): array
{
    [$where, $params] = rekap_weekly_area_where($user, $filters);
    $emailField = $filters['petugas_type'] === 'pcl' ? 'pencacah_email' : 'pengawas_email';
    $where[] = "ms.$emailField IS NOT NULL";
    $where[] = "ms.$emailField <> ''";

    $stmt = db()->prepare("SELECT
            ms.$emailField email,
            u.name petugas_name,
            MIN(k.id) sort_kab_id,
            MIN(kc.kdkec) sort_kec_code,
            MIN(d.kddesa) sort_desa_code,
            GROUP_CONCAT(DISTINCT ms.pengawas_email ORDER BY ms.pengawas_email SEPARATOR ',') pml_emails,
            GROUP_CONCAT(DISTINCT ms.pencacah_email ORDER BY ms.pencacah_email SEPARATOR ',') pcl_emails,
            GROUP_CONCAT(DISTINCT CASE
                WHEN up.name IS NULL OR up.name='' OR LOWER(up.name)=LOWER(ms.pengawas_email) THEN ms.pengawas_email
                ELSE up.name
            END ORDER BY up.name, ms.pengawas_email SEPARATOR ', ') pml_names,
            GROUP_CONCAT(DISTINCT k.nmkab ORDER BY k.id SEPARATOR ', ') kabupaten,
            GROUP_CONCAT(DISTINCT kc.nmkec ORDER BY k.id, kc.kdkec SEPARATOR ', ') wilayah_kerja_kecamatan,
            GROUP_CONCAT(DISTINCT d.nmdesa ORDER BY kc.kdkec, d.kddesa SEPARATOR ', ') wilayah_kerja,
            COUNT(ms.id) subsls_total
        FROM master_subsls ms
        JOIN master_sls sl ON sl.id=ms.sls_id
        JOIN master_desa d ON d.id=sl.desa_id
        JOIN master_kec kc ON kc.id=d.kec_id
        JOIN master_kab k ON k.id=kc.kab_id
        LEFT JOIN users u ON u.email=ms.$emailField
        LEFT JOIN users up ON up.email=ms.pengawas_email
        WHERE " . implode(' AND ', $where) . "
        GROUP BY ms.$emailField, u.name
        ORDER BY sort_kab_id, sort_kec_code, sort_desa_code, u.name, ms.$emailField");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function rekap_weekly_values(array $user, array $filters, string $dateStart, string $dateEnd): array
{
    [$where, $params] = rekap_weekly_area_where($user, $filters);
    $emailField = $filters['petugas_type'] === 'pcl' ? 'pencacah_email' : 'pengawas_email';
    $queryStart = date('Y-m-d', strtotime($dateStart . ' -1 day'));
    $where[] = 'ds.tanggal BETWEEN ? AND ?';
    $params[] = $queryStart;
    $params[] = $dateEnd;
    $where[] = "ds.$emailField IS NOT NULL";
    $where[] = "ds.$emailField <> ''";

    $stmt = db()->prepare("SELECT
            ds.$emailField email,
            ds.tanggal,
            SUM(ds.target) target,
            SUM(ds.draft_count) draft_count,
            SUM(ds.submitted_by_pencacah + ds.rejected_by_pengawas + ds.pending_count + ds.approved_by_pengawas) progress_count
        FROM daily_status ds
        JOIN master_subsls ms ON ms.id=ds.subsls_id
        JOIN master_sls sl ON sl.id=ms.sls_id
        JOIN master_desa d ON d.id=sl.desa_id
        JOIN master_kec kc ON kc.id=d.kec_id
        JOIN master_kab k ON k.id=kc.kab_id
        WHERE " . implode(' AND ', $where) . "
        GROUP BY ds.$emailField, ds.tanggal
        ORDER BY ds.tanggal, ds.$emailField");
    $stmt->execute($params);

    $matrix = [];
    foreach ($stmt->fetchAll() as $row) {
        $matrix[normalize_email((string)$row['email'])][(string)$row['tanggal']] = [
            'count' => (int)$row['progress_count'],
            'draft_count' => (int)$row['draft_count'],
            'target' => (int)$row['target'],
        ];
    }
    return $matrix;
}

function rekap_weekly_dates(string $end): array
{
    $start = date('Y-m-d', strtotime($end . ' -6 days'));
    $dates = [];
    for ($date = $start; $date <= $end; $date = date('Y-m-d', strtotime($date . ' +1 day'))) {
        $dates[] = $date;
    }
    return $dates;
}

function rekap_weekly_date_label(string $date): string
{
    static $months = [
        '01' => 'Januari', '02' => 'Februari', '03' => 'Maret', '04' => 'April',
        '05' => 'Mei', '06' => 'Juni', '07' => 'Juli', '08' => 'Agustus',
        '09' => 'September', '10' => 'Oktober', '11' => 'November', '12' => 'Desember',
    ];
    [$year, $month, $day] = explode('-', $date);
    return (int)$day . ' ' . ($months[$month] ?? $month);
}

function rekap_weekly_pct(int $count, int $target): float
{
    return $target > 0 ? $count / $target * 100 : 0;
}

function rekap_weekly_pct_export(float $pct): string
{
    return number_format($pct, 2, '.', '');
}

function rekap_weekly_pct_web(float $pct): string
{
    return number_format($pct, 2, ',', '.') . '%';
}

function rekap_weekly_pct_class(float $pct): string
{
    if ($pct < 20) {
        return 'weekly-progress-low';
    }
    if ($pct < 40) {
        return 'weekly-progress-warning';
    }
    if ($pct < 75) {
        return 'weekly-progress-mid';
    }
    return 'weekly-progress-high';
}

function rekap_weekly_draft_pct_class(float $pct): string
{
    if ($pct < 5) {
        return 'weekly-draft-low';
    }
    if ($pct < 10) {
        return 'weekly-draft-warning';
    }
    return 'weekly-draft-high';
}

function rekap_weekly_apply_search(array $rows, array $filters): array
{
    $nameTerm = strtolower(trim((string)($filters['search_nama'] ?? '')));
    $pmlTerm = strtolower(trim((string)($filters['search_pml'] ?? '')));
    if ($nameTerm === '' && $pmlTerm === '') {
        return $rows;
    }
    return array_values(array_filter($rows, function (array $row) use ($nameTerm, $pmlTerm): bool {
        if ($nameTerm !== '') {
            $nameHaystack = strtolower(trim((string)($row['petugas_name'] ?? '')) . ' ' . (string)($row['email'] ?? ''));
            if (!str_contains($nameHaystack, $nameTerm)) {
                return false;
            }
        }
        if ($pmlTerm !== '') {
            $pmlHaystack = strtolower((string)($row['pml_names'] ?? ''));
            if (!str_contains($pmlHaystack, $pmlTerm)) {
                return false;
            }
        }
        return true;
    }));
}

function rekap_weekly_latest_daily(array $dailyRows, string $dateEnd): ?array
{
    $latestDate = null;
    foreach (array_keys($dailyRows) as $date) {
        if ($date <= $dateEnd && ($latestDate === null || $date > $latestDate)) {
            $latestDate = $date;
        }
    }
    return $latestDate === null ? null : $dailyRows[$latestDate];
}

function rekap_weekly_default_sort_rows(array $rows, array $matrix, array $dates, array $filters): array
{
    $dateEnd = (string)end($dates);
    usort($rows, function (array $a, array $b) use ($matrix, $dateEnd, $filters): int {
        foreach (['sort_kab_id', 'sort_kec_code'] as $key) {
            $cmp = strnatcasecmp((string)($a[$key] ?? ''), (string)($b[$key] ?? ''));
            if ($cmp !== 0) {
                return $cmp;
            }
        }
        if (($filters['kec_id'] ?? '') !== '') {
            $cmp = strnatcasecmp((string)($a['sort_desa_code'] ?? ''), (string)($b['sort_desa_code'] ?? ''));
            if ($cmp !== 0) {
                return $cmp;
            }
        }
        $aDaily = rekap_weekly_latest_daily($matrix[normalize_email((string)($a['email'] ?? ''))] ?? [], $dateEnd);
        $bDaily = rekap_weekly_latest_daily($matrix[normalize_email((string)($b['email'] ?? ''))] ?? [], $dateEnd);
        $aPct = rekap_weekly_pct((int)($aDaily['count'] ?? 0), (int)($aDaily['target'] ?? 0));
        $bPct = rekap_weekly_pct((int)($bDaily['count'] ?? 0), (int)($bDaily['target'] ?? 0));
        $cmp = $aPct <=> $bPct;
        if ($cmp !== 0) {
            return $cmp;
        }
        if (($filters['petugas_type'] ?? 'pcl') === 'pcl') {
            $cmp = strnatcasecmp((string)($a['pml_names'] ?? ''), (string)($b['pml_names'] ?? ''));
            if ($cmp !== 0) {
                return $cmp;
            }
        }
        $cmp = strnatcasecmp((string)($a['petugas_name'] ?? ''), (string)($b['petugas_name'] ?? ''));
        return $cmp !== 0 ? $cmp : strnatcasecmp((string)($a['email'] ?? ''), (string)($b['email'] ?? ''));
    });
    return $rows;
}

function rekap_weekly_petugas_summary(array $rows, string $type): array
{
    if ($type === 'pcl') {
        $pmlEmails = [];
        foreach ($rows as $row) {
            foreach (explode(',', (string)($row['pml_emails'] ?? '')) as $email) {
                $email = normalize_email($email);
                if ($email !== '') {
                    $pmlEmails[$email] = true;
                }
            }
        }
        return ['pcl' => count($rows), 'pml' => count($pmlEmails)];
    }
    $pclEmails = [];
    foreach ($rows as $row) {
        foreach (explode(',', (string)($row['pcl_emails'] ?? '')) as $email) {
            $email = normalize_email($email);
            if ($email !== '') {
                $pclEmails[$email] = true;
            }
        }
    }
    return ['pcl' => count($pclEmails), 'pml' => count($rows)];
}

function rekap_weekly_export_payload(array $rows, array $dates, array $matrix, array $filters): array
{
    $dateEnd = end($dates);
    $dateEndLabel = rekap_weekly_date_label((string)$dateEnd);
    $headers = ['Nama Petugas', 'Email Petugas'];
    if ($filters['petugas_type'] === 'pcl') {
        $headers[] = 'Nama PML';
    }
    $headers = array_merge($headers, [
        'Kabupaten',
        'Wilayah Kerja Kecamatan',
        'Wilayah Kerja Desa',
        'Total Assignment (' . $dateEndLabel . ')',
        'Total Submit sd ' . $dateEndLabel,
        '% Submit sd ' . $dateEndLabel,
        'Total Draft sd ' . $dateEndLabel,
        '% Draft sd ' . $dateEndLabel,
    ]);
    foreach ($dates as $date) {
        $headers[] = 'Submit Tanggal ' . rekap_weekly_date_label($date);
    }
    $headers[] = 'Jumlah SubSLS';

    $out = [];
    foreach ($rows as $row) {
        $email = normalize_email((string)$row['email']);
        $petugasDailyRows = $matrix[$email] ?? [];
        $endDaily = rekap_weekly_latest_daily($petugasDailyRows, (string)$dateEnd);
        $target = (int)($endDaily['target'] ?? 0);
        $draftCount = (int)($endDaily['draft_count'] ?? 0);
        $rekapCount = (int)($endDaily['count'] ?? 0);
        $line = [
            trim((string)($row['petugas_name'] ?? '')) ?: '-',
            $row['email'],
        ];
        if ($filters['petugas_type'] === 'pcl') {
            $line[] = $row['pml_names'] ?: '-';
        }
        $line[] = $row['kabupaten'] ?: '-';
        $line[] = $row['wilayah_kerja_kecamatan'] ?: '-';
        $line[] = $row['wilayah_kerja'] ?: '-';
        $line[] = $target;
        $line[] = $rekapCount;
        $line[] = rekap_weekly_pct_export(rekap_weekly_pct($rekapCount, $target));
        $line[] = $draftCount;
        $line[] = rekap_weekly_pct_export(rekap_weekly_pct($draftCount, $target));
        foreach ($dates as $date) {
            $daily = $matrix[$email][$date] ?? null;
            $previous = $matrix[$email][date('Y-m-d', strtotime($date . ' -1 day'))] ?? null;
            $line[] = $daily !== null && $previous !== null
                ? (int)$daily['count'] - (int)$previous['count']
                : 0;
        }
        $line[] = (int)$row['subsls_total'];
        $out[] = $line;
    }
    return [$headers, $out];
}

function rekap_weekly_header_html(string $header): string
{
    if ($header === 'Wilayah Kerja Kecamatan') {
        return 'Wilayah Kerja<br>Kecamatan';
    }
    if ($header === 'Wilayah Kerja Desa') {
        return 'Wilayah Kerja<br>Desa';
    }
    if ($header === 'Jumlah SubSLS') {
        return 'Jumlah<br>SubSLS';
    }
    if (preg_match('/^Total Assignment \((.+)\)$/', $header, $m)) {
        return 'Total<br>Assignment<br>(' . e($m[1]) . ')';
    }
    if (preg_match('/^Total Submit sd (.+)$/', $header, $m)) {
        return 'Total<br>Submit sd<br>' . e($m[1]);
    }
    if (preg_match('/^% Submit sd (.+)$/', $header, $m)) {
        return '% Submit<br>sd<br>' . e($m[1]);
    }
    if (preg_match('/^Total Draft sd (.+)$/', $header, $m)) {
        return 'Total<br>Draft sd<br>' . e($m[1]);
    }
    if (preg_match('/^% Draft sd (.+)$/', $header, $m)) {
        return '% Draft<br>sd<br>' . e($m[1]);
    }
    if (preg_match('/^Submit Tanggal (.+)$/', $header, $m)) {
        return 'Submit<br>Tanggal<br>' . e($m[1]);
    }
    return e($header);
}

function rekap_weekly_sort_table_rows(array $headers, array $rows, array $filters): array
{
    $colText = (string)($filters['sort_col'] ?? '');
    $dir = (string)($filters['sort_dir'] ?? '');
    if ($colText === '' || !in_array($dir, ['asc', 'desc'], true)) {
        return $rows;
    }
    $col = (int)$colText;
    if (!array_key_exists($col, $headers)) {
        return $rows;
    }
    $numeric = rekap_weekly_xlsx_header_is_numeric((string)$headers[$col]);
    usort($rows, function (array $a, array $b) use ($col, $dir, $numeric): int {
        $av = $a[$col] ?? '';
        $bv = $b[$col] ?? '';
        if ($numeric) {
            $cmp = ((float)$av) <=> ((float)$bv);
        } else {
            $cmp = strnatcasecmp((string)$av, (string)$bv);
        }
        return $dir === 'asc' ? $cmp : -$cmp;
    });
    return $rows;
}

function rekap_weekly_xlsx_col(int $index): string
{
    $name = '';
    while ($index > 0) {
        $mod = ($index - 1) % 26;
        $name = chr(65 + $mod) . $name;
        $index = intdiv($index - $mod, 26);
    }
    return $name;
}

function rekap_weekly_xlsx_numeric_value($value): ?string
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

function rekap_weekly_xlsx_header_is_numeric(string $header): bool
{
    $header = strtolower($header);
    foreach (['email', 'nama', 'petugas', 'kabupaten', 'kecamatan', 'desa', 'wilayah', 'pml'] as $textPart) {
        if (str_contains($header, $textPart)) {
            return false;
        }
    }
    foreach (['jumlah subsls', 'total assignment', 'total draft', '% draft', 'total submit', '% submit', 'submit tanggal', 'count', 'persen'] as $numericPart) {
        if (str_contains($header, $numericPart)) {
            return true;
        }
    }
    return false;
}

function rekap_weekly_xlsx_header_is_pct(string $header): bool
{
    $header = strtolower($header);
    return str_contains($header, '% submit') || str_contains($header, '% draft');
}

function rekap_weekly_xlsx_draft_pct_style(string $header, $value): int
{
    $header = strtolower($header);
    if (!str_contains($header, '% draft')) {
        return 0;
    }
    $number = rekap_weekly_xlsx_numeric_value($value);
    if ($number === null) {
        return 0;
    }
    $pct = (float)$number;
    if ($pct < 5) {
        return 4;
    }
    if ($pct < 10) {
        return 5;
    }
    return 6;
}

function rekap_weekly_xlsx_cell($value, int $row, int $col, int $style = 0, bool $numeric = false): string
{
    $ref = rekap_weekly_xlsx_col($col) . $row;
    if ($numeric) {
        $number = rekap_weekly_xlsx_numeric_value($value);
        if ($number !== null) {
            return '<c r="' . $ref . '" s="' . $style . '"><v>' . htmlspecialchars($number, ENT_XML1 | ENT_COMPAT, 'UTF-8') . '</v></c>';
        }
    }
    return '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is><t>' . htmlspecialchars((string)$value, ENT_XML1 | ENT_COMPAT, 'UTF-8') . '</t></is></c>';
}

function rekap_weekly_export(array $headers, array $rows, array $filters, string $format): void
{
    $filename = 'rekap_petugas_weekly_' . $filters['petugas_type'] . '_' . str_replace('-', '', $filters['tanggal']) . '_' . date('Ymd_His');
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

    $tmp = tempnam(sys_get_temp_dir(), 'rekap_weekly_');
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Gagal membuat file Excel.');
    }
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
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets><sheet name="rekap_petugas_weekly" sheetId="1" r:id="rId1"/></sheets>
</workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
</Relationships>');
    $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <fonts count="6">
    <font><sz val="11"/><name val="Calibri"/></font>
    <font><b/><sz val="11"/><color rgb="FF1F2937"/><name val="Calibri"/></font>
    <font><sz val="9"/><name val="Calibri"/></font>
    <font><b/><sz val="11"/><color rgb="FF16A34A"/><name val="Calibri"/></font>
    <font><b/><sz val="11"/><color rgb="FFFB7185"/><name val="Calibri"/></font>
    <font><b/><sz val="11"/><color rgb="FFDC2626"/><name val="Calibri"/></font>
  </fonts>
  <fills count="2">
    <fill><patternFill patternType="none"/></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFDBEAFE"/><bgColor indexed="64"/></patternFill></fill>
  </fills>
  <borders count="2">
    <border/>
    <border><left style="thin"><color rgb="FFCBD5E1"/></left><right style="thin"><color rgb="FFCBD5E1"/></right><top style="thin"><color rgb="FFCBD5E1"/></top><bottom style="thin"><color rgb="FFCBD5E1"/></bottom></border>
  </borders>
  <cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
  <cellXfs count="7">
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
    <xf numFmtId="0" fontId="1" fillId="1" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>
    <xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0"/>
    <xf numFmtId="2" fontId="0" fillId="0" borderId="0" xfId="0"/>
    <xf numFmtId="2" fontId="3" fillId="0" borderId="0" xfId="0"/>
    <xf numFmtId="2" fontId="4" fillId="0" borderId="0" xfId="0"/>
    <xf numFmtId="2" fontId="5" fillId="0" borderId="0" xfId="0"/>
  </cellXfs>
</styleSheet>');

    $identityCols = $filters['petugas_type'] === 'pcl' ? 7 : 6;
    $lastColumn = count($headers);
    $sheet = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
        . '<cols><col min="1" max="2" width="28" customWidth="1"/><col min="3" max="' . $identityCols . '" width="28" customWidth="1"/>'
        . '<col min="' . ($identityCols + 1) . '" max="' . $lastColumn . '" width="15" customWidth="1"/></cols><sheetData>';
    foreach (array_merge([$headers], $rows) as $rIndex => $row) {
        $rowNumber = $rIndex + 1;
        $sheet .= '<row r="' . $rowNumber . '"' . ($rowNumber === 1 ? ' ht="30" customHeight="1"' : '') . '>';
        $smallFontColumns = [2, $filters['petugas_type'] === 'pcl' ? 6 : 5];
        foreach ($row as $cIndex => $value) {
            $columnNumber = $cIndex + 1;
            $style = $rowNumber === 1 ? 1 : (in_array($columnNumber, $smallFontColumns, true) ? 2 : 0);
            $header = (string)($headers[$cIndex] ?? '');
            $numeric = $rowNumber > 1 && rekap_weekly_xlsx_header_is_numeric($header);
            if ($rowNumber > 1 && rekap_weekly_xlsx_header_is_pct($header)) {
                $style = 3;
            }
            $draftStyle = $rowNumber > 1 ? rekap_weekly_xlsx_draft_pct_style($header, $value) : 0;
            if ($draftStyle !== 0) {
                $style = $draftStyle;
            }
            $sheet .= rekap_weekly_xlsx_cell($value, $rowNumber, $columnNumber, $style, $numeric);
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

$opts = rekap_weekly_filter_options($user, $filters);
$dates = rekap_weekly_dates($filters['tanggal']);
$dateStart = $dates[0];
$dateEnd = $dates[count($dates) - 1];
$rows = [];
$headers = [];
$tableRows = [];
$petugasSummary = ['pcl' => 0, 'pml' => 0];
if ($hasFiltered) {
    $rows = rekap_weekly_apply_search(rekap_weekly_petugas_rows($user, $filters), $filters);
    $matrix = rekap_weekly_values($user, $filters, $dateStart, $dateEnd);
    $rows = rekap_weekly_default_sort_rows($rows, $matrix, $dates, $filters);
    $petugasSummary = rekap_weekly_petugas_summary($rows, $filters['petugas_type']);
    [$headers, $tableRows] = rekap_weekly_export_payload($rows, $dates, $matrix, $filters);
    $tableRows = rekap_weekly_sort_table_rows($headers, $tableRows, $filters);
    if (($_GET['action'] ?? '') === 'export') {
        $format = ($_GET['format'] ?? 'csv') === 'xlsx' ? 'xlsx' : 'csv';
        rekap_weekly_export($headers, $tableRows, $filters, $format);
    }
}
$showWeeklyKecamatanBreaks = $hasFiltered
    && $filters['sort_col'] === ''
    && $filters['sort_dir'] === ''
    && $filters['search_nama'] === ''
    && $filters['search_pml'] === '';

render_header('Rekap Petugas Weekly');
?>
<style>
  .weekly-note {
    background: linear-gradient(90deg, #fff7ed 0%, #fffbeb 100%);
    border-left: 5px solid #f59e0b;
    border-radius: 8px;
    color: #92400e;
    font-weight: 800;
    margin-bottom: 16px;
    padding: 10px 14px;
  }
  .weekly-note-line {
    margin-top: 4px;
  }
  .weekly-progress-legend {
    align-items: center;
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    margin-top: 6px;
  }
  .weekly-progress-legend span {
    align-items: center;
    display: inline-flex;
    gap: 5px;
  }
  .weekly-progress-legend i {
    border-radius: 999px;
    display: inline-block;
    height: 9px;
    width: 9px;
  }
  .weekly-progress-low { color: #dc2626; font-weight: 800; }
  .weekly-progress-warning { color: #f59e0b; font-weight: 800; }
  .weekly-progress-mid { color: #2563eb; font-weight: 800; }
  .weekly-progress-high { color: #16a34a; font-weight: 800; }
  .weekly-draft-low { color: #16a34a; font-weight: 800; }
  .weekly-draft-warning { color: #fb7185; font-weight: 800; }
  .weekly-draft-high { color: #dc2626; font-weight: 800; }
  .weekly-table th {
    line-height: 1.1;
    text-align: center;
    vertical-align: middle !important;
    white-space: normal;
  }
  .weekly-freeze-pane {
    max-height: 70vh;
    overflow: auto;
  }
  .weekly-table {
    border-collapse: separate;
    border-spacing: 0;
  }
  .weekly-table thead th {
    background: #f8fafc;
    background-clip: padding-box;
    position: sticky;
    top: 0;
    z-index: 8;
  }
  .weekly-table th:nth-child(1),
  .weekly-table td:nth-child(1) {
    left: 0;
    min-width: 170px;
    width: 170px;
  }
  .weekly-table th:nth-child(2),
  .weekly-table td:nth-child(2) {
    left: 170px;
    min-width: 150px;
    width: 150px;
  }
  .weekly-table th:nth-child(3),
  .weekly-table td:nth-child(3) {
    left: 320px;
    min-width: 130px;
    width: 130px;
  }
  .weekly-table th:nth-child(4),
  .weekly-table td:nth-child(4) {
    left: 450px;
    min-width: 180px;
    width: 180px;
  }
  .weekly-table.weekly-freeze-pcl th:nth-child(5),
  .weekly-table.weekly-freeze-pcl td:nth-child(5) {
    left: 630px;
    min-width: 220px;
    width: 220px;
  }
  .weekly-table.weekly-freeze-pml th:nth-child(4),
  .weekly-table.weekly-freeze-pml td:nth-child(4) {
    left: 450px;
    min-width: 220px;
    width: 220px;
  }
  .weekly-table.weekly-freeze-pml th:nth-child(-n+4),
  .weekly-table.weekly-freeze-pml td:nth-child(-n+4) {
    background-clip: padding-box;
    position: sticky;
    z-index: 7;
  }
  .weekly-table.weekly-freeze-pcl th:nth-child(-n+5),
  .weekly-table.weekly-freeze-pcl td:nth-child(-n+5) {
    background-clip: padding-box;
    position: sticky;
    z-index: 7;
  }
  .weekly-table.weekly-freeze-pml tbody td:nth-child(-n+4),
  .weekly-table.weekly-freeze-pcl tbody td:nth-child(-n+5) {
    background: #fff;
  }
  .weekly-table.weekly-freeze-pml tbody tr:nth-of-type(odd) td:nth-child(-n+4),
  .weekly-table.weekly-freeze-pcl tbody tr:nth-of-type(odd) td:nth-child(-n+5) {
    background: #f9fafb;
  }
  .weekly-table.weekly-freeze-pml tbody tr:hover td:nth-child(-n+4),
  .weekly-table.weekly-freeze-pcl tbody tr:hover td:nth-child(-n+5) {
    background: #eef2ff;
  }
  .weekly-table.weekly-freeze-pml thead th:nth-child(-n+4),
  .weekly-table.weekly-freeze-pcl thead th:nth-child(-n+5) {
    z-index: 10;
  }
  .weekly-table.weekly-freeze-pml th:nth-child(4),
  .weekly-table.weekly-freeze-pml td:nth-child(4),
  .weekly-table.weekly-freeze-pcl th:nth-child(5),
  .weekly-table.weekly-freeze-pcl td:nth-child(5) {
    box-shadow: 3px 0 0 #111827;
  }
  .weekly-kecamatan-break > td {
    border-top: 3px solid #111827 !important;
  }
  .weekly-header-box {
    align-items: center;
    display: flex;
    flex-direction: column;
    height: 104px;
    justify-content: space-between;
  }
  .weekly-header-label {
    align-items: center;
    display: flex;
    flex: 1;
    justify-content: center;
    min-height: 68px;
  }
  .weekly-table td { white-space: nowrap; }
  .weekly-table th.weekly-compact-number {
    min-width: 78px;
    width: 78px;
  }
  .weekly-table th.weekly-identity {
    min-width: 130px;
  }
  .weekly-table th.weekly-wide {
    min-width: 185px;
  }
  .weekly-table thead th.weekly-head-orange {
    background: #ffedd5 !important;
    color: #7c2d12;
  }
  .weekly-table thead th.weekly-head-blue {
    background: #dbeafe !important;
    color: #1e3a8a;
  }
  .weekly-table thead th.weekly-head-purple {
    background: #ede9fe !important;
    color: #4c1d95;
  }
  .weekly-table thead th.weekly-head-green {
    background: #dcfce7 !important;
    color: #14532d;
  }
  .weekly-small { font-size: .82rem; }
  .weekly-smaller { font-size: .66rem; }
  .weekly-sortable {
    user-select: none;
  }
  .weekly-sort-select {
    border-radius: 4px;
    font-size: .72rem;
    height: 24px;
    margin-top: 5px;
    min-width: 78px;
    padding: 1px 4px;
  }
  .weekly-search-input {
    border-radius: 4px;
    font-size: .72rem;
    height: 24px;
    margin-top: 5px;
    min-width: 128px;
    padding: 1px 6px;
  }
  .weekly-sort-spacer {
    display: block;
    height: 29px;
  }
  .weekly-page-size {
    max-width: 110px;
  }
  .weekly-toolbar {
    align-items: center;
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    justify-content: space-between;
  }
  .weekly-toolbar-left {
    align-items: flex-start;
    display: flex;
    flex-direction: column;
    gap: 8px;
  }
  .weekly-page-control {
    align-items: center;
    display: flex;
  }
</style>

<form class="card card-body mb-3" method="get">
  <div class="form-row align-items-end">
    <input type="hidden" name="search_nama" value="<?= e($filters['search_nama']) ?>">
    <input type="hidden" name="search_pml" value="<?= e($filters['search_pml']) ?>">
    <div class="form-group col-12 col-md-2">
      <label>Tanggal</label>
      <input type="date" class="form-control" name="tanggal" value="<?= e($filters['tanggal']) ?>">
    </div>
    <div class="form-group col-12 col-md-2">
      <label>Jenis Petugas</label>
      <select class="form-control" name="petugas_type">
        <option value="pcl" <?= $filters['petugas_type']==='pcl'?'selected':'' ?>>PCL</option>
        <option value="pml" <?= $filters['petugas_type']==='pml'?'selected':'' ?>>PML</option>
      </select>
    </div>
    <?php if (in_array($user['role'], ['superadmin', 'viewer_prov'], true)): ?>
      <div class="form-group col-12 col-md-2">
        <label>Kabupaten</label>
        <select class="form-control" name="kab_id">
          <option value="">Semua Kabupaten</option>
          <?php foreach ($opts['kabupaten'] as $o): ?>
            <option value="<?= e($o['value']) ?>" <?= $filters['kab_id']===$o['value']?'selected':'' ?>><?= e($o['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    <?php endif; ?>
    <div class="form-group col-12 col-md-2">
      <label>Kecamatan</label>
      <select class="form-control" name="kec_id" <?= $filters['kab_id']===''?'disabled':'' ?>>
        <option value="">Semua Kecamatan</option>
        <?php foreach ($opts['kecamatan'] as $o): ?>
          <option value="<?= e($o['value']) ?>" <?= $filters['kec_id']===$o['value']?'selected':'' ?>><?= e($o['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group col-12 col-md-2">
      <label>Desa</label>
      <select class="form-control" name="desa_id" <?= $filters['kec_id']===''?'disabled':'' ?>>
        <option value="">Semua Desa</option>
        <?php foreach ($opts['desa'] as $o): ?>
          <option value="<?= e($o['value']) ?>" <?= $filters['desa_id']===$o['value']?'selected':'' ?>><?= e($o['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group col-12 col-md-2">
      <button class="btn btn-primary btn-block" type="submit" name="filter" value="1"><i class="fas fa-filter mr-1"></i>Filter</button>
    </div>
  </div>
</form>

<?php if (!$hasFiltered): ?>
  <div class="alert alert-info">Pilih filter lalu klik <strong>Filter</strong> untuk menampilkan Rekap Petugas Weekly.</div>
<?php else: ?>
  <div class="weekly-note">
    <div>Periode <?= e(rekap_weekly_date_label($dateStart)) ?> - <?= e(rekap_weekly_date_label($dateEnd)) ?></div>
    <div class="weekly-note-line">Submit = submit+reject+pending+approve</div>
    <div class="weekly-note-line">Diurutkan berdasarkan kecamatan lalu % Submit Ascending</div>
    <div class="weekly-note-line">Rekap PCL (<?= number_format($petugasSummary['pcl'], 0, ',', '.') ?> petugas), PML (<?= number_format($petugasSummary['pml'], 0, ',', '.') ?> petugas)</div>
    <div class="weekly-progress-legend small">
      <span><i style="background:#dc2626"></i>&lt; 20%</span>
      <span><i style="background:#f59e0b"></i>20% - &lt; 40%</span>
      <span><i style="background:#2563eb"></i>40% - &lt; 75%</span>
      <span><i style="background:#16a34a"></i>75% - 100%</span>
    </div>
  </div>
  <div class="card mb-3">
    <div class="card-body weekly-toolbar">
      <div class="weekly-toolbar-left">
        <div class="font-weight-bold">Total Baris <?= number_format(count($tableRows), 0, ',', '.') ?></div>
        <div class="weekly-page-control">
          <select class="form-control form-control-sm weekly-page-size" id="weeklyPageSize">
            <option value="20" selected>20</option>
            <option value="50">50</option>
            <option value="100">100</option>
            <option value="500">500</option>
            <option value="1000">1000</option>
          </select>
        </div>
      </div>
      <div>
        <?php $exportQuery = array_merge($filters, ['filter' => 1, 'action' => 'export']); ?>
        <a class="btn btn-outline-success btn-sm mr-2" href="?<?= e(http_build_query($exportQuery + ['format' => 'csv'])) ?>"><i class="fas fa-file-csv mr-1"></i>Download CSV</a>
        <a class="btn btn-success btn-sm" href="?<?= e(http_build_query($exportQuery + ['format' => 'xlsx'])) ?>"><i class="fas fa-file-excel mr-1"></i>Download Excel</a>
      </div>
    </div>
  </div>
  <div class="card">
    <div class="card-body table-responsive p-0 weekly-freeze-pane">
      <table class="table table-sm table-bordered table-striped mb-0 weekly-table weekly-freeze-<?= $filters['petugas_type'] === 'pcl' ? 'pcl' : 'pml' ?>">
        <thead>
          <tr>
            <?php foreach ($headers as $i => $header): ?>
              <?php
                if ((string)$header === 'Email Petugas') {
                    continue;
                }
                $isNumericHeader = rekap_weekly_xlsx_header_is_numeric((string)$header);
                $isKabupatenHeader = (string)$header === 'Kabupaten';
                $isSearchHeader = in_array((string)$header, ['Nama Petugas', 'Nama PML'], true);
                $isSortableHeader = $isNumericHeader || $isKabupatenHeader;
              ?>
              <?php
                $headerText = strtolower((string)$header);
                $headerClass = $isNumericHeader ? 'weekly-sortable weekly-compact-number' : 'weekly-identity';
                if ($isKabupatenHeader) {
                    $headerClass .= ' weekly-sortable';
                }
                if (in_array((string)$header, ['Jumlah SubSLS'], true) || str_starts_with((string)$header, 'Total Assignment')) {
                    $headerClass .= ' weekly-head-orange';
                } elseif (str_starts_with((string)$header, 'Total Draft') || str_starts_with((string)$header, '% Draft')) {
                    $headerClass .= ' weekly-head-purple';
                } elseif (str_starts_with((string)$header, 'Total Submit') || str_starts_with((string)$header, '% Submit')) {
                    $headerClass .= ' weekly-head-blue';
                } elseif (str_starts_with((string)$header, 'Submit Tanggal')) {
                    $headerClass .= ' weekly-head-green';
                }
                if (str_contains($headerText, 'email') || str_contains($headerText, 'wilayah kerja') || str_contains($headerText, 'nama pml')) {
                    $headerClass .= ' weekly-wide';
                }
              ?>
              <th class="<?= e($headerClass) ?>" <?= $isSortableHeader ? 'data-sort-col="' . (int)$i . '" data-sort-type="' . ($isNumericHeader ? 'number' : 'text') . '"' : '' ?>>
                <div class="weekly-header-box">
                  <div class="weekly-header-label"><?= rekap_weekly_header_html((string)$header) ?></div>
                  <?php if ($isSortableHeader): ?>
                    <select class="form-control form-control-sm weekly-sort-select" data-sort-col="<?= (int)$i ?>" data-sort-type="<?= $isNumericHeader ? 'number' : 'text' ?>" aria-label="Sort <?= e((string)$header) ?>">
                      <option value="">Sort</option>
                      <option value="asc" <?= $filters['sort_col']===(string)$i && $filters['sort_dir']==='asc' ? 'selected' : '' ?>>Ascending</option>
                      <option value="desc" <?= $filters['sort_col']===(string)$i && $filters['sort_dir']==='desc' ? 'selected' : '' ?>>Descending</option>
                      <option value="clear">Clear</option>
                    </select>
                  <?php elseif ($isSearchHeader): ?>
                    <?php $searchKey = (string)$header === 'Nama PML' ? 'search_pml' : 'search_nama'; ?>
                    <input class="form-control form-control-sm weekly-search-input" type="search" value="<?= e($filters[$searchKey]) ?>" data-weekly-server-search="<?= e($searchKey) ?>" placeholder="Cari nama">
                  <?php else: ?>
                    <span class="weekly-sort-spacer"></span>
                  <?php endif; ?>
                </div>
              </th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php
            $weeklyKecIndex = array_search('Wilayah Kerja Kecamatan', $headers, true);
            $previousWeeklyKecamatan = null;
          ?>
          <?php foreach ($tableRows as $rowIndex => $row): ?>
            <?php
              $currentWeeklyKecamatan = $weeklyKecIndex === false ? '' : (string)($row[$weeklyKecIndex] ?? '');
              $hasWeeklyKecamatanBreak = $showWeeklyKecamatanBreaks && $rowIndex > 0 && $currentWeeklyKecamatan !== $previousWeeklyKecamatan;
              $previousWeeklyKecamatan = $currentWeeklyKecamatan;
            ?>
            <tr class="<?= $hasWeeklyKecamatanBreak ? 'weekly-kecamatan-break' : '' ?>" data-original-index="<?= (int)$rowIndex ?>">
              <?php foreach ($row as $i => $value): ?>
                <?php
                  if ((string)($headers[$i] ?? '') === 'Email Petugas') {
                      continue;
                  }
                  $header = strtolower((string)($headers[$i] ?? ''));
                  $isNumeric = rekap_weekly_xlsx_header_is_numeric($header);
                  $small = str_contains($header, 'email') || str_contains($header, 'wilayah kerja');
                  $smaller = str_contains($header, 'wilayah kerja desa');
                  $isPercent = str_contains($header, '% submit') || str_contains($header, '% draft');
                  $isDraftPercent = str_contains($header, '% draft');
                  $pctValue = $isPercent ? (float)$value : 0.0;
                ?>
                <td class="<?= $isNumeric ? 'text-right' : '' ?> <?= $smaller ? 'weekly-smaller' : ($small ? 'weekly-small' : '') ?>" <?= $isNumeric ? 'data-sort-value="' . e((string)(float)$value) . '"' : '' ?>>
                  <?php if ($isPercent): ?>
                    <span class="<?= e($isDraftPercent ? rekap_weekly_draft_pct_class($pctValue) : rekap_weekly_pct_class($pctValue)) ?>"><?= e(rekap_weekly_pct_web($pctValue)) ?></span>
                  <?php else: ?>
                    <?= $isNumeric ? e(number_format((float)$value, str_contains($header, 'persen') || str_contains($header, '%') ? 2 : 0, ',', '.')) : e((string)$value) ?>
                  <?php endif; ?>
                </td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
          <?php if (!$tableRows): ?>
            <tr><td colspan="<?= max(1, count($headers) - 1) ?>" class="text-center text-muted">Tidak ada data.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <div class="card-footer d-flex justify-content-between align-items-center">
      <button class="btn btn-outline-secondary btn-sm weekly-prev" type="button">Prev</button>
      <span class="small text-muted weekly-page-info"></span>
      <button class="btn btn-outline-secondary btn-sm weekly-next" type="button">Next</button>
    </div>
  </div>
<?php endif; ?>

<script>
document.querySelectorAll('.weekly-table').forEach(function (table) {
  const tbody = table.querySelector('tbody');
  if (!tbody) return;
  const card = table.closest('.card');
  const pageSizeSelect = document.getElementById('weeklyPageSize');
  const prev = card ? card.querySelector('.weekly-prev') : null;
  const next = card ? card.querySelector('.weekly-next') : null;
  const info = card ? card.querySelector('.weekly-page-info') : null;
  let currentPage = 1;
  function dataRows() {
    return Array.from(tbody.querySelectorAll('tr')).filter(function (row) {
      return row.querySelectorAll('td').length > 1;
    });
  }
  function filteredRows() {
    return dataRows();
  }
  function pageSize() {
    return Number((pageSizeSelect && pageSizeSelect.value) || 20);
  }
  function renderPage() {
    const allRows = dataRows();
    const rows = filteredRows();
    const size = pageSize();
    const totalPages = Math.max(1, Math.ceil(rows.length / size));
    currentPage = Math.min(Math.max(1, currentPage), totalPages);
    allRows.forEach(function (row) {
      row.style.display = 'none';
    });
    rows.forEach(function (row, index) {
      row.style.display = index >= (currentPage - 1) * size && index < currentPage * size ? '' : 'none';
    });
    if (info) info.textContent = rows.length ? 'Halaman ' + currentPage + ' dari ' + totalPages + ' (' + rows.length + ' hasil)' : 'Tidak ada data';
    if (prev) prev.disabled = currentPage <= 1;
    if (next) next.disabled = currentPage >= totalPages;
  }
  if (pageSizeSelect) {
    pageSizeSelect.addEventListener('change', function () {
      currentPage = 1;
      renderPage();
    });
  }
  if (prev) {
    prev.addEventListener('click', function () {
      currentPage--;
      renderPage();
    });
  }
  if (next) {
    next.addEventListener('click', function () {
      currentPage++;
      renderPage();
    });
  }
  table.querySelectorAll('.weekly-sort-select').forEach(function (select) {
    select.addEventListener('change', function () {
      const direction = select.value;
      const params = new URLSearchParams(window.location.search);
      params.set('filter', '1');
      if (direction === 'asc' || direction === 'desc') {
        params.set('sort_col', select.dataset.sortCol || '0');
        params.set('sort_dir', direction);
      } else {
        params.delete('sort_col');
        params.delete('sort_dir');
      }
      window.location.search = params.toString();
    });
  });
  renderPage();
});

document.querySelectorAll('[data-weekly-server-search]').forEach(function (input) {
  let timer = null;
  input.addEventListener('input', function () {
    window.clearTimeout(timer);
    timer = window.setTimeout(function () {
      const params = new URLSearchParams(window.location.search);
      params.set('filter', '1');
      document.querySelectorAll('[data-weekly-server-search]').forEach(function (field) {
        const key = field.dataset.weeklyServerSearch;
        const value = (field.value || '').trim();
        if (value) {
          params.set(key, value);
        } else {
          params.delete(key);
        }
      });
      window.location.search = params.toString();
    }, 600);
  });
});
</script>

<?php render_footer(); ?>
