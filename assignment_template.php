<?php
require __DIR__ . '/layout.php';
$user = require_role(['superadmin', 'admin_kab']);

function assignment_rows(array $user): array
{
    $where = '';
    $params = [];
    if ($user['role'] === 'admin_kab') {
        $where = 'WHERE k.id=?';
        $params[] = $user['kab_id'];
    }
    $stmt = db()->prepare("SELECT ms.id subsls_id, p.id prov_id, k.id kab_id, kc.kdkec,
            d.kddesa, sl.kdsls, sl.nmsls, ms.kdsubsls, ms.nmsubsls,
            ms.pengawas_email, ms.pencacah_email
        FROM master_subsls ms
        JOIN master_sls sl ON sl.id=ms.sls_id
        JOIN master_desa d ON d.id=sl.desa_id
        JOIN master_kec kc ON kc.id=d.kec_id
        JOIN master_kab k ON k.id=kc.kab_id
        JOIN master_prov p ON p.id=k.prov_id
        $where
        ORDER BY k.id, kc.kdkec, d.kddesa, sl.kdsls, ms.kdsubsls");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function xlsx_col(int $index): string
{
    $name = '';
    while ($index > 0) {
        $index--;
        $name = chr(65 + ($index % 26)) . $name;
        $index = intdiv($index, 26);
    }
    return $name;
}

function xlsx_cell(string $value, int $row, int $col): string
{
    $ref = xlsx_col($col) . $row;
    return '<c r="' . $ref . '" t="inlineStr"><is><t>' . htmlspecialchars($value, ENT_XML1) . '</t></is></c>';
}

function download_assignment_template(array $user): void
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
        'pengawas_email',
        'pencacah_email',
    ];
    $rows = assignment_rows($user);
    $sheetRows = [];
    $sheetRows[] = $headers;
    foreach ($rows as $row) {
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

    $tmp = tempnam(sys_get_temp_dir(), 'petugas_');
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
  <sheets><sheet name="template_petugas" sheetId="1" r:id="rId1"/></sheets>
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
            $sheet .= xlsx_cell((string)$value, $rowNumber, $cIndex + 1);
        }
        $sheet .= '</row>';
    }
    $sheet .= '</sheetData></worksheet>';
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
    $zip->close();

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="template_ganti_petugas.xlsx"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    unlink($tmp);
    exit;
}

function read_xlsx_rows(string $path): array
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

