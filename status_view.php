<?php
require __DIR__ . '/layout.php';
$user = require_role(['superadmin', 'admin_kab', 'viewer_prov', 'viewer_kab']);
$requestedPerPage = (int)($_GET['per_page'] ?? 20);
$filters = [
    'kab_id' => $_GET['kab_id'] ?? '',
    'kec_id' => $_GET['kec_id'] ?? '',
    'desa_id' => $_GET['desa_id'] ?? '',
    'view_mode' => ($_GET['view_mode'] ?? 'card') === 'table' ? 'table' : 'card',
    'card_sort' => ($_GET['card_sort'] ?? 'desc') === 'asc' ? 'asc' : 'desc',
    'sort_key' => trim((string)($_GET['sort_key'] ?? '')),
    'sort_dir' => ($_GET['sort_dir'] ?? '') === 'desc' ? 'desc' : (($_GET['sort_dir'] ?? '') === 'asc' ? 'asc' : ''),
    'per_page' => in_array($requestedPerPage, [20, 50, 100], true) ? $requestedPerPage : 20,
];
$statusSearchKeys = ['search_kode', 'search_desa', 'search_pengawas', 'search_pencacah'];
foreach ($statusSearchKeys as $key) {
    $filters[$key] = trim((string)($_GET[$key] ?? ''));
}
if (in_array($user['role'], ['admin_kab', 'viewer_kab'], true)) {
    $filters['kab_id'] = $user['kab_id'];
}

function status_filter_options(array $user, array $filters): array
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

$opts = status_filter_options($user, $filters);
$rows = [];
$pmlCards = [];
$pclCards = [];
$error = null;
$statusFields = status_fields();
$fields = [
    'open_count' => $statusFields['open_count'],
    'submitted_by_pencacah' => $statusFields['submitted_by_pencacah'],
    'rejected_by_pengawas' => $statusFields['rejected_by_pengawas'],
    'pending_count' => $statusFields['pending_count'],
    'approved_by_pengawas' => $statusFields['approved_by_pengawas'],
];
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = $filters['per_page'];
$totalRows = 0;
$totalPages = 0;
$statusPetugasSummary = ['pcl' => 0, 'pml' => 0];
$requiresSpecificKab = in_array($user['role'], ['superadmin', 'viewer_prov'], true);
$isAllKabCardTooLarge = $requiresSpecificKab && $filters['kab_id'] === '' && $filters['view_mode'] === 'card';
$canShowStatus = true;

function status_view_build_filter(array $user, array $filters): array
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
    $searchExpressions = [
        'search_kode' => "CONCAT(k.id, kc.kdkec, d.kddesa, sl.kdsls, ms.kdsubsls)",
        'search_desa' => "d.nmdesa",
        'search_pengawas' => "CONCAT(COALESCE(up.name, ''), ' ', COALESCE(ms.pengawas_email, ''))",
        'search_pencacah' => "CONCAT(COALESCE(uc.name, ''), ' ', COALESCE(ms.pencacah_email, ''))",
    ];
    foreach ($searchExpressions as $key => $expr) {
        if (($filters[$key] ?? '') !== '') {
            $where[] = $expr . ' LIKE ?';
            $params[] = '%' . $filters[$key] . '%';
        }
    }
    return [$where ? 'WHERE ' . implode(' AND ', $where) : '', $params];
}

function status_view_order_clause(array $filters): string
{
    $sortMap = [
        'target' => 'COALESCE(ss.target,0)',
        'draft_count' => 'COALESCE(ss.draft_count,0)',
        'draft_pct' => 'CASE WHEN COALESCE(ss.target,0)>0 THEN COALESCE(ss.draft_count,0)/COALESCE(ss.target,0) ELSE 0 END',
        'open_count' => 'COALESCE(ss.open_count,0)',
        'submitted_by_pencacah' => 'COALESCE(ss.submitted_by_pencacah,0)',
        'rejected_by_pengawas' => 'COALESCE(ss.rejected_by_pengawas,0)',
        'pending_count' => 'COALESCE(ss.pending_count,0)',
        'approved_by_pengawas' => 'COALESCE(ss.approved_by_pengawas,0)',
        'approved_pct' => 'CASE WHEN COALESCE(ss.target,0)>0 THEN COALESCE(ss.approved_by_pengawas,0)/COALESCE(ss.target,0) ELSE 0 END',
        'progress_count' => '(COALESCE(ss.submitted_by_pencacah,0)+COALESCE(ss.rejected_by_pengawas,0)+COALESCE(ss.pending_count,0)+COALESCE(ss.approved_by_pengawas,0))',
        'progress_pct' => 'CASE WHEN COALESCE(ss.target,0)>0 THEN (COALESCE(ss.submitted_by_pencacah,0)+COALESCE(ss.rejected_by_pengawas,0)+COALESCE(ss.pending_count,0)+COALESCE(ss.approved_by_pengawas,0))/COALESCE(ss.target,0) ELSE 0 END',
    ];
    $key = (string)($filters['sort_key'] ?? '');
    $dir = (string)($filters['sort_dir'] ?? '');
    if (!isset($sortMap[$key]) || !in_array($dir, ['asc', 'desc'], true)) {
        return 'ORDER BY k.id, kc.kdkec, d.kddesa, sl.kdsls, ms.kdsubsls';
    }
    return 'ORDER BY ' . $sortMap[$key] . ' ' . strtoupper($dir) . ', k.id, kc.kdkec, d.kddesa, sl.kdsls, ms.kdsubsls';
}

