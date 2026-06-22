<?php
require __DIR__ . '/layout.php';
$user = require_role(['superadmin', 'admin_kab', 'viewer_prov', 'viewer_kab']);
$filters = [
    'kab_id' => $_GET['kab_id'] ?? '',
    'kec_id' => $_GET['kec_id'] ?? '',
    'desa_id' => $_GET['desa_id'] ?? '',
];
if (in_array($user['role'], ['admin_kab', 'viewer_kab'], true)) {
    $filters['kab_id'] = $user['kab_id'];
}

function petugas_filter_options(array $user, array $filters): array
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

function petugas_xlsx_col(int $index): string
{
    $name = '';
    while ($index > 0) {
        $index--;
        $name = chr(65 + ($index % 26)) . $name;
        $index = intdiv($index, 26);
    }
    return $name;
}

function petugas_xlsx_cell(string $value, int $row, int $col): string
{
    $ref = petugas_xlsx_col($col) . $row;
    return '<c r="' . $ref . '" t="inlineStr"><is><t>' . htmlspecialchars($value, ENT_XML1) . '</t></is></c>';
}

function petugas_template_rows(array $user, array $filters): array
{
    $where = ['k.id=?'];
    $params = [$user['kab_id']];
    if (!empty($filters['kec_id'])) {
        $where[] = 'kc.id=?';
        $params[] = $filters['kec_id'];
    }
    if (!empty($filters['desa_id'])) {
        $where[] = 'd.id=?';
        $params[] = $filters['desa_id'];
    }
    $stmt = db()->prepare("SELECT ms.id subsls_id, p.id prov_id, k.id kab_id, kc.kdkec,
            d.kddesa, sl.kdsls, sl.nmsls, ms.kdsubsls,
            ms.pengawas_email, ms.pencacah_email
        FROM master_subsls ms
        JOIN master_sls sl ON sl.id=ms.sls_id
        JOIN master_desa d ON d.id=sl.desa_id
        JOIN master_kec kc ON kc.id=d.kec_id
        JOIN master_kab k ON k.id=kc.kab_id
        JOIN master_prov p ON p.id=k.prov_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY k.id, kc.kdkec, d.kddesa, sl.kdsls, ms.kdsubsls");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function petugas_download_assignment_template(array $user, array $filters): void
{
    $sheetRows = [[
        'subsls_id',
        'kode_subsls',
        'provinsi',
        'kabupaten',
        'kecamatan',
        'desa',
        'sls',
        'nama_sls',
        'subsls',
        'pengawas_email',
        'pencacah_email',
    ]];
    foreach (petugas_template_rows($user, $filters) as $row) {
        $sheetRows[] = [
            $row['subsls_id'],
            $row['kdsls'] . $row['kdsubsls'],
            $row['prov_id'],
            $row['kab_id'],
            $row['kdkec'],
            $row['kddesa'],
            $row['kdsls'],
            $row['nmsls'],
            $row['kdsubsls'],
            $row['pengawas_email'],
            $row['pencacah_email'],
        ];
    }

    $tmp = tempnam(sys_get_temp_dir(), 'petugas_tpl_');
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
  <sheets><sheet name="template_ganti_petugas" sheetId="1" r:id="rId1"/></sheets>
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
            $sheet .= petugas_xlsx_cell((string)$value, $rowNumber, $cIndex + 1);
        }
        $sheet .= '</row>';
    }
    $sheet .= '</sheetData></worksheet>';
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
    $zip->close();

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="template_ganti_petugas_' . $user['kab_id'] . '.xlsx"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    unlink($tmp);
    exit;
}

if (($_GET['action'] ?? '') === 'download_petugas_template' && $user['role'] === 'admin_kab') {
    petugas_download_assignment_template($user, $filters);
}

$opts = petugas_filter_options($user, $filters);
$rows = [];
$error = null;
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 100;
$totalRows = 0;
$totalPages = 0;
$petugasSummary = ['pengawas' => 0, 'pencacah' => 0];

