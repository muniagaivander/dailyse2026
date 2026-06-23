<?php
require __DIR__ . '/layout.php';
$user = require_role(['superadmin', 'admin_kab', 'pengawas']);
ensure_completion_status_table();

function ct_xlsx_col(int $index): string
{
    $name = '';
    while ($index > 0) {
        $index--;
        $name = chr(65 + ($index % 26)) . $name;
        $index = intdiv($index, 26);
    }
    return $name;
}

function ct_xlsx_cell(string $value, int $row, int $col): string
{
    $ref = ct_xlsx_col($col) . $row;
    return '<c r="' . $ref . '" t="inlineStr"><is><t>' . htmlspecialchars($value, ENT_XML1) . '</t></is></c>';
}

function ct_rows_for_user(array $user): array
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
    $sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $stmt = db()->prepare("SELECT ms.id subsls_id, p.id provinsi, k.id kabupaten, kc.kdkec kecamatan,
            d.kddesa desa, sl.kdsls sls, sl.nmsls nama_sls, ms.kdsubsls subsls, ms.nmsubsls,
            ms.pengawas_email, ms.pencacah_email,
            up.name pengawas_name, uc.name pencacah_name,
            COALESCE(cs.status_selesai, 'Belum Selesai') status_selesai
        FROM master_subsls ms
        JOIN master_sls sl ON sl.id=ms.sls_id
        JOIN master_desa d ON d.id=sl.desa_id
        JOIN master_kec kc ON kc.id=d.kec_id
        JOIN master_kab k ON k.id=kc.kab_id
        JOIN master_prov p ON p.id=k.prov_id
        LEFT JOIN users up ON up.email=ms.pengawas_email
        LEFT JOIN users uc ON uc.email=ms.pencacah_email
        LEFT JOIN subsls_completion_status cs ON cs.subsls_id=ms.id
        $sqlWhere
        ORDER BY k.id, kc.kdkec, d.kddesa, sl.kdsls, ms.kdsubsls");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function ct_download_template(array $user): void
{
    $headers = [
        'subsls_id',
        'kode_subsls',
        'provinsi',
        'kabupaten',
        'kecamatan',
        'desa',
        'sls',
        'nama_sls',
        'subsls',
        'pengawas_nama',
        'pengawas_email',
        'pencacah_nama',
        'pencacah_email',
        'status selesai',
    ];
    $sheetRows = [$headers];
    foreach (ct_rows_for_user($user) as $row) {
        $sheetRows[] = [
            $row['subsls_id'],
            $row['sls'] . $row['subsls'],
            $row['provinsi'],
            $row['kabupaten'],
            $row['kecamatan'],
            $row['desa'],
            $row['sls'],
            $row['nama_sls'],
            $row['subsls'],
            $row['pengawas_name'],
            $row['pengawas_email'],
            $row['pencacah_name'],
            $row['pencacah_email'],
            $row['status_selesai'],
        ];
    }

    $tmp = tempnam(sys_get_temp_dir(), 'completion_');
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
  <sheets><sheet name="status_selesai_subsls" sheetId="1" r:id="rId1"/></sheets>
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
            $sheet .= ct_xlsx_cell((string)$value, $rowNumber, $cIndex + 1);
        }
        $sheet .= '</row>';
    }
    $sheet .= '</sheetData></worksheet>';
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
    $zip->close();

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="template_status_selesai_subsls.xlsx"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    unlink($tmp);
    exit;
}

function ct_read_xlsx_rows(string $path): array
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('File Excel tidak bisa dibuka.');
    }
    $sharedStrings = [];
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($sharedXml !== false) {
        $xml = simplexml_load_string($sharedXml);
        foreach ($xml->si as $si) {
            $text = '';
            if (isset($si->t)) {
                $text = (string)$si->t;
            } elseif (isset($si->r)) {
                foreach ($si->r as $run) {
                    $text .= (string)$run->t;
                }
            }
            $sharedStrings[] = $text;
        }
    }
    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    if ($sheetXml === false) {
        throw new RuntimeException('Sheet pertama tidak ditemukan.');
    }
    $xml = simplexml_load_string($sheetXml);
    $rows = [];
    foreach ($xml->sheetData->row as $row) {
        $values = [];
        foreach ($row->c as $cell) {
            $ref = (string)$cell['r'];
            preg_match('/([A-Z]+)/', $ref, $m);
            $col = 0;
            foreach (str_split($m[1] ?? 'A') as $char) {
                $col = $col * 26 + ord($char) - 64;
            }
            $type = (string)$cell['t'];
            if ($type === 's') {
                $value = $sharedStrings[(int)$cell->v] ?? '';
            } elseif ($type === 'inlineStr') {
                $value = (string)$cell->is->t;
            } else {
                $value = (string)$cell->v;
            }
            $values[$col - 1] = trim($value);
        }
        if ($values) {
            ksort($values);
            $rows[] = $values;
        }
    }
    return $rows;
}

function ct_normalize_status(string $value): string
{
    $normalized = strtolower(trim($value));
    if ($normalized === 'selesai') {
        return 'Selesai';
    }
    if (in_array($normalized, ['belum selesai', 'belum_selesai', 'belum'], true)) {
        return 'Belum Selesai';
    }
    throw new RuntimeException("Status selesai tidak valid: {$value}. Gunakan Selesai atau Belum Selesai.");
}

