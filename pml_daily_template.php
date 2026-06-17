<?php
require __DIR__ . '/layout.php';
$user = require_role(['pengawas']);
$date = today();

function pml_work_area_label(array $user): string
{
    $stmt = db()->prepare("SELECT DISTINCT CONCAT(k.id, ' - ', k.nmkab) label
        FROM master_subsls ms
        JOIN master_sls sl ON sl.id=ms.sls_id
        JOIN master_desa d ON d.id=sl.desa_id
        JOIN master_kec kc ON kc.id=d.kec_id
        JOIN master_kab k ON k.id=kc.kab_id
        WHERE ms.pengawas_email=?
        ORDER BY label");
    $stmt->execute([$user['email']]);
    $labels = array_column($stmt->fetchAll(), 'label');
    return $labels ? implode(', ', $labels) : 'wilayah kerja Anda';
}

function pml_has_submission(string $date, string $pengawasEmail): bool
{
    $stmt = db()->prepare("SELECT
        (SELECT COUNT(*) FROM submit_locks WHERE tanggal=? AND pengawas_email=?) +
        (SELECT COUNT(*) FROM daily_status WHERE tanggal=? AND pengawas_email=?) AS total");
    $stmt->execute([$date, $pengawasEmail, $date, $pengawasEmail]);
    return (int)$stmt->fetchColumn() > 0;
}

function pml_master_rows(string $pengawasEmail, string $date): array
{
    $stmt = db()->prepare("SELECT ms.id subsls_id, p.id provinsi, k.id kabupaten, kc.kdkec kecamatan,
            d.kddesa desa, sl.kdsls sls, sl.nmsls nama_sls, ms.kdsubsls subsls, ms.pengawas_email, ms.pencacah_email,
            COALESCE(ss.open_count,0) open_count,
            COALESCE(ss.draft_count,0) draft_count,
            COALESCE(ss.submitted_by_pencacah,0) submitted_by_pencacah,
            COALESCE(ss.approved_by_pengawas,0) approved_by_pengawas,
            COALESCE(ss.rejected_by_pengawas,0) rejected_by_pengawas
        FROM master_subsls ms
        JOIN master_sls sl ON sl.id=ms.sls_id
        JOIN master_desa d ON d.id=sl.desa_id
        JOIN master_kec kc ON kc.id=d.kec_id
        JOIN master_kab k ON k.id=kc.kab_id
        JOIN master_prov p ON p.id=k.prov_id
        LEFT JOIN subsls_status ss ON ss.subsls_id=ms.id
        WHERE ms.pengawas_email=?
        ORDER BY ms.pencacah_email, ms.id");
    $stmt->execute([$pengawasEmail]);
    return $stmt->fetchAll();
}

function pml_xlsx_col(int $index): string
{
    $name = '';
    while ($index > 0) {
        $index--;
        $name = chr(65 + ($index % 26)) . $name;
        $index = intdiv($index, 26);
    }
    return $name;
}

function pml_xlsx_cell(string $value, int $row, int $col): string
{
    $ref = pml_xlsx_col($col) . $row;
    return '<c r="' . $ref . '" t="inlineStr"><is><t>' . htmlspecialchars($value, ENT_XML1) . '</t></is></c>';
}

function pml_download_template(array $user, string $date): void
{
    $headers = [
        'tanggal',
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
        'open',
        'submit',
        'reject',
        'pending',
        'approved',
    ];
    $sheetRows = [$headers];
    foreach (pml_master_rows($user['email'], $date) as $row) {
        $sheetRows[] = [
            $date,
            $row['subsls_id'],
            $row['sls'] . $row['subsls'],
            $row['provinsi'],
            $row['kabupaten'],
            $row['kecamatan'],
            $row['desa'],
            $row['sls'],
            $row['nama_sls'],
            $row['subsls'],
            $row['pengawas_email'],
            $row['pencacah_email'],
            $row['open_count'],
            $row['submitted_by_pencacah'],
            $row['rejected_by_pengawas'],
            $row['draft_count'],
            $row['approved_by_pengawas'],
        ];
    }

    $tmp = tempnam(sys_get_temp_dir(), 'pml_daily_');
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
  <sheets><sheet name="template_harian_pml" sheetId="1" r:id="rId1"/></sheets>
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
            $sheet .= pml_xlsx_cell((string)$value, $rowNumber, $cIndex + 1);
        }
        $sheet .= '</row>';
    }
    $sheet .= '</sheetData></worksheet>';
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
    $zip->close();

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="template_harian_pml_' . $date . '.xlsx"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    unlink($tmp);
    exit;
}

function pml_read_xlsx_rows(string $path): array
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

function pml_excel_date(string $value): string
{
    $value = trim($value);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return $value;
    }
    if (is_numeric($value)) {
        return gmdate('Y-m-d', ((int)$value - 25569) * 86400);
    }
    $time = strtotime($value);
    if ($time === false) {
        throw new RuntimeException("Tanggal tidak valid: {$value}");
    }
    return date('Y-m-d', $time);
}

