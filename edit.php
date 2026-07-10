<?php
require __DIR__ . '/layout.php';
$user = require_role(['superadmin', 'admin_kab']);

function edit_filters_from_request(): array
{
    return [
        'kab_id' => $_GET['kab_id'] ?? ($_POST['kab_id'] ?? ''),
        'kec_id' => $_GET['kec_id'] ?? ($_POST['kec_id'] ?? ''),
        'desa_id' => $_GET['desa_id'] ?? ($_POST['desa_id'] ?? ''),
        'pengawas_email' => normalize_email($_GET['pengawas_email'] ?? ($_POST['pengawas_email'] ?? '')),
        'pencacah_email' => normalize_email($_GET['pencacah_email'] ?? ($_POST['pencacah_email'] ?? '')),
    ];
}

function edit_filter_where(array $user, array $filters, string $alias = 'ds'): array
{
    $where = [];
    $params = [];
    if ($user['role'] === 'admin_kab') {
        $where[] = 'k.id=?';
        $params[] = $user['kab_id'];
    } elseif ($user['role'] === 'pengawas') {
        $where[] = $alias . '.pengawas_email=?';
        $params[] = $user['email'];
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
    if ($filters['pengawas_email']) {
        $where[] = $alias . '.pengawas_email=?';
        $params[] = $filters['pengawas_email'];
    }
    if ($filters['pencacah_email']) {
        $where[] = $alias . '.pencacah_email=?';
        $params[] = $filters['pencacah_email'];
    }
    return [$where, $params];
}

function edit_options_where(array $user, array $filters, array $keys): array
{
    $where = [];
    $params = [];
    if ($user['role'] === 'admin_kab') {
        $where[] = 'k.id=?';
        $params[] = $user['kab_id'];
    } elseif ($user['role'] === 'pengawas') {
        $where[] = 'ms.pengawas_email=?';
        $params[] = $user['email'];
    }
    $map = [
        'kab_id' => 'k.id',
        'kec_id' => 'kc.id',
        'desa_id' => 'd.id',
        'pengawas_email' => 'ms.pengawas_email',
    ];
    foreach ($keys as $key) {
        if (!empty($filters[$key]) && isset($map[$key])) {
            $where[] = $map[$key] . '=?';
            $params[] = $filters[$key];
        }
    }
    return [$where ? 'WHERE ' . implode(' AND ', $where) : '', $params];
}

function edit_area_options(array $user, array $filters): array
{
    $out = ['kabupaten' => [], 'kecamatan' => [], 'desa' => [], 'pengawas' => [], 'pencacah' => []];
    if ($user['role'] === 'superadmin') {
        $out['kabupaten'] = db()->query("SELECT id value, CONCAT(id,' - ',nmkab) label FROM master_kab ORDER BY id")->fetchAll();
    }

    [$whereKec, $paramsKec] = edit_options_where($user, $filters, ['kab_id']);
    $stmt = db()->prepare("SELECT DISTINCT kc.id value, CONCAT(kc.kdkec,' - ',kc.nmkec) label
        FROM master_subsls ms
        JOIN master_sls sl ON sl.id=ms.sls_id
        JOIN master_desa d ON d.id=sl.desa_id
        JOIN master_kec kc ON kc.id=d.kec_id
        JOIN master_kab k ON k.id=kc.kab_id
        $whereKec
        ORDER BY label");
    $stmt->execute($paramsKec);
    $out['kecamatan'] = $stmt->fetchAll();

    [$whereDesa, $paramsDesa] = edit_options_where($user, $filters, ['kab_id', 'kec_id']);
    $stmt = db()->prepare("SELECT DISTINCT d.id value, CONCAT(d.kddesa,' - ',d.nmdesa) label
        FROM master_subsls ms
        JOIN master_sls sl ON sl.id=ms.sls_id
        JOIN master_desa d ON d.id=sl.desa_id
        JOIN master_kec kc ON kc.id=d.kec_id
        JOIN master_kab k ON k.id=kc.kab_id
        $whereDesa
        ORDER BY label");
    $stmt->execute($paramsDesa);
    $out['desa'] = $stmt->fetchAll();

    if ($user['role'] !== 'pengawas') {
        [$wherePengawas, $paramsPengawas] = edit_options_where($user, $filters, ['kab_id', 'kec_id', 'desa_id']);
        $wherePengawas .= $wherePengawas ? " AND ms.pengawas_email IS NOT NULL AND ms.pengawas_email <> ''" : "WHERE ms.pengawas_email IS NOT NULL AND ms.pengawas_email <> ''";
        $stmt = db()->prepare("SELECT DISTINCT ms.pengawas_email value, up.name
            FROM master_subsls ms
            JOIN master_sls sl ON sl.id=ms.sls_id
            JOIN master_desa d ON d.id=sl.desa_id
            JOIN master_kec kc ON kc.id=d.kec_id
            JOIN master_kab k ON k.id=kc.kab_id
            LEFT JOIN users up ON up.email=ms.pengawas_email
            $wherePengawas
            ORDER BY up.name, ms.pengawas_email");
        $stmt->execute($paramsPengawas);
        $out['pengawas'] = array_map(fn($row) => [
            'value' => $row['value'],
            'label' => petugas_label($row['value'], $row['name'] ?? ''),
        ], $stmt->fetchAll());
    }

    [$wherePencacah, $paramsPencacah] = edit_options_where($user, $filters, ['kab_id', 'kec_id', 'desa_id', 'pengawas_email']);
    $wherePencacah .= $wherePencacah ? " AND ms.pencacah_email IS NOT NULL AND ms.pencacah_email <> ''" : "WHERE ms.pencacah_email IS NOT NULL AND ms.pencacah_email <> ''";
    $stmt = db()->prepare("SELECT DISTINCT ms.pencacah_email value, uc.name
        FROM master_subsls ms
        JOIN master_sls sl ON sl.id=ms.sls_id
        JOIN master_desa d ON d.id=sl.desa_id
        JOIN master_kec kc ON kc.id=d.kec_id
        JOIN master_kab k ON k.id=kc.kab_id
        LEFT JOIN users uc ON uc.email=ms.pencacah_email
        $wherePencacah
        ORDER BY uc.name, ms.pencacah_email");
    $stmt->execute($paramsPencacah);
    $out['pencacah'] = array_map(fn($row) => [
        'value' => $row['value'],
        'label' => petugas_label($row['value'], $row['name'] ?? ''),
    ], $stmt->fetchAll());

    return $out;
}

function edit_daily_dates(array $user, array $filters): array
{
    [$where, $params] = edit_filter_where($user, $filters);
    $sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $stmt = db()->prepare("SELECT DISTINCT ds.tanggal
        FROM daily_status ds
        JOIN master_subsls ms ON ms.id=ds.subsls_id
        JOIN master_sls sl ON sl.id=ms.sls_id
        JOIN master_desa d ON d.id=sl.desa_id
        JOIN master_kec kc ON kc.id=d.kec_id
        JOIN master_kab k ON k.id=kc.kab_id
        $sqlWhere
        ORDER BY ds.tanggal DESC");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function edit_refresh_subsls_status(string $subslsId): void
{
    $stmt = db()->prepare("SELECT open_count,draft_count,submitted_by_pencacah,approved_by_pengawas,rejected_by_pengawas,pending_count,target,updated_at,updated_by
        FROM daily_status
        WHERE subsls_id=?
        ORDER BY tanggal DESC, updated_at DESC, id DESC
        LIMIT 1");
    $stmt->execute([$subslsId]);
    $latest = $stmt->fetch();
    if (!$latest) {
        return;
    }
    db()->prepare("REPLACE INTO subsls_status (subsls_id,open_count,draft_count,submitted_by_pencacah,approved_by_pengawas,rejected_by_pengawas,pending_count,target,last_update,updated_by)
        VALUES (?,?,?,?,?,?,?,?,?,?)")
        ->execute([
            $subslsId,
            $latest['open_count'],
            $latest['draft_count'],
            $latest['submitted_by_pencacah'],
            $latest['approved_by_pengawas'],
            $latest['rejected_by_pengawas'],
            $latest['pending_count'],
            $latest['target'],
            $latest['updated_at'],
            $latest['updated_by'],
        ]);
}

function edit_user_can_touch_row(array $user, string $date, string $subslsId): bool
{
    $where = ['ds.tanggal=?', 'ds.subsls_id=?'];
    $params = [$date, $subslsId];
    if ($user['role'] === 'admin_kab') {
        $where[] = 'k.id=?';
        $params[] = $user['kab_id'];
    } elseif ($user['role'] === 'pengawas') {
        $where[] = 'ds.pengawas_email=?';
        $params[] = $user['email'];
    }
    $stmt = db()->prepare("SELECT COUNT(*)
        FROM daily_status ds
        JOIN master_subsls ms ON ms.id=ds.subsls_id
        JOIN master_sls sl ON sl.id=ms.sls_id
        JOIN master_desa d ON d.id=sl.desa_id
        JOIN master_kec kc ON kc.id=d.kec_id
        JOIN master_kab k ON k.id=kc.kab_id
        WHERE " . implode(' AND ', $where));
    $stmt->execute($params);
    return (int)$stmt->fetchColumn() > 0;
}

function save_edit_daily(string $date, string $subslsId, array $user, array $post, int $i): void
{
    if (!edit_user_can_touch_row($user, $date, $subslsId)) {
        return;
    }
    $open = max(0, (int)($post['open_count'][$i] ?? 0));
    $draft = max(0, (int)($post['draft_count'][$i] ?? 0));
    $submitted = max(0, (int)($post['submitted_by_pencacah'][$i] ?? 0));
    $approved = max(0, (int)($post['approved_by_pengawas'][$i] ?? 0));
    $rejected = max(0, (int)($post['rejected_by_pengawas'][$i] ?? 0));
    $pending = max(0, (int)($post['pending_count'][$i] ?? 0));
    $target = $open + $draft + $submitted + $approved + $rejected + $pending;

    db()->prepare("UPDATE daily_status
        SET target=?, open_count=?, draft_count=?, submitted_by_pencacah=?, approved_by_pengawas=?, rejected_by_pengawas=?, pending_count=?, updated_by=?
        WHERE tanggal=? AND subsls_id=?")
        ->execute([$target, $open, $draft, $submitted, $approved, $rejected, $pending, $user['email'], $date, $subslsId]);
    edit_refresh_subsls_status($subslsId);
}

function edit_daily_rows(array $user, array $filters, string $date): array
{
    [$where, $params] = edit_filter_where($user, $filters);
    $where[] = 'ds.tanggal=?';
    $params[] = $date;
    $stmt = db()->prepare("SELECT ds.*, p.id prov_id, p.nmprov, k.id kab_id, k.nmkab,
            kc.id kec_id, kc.kdkec, kc.nmkec, d.id desa_id, d.kddesa, d.nmdesa,
            sl.id sls_id, sl.kdsls, sl.nmsls, ms.kdsubsls, ms.nmsubsls,
            up.name pengawas_name, uc.name pencacah_name
        FROM daily_status ds
        JOIN master_subsls ms ON ms.id=ds.subsls_id
        JOIN master_sls sl ON sl.id=ms.sls_id
        JOIN master_desa d ON d.id=sl.desa_id
        JOIN master_kec kc ON kc.id=d.kec_id
        JOIN master_kab k ON k.id=kc.kab_id
        JOIN master_prov p ON p.id=k.prov_id
        LEFT JOIN users up ON up.email=ds.pengawas_email
        LEFT JOIN users uc ON uc.email=ds.pencacah_email
        WHERE " . implode(' AND ', $where) . "
        ORDER BY uc.name, ds.pencacah_email, d.nmdesa, sl.kdsls, ms.kdsubsls");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function edit_xlsx_col(int $index): string
{
    $name = '';
    while ($index > 0) {
        $index--;
        $name = chr(65 + ($index % 26)) . $name;
        $index = intdiv($index, 26);
    }
    return $name;
}

function edit_xlsx_numeric_value($value): ?string
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

function edit_xlsx_header_is_numeric(string $header): bool
{
    $header = strtolower($header);
    foreach (['kode', 'id', 'email', 'nama', 'tanggal', 'kabupaten', 'kecamatan', 'desa', 'sls', 'updated', 'by'] as $textPart) {
        if (str_contains($header, $textPart)) {
            return false;
        }
    }
    foreach (['target', 'open', 'draft', 'submit', 'reject', 'pending', 'approve', 'approved'] as $numericPart) {
        if (str_contains($header, $numericPart)) {
            return true;
        }
    }
    return false;
}

function edit_xlsx_cell($value, int $row, int $col, bool $numeric = false): string
{
    $ref = edit_xlsx_col($col) . $row;
    if ($numeric) {
        $number = edit_xlsx_numeric_value($value);
        if ($number !== null) {
            return '<c r="' . $ref . '"><v>' . htmlspecialchars($number, ENT_XML1) . '</v></c>';
        }
    }
    $value = (string)$value;
    return '<c r="' . $ref . '" t="inlineStr"><is><t>' . htmlspecialchars($value, ENT_XML1) . '</t></is></c>';
}

function edit_export_rows(array $headers, array $rows, string $filename, string $format): void
{
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

    $sheetRows = array_merge([$headers], $rows);
    $tmp = tempnam(sys_get_temp_dir(), 'edit_export_');
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
  <sheets><sheet name="edit_harian" sheetId="1" r:id="rId1"/></sheets>
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
            $numeric = $rowNumber > 1 && edit_xlsx_header_is_numeric((string)($headers[$cIndex] ?? ''));
            $sheet .= edit_xlsx_cell($value, $rowNumber, $cIndex + 1, $numeric);
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

function edit_export_payload(array $rows): array
{
    $headers = ['tanggal', 'prov_id', 'kab_id', 'kabupaten', 'kec_id', 'kecamatan', 'desa_id', 'desa', 'sls_id', 'kode_sls', 'nama_sls', 'subsls_id', 'kode_subsls', 'nama_subsls', 'pengawas_email', 'pencacah_email', 'target', 'open', 'draft', 'submit', 'reject', 'pending', 'approved', 'updated_at', 'updated_by'];
    $out = [];
    foreach ($rows as $row) {
        $out[] = [
            $row['tanggal'],
            $row['prov_id'],
            $row['kab_id'],
            $row['nmkab'],
            $row['kec_id'],
            $row['kdkec'] . ' - ' . $row['nmkec'],
            $row['desa_id'],
            $row['kddesa'] . ' - ' . $row['nmdesa'],
            $row['sls_id'],
            $row['kdsls'],
            $row['nmsls'],
            $row['subsls_id'],
            $row['kdsls'] . $row['kdsubsls'],
            $row['nmsubsls'],
            petugas_label($row['pengawas_email'], $row['pengawas_name'] ?? ''),
            petugas_label($row['pencacah_email'], $row['pencacah_name'] ?? ''),
            $row['target'],
            $row['open_count'],
            $row['draft_count'],
            $row['submitted_by_pencacah'],
            $row['rejected_by_pengawas'],
            $row['pending_count'],
            $row['approved_by_pengawas'],
            $row['updated_at'],
            $row['updated_by'],
        ];
    }
    return [$headers, $out];
}

$filters = edit_filters_from_request();
if ($user['role'] === 'admin_kab') {
    $filters['kab_id'] = $user['kab_id'];
}
if ($user['role'] === 'pengawas') {
    $filters['pengawas_email'] = $user['email'];
}

if (($_GET['action'] ?? '') === 'export') {
    $date = $_GET['tanggal'] ?? '';
    $format = ($_GET['format'] ?? 'csv') === 'xlsx' ? 'xlsx' : 'csv';
    if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        http_response_code(400);
        exit('Tanggal export tidak valid.');
    }
    [$headers, $exportRows] = edit_export_payload(edit_daily_rows($user, $filters, $date));
    $nameParts = ['data_harian', $date];
    if (!empty($filters['pengawas_email'])) {
        $nameParts[] = preg_replace('/[^a-zA-Z0-9]+/', '_', $filters['pengawas_email']);
    }
    edit_export_rows($headers, $exportRows, implode('_', $nameParts), $format);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $date = $_POST['tanggal'] ?? '';
    $ids = $_POST['subsls_id'] ?? [];
    $redirectQuery = ['action' => 'form', 'tanggal' => $date] + $filters;
    db()->beginTransaction();
    try {
        foreach ($ids as $i => $id) {
            save_edit_daily($date, (string)$id, $user, $_POST, $i);
        }
        db()->commit();
        flash('success', 'Data tanggal ini berhasil diedit.');
    } catch (Throwable $e) {
        db()->rollBack();
        flash('error', $e->getMessage());
    }
    redirect('edit.php?' . http_build_query($redirectQuery));
}

$opts = edit_area_options($user, $filters);
$action = $_GET['action'] ?? '';
$filterReady = $user['role'] === 'pengawas'
    || ((bool)$filters['pengawas_email'] && ($user['role'] !== 'superadmin' || (bool)$filters['kab_id']));
$showDates = $filterReady && in_array($action, ['dates', 'form'], true);
$showForm = $action === 'form';
$dates = $showDates ? edit_daily_dates($user, $filters) : [];
$date = $_GET['tanggal'] ?? ($dates[0]['tanggal'] ?? '');
$rows = [];
$groups = [];
if ($showForm && $date) {
    $rows = edit_daily_rows($user, $filters, $date);
    foreach ($rows as $row) {
        $groups[petugas_label($row['pencacah_email'], $row['pencacah_name'] ?? '')][] = $row;
    }
}

$EXTRA_HEAD = '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">' . "\n"
    . '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@ttskch/select2-bootstrap4-theme@1.5.2/dist/select2-bootstrap4.min.css">';
$EXTRA_FOOTER_SCRIPTS = '<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>';

render_header('Edit Harian');
?>
<?php if ($msg = flash('success')): ?><div class="alert alert-success"><?= e($msg) ?></div><?php endif; ?>
<?php if ($msg = flash('error')): ?><div class="alert alert-danger"><?= e($msg) ?></div><?php endif; ?>

<form class="card card-body mb-3" method="get" id="editFilterForm">
  <div class="form-row align-items-end">
    <?php if ($user['role'] === 'superadmin'): ?>
      <div class="form-group col-md-4">
        <label>Kabupaten</label>
        <select class="form-control" name="kab_id" id="kab_id">
          <option value="">Pilih Kabupaten</option>
          <?php foreach ($opts['kabupaten'] as $o): ?><option value="<?= e($o['value']) ?>" <?= $filters['kab_id']===$o['value']?'selected':'' ?>><?= e($o['label']) ?></option><?php endforeach; ?>
        </select>
      </div>
    <?php else: ?>
      <input type="hidden" name="kab_id" value="<?= e($filters['kab_id']) ?>">
    <?php endif; ?>
    <?php if ($user['role'] !== 'pengawas'): ?>
      <div class="form-group col-md-5">
        <label>Pengawas</label>
        <select class="form-control select2-pengawas" name="pengawas_email" id="pengawas_email" <?= ($user['role'] === 'superadmin' && !$filters['kab_id']) ? 'disabled' : '' ?>>
          <option value=""><?= ($user['role'] === 'superadmin' && !$filters['kab_id']) ? 'Pilih kabupaten dulu' : 'Pilih Pengawas' ?></option>
          <?php foreach ($opts['pengawas'] as $o): ?><option value="<?= e($o['value']) ?>" <?= $filters['pengawas_email']===$o['value']?'selected':'' ?>><?= e($o['label']) ?></option><?php endforeach; ?>
        </select>
      </div>
    <?php endif; ?>
    <div class="form-group col-md-3"><button class="btn btn-primary" id="showDatesButton" name="action" value="dates" <?= $filterReady ? '' : 'disabled' ?>>Tampilkan Tanggal</button></div>
  </div>
  <?php if ($showDates): ?>
    <hr class="mt-0">
    <div class="form-row align-items-end" id="dateSection">
      <div class="form-group col-md-3">
        <label>Tanggal yang Akan Diedit</label>
        <select class="form-control" name="tanggal" <?= $dates ? '' : 'disabled' ?> required>
          <?php if (!$dates): ?>
            <option value="">Tidak ada tanggal pada filter ini</option>
          <?php else: ?>
            <?php foreach ($dates as $d): ?><option value="<?= e($d['tanggal']) ?>" <?= $date===$d['tanggal']?'selected':'' ?>><?= e($d['tanggal']) ?></option><?php endforeach; ?>
          <?php endif; ?>
        </select>
      </div>
      <div class="form-group col-md-3"><button class="btn btn-success" name="action" value="form" <?= $dates ? '' : 'disabled' ?>>Tampilkan Form Edit</button></div>
    </div>
  <?php endif; ?>
</form>

<?php if ($showDates && !$dates): ?>
  <div class="alert alert-info" id="noDatesAlert">Tidak ada tanggal input harian pada filter ini.</div>
<?php endif; ?>

<?php if ($rows): ?>
<?php
  $exportBaseQuery = array_merge($filters, ['action' => 'export', 'tanggal' => $date]);
  $exportCsvQuery = array_merge($exportBaseQuery, ['format' => 'csv']);
  $exportXlsxQuery = array_merge($exportBaseQuery, ['format' => 'xlsx']);
?>
<style>
.edit-pencacah-tabs .nav-link {
  border: 1px solid #86efac;
  color: #111827;
  margin: 0 6px 6px 0;
}
.edit-pencacah-tabs .nav-link.active {
  background: #dcfce7;
  border-color: #22c55e;
  color: #111827;
}
</style>
<form method="post" id="editResult" data-progress-submit data-progress-title="Menyimpan edit harian..." data-progress-text="Mohon tunggu, perubahan sedang disimpan.">
  <input type="hidden" name="kab_id" value="<?= e($filters['kab_id']) ?>">
  <input type="hidden" name="kec_id" value="<?= e($filters['kec_id']) ?>">
  <input type="hidden" name="desa_id" value="<?= e($filters['desa_id']) ?>">
  <input type="hidden" name="pengawas_email" value="<?= e($filters['pengawas_email']) ?>">
  <input type="hidden" name="pencacah_email" value="<?= e($filters['pencacah_email']) ?>">
  <input type="hidden" name="tanggal" value="<?= e($date) ?>">
  <div class="card">
    <div class="card-header p-2 d-flex justify-content-between align-items-center">
      <ul class="nav nav-pills edit-pencacah-tabs" role="tablist">
        <?php $tabIndex = 0; foreach ($groups as $pencacah => $items): ?>
          <?php $tabId = 'edit-pencacah-' . $tabIndex; ?>
          <li class="nav-item">
            <a class="nav-link <?= $tabIndex === 0 ? 'active' : '' ?>" data-toggle="tab" href="#<?= e($tabId) ?>" role="tab">
              <?= e($pencacah) ?> <span class="badge badge-light ml-1"><?= count($items) ?></span>
            </a>
          </li>
        <?php $tabIndex++; endforeach; ?>
      </ul>
      <span class="text-muted small">Tanggal: <?= e($date) ?></span>
    </div>
    <div class="card-body p-0">
      <div class="tab-content">
        <?php $tabIndex = 0; foreach ($groups as $pencacah => $items): ?>
          <?php $tabId = 'edit-pencacah-' . $tabIndex; ?>
          <div class="tab-pane fade <?= $tabIndex === 0 ? 'show active' : '' ?>" id="<?= e($tabId) ?>" role="tabpanel">
            <div class="table-responsive">
              <table class="table table-sm table-bordered mb-0">
                <thead>
                  <tr>
                    <th>Desa</th><th>SLS</th><th>Kode SubSLS</th><th>SubSLS</th><th>Target</th>
                    <?php foreach (daily_form_status_fields() as $label): ?><th><?= e($label) ?></th><?php endforeach; ?>
                  </tr>
                </thead>
                <tbody>
                <?php foreach ($items as $r): ?>
                  <tr>
                    <td><?= e($r['nmdesa']) ?></td>
                    <td><?= e($r['nmsls']) ?></td>
                    <td><?= e($r['kdsls'] . $r['kdsubsls']) ?><input type="hidden" name="subsls_id[]" value="<?= e($r['subsls_id']) ?>"></td>
                    <td><?= e($r['kdsubsls']) ?></td>
                    <td><input class="form-control form-control-sm target" disabled value="<?= e($r['target']) ?>"></td>
                    <?php foreach (array_keys(daily_form_status_fields()) as $f): ?>
                      <td><input class="form-control form-control-sm status-input" type="number" min="0" name="<?= $f ?>[]" value="<?= e($r[$f]) ?>"></td>
                    <?php endforeach; ?>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        <?php $tabIndex++; endforeach; ?>
      </div>
    </div>
  </div>
  <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 mt-3">
    <button class="btn btn-success mb-2" type="submit">Edit Data Tanggal Ini</button>
    <div class="mb-2">
      <a class="btn btn-outline-success mr-2" href="?<?= e(http_build_query($exportXlsxQuery)) ?>"><i class="fas fa-file-excel mr-1"></i>Export XLSX</a>
      <a class="btn btn-outline-success" href="?<?= e(http_build_query($exportCsvQuery)) ?>"><i class="fas fa-file-csv mr-1"></i>Export CSV</a>
    </div>
  </div>
</form>
<?php elseif ($showForm && $date): ?>
  <div class="alert alert-info" id="noRowsAlert">Tidak ada data harian pada tanggal dan filter ini.</div>
<?php endif; ?>

<script>
document.querySelectorAll('.status-input').forEach(input => input.addEventListener('input', () => {
  const tr = input.closest('tr');
  tr.querySelector('.target').value = Array.from(tr.querySelectorAll('.status-input')).reduce((s, el) => s + Number(el.value || 0), 0);
}));
const kabupaten = document.getElementById('kab_id');
const pengawasSelect = document.getElementById('pengawas_email');
const editFilterForm = document.getElementById('editFilterForm');
const showDatesButton = document.getElementById('showDatesButton');
function resetDateAndResult() {
  const dateSection = document.getElementById('dateSection');
  const editResult = document.getElementById('editResult');
  const noDatesAlert = document.getElementById('noDatesAlert');
  const noRowsAlert = document.getElementById('noRowsAlert');
  if (dateSection) dateSection.style.display = 'none';
  if (editResult) editResult.style.display = 'none';
  if (noDatesAlert) noDatesAlert.style.display = 'none';
  if (noRowsAlert) noRowsAlert.style.display = 'none';
}
function setFirstOptionLabel(select, label) {
  if (select && select.options.length) {
    select.options[0].textContent = label;
  }
}
function reloadFilterOptions() {
  resetDateAndResult();
  editFilterForm.submit();
}
function syncFilterState() {
  const canUsePengawas = !kabupaten || Boolean(kabupaten.value);
  const canShowDates = <?= $user['role'] === 'pengawas' ? 'true' : 'false' ?> || (canUsePengawas && pengawasSelect && Boolean(pengawasSelect.value));
  if (pengawasSelect) {
    pengawasSelect.disabled = !canUsePengawas;
    setFirstOptionLabel(pengawasSelect, canUsePengawas ? 'Pilih Pengawas' : 'Pilih kabupaten dulu');
    if (!canUsePengawas) pengawasSelect.value = '';
    if (window.jQuery && jQuery.fn.select2) {
      jQuery(pengawasSelect).trigger('change.select2');
    }
  }
  if (showDatesButton) {
    showDatesButton.disabled = !canShowDates;
  }
}
if (kabupaten) {
  kabupaten.addEventListener('change', function () {
    if (pengawasSelect) pengawasSelect.value = '';
    syncFilterState();
    reloadFilterOptions();
  });
}
if (pengawasSelect) {
  pengawasSelect.addEventListener('change', function () {
    syncFilterState();
    resetDateAndResult();
  });
}
syncFilterState();
window.addEventListener('load', function () {
  if (window.jQuery && jQuery.fn.select2 && pengawasSelect) {
    jQuery(pengawasSelect).select2({
      theme: 'bootstrap4',
      width: '100%',
      placeholder: 'Pilih Pengawas'
    });
    jQuery(pengawasSelect).on('change', syncFilterState);
  }
});
</script>
<?php render_footer(); ?>
