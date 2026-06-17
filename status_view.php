<?php
require __DIR__ . '/layout.php';
$user = require_role(['superadmin', 'admin_kab']);
$filters = [
    'kab_id' => $_GET['kab_id'] ?? '',
    'kec_id' => $_GET['kec_id'] ?? '',
    'desa_id' => $_GET['desa_id'] ?? '',
];
if ($user['role'] === 'admin_kab') {
    $filters['kab_id'] = $user['kab_id'];
}

function status_filter_options(array $user, array $filters): array
{
    $out = ['kabupaten' => [], 'kecamatan' => [], 'desa' => []];
    if ($user['role'] === 'admin_kab') {
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
$error = null;
$fields = status_fields();
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 100;
$totalRows = 0;
$totalPages = 0;

function status_view_build_filter(array $user, array $filters): array
{
    $where = [];
    $params = [];
    if ($user['role'] === 'admin_kab') {
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

function status_view_select_sql(string $sqlWhere, ?int $limitRows = null, ?int $offsetRows = null): string
{
    $limit = '';
    if ($limitRows !== null && $offsetRows !== null) {
        $limit = 'LIMIT ' . max(1, $limitRows) . ' OFFSET ' . max(0, $offsetRows);
    }
    return "SELECT k.id kab_id, k.nmkab, kc.kdkec, kc.nmkec, d.kddesa, d.nmdesa,
                sl.kdsls, sl.nmsls, ms.kdsubsls, ms.nmsubsls,
                ms.pengawas_email, ms.pencacah_email,
                COALESCE(ss.target,0) target,
                COALESCE(ss.open_count,0) open_count,
                COALESCE(ss.draft_count,0) draft_count,
                COALESCE(ss.submitted_by_pencacah,0) submitted_by_pencacah,
                COALESCE(ss.approved_by_pengawas,0) approved_by_pengawas,
                COALESCE(ss.rejected_by_pengawas,0) rejected_by_pengawas,
                ss.last_update, ss.updated_by,
                COALESCE(cs.status_selesai, 'Belum Selesai') status_selesai
            FROM master_subsls ms
            JOIN master_sls sl ON sl.id=ms.sls_id
            JOIN master_desa d ON d.id=sl.desa_id
            JOIN master_kec kc ON kc.id=d.kec_id
            JOIN master_kab k ON k.id=kc.kab_id
            LEFT JOIN subsls_status ss ON ss.subsls_id=ms.id
            LEFT JOIN subsls_completion_status cs ON cs.subsls_id=ms.id
            $sqlWhere
            ORDER BY k.id, kc.kdkec, d.kddesa, sl.kdsls, ms.kdsubsls
            $limit";
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

function status_view_xlsx_cell(string $value, int $row, int $col): string
{
    $ref = status_view_xlsx_col($col) . $row;
    return '<c r="' . $ref . '" t="inlineStr"><is><t>' . htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8') . '</t></is></c>';
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
</Relationships>');
    $sheet = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
    $allRows = array_merge([$headers], $rows);
    foreach ($allRows as $rIndex => $row) {
        $rowNumber = $rIndex + 1;
        $sheet .= '<row r="' . $rowNumber . '">';
        foreach ($row as $cIndex => $value) {
            $sheet .= status_view_xlsx_cell((string)$value, $rowNumber, $cIndex + 1);
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
    $format = ($_GET['format'] ?? 'csv') === 'xlsx' ? 'xlsx' : 'csv';
    [$sqlWhere, $params] = status_view_build_filter($user, $filters);
    $stmt = db()->prepare(status_view_select_sql($sqlWhere));
    $stmt->execute($params);
    $exportSource = $stmt->fetchAll();
    $headers = ['Kabupaten', 'Kecamatan', 'Desa', 'Kode SubSLS', 'SLS', 'Nama SLS', 'SubSLS', 'Pengawas', 'Pencacah', 'Target'];
    foreach ($fields as $label) {
        $headers[] = $label;
    }
    $headers[] = 'Last Update';
    $headers[] = 'Updated By';
    $headers[] = 'Status Selesai';
    $exportRows = [];
    foreach ($exportSource as $r) {
        $row = [
            $r['kab_id'] . ' - ' . $r['nmkab'],
            $r['kdkec'] . ' - ' . $r['nmkec'],
            $r['kddesa'] . ' - ' . $r['nmdesa'],
            $r['kdsls'] . $r['kdsubsls'],
            $r['kdsls'],
            $r['nmsls'],
            $r['kdsubsls'] . ' - ' . $r['nmsubsls'],
            $r['pengawas_email'],
            $r['pencacah_email'],
            (string)(int)$r['target'],
        ];
        foreach (array_keys($fields) as $field) {
            $row[] = (string)(int)$r[$field];
        }
        $row[] = $r['last_update'] ?: '';
        $row[] = $r['updated_by'] ?: '';
        $row[] = $r['status_selesai'];
        $exportRows[] = $row;
    }
    status_view_export($headers, $exportRows, $format);
}

if (isset($_GET['filter'])) {
    [$sqlWhere, $params] = status_view_build_filter($user, $filters);
    $countStmt = db()->prepare("SELECT COUNT(*)
        FROM master_subsls ms
        JOIN master_sls sl ON sl.id=ms.sls_id
        JOIN master_desa d ON d.id=sl.desa_id
        JOIN master_kec kc ON kc.id=d.kec_id
        JOIN master_kab k ON k.id=kc.kab_id
        $sqlWhere");
    $countStmt->execute($params);
    $totalRows = (int)$countStmt->fetchColumn();
    $totalPages = max(1, (int)ceil($totalRows / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    $stmt = db()->prepare(status_view_select_sql($sqlWhere, $perPage, $offset));
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
}

render_header('Status Terupdate');
?>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<form class="card card-body mb-3" method="get">
  <div class="form-row align-items-end">
    <?php if ($user['role'] === 'superadmin'): ?>
      <div class="form-group col-md-3">
        <label>Kabupaten</label>
        <select class="form-control" name="kab_id" id="kab_id">
          <option value="">Semua Kabupaten</option>
          <?php foreach ($opts['kabupaten'] as $o): ?><option value="<?= e($o['value']) ?>" <?= $filters['kab_id']===$o['value']?'selected':'' ?>><?= e($o['label']) ?></option><?php endforeach; ?>
        </select>
      </div>
    <?php else: ?>
      <input type="hidden" name="kab_id" value="<?= e($filters['kab_id']) ?>">
    <?php endif; ?>
    <div class="form-group col-md-3">
      <label>Kecamatan</label>
      <select class="form-control" name="kec_id" id="kec_id" <?= $filters['kab_id'] ? '' : 'disabled' ?>>
        <option value=""><?= $filters['kab_id'] ? 'Semua Kecamatan' : 'Pilih kabupaten dulu' ?></option>
        <?php foreach ($opts['kecamatan'] as $o): ?><option value="<?= e($o['value']) ?>" <?= $filters['kec_id']===$o['value']?'selected':'' ?>><?= e($o['label']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="form-group col-md-3">
      <label>Desa</label>
      <select class="form-control" name="desa_id" id="desa_id" <?= $filters['kec_id'] ? '' : 'disabled' ?>>
        <option value=""><?= $filters['kec_id'] ? 'Semua Desa' : 'Pilih kecamatan dulu' ?></option>
        <?php foreach ($opts['desa'] as $o): ?><option value="<?= e($o['value']) ?>" <?= $filters['desa_id']===$o['value']?'selected':'' ?>><?= e($o['label']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="form-group col-md-2"><button class="btn btn-primary" name="filter" value="1">Filter</button></div>
  </div>
</form>
<?php if ($rows): ?>
<div class="card">
  <div class="card-header py-2 d-flex justify-content-between align-items-center">
    <span>Menampilkan <?= number_format(count($rows), 0, ',', '.') ?> dari <?= number_format($totalRows, 0, ',', '.') ?> SubSLS</span>
    <div>
      <?php
        $exportQuery = ['filter' => 1, 'kab_id' => $filters['kab_id'], 'kec_id' => $filters['kec_id'], 'desa_id' => $filters['desa_id'], 'action' => 'export'];
      ?>
      <a class="btn btn-outline-success btn-sm mr-2" href="?<?= e(http_build_query($exportQuery + ['format' => 'csv'])) ?>"><i class="fas fa-file-csv mr-1"></i>Download CSV</a>
      <a class="btn btn-outline-success btn-sm" href="?<?= e(http_build_query($exportQuery + ['format' => 'xlsx'])) ?>"><i class="fas fa-file-excel mr-1"></i>Download Excel</a>
    </div>
  </div>
  <div class="card-body table-responsive p-0">
    <table class="table table-sm table-bordered table-striped mb-0">
      <thead>
        <tr>
          <th>Kode SubSLS</th><th>Desa</th><th>SLS</th><th>SubSLS</th><th>Pengawas</th><th>Pencacah</th><th>Target</th>
          <?php foreach ($fields as $label): ?><th><?= e($label) ?></th><?php endforeach; ?>
          <th>Last Update</th><th>Updated By</th><th>Status Selesai</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= e($r['kdsls'] . $r['kdsubsls']) ?></td>
          <td><?= e($r['nmdesa']) ?></td>
          <td><?= e($r['kdsls'] . ' - ' . $r['nmsls']) ?></td>
          <td><?= e($r['nmsubsls']) ?></td>
          <td><?= e($r['pengawas_email']) ?></td>
          <td><?= e($r['pencacah_email']) ?></td>
          <td><?= number_format((int)$r['target'], 0, ',', '.') ?></td>
          <?php foreach (array_keys($fields) as $field): ?><td><?= number_format((int)$r[$field], 0, ',', '.') ?></td><?php endforeach; ?>
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
        $baseQuery = ['filter' => 1, 'kab_id' => $filters['kab_id'], 'kec_id' => $filters['kec_id'], 'desa_id' => $filters['desa_id']];
      ?>
      <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="?<?= e(http_build_query($baseQuery + ['page' => max(1, $page - 1)])) ?>">Prev</a></li>
      <?php for ($p = $start; $p <= $end; $p++): ?>
        <li class="page-item <?= $p === $page ? 'active' : '' ?>"><a class="page-link" href="?<?= e(http_build_query($baseQuery + ['page' => $p])) ?>"><?= $p ?></a></li>
      <?php endfor; ?>
      <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>"><a class="page-link" href="?<?= e(http_build_query($baseQuery + ['page' => min($totalPages, $page + 1)])) ?>">Next</a></li>
    </ul>
  </nav>
<?php endif; ?>
<?php elseif (isset($_GET['filter']) && !$error): ?>
  <div class="alert alert-info">Tidak ada data status pada desa ini.</div>
<?php endif; ?>
<script>
const kabupaten = document.getElementById('kab_id');
if (kabupaten) {
  kabupaten.addEventListener('change', function () {
    document.getElementById('kec_id').value = '';
    document.getElementById('desa_id').value = '';
    this.form.submit();
  });
}
document.getElementById('kec_id').addEventListener('change', function () {
  document.getElementById('desa_id').value = '';
  this.form.submit();
});
</script>
<?php render_footer(); ?>
