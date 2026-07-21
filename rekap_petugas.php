<?php
require __DIR__ . '/layout.php';
$user = require_role(['superadmin', 'admin_kab', 'viewer_prov', 'viewer_kab']);
$requestedPerPage = (int)($_GET['per_page'] ?? 20);

$filters = [
    'petugas_type' => ($_GET['petugas_type'] ?? 'pml') === 'pcl' ? 'pcl' : 'pml',
    'kab_id' => $_GET['kab_id'] ?? '',
    'kec_id' => $_GET['kec_id'] ?? '',
    'desa_id' => $_GET['desa_id'] ?? '',
    'sort_key' => trim((string)($_GET['sort_key'] ?? '')),
    'sort_dir' => ($_GET['sort_dir'] ?? '') === 'desc' ? 'desc' : (($_GET['sort_dir'] ?? '') === 'asc' ? 'asc' : ''),
    'per_page' => in_array($requestedPerPage, [20, 50, 100], true) ? $requestedPerPage : 20,
];
$rekapSearchKeys = ['search_nama', 'search_kabupaten', 'search_kecamatan', 'search_desa'];
foreach ($rekapSearchKeys as $key) {
    $filters[$key] = trim((string)($_GET[$key] ?? ''));
}
if (in_array($user['role'], ['admin_kab', 'viewer_kab'], true)) {
    $filters['kab_id'] = $user['kab_id'];
}

function rekap_petugas_filter_options(array $user, array $filters): array
{
    $out = ['kabupaten' => [], 'kecamatan' => [], 'desa' => []];
    if (in_array($user['role'], ['admin_kab', 'viewer_kab'], true)) {
        $stmt = db()->prepare("SELECT id value, CONCAT(id,' - ',nmkab) label FROM master_kab WHERE id=? ORDER BY id");
        $stmt->execute([$user['kab_id']]);
    } else {
        $stmt = db()->query("SELECT id value, CONCAT(id,' - ',nmkab) label FROM master_kab ORDER BY id");
    }
    $out['kabupaten'] = $stmt->fetchAll();

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

    return $out;
}

function rekap_petugas_where(array $user, array $filters): array
{
    $where = [];
    $params = [];
    if (in_array($user['role'], ['admin_kab', 'viewer_kab'], true)) {
        $where[] = 'k.id=?';
        $params[] = $user['kab_id'];
    } elseif ($filters['kab_id']) {
        $where[] = 'k.id=?';
        $params[] = $filters['kab_id'];
    }
    if ($filters['kec_id']) {
        $where[] = 'kc.id=?';
        $params[] = $filters['kec_id'];
    }
    if ($filters['desa_id']) {
        $where[] = 'd.id=?';
        $params[] = $filters['desa_id'];
    }
    return [$where ? 'WHERE ' . implode(' AND ', $where) : '', $params];
}