function ct_can_update(array $user, string $subslsId): bool
{
    $where = ['ms.id=?'];
    $params = [$subslsId];
    if ($user['role'] === 'admin_kab') {
        $where[] = 'kc.kab_id=?';
        $params[] = $user['kab_id'];
    } elseif ($user['role'] === 'pengawas') {
        $where[] = 'ms.pengawas_email=?';
        $params[] = $user['email'];
    }
    $stmt = db()->prepare("SELECT COUNT(*)
        FROM master_subsls ms
        JOIN master_sls sl ON sl.id=ms.sls_id
        JOIN master_desa d ON d.id=sl.desa_id
        JOIN master_kec kc ON kc.id=d.kec_id
        WHERE " . implode(' AND ', $where));
    $stmt->execute($params);
    return (int)$stmt->fetchColumn() > 0;
}

function ct_can_mark_done(string $subslsId): bool
{
    $stmt = db()->prepare("SELECT target, approved_by_pengawas
        FROM subsls_status
        WHERE subsls_id=?");
    $stmt->execute([$subslsId]);
    $status = $stmt->fetch();
    if (!$status) {
        return false;
    }
    return (int)$status['target'] === (int)$status['approved_by_pengawas'];
}

function ct_import_template(string $path, array $user): array
{
    $rows = ct_read_xlsx_rows($path);
    if (!$rows) {
        throw new RuntimeException('File kosong.');
    }
    $headers = array_map(fn($v) => strtolower(trim((string)$v)), $rows[0]);
    $idx = array_flip($headers);
    foreach (['subsls_id', 'status selesai'] as $required) {
        if (!array_key_exists($required, $idx)) {
            throw new RuntimeException("Kolom {$required} tidak ditemukan.");
        }
    }

    $processed = 0;
    $skipped = 0;
    $updates = [];
    $validationErrors = [];
    for ($i = 1; $i < count($rows); $i++) {
        $row = $rows[$i];
        $rowNumber = $i + 1;
        $subslsId = trim((string)($row[$idx['subsls_id']] ?? ''));
        $statusValue = trim((string)($row[$idx['status selesai']] ?? ''));
        if ($subslsId === '' || $statusValue === '') {
            $skipped++;
            continue;
        }
        if (!ct_can_update($user, $subslsId)) {
            $skipped++;
            continue;
        }
        $normalizedStatus = ct_normalize_status($statusValue);
        if ($normalizedStatus === 'Selesai' && !ct_can_mark_done($subslsId)) {
            $validationErrors[] = 'Row ' . $rowNumber . ' idsubsls ' . $subslsId . ', "Masih ada pekerjaan belum selesai (Approve kurang/tidak sama dengan nilai target)"';
            continue;
        }
        $updates[] = [$subslsId, $normalizedStatus, $user['email']];
    }
    if ($validationErrors) {
        throw new RuntimeException(implode("\n", $validationErrors));
    }

    db()->beginTransaction();
    try {
        $stmt = db()->prepare("INSERT INTO subsls_completion_status (subsls_id,status_selesai,updated_by)
            VALUES (?,?,?)
            ON DUPLICATE KEY UPDATE status_selesai=VALUES(status_selesai), updated_by=VALUES(updated_by), updated_at=CURRENT_TIMESTAMP");
        foreach ($updates as $update) {
            $stmt->execute($update);
            $processed++;
        }
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
    }
    return ['read' => max(count($rows) - 1, 0), 'processed' => $processed, 'skipped' => $skipped];
}

if (($_GET['action'] ?? '') === 'download') {
    ct_download_template($user);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $returnTo = in_array($_POST['return_to'] ?? '', ['status_selesai.php', 'completion_template.php'], true) ? $_POST['return_to'] : 'completion_template.php';
    try {
        if ($user['role'] === 'pengawas') {
            throw new RuntimeException('Pengawas hanya dapat download template. Isikan template dan kirim ke Tim SPBE BPS wilayah kerja.');
        }
        if (!isset($_FILES['template']) || $_FILES['template']['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Upload file belum berhasil.');
        }
        if (strtolower(pathinfo($_FILES['template']['name'], PATHINFO_EXTENSION)) !== 'xlsx') {
            throw new RuntimeException('File harus berformat .xlsx.');
        }
        $result = ct_import_template($_FILES['template']['tmp_name'], $user);
        flash('success', 'Template status selesai berhasil diproses. Baris dibaca: ' . $result['read'] . ', valid diproses: ' . $result['processed'] . ', skip: ' . $result['skipped'] . '.');
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }
    redirect($returnTo);
}

render_header('Upload By Template Status SubSLS');
?>
<div class="card">
  <div class="card-body">
    <a class="btn btn-success mb-3" href="completion_template.php?action=download"><i class="fas fa-download mr-1"></i>Download Template Excel</a>
    <form method="post" enctype="multipart/form-data" data-progress-submit data-progress-title="Mengupload status selesai SubSLS..." data-progress-text="Mohon tunggu, template sedang dibaca dan disimpan.">
      <div class="form-group">
        <label>Upload Template Status SubSLS yang Sudah Diisi</label>
        <input class="form-control-file" type="file" name="template" accept=".xlsx" required>
      </div>
      <button class="btn btn-primary"><i class="fas fa-upload mr-1"></i>Upload Status SubSLS</button>
    </form>
  </div>
</div>
<div class="card">
  <div class="card-body">
    <table class="table table-sm table-bordered mb-0">
      <thead><tr><th>Kolom</th><th>Keterangan</th></tr></thead>
      <tbody>
        <tr><td>subsls_id</td><td>Kunci unik wilayah. Boleh upload sebagian baris saja.</td></tr>
        <tr><td>status selesai</td><td>Isi dengan <strong>Selesai</strong> atau <strong>Belum Selesai</strong>.</td></tr>
        <tr><td>pengawas_email dan pencacah_email</td><td>Hanya informasi dari master, tidak dipakai untuk mengganti petugas.</td></tr>
      </tbody>
    </table>
  </div>
</div>
<?php render_footer(); ?>