function pml_import_template(string $path, array $user, string $date): array
{
    $rows = pml_read_xlsx_rows($path);
    if (!$rows) {
        throw new RuntimeException('File kosong.');
    }
    $headers = array_map(fn($v) => strtolower(trim((string)$v)), $rows[0]);
    $idx = array_flip($headers);
    $statusColumns = [
        'open_count' => ['open', 'open_count'],
        'submitted_by_pencacah' => ['submit', 'submitted_by_pencacah'],
        'rejected_by_pengawas' => ['reject', 'rejected_by_pengawas'],
        'draft_count' => ['pending', 'draft_count'],
        'approved_by_pengawas' => ['approved', 'approved_by_pengawas'],
    ];
    foreach (['subsls_id'] as $required) {
        if (!array_key_exists($required, $idx)) {
            throw new RuntimeException("Kolom {$required} tidak ditemukan.");
        }
    }
    foreach ($statusColumns as $aliases) {
        if (!array_filter($aliases, fn($alias) => array_key_exists($alias, $idx))) {
            throw new RuntimeException('Kolom status tidak lengkap. Gunakan header: open, submit, reject, pending, approved.');
        }
    }

    $masterRows = pml_master_rows($user['email'], $date);
    $dataById = [];
    foreach ($masterRows as $master) {
        $dataById[$master['subsls_id']] = [
            'open_count' => (int)$master['open_count'],
            'draft_count' => (int)$master['draft_count'],
            'submitted_by_pencacah' => (int)$master['submitted_by_pencacah'],
            'approved_by_pengawas' => (int)$master['approved_by_pengawas'],
            'rejected_by_pengawas' => (int)$master['rejected_by_pengawas'],
            'master' => $master,
        ];
    }
    $futureDateRows = [];
    for ($i = 1; $i < count($rows); $i++) {
        $row = $rows[$i];
        $subslsId = trim((string)($row[$idx['subsls_id']] ?? ''));
        if ($subslsId === '' || !isset($dataById[$subslsId])) {
            continue;
        }
        if (array_key_exists('tanggal', $idx)) {
            $rowDate = pml_excel_date((string)($row[$idx['tanggal']] ?? ''));
            if ($rowDate > today()) {
                $futureDateRows[] = 'Row ' . ($i + 1) . ' - subsls_id ' . $subslsId . ' - tanggal ' . $rowDate;
                continue;
            }
        }
        foreach ($statusColumns as $field => $aliases) {
            $alias = array_values(array_filter($aliases, fn($name) => array_key_exists($name, $idx)))[0];
            $dataById[$subslsId][$field] = max(0, (int)($row[$idx[$alias]] ?? 0));
        }
    }
    if ($futureDateRows) {
        $shownRows = array_slice($futureDateRows, 0, 100);
        $message = 'Ada tanggal setelah hari upload (' . today() . '). Perbaiki baris berikut: ' . implode('; ', $shownRows);
        if (count($futureDateRows) > count($shownRows)) {
            $message .= '; dan ' . (count($futureDateRows) - count($shownRows)) . ' baris lainnya.';
        }
        throw new RuntimeException($message);
    }

    db()->beginTransaction();
    try {
        $stmtDaily = db()->prepare("INSERT INTO daily_status
            (tanggal,subsls_id,kab_id,pengawas_email,pencacah_email,target,open_count,draft_count,submitted_by_pencacah,approved_by_pengawas,rejected_by_pengawas,submitted_at,updated_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE target=VALUES(target),open_count=VALUES(open_count),draft_count=VALUES(draft_count),
                submitted_by_pencacah=VALUES(submitted_by_pencacah),approved_by_pengawas=VALUES(approved_by_pengawas),
                rejected_by_pengawas=VALUES(rejected_by_pengawas),updated_by=VALUES(updated_by)");
        $stmtStatus = db()->prepare("REPLACE INTO subsls_status
            (subsls_id,open_count,draft_count,submitted_by_pencacah,approved_by_pengawas,rejected_by_pengawas,target,last_update,updated_by)
            VALUES (?,?,?,?,?,?,?,?,?)");
        $now = date('Y-m-d H:i:s');
        $processed = 0;
        foreach ($dataById as $subslsId => $data) {
            $master = $data['master'];
            $target = $data['open_count'] + $data['draft_count'] + $data['submitted_by_pencacah'] + $data['approved_by_pengawas'] + $data['rejected_by_pengawas'];
            $stmtDaily->execute([
                $date,
                $subslsId,
                $master['kabupaten'],
                $user['email'],
                $master['pencacah_email'],
                $target,
                $data['open_count'],
                $data['draft_count'],
                $data['submitted_by_pencacah'],
                $data['approved_by_pengawas'],
                $data['rejected_by_pengawas'],
                $now,
                $user['email'],
            ]);
            $stmtStatus->execute([
                $subslsId,
                $data['open_count'],
                $data['draft_count'],
                $data['submitted_by_pencacah'],
                $data['approved_by_pengawas'],
                $data['rejected_by_pengawas'],
                $target,
                $now,
                $user['email'],
            ]);
            $processed++;
        }
        db()->prepare("INSERT INTO submit_locks (tanggal,pengawas_email,status)
            VALUES (?,?, 'SUBMITTED')
            ON DUPLICATE KEY UPDATE status=VALUES(status), updated_at=CURRENT_TIMESTAMP")
            ->execute([$date, $user['email']]);
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
    }
    return ['read' => max(count($rows) - 1, 0), 'processed' => $processed];
}

if (($_GET['action'] ?? '') === 'download') {
    pml_download_template($user, $date);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $returnTo = in_array($_POST['return_to'] ?? '', ['input.php', 'pml_daily_template.php'], true) ? $_POST['return_to'] : 'pml_daily_template.php';
    flash('error', 'Pengawas hanya dapat download template. Isikan template dan kirim ke Tim SPBE BPS ' . pml_work_area_label($user) . '.');
    redirect($returnTo);
}

$isLocked = pml_has_submission($date, $user['email']);
render_header('Template Harian Excel');
?>
<?php if ($msg = flash('success')): ?><div class="alert alert-success"><?= e($msg) ?></div><?php endif; ?>
<?php if ($msg = flash('error')): ?><div class="alert alert-danger"><?= e($msg) ?></div><?php endif; ?>
<?php if ($isLocked): ?>
  <div class="alert alert-info">Hari Ini sudah melakukan Input Harian</div>
<?php endif; ?>
<div class="card">
  <div class="card-body">
    <div class="form-group col-md-3 px-0">
      <label>Tanggal</label>
      <input class="form-control" value="<?= e($date) ?>" disabled>
    </div>
    <a class="btn btn-success mb-3" href="pml_daily_template.php?action=download"><i class="fas fa-download mr-1"></i>Download Template Excel Hari Ini</a>
    <div class="alert alert-info mb-0">
      Isikan template dan kirim ke Tim SPBE BPS <?= e(pml_work_area_label($user)) ?>.
    </div>
  </div>
</div>
<?php render_footer(); ?>