function status_view_select_sql(string $sqlWhere, ?int $limitRows = null, ?int $offsetRows = null, ?array $filters = null): string
{
    $limit = '';
    if ($limitRows !== null && $offsetRows !== null) {
        $limit = 'LIMIT ' . max(1, $limitRows) . ' OFFSET ' . max(0, $offsetRows);
    }
    $orderClause = status_view_order_clause($filters ?? []);
    return "SELECT k.id kab_id, k.nmkab, kc.kdkec, kc.nmkec, d.kddesa, d.nmdesa,
                sl.kdsls, sl.nmsls, ms.kdsubsls, ms.nmsubsls,
                ms.pengawas_email, ms.pencacah_email,
                up.name pengawas_name, uc.name pencacah_name,
                COALESCE(ss.target,0) target,
                COALESCE(ss.open_count,0) open_count,
                COALESCE(ss.draft_count,0) draft_count,
                COALESCE(ss.submitted_by_pencacah,0) submitted_by_pencacah,
                COALESCE(ss.approved_by_pengawas,0) approved_by_pengawas,
                COALESCE(ss.rejected_by_pengawas,0) rejected_by_pengawas,
                COALESCE(ss.pending_count,0) pending_count,
                ss.last_update, ss.updated_by,
                COALESCE(cs.status_selesai, 'Belum Selesai') status_selesai
            FROM master_subsls ms
            JOIN master_sls sl ON sl.id=ms.sls_id
            JOIN master_desa d ON d.id=sl.desa_id
            JOIN master_kec kc ON kc.id=d.kec_id
            JOIN master_kab k ON k.id=kc.kab_id
            LEFT JOIN users up ON up.email=ms.pengawas_email
            LEFT JOIN users uc ON uc.email=ms.pencacah_email
            LEFT JOIN subsls_status ss ON ss.subsls_id=ms.id
            LEFT JOIN subsls_completion_status cs ON cs.subsls_id=ms.id
            $sqlWhere
            $orderClause
            $limit";
}

function status_view_progress_count(array $row): int
{
    return (int)$row['submitted_by_pencacah'] + (int)$row['rejected_by_pengawas'] + (int)$row['pending_count'] + (int)$row['approved_by_pengawas'];
}

function status_view_progress_pct(array $row): float
{
    $target = (int)$row['target'];
    return $target > 0 ? (status_view_progress_count($row) / $target) * 100 : 0.0;
}

function status_view_field_pct(array $row, string $field): float
{
    $target = (int)$row['target'];
    return $target > 0 ? ((int)$row[$field] / $target) * 100 : 0.0;
}

function status_view_progress_pct_class(float $pct): string
{
    if ($pct < 20) {
        return 'status-progress-low';
    }
    if ($pct < 40) {
        return 'status-progress-warning';
    }
    if ($pct < 75) {
        return 'status-progress-mid';
    }
    return 'status-progress-high';
}

function status_view_draft_pct_class(float $pct): string
{
    if ($pct < 5) {
        return 'status-draft-low';
    }
    if ($pct < 10) {
        return 'status-draft-warning';
    }
    return 'status-draft-high';
}

function status_view_card_where(string $sqlWhere, string $emailField): string
{
    $extra = "ms.{$emailField} IS NOT NULL AND ms.{$emailField} <> ''";
    return $sqlWhere ? $sqlWhere . ' AND ' . $extra : 'WHERE ' . $extra;
}

