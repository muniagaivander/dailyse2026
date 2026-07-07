<?php
require __DIR__ . '/layout.php';
$user = require_role(['superadmin', 'admin_kab', 'viewer_prov', 'viewer_kab']);

$selectedDataType = (string)($_GET['data_type'] ?? 'rekap');
if (!in_array($selectedDataType, ['rekap', 'daily_achievement', 'daily_plus_rekap'], true)) {
    $selectedDataType = 'rekap';
}
$filters = [
    'data_type' => $selectedDataType,
    'date_start' => (string)($_GET['date_start'] ?? '2026-06-15'),
    'date_end' => (string)($_GET['date_end'] ?? date('Y-m-d')),
    'petugas_type' => ($_GET['petugas_type'] ?? 'pml') === 'pcl' ? 'pcl' : 'pml',
    'kab_id' => (string)($_GET['kab_id'] ?? ''),
    'kec_id' => (string)($_GET['kec_id'] ?? ''),
    'desa_id' => (string)($_GET['desa_id'] ?? ''),
];
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['date_start'])) {
    $filters['date_start'] = '2026-06-15';
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['date_end'])) {
    $filters['date_end'] = date('Y-m-d');
}
if ($filters['date_start'] > $filters['date_end']) {
    [$filters['date_start'], $filters['date_end']] = [$filters['date_end'], $filters['date_start']];
}
if (in_array($user['role'], ['admin_kab', 'viewer_kab'], true)) {
    $filters['kab_id'] = (string)$user['kab_id'];
}

