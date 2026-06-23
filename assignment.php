<?php
require __DIR__ . '/layout.php';
$user = require_role(['superadmin']);
$filters = ['kab_id'=>$_GET['kab_id'] ?? '', 'kec_id'=>$_GET['kec_id'] ?? '', 'desa_id'=>$_GET['desa_id'] ?? ''];

function assignment_filter_options(array $filters): array
{
    $out = ['kabupaten' => [], 'kecamatan' => [], 'desa' => []];
    $out['kabupaten'] = db()->query("SELECT id value, CONCAT(id,' - ',nmkab) label FROM master_kab ORDER BY id")->fetchAll();

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

$opts = assignment_filter_options($filters);
$rows = [];
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ids = $_POST['subsls_id'] ?? [];
    $updated = 0;
    $skipped = 0;
    db()->beginTransaction();
    try {
        $stmtCurrent = db()->prepare("SELECT pengawas_email, pencacah_email FROM master_subsls WHERE id=?");
        $stmtMaster = db()->prepare("UPDATE master_subsls SET pengawas_email=?, pencacah_email=? WHERE id=?");
        $stmtDaily = db()->prepare("UPDATE daily_status SET pengawas_email=?, pencacah_email=? WHERE subsls_id=?");
        $stmtPengawasUser = db()->prepare("INSERT INTO users (email,password_hash,role,name,active) VALUES (?,?, 'pengawas', ?, 1) ON DUPLICATE KEY UPDATE role='pengawas', active=1, name=VALUES(name)");
        $stmtPencacahUser = db()->prepare("INSERT INTO users (email,password_hash,role,name,active) VALUES (?,?, 'pencacah', ?, 1) ON DUPLICATE KEY UPDATE active=1, name=VALUES(name)");
        foreach ($ids as $i => $id) {
            $pengawasName = trim((string)($_POST['pengawas_name'][$i] ?? ''));
            $pengawas = normalize_email($_POST['pengawas_email'][$i] ?? '');
            $pencacahName = trim((string)($_POST['pencacah_name'][$i] ?? ''));
            $pencacah = normalize_email($_POST['pencacah_email'][$i] ?? '');
            $stmtCurrent->execute([$id]);
            $current = $stmtCurrent->fetch();
            if (!$current) {
                $skipped++;
                continue;
            }
            if (normalize_email($current['pengawas_email']) === $pengawas && normalize_email($current['pencacah_email']) === $pencacah && $pengawasName === '' && $pencacahName === '') {
                $skipped++;
                continue;
            }
            $stmtMaster->execute([$pengawas,$pencacah,$id]);
            $stmtDaily->execute([$pengawas,$pencacah,$id]);
            if ($pengawas) {
                $stmtPengawasUser->execute([$pengawas,password_hash('123', PASSWORD_DEFAULT),$pengawasName !== '' ? $pengawasName : $pengawas]);
            }
            if ($pencacah) {
                $stmtPencacahUser->execute([$pencacah,password_hash('123', PASSWORD_DEFAULT),$pencacahName !== '' ? $pencacahName : $pencacah]);
            }
            $updated++;
        }
        sync_petugas_user_active_status();
        db()->commit();
        flash('success', 'Master petugas berhasil diperbarui. Berubah: ' . $updated . ', dilewati karena sama/tidak ditemukan: ' . $skipped . '.');
    } catch (Throwable $e) {
        db()->rollBack();
        flash('error', $e->getMessage());
    }
    redirect('assignment.php');
}