function status_view_card_rows(array $user, array $filters, string $type): array
{
    [$sqlWhere, $params] = status_view_build_filter($user, $filters);
    $sortDirection = ($filters['card_sort'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
    if ($type === 'pml') {
        $where = status_view_card_where($sqlWhere, 'pengawas_email');
        $sql = "SELECT ms.pengawas_email email, up.name petugas_name,
                    COUNT(DISTINCT NULLIF(ms.pencacah_email,'')) pcl_count,
                    COUNT(ms.id) subsls_count,
                    GROUP_CONCAT(DISTINCT CASE
                        WHEN uc.name IS NULL OR uc.name='' OR LOWER(uc.name)=LOWER(ms.pencacah_email) THEN ms.pencacah_email
                        ELSE CONCAT(uc.name, ' ', ms.pencacah_email)
                    END ORDER BY uc.name, ms.pencacah_email SEPARATOR ' ') pencacah_names,
                    GROUP_CONCAT(DISTINCT d.nmdesa ORDER BY kc.kdkec, d.kddesa SEPARATOR ', ') desa_names,
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
                LEFT JOIN users up ON up.email=ms.pengawas_email
                LEFT JOIN users uc ON uc.email=ms.pencacah_email
                LEFT JOIN subsls_status ss ON ss.subsls_id=ms.id
                $where
                GROUP BY ms.pengawas_email, up.name
                ORDER BY CASE WHEN COALESCE(SUM(ss.target),0) > 0
                    THEN ((COALESCE(SUM(ss.submitted_by_pencacah),0) + COALESCE(SUM(ss.rejected_by_pengawas),0) + COALESCE(SUM(ss.pending_count),0) + COALESCE(SUM(ss.approved_by_pengawas),0)) / COALESCE(SUM(ss.target),0))
                    ELSE 0 END $sortDirection, up.name, ms.pengawas_email";
    } else {
        $where = status_view_card_where($sqlWhere, 'pencacah_email');
        $sql = "SELECT ms.pencacah_email email, uc.name petugas_name,
                    ms.pengawas_email pengawas_email, up.name pengawas_name,
                    1 pcl_count,
                    COUNT(ms.id) subsls_count,
                    GROUP_CONCAT(DISTINCT d.nmdesa ORDER BY kc.kdkec, d.kddesa SEPARATOR ', ') desa_names,
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
                LEFT JOIN users uc ON uc.email=ms.pencacah_email
                LEFT JOIN users up ON up.email=ms.pengawas_email
                LEFT JOIN subsls_status ss ON ss.subsls_id=ms.id
                $where
                GROUP BY ms.pencacah_email, uc.name, ms.pengawas_email, up.name
                ORDER BY CASE WHEN COALESCE(SUM(ss.target),0) > 0
                    THEN ((COALESCE(SUM(ss.submitted_by_pencacah),0) + COALESCE(SUM(ss.rejected_by_pengawas),0) + COALESCE(SUM(ss.pending_count),0) + COALESCE(SUM(ss.approved_by_pengawas),0)) / COALESCE(SUM(ss.target),0))
                    ELSE 0 END $sortDirection, uc.name, ms.pencacah_email";
    }
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function status_view_card_title(array $row, string $type): string
{
    $label = petugas_label($row['email'], $row['petugas_name'] ?? '');
    if ($type === 'pcl') {
        $label .= ' (' . petugas_label($row['pengawas_email'] ?? '', $row['pengawas_name'] ?? '') . ')';
    }
    return $label;
}

function status_view_card_subtitle(array $row): string
{
    return '(' . petugas_label($row['pengawas_email'] ?? '', $row['pengawas_name'] ?? '') . ')';
}

function status_view_card_section_sort_url(array $filters, string $sort): string
{
    return '?' . http_build_query([
        'filter' => 1,
        'kab_id' => $filters['kab_id'],
        'kec_id' => $filters['kec_id'],
        'desa_id' => $filters['desa_id'],
        'view_mode' => 'card',
        'card_sort' => $sort,
    ]);
}

function status_view_xlsx_col(int $index): string
{
    $name = '';
    while ($index > 0) {
        $mod = ($index - 1) % 26;
        $name = chr(65 + $mod) . $name;
        $index = intdiv($index - $mod, 26);
    }
    return $name;
}

function status_view_xlsx_numeric_value($value): ?string
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

function status_view_xlsx_header_is_numeric(string $header): bool
{
    $header = strtolower($header);
    foreach (['kode', 'id', 'email', 'nama', 'pengawas', 'pencacah', 'kabupaten', 'kecamatan', 'desa', 'sls', 'updated', 'last', 'status selesai'] as $textPart) {
        if (str_contains($header, $textPart)) {
            return false;
        }
    }
    foreach (['target', 'open', 'draft', 'submit', 'reject', 'pending', 'approve', 'approved', 'progress', 'count', 'persen'] as $numericPart) {
        if (str_contains($header, $numericPart)) {
            return true;
        }
    }
    return false;
}

function status_view_xlsx_pct_style(string $header, $value): int
{
    $header = strtolower($header);
    if (str_contains($header, 'draft') && str_contains($header, 'persen')) {
        $number = status_view_xlsx_numeric_value($value);
        if ($number === null) {
            return 0;
        }
        $pct = (float)$number;
        if ($pct < 5) {
            return 5;
        }
        if ($pct < 10) {
            return 6;
        }
        return 7;
    }
    if (!str_contains($header, 'persen') || (!str_contains($header, 'progress') && !str_contains($header, 'approved'))) {
        return 0;
    }
    $number = status_view_xlsx_numeric_value($value);
    if ($number === null) {
        return 0;
    }
    $pct = (float)$number;
    if ($pct < 20) {
        return 1;
    }
    if ($pct < 40) {
        return 2;
    }
    if ($pct < 75) {
        return 3;
    }
    return 4;
}

function status_view_xlsx_cell($value, int $row, int $col, int $style = 0, bool $numeric = false): string
{
    $ref = status_view_xlsx_col($col) . $row;
    if ($numeric) {
        $number = status_view_xlsx_numeric_value($value);
        if ($number !== null) {
            return '<c r="' . $ref . '" s="' . $style . '"><v>' . htmlspecialchars($number, ENT_XML1 | ENT_COMPAT, 'UTF-8') . '</v></c>';
        }
    }
    $value = (string)$value;
    return '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is><t>' . htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8') . '</t></is></c>';
}

function status_view_export(array $headers, array $rows, string $format): void
{
    $filename = 'status_terupdate_' . date('Ymd');
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

    $tmp = tempnam(sys_get_temp_dir(), 'status_export_');
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
  <sheets><sheet name="status_terupdate" sheetId="1" r:id="rId1"/></sheets>
</workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
</Relationships>');
    $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <fonts count="8">
    <font><sz val="11"/><name val="Calibri"/></font>
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
  <cellXfs count="8">
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
    <xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0"/>
    <xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0"/>
    <xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0"/>
    <xf numFmtId="0" fontId="4" fillId="0" borderId="0" xfId="0"/>
    <xf numFmtId="0" fontId="5" fillId="0" borderId="0" xfId="0"/>
    <xf numFmtId="0" fontId="6" fillId="0" borderId="0" xfId="0"/>
    <xf numFmtId="0" fontId="7" fillId="0" borderId="0" xfId="0"/>
  </cellXfs>
</styleSheet>');
    $sheet = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
    $allRows = array_merge([$headers], $rows);
    foreach ($allRows as $rIndex => $row) {
        $rowNumber = $rIndex + 1;
        $sheet .= '<row r="' . $rowNumber . '">';
        foreach ($row as $cIndex => $value) {
            $header = (string)($headers[$cIndex] ?? '');
            $numeric = $rowNumber > 1 && status_view_xlsx_header_is_numeric($header);
            $style = $rowNumber > 1 ? status_view_xlsx_pct_style($header, $value) : 0;
            $sheet .= status_view_xlsx_cell($value, $rowNumber, $cIndex + 1, $style, $numeric);
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

if (($_GET['action'] ?? '') === 'export' && isset($_GET['filter'])) {
    if (!$canShowStatus) {
        http_response_code(400);
        exit('Pilih salah satu kabupaten terlebih dahulu.');
    }
    $format = ($_GET['format'] ?? 'csv') === 'xlsx' ? 'xlsx' : 'csv';
    [$sqlWhere, $params] = status_view_build_filter($user, $filters);
    $stmt = db()->prepare(status_view_select_sql($sqlWhere, null, null, $filters));
    $stmt->execute($params);
    $exportSource = $stmt->fetchAll();
    $headers = [
        'Kode SubSLS',
        'Desa',
        'SLS',
        'SubSLS',
        'Nama Pengawas',
        'Email Pengawas',
        'Nama Pencacah',
        'Email Pencacah',
        'Target',
        'Progress (Count)',
        'Progress (Persen %)',
        'Approved (Count)',
        'Approved (Persen %)',
        'Draft (Count)',
        'Draft (Persen %)',
        'Open',
        'Submit',
        'Reject',
        'Pending',
        'Last Update',
        'Update By',
        'Status Selesai',
    ];
    $exportRows = [];
    foreach ($exportSource as $r) {
        $row = [
            $r['kab_id'] . $r['kdkec'] . $r['kddesa'] . $r['kdsls'] . $r['kdsubsls'],
            $r['nmdesa'],
            $r['nmsls'],
            $r['kdsubsls'],
            trim((string)($r['pengawas_name'] ?? '')) ?: '-',
            $r['pengawas_email'],
            trim((string)($r['pencacah_name'] ?? '')) ?: '-',
            $r['pencacah_email'],
            (string)(int)$r['target'],
            (string)status_view_progress_count($r),
            number_format(status_view_progress_pct($r), 2, ',', '.') . '%',
            (string)(int)$r['approved_by_pengawas'],
            number_format(status_view_field_pct($r, 'approved_by_pengawas'), 2, ',', '.') . '%',
            (string)(int)$r['draft_count'],
            number_format(status_view_field_pct($r, 'draft_count'), 2, ',', '.') . '%',
            (string)(int)$r['open_count'],
            (string)(int)$r['submitted_by_pencacah'],
            (string)(int)$r['rejected_by_pengawas'],
            (string)(int)$r['pending_count'],
            $r['last_update'] ?: '',
            $r['updated_by'] ?: '',
            $r['status_selesai'],
        ];
        $exportRows[] = $row;
    }
    status_view_export($headers, $exportRows, $format);
}

if ($canShowStatus && $filters['view_mode'] === 'card' && !$isAllKabCardTooLarge) {
    $pmlCards = status_view_card_rows($user, $filters, 'pml');
    $pclCards = status_view_card_rows($user, $filters, 'pcl');
}

if ($canShowStatus && $filters['view_mode'] === 'table') {
    [$sqlWhere, $params] = status_view_build_filter($user, $filters);
    $countStmt = db()->prepare("SELECT COUNT(*)
        FROM master_subsls ms
        JOIN master_sls sl ON sl.id=ms.sls_id
        JOIN master_desa d ON d.id=sl.desa_id
        JOIN master_kec kc ON kc.id=d.kec_id
        JOIN master_kab k ON k.id=kc.kab_id
        LEFT JOIN users up ON up.email=ms.pengawas_email
        LEFT JOIN users uc ON uc.email=ms.pencacah_email
        $sqlWhere");
    $countStmt->execute($params);
    $totalRows = (int)$countStmt->fetchColumn();
    $summaryStmt = db()->prepare("SELECT
            COUNT(DISTINCT NULLIF(ms.pencacah_email, '')) pcl_count,
            COUNT(DISTINCT NULLIF(ms.pengawas_email, '')) pml_count
        FROM master_subsls ms
        JOIN master_sls sl ON sl.id=ms.sls_id
        JOIN master_desa d ON d.id=sl.desa_id
        JOIN master_kec kc ON kc.id=d.kec_id
        JOIN master_kab k ON k.id=kc.kab_id
        LEFT JOIN users up ON up.email=ms.pengawas_email
        LEFT JOIN users uc ON uc.email=ms.pencacah_email
        $sqlWhere");
    $summaryStmt->execute($params);
    $summary = $summaryStmt->fetch() ?: [];
    $statusPetugasSummary = [
        'pcl' => (int)($summary['pcl_count'] ?? 0),
        'pml' => (int)($summary['pml_count'] ?? 0),
    ];
    $totalPages = max(1, (int)ceil($totalRows / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    $stmt = db()->prepare(status_view_select_sql($sqlWhere, $perPage, $offset, $filters));
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
}

render_header('Status Terupdate');
?>
<style>
.status-view-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
  gap: 14px;
}
.status-info-section {
  background: linear-gradient(90deg, #fff7ed 0%, #fffbeb 100%);
  border-left: 5px solid #f59e0b;
  border-radius: 8px;
  color: #92400e;
  font-weight: 800;
  margin-bottom: 16px;
  padding: 10px 14px;
}
.status-progress-legend {
  align-items: center;
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
}
.status-progress-legend span {
  align-items: center;
  display: inline-flex;
  gap: 5px;
}
.status-progress-legend i {
  border-radius: 999px;
  display: inline-block;
  height: 9px;
  width: 9px;
}
.status-summary-card {
  border: 1px solid #f0b35c;
  border-left: 5px solid #f59e0b;
  border-radius: 8px;
  background: linear-gradient(180deg, #fff3df 0%, #fffaf2 64%);
  box-shadow: 0 8px 18px rgba(180, 83, 9, .12);
}
.status-summary-card .card-body {
  padding: 14px;
}
.status-person-title {
  font-weight: 700;
  color: #92400e;
  margin-bottom: 4px;
  overflow-wrap: anywhere;
}
.status-person-supervisor {
  color: #7c2d12;
  font-size: .86rem;
  margin-top: -2px;
  margin-bottom: 6px;
  overflow-wrap: anywhere;
}
.status-person-meta {
  color: #b45309;
  font-size: .9rem;
  margin-bottom: 10px;
}
.status-area-list {
  color: #6b7280;
  font-size: .85rem;
  margin-top: 10px;
  padding-top: 10px;
  border-top: 1px solid rgba(217, 119, 6, .22);
}
.status-card-row {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  padding: 3px 8px;
  border-radius: 6px;
  color: #374151;
}
.status-card-row:nth-of-type(even) {
  background: rgba(251, 191, 36, .18);
}
.status-card-row strong {
  color: #111827;
}
.status-card-rule {
  border-top: 1px dashed rgba(217, 119, 6, .38);
  margin: 9px 0;
}
.status-progress-value {
  color: #b45309;
  font-weight: 700;
}
.status-card-section {
  align-items: center;
  background: linear-gradient(90deg, #dbeafe 0%, rgba(239, 246, 255, .88) 100%);
  border-left: 5px solid #3b82f6;
  border-radius: 8px;
  box-shadow: inset 0 -1px 0 rgba(37, 99, 235, .16);
  display: flex;
  gap: 14px;
  justify-content: flex-start;
  margin: 18px 0 12px;
  padding: 10px 14px;
}
.status-card-section h5 {
  color: #1d4ed8;
  font-weight: 800;
  margin: 0;
}
.status-sort-control {
  align-items: center;
  color: #1e40af;
  display: inline-flex;
  font-size: .9rem;
  gap: 6px;
  text-decoration: none;
  white-space: nowrap;
}
.status-sort-control:hover {
  color: #2563eb;
  text-decoration: none;
}
.status-section-search {
  max-width: 280px;
  min-width: 220px;
}
.status-header-sub {
  white-space: nowrap;
}
.status-pct-cell {
  color: #2563eb;
  font-weight: 700;
}
.status-progress-low {
  color: #dc2626 !important;
  font-weight: 800;
}
.status-progress-warning {
  color: #f59e0b !important;
  font-weight: 800;
}
.status-progress-mid {
  color: #2563eb !important;
  font-weight: 800;
}
.status-progress-high {
  color: #16a34a !important;
  font-weight: 800;
}
.status-draft-low {
  color: #16a34a !important;
  font-weight: 800;
}
.status-draft-warning {
  color: #fb7185 !important;
  font-weight: 800;
}
.status-draft-high {
  color: #dc2626 !important;
  font-weight: 800;
}
.status-table-view th {
  height: 92px;
  text-align: center;
  vertical-align: bottom !important;
  white-space: nowrap;
}
.status-freeze-pane {
  max-height: 70vh;
  overflow: auto;
}
.status-table-view {
  border-collapse: separate;
  border-spacing: 0;
}
.status-table-view thead th {
  background: #f8fafc;
  background-clip: padding-box;
  position: sticky;
  top: 0;
  z-index: 8;
}
.status-table-view th:nth-child(1),
.status-table-view td:nth-child(1) {
  left: 0;
  min-width: 155px;
  width: 155px;
}
.status-table-view th:nth-child(2),
.status-table-view td:nth-child(2) {
  left: 155px;
  min-width: 150px;
  width: 150px;
}
.status-table-view th:nth-child(3),
.status-table-view td:nth-child(3) {
  left: 305px;
  min-width: 105px;
  width: 105px;
}
.status-table-view th:nth-child(4),
.status-table-view td:nth-child(4) {
  left: 410px;
  min-width: 85px;
  width: 85px;
}
.status-table-view th:nth-child(5),
.status-table-view td:nth-child(5) {
  left: 495px;
  min-width: 175px;
  width: 175px;
}
.status-table-view th:nth-child(6),
.status-table-view td:nth-child(6) {
  box-shadow: 3px 0 0 #111827;
  left: 670px;
  min-width: 175px;
  width: 175px;
}
.status-table-view th:nth-child(-n+6),
.status-table-view td:nth-child(-n+6) {
  background-clip: padding-box;
  position: sticky;
  z-index: 7;
}
.status-table-view tbody td:nth-child(-n+6) {
  background: #fff;
}
.status-table-view tbody tr:nth-of-type(odd) td:nth-child(-n+6) {
  background: #f9fafb;
}
.status-table-view tbody tr:hover td:nth-child(-n+6) {
  background: #eef2ff;
}
.status-table-view thead th:nth-child(-n+6) {
  background: #f8fafc;
  z-index: 10;
}
.status-table-view th > div:first-child {
  line-height: 1.12;
  min-height: 44px;
  text-align: center;
}
.status-table-view .status-head-blue {
  background: #dbeafe !important;
  color: #1e3a8a;
}
.status-table-view .status-head-yellow {
  background: #fef3c7 !important;
  color: #78350f;
}
.status-table-view .status-head-light-green {
  background: #dcfce7 !important;
  color: #14532d;
}
.status-table-view .status-head-red {
  background: #fee2e2 !important;
  color: #7f1d1d;
}
.status-table-view .status-head-dark-green {
  background: #bbf7d0 !important;
  color: #064e3b;
}
.status-table-search,
.status-table-sort {
  border-radius: 4px;
  font-size: .72rem;
  height: 24px;
  margin-top: 5px;
  min-width: 76px;
  padding: 1px 5px;
}
.status-table-search {
  min-width: 145px;
}
.status-table-spacer {
  display: block;
  height: 29px;
  margin-top: 5px;
}
.status-page-size {
  max-width: 96px;
}
@media (max-width: 575.98px) {
  .status-card-section {
    align-items: flex-start;
    flex-direction: column;
    gap: 6px;
  }
  .status-section-search {
    max-width: 100%;
    min-width: 0;
    width: 100%;
  }
}
</style>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<form class="card card-body mb-3" method="get">
  <input type="hidden" name="card_sort" value="<?= e($filters['card_sort']) ?>">
  <input type="hidden" name="per_page" value="<?= (int)$filters['per_page'] ?>">
  <?php foreach ($statusSearchKeys as $key): ?>
    <input type="hidden" name="<?= e($key) ?>" value="<?= e($filters[$key]) ?>">
  <?php endforeach; ?>
  <div class="form-row align-items-end">
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
    <div class="form-group col-12 col-md-2">
      <label>Tampilan</label>
      <select class="form-control" name="view_mode" id="view_mode">
        <option value="card" <?= $filters['view_mode']==='card'?'selected':'' ?>>Card View</option>
        <option value="table" <?= $filters['view_mode']==='table'?'selected':'' ?>>Table View</option>
      </select>
    </div>
    <div class="form-group col-12 col-md-1"><button class="btn btn-primary btn-block" name="filter" value="1">Filter</button></div>
  </div>
</form>
<div class="status-info-section">
  <div><em>Progress Pendataan = Submit+Reject+Pending+Approve</em></div>
  <?php if ($filters['view_mode'] === 'table' && isset($_GET['filter'])): ?>
    <div class="small mt-1">Rekap PCL (<?= number_format($statusPetugasSummary['pcl'], 0, ',', '.') ?> petugas), PML (<?= number_format($statusPetugasSummary['pml'], 0, ',', '.') ?> petugas)</div>
    <div class="small mt-2 status-progress-legend">
      <span><i style="background:#dc2626"></i>&lt; 20%</span>
      <span><i style="background:#f59e0b"></i>20% - &lt; 40%</span>
      <span><i style="background:#2563eb"></i>40% - &lt; 75%</span>
      <span><i style="background:#16a34a"></i>75% - 100%</span>
    </div>
  <?php endif; ?>
</div>
<?php if ($isAllKabCardTooLarge): ?>
  <div class="alert alert-warning">Data Terlalu Banyak, silahkan Table View saja.</div>
<?php endif; ?>
<?php if ($canShowStatus && $filters['view_mode'] === 'card' && !$isAllKabCardTooLarge): ?>
  <?php
    $nextSort = $filters['card_sort'] === 'asc' ? 'desc' : 'asc';
    $sortIcon = $filters['card_sort'] === 'asc' ? 'fa-arrow-up-short-wide' : 'fa-arrow-down-wide-short';
    $sortLabel = $filters['card_sort'] === 'asc' ? 'Ascending' : 'Descending';
  ?>
  <div class="status-card-section">
    <h5>Card PML</h5>
    <a class="status-sort-control" href="<?= e(status_view_card_section_sort_url($filters, $nextSort)) ?>">
      <span>Sort by Progress Pendataan: <?= e($sortLabel) ?></span><i class="fas <?= e($sortIcon) ?>"></i>
    </a>
    <input class="form-control form-control-sm status-section-search" id="pmlSearch" type="search" placeholder="Cari nama PML">
  </div>
  <?php if ($pmlCards): ?>
    <div class="status-view-grid mb-4" id="pmlCardGrid">
      <?php foreach ($pmlCards as $card): ?>
        <?php $progressCount = status_view_progress_count($card); $progressPct = status_view_progress_pct($card); ?>
        <div class="status-summary-card" data-card-pml-search="<?= e(strtolower(status_view_card_title($card, 'pml'))) ?>" data-card-pcl-search="<?= e(strtolower($card['pencacah_names'] ?? '')) ?>">
          <div class="card-body">
            <div class="status-person-title"><?= e(status_view_card_title($card, 'pml')) ?></div>
            <div class="status-person-meta"><?= number_format((int)$card['pcl_count'], 0, ',', '.') ?> PCL - <?= number_format((int)$card['subsls_count'], 0, ',', '.') ?> SubSLS</div>
            <div class="status-metric-block">
            <div class="status-card-row"><span>Open</span><strong><?= number_format((int)$card['open_count'], 0, ',', '.') ?></strong></div>
            <div class="status-card-row"><span>Draft</span><strong><?= number_format((int)$card['draft_count'], 0, ',', '.') ?></strong></div>
            <div class="status-card-row"><span>Submitted</span><strong><?= number_format((int)$card['submitted_by_pencacah'], 0, ',', '.') ?></strong></div>
            <div class="status-card-row"><span>Rejected</span><strong><?= number_format((int)$card['rejected_by_pengawas'], 0, ',', '.') ?></strong></div>
            <div class="status-card-row"><span>Pending</span><strong><?= number_format((int)$card['pending_count'], 0, ',', '.') ?></strong></div>
            <div class="status-card-row"><span>Approved</span><strong><?= number_format((int)$card['approved_by_pengawas'], 0, ',', '.') ?></strong></div>
            <div class="status-card-rule"></div>
            <div class="status-card-row"><span>Progress</span><span class="status-progress-value">(<?= number_format($progressPct, 2, ',', '.') ?>%) <?= number_format($progressCount, 0, ',', '.') ?></span></div>
            <div class="status-card-rule"></div>
            <div class="status-card-row"><span>Total Assignment</span><strong><?= number_format((int)$card['target'], 0, ',', '.') ?></strong></div>
            </div>
            <div class="status-area-list"><strong>Wilayah Kerja Desa:</strong> <?= e($card['desa_names'] ?: '-') ?></div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <div class="alert alert-info">Tidak ada data PML pada filter ini.</div>
  <?php endif; ?>

  <div class="status-card-section">
    <h5>Card PCL</h5>
    <a class="status-sort-control" href="<?= e(status_view_card_section_sort_url($filters, $nextSort)) ?>">
      <span>Sort by Progress Pendataan: <?= e($sortLabel) ?></span><i class="fas <?= e($sortIcon) ?>"></i>
    </a>
    <input class="form-control form-control-sm status-section-search" id="pclSearch" type="search" placeholder="Cari nama PCL">
  </div>
  <?php if ($pclCards): ?>
    <div class="status-view-grid" id="pclCardGrid">
      <?php foreach ($pclCards as $card): ?>
        <?php $progressCount = status_view_progress_count($card); $progressPct = status_view_progress_pct($card); ?>
        <div class="status-summary-card" data-card-pml-search="<?= e(strtolower(status_view_card_subtitle($card))) ?>" data-card-pcl-search="<?= e(strtolower(petugas_label($card['email'], $card['petugas_name'] ?? ''))) ?>">
          <div class="card-body">
            <div class="status-person-title"><?= e(petugas_label($card['email'], $card['petugas_name'] ?? '')) ?></div>
            <div class="status-person-supervisor"><?= e(status_view_card_subtitle($card)) ?></div>
            <div class="status-person-meta"><?= number_format((int)$card['pcl_count'], 0, ',', '.') ?> PCL - <?= number_format((int)$card['subsls_count'], 0, ',', '.') ?> SubSLS</div>
            <div class="status-metric-block">
            <div class="status-card-row"><span>Open</span><strong><?= number_format((int)$card['open_count'], 0, ',', '.') ?></strong></div>
            <div class="status-card-row"><span>Draft</span><strong><?= number_format((int)$card['draft_count'], 0, ',', '.') ?></strong></div>
            <div class="status-card-row"><span>Submitted</span><strong><?= number_format((int)$card['submitted_by_pencacah'], 0, ',', '.') ?></strong></div>
            <div class="status-card-row"><span>Rejected</span><strong><?= number_format((int)$card['rejected_by_pengawas'], 0, ',', '.') ?></strong></div>
            <div class="status-card-row"><span>Pending</span><strong><?= number_format((int)$card['pending_count'], 0, ',', '.') ?></strong></div>
            <div class="status-card-row"><span>Approved</span><strong><?= number_format((int)$card['approved_by_pengawas'], 0, ',', '.') ?></strong></div>
            <div class="status-card-rule"></div>
            <div class="status-card-row"><span>Progress</span><span class="status-progress-value">(<?= number_format($progressPct, 2, ',', '.') ?>%) <?= number_format($progressCount, 0, ',', '.') ?></span></div>
            <div class="status-card-rule"></div>
            <div class="status-card-row"><span>Total Assignment</span><strong><?= number_format((int)$card['target'], 0, ',', '.') ?></strong></div>
            </div>
            <div class="status-area-list"><strong>Wilayah Kerja Desa:</strong> <?= e($card['desa_names'] ?: '-') ?></div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <div class="alert alert-info">Tidak ada data PCL pada filter ini.</div>
  <?php endif; ?>
<?php endif; ?>
<?php if ($canShowStatus && $filters['view_mode'] === 'table' && $rows): ?>
<div class="card">
  <div class="card-header py-2 d-flex justify-content-between align-items-center">
    <span>Menampilkan <?= number_format(count($rows), 0, ',', '.') ?> dari <?= number_format($totalRows, 0, ',', '.') ?> SubSLS</span>
    <div>
      <?php
        $exportQuery = ['filter' => 1, 'kab_id' => $filters['kab_id'], 'kec_id' => $filters['kec_id'], 'desa_id' => $filters['desa_id'], 'view_mode' => $filters['view_mode'], 'per_page' => $filters['per_page'], 'action' => 'export'];
        foreach ($statusSearchKeys as $key) {
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
        $pageSizeQuery = ['filter' => 1, 'kab_id' => $filters['kab_id'], 'kec_id' => $filters['kec_id'], 'desa_id' => $filters['desa_id'], 'view_mode' => 'table'];
        foreach ($statusSearchKeys as $key) {
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
      <select class="form-control form-control-sm status-page-size" name="per_page" onchange="this.form.submit()">
        <?php foreach ([20, 50, 100] as $size): ?>
          <option value="<?= $size ?>" <?= $filters['per_page'] === $size ? 'selected' : '' ?>><?= $size ?></option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>
  <div class="card-body table-responsive p-0 status-freeze-pane">
    <table class="table table-sm table-bordered table-striped mb-0 status-table-view" id="statusTableView">
      <thead>
        <tr>
          <th><div>Kode SubSLS</div><input class="form-control form-control-sm status-table-search" type="search" placeholder="Cari kode" value="<?= e($filters['search_kode']) ?>" data-status-server-search="search_kode"></th>
          <th><div>Desa</div><input class="form-control form-control-sm status-table-search" type="search" placeholder="Cari desa" value="<?= e($filters['search_desa']) ?>" data-status-server-search="search_desa"></th>
          <th><div>SLS</div><span class="status-table-spacer"></span></th>
          <th><div>SubSLS</div><span class="status-table-spacer"></span></th>
          <th><div>Nama Pengawas</div><input class="form-control form-control-sm status-table-search" type="search" placeholder="Cari pengawas" value="<?= e($filters['search_pengawas']) ?>" data-status-server-search="search_pengawas"></th>
          <th><div>Nama Pencacah</div><input class="form-control form-control-sm status-table-search" type="search" placeholder="Cari pencacah" value="<?= e($filters['search_pencacah']) ?>" data-status-server-search="search_pencacah"></th>
          <?php
            $statusSortHeaders = [
                ['label' => 'Target', 'class' => 'status-head-blue', 'key' => 'target'],
                ['label' => 'Progress<br><span class="status-header-sub">(Count)</span>', 'class' => 'status-head-light-green', 'key' => 'progress_count'],
                ['label' => 'Progress<br><span class="status-header-sub">(Persen %)</span>', 'class' => 'status-head-light-green', 'key' => 'progress_pct'],
                ['label' => 'Approved<br><span class="status-header-sub">(Count)</span>', 'class' => 'status-head-dark-green', 'key' => 'approved_by_pengawas'],
                ['label' => 'Approved<br><span class="status-header-sub">(Persen %)</span>', 'class' => 'status-head-dark-green', 'key' => 'approved_pct'],
                ['label' => 'Draft<br><span class="status-header-sub">(Count)</span>', 'class' => 'status-head-yellow', 'key' => 'draft_count'],
                ['label' => 'Draft<br><span class="status-header-sub">(Persen %)</span>', 'class' => 'status-head-yellow', 'key' => 'draft_pct'],
                ['label' => 'Open', 'class' => 'status-head-blue', 'key' => 'open_count'],
                ['label' => 'Submit', 'class' => 'status-head-light-green', 'key' => 'submitted_by_pencacah'],
                ['label' => 'Reject', 'class' => 'status-head-red', 'key' => 'rejected_by_pengawas'],
                ['label' => 'Pending', 'class' => 'status-head-red', 'key' => 'pending_count'],
            ];
          ?>
          <?php foreach ($statusSortHeaders as $i => $header): ?>
            <th class="<?= e($header['class']) ?>">
              <div><?= $header['label'] ?></div>
              <select class="form-control form-control-sm status-table-sort" data-status-sort-key="<?= e($header['key']) ?>">
                <option value="">Sort</option>
                <option value="asc" <?= $filters['sort_key']===$header['key'] && $filters['sort_dir']==='asc' ? 'selected' : '' ?>>Ascending</option>
                <option value="desc" <?= $filters['sort_key']===$header['key'] && $filters['sort_dir']==='desc' ? 'selected' : '' ?>>Descending</option>
                <option value="clear">Clear</option>
              </select>
            </th>
          <?php endforeach; ?>
          <th><div>Last Update</div><span class="status-table-spacer"></span></th>
          <th><div>Updated By</div><span class="status-table-spacer"></span></th>
          <th><div>Status Selesai</div><span class="status-table-spacer"></span></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $rowIndex => $r): ?>
        <?php
          $statusProgressCount = status_view_progress_count($r);
          $statusProgressPct = status_view_progress_pct($r);
          $draftPct = status_view_field_pct($r, 'draft_count');
        ?>
        <tr data-original-index="<?= (int)$rowIndex ?>">
          <td><?= e($r['kab_id'] . $r['kdkec'] . $r['kddesa'] . $r['kdsls'] . $r['kdsubsls']) ?></td>
          <td><?= e($r['nmdesa']) ?></td>
          <td><?= e($r['nmsls']) ?></td>
          <td><?= e($r['kdsubsls']) ?></td>
          <td><?= e(trim((string)($r['pengawas_name'] ?? '')) ?: '-') ?></td>
          <td><?= e(trim((string)($r['pencacah_name'] ?? '')) ?: '-') ?></td>
          <td data-sort-value="<?= (int)$r['target'] ?>"><?= number_format((int)$r['target'], 0, ',', '.') ?></td>
          <td data-sort-value="<?= $statusProgressCount ?>"><?= number_format($statusProgressCount, 0, ',', '.') ?></td>
          <td class="status-pct-cell <?= e(status_view_progress_pct_class($statusProgressPct)) ?>" data-sort-value="<?= e((string)$statusProgressPct) ?>"><?= number_format($statusProgressPct, 2, ',', '.') ?>%</td>
          <td data-sort-value="<?= (int)$r['approved_by_pengawas'] ?>"><?= number_format((int)$r['approved_by_pengawas'], 0, ',', '.') ?></td>
          <?php $approvedPct = status_view_field_pct($r, 'approved_by_pengawas'); ?>
          <td class="status-pct-cell <?= e(status_view_progress_pct_class($approvedPct)) ?>" data-sort-value="<?= e((string)$approvedPct) ?>"><?= number_format($approvedPct, 2, ',', '.') ?>%</td>
          <td data-sort-value="<?= (int)$r['draft_count'] ?>"><?= number_format((int)$r['draft_count'], 0, ',', '.') ?></td>
          <td class="status-pct-cell <?= e(status_view_draft_pct_class($draftPct)) ?>" data-sort-value="<?= e((string)$draftPct) ?>"><?= number_format($draftPct, 2, ',', '.') ?>%</td>
          <td data-sort-value="<?= (int)$r['open_count'] ?>"><?= number_format((int)$r['open_count'], 0, ',', '.') ?></td>
          <td data-sort-value="<?= (int)$r['submitted_by_pencacah'] ?>"><?= number_format((int)$r['submitted_by_pencacah'], 0, ',', '.') ?></td>
          <td data-sort-value="<?= (int)$r['rejected_by_pengawas'] ?>"><?= number_format((int)$r['rejected_by_pengawas'], 0, ',', '.') ?></td>
          <td data-sort-value="<?= (int)$r['pending_count'] ?>"><?= number_format((int)$r['pending_count'], 0, ',', '.') ?></td>
          <td><?= e($r['last_update'] ?: '-') ?></td>
          <td><?= e($r['updated_by'] ?: '-') ?></td>
          <td><?= e($r['status_selesai']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php if ($totalPages > 1): ?>
  <nav class="mt-3">
    <ul class="pagination pagination-sm">
      <?php
        $start = max(1, $page - 2);
        $end = min($totalPages, $page + 2);
        $baseQuery = ['filter' => 1, 'kab_id' => $filters['kab_id'], 'kec_id' => $filters['kec_id'], 'desa_id' => $filters['desa_id'], 'view_mode' => $filters['view_mode'], 'per_page' => $filters['per_page']];
        foreach ($statusSearchKeys as $key) {
            if ($filters[$key] !== '') {
                $baseQuery[$key] = $filters[$key];
            }
        }
        if ($filters['sort_key'] !== '' && $filters['sort_dir'] !== '') {
            $baseQuery['sort_key'] = $filters['sort_key'];
            $baseQuery['sort_dir'] = $filters['sort_dir'];
        }
      ?>
      <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="?<?= e(http_build_query($baseQuery + ['page' => max(1, $page - 1)])) ?>">Prev</a></li>
      <?php for ($p = $start; $p <= $end; $p++): ?>
        <li class="page-item <?= $p === $page ? 'active' : '' ?>"><a class="page-link" href="?<?= e(http_build_query($baseQuery + ['page' => $p])) ?>"><?= $p ?></a></li>
      <?php endfor; ?>
      <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>"><a class="page-link" href="?<?= e(http_build_query($baseQuery + ['page' => min($totalPages, $page + 1)])) ?>">Next</a></li>
    </ul>
  </nav>
<?php endif; ?>
<?php elseif ($canShowStatus && $filters['view_mode'] === 'table' && !$error): ?>
  <div class="alert alert-info">Tidak ada data status pada filter ini.</div>
<?php endif; ?>
<script>
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
const viewMode = document.getElementById('view_mode');
if (viewMode) {
  viewMode.addEventListener('change', function () {
    this.form.submit();
  });
}
function applyLinkedCardSearch() {
  const pmlKeyword = (document.getElementById('pmlSearch')?.value || '').trim().toLowerCase();
  const pclKeyword = (document.getElementById('pclSearch')?.value || '').trim().toLowerCase();
  document.querySelectorAll('#pmlCardGrid .status-summary-card, #pclCardGrid .status-summary-card').forEach(function (card) {
    const pmlHaystack = card.dataset.cardPmlSearch || '';
    const pclHaystack = card.dataset.cardPclSearch || '';
    const matchPml = !pmlKeyword || pmlHaystack.includes(pmlKeyword);
    const matchPcl = !pclKeyword || pclHaystack.includes(pclKeyword);
    card.style.display = (matchPml && matchPcl) ? '' : 'none';
  });
}
['pmlSearch', 'pclSearch'].forEach(function (inputId) {
  const input = document.getElementById(inputId);
  if (input) input.addEventListener('input', applyLinkedCardSearch);
});

document.querySelectorAll('#statusTableView').forEach(function (table) {
  const sortSelects = Array.from(table.querySelectorAll('[data-status-sort-key]'));

  sortSelects.forEach(function (select) {
    select.addEventListener('change', function () {
      const direction = select.value;
      const params = new URLSearchParams(window.location.search);
      params.set('filter', '1');
      params.set('view_mode', 'table');
      params.set('per_page', <?= (int)$filters['per_page'] ?>);
      params.delete('page');
      if (direction === 'asc' || direction === 'desc') {
        params.set('sort_key', select.dataset.statusSortKey || '');
        params.set('sort_dir', direction);
      } else {
        params.delete('sort_key');
        params.delete('sort_dir');
      }
      window.location.search = params.toString();
    });
  });
});

document.querySelectorAll('[data-status-server-search]').forEach(function (input) {
  let timer = null;
  input.addEventListener('input', function () {
    window.clearTimeout(timer);
    timer = window.setTimeout(function () {
      const params = new URLSearchParams(window.location.search);
      params.set('filter', '1');
      params.set('view_mode', 'table');
      params.set('per_page', <?= (int)$filters['per_page'] ?>);
      params.delete('page');
      document.querySelectorAll('[data-status-server-search]').forEach(function (field) {
        const key = field.dataset.statusServerSearch;
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
