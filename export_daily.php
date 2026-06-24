<?php
require __DIR__ . '/layout.php';
$user = require_role(['superadmin']);

$filters = [
    'tanggal' => $_GET['tanggal'] ?? today(),
    'kab_id' => $_GET['kab_id'] ?? '',
    'kec_id' => $_GET['kec_id'] ?? '',
    'desa_id' => $_GET['desa_id'] ?? '',
    'pengawas_email' => normalize_email($_GET['pengawas_email'] ?? ''),
    'pencacah_email' => normalize_email($_GET['pencacah_email'] ?? ''),
];

function export_daily_filter_options(array $filters): array
{
    $out = ['kabupaten' => [], 'kecamatan' => [], 'desa' => [], 'pengawas' => [], 'pencacah' => []];
    $out['kabupaten'] = db()->query("SELECT id value, CONCAT(id,' - ',nmkab) label FROM master_kab ORDER BY id")->fetchAll();

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

    $where = [];
    $params = [];
    if ($filters['kab_id'] !== '') {
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
    $pengawasWhere = $where;
    $pengawasWhere[] = "ms.pengawas_email IS NOT NULL";
    $pengawasWhere[] = "ms.pengawas_email <> ''";
    $sqlWhere = 'WHERE ' . implode(' AND ', $pengawasWhere);

    $stmt = db()->prepare("SELECT DISTINCT ms.pengawas_email value, up.name
        FROM master_subsls ms
        JOIN master_sls sl ON sl.id=ms.sls_id
        JOIN master_desa d ON d.id=sl.desa_id
        JOIN master_kec kc ON kc.id=d.kec_id
        JOIN master_kab k ON k.id=kc.kab_id
        LEFT JOIN users up ON up.email=ms.pengawas_email
        $sqlWhere
        ORDER BY up.name, ms.pengawas_email");
    $stmt->execute($params);
    $out['pengawas'] = array_map(fn($row) => [
        'value' => $row['value'],
        'label' => petugas_label($row['value'], $row['name'] ?? ''),
    ], $stmt->fetchAll());

    $pencacahWhere = $where;
    $pencacahParams = $params;
    if ($filters['pengawas_email'] !== '') {
        $pencacahWhere[] = 'ms.pengawas_email=?';
        $pencacahParams[] = $filters['pengawas_email'];
    }
    $pencacahWhere[] = "ms.pencacah_email IS NOT NULL";
    $pencacahWhere[] = "ms.pencacah_email <> ''";
    $pencacahSqlWhere = 'WHERE ' . implode(' AND ', $pencacahWhere);
    $stmt = db()->prepare("SELECT DISTINCT ms.pencacah_email value, uc.name
        FROM master_subsls ms
        JOIN master_sls sl ON sl.id=ms.sls_id
        JOIN master_desa d ON d.id=sl.desa_id
        JOIN master_kec kc ON kc.id=d.kec_id
        JOIN master_kab k ON k.id=kc.kab_id
        LEFT JOIN users uc ON uc.email=ms.pencacah_email
        $pencacahSqlWhere
        ORDER BY uc.name, ms.pencacah_email");
    $stmt->execute($pencacahParams);
    $out['pencacah'] = array_map(fn($row) => [
        'value' => $row['value'],
        'label' => petugas_label($row['value'], $row['name'] ?? ''),
    ], $stmt->fetchAll());

    return $out;
}

function export_daily_where(array $filters): array
{
    $where = ['ds.tanggal=?'];
    $params = [$filters['tanggal']];
    if ($filters['kab_id'] !== '') {
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
    if ($filters['pengawas_email'] !== '') {
        $where[] = 'ds.pengawas_email=?';
        $params[] = $filters['pengawas_email'];
    }
    if ($filters['pencacah_email'] !== '') {
        $where[] = 'ds.pencacah_email=?';
        $params[] = $filters['pencacah_email'];
    }
    return [implode(' AND ', $where), $params];
}

function export_daily_headers(): array
{
    return [
        'tanggal',
        'prov_id',
        'kab_id',
        'kabupaten',
        'kec_id',
        'kecamatan',
        'desa_id',
        'desa',
        'sls',
        'nama_sls',
        'kode_subsls',
        'nama_subsls',
        'subsls_id',
        'pengawas',
        'pencacah',
        'target',
        'open',
        'draft',
        'submit',
        'reject',
        'pending',
        'approved',
        'submitted_at',
        'updated_at',
        'updated_by',
    ];
}

function export_daily_row_values(array $row): array
{
    return [
        $row['tanggal'],
        $row['prov_id'],
        $row['kab_id'],
        $row['nmkab'],
        $row['kdkec'],
        $row['nmkec'],
        $row['kddesa'],
        $row['nmdesa'],
        $row['kdsls'],
        $row['nmsls'],
        $row['kdsls'] . $row['kdsubsls'],
        $row['nmsubsls'],
        $row['subsls_id'],
        petugas_label($row['pengawas_email'], $row['pengawas_name'] ?? ''),
        petugas_label($row['pencacah_email'], $row['pencacah_name'] ?? ''),
        (int)$row['target'],
        (int)$row['open_count'],
        (int)$row['draft_count'],
        (int)$row['submitted_by_pencacah'],
        (int)$row['rejected_by_pengawas'],
        (int)$row['pending_count'],
        (int)$row['approved_by_pengawas'],
        $row['submitted_at'],
        $row['updated_at'],
        $row['updated_by'],
    ];
}

function export_daily_prepare_statement(array $filters): PDOStatement
{
    [$sqlWhere, $params] = export_daily_where($filters);
    $stmt = db()->prepare("SELECT ds.tanggal, p.id prov_id, k.id kab_id, k.nmkab, kc.kdkec, kc.nmkec,
            d.kddesa, d.nmdesa, sl.kdsls, sl.nmsls, ms.kdsubsls, ms.nmsubsls,
            ds.subsls_id, ds.pengawas_email, up.name pengawas_name, ds.pencacah_email, uc.name pencacah_name,
            ds.target, ds.open_count, ds.draft_count, ds.submitted_by_pencacah, ds.rejected_by_pengawas,
            ds.pending_count, ds.approved_by_pengawas, ds.submitted_at, ds.updated_at, ds.updated_by
        FROM daily_status ds
        JOIN master_subsls ms ON ms.id=ds.subsls_id
        JOIN master_sls sl ON sl.id=ms.sls_id
        JOIN master_desa d ON d.id=sl.desa_id
        JOIN master_kec kc ON kc.id=d.kec_id
        JOIN master_kab k ON k.id=kc.kab_id
        JOIN master_prov p ON p.id=k.prov_id
        LEFT JOIN users up ON up.email=ds.pengawas_email
        LEFT JOIN users uc ON uc.email=ds.pencacah_email
        WHERE $sqlWhere
        ORDER BY ds.tanggal, k.id, kc.kdkec, d.kddesa, sl.kdsls, ms.kdsubsls");
    $stmt->execute($params);
    return $stmt;
}

function export_daily_validate_date(array $filters): void
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['tanggal'])) {
        http_response_code(400);
        exit('Tanggal tidak valid.');
    }
}

function export_daily_stream_csv(array $filters): void
{
    export_daily_validate_date($filters);
    $pdo = db();
    if (defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')) {
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
    }
    $stmt = export_daily_prepare_statement($filters);
    $filename = 'daily_status_' . $filters['tanggal'] . '_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fputcsv($out, export_daily_headers());
    while ($row = $stmt->fetch()) {
        fputcsv($out, export_daily_row_values($row));
    }
    fclose($out);
    exit;
}

function export_daily_xlsx_col(int $index): string
{
    $name = '';
    while ($index > 0) {
        $index--;
        $name = chr(65 + ($index % 26)) . $name;
        $index = intdiv($index, 26);
    }
    return $name;
}

function export_daily_xlsx_cell(string $value, int $row, int $col): string
{
    $ref = export_daily_xlsx_col($col) . $row;
    return '<c r="' . $ref . '" t="inlineStr"><is><t>' . htmlspecialchars($value, ENT_XML1) . '</t></is></c>';
}

function export_daily_stream_xlsx(array $filters): void
{
    export_daily_validate_date($filters);
    $stmt = export_daily_prepare_statement($filters);
    $tmp = tempnam(sys_get_temp_dir(), 'daily_export_');
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
  <sheets><sheet name="daily_status" sheetId="1" r:id="rId1"/></sheets>
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
    $rowNumber = 1;
    $sheet .= '<row r="' . $rowNumber . '">';
    foreach (export_daily_headers() as $col => $value) {
        $sheet .= export_daily_xlsx_cell((string)$value, $rowNumber, $col + 1);
    }
    $sheet .= '</row>';
    while ($row = $stmt->fetch()) {
        $rowNumber++;
        $sheet .= '<row r="' . $rowNumber . '">';
        foreach (export_daily_row_values($row) as $col => $value) {
            $sheet .= export_daily_xlsx_cell((string)$value, $rowNumber, $col + 1);
        }
        $sheet .= '</row>';
    }
    $sheet .= '</sheetData></worksheet>';
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
    $zip->close();

    $filename = 'daily_status_' . $filters['tanggal'] . '_' . date('Ymd_His') . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    unlink($tmp);
    exit;
}

if (($_GET['action'] ?? '') === 'export') {
    $format = ($_GET['format'] ?? 'csv') === 'xlsx' ? 'xlsx' : 'csv';
    if ($format === 'xlsx') {
        export_daily_stream_xlsx($filters);
    }
    export_daily_stream_csv($filters);
}

$opts = export_daily_filter_options($filters);

render_header('Export Data Daily');
?>
<div class="alert alert-info">
  Export tersedia dalam format CSV dan Excel. Untuk data sangat besar, CSV tetap paling ringan.
</div>
<form class="card card-body mb-3" method="get">
  <input type="hidden" name="action" value="export">
  <div class="form-row align-items-end">
    <div class="form-group col-md-2">
      <label>Tanggal</label>
      <input class="form-control" type="date" name="tanggal" value="<?= e($filters['tanggal']) ?>" required>
    </div>
    <div class="form-group col-md-2">
      <label>Kabupaten</label>
      <select class="form-control" name="kab_id" id="kab_id">
        <option value="">Semua Kabupaten</option>
        <?php foreach ($opts['kabupaten'] as $o): ?><option value="<?= e($o['value']) ?>" <?= $filters['kab_id']===$o['value']?'selected':'' ?>><?= e($o['label']) ?></option><?php endforeach; ?>
      </select>
    </div>
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
      <label>Pengawas</label>
      <select class="form-control" name="pengawas_email" id="pengawas_email">
        <option value="">Semua Pengawas</option>
        <?php foreach ($opts['pengawas'] as $o): ?><option value="<?= e($o['value']) ?>" <?= $filters['pengawas_email']===$o['value']?'selected':'' ?>><?= e($o['label']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="form-group col-md-3">
      <label>Pencacah</label>
      <select class="form-control" name="pencacah_email" id="pencacah_email">
        <option value="">Semua Pencacah</option>
        <?php foreach ($opts['pencacah'] as $o): ?><option value="<?= e($o['value']) ?>" <?= $filters['pencacah_email']===$o['value']?'selected':'' ?>><?= e($o['label']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="form-group col-md-4">
      <button class="btn btn-success mr-2" name="format" value="csv"><i class="fas fa-file-csv mr-1"></i>Download CSV</button>
      <button class="btn btn-success" name="format" value="xlsx"><i class="fas fa-file-excel mr-1"></i>Download Excel</button>
    </div>
  </div>
</form>
<script>
const kabupaten = document.getElementById('kab_id');
if (kabupaten) {
  kabupaten.addEventListener('change', function () {
    document.getElementById('kec_id').value = '';
    document.getElementById('desa_id').value = '';
    document.getElementById('pengawas_email').value = '';
    document.getElementById('pencacah_email').value = '';
    this.form.removeAttribute('action');
    this.form.querySelector('[name="action"]').value = '';
    this.form.submit();
  });
}
const kecamatan = document.getElementById('kec_id');
if (kecamatan) {
  kecamatan.addEventListener('change', function () {
    document.getElementById('desa_id').value = '';
    document.getElementById('pengawas_email').value = '';
    document.getElementById('pencacah_email').value = '';
    this.form.querySelector('[name="action"]').value = '';
    this.form.submit();
  });
}
const desa = document.getElementById('desa_id');
if (desa) {
  desa.addEventListener('change', function () {
    document.getElementById('pengawas_email').value = '';
    document.getElementById('pencacah_email').value = '';
    this.form.querySelector('[name="action"]').value = '';
    this.form.submit();
  });
}
const pengawas = document.getElementById('pengawas_email');
if (pengawas) {
  pengawas.addEventListener('change', function () {
    document.getElementById('pencacah_email').value = '';
    this.form.querySelector('[name="action"]').value = '';
    this.form.submit();
  });
}
</script>
<?php render_footer(); ?>
