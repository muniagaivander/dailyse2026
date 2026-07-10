<?php
require __DIR__ . '/layout.php';
$user = require_role(['superadmin', 'admin_kab', 'viewer_prov', 'viewer_kab']);

$filters = [
    'tanggal' => (string)($_GET['tanggal'] ?? date('Y-m-d')),
    'petugas_type' => ($_GET['petugas_type'] ?? 'pcl') === 'pml' ? 'pml' : 'pcl',
    'kab_id' => (string)($_GET['kab_id'] ?? ''),
    'kec_id' => (string)($_GET['kec_id'] ?? ''),
    'desa_id' => (string)($_GET['desa_id'] ?? ''),
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
            GROUP_CONCAT(DISTINCT CASE
                WHEN up.name IS NULL OR up.name='' OR LOWER(up.name)=LOWER(ms.pengawas_email) THEN ms.pengawas_email
                ELSE up.name
            END ORDER BY up.name, ms.pengawas_email SEPARATOR ', ') pml_names,
            GROUP_CONCAT(DISTINCT CONCAT(k.id,' - ',k.nmkab) ORDER BY k.id SEPARATOR ', ') kabupaten,
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
        ORDER BY sort_kab_id, u.name, ms.$emailField");
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
        'Jumlah SubSLS',
        'Total Assignment (' . $dateEndLabel . ')',
        'Total Submit sd ' . $dateEndLabel,
        '% Submit sd tanggal ' . $dateEndLabel,
    ]);
    foreach ($dates as $date) {
        $headers[] = 'Submit Tanggal ' . rekap_weekly_date_label($date);
    }

    $out = [];
    foreach ($rows as $row) {
        $email = normalize_email((string)$row['email']);
        $endDaily = $matrix[$email][$dateEnd] ?? null;
        $target = (int)($endDaily['target'] ?? 0);
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
        $line[] = (int)$row['subsls_total'];
        $line[] = $target;
        $line[] = $rekapCount;
        $line[] = round(rekap_weekly_pct($rekapCount, $target), 2);
        foreach ($dates as $date) {
            $daily = $matrix[$email][$date] ?? null;
            $previous = $matrix[$email][date('Y-m-d', strtotime($date . ' -1 day'))] ?? null;
            $line[] = $daily !== null && $previous !== null
                ? (int)$daily['count'] - (int)$previous['count']
                : 0;
        }
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
    if (preg_match('/^% Submit sd tanggal (.+)$/', $header, $m)) {
        return '% Submit<br>sd tanggal<br>' . e($m[1]);
    }
    if (preg_match('/^Submit Tanggal (.+)$/', $header, $m)) {
        return 'Submit<br>Tanggal<br>' . e($m[1]);
    }
    return e($header);
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
    foreach (['jumlah subsls', 'total assignment', 'total submit', '% submit', 'submit tanggal', 'count', 'persen'] as $numericPart) {
        if (str_contains($header, $numericPart)) {
            return true;
        }
    }
    return false;
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
  <fonts count="3">
    <font><sz val="11"/><name val="Calibri"/></font>
    <font><b/><sz val="11"/><color rgb="FF1F2937"/><name val="Calibri"/></font>
    <font><sz val="9"/><name val="Calibri"/></font>
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
  <cellXfs count="3">
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
    <xf numFmtId="0" fontId="1" fillId="1" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>
    <xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0"/>
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
            $numeric = $rowNumber > 1 && rekap_weekly_xlsx_header_is_numeric((string)($headers[$cIndex] ?? ''));
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
if ($hasFiltered) {
    $rows = rekap_weekly_petugas_rows($user, $filters);
    $matrix = rekap_weekly_values($user, $filters, $dateStart, $dateEnd);
    [$headers, $tableRows] = rekap_weekly_export_payload($rows, $dates, $matrix, $filters);
    if (($_GET['action'] ?? '') === 'export') {
        $format = ($_GET['format'] ?? 'csv') === 'xlsx' ? 'xlsx' : 'csv';
        rekap_weekly_export($headers, $tableRows, $filters, $format);
    }
}

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
  .weekly-table th {
    line-height: 1.1;
    text-align: center;
    vertical-align: middle !important;
    white-space: normal;
  }
  .weekly-header-box {
    align-items: center;
    display: flex;
    flex-direction: column;
    height: 98px;
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
  .weekly-head-orange {
    background: #ffedd5;
    color: #7c2d12;
  }
  .weekly-head-blue {
    background: #dbeafe;
    color: #1e3a8a;
  }
  .weekly-head-green {
    background: #dcfce7;
    color: #14532d;
  }
  .weekly-small { font-size: .82rem; }
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
    Periode <?= e(rekap_weekly_date_label($dateStart)) ?> - <?= e(rekap_weekly_date_label($dateEnd)) ?>
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
    <div class="card-body table-responsive p-0">
      <table class="table table-sm table-bordered table-striped mb-0 weekly-table">
        <thead>
          <tr>
            <?php foreach ($headers as $i => $header): ?>
              <?php
                $isNumericHeader = rekap_weekly_xlsx_header_is_numeric((string)$header);
                $isKabupatenHeader = (string)$header === 'Kabupaten';
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
                      <option value="asc">Ascending</option>
                      <option value="desc">Descending</option>
                      <option value="clear">Clear</option>
                    </select>
                  <?php else: ?>
                    <span class="weekly-sort-spacer"></span>
                  <?php endif; ?>
                </div>
              </th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($tableRows as $rowIndex => $row): ?>
            <tr data-original-index="<?= (int)$rowIndex ?>">
              <?php foreach ($row as $i => $value): ?>
                <?php
                  $header = strtolower((string)($headers[$i] ?? ''));
                  $isNumeric = rekap_weekly_xlsx_header_is_numeric($header);
                  $small = str_contains($header, 'email') || str_contains($header, 'wilayah kerja');
                ?>
                <td class="<?= $isNumeric ? 'text-right' : '' ?> <?= $small ? 'weekly-small' : '' ?>" <?= $isNumeric ? 'data-sort-value="' . e((string)(float)$value) . '"' : '' ?>>
                  <?= $isNumeric ? e(number_format((float)$value, str_contains($header, 'persen') ? 2 : 0, ',', '.')) : e((string)$value) ?>
                </td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
          <?php if (!$tableRows): ?>
            <tr><td colspan="<?= max(1, count($headers)) ?>" class="text-center text-muted">Tidak ada data.</td></tr>
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
  function pageSize() {
    return Number((pageSizeSelect && pageSizeSelect.value) || 20);
  }
  function renderPage() {
    const rows = dataRows();
    const size = pageSize();
    const totalPages = Math.max(1, Math.ceil(rows.length / size));
    currentPage = Math.min(Math.max(1, currentPage), totalPages);
    rows.forEach(function (row, index) {
      row.style.display = index >= (currentPage - 1) * size && index < currentPage * size ? '' : 'none';
    });
    if (info) info.textContent = rows.length ? 'Halaman ' + currentPage + ' dari ' + totalPages : 'Tidak ada data';
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
      const col = Number(select.dataset.sortCol || 0);
      const direction = select.value;
      table.querySelectorAll('.weekly-sort-select').forEach(function (other) {
        if (other !== select) other.value = '';
      });
      const rows = dataRows();
      if (direction === 'clear' || direction === '') {
        rows.sort(function (a, b) {
          return Number(a.dataset.originalIndex || 0) - Number(b.dataset.originalIndex || 0);
        });
        select.value = '';
        rows.forEach(function (row) { tbody.appendChild(row); });
        currentPage = 1;
        renderPage();
        return;
      }
      rows.sort(function (a, b) {
        const sortType = select.dataset.sortType || 'number';
        if (sortType === 'text') {
          const at = (a.children[col] ? a.children[col].textContent : '').trim();
          const bt = (b.children[col] ? b.children[col].textContent : '').trim();
          return direction === 'asc' ? at.localeCompare(bt) : bt.localeCompare(at);
        }
        const av = Number((a.children[col] && a.children[col].dataset.sortValue) || 0);
        const bv = Number((b.children[col] && b.children[col].dataset.sortValue) || 0);
        return direction === 'asc' ? av - bv : bv - av;
      });
      rows.forEach(function (row) { tbody.appendChild(row); });
      currentPage = 1;
      renderPage();
    });
  });
  renderPage();
});
</script>

<?php render_footer(); ?>