if (isset($_GET['filter'])) {
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
    $sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';
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

    $summaryStmt = db()->prepare("SELECT
            COUNT(DISTINCT NULLIF(ms.pengawas_email, '')) jumlah_pengawas,
            COUNT(DISTINCT NULLIF(ms.pencacah_email, '')) jumlah_pencacah
        FROM master_subsls ms
        JOIN master_sls sl ON sl.id=ms.sls_id
        JOIN master_desa d ON d.id=sl.desa_id
        JOIN master_kec kc ON kc.id=d.kec_id
        JOIN master_kab k ON k.id=kc.kab_id
        $sqlWhere");
    $summaryStmt->execute($params);
    $summary = $summaryStmt->fetch() ?: [];
    $petugasSummary = [
        'pengawas' => (int)($summary['jumlah_pengawas'] ?? 0),
        'pencacah' => (int)($summary['jumlah_pencacah'] ?? 0),
    ];

    $stmt = db()->prepare("SELECT p.nmprov, k.id kab_id, k.nmkab, kc.kdkec, kc.nmkec, d.kddesa, d.nmdesa, sl.kdsls, sl.nmsls,
                ms.kdsubsls, ms.nmsubsls, ms.pengawas_email, ms.pencacah_email
            FROM master_subsls ms
            JOIN master_sls sl ON sl.id=ms.sls_id
            JOIN master_desa d ON d.id=sl.desa_id
            JOIN master_kec kc ON kc.id=d.kec_id
            JOIN master_kab k ON k.id=kc.kab_id
            JOIN master_prov p ON p.id=k.prov_id
            $sqlWhere
            ORDER BY k.id, kc.kdkec, d.kddesa, sl.kdsls, ms.kdsubsls
            LIMIT $perPage OFFSET $offset");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
}

render_header('Daftar Petugas');
?>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<form class="card card-body mb-3" method="get">
  <div class="form-row align-items-end">
    <?php if (in_array($user['role'], ['superadmin', 'viewer_prov'], true)): ?>
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
<?php if (isset($_GET['filter'])): ?>
  <div class="card card-body py-2 mb-3">
    <div><strong>Jumlah Pengawas:</strong> <?= number_format($petugasSummary['pengawas'], 0, ',', '.') ?></div>
    <div><strong>Jumlah Pencacah:</strong> <?= number_format($petugasSummary['pencacah'], 0, ',', '.') ?></div>
  </div>
<?php endif; ?>
<?php if ($user['role'] === 'admin_kab'): ?>
  <?php $downloadQuery = ['action' => 'download_petugas_template', 'kab_id' => $filters['kab_id'], 'kec_id' => $filters['kec_id'], 'desa_id' => $filters['desa_id']]; ?>
  <div class="card card-body mb-3">
    <strong>Template Ganti Petugas</strong>
    <div class="text-muted small mb-2">Download dan isi template lalu kirimkan ke ADMIN Provinsi (Muniaga Ivander).</div>
    <div><a class="btn btn-success" href="petugas.php?<?= e(http_build_query($downloadQuery)) ?>"><i class="fas fa-download mr-1"></i>Download Template Ganti Petugas</a></div>
  </div>
<?php endif; ?>
<?php if ($rows): ?>
<div class="card">
  <div class="card-header py-2">
    <span>Menampilkan <?= number_format(count($rows), 0, ',', '.') ?> dari <?= number_format($totalRows, 0, ',', '.') ?> SubSLS</span>
  </div>
  <div class="card-body table-responsive p-0">
    <table class="table table-sm table-bordered table-striped mb-0">
      <thead><tr><th>Kode SubSLS</th><th>Desa</th><th>SLS</th><th>SubSLS</th><th>Pengawas</th><th>Pencacah</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= e($r['kab_id'] . $r['kdkec'] . $r['kddesa'] . $r['kdsls'] . $r['kdsubsls']) ?></td>
          <td><?= e($r['nmdesa']) ?></td>
          <td><?= e($r['kdsls'] . ' - ' . $r['nmsls']) ?></td>
          <td><?= e($r['kdsubsls']) ?></td>
          <td><?= e($r['pengawas_email']) ?></td>
          <td><?= e($r['pencacah_email']) ?></td>
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
  <div class="alert alert-info">Tidak ada SubSLS pada desa ini.</div>
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
