<?php
require __DIR__ . '/layout.php';
$user = require_role(['superadmin', 'admin_kab', 'viewer_prov', 'viewer_kab']);

$filters = [
    'petugas_type' => ($_GET['petugas_type'] ?? 'pml') === 'pcl' ? 'pcl' : 'pml',
    'kab_id' => $_GET['kab_id'] ?? '',
    'kec_id' => $_GET['kec_id'] ?? '',
    'desa_id' => $_GET['desa_id'] ?? '',
];
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
            GROUP_CONCAT(DISTINCT CASE
                WHEN up.name IS NULL OR up.name='' OR LOWER(up.name)=LOWER(ms.pengawas_email) THEN ms.pengawas_email
                ELSE up.name
            END ORDER BY up.name, ms.pengawas_email SEPARATOR ', ') pml_names,
            GROUP_CONCAT(DISTINCT CONCAT(k.id,' - ',k.nmkab) ORDER BY k.id SEPARATOR ', ') kabupaten,
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

function rekap_petugas_count_pct_text(int $count, int $target): string
{
    $pct = $target > 0 ? $count / $target * 100 : 0;
    return e(number_format($count, 0, ',', '.')) . ' <span class="rekap-pct">(' . e(number_format($pct, 2, ',', '.')) . '%)</span>';
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

function rekap_petugas_xlsx_cell(string $value, int $row, int $col, int $style = 0): string
{
    $ref = rekap_petugas_xlsx_col($col) . $row;
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
  <fonts count="2">
    <font><sz val="11"/><name val="Calibri"/></font>
    <font><sz val="9"/><name val="Calibri"/></font>
  </fonts>
  <fills count="1"><fill><patternFill patternType="none"/></fill></fills>
  <borders count="1"><border/></borders>
  <cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
  <cellXfs count="2">
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
    <xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0"/>
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
            $sheet .= rekap_petugas_xlsx_cell((string)$value, $rowNumber, $columnNumber, $style);
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
    'draft_count' => 'Draft',
    'submitted_by_pencacah' => 'Submit',
    'rejected_by_pengawas' => 'Reject',
    'pending_count' => 'Pending',
    'approved_by_pengawas' => 'Approve',
];
$rows = rekap_petugas_rows($user, $filters);
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 100;
$totalRows = count($rows);
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page = min($page, $totalPages);
$displayRows = array_slice($rows, ($page - 1) * $perPage, $perPage);

if (($_GET['action'] ?? '') === 'export') {
    $format = ($_GET['format'] ?? 'csv') === 'xlsx' ? 'xlsx' : 'csv';
    $headers = ['Nama Petugas', 'Email Petugas', 'Kabupaten', 'Wilayah Kerja Kecamatan', 'Wilayah Kerja Desa', 'Jumlah SubSLS', 'Target'];
    if ($filters['petugas_type'] === 'pcl') {
        array_splice($headers, 2, 0, ['Nama PML']);
    }
    foreach ($fields as $label) {
        $headers[] = $label;
    }
    $headers[] = 'Progress Pendataan';
    $exportRows = [];
    foreach ($rows as $r) {
        $row = [
            trim((string)($r['petugas_name'] ?? '')) ?: '-',
            $r['email'],
            $r['kabupaten'] ?: '-',
            $r['wilayah_kerja_kecamatan'] ?: '-',
            $r['wilayah_kerja'] ?: '-',
            (string)(int)$r['subsls_total'],
            (string)(int)$r['target'],
        ];
        if ($filters['petugas_type'] === 'pcl') {
            array_splice($row, 2, 0, [$r['pml_names'] ?: '-']);
        }
        foreach (array_keys($fields) as $field) {
            $row[] = (string)(int)$r[$field];
        }
        $pendataanCount = rekap_petugas_pendataan_count($r);
        $target = (int)$r['target'];
        $pendataanPct = $target > 0 ? $pendataanCount / $target * 100 : 0;
        $row[] = number_format($pendataanCount, 0, ',', '.') . ' (' . number_format($pendataanPct, 2, ',', '.') . '%)';
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
</style>
<form class="card card-body mb-3" method="get">
  <div class="form-row align-items-end">
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
<div class="rekap-info-section"><em>Progress Pendataan = Submit+Reject+Pending+Approve</em></div>

<div class="card">
  <div class="card-header py-2 d-flex justify-content-between align-items-center">
    <strong>Rekap <?= $filters['petugas_type'] === 'pcl' ? 'PCL' : 'PML' ?> (<?= number_format($totalRows, 0, ',', '.') ?> petugas)</strong>
    <div>
      <?php
        $exportQuery = [
            'petugas_type' => $filters['petugas_type'],
            'kab_id' => $filters['kab_id'],
            'kec_id' => $filters['kec_id'],
            'desa_id' => $filters['desa_id'],
            'action' => 'export',
        ];
      ?>
      <a class="btn btn-outline-success btn-sm mr-2" href="?<?= e(http_build_query($exportQuery + ['format' => 'csv'])) ?>"><i class="fas fa-file-csv mr-1"></i>Download CSV</a>
      <a class="btn btn-outline-success btn-sm" href="?<?= e(http_build_query($exportQuery + ['format' => 'xlsx'])) ?>"><i class="fas fa-file-excel mr-1"></i>Download Excel</a>
    </div>
  </div>
  <div class="card-body table-responsive p-0">
    <table class="table table-sm table-bordered table-striped mb-0">
      <thead>
        <tr>
          <th>Nama Petugas</th>
          <th>Email Petugas</th>
          <?php if ($filters['petugas_type'] === 'pcl'): ?><th>Nama PML</th><?php endif; ?>
          <th>Kabupaten</th>
          <th>Wilayah Kerja Kecamatan</th>
          <th>Wilayah Kerja Desa</th>
          <th class="text-right">Jumlah SubSLS</th>
          <th class="text-right">Target</th>
          <?php foreach ($fields as $label): ?><th class="text-right"><?= e($label) ?></th><?php endforeach; ?>
          <th class="text-right">Progress Pendataan</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($displayRows as $r): ?>
          <tr>
            <td><?= e(trim((string)($r['petugas_name'] ?? '')) ?: '-') ?></td>
            <td class="rekap-small-text"><?= e($r['email']) ?></td>
            <?php if ($filters['petugas_type'] === 'pcl'): ?><td><?= e($r['pml_names'] ?: '-') ?></td><?php endif; ?>
            <td><?= e($r['kabupaten'] ?: '-') ?></td>
            <td><?= e($r['wilayah_kerja_kecamatan'] ?: '-') ?></td>
            <td class="rekap-small-text"><?= e($r['wilayah_kerja'] ?: '-') ?></td>
            <td class="text-right"><?= number_format((int)$r['subsls_total'], 0, ',', '.') ?></td>
            <td class="text-right"><?= number_format((int)$r['target'], 0, ',', '.') ?></td>
            <?php foreach (array_keys($fields) as $field): ?><td class="text-right"><?= number_format((int)$r[$field], 0, ',', '.') ?></td><?php endforeach; ?>
            <td class="text-right"><?= rekap_petugas_count_pct_text(rekap_petugas_pendataan_count($r), (int)$r['target']) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$displayRows): ?>
          <tr><td colspan="<?= 8 + count($fields) + ($filters['petugas_type'] === 'pcl' ? 1 : 0) ?>" class="text-center text-muted">Tidak ada data petugas pada filter ini.</td></tr>
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
        ];
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
</script>
<?php render_footer(); ?>
