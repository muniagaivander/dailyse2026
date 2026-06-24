<?php
require __DIR__ . '/layout.php';
$user = require_role(['superadmin', 'admin_kab', 'viewer_prov', 'viewer_kab']);
$filters = [
    'kab_id' => $_GET['kab_id'] ?? '',
    'kec_id' => $_GET['kec_id'] ?? '',
    'desa_id' => $_GET['desa_id'] ?? '',
    'view_mode' => ($_GET['view_mode'] ?? 'card') === 'table' ? 'table' : 'card',
    'card_sort' => ($_GET['card_sort'] ?? 'desc') === 'asc' ? 'asc' : 'desc',
];
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
    'draft_count' => $statusFields['draft_count'],
    'submitted_by_pencacah' => $statusFields['submitted_by_pencacah'],
    'rejected_by_pengawas' => $statusFields['rejected_by_pengawas'],
    'pending_count' => $statusFields['pending_count'],
    'approved_by_pengawas' => $statusFields['approved_by_pengawas'],
];
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 100;
$totalRows = 0;
$totalPages = 0;
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
            ORDER BY k.id, kc.kdkec, d.kddesa, sl.kdsls, ms.kdsubsls
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
    if (!$canShowStatus) {
        http_response_code(400);
        exit('Pilih salah satu kabupaten terlebih dahulu.');
    }
    $format = ($_GET['format'] ?? 'csv') === 'xlsx' ? 'xlsx' : 'csv';
    [$sqlWhere, $params] = status_view_build_filter($user, $filters);
    $stmt = db()->prepare(status_view_select_sql($sqlWhere));
    $stmt->execute($params);
    $exportSource = $stmt->fetchAll();
    $headers = ['Kabupaten', 'Kecamatan', 'Desa', 'Kode SubSLS', 'SLS', 'SubSLS', 'Pengawas', 'Pencacah', 'Target'];
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
            $r['kab_id'] . $r['kdkec'] . $r['kddesa'] . $r['kdsls'] . $r['kdsubsls'],
            $r['nmsls'],
            $r['kdsubsls'],
            petugas_label($r['pengawas_email'], $r['pengawas_name'] ?? ''),
            petugas_label($r['pencacah_email'], $r['pencacah_name'] ?? ''),
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
<style>
.status-view-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
  gap: 14px;
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
<div class="alert alert-light border mb-3">
  <strong><em>Progress Pendataan = Submit+Reject+Pending+Approve</em></strong>
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
        <div class="status-summary-card" data-card-search="<?= e(strtolower(status_view_card_title($card, 'pml'))) ?>">
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
        <div class="status-summary-card" data-card-search="<?= e(strtolower(petugas_label($card['email'], $card['petugas_name'] ?? ''))) ?>">
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
        $exportQuery = ['filter' => 1, 'kab_id' => $filters['kab_id'], 'kec_id' => $filters['kec_id'], 'desa_id' => $filters['desa_id'], 'view_mode' => $filters['view_mode'], 'action' => 'export'];
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
          <td><?= e($r['kab_id'] . $r['kdkec'] . $r['kddesa'] . $r['kdsls'] . $r['kdsubsls']) ?></td>
          <td><?= e($r['nmdesa']) ?></td>
          <td><?= e($r['nmsls']) ?></td>
          <td><?= e($r['kdsubsls']) ?></td>
          <td><?= e(petugas_label($r['pengawas_email'], $r['pengawas_name'] ?? '')) ?></td>
          <td><?= e(petugas_label($r['pencacah_email'], $r['pencacah_name'] ?? '')) ?></td>
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
        $baseQuery = ['filter' => 1, 'kab_id' => $filters['kab_id'], 'kec_id' => $filters['kec_id'], 'desa_id' => $filters['desa_id'], 'view_mode' => $filters['view_mode']];
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
function bindCardSearch(inputId, gridId) {
  const input = document.getElementById(inputId);
  const grid = document.getElementById(gridId);
  if (!input || !grid) return;
  input.addEventListener('input', function () {
    const keyword = this.value.trim().toLowerCase();
    grid.querySelectorAll('.status-summary-card').forEach(function (card) {
      const haystack = card.dataset.cardSearch || '';
      card.style.display = haystack.includes(keyword) ? '' : 'none';
    });
  });
}
bindCardSearch('pmlSearch', 'pmlCardGrid');
bindCardSearch('pclSearch', 'pclCardGrid');
</script>
<?php render_footer(); ?>