function rekap_daily_filter_options(array $user, array $filters): array
{
    $out = ['kabupaten' => [], 'kecamatan' => [], 'desa' => []];
    if (in_array($user['role'], ['admin_kab', 'viewer_kab'], true)) {
        $stmt = db()->prepare("SELECT id value, CONCAT(id,' - ',nmkab) label FROM master_kab WHERE id=?");
        $stmt->execute([$user['kab_id']]);
    } else {
        $stmt = db()->query("SELECT id value, CONCAT(id,' - ',nmkab) label FROM master_kab ORDER BY id");
    }
    $out['kabupaten'] = $stmt->fetchAll();

    if ($filters['kab_id'] !== '') {
        $stmt = db()->prepare("SELECT id value, CONCAT(kdkec,' - ',nmkec) label
            FROM master_kec WHERE kab_id=? ORDER BY kdkec, nmkec");
        $stmt->execute([$filters['kab_id']]);
        $out['kecamatan'] = $stmt->fetchAll();
    }
    if ($filters['kec_id'] !== '') {
        $stmt = db()->prepare("SELECT id value, CONCAT(kddesa,' - ',nmdesa) label
            FROM master_desa WHERE kec_id=? ORDER BY kddesa, nmdesa");
        $stmt->execute([$filters['kec_id']]);
        $out['desa'] = $stmt->fetchAll();
    }
    return $out;
}

function rekap_daily_area_where(array $user, array $filters, string $prefix = ''): array
{
    $where = [];
    $params = [];
    if (in_array($user['role'], ['admin_kab', 'viewer_kab'], true)) {
        $where[] = $prefix . 'k.id=?';
        $params[] = $user['kab_id'];
    } elseif ($filters['kab_id'] !== '') {
        $where[] = $prefix . 'k.id=?';
        $params[] = $filters['kab_id'];
    }
    if ($filters['kec_id'] !== '') {
        $where[] = $prefix . 'kc.id=?';
        $params[] = $filters['kec_id'];
    }
    if ($filters['desa_id'] !== '') {
        $where[] = $prefix . 'd.id=?';
        $params[] = $filters['desa_id'];
    }
    return [$where, $params];
}

function rekap_daily_petugas_rows(array $user, array $filters): array
{
    [$where, $params] = rekap_daily_area_where($user, $filters);
    $emailField = $filters['petugas_type'] === 'pcl' ? 'pencacah_email' : 'pengawas_email';
    $where[] = "ms.$emailField IS NOT NULL";
    $where[] = "ms.$emailField <> ''";
    $sqlWhere = 'WHERE ' . implode(' AND ', $where);

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
        $sqlWhere
        GROUP BY ms.$emailField, u.name
        ORDER BY sort_kab_id, u.name, ms.$emailField");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function rekap_daily_values(array $user, array $filters): array
{
    [$where, $params] = rekap_daily_area_where($user, $filters);
    $dateStart = $filters['date_start'];
    $dateEnd = $filters['date_end'];
    $queryStart = in_array($filters['data_type'], ['daily_achievement', 'daily_plus_rekap'], true)
        ? date('Y-m-d', strtotime($dateStart . ' -1 day'))
        : $dateStart;
    $emailField = $filters['petugas_type'] === 'pcl' ? 'pencacah_email' : 'pengawas_email';
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

    $dates = [];
    $matrix = [];
    foreach ($stmt->fetchAll() as $row) {
        $date = (string)$row['tanggal'];
        $email = normalize_email((string)$row['email']);
        if ($date >= $dateStart) {
            $dates[$date] = true;
        }
        $matrix[$email][$date] = [
            'count' => (int)$row['progress_count'],
            'target' => (int)$row['target'],
        ];
    }
    $dates = array_keys($dates);
    sort($dates);
    return [$dates, $matrix];
}

function rekap_daily_date_label(string $date): string
{
    static $months = [
        '01' => 'Januari', '02' => 'Februari', '03' => 'Maret', '04' => 'April',
        '05' => 'Mei', '06' => 'Juni', '07' => 'Juli', '08' => 'Agustus',
        '09' => 'September', '10' => 'Oktober', '11' => 'November', '12' => 'Desember',
    ];
    [$year, $month, $day] = explode('-', $date);
    return (int)$day . ' ' . ($months[$month] ?? $month);
}

function rekap_daily_pct(int $count, int $target): float
{
    return $target > 0 ? $count / $target * 100 : 0;
}

function rekap_daily_xlsx_col(int $index): string
{
    $name = '';
    while ($index > 0) {
        $mod = ($index - 1) % 26;
        $name = chr(65 + $mod) . $name;
        $index = intdiv($index - $mod, 26);
    }
    return $name;
}

function rekap_daily_xlsx_cell(string $value, int $row, int $col, int $style = 0): string
{
    $ref = rekap_daily_xlsx_col($col) . $row;
    return '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is><t>'
        . htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8')
        . '</t></is></c>';
}

function rekap_daily_export(array $rows, array $dates, array $matrix, array $filters, string $format): void
{
    $fixedHeaders = ['Nama Petugas', 'Email Petugas'];
    if ($filters['petugas_type'] === 'pcl') {
        $fixedHeaders[] = 'Nama PML';
    }
    $fixedHeaders = array_merge($fixedHeaders, ['Kabupaten', 'Wilayah Kerja Kecamatan', 'Wilayah Kerja Desa', 'Jumlah SubSLS']);
    $headers = $fixedHeaders;
    foreach ($dates as $date) {
        $dateLabel = rekap_daily_date_label($date);
        if ($filters['data_type'] === 'daily_plus_rekap') {
            $headers[] = 'Capaian ' . $dateLabel . '-Count';
            $headers[] = 'Capaian ' . $dateLabel . '-Percent';
            $headers[] = 'Rekap Sampai ' . $dateLabel . '-Count';
            $headers[] = 'Rekap Sampai ' . $dateLabel . '-Percent';
        } else {
            $headers[] = $dateLabel . '-Count';
            $headers[] = $dateLabel . '-Persen';
        }
    }

    $exportRows = [];
    foreach ($rows as $row) {
        $values = [
            trim((string)($row['petugas_name'] ?? '')) ?: '-',
            $row['email'],
        ];
        if ($filters['petugas_type'] === 'pcl') {
            $values[] = $row['pml_names'] ?: '-';
        }
        $values[] = $row['kabupaten'] ?: '-';
        $values[] = $row['wilayah_kerja_kecamatan'] ?: '-';
        $values[] = $row['wilayah_kerja'] ?: '-';
        $values[] = (string)(int)$row['subsls_total'];
        $email = normalize_email((string)$row['email']);
        foreach ($dates as $date) {
            $daily = $matrix[$email][$date] ?? null;
            $count = (int)($daily['count'] ?? 0);
            $target = (int)($daily['target'] ?? 0);
            if ($filters['data_type'] === 'daily_plus_rekap') {
                $rekapCount = $count;
                $previousDate = date('Y-m-d', strtotime($date . ' -1 day'));
                $previous = $matrix[$email][$previousDate] ?? null;
                $capaianCount = $daily !== null && $previous !== null
                    ? (int)$daily['count'] - (int)$previous['count']
                    : 0;
                $values[] = (string)$capaianCount;
                $values[] = number_format(rekap_daily_pct($capaianCount, $target), 2, ',', '.') . '%';
                $values[] = (string)$rekapCount;
                $values[] = number_format(rekap_daily_pct($rekapCount, $target), 2, ',', '.') . '%';
                continue;
            }
            if ($filters['data_type'] === 'daily_achievement') {
                $previousDate = date('Y-m-d', strtotime($date . ' -1 day'));
                $previous = $matrix[$email][$previousDate] ?? null;
                $count = $daily !== null && $previous !== null
                    ? (int)$daily['count'] - (int)$previous['count']
                    : 0;
            }
            $values[] = (string)$count;
            $values[] = number_format(rekap_daily_pct($count, $target), 2, ',', '.') . '%';
        }
        $exportRows[] = $values;
    }

    $suffix = str_replace('-', '', $filters['date_start']) . '_' . str_replace('-', '', $filters['date_end']);
    if ($filters['data_type'] === 'daily_achievement') {
        $typePrefix = 'capaian_petugas_daily';
    } elseif ($filters['data_type'] === 'daily_plus_rekap') {
        $typePrefix = 'capaian_rekap_petugas_daily';
    } else {
        $typePrefix = 'rekap_petugas_daily';
    }
    $filename = $typePrefix . '_' . $filters['petugas_type'] . '_' . $suffix . '_' . date('Ymd_His');
    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, $headers);
        foreach ($exportRows as $row) {
            fputcsv($out, $row);
        }
        fclose($out);
        exit;
    }

    $tmp = tempnam(sys_get_temp_dir(), 'rekap_daily_');
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
  <sheets><sheet name="rekap_petugas_daily" sheetId="1" r:id="rId1"/></sheets>
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
    <border>
      <left style="thin"><color rgb="FFCBD5E1"/></left>
      <right style="thin"><color rgb="FFCBD5E1"/></right>
      <top style="thin"><color rgb="FFCBD5E1"/></top>
      <bottom style="thin"><color rgb="FFCBD5E1"/></bottom>
    </border>
  </borders>
  <cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
  <cellXfs count="3">
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
    <xf numFmtId="0" fontId="1" fillId="1" borderId="1" xfId="0" applyAlignment="1">
      <alignment horizontal="center" vertical="center" wrapText="1"/>
    </xf>
    <xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0"/>
  </cellXfs>
</styleSheet>');

    $fixedCount = count($fixedHeaders);
    $columnsPerDate = $filters['data_type'] === 'daily_plus_rekap' ? 4 : 2;
    $lastColumn = $fixedCount + (count($dates) * $columnsPerDate);
    $identityColumnsXml = '<col min="1" max="1" width="24" customWidth="1"/>'
        . '<col min="2" max="2" width="30" customWidth="1"/>';
    if ($filters['petugas_type'] === 'pcl') {
        $identityColumnsXml .= '<col min="3" max="3" width="30" customWidth="1"/>'
            . '<col min="4" max="4" width="28" customWidth="1"/>'
            . '<col min="5" max="5" width="30" customWidth="1"/>'
            . '<col min="6" max="6" width="42" customWidth="1"/>'
            . '<col min="7" max="7" width="16" customWidth="1"/>';
    } else {
        $identityColumnsXml .= '<col min="3" max="3" width="28" customWidth="1"/>'
            . '<col min="4" max="4" width="30" customWidth="1"/>'
            . '<col min="5" max="5" width="42" customWidth="1"/>'
            . '<col min="6" max="6" width="16" customWidth="1"/>';
    }
    $sheet = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
        . '<cols>'
        . $identityColumnsXml
        . ($lastColumn > $fixedCount
            ? '<col min="' . ($fixedCount + 1) . '" max="' . $lastColumn . '" width="12" customWidth="1"/>'
            : '')
        . '</cols><sheetData>';
    foreach (array_merge([$headers], $exportRows) as $rowIndex => $row) {
        $rowNumber = $rowIndex + 1;
        $sheet .= '<row r="' . $rowNumber . '"' . ($rowNumber === 1 ? ' ht="24" customHeight="1"' : '') . '>';
        $smallFontColumns = [2, $filters['petugas_type'] === 'pcl' ? 6 : 5];
        foreach ($row as $colIndex => $value) {
            $columnNumber = $colIndex + 1;
            $style = $rowNumber === 1 ? 1 : (in_array($columnNumber, $smallFontColumns, true) ? 2 : 0);
            $sheet .= rekap_daily_xlsx_cell((string)$value, $rowNumber, $columnNumber, $style);
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

$opts = rekap_daily_filter_options($user, $filters);

if (($_GET['action'] ?? '') === 'export') {
    $rows = rekap_daily_petugas_rows($user, $filters);
    [$dates, $matrix] = rekap_daily_values($user, $filters);
    $format = ($_GET['format'] ?? 'csv') === 'xlsx' ? 'xlsx' : 'csv';
    rekap_daily_export($rows, $dates, $matrix, $filters, $format);
}

render_header('Rekap Petugas Daily');
?>
<style>
  .rekap-daily-info {
    background: linear-gradient(90deg, #fff7ed 0%, #fffbeb 100%);
    border-left: 5px solid #f59e0b;
    border-radius: 8px;
    color: #92400e;
    font-weight: 800;
    margin-bottom: 16px;
    padding: 10px 14px;
  }
  .rekap-daily-export {
    border-left: 4px solid #2563eb;
  }
</style>

<form class="card card-body mb-3" method="get">
  <div class="form-row align-items-end">
    <div class="form-group col-12 col-md-2">
      <label>Tipe Data</label>
      <select class="form-control" name="data_type" id="data_type">
        <option value="rekap" <?= $filters['data_type']==='rekap'?'selected':'' ?>>Rekap</option>
        <option value="daily_achievement" <?= $filters['data_type']==='daily_achievement'?'selected':'' ?>>Capaian Per Hari</option>
        <option value="daily_plus_rekap" <?= $filters['data_type']==='daily_plus_rekap'?'selected':'' ?>>Capaian Per Hari + Rekap</option>
      </select>
    </div>
    <div class="form-group col-12 col-md-2">
      <label>Tanggal Awal</label>
      <input class="form-control" type="date" name="date_start" id="date_start" value="<?= e($filters['date_start']) ?>">
    </div>
    <div class="form-group col-12 col-md-2">
      <label>Tanggal Akhir</label>
      <input class="form-control" type="date" name="date_end" id="date_end" value="<?= e($filters['date_end']) ?>">
    </div>
    <div class="form-group col-12 col-md-2">
      <label>Jenis Petugas</label>
      <select class="form-control" name="petugas_type" id="petugas_type">
        <option value="pml" <?= $filters['petugas_type']==='pml'?'selected':'' ?>>PML</option>
        <option value="pcl" <?= $filters['petugas_type']==='pcl'?'selected':'' ?>>PCL</option>
      </select>
    </div>
    <?php if (in_array($user['role'], ['superadmin', 'viewer_prov'], true)): ?>
      <div class="form-group col-12 col-md-2">
        <label>Kabupaten</label>
        <select class="form-control" name="kab_id" id="kab_id">
          <option value="">Semua Kabupaten</option>
          <?php foreach ($opts['kabupaten'] as $option): ?>
            <option value="<?= e($option['value']) ?>" <?= $filters['kab_id']===$option['value']?'selected':'' ?>><?= e($option['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    <?php else: ?>
      <input type="hidden" name="kab_id" value="<?= e($filters['kab_id']) ?>">
    <?php endif; ?>
    <div class="form-group col-12 col-md-2">
      <label>Kecamatan</label>
      <select class="form-control" name="kec_id" id="kec_id" <?= $filters['kab_id'] !== '' ? '' : 'disabled' ?>>
        <option value=""><?= $filters['kab_id'] !== '' ? 'Semua Kecamatan' : 'Pilih kabupaten dulu' ?></option>
        <?php foreach ($opts['kecamatan'] as $option): ?>
          <option value="<?= e($option['value']) ?>" <?= $filters['kec_id']===$option['value']?'selected':'' ?>><?= e($option['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group col-12 col-md-2">
      <label>Desa</label>
      <select class="form-control" name="desa_id" id="desa_id" <?= $filters['kec_id'] !== '' ? '' : 'disabled' ?>>
        <option value=""><?= $filters['kec_id'] !== '' ? 'Semua Desa' : 'Pilih kecamatan dulu' ?></option>
        <?php foreach ($opts['desa'] as $option): ?>
          <option value="<?= e($option['value']) ?>" <?= $filters['desa_id']===$option['value']?'selected':'' ?>><?= e($option['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group col-12 col-md-1">
      <button class="btn btn-primary btn-block">Filter</button>
    </div>
  </div>
</form>

<div class="rekap-daily-info"><em>Progress Pendataan = Submit+Reject+Pending+Approved</em></div>

<?php
  $exportQuery = [
      'date_start' => $filters['date_start'],
      'date_end' => $filters['date_end'],
      'data_type' => $filters['data_type'],
      'petugas_type' => $filters['petugas_type'],
      'kab_id' => $filters['kab_id'],
      'kec_id' => $filters['kec_id'],
      'desa_id' => $filters['desa_id'],
      'action' => 'export',
  ];
?>
<div class="card rekap-daily-export">
  <div class="card-body">
    <h5 class="font-weight-bold">Export <?php
      if ($filters['data_type'] === 'daily_achievement') {
          echo 'Capaian Per Hari';
      } elseif ($filters['data_type'] === 'daily_plus_rekap') {
          echo 'Capaian Per Hari + Rekap';
      } else {
          echo 'Rekap Petugas Daily';
      }
    ?></h5>
    <p class="text-muted">
      <?php if ($filters['data_type'] === 'daily_achievement'): ?>
        Count dihitung dari Progress Pendataan H dikurangi H-1. Persen dihitung dari Count dibagi Target pada hari H. Jika data H-1 tidak tersedia, Count ditetapkan 0.
      <?php elseif ($filters['data_type'] === 'daily_plus_rekap'): ?>
        File berisi Capaian Per Hari dan Rekap sampai tanggal tersebut. Capaian dihitung dari Progress Pendataan H dikurangi H-1.
      <?php else: ?>
        File berisi kondisi rekap Progress Pendataan pada setiap tanggal.
      <?php endif; ?>
      Proses dapat memerlukan waktu untuk cakupan data yang besar.
    </p>
    <a class="btn btn-success mr-2" href="?<?= e(http_build_query($exportQuery + ['format' => 'csv'])) ?>"><i class="fas fa-file-csv mr-1"></i>Download CSV</a>
    <a class="btn btn-success" href="?<?= e(http_build_query($exportQuery + ['format' => 'xlsx'])) ?>"><i class="fas fa-file-excel mr-1"></i>Download Excel</a>
  </div>
</div>

<script>
['data_type', 'petugas_type'].forEach(function (id) {
  const element = document.getElementById(id);
  if (element) element.addEventListener('change', function () { this.form.submit(); });
});
const kabupaten = document.getElementById('kab_id');
if (kabupaten) {
  kabupaten.addEventListener('change', function () {
    document.getElementById('kec_id').value = '';
    document.getElementById('desa_id').value = '';
    this.form.submit();
  });
}
const kecamatan = document.getElementById('kec_id');
if (kecamatan) {
  kecamatan.addEventListener('change', function () {
    document.getElementById('desa_id').value = '';
    this.form.submit();
  });
}
const desa = document.getElementById('desa_id');
if (desa) {
  desa.addEventListener('change', function () { this.form.submit(); });
}
</script>
<?php render_footer(); ?>
