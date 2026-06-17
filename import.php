<?php
require __DIR__ . '/layout.php';
$user = require_role(['superadmin']);
ensure_default_admins();
set_time_limit(0);
ini_set('memory_limit', '512M');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv'])) {
    $file = $_FILES['csv']['tmp_name'];
    $fh = fopen($file, 'r');
    $headers = fgetcsv($fh);
    $headers = array_map('trim', $headers ?: []);
    $count = 0;
    db()->beginTransaction();
    try {
        while (($row = fgetcsv($fh)) !== false) {
            $r = array_combine($headers, $row);
            if (!$r || empty($r['idsubsls_25_2'])) continue;
            import_master_row($r);
            $count++;
            if ($count % 500 === 0) {
                db()->commit();
                db()->beginTransaction();
            }
        }
        sync_petugas_user_active_status();
        db()->commit();
        flash('success', "Import selesai: {$count} baris.");
    } catch (Throwable $e) {
        db()->rollBack();
        flash('error', $e->getMessage());
    }
    redirect('import.php');
}

function import_master_row(array $r): void {
    $kdprov = str_pad($r['kdprov'], 2, '0', STR_PAD_LEFT);
    $kdkab = str_pad($r['kdkab'], 2, '0', STR_PAD_LEFT);
    $kdkec = str_pad($r['kdkec'], 3, '0', STR_PAD_LEFT);
    $kddesa = str_pad($r['kddesa'], 3, '0', STR_PAD_LEFT);
    $kdsls = str_pad($r['kdsls'], 4, '0', STR_PAD_LEFT);
    $kdsubsls = str_pad($r['kdsubsls'], 2, '0', STR_PAD_LEFT);
    $kabId = $kdprov . $kdkab;
    $kecId = $kabId . $kdkec;
    $desaId = $kecId . $kddesa;
    $slsId = $desaId . $kdsls;
    $subslsId = (string)$r['idsubsls_25_2'];
    $pengawas = normalize_email($r['PENGAWAS'] ?? '');
    $pencacah = normalize_email($r['PENCACAH'] ?? '');

    db()->prepare("REPLACE INTO master_prov (id,kdprov,nmprov) VALUES (?,?,?)")->execute([$kdprov,$kdprov,$r['nmprov']]);
    db()->prepare("REPLACE INTO master_kab (id,prov_id,kdkab,nmkab) VALUES (?,?,?,?)")->execute([$kabId,$kdprov,$kdkab,$r['nmkab']]);
    db()->prepare("REPLACE INTO master_kec (id,kab_id,kdkec,nmkec) VALUES (?,?,?,?)")->execute([$kecId,$kabId,$kdkec,$r['nmkec']]);
    db()->prepare("REPLACE INTO master_desa (id,kec_id,kddesa,nmdesa) VALUES (?,?,?,?)")->execute([$desaId,$kecId,$kddesa,$r['nmdesa']]);
    db()->prepare("REPLACE INTO master_sls (id,desa_id,kdsls,nmsls) VALUES (?,?,?,?)")->execute([$slsId,$desaId,$kdsls,$r['nmsls']]);
    db()->prepare("REPLACE INTO master_subsls (id,sls_id,kdsubsls,nmsubsls,idsubls,pengawas_email,pencacah_email) VALUES (?,?,?,?,?,?,?)")
        ->execute([$subslsId,$slsId,$kdsubsls,$r['nmsubsls'],$r['idsubls'] ?? null,$pengawas,$pencacah]);
    db()->prepare("INSERT IGNORE INTO subsls_status (subsls_id) VALUES (?)")->execute([$subslsId]);
    if ($pengawas) {
        db()->prepare("INSERT INTO users (email,password_hash,role,kab_id,name,active) VALUES (?,?,?,?,?,1)
            ON DUPLICATE KEY UPDATE kab_id=VALUES(kab_id), role='pengawas', active=1")
            ->execute([$pengawas,password_hash('123', PASSWORD_DEFAULT),'pengawas',$kabId,$pengawas]);
    }
    if ($pencacah) {
        db()->prepare("INSERT INTO users (email,password_hash,role,kab_id,name,active) VALUES (?,?,?,?,?,1)
            ON DUPLICATE KEY UPDATE kab_id=VALUES(kab_id), active=1, name=VALUES(name)")
            ->execute([$pencacah,password_hash('123', PASSWORD_DEFAULT),'pencacah',$kabId,$pencacah]);
    }
}

render_header('Import Master');
?>
<?php if ($msg = flash('success')): ?><div class="alert alert-success"><?= e($msg) ?></div><?php endif; ?>
<?php if ($msg = flash('error')): ?><div class="alert alert-danger"><?= e($msg) ?></div><?php endif; ?>
<div class="card">
  <div class="card-body">
    <form method="post" enctype="multipart/form-data">
      <div class="form-group">
        <label>CSV master wilayah</label>
        <input class="form-control" type="file" name="csv" accept=".csv" required>
      </div>
      <button class="btn btn-primary">Import</button>
    </form>
  </div>
</div>
<?php render_footer(); ?>