function rekap_petugas_rows(array $user, array $filters): array
{
    [$sqlWhere, $params] = rekap_petugas_where($user, $filters);
    $emailField = $filters['petugas_type'] === 'pcl' ? 'pencacah_email' : 'pengawas_email';
    $where = $sqlWhere
        ? $sqlWhere . " AND ms.$emailField IS NOT NULL AND ms.$emailField <> ''"
        : "WHERE ms.$emailField IS NOT NULL AND ms.$emailField <> ''";

    $stmt = db()->prepare("SELECT
            ms.$emailField email,
            u.name petugas_name,
            MIN(k.id) sort_kab_id,
            MIN(kc.kdkec) sort_kec_code,
            MIN(d.kddesa) sort_desa_code,
            GROUP_CONCAT(DISTINCT CASE
                WHEN up.name IS NULL OR up.name='' OR LOWER(up.name)=LOWER(ms.pengawas_email) THEN ms.pengawas_email
                ELSE up.name
            END ORDER BY up.name, ms.pengawas_email SEPARATOR ', ') pml_names,
            GROUP_CONCAT(DISTINCT ms.pengawas_email ORDER BY ms.pengawas_email SEPARATOR ',') pml_emails,
            GROUP_CONCAT(DISTINCT CONCAT(k.id,' - ',k.nmkab) ORDER BY k.id SEPARATOR ', ') kabupaten,
            GROUP_CONCAT(DISTINCT k.nmkab ORDER BY k.id SEPARATOR ', ') kabupaten_nama,
            GROUP_CONCAT(DISTINCT kc.nmkec ORDER BY k.id, kc.kdkec SEPARATOR ', ') wilayah_kerja_kecamatan,
            GROUP_CONCAT(DISTINCT d.nmdesa ORDER BY kc.kdkec, d.kddesa SEPARATOR ', ') wilayah_kerja,
            COUNT(ms.id) subsls_total,
            COALESCE(SUM(ss.target),0) target,
            COALESCE(SUM(ss.open_count),0) open_count,
            COALESCE(SUM(ss.draft_count),0) draft_count,
            COALESCE(SUM(ss.submitted_by_pencacah),0) submitted_by_pencacah,
            COALESCE(SUM(ss.rejected_by_pengawas),0) rejected_by_pengawas,
            COALESCE(SUM(ss.pending_count),0) pending_count,
            COALESCE(SUM(ss.approved_by_pengawas),0) approved_by_pengawas
        FROM master_subsls ms
        JOIN master_sls sl ON sl.id=ms.sls_id
        JOIN master_desa d ON d.id=sl.desa_id
        JOIN master_kec kc ON kc.id=d.kec_id
        JOIN master_kab k ON k.id=kc.kab_id
        LEFT JOIN users u ON u.email=ms.$emailField
        LEFT JOIN users up ON up.email=ms.pengawas_email
        LEFT JOIN subsls_status ss ON ss.subsls_id=ms.id
        $where
        GROUP BY ms.$emailField, u.name
        ORDER BY sort_kab_id, u.name, ms.$emailField");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function rekap_petugas_pendataan_count(array $row): int
{
    return (int)($row['submitted_by_pencacah'] ?? 0)
        + (int)($row['rejected_by_pengawas'] ?? 0)
        + (int)($row['pending_count'] ?? 0)
        + (int)($row['approved_by_pengawas'] ?? 0);
}

function rekap_petugas_pct_text(int $count, int $target): string
{
    $pct = $target > 0 ? $count / $target * 100 : 0;
    return number_format($pct, 2, ',', '.') . '%';
}

function rekap_petugas_pct_class(float $pct): string
{
    if ($pct < 20) {
        return 'rekap-progress-low';
    }
    if ($pct < 40) {
        return 'rekap-progress-warning';
    }
    if ($pct < 75) {
        return 'rekap-progress-mid';
    }
    return 'rekap-progress-high';
}

function rekap_petugas_draft_pct_class(float $pct): string
{
    if ($pct < 5) {
        return 'rekap-draft-low';
    }
    if ($pct < 10) {
        return 'rekap-draft-warning';
    }
    return 'rekap-draft-high';
}

function rekap_petugas_apply_search(array $rows, array $filters): array
{
    $map = [
        'search_nama' => fn(array $row): string => trim((string)($row['petugas_name'] ?? '')) . ' ' . (string)($row['email'] ?? ''),
        'search_kabupaten' => fn(array $row): string => (string)($row['kabupaten'] ?? '') . ' ' . (string)($row['kabupaten_nama'] ?? ''),
        'search_kecamatan' => fn(array $row): string => (string)($row['wilayah_kerja_kecamatan'] ?? ''),
        'search_desa' => fn(array $row): string => (string)($row['wilayah_kerja'] ?? ''),
    ];
    foreach ($map as $key => $getter) {
        $term = strtolower(trim((string)($filters[$key] ?? '')));
        if ($term === '') {
            continue;
        }
        $rows = array_values(array_filter($rows, function (array $row) use ($getter, $term): bool {
            return str_contains(strtolower($getter($row)), $term);
        }));
    }
    return $rows;
}

function rekap_petugas_progress_count(array $row): int
{
    return (int)($row['submitted_by_pencacah'] ?? 0)
        + (int)($row['rejected_by_pengawas'] ?? 0)
        + (int)($row['pending_count'] ?? 0)
        + (int)($row['approved_by_pengawas'] ?? 0);
}

function rekap_petugas_sort_rows(array $rows, array $filters): array
{
    $sortMap = [
        'subsls_total' => fn(array $row): float => (float)($row['subsls_total'] ?? 0),
        'target' => fn(array $row): float => (float)($row['target'] ?? 0),
        'draft_count' => fn(array $row): float => (float)($row['draft_count'] ?? 0),
        'draft_pct' => fn(array $row): float => (float)($row['target'] ?? 0) > 0 ? (float)($row['draft_count'] ?? 0) / (float)$row['target'] : 0.0,
        'open_count' => fn(array $row): float => (float)($row['open_count'] ?? 0),
        'submitted_by_pencacah' => fn(array $row): float => (float)($row['submitted_by_pencacah'] ?? 0),
        'rejected_by_pengawas' => fn(array $row): float => (float)($row['rejected_by_pengawas'] ?? 0),
        'pending_count' => fn(array $row): float => (float)($row['pending_count'] ?? 0),
        'approved_by_pengawas' => fn(array $row): float => (float)($row['approved_by_pengawas'] ?? 0),
        'approved_pct' => fn(array $row): float => (float)($row['target'] ?? 0) > 0 ? (float)($row['approved_by_pengawas'] ?? 0) / (float)$row['target'] : 0.0,
        'progress_count' => fn(array $row): float => (float)rekap_petugas_progress_count($row),
        'progress_pct' => fn(array $row): float => (float)($row['target'] ?? 0) > 0 ? rekap_petugas_progress_count($row) / (float)$row['target'] : 0.0,
    ];
    $key = (string)($filters['sort_key'] ?? '');
    $dir = (string)($filters['sort_dir'] ?? '');
    if (!isset($sortMap[$key]) || !in_array($dir, ['asc', 'desc'], true)) {
        usort($rows, function (array $a, array $b) use ($filters): int {
            $groupKeys = [];
            if (($filters['kab_id'] ?? '') === '') {
                $groupKeys[] = 'sort_kab_id';
                $groupKeys[] = 'sort_kec_code';
            } elseif (($filters['kec_id'] ?? '') === '') {
                $groupKeys[] = 'sort_kec_code';
            } else {
                $groupKeys[] = 'sort_desa_code';
            }
            foreach ($groupKeys as $groupKey) {
                $cmp = strnatcasecmp((string)($a[$groupKey] ?? ''), (string)($b[$groupKey] ?? ''));
                if ($cmp !== 0) {
                    return $cmp;
                }
            }
            $aTarget = (float)($a['target'] ?? 0);
            $bTarget = (float)($b['target'] ?? 0);
            $aPct = $aTarget > 0 ? rekap_petugas_progress_count($a) / $aTarget : 0.0;
            $bPct = $bTarget > 0 ? rekap_petugas_progress_count($b) / $bTarget : 0.0;
            $cmp = $aPct <=> $bPct;
            if ($cmp !== 0) {
                return $cmp;
            }
            $cmp = strnatcasecmp((string)($a['petugas_name'] ?? ''), (string)($b['petugas_name'] ?? ''));
            if ($cmp !== 0) {
                return $cmp;
            }
            return strnatcasecmp((string)($a['email'] ?? ''), (string)($b['email'] ?? ''));
        });
        return $rows;
    }
    $getter = $sortMap[$key];
    usort($rows, function (array $a, array $b) use ($getter, $dir): int {
        $cmp = $getter($a) <=> $getter($b);
        return $dir === 'asc' ? $cmp : -$cmp;
    });
    return $rows;
}

function rekap_petugas_xlsx_col(int $index): string
{
    $name = '';
    while ($index > 0) {
        $mod = ($index - 1) % 26;
        $name = chr(65 + $mod) . $name;
        $index = intdiv($index - $mod, 26);
    }
    return $name;
}

function rekap_petugas_xlsx_numeric_value($value): ?string
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

function rekap_petugas_xlsx_header_is_numeric(string $header): bool
{
    $header = strtolower($header);
    foreach (['kode', 'id', 'email', 'nama', 'petugas', 'kabupaten', 'kecamatan', 'desa', 'wilayah', 'pml'] as $textPart) {
        if (str_contains($header, $textPart)) {
            return false;
        }
    }
    foreach (['jumlah subsls', 'target', 'open', 'draft', 'submit', 'reject', 'pending', 'approve', 'progress', 'count', 'persen'] as $numericPart) {
        if (str_contains($header, $numericPart)) {
            return true;
        }
    }
    return false;
}

function rekap_petugas_xlsx_pct_style(string $header, $value, int $defaultStyle = 0): int
{
    $header = strtolower($header);
    if (str_contains($header, 'draft') && str_contains($header, 'persen')) {
        $number = rekap_petugas_xlsx_numeric_value($value);
        if ($number === null) {
            return $defaultStyle;
        }
        $pct = (float)$number;
        if ($pct < 5) {
            return 6;
        }
        if ($pct < 10) {
            return 7;
        }
        return 8;
    }
    if (!str_contains($header, 'persen') || (!str_contains($header, 'progress') && !str_contains($header, 'approve'))) {
        return $defaultStyle;
    }
    $number = rekap_petugas_xlsx_numeric_value($value);
    if ($number === null) {
        return $defaultStyle;
    }
    $pct = (float)$number;
    if ($pct < 20) {
        return 2;
    }
    if ($pct < 40) {
        return 3;
    }
    if ($pct < 75) {
        return 4;
    }
    return 5;
}

function rekap_petugas_xlsx_cell($value, int $row, int $col, int $style = 0, bool $numeric = false): string
{
    $ref = rekap_petugas_xlsx_col($col) . $row;
    if ($numeric) {
        $number = rekap_petugas_xlsx_numeric_value($value);
        if ($number !== null) {
            return '<c r="' . $ref . '" s="' . $style . '"><v>' . htmlspecialchars($number, ENT_XML1 | ENT_COMPAT, 'UTF-8') . '</v></c>';
        }
    }
    $value = (string)$value;
    return '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is><t>' . htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8') . '</t></is></c>';
}

function rekap_petugas_export(array $headers, array $rows, string $format, string $type): void
{
    $filename = 'rekap_petugas_' . $type . '_' . date('Ymd');
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

    $tmp = tempnam(sys_get_temp_dir(), 'rekap_petugas_');
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
  <sheets><sheet name="rekap_petugas" sheetId="1" r:id="rId1"/></sheets>
</workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
</Relationships>');
    $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <fonts count="9">
    <font><sz val="11"/><name val="Calibri"/></font>
    <font><sz val="9"/><name val="Calibri"/></font>
    <font><b/><sz val="11"/><color rgb="FFDC2626"/><name val="Calibri"/></font>
    <font><b/><sz val="11"/><color rgb="FFF59E0B"/><name val="Calibri"/></font>
    <font><b/><sz val="11"/><color rgb="FF2563EB"/><name val="Calibri"/></font>
    <font><b/><sz val="11"/><color rgb="FF16A34A"/><name val="Calibri"/></font>
    <font><b/><sz val="11"/><color rgb="FF16A34A"/><name val="Calibri"/></font>
    <font><b/><sz val="11"/><color rgb="FFFB7185"/><name val="Calibri"/></font>
    <font><b/><sz val="11"/><color rgb="FFDC2626"/><name val="Calibri"/></font>
  </fonts>
  <fills count="1"><fill><patternFill patternType="none"/></fill></fills>
  <borders count="1"><border/></borders>
  <cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
  <cellXfs count="9">
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
    <xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0"/>
    <xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0"/>
    <xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0"/>
    <xf numFmtId="0" fontId="4" fillId="0" borderId="0" xfId="0"/>
    <xf numFmtId="0" fontId="5" fillId="0" borderId="0" xfId="0"/>
    <xf numFmtId="0" fontId="6" fillId="0" borderId="0" xfId="0"/>
    <xf numFmtId="0" fontId="7" fillId="0" borderId="0" xfId="0"/>
    <xf numFmtId="0" fontId="8" fillId="0" borderId="0" xfId="0"/>
  </cellXfs>
</styleSheet>');
    $smallFontColumns = [2, $type === 'pcl' ? 6 : 5];
    $sheet = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
    foreach (array_merge([$headers], $rows) as $rIndex => $row) {
        $rowNumber = $rIndex + 1;
        $sheet .= '<row r="' . $rowNumber . '">';
        foreach ($row as $cIndex => $value) {
            $columnNumber = $cIndex + 1;
            $style = $rowNumber > 1 && in_array($columnNumber, $smallFontColumns, true) ? 1 : 0;
            $header = (string)($headers[$cIndex] ?? '');
            $numeric = $rowNumber > 1 && rekap_petugas_xlsx_header_is_numeric($header);
            $style = $rowNumber > 1 ? rekap_petugas_xlsx_pct_style($header, $value, $style) : $style;
            $sheet .= rekap_petugas_xlsx_cell($value, $rowNumber, $columnNumber, $style, $numeric);
        }
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

$opts = rekap_petugas_filter_options($user, $filters);
$fields = [
    'open_count' => 'Open',
    'submitted_by_pencacah' => 'Submit',
    'rejected_by_pengawas' => 'Reject',
    'pending_count' => 'Pending',
    'approved_by_pengawas' => 'Approve',
];
$rows = rekap_petugas_sort_rows(rekap_petugas_apply_search(rekap_petugas_rows($user, $filters), $filters), $filters);
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = $filters['per_page'];
$totalRows = count($rows);
$relatedPmlCount = 0;
if ($filters['petugas_type'] === 'pcl') {
    $relatedPmlEmails = [];
    foreach ($rows as $row) {
        foreach (explode(',', (string)($row['pml_emails'] ?? '')) as $email) {
            $email = normalize_email($email);
            if ($email !== '') {
                $relatedPmlEmails[$email] = true;
            }
        }
    }
    $relatedPmlCount = count($relatedPmlEmails);
}
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page = min($page, $totalPages);
$displayRows = array_slice($rows, ($page - 1) * $perPage, $perPage);
$showKecamatanBreaks = $filters['sort_key'] === ''
    && $filters['sort_dir'] === ''
    && !array_filter($rekapSearchKeys, fn($key) => trim((string)($filters[$key] ?? '')) !== '');

if (($_GET['action'] ?? '') === 'export') {
    $format = ($_GET['format'] ?? 'csv') === 'xlsx' ? 'xlsx' : 'csv';
    $headers = ['Nama Petugas', 'Email Petugas'];
    if ($filters['petugas_type'] === 'pcl') {
        $headers[] = 'Nama PML';
    }
    $headers = array_merge($headers, [
        'Kabupaten',
        'Wilayah Kerja Kecamatan',
        'Wilayah Kerja Desa',
        'Target',
        'Progress Pendataan Count',
        'Progress Pendataan (Persen %)',
        'Draft (Count)',
        'Draft (Persen %)',
        'Approve (Count)',
        'Approve (Persen %)',
        'Open',
        'Submit',
        'Reject',
        'Pending',
        'Jumlah SubSLS',
    ]);
    $exportRows = [];
    foreach ($rows as $r) {
        $target = (int)$r['target'];
        $pendataanCount = rekap_petugas_pendataan_count($r);
        $row = [
            trim((string)($r['petugas_name'] ?? '')) ?: '-',
            $r['email'],
        ];
        if ($filters['petugas_type'] === 'pcl') {
            $row[] = $r['pml_names'] ?: '-';
        }
        $row = array_merge($row, [
            $r['kabupaten_nama'] ?: '-',
            $r['wilayah_kerja_kecamatan'] ?: '-',
            $r['wilayah_kerja'] ?: '-',
            (string)$target,
            number_format($pendataanCount, 0, ',', '.'),
            rekap_petugas_pct_text($pendataanCount, $target),
            number_format((int)$r['draft_count'], 0, ',', '.'),
            rekap_petugas_pct_text((int)$r['draft_count'], $target),
            number_format((int)$r['approved_by_pengawas'], 0, ',', '.'),
            rekap_petugas_pct_text((int)$r['approved_by_pengawas'], $target),
            (string)(int)$r['open_count'],
            (string)(int)$r['submitted_by_pencacah'],
            (string)(int)$r['rejected_by_pengawas'],
            (string)(int)$r['pending_count'],
            (string)(int)$r['subsls_total'],
        ]);
        $exportRows[] = $row;
    }
    rekap_petugas_export($headers, $exportRows, $format, $filters['petugas_type']);
}

render_header('Rekap Petugas');
?>
<style>
  .rekap-info-section {
    background: linear-gradient(90deg, #fff7ed 0%, #fffbeb 100%);
    border-left: 5px solid #f59e0b;
    border-radius: 8px;
    color: #92400e;
    font-weight: 800;
    margin-bottom: 16px;
    padding: 10px 14px;
  }
  .rekap-pct {
    color: #2563eb;
    font-weight: 700;
  }
  .rekap-small-text {
    font-size: 9pt;
  }
  .rekap-header-sub {
    white-space: nowrap;
  }
  .rekap-table th {
    text-align: center;
    vertical-align: bottom !important;
    white-space: nowrap;
  }
  .rekap-freeze-pane {
    max-height: 70vh;
    overflow: auto;
  }
  .rekap-table {
    border-collapse: separate;
    border-spacing: 0;
  }
  .rekap-table thead th {
    background: #f8fafc;
    background-clip: padding-box;
    position: sticky;
    top: 0;
    z-index: 8;
  }
  .rekap-table th:nth-child(1),
  .rekap-table td:nth-child(1) {
    left: 0;
    min-width: 170px;
    width: 170px;
  }
  .rekap-table th:nth-child(2),
  .rekap-table td:nth-child(2) {
    left: 170px;
    min-width: 150px;
    width: 150px;
  }
  .rekap-table th:nth-child(3),
  .rekap-table td:nth-child(3) {
    left: 320px;
    min-width: 130px;
    width: 130px;
  }
  .rekap-table th:nth-child(4),
  .rekap-table td:nth-child(4) {
    left: 450px;
    min-width: 180px;
    width: 180px;
  }
  .rekap-table.rekap-freeze-pcl th:nth-child(5),
  .rekap-table.rekap-freeze-pcl td:nth-child(5) {
    left: 630px;
    min-width: 220px;
    width: 220px;
  }
  .rekap-table.rekap-freeze-pml th:nth-child(4),
  .rekap-table.rekap-freeze-pml td:nth-child(4) {
    left: 450px;
    min-width: 220px;
    width: 220px;
  }
  .rekap-table.rekap-freeze-pml th:nth-child(-n+4),
  .rekap-table.rekap-freeze-pml td:nth-child(-n+4) {
    background-clip: padding-box;
    position: sticky;
    z-index: 7;
  }
  .rekap-table.rekap-freeze-pcl th:nth-child(-n+5),
  .rekap-table.rekap-freeze-pcl td:nth-child(-n+5) {
    background-clip: padding-box;
    position: sticky;
    z-index: 7;
  }
  .rekap-table.rekap-freeze-pml tbody td:nth-child(-n+4),
  .rekap-table.rekap-freeze-pcl tbody td:nth-child(-n+5) {
    background: #fff;
  }
  .rekap-table.rekap-freeze-pml tbody tr:nth-of-type(odd) td:nth-child(-n+4),
  .rekap-table.rekap-freeze-pcl tbody tr:nth-of-type(odd) td:nth-child(-n+5) {
    background: #f9fafb;
  }
  .rekap-table.rekap-freeze-pml tbody tr:hover td:nth-child(-n+4),
  .rekap-table.rekap-freeze-pcl tbody tr:hover td:nth-child(-n+5) {
    background: #eef2ff;
  }
  .rekap-table.rekap-freeze-pml thead th:nth-child(-n+4),
  .rekap-table.rekap-freeze-pcl thead th:nth-child(-n+5) {
    z-index: 10;
  }
  .rekap-table.rekap-freeze-pml th:nth-child(4),
  .rekap-table.rekap-freeze-pml td:nth-child(4),
  .rekap-table.rekap-freeze-pcl th:nth-child(5),
  .rekap-table.rekap-freeze-pcl td:nth-child(5) {
    box-shadow: 3px 0 0 #111827;
  }
  .rekap-kecamatan-break > td {
    border-top: 3px solid #111827 !important;
  }
  .rekap-header-label {
    align-items: center;
    display: flex;
    justify-content: center;
    line-height: 1.08;
    min-height: 48px;
  }
  .rekap-control-spacer {
    display: block;
    height: 29px;
  }
  .rekap-head-blue {
    background: #dbeafe !important;
    color: #1e3a8a;
  }
  .rekap-head-yellow {
    background: #fef3c7 !important;
    color: #78350f;
  }
  .rekap-head-light-green {
    background: #dcfce7 !important;
    color: #14532d;
  }
  .rekap-head-red {
    background: #fee2e2 !important;
    color: #7f1d1d;
  }
  .rekap-head-dark-green {
    background: #bbf7d0 !important;
    color: #064e3b;
  }
  .rekap-search-input,
  .rekap-sort-select {
    border-radius: 4px;
    font-size: .72rem;
    height: 24px;
    margin-top: 5px;
    min-width: 92px;
    padding: 1px 5px;
  }
  .rekap-search-input {
    min-width: 145px;
  }
  .rekap-page-size {
    max-width: 96px;
  }
  .rekap-progress-low {
    color: #dc2626 !important;
    font-weight: 800;
  }
  .rekap-progress-warning {
    color: #f59e0b !important;
    font-weight: 800;
  }
  .rekap-progress-mid {
    color: #2563eb !important;
    font-weight: 800;
  }
  .rekap-progress-high {
    color: #16a34a !important;
    font-weight: 800;
  }
  .rekap-draft-low {
    color: #16a34a !important;
    font-weight: 800;
  }
  .rekap-draft-warning {
    color: #fb7185 !important;
    font-weight: 800;
  }
  .rekap-draft-high {
    color: #dc2626 !important;
    font-weight: 800;
  }
  .rekap-progress-legend {
    align-items: center;
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
  }
  .rekap-progress-legend span {
    align-items: center;
    display: inline-flex;
    gap: 5px;
  }
  .rekap-progress-legend i {
    border-radius: 999px;
    display: inline-block;
    height: 9px;
    width: 9px;
  }
</style>
<form class="card card-body mb-3" method="get">
  <div class="form-row align-items-end">
    <?php foreach ($rekapSearchKeys as $key): ?>
      <input type="hidden" name="<?= e($key) ?>" value="<?= e($filters[$key]) ?>">
    <?php endforeach; ?>
    <input type="hidden" name="per_page" value="<?= (int)$filters['per_page'] ?>">
    <div class="form-group col-12 col-md-2">
      <label>Jenis Petugas</label>
      <select class="form-control" name="petugas_type" id="petugas_type">
        <option value="pml" <?= $filters['petugas_type']==='pml'?'selected':'' ?>>PML</option>
        <option value="pcl" <?= $filters['petugas_type']==='pcl'?'selected':'' ?>>PCL</option>
      </select>
    </div>
    <?php if (in_array($user['role'], ['superadmin', 'viewer_prov'], true)): ?>
      <div class="form-group col-12 col-md-3">
        <label>Kabupaten</label>
        <select class="form-control" name="kab_id" id="kab_id">
          <option value="">Semua Kabupaten</option>
          <?php foreach ($opts['kabupaten'] as $o): ?><option value="<?= e($o['value']) ?>" <?= $filters['kab_id']===$o['value']?'selected':'' ?>><?= e($o['label']) ?></option><?php endforeach; ?>
        </select>
      </div>
    <?php else: ?>
      <input type="hidden" name="kab_id" value="<?= e($filters['kab_id']) ?>">
    <?php endif; ?>
    <div class="form-group col-12 col-md-3">
      <label>Kecamatan</label>
      <select class="form-control" name="kec_id" id="kec_id" <?= $filters['kab_id'] ? '' : 'disabled' ?>>
        <option value=""><?= $filters['kab_id'] ? 'Semua Kecamatan' : 'Pilih kabupaten dulu' ?></option>
        <?php foreach ($opts['kecamatan'] as $o): ?><option value="<?= e($o['value']) ?>" <?= $filters['kec_id']===$o['value']?'selected':'' ?>><?= e($o['label']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="form-group col-12 col-md-3">
      <label>Desa</label>
      <select class="form-control" name="desa_id" id="desa_id" <?= $filters['kec_id'] ? '' : 'disabled' ?>>
        <option value=""><?= $filters['kec_id'] ? 'Semua Desa' : 'Pilih kecamatan dulu' ?></option>
        <?php foreach ($opts['desa'] as $o): ?><option value="<?= e($o['value']) ?>" <?= $filters['desa_id']===$o['value']?'selected':'' ?>><?= e($o['label']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="form-group col-12 col-md-1"><button class="btn btn-primary btn-block">Filter</button></div>
  </div>
</form>
<div class="rekap-info-section">
  <div><em>Progress Pendataan = Submit+Reject+Pending+Approve</em></div>
  <div class="small mt-1">Diurutkan <em>default</em> berdasarkan kecamatan dan % Progress Pendataan Ascending</div>
  <div class="small mt-2 rekap-progress-legend">
    <span><i style="background:#dc2626"></i>&lt; 20%</span>
    <span><i style="background:#f59e0b"></i>20% - &lt; 40%</span>
    <span><i style="background:#2563eb"></i>40% - &lt; 75%</span>
    <span><i style="background:#16a34a"></i>75% - 100%</span>
  </div>
</div>

<div class="card">
  <div class="card-header py-2 d-flex justify-content-between align-items-center">
    <strong>
      Rekap <?= $filters['petugas_type'] === 'pcl' ? 'PCL' : 'PML' ?> (<?= number_format($totalRows, 0, ',', '.') ?> petugas)<?= $filters['petugas_type'] === 'pcl' ? ', PML (' . number_format($relatedPmlCount, 0, ',', '.') . ' petugas)' : '' ?>
    </strong>
    <div>
      <?php
        $exportQuery = [
            'petugas_type' => $filters['petugas_type'],
            'kab_id' => $filters['kab_id'],
            'kec_id' => $filters['kec_id'],
            'desa_id' => $filters['desa_id'],
            'per_page' => $filters['per_page'],
            'action' => 'export',
        ];
        foreach ($rekapSearchKeys as $key) {
            if ($filters[$key] !== '') {
                $exportQuery[$key] = $filters[$key];
            }
        }
        if ($filters['sort_key'] !== '' && $filters['sort_dir'] !== '') {
            $exportQuery['sort_key'] = $filters['sort_key'];
            $exportQuery['sort_dir'] = $filters['sort_dir'];
        }
      ?>
      <a class="btn btn-outline-success btn-sm mr-2" href="?<?= e(http_build_query($exportQuery + ['format' => 'csv'])) ?>"><i class="fas fa-file-csv mr-1"></i>Download CSV</a>
      <a class="btn btn-outline-success btn-sm" href="?<?= e(http_build_query($exportQuery + ['format' => 'xlsx'])) ?>"><i class="fas fa-file-excel mr-1"></i>Download Excel</a>
    </div>
  </div>
  <div class="card-body py-2 border-bottom">
    <form class="form-inline" method="get">
      <?php
        $pageSizeQuery = [
            'petugas_type' => $filters['petugas_type'],
            'kab_id' => $filters['kab_id'],
            'kec_id' => $filters['kec_id'],
            'desa_id' => $filters['desa_id'],
        ];
        foreach ($rekapSearchKeys as $key) {
            $pageSizeQuery[$key] = $filters[$key];
        }
        if ($filters['sort_key'] !== '' && $filters['sort_dir'] !== '') {
            $pageSizeQuery['sort_key'] = $filters['sort_key'];
            $pageSizeQuery['sort_dir'] = $filters['sort_dir'];
        }
      ?>
      <?php foreach ($pageSizeQuery as $key => $value): ?>
        <input type="hidden" name="<?= e((string)$key) ?>" value="<?= e((string)$value) ?>">
      <?php endforeach; ?>
      <label class="mr-2 mb-0">Tampilkan</label>
      <select class="form-control form-control-sm rekap-page-size" name="per_page" onchange="this.form.submit()">
        <?php foreach ([20, 50, 100] as $size): ?>
          <option value="<?= $size ?>" <?= $filters['per_page'] === $size ? 'selected' : '' ?>><?= $size ?></option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>
  <div class="card-body table-responsive p-0 rekap-freeze-pane">
    <table class="table table-sm table-bordered table-striped mb-0 rekap-table rekap-freeze-<?= $filters['petugas_type'] === 'pcl' ? 'pcl' : 'pml' ?>" id="rekapPetugasTable">
      <thead>
        <tr>
          <th><div class="rekap-header-label">Nama Petugas</div><input class="form-control form-control-sm rekap-search-input" type="search" placeholder="Cari nama" value="<?= e($filters['search_nama']) ?>" data-rekap-server-search="search_nama"></th>
          <?php if ($filters['petugas_type'] === 'pcl'): ?><th><div class="rekap-header-label">Nama PML</div><span class="rekap-control-spacer"></span></th><?php endif; ?>
          <th><div class="rekap-header-label">Kabupaten</div><input class="form-control form-control-sm rekap-search-input" type="search" placeholder="Cari kab" value="<?= e($filters['search_kabupaten']) ?>" data-rekap-server-search="search_kabupaten"></th>
          <th><div class="rekap-header-label">Wilayah<br>Kerja<br>Kecamatan</div><input class="form-control form-control-sm rekap-search-input" type="search" placeholder="Cari kec" value="<?= e($filters['search_kecamatan']) ?>" data-rekap-server-search="search_kecamatan"></th>
          <th><div class="rekap-header-label">Wilayah<br>Kerja<br>Desa</div><input class="form-control form-control-sm rekap-search-input" type="search" placeholder="Cari desa" value="<?= e($filters['search_desa']) ?>" data-rekap-server-search="search_desa"></th>
          <?php
            $numericHeaders = [
                ['label' => 'Target', 'class' => 'rekap-head-blue', 'key' => 'target'],
                ['label' => 'Progress<br>Pendataan<br>(Count)', 'class' => 'rekap-head-light-green', 'key' => 'progress_count'],
                ['label' => 'Progress<br>Pendataan<br>(Persen %)', 'class' => 'rekap-head-light-green', 'key' => 'progress_pct'],
                ['label' => 'Draft<br>(Count)', 'class' => 'rekap-head-yellow', 'key' => 'draft_count'],
                ['label' => 'Draft<br>(Persen %)', 'class' => 'rekap-head-yellow', 'key' => 'draft_pct'],
                ['label' => 'Approve<br>(Count)', 'class' => 'rekap-head-dark-green', 'key' => 'approved_by_pengawas'],
                ['label' => 'Approve<br>(Persen %)', 'class' => 'rekap-head-dark-green', 'key' => 'approved_pct'],
                ['label' => 'Open', 'class' => 'rekap-head-blue', 'key' => 'open_count'],
                ['label' => 'Submit', 'class' => 'rekap-head-light-green', 'key' => 'submitted_by_pencacah'],
                ['label' => 'Reject', 'class' => 'rekap-head-red', 'key' => 'rejected_by_pengawas'],
                ['label' => 'Pending', 'class' => 'rekap-head-red', 'key' => 'pending_count'],
                ['label' => 'Jumlah<br>SubSLS', 'class' => 'rekap-head-blue', 'key' => 'subsls_total'],
            ];
          ?>
          <?php foreach ($numericHeaders as $i => $header): ?>
            <th class="text-right <?= e($header['class']) ?>">
              <div class="rekap-header-label"><?= $header['label'] ?></div>
              <select class="form-control form-control-sm rekap-sort-select" data-rekap-sort-key="<?= e($header['key']) ?>">
                <option value="">Sort</option>
                <option value="asc" <?= $filters['sort_key']===$header['key'] && $filters['sort_dir']==='asc' ? 'selected' : '' ?>>Ascending</option>
                <option value="desc" <?= $filters['sort_key']===$header['key'] && $filters['sort_dir']==='desc' ? 'selected' : '' ?>>Descending</option>
                <option value="clear">Clear</option>
              </select>
            </th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <?php $previousKecamatan = null; ?>
        <?php foreach ($displayRows as $rowIndex => $r): ?>
          <?php
            $rowTarget = (int)$r['target'];
            $draftPct = $rowTarget > 0 ? (int)$r['draft_count'] / $rowTarget * 100 : 0;
            $pendataanCount = rekap_petugas_pendataan_count($r);
            $pendataanPct = $rowTarget > 0 ? $pendataanCount / $rowTarget * 100 : 0;
            $currentKecamatan = (string)($r['wilayah_kerja_kecamatan'] ?? '');
            $hasKecamatanBreak = $showKecamatanBreaks && $rowIndex > 0 && $currentKecamatan !== $previousKecamatan;
            $previousKecamatan = $currentKecamatan;
          ?>
          <tr class="<?= $hasKecamatanBreak ? 'rekap-kecamatan-break' : '' ?>" data-original-index="<?= (int)$rowIndex ?>">
            <td><?= e(trim((string)($r['petugas_name'] ?? '')) ?: '-') ?></td>
            <?php if ($filters['petugas_type'] === 'pcl'): ?><td><?= e($r['pml_names'] ?: '-') ?></td><?php endif; ?>
            <td><?= e($r['kabupaten_nama'] ?: '-') ?></td>
            <td><?= e($r['wilayah_kerja_kecamatan'] ?: '-') ?></td>
            <td class="rekap-small-text"><?= e($r['wilayah_kerja'] ?: '-') ?></td>
            <td class="text-right" data-sort-value="<?= $rowTarget ?>"><?= number_format($rowTarget, 0, ',', '.') ?></td>
            <td class="text-right" data-sort-value="<?= $pendataanCount ?>"><?= number_format($pendataanCount, 0, ',', '.') ?></td>
            <td class="text-right rekap-pct <?= e(rekap_petugas_pct_class($pendataanPct)) ?>" data-sort-value="<?= e((string)$pendataanPct) ?>"><?= e(rekap_petugas_pct_text($pendataanCount, $rowTarget)) ?></td>
            <td class="text-right" data-sort-value="<?= (int)$r['draft_count'] ?>"><?= number_format((int)$r['draft_count'], 0, ',', '.') ?></td>
            <td class="text-right rekap-pct <?= e(rekap_petugas_draft_pct_class($draftPct)) ?>" data-sort-value="<?= e((string)$draftPct) ?>"><?= e(rekap_petugas_pct_text((int)$r['draft_count'], $rowTarget)) ?></td>
            <td class="text-right" data-sort-value="<?= (int)$r['approved_by_pengawas'] ?>"><?= number_format((int)$r['approved_by_pengawas'], 0, ',', '.') ?></td>
            <?php $approvePct = $rowTarget > 0 ? (int)$r['approved_by_pengawas'] / $rowTarget * 100 : 0; ?>
            <td class="text-right rekap-pct <?= e(rekap_petugas_pct_class($approvePct)) ?>" data-sort-value="<?= e((string)$approvePct) ?>"><?= e(rekap_petugas_pct_text((int)$r['approved_by_pengawas'], $rowTarget)) ?></td>
            <td class="text-right" data-sort-value="<?= (int)$r['open_count'] ?>"><?= number_format((int)$r['open_count'], 0, ',', '.') ?></td>
            <td class="text-right" data-sort-value="<?= (int)$r['submitted_by_pencacah'] ?>"><?= number_format((int)$r['submitted_by_pencacah'], 0, ',', '.') ?></td>
            <td class="text-right" data-sort-value="<?= (int)$r['rejected_by_pengawas'] ?>"><?= number_format((int)$r['rejected_by_pengawas'], 0, ',', '.') ?></td>
            <td class="text-right" data-sort-value="<?= (int)$r['pending_count'] ?>"><?= number_format((int)$r['pending_count'], 0, ',', '.') ?></td>
            <td class="text-right" data-sort-value="<?= (int)$r['subsls_total'] ?>"><?= number_format((int)$r['subsls_total'], 0, ',', '.') ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$displayRows): ?>
          <tr><td colspan="<?= 16 + ($filters['petugas_type'] === 'pcl' ? 1 : 0) ?>" class="text-center text-muted">Tidak ada data petugas pada filter ini.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php if ($totalPages > 1): ?>
  <nav class="mt-3">
    <ul class="pagination pagination-sm">
      <?php
        $baseQuery = [
            'petugas_type' => $filters['petugas_type'],
            'kab_id' => $filters['kab_id'],
            'kec_id' => $filters['kec_id'],
            'desa_id' => $filters['desa_id'],
            'per_page' => $filters['per_page'],
        ];
        foreach ($rekapSearchKeys as $key) {
            if ($filters[$key] !== '') {
                $baseQuery[$key] = $filters[$key];
            }
        }
        if ($filters['sort_key'] !== '' && $filters['sort_dir'] !== '') {
            $baseQuery['sort_key'] = $filters['sort_key'];
            $baseQuery['sort_dir'] = $filters['sort_dir'];
        }
        $start = max(1, $page - 2);
        $end = min($totalPages, $page + 2);
      ?>
      <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="?<?= e(http_build_query($baseQuery + ['page' => max(1, $page - 1)])) ?>">Prev</a></li>
      <?php for ($p = $start; $p <= $end; $p++): ?>
        <li class="page-item <?= $p === $page ? 'active' : '' ?>"><a class="page-link" href="?<?= e(http_build_query($baseQuery + ['page' => $p])) ?>"><?= $p ?></a></li>
      <?php endfor; ?>
      <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>"><a class="page-link" href="?<?= e(http_build_query($baseQuery + ['page' => min($totalPages, $page + 1)])) ?>">Next</a></li>
    </ul>
  </nav>
<?php endif; ?>

<script>
const petugasType = document.getElementById('petugas_type');
if (petugasType) {
  petugasType.addEventListener('change', function () {
    this.form.submit();
  });
}
const kabupaten = document.getElementById('kab_id');
if (kabupaten) {
  kabupaten.addEventListener('change', function () {
    const kec = document.getElementById('kec_id');
    const desa = document.getElementById('desa_id');
    if (kec) kec.value = '';
    if (desa) desa.value = '';
    this.form.submit();
  });
}
const kecamatan = document.getElementById('kec_id');
if (kecamatan) {
  kecamatan.addEventListener('change', function () {
    const desa = document.getElementById('desa_id');
    if (desa) desa.value = '';
    this.form.submit();
  });
}
const desa = document.getElementById('desa_id');
if (desa) {
  desa.addEventListener('change', function () {
    this.form.submit();
  });
}

document.querySelectorAll('#rekapPetugasTable').forEach(function (table) {
  const sortSelects = Array.from(table.querySelectorAll('[data-rekap-sort-key]'));

  sortSelects.forEach(function (select) {
    select.addEventListener('change', function () {
      const direction = select.value;
      const params = new URLSearchParams(window.location.search);
      params.delete('page');
      if (direction === 'asc' || direction === 'desc') {
        params.set('sort_key', select.dataset.rekapSortKey || '');
        params.set('sort_dir', direction);
      } else {
        params.delete('sort_key');
        params.delete('sort_dir');
      }
      window.location.search = params.toString();
    });
  });
});

document.querySelectorAll('[data-rekap-server-search]').forEach(function (input) {
  let timer = null;
  input.addEventListener('input', function () {
    window.clearTimeout(timer);
    timer = window.setTimeout(function () {
      const params = new URLSearchParams(window.location.search);
      params.delete('page');
      document.querySelectorAll('[data-rekap-server-search]').forEach(function (field) {
        const key = field.dataset.rekapServerSearch;
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