function import_assignment_template(string $path, array $user): array
{
    $rows = read_xlsx_rows($path);
    if (!$rows) {
        throw new RuntimeException('File kosong.');
    }
    $headers = array_map(fn($v) => strtolower(trim((string)$v)), $rows[0]);
    $idx = array_flip($headers);
    foreach (['subsls_id', 'pengawas_email', 'pencacah_email'] as $required) {
        if (!array_key_exists($required, $idx)) {
            throw new RuntimeException("Kolom {$required} tidak ditemukan.");
        }
    }

    $processed = 0;
    $skipped = 0;
    db()->beginTransaction();
    try {
        $stmtExists = db()->prepare("SELECT COUNT(*)
            FROM master_subsls ms
            JOIN master_sls sl ON sl.id=ms.sls_id
            JOIN master_desa d ON d.id=sl.desa_id
            JOIN master_kec kc ON kc.id=d.kec_id
            JOIN master_kab k ON k.id=kc.kab_id
            WHERE ms.id=? " . ($user['role'] === 'admin_kab' ? "AND k.id=?" : ""));
        $stmtMaster = db()->prepare("UPDATE master_subsls SET pengawas_email=?, pencacah_email=? WHERE id=?");
        $stmtDaily = db()->prepare("UPDATE daily_status SET pengawas_email=?, pencacah_email=? WHERE subsls_id=?");
        $stmtPengawasUser = db()->prepare("INSERT INTO users (email,password_hash,role,name,active) VALUES (?,?, 'pengawas', ?, 1) ON DUPLICATE KEY UPDATE role='pengawas', active=1, name=VALUES(name)");
        $stmtPencacahUser = db()->prepare("INSERT INTO users (email,password_hash,role,name,active) VALUES (?,?, 'pencacah', ?, 1) ON DUPLICATE KEY UPDATE active=1, name=VALUES(name)");
        $hash = password_hash('123', PASSWORD_DEFAULT);
        for ($i = 1; $i < count($rows); $i++) {
            $row = $rows[$i];
            $subslsId = trim((string)($row[$idx['subsls_id']] ?? ''));
            if ($subslsId === '') {
                $skipped++;
                continue;
            }
            $pengawas = normalize_email($row[$idx['pengawas_email']] ?? '');
            $pencacah = normalize_email($row[$idx['pencacah_email']] ?? '');
            $existsParams = [$subslsId];
            if ($user['role'] === 'admin_kab') {
                $existsParams[] = $user['kab_id'];
            }
            $stmtExists->execute($existsParams);
            if (!$stmtExists->fetchColumn()) {
                $skipped++;
                continue;
            }
            $stmtMaster->execute([$pengawas, $pencacah, $subslsId]);
            $stmtDaily->execute([$pengawas, $pencacah, $subslsId]);
            if ($pengawas !== '') {
                $stmtPengawasUser->execute([$pengawas, $hash, $pengawas]);
            }
            if ($pencacah !== '') {
                $stmtPencacahUser->execute([$pencacah, $hash, $pencacah]);
            }
            $processed++;
        }
        sync_petugas_user_active_status();
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
    }
    return ['processed' => $processed, 'skipped' => $skipped, 'read' => max(count($rows) - 1, 0)];
}

if (($_GET['action'] ?? '') === 'download') {
    download_assignment_template($user);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $returnTo = in_array($_POST['return_to'] ?? '', ['assignment.php', 'assignment_template.php'], true) ? $_POST['return_to'] : 'assignment_template.php';
    try {
        if (!isset($_FILES['template']) || $_FILES['template']['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Upload file belum berhasil.');
        }
        $ext = strtolower(pathinfo($_FILES['template']['name'], PATHINFO_EXTENSION));
        if ($ext !== 'xlsx') {
            throw new RuntimeException('File harus berformat .xlsx.');
        }
        $result = import_assignment_template($_FILES['template']['tmp_name'], $user);
        flash('success', 'Template berhasil diproses. Baris dibaca: ' . $result['read'] . ', baris valid diproses: ' . $result['processed'] . ', skip: ' . $result['skipped'] . '.');
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }
    redirect($returnTo);
}

render_header('Ganti Petugas by Template');
?>
<?php if ($msg = flash('success')): ?><div class="alert alert-success"><?= e($msg) ?></div><?php endif; ?>
<?php if ($msg = flash('error')): ?><div class="alert alert-danger"><?= e($msg) ?></div><?php endif; ?>
<div class="card">
  <div class="card-body">
    <a class="btn btn-success mb-3" href="assignment_template.php?action=download"><i class="fas fa-download mr-1"></i>Download Template Excel</a>
    <form method="post" enctype="multipart/form-data" data-progress-submit data-progress-title="Mengupload ganti petugas..." data-progress-text="Mohon tunggu, template sedang diproses dan master petugas diperbarui.">
      <div class="form-group">
        <label>Upload Template yang Sudah Diisi</label>
        <input class="form-control-file" type="file" name="template" accept=".xlsx" required>
      </div>
      <button class="btn btn-primary"><i class="fas fa-upload mr-1"></i>Upload dan Update Petugas</button>
    </form>
  </div>
</div>
<div class="card">
  <div class="card-body">
    <table class="table table-sm table-bordered mb-0">
      <thead><tr><th>Kolom</th><th>Keterangan</th></tr></thead>
      <tbody>
        <tr><td>subsls_id</td><td>Kunci unik wilayah. Jangan diubah. Isi hanya baris SubSLS yang mau diganti petugasnya.</td></tr>
        <tr><td>kode_subsls sampai subsls</td><td>Informasi wilayah untuk membantu pengecekan. Tidak dipakai sebagai kunci update.</td></tr>
        <tr><td>pengawas_email</td><td>Email pengawas baru.</td></tr>
        <tr><td>pencacah_email</td><td>Email pencacah baru.</td></tr>
      </tbody>
    </table>
  </div>
</div>
<?php render_footer(); ?>
