<?php
require __DIR__ . '/layout.php';
$user = require_role(['superadmin', 'admin_kab', 'pengawas']);
ensure_completion_status_table();

$filters = [
    'kab_id' => $_GET['kab_id'] ?? '',
    'kec_id' => $_GET['kec_id'] ?? '',
    'desa_id' => $_GET['desa_id'] ?? '',
    'pencacah_email' => normalize_email($_GET['pencacah_email'] ?? ''),
];
if ($user['role'] === 'admin_kab') {
    $filters['kab_id'] = $user['kab_id'];
}

function completion_area_options(array $user, array $filters): array
{
    $out = ['kabupaten' => [], 'kecamatan' => [], 'desa' => []];
    if ($user['role'] === 'superadmin') {
        $out['kabupaten'] = db()->query("SELECT id value, CONCAT(id,' - ',nmkab) label FROM master_kab ORDER BY id")->fetchAll();
    } elseif ($user['role'] === 'admin_kab') {
        $stmt = db()->prepare("SELECT id value, CONCAT(id,' - ',nmkab) label FROM master_kab WHERE id=?");
        $stmt->execute([$user['kab_id']]);
        $out['kabupaten'] = $stmt->fetchAll();
    }

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

function completion_pencacah_options(string $pengawasEmail): array
{
    $stmt = db()->prepare("SELECT DISTINCT pencacah_email email
        FROM master_subsls
        WHERE pengawas_email=? AND pencacah_email IS NOT NULL AND pencacah_email <> ''
        ORDER BY pencacah_email");
    $stmt->execute([$pengawasEmail]);
    return array_column($stmt->fetchAll(), 'email');
}

function completion_work_area_label(array $user): string
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

function completion_can_update(array $user, string $subslsId): bool
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $subslsId = (string)($_POST['subsls_id'] ?? '');
    $status = (string)($_POST['status_selesai'] ?? '');
    $query = [
        'kab_id' => $_POST['kab_id'] ?? '',
        'kec_id' => $_POST['kec_id'] ?? '',
        'desa_id' => $_POST['desa_id'] ?? '',
        'pencacah_email' => $_POST['pencacah_email'] ?? '',
        'filter' => 1,
    ];
    try {
        if (!in_array($status, ['Belum Selesai', 'Selesai'], true)) {
            throw new RuntimeException('Status tidak valid.');
        }
        if (!completion_can_update($user, $subslsId)) {
            throw new RuntimeException('SubSLS tidak ditemukan atau bukan wilayah akses user.');
        }
        db()->prepare("INSERT INTO subsls_completion_status (subsls_id,status_selesai,updated_by)
            VALUES (?,?,?)
            ON DUPLICATE KEY UPDATE status_selesai=VALUES(status_selesai), updated_by=VALUES(updated_by), updated_at=CURRENT_TIMESTAMP")
            ->execute([$subslsId, $status, $user['email']]);
        flash('success', 'Status selesai SubSLS berhasil diperbarui.');
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }
    redirect('status_selesai.php?' . http_build_query($query));
}

$opts = in_array($user['role'], ['superadmin', 'admin_kab'], true) ? completion_area_options($user, $filters) : ['kabupaten' => [], 'kecamatan' => [], 'desa' => []];
$pencacahOptions = $user['role'] === 'pengawas' ? completion_pencacah_options($user['email']) : [];
$rows = [];
$error = null;

if (isset($_GET['filter'])) {
    if (in_array($user['role'], ['superadmin', 'admin_kab'], true)) {
        if (!$filters['kab_id'] || !$filters['kec_id'] || !$filters['desa_id']) {
            $error = $user['role'] === 'superadmin' ? 'Pilih sampai level desa.' : 'Pilih kecamatan dan desa.';
        } else {
            $areaWhere = ['d.id=?'];
            $areaParams = [$filters['desa_id']];
            if ($user['role'] === 'admin_kab') {
                $areaWhere[] = 'kc.kab_id=?';
                $areaParams[] = $user['kab_id'];
            }
            $stmt = db()->prepare("SELECT ms.id subsls_id, CONCAT(sl.kdsls, ms.kdsubsls) kode_subsls,
                    d.nmdesa, sl.kdsls, sl.nmsls, ms.kdsubsls, ms.nmsubsls, ms.pengawas_email, ms.pencacah_email,
                    COALESCE(cs.status_selesai, 'Belum Selesai') status_selesai, cs.updated_at, cs.updated_by
                FROM master_subsls ms
                JOIN master_sls sl ON sl.id=ms.sls_id
                JOIN master_desa d ON d.id=sl.desa_id
                JOIN master_kec kc ON kc.id=d.kec_id
                LEFT JOIN subsls_completion_status cs ON cs.subsls_id=ms.id
                WHERE " . implode(' AND ', $areaWhere) . "
                ORDER BY sl.kdsls, ms.kdsubsls");
            $stmt->execute($areaParams);
            $rows = $stmt->fetchAll();
        }
    } elseif ($user['role'] === 'pengawas') {
        if (!$filters['pencacah_email']) {
            $error = 'Pilih pencacah dulu.';
        } else {
            $stmt = db()->prepare("SELECT ms.id subsls_id, CONCAT(sl.kdsls, ms.kdsubsls) kode_subsls,
                    d.nmdesa, sl.kdsls, sl.nmsls, ms.kdsubsls, ms.nmsubsls, ms.pengawas_email, ms.pencacah_email,
                    COALESCE(cs.status_selesai, 'Belum Selesai') status_selesai, cs.updated_at, cs.updated_by
                FROM master_subsls ms
                JOIN master_sls sl ON sl.id=ms.sls_id
                JOIN master_desa d ON d.id=sl.desa_id
                LEFT JOIN subsls_completion_status cs ON cs.subsls_id=ms.id
                WHERE ms.pengawas_email=? AND ms.pencacah_email=?
                ORDER BY ms.id");
            $stmt->execute([$user['email'], $filters['pencacah_email']]);
            $rows = $stmt->fetchAll();
        }
    }
}

render_header('Status Selesai SubSLS');
?>
<div class="mb-3">
  <button class="btn btn-outline-success" type="button" data-toggle="modal" data-target="#completionTemplateModal"><i class="fas fa-file-excel mr-1"></i>Upload By Template</button>
</div>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<form class="card card-body mb-3" method="get">
  <div class="form-row align-items-end">
    <?php if ($user['role'] === 'superadmin'): ?>
      <div class="form-group col-md-3">
        <label>Kabupaten</label>
        <select class="form-control" name="kab_id" id="kab_id" required>
          <option value="">Pilih Kabupaten</option>
          <?php foreach ($opts['kabupaten'] as $o): ?><option value="<?= e($o['value']) ?>" <?= $filters['kab_id']===$o['value']?'selected':'' ?>><?= e($o['label']) ?></option><?php endforeach; ?>
        </select>
      </div>
    <?php elseif ($user['role'] === 'admin_kab'): ?>
      <input type="hidden" name="kab_id" value="<?= e($filters['kab_id']) ?>">
    <?php endif; ?>

    <?php if (in_array($user['role'], ['superadmin', 'admin_kab'], true)): ?>
      <div class="form-group col-md-3">
        <label>Kecamatan</label>
        <select class="form-control" name="kec_id" id="kec_id" <?= $filters['kab_id'] ? '' : 'disabled' ?> required>
          <option value=""><?= $filters['kab_id'] ? 'Pilih Kecamatan' : 'Pilih kabupaten dulu' ?></option>
          <?php foreach ($opts['kecamatan'] as $o): ?><option value="<?= e($o['value']) ?>" <?= $filters['kec_id']===$o['value']?'selected':'' ?>><?= e($o['label']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-group col-md-3">
        <label>Desa</label>
        <select class="form-control" name="desa_id" id="desa_id" <?= $filters['kec_id'] ? '' : 'disabled' ?> required>
          <option value=""><?= $filters['kec_id'] ? 'Pilih Desa' : 'Pilih kecamatan dulu' ?></option>
          <?php foreach ($opts['desa'] as $o): ?><option value="<?= e($o['value']) ?>" <?= $filters['desa_id']===$o['value']?'selected':'' ?>><?= e($o['label']) ?></option><?php endforeach; ?>
        </select>
      </div>
    <?php else: ?>
      <div class="form-group col-md-4">
        <label>Pencacah</label>
        <select class="form-control" name="pencacah_email" required>
          <option value="">Pilih Pencacah</option>
          <?php foreach ($pencacahOptions as $email): ?><option value="<?= e($email) ?>" <?= $filters['pencacah_email']===$email?'selected':'' ?>><?= e($email) ?></option><?php endforeach; ?>
        </select>
      </div>
    <?php endif; ?>
    <div class="form-group col-md-2"><button class="btn btn-primary" name="filter" value="1">Filter</button></div>
  </div>
</form>

<?php if ($rows): ?>
<div class="card">
  <div class="card-body table-responsive p-0">
    <table class="table table-bordered table-sm table-striped mb-0">
      <thead>
        <tr>
          <th>Kode SubSLS</th>
          <th>SLS</th>
          <th>SubSLS</th>
          <th>Pengawas</th>
          <th>Pencacah</th>
          <th>Status Selesai</th>
          <th>Status Selesai Terakhir</th>
          <th>Update Terakhir</th>
          <th style="width: 170px">Aksi</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <?php $formId = 'completion-' . md5($r['subsls_id']); ?>
        <tr>
          <td><?= e($r['kode_subsls']) ?></td>
          <td><?= e($r['kdsls'] . ' - ' . $r['nmsls']) ?></td>
          <td><?= e($r['kdsubsls'] . ' - ' . $r['nmsubsls']) ?></td>
          <td><?= e($r['pengawas_email']) ?></td>
          <td><?= e($r['pencacah_email']) ?></td>
          <td>
            <form method="post" id="<?= e($formId) ?>" class="d-none">
              <input type="hidden" name="subsls_id" value="<?= e($r['subsls_id']) ?>">
              <input type="hidden" name="kab_id" value="<?= e($filters['kab_id']) ?>">
              <input type="hidden" name="kec_id" value="<?= e($filters['kec_id']) ?>">
              <input type="hidden" name="desa_id" value="<?= e($filters['desa_id']) ?>">
              <input type="hidden" name="pencacah_email" value="<?= e($filters['pencacah_email']) ?>">
            </form>
            <select class="form-control form-control-sm" name="status_selesai" form="<?= e($formId) ?>">
              <?php foreach (['Belum Selesai', 'Selesai'] as $status): ?>
                <option value="<?= e($status) ?>" <?= $r['status_selesai']===$status?'selected':'' ?>><?= e($status) ?></option>
              <?php endforeach; ?>
            </select>
          </td>
          <td><?= e($r['status_selesai']) ?></td>
          <td><?= e($r['updated_at'] ? $r['updated_at'] . ' oleh ' . $r['updated_by'] : '-') ?></td>
          <td><button class="btn btn-success btn-sm" type="submit" form="<?= e($formId) ?>">Update Status</button></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php elseif (isset($_GET['filter']) && !$error): ?>
  <div class="alert alert-info">Tidak ada SubSLS pada filter ini.</div>
<?php endif; ?>

<div class="modal fade" id="completionTemplateModal" tabindex="-1" role="dialog" aria-labelledby="completionTemplateModalLabel" aria-hidden="true">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="completionTemplateModalLabel">Upload By Template Status SubSLS</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Tutup"><span aria-hidden="true">&times;</span></button>
      </div>
      <div class="modal-body">
        <a class="btn btn-success mb-3" href="completion_template.php?action=download"><i class="fas fa-download mr-1"></i>Download Template Excel</a>
        <?php if ($user['role'] === 'pengawas'): ?>
          <div class="alert alert-info mb-0">
            Isikan template dan kirim ke Tim SPBE BPS <?= e(completion_work_area_label($user)) ?>.
          </div>
        <?php else: ?>
          <form method="post" action="completion_template.php" enctype="multipart/form-data" data-progress-submit data-progress-title="Mengupload status selesai SubSLS..." data-progress-text="Mohon tunggu, template sedang dibaca dan disimpan.">
            <input type="hidden" name="return_to" value="status_selesai.php">
            <div class="form-group">
              <label>Upload Template Status SubSLS yang Sudah Diisi</label>
              <input class="form-control-file" type="file" name="template" accept=".xlsx" required>
            </div>
            <button class="btn btn-primary"><i class="fas fa-upload mr-1"></i>Upload Status SubSLS</button>
          </form>
        <?php endif; ?>
        <hr>
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
  </div>
</div>

<script>
const kabupaten = document.getElementById('kab_id');
if (kabupaten) {
  kabupaten.addEventListener('change', function () {
    document.getElementById('kec_id').value = '';
    document.getElementById('desa_id').value = '';
    this.form.submit();
  });
}
const kecamatan = document.getElementById('kec_id');
if (kecamatan) {
  kecamatan.addEventListener('change', function () {
    document.getElementById('desa_id').value = '';
    this.form.submit();
  });
}
</script>
<?php render_footer(); ?>
