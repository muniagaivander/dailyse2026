<?php

require __DIR__ . '/bootstrap.php';

set_time_limit(0);
ini_set('memory_limit', '512M');

$path = $argv[1] ?? null;

if (!$path || !is_file($path)) {
    fwrite(STDERR, "CSV tidak ditemukan. Pakai: php import_cli.php \"D:\\path\\master.csv\"\n");
    exit(1);
}

function cli_value(array $row, string $key): string
{
    return trim((string)($row[$key] ?? ''));
}

function cli_digits(string $value, int $length): string
{
    $digits = preg_replace('/\D+/', '', $value);
    return str_pad(substr($digits, -$length), $length, '0', STR_PAD_LEFT);
}

$fh = fopen($path, 'r');
if (!$fh) {
    fwrite(STDERR, "Gagal membuka CSV: {$path}\n");
    exit(1);
}

$headers = fgetcsv($fh, 0, ',', '"', '\\');
if (!$headers) {
    fwrite(STDERR, "CSV kosong atau header tidak terbaca.\n");
    exit(1);
}

$headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]);
$headers = array_map('trim', $headers);
$required = ['kdprov', 'kdkab', 'kdkec', 'kddesa', 'kdsls', 'kdsubsls', 'PENGAWAS', 'PENCACAH', 'idsubsls_25_2'];
$missing = array_values(array_diff($required, $headers));
if ($missing) {
    fwrite(STDERR, "Header CSV kurang: " . implode(', ', $missing) . "\n");
    exit(1);
}

ensure_default_admins();

$hash123 = password_hash('123', PASSWORD_DEFAULT);
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$stmtProv = $pdo->prepare('REPLACE INTO master_prov (id, kdprov, nmprov) VALUES (?, ?, ?)');
$stmtKab = $pdo->prepare('REPLACE INTO master_kab (id, prov_id, kdkab, nmkab) VALUES (?, ?, ?, ?)');
$stmtKec = $pdo->prepare('REPLACE INTO master_kec (id, kab_id, kdkec, nmkec) VALUES (?, ?, ?, ?)');
$stmtDesa = $pdo->prepare('REPLACE INTO master_desa (id, kec_id, kddesa, nmdesa) VALUES (?, ?, ?, ?)');
$stmtSls = $pdo->prepare('REPLACE INTO master_sls (id, desa_id, kdsls, nmsls) VALUES (?, ?, ?, ?)');
$stmtSubsls = $pdo->prepare(
    'REPLACE INTO master_subsls
    (id, sls_id, kdsubsls, nmsubsls, idsubls, pengawas_email, pencacah_email)
    VALUES (?, ?, ?, ?, ?, ?, ?)'
);
$stmtStatus = $pdo->prepare(
    'INSERT IGNORE INTO subsls_status
    (subsls_id, open_count, draft_count, submitted_by_pencacah, approved_by_pengawas, rejected_by_pengawas, pending_count, target, last_update)
    VALUES (?, 0, 0, 0, 0, 0, 0, 0, NULL)'
);
$stmtUser = $pdo->prepare(
    'INSERT INTO users (email, password_hash, role, kab_id, name, active)
    VALUES (?, ?, "pengawas", ?, ?, 1)
    ON DUPLICATE KEY UPDATE kab_id = VALUES(kab_id), name = VALUES(name), active = 1'
);
$stmtPencacahUser = $pdo->prepare(
    'INSERT INTO users (email, password_hash, role, kab_id, name, active)
    VALUES (?, ?, "pencacah", ?, ?, 1)
    ON DUPLICATE KEY UPDATE kab_id = VALUES(kab_id), name = VALUES(name), active = 1'
);

$count = 0;
$skipped = 0;
$pdo->beginTransaction();

while (($data = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
    if (count($data) === 1 && trim((string)$data[0]) === '') {
        continue;
    }

    $row = array_combine($headers, array_pad($data, count($headers), ''));
    if (!$row) {
        $skipped++;
        continue;
    }

    $prov = cli_digits(cli_value($row, 'kdprov'), 2);
    $kab = cli_digits(cli_value($row, 'kdkab'), 2);
    $kec = cli_digits(cli_value($row, 'kdkec'), 3);
    $desa = cli_digits(cli_value($row, 'kddesa'), 3);
    $sls = cli_digits(cli_value($row, 'kdsls'), 4);
    $subsls = cli_digits(cli_value($row, 'kdsubsls'), 2);
    $subslsId = cli_value($row, 'idsubsls_25_2');

    if ($prov === '' || $kab === '' || $kec === '' || $desa === '' || $sls === '' || $subslsId === '') {
        $skipped++;
        continue;
    }

    $provId = $prov;
    $kabId = $prov . $kab;
    $kecId = $kabId . $kec;
    $desaId = $kecId . $desa;
    $slsId = $desaId . $sls;

    $pengawas = strtolower(cli_value($row, 'PENGAWAS'));
    $pencacah = strtolower(cli_value($row, 'PENCACAH'));

    $stmtProv->execute([$provId, $prov, cli_value($row, 'nmprov')]);
    $stmtKab->execute([$kabId, $provId, $kab, cli_value($row, 'nmkab')]);
    $stmtKec->execute([$kecId, $kabId, $kec, cli_value($row, 'nmkec')]);
    $stmtDesa->execute([$desaId, $kecId, $desa, cli_value($row, 'nmdesa')]);
    $stmtSls->execute([$slsId, $desaId, $sls, cli_value($row, 'nmsls')]);
    $stmtSubsls->execute([
        $subslsId,
        $slsId,
        $subsls,
        cli_value($row, 'nmsubsls'),
        cli_value($row, 'idsubls'),
        $pengawas,
        $pencacah,
    ]);
    $stmtStatus->execute([$subslsId]);

    if ($pengawas !== '') {
        $stmtUser->execute([$pengawas, $hash123, $kabId, $pengawas]);
    }
    if ($pencacah !== '') {
        $stmtPencacahUser->execute([$pencacah, $hash123, $kabId, $pencacah]);
    }

    $count++;
    if ($count % 500 === 0) {
        $pdo->commit();
        echo "Imported {$count} rows...\n";
        $pdo->beginTransaction();
    }
}

$pdo->commit();
fclose($fh);
sync_petugas_user_active_status();

echo "Selesai import {$count} rows. Skip {$skipped} rows.\n";