if (isset($_GET['filter'])) {
    if (!$filters['kab_id'] || !$filters['kec_id'] || !$filters['desa_id']) {
        $error = 'Pilih sampai level Desa';
    } else {
        $stmt = db()->prepare("SELECT ms.*, sl.kdsls, sl.nmsls, d.nmdesa,
                up.name pengawas_name, uc.name pencacah_name
            FROM master_subsls ms
            JOIN master_sls sl ON sl.id=ms.sls_id
            JOIN master_desa d ON d.id=sl.desa_id
            LEFT JOIN users up ON up.email=ms.pengawas_email
            LEFT JOIN users uc ON uc.email=ms.pencacah_email
            WHERE d.id=?
            ORDER BY sl.kdsls, ms.kdsubsls");
        $stmt->execute([$filters['desa_id']]);
        $rows = $stmt->fetchAll();
    }
}

render_header('Ganti Pengawas/Pencacah');
?>
<div class="mb-3">
  <button class="btn btn-outline-success" type="button" data-toggle="modal" data-target="#assignmentTemplateModal"><i class="fas fa-file-excel mr-1"></i>Upload By Template</button>
</div>
<?php if ($msg = flash('success')): ?><div class="alert alert-success"><?= e($msg) ?></div><?php endif; ?>
<?php if ($msg = flash('error')): ?><div class="alert alert-danger"><?= e($msg) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<form class="card card-body mb-3" method="get">
  <div class="form-row align-items-end">
    <div class="form-group col-md-2"><label>Kabupaten</label><select class="form-control" name="kab_id" id="kab_id"><option value="">Pilih Kabupaten</option><?php foreach ($opts['kabupaten'] as $o): ?><option value="<?= e($o['value']) ?>" <?= $filters['kab_id']===$o['value']?'selected':'' ?>><?= e($o['label']) ?></option><?php endforeach; ?></select></div>
    <div class="form-group col-md-2"><label>Kecamatan</label><select class="form-control" name="kec_id" id="kec_id" <?= $filters['kab_id'] ? '' : 'disabled' ?>><option value=""><?= $filters['kab_id'] ? 'Pilih Kecamatan' : 'Pilih kabupaten dulu' ?></option><?php foreach ($opts['kecamatan'] as $o): ?><option value="<?= e($o['value']) ?>" <?= $filters['kec_id']===$o['value']?'selected':'' ?>><?= e($o['label']) ?></option><?php endforeach; ?></select></div>
    <div class="form-group col-md-2"><label>Desa</label><select class="form-control" name="desa_id" id="desa_id" <?= $filters['kec_id'] ? '' : 'disabled' ?>><option value=""><?= $filters['kec_id'] ? 'Pilih Desa' : 'Pilih kecamatan dulu' ?></option><?php foreach ($opts['desa'] as $o): ?><option value="<?= e($o['value']) ?>" <?= $filters['desa_id']===$o['value']?'selected':'' ?>><?= e($o['label']) ?></option><?php endforeach; ?></select></div>
    <div class="form-group col-md-2"><button class="btn btn-primary" name="filter" value="1">Filter</button></div>
  </div>
</form>
<?php if ($rows): ?>
<form method="post" data-progress-submit data-progress-title="Mengupdate master petugas..." data-progress-text="Mohon tunggu, sistem sedang mengecek perubahan dan memperbarui data satu desa.">
  <div class="card"><div class="card-body table-responsive p-0"><table class="table table-bordered table-sm">
    <thead><tr><th>Kode SubSLS</th><th>SLS</th><th>SubSLS</th><th>Nama Pengawas</th><th>Email Pengawas</th><th>Nama Pencacah</th><th>Email Pencacah</th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?><tr>
      <td><?= e($r['kdsls'] . $r['kdsubsls']) ?><input type="hidden" name="subsls_id[]" value="<?= e($r['id']) ?>"></td>
      <td><?= e($r['kdsls'] . ' - ' . $r['nmsls']) ?></td>
      <td><?= e($r['nmsubsls']) ?></td>
      <td><input class="form-control form-control-sm" name="pengawas_name[]" value="<?= e($r['pengawas_name'] ?? '') ?>"></td>
      <td><input class="form-control form-control-sm" name="pengawas_email[]" value="<?= e($r['pengawas_email']) ?>"></td>
      <td><input class="form-control form-control-sm" name="pencacah_name[]" value="<?= e($r['pencacah_name'] ?? '') ?>"></td>
      <td><input class="form-control form-control-sm" name="pencacah_email[]" value="<?= e($r['pencacah_email']) ?>"></td>
    </tr><?php endforeach; ?>
    </tbody>
  </table></div></div>
  <button class="btn btn-success mb-4">Update Master Petugas</button>
</form>
<?php endif; ?>
<div class="modal fade" id="assignmentTemplateModal" tabindex="-1" role="dialog" aria-labelledby="assignmentTemplateModalLabel" aria-hidden="true">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="assignmentTemplateModalLabel">Upload By Template Ganti Petugas</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Tutup"><span aria-hidden="true">&times;</span></button>
      </div>
      <div class="modal-body">
        <a class="btn btn-success mb-3" href="assignment_template.php?action=download"><i class="fas fa-download mr-1"></i>Download Template Excel</a>
        <form method="post" action="assignment_template.php" enctype="multipart/form-data" data-progress-submit data-progress-title="Mengupload master petugas..." data-progress-text="Mohon tunggu, template sedang dibaca dan master petugas diperbarui.">
          <input type="hidden" name="return_to" value="assignment.php">
          <div class="form-group">
            <label>Upload Template Ganti Petugas yang Sudah Diisi</label>
            <input class="form-control-file" type="file" name="template" accept=".xlsx" required>
          </div>
          <button class="btn btn-primary"><i class="fas fa-upload mr-1"></i>Upload Ganti Petugas</button>
        </form>
        <hr>
        <table class="table table-sm table-bordered mb-0">
          <thead><tr><th>Kolom</th><th>Keterangan</th></tr></thead>
          <tbody>
            <tr><td>subsls_id</td><td>Kunci unik wilayah. Jangan diubah. Isi hanya baris SubSLS yang mau diganti petugasnya.</td></tr>
            <tr><td>kode_subsls sampai subsls</td><td>Informasi wilayah untuk membantu pengecekan. Tidak dipakai sebagai kunci update.</td></tr>
            <tr><td>pengawas_nama dan pencacah_nama</td><td>Nama petugas yang akan disimpan ke tabel users.</td></tr>
            <tr><td>pengawas_email</td><td>Email pengawas baru.</td></tr>
            <tr><td>pencacah_email</td><td>Email pencacah baru.</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<script>
document.getElementById('kab_id').addEventListener('change', function () {
  document.getElementById('kec_id').value = '';
  document.getElementById('desa_id').value = '';
  this.form.submit();
});
document.getElementById('kec_id').addEventListener('change', function () {
  document.getElementById('desa_id').value = '';
  this.form.submit();
});
</script>
<?php render_footer(); ?>
