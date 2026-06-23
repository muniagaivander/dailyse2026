<?php
require __DIR__ . '/layout.php';
$user = require_role(['superadmin', 'admin_kab', 'pengawas']);
$date = today();

function has_daily_submission(string $date, string $pengawasEmail): bool
{
    $stmt = db()->prepare("SELECT
        (SELECT COUNT(*) FROM submit_locks WHERE tanggal=? AND pengawas_email=?) +
        (SELECT COUNT(*) FROM daily_status WHERE tanggal=? AND pengawas_email=?) AS total");
    $stmt->execute([$date, $pengawasEmail, $date, $pengawasEmail]);
    return (int)$stmt->fetchColumn() > 0;
}

function daily_submission_info(string $date, string $pengawasEmail): ?array
{
    $stmt = db()->prepare("SELECT updated_by, MAX(updated_at) updated_at, MAX(submitted_at) submitted_at
        FROM daily_status
        WHERE tanggal=? AND pengawas_email=?
        GROUP BY updated_by
        ORDER BY updated_at DESC
        LIMIT 1");
    $stmt->execute([$date, $pengawasEmail]);
    $row = $stmt->fetch();
    if ($row) {
        return $row;
    }
    $stmt = db()->prepare("SELECT pengawas_email updated_by, submitted_at updated_at, submitted_at
        FROM submit_locks
        WHERE tanggal=? AND pengawas_email=?
        LIMIT 1");
    $stmt->execute([$date, $pengawasEmail]);
    return $stmt->fetch() ?: null;
}

function format_daily_submission_message(string $date, string $pengawasEmail): string
{
    $info = daily_submission_info($date, $pengawasEmail);
    if (!$info) {
        return 'Hari Ini sudah melakukan Input Harian';
    }
    $by = $info['updated_by'] ?: $pengawasEmail;
    $timeValue = $info['updated_at'] ?: ($info['submitted_at'] ?? '');
    $timeText = $timeValue ? ' tanggal ' . date('d/m/Y H:i', strtotime($timeValue)) : '';
    return 'Tanggal hari ini sudah diupload oleh ' . $by . $timeText . '.';
}

function input_area_options(array $user, array $filters): array
{
    $out = ['kabupaten' => [], 'kecamatan' => [], 'desa' => [], 'pengawas' => []];
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
    if (!empty($filters['desa_id'])) {
        $stmt = db()->prepare("SELECT DISTINCT ms.pengawas_email value, ms.pengawas_email label
            FROM master_subsls ms
            JOIN master_sls sl ON sl.id=ms.sls_id
            WHERE sl.desa_id=? AND ms.pengawas_email IS NOT NULL AND ms.pengawas_email <> ''
            ORDER BY ms.pengawas_email");
        $stmt->execute([$filters['desa_id']]);
        $out['pengawas'] = $stmt->fetchAll();
    }
    return $out;
}

function input_pengawas_access(array $user, string $pengawasEmail, array $filters): bool
{
    if ($user['role'] === 'pengawas') {
        return $pengawasEmail === $user['email'];
    }
    $where = ['ms.pengawas_email=?'];
    $params = [$pengawasEmail];
    if ($user['role'] === 'admin_kab') {
        $where[] = 'kc.kab_id=?';
        $params[] = $user['kab_id'];
    }
    foreach (['kab_id' => 'kc.kab_id', 'kec_id' => 'kc.id', 'desa_id' => 'd.id'] as $key => $col) {
        if (!empty($filters[$key])) {
            $where[] = "$col=?";
            $params[] = $filters[$key];
        }
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

function input_user_work_area_label(array $user): string
{
    $field = $user['role'] === 'pencacah' ? 'ms.pencacah_email' : 'ms.pengawas_email';
    $stmt = db()->prepare("SELECT DISTINCT CONCAT(k.id, ' - ', k.nmkab) label
        FROM master_subsls ms
        JOIN master_sls sl ON sl.id=ms.sls_id
        JOIN master_desa d ON d.id=sl.desa_id
        JOIN master_kec kc ON kc.id=d.kec_id
        JOIN master_kab k ON k.id=kc.kab_id
        WHERE $field=?
        ORDER BY label");
    $stmt->execute([$user['email']]);
    $labels = array_column($stmt->fetchAll(), 'label');
    return $labels ? implode(', ', $labels) : 'wilayah kerja Anda';
}

function save_daily($date, $subslsId, $actingUser, $pengawasEmail, $post, $i): void {
    $open = (int)($post['open_count'][$i] ?? 0);
    $draft = (int)($post['draft_count'][$i] ?? 0);
    $submitted = (int)($post['submitted_by_pencacah'][$i] ?? 0);
    $approved = (int)($post['approved_by_pengawas'][$i] ?? 0);
    $rejected = (int)($post['rejected_by_pengawas'][$i] ?? 0);
    $pending = (int)($post['pending_count'][$i] ?? 0);
    $target = $open + $draft + $submitted + $approved + $rejected + $pending;
    $stmt = db()->prepare("SELECT ms.*, kc.kab_id
        FROM master_subsls ms
        JOIN master_sls sl ON sl.id=ms.sls_id
        JOIN master_desa d ON d.id=sl.desa_id
        JOIN master_kec kc ON kc.id=d.kec_id
        WHERE ms.id=?");
    $stmt->execute([$subslsId]);
    $m = $stmt->fetch();
    if (!$m || $m['pengawas_email'] !== $pengawasEmail) return;
    if ($actingUser['role'] === 'admin_kab' && $m['kab_id'] !== $actingUser['kab_id']) return;

    db()->prepare("INSERT INTO daily_status (tanggal,subsls_id,kab_id,pengawas_email,pencacah_email,target,open_count,draft_count,submitted_by_pencacah,approved_by_pengawas,rejected_by_pengawas,pending_count,submitted_at,updated_by)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE target=VALUES(target),open_count=VALUES(open_count),draft_count=VALUES(draft_count),submitted_by_pencacah=VALUES(submitted_by_pencacah),approved_by_pengawas=VALUES(approved_by_pengawas),rejected_by_pengawas=VALUES(rejected_by_pengawas),pending_count=VALUES(pending_count),updated_by=VALUES(updated_by)")
        ->execute([$date,$subslsId,$m['kab_id'],$pengawasEmail,$m['pencacah_email'],$target,$open,$draft,$submitted,$approved,$rejected,$pending,date('Y-m-d H:i:s'),$actingUser['email']]);
    db()->prepare("REPLACE INTO subsls_status (subsls_id,open_count,draft_count,submitted_by_pencacah,approved_by_pengawas,rejected_by_pengawas,pending_count,target,last_update,updated_by)
        VALUES (?,?,?,?,?,?,?,?,?,?)")
        ->execute([$subslsId,$open,$draft,$submitted,$approved,$rejected,$pending,$target,date('Y-m-d H:i:s'),$actingUser['email']]);
}

$filters = [
    'kab_id' => $_GET['kab_id'] ?? ($_POST['kab_id'] ?? ''),
    'kec_id' => $_GET['kec_id'] ?? ($_POST['kec_id'] ?? ''),
    'desa_id' => $_GET['desa_id'] ?? ($_POST['desa_id'] ?? ''),
    'pengawas_email' => normalize_email($_GET['pengawas_email'] ?? ($_POST['pengawas_email'] ?? '')),
];
if ($user['role'] === 'admin_kab') {
    $filters['kab_id'] = $user['kab_id'];
}
if ($user['role'] === 'pengawas') {
    $filters['pengawas_email'] = $user['email'];
}

$selectedPengawas = $filters['pengawas_email'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $redirectQuery = ['show' => 1] + $filters;
    if (!$selectedPengawas || !input_pengawas_access($user, $selectedPengawas, $filters)) {
        flash('error', 'Pengawas tidak valid atau di luar wilayah akses user.');
        redirect('input.php?' . http_build_query($redirectQuery));
    }
    if (has_daily_submission($date, $selectedPengawas)) {
        flash('error', format_daily_submission_message($date, $selectedPengawas));
        redirect('input.php?' . http_build_query($redirectQuery));
    }
    $ids = $_POST['subsls_id'] ?? [];
    db()->beginTransaction();
    try {
        foreach ($ids as $i => $id) {
            save_daily($date, $id, $user, $selectedPengawas, $_POST, $i);
        }
        db()->prepare("INSERT INTO submit_locks (tanggal,pengawas_email,status)
            VALUES (?,?, 'SUBMITTED')
            ON DUPLICATE KEY UPDATE status=VALUES(status), updated_at=CURRENT_TIMESTAMP")
            ->execute([$date,$selectedPengawas]);
        db()->commit();
        flash('success', 'sukses kirim progress hari ini , jika ada yang salah silahkan ubah di menu edit harian');
    } catch (Throwable $e) {
        db()->rollBack();
        flash('error', $e->getMessage());
    }
    redirect('input.php?' . http_build_query($redirectQuery));
}

$opts = in_array($user['role'], ['superadmin', 'admin_kab'], true) ? input_area_options($user, $filters) : ['kabupaten' => [], 'kecamatan' => [], 'desa' => [], 'pengawas' => []];
$error = null;
$isLocked = $selectedPengawas ? has_daily_submission($date, $selectedPengawas) : false;
$rows = [];
$groups = [];
if (isset($_GET['show'])) {
    if (in_array($user['role'], ['superadmin', 'admin_kab'], true)) {
        if (!$filters['kab_id'] || !$filters['kec_id'] || !$filters['desa_id'] || !$selectedPengawas) {
            $error = $user['role'] === 'superadmin' ? 'Pilih kabupaten, kecamatan, desa, dan pengawas.' : 'Pilih kecamatan, desa, dan pengawas.';
        } elseif (!input_pengawas_access($user, $selectedPengawas, $filters)) {
            $error = 'Pengawas tidak valid atau di luar wilayah akses user.';
        }
    }
    if (!$error && $selectedPengawas && !$isLocked) {
        $where = ['ms.pengawas_email=?'];
        $params = [$selectedPengawas];
        if (!empty($filters['desa_id'])) {
            $where[] = 'd.id=?';
            $params[] = $filters['desa_id'];
        }
        if ($user['role'] === 'admin_kab') {
            $where[] = 'kc.kab_id=?';
            $params[] = $user['kab_id'];
        }
        $stmt = db()->prepare("SELECT ms.id subsls_id, ms.kdsubsls, ms.nmsubsls, ms.pencacah_email, sl.kdsls, sl.nmsls, d.nmdesa
            FROM master_subsls ms
            JOIN master_sls sl ON sl.id=ms.sls_id
            JOIN master_desa d ON d.id=sl.desa_id
            JOIN master_kec kc ON kc.id=d.kec_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY ms.pencacah_email, d.nmdesa, sl.nmsls, ms.kdsubsls");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        foreach ($rows as $row) {
            $key = $row['pencacah_email'] ?: 'Tanpa Pencacah';
            $groups[$key][] = $row;
        }
    }
}

render_header('Input Harian');
?>
<?php if ($user['role'] === 'pengawas'): ?>
  <div class="alert alert-info">
    Menu Input Harian sementara kami Tutup ya, akan ada pemberitahuan lebih lanjut. Semangat Bapak/Ibu Dalam Mengerjakan SE 2026. Kita PASTI BISA.
  </div>
  <?php render_footer(); exit; ?>
<?php endif; ?>
<div class="mb-3">
  <button class="btn btn-outline-success" type="button" data-toggle="modal" data-target="#dailyTemplateModal"><i class="fas fa-file-excel mr-1"></i>Upload By Template</button>
</div>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<?php if ($isLocked): ?>
  <div class="alert alert-info"><?= e(format_daily_submission_message($date, $selectedPengawas)) ?></div>
<?php endif; ?>

<?php if (!$isLocked): ?>
  <div class="card"><div class="card-body">
    <form method="get">
      <div class="form-row align-items-end">
        <div class="form-group col-md-2"><label>Tanggal</label><input class="form-control" value="<?= e($date) ?>" disabled></div>
        <?php if ($user['role'] === 'superadmin'): ?>
          <div class="form-group col-md-2">
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
          <div class="form-group col-md-2">
            <label>Kecamatan</label>
            <select class="form-control" name="kec_id" id="kec_id" <?= $filters['kab_id'] ? '' : 'disabled' ?> required>
              <option value=""><?= $filters['kab_id'] ? 'Pilih Kecamatan' : 'Pilih kabupaten dulu' ?></option>
              <?php foreach ($opts['kecamatan'] as $o): ?><option value="<?= e($o['value']) ?>" <?= $filters['kec_id']===$o['value']?'selected':'' ?>><?= e($o['label']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="form-group col-md-2">
            <label>Desa</label>
            <select class="form-control" name="desa_id" id="desa_id" <?= $filters['kec_id'] ? '' : 'disabled' ?> required>
              <option value=""><?= $filters['kec_id'] ? 'Pilih Desa' : 'Pilih kecamatan dulu' ?></option>
              <?php foreach ($opts['desa'] as $o): ?><option value="<?= e($o['value']) ?>" <?= $filters['desa_id']===$o['value']?'selected':'' ?>><?= e($o['label']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="form-group col-md-3">
            <label>Pengawas</label>
            <select class="form-control" name="pengawas_email" id="pengawas_email" <?= $filters['desa_id'] ? '' : 'disabled' ?> required>
              <option value=""><?= $filters['desa_id'] ? 'Pilih Pengawas' : 'Pilih desa dulu' ?></option>
              <?php foreach ($opts['pengawas'] as $o): ?><option value="<?= e($o['value']) ?>" <?= $selectedPengawas===$o['value']?'selected':'' ?>><?= e($o['label']) ?></option><?php endforeach; ?>
            </select>
          </div>
        <?php endif; ?>
        <div class="form-group col-md-2"><button class="btn btn-primary" name="show" value="1">Tampilkan Form Input</button></div>
      </div>
    </form>
  </div></div>
<?php endif; ?>

<?php if ($rows): ?>
<style>
.pencacah-tabs .nav-link {
  border: 1px solid #86efac;
  color: #111827;
  margin: 0 6px 6px 0;
}
.pencacah-tabs .nav-link.active {
  background: #dcfce7;
  border-color: #22c55e;
  color: #111827;
}
</style>
<form method="post" id="dailyInputForm" data-progress-submit data-progress-title="Mengirim progress harian..." data-progress-text="Mohon tunggu, semua tab pencacah sedang disimpan.">
  <input type="hidden" name="kab_id" value="<?= e($filters['kab_id']) ?>">
  <input type="hidden" name="kec_id" value="<?= e($filters['kec_id']) ?>">
  <input type="hidden" name="desa_id" value="<?= e($filters['desa_id']) ?>">
  <input type="hidden" name="pengawas_email" value="<?= e($selectedPengawas) ?>">
  <div class="card">
    <div class="card-header p-2">
      <ul class="nav nav-pills pencacah-tabs" role="tablist">
        <?php $tabIndex = 0; foreach ($groups as $pencacah => $items): ?>
          <?php $tabId = 'pencacah-' . $tabIndex; ?>
          <li class="nav-item">
            <a class="nav-link <?= $tabIndex === 0 ? 'active' : '' ?>" data-toggle="tab" href="#<?= e($tabId) ?>" role="tab">
              <?= e($pencacah) ?> <span class="badge badge-light ml-1"><?= count($items) ?></span>
            </a>
          </li>
        <?php $tabIndex++; endforeach; ?>
      </ul>
    </div>
    <div class="card-body p-0">
      <div class="tab-content">
        <?php $tabIndex = 0; foreach ($groups as $pencacah => $items): ?>
          <?php $tabId = 'pencacah-' . $tabIndex; ?>
          <div class="tab-pane fade <?= $tabIndex === 0 ? 'show active' : '' ?>" id="<?= e($tabId) ?>" role="tabpanel">
            <div class="table-responsive">
              <table class="table table-sm table-bordered mb-0">
                <thead>
                  <tr>
                    <th>Desa</th><th>SLS</th><th>Kode SubSLS</th><th>SubSLS</th><th>Target</th><?php foreach (daily_form_status_fields() as $label): ?><th><?= e($label) ?></th><?php endforeach; ?>
                  </tr>
                </thead>
                <tbody>
                <?php foreach ($items as $r): ?>
                  <tr>
                    <td><?= e($r['nmdesa']) ?></td>
                    <td><?= e($r['kdsls'] . ' - ' . $r['nmsls']) ?></td>
                    <td><?= e($r['kdsls'] . $r['kdsubsls']) ?><input type="hidden" name="subsls_id[]" value="<?= e($r['subsls_id']) ?>"></td>
                    <td><?= e($r['nmsubsls']) ?></td>
                    <td><input class="form-control form-control-sm target" disabled value="0"></td>
                    <?php foreach (array_keys(daily_form_status_fields()) as $f): ?>
                      <td><input class="form-control form-control-sm status-input" type="number" min="0" name="<?= $f ?>[]" value="0"></td>
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
  <button id="submitBtn" class="btn btn-success mb-4 mt-3">Kirim Data Hari Ini</button>
</form>
<?php elseif (isset($_GET['show']) && !$error && !$isLocked && $selectedPengawas): ?>
  <div class="alert alert-info">Tidak ada SubSLS untuk pengawas pada filter ini.</div>
<?php endif; ?>

<div class="modal fade" id="confirmDailySubmitModal" tabindex="-1" role="dialog" aria-labelledby="confirmDailySubmitModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="confirmDailySubmitModalLabel">Konfirmasi Pengiriman</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Tutup"><span aria-hidden="true">&times;</span></button>
      </div>
      <div class="modal-body">
        <p class="mb-0">Sudah Lengkap Semua Pencacah?</p>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" type="button" data-dismiss="modal">Batal</button>
        <button class="btn btn-success" type="button" id="confirmDailySubmitYes">Ya</button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="dailyTemplateModal" tabindex="-1" role="dialog" aria-labelledby="dailyTemplateModalLabel" aria-hidden="true">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="dailyTemplateModalLabel">Upload By Template</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Tutup"><span aria-hidden="true">&times;</span></button>
      </div>
      <div class="modal-body">
        <?php $templatePage = $user['role'] === 'pengawas' ? 'pml_daily_template.php' : 'daily_template.php'; ?>
        <a class="btn btn-success mb-3" href="<?= e($templatePage) ?>?action=download"><i class="fas fa-download mr-1"></i>Download Template Excel</a>
        <?php if ($user['role'] === 'pengawas'): ?>
          <div class="alert alert-info mb-0">
            Isikan template dan kirim ke Tim SPBE BPS <?= e(input_user_work_area_label($user)) ?>.
          </div>
        <?php else: ?>
          <form method="post" action="<?= e($templatePage) ?>" enctype="multipart/form-data" data-progress-submit data-progress-title="Mengupload progress harian..." data-progress-text="Mohon tunggu, template sedang dibaca dan disimpan.">
            <input type="hidden" name="return_to" value="input.php">
            <div class="form-group">
              <label>Upload Template yang Sudah Diisi</label>
              <input class="form-control-file" type="file" name="template" accept=".xlsx" required>
            </div>
            <button class="btn btn-primary"><i class="fas fa-upload mr-1"></i>Upload Progress Harian</button>
          </form>
        <?php endif; ?>
        <hr>
        <table class="table table-sm table-bordered mb-0">
          <thead><tr><th>Kolom</th><th>Keterangan</th></tr></thead>
          <tbody>
            <tr><td>tanggal</td><td>Format disarankan YYYY-MM-DD, contoh <?= e(today()) ?>. Tidak boleh lebih besar dari hari upload.</td></tr>
            <tr><td>subsls_id</td><td>Kunci unik wilayah. Isi hanya baris SubSLS yang mau di-upload.</td></tr>
            <tr><td>open, draft, submit, reject, pending, approved</td><td>Nilai status harian. Target dihitung otomatis dari jumlah enam status ini.</td></tr>
            <tr><td>pengawas_email dan pencacah_email</td><td>Hanya informasi dari master, tidak dipakai untuk mengganti petugas.</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<script>
document.querySelectorAll('.status-input').forEach(input => input.addEventListener('input', () => {
  const tr = input.closest('tr');
  const total = Array.from(tr.querySelectorAll('.status-input')).reduce((s, el) => s + Number(el.value || 0), 0);
  tr.querySelector('.target').value = total;
}));
const kabupaten = document.getElementById('kab_id');
if (kabupaten) {
  kabupaten.addEventListener('change', function () {
    document.getElementById('kec_id').value = '';
    document.getElementById('desa_id').value = '';
    document.getElementById('pengawas_email').value = '';
    this.form.submit();
  });
}
const kecamatan = document.getElementById('kec_id');
if (kecamatan) {
  kecamatan.addEventListener('change', function () {
    document.getElementById('desa_id').value = '';
    document.getElementById('pengawas_email').value = '';
    this.form.submit();
  });
}
const desa = document.getElementById('desa_id');
if (desa) {
  desa.addEventListener('change', function () {
    document.getElementById('pengawas_email').value = '';
    this.form.submit();
  });
}
const submitBtn = document.getElementById('submitBtn');
if (submitBtn) {
  submitBtn.addEventListener('click', function (event) {
    event.preventDefault();
    $('#confirmDailySubmitModal').modal('show');
  });
}
const confirmDailySubmitYes = document.getElementById('confirmDailySubmitYes');
if (confirmDailySubmitYes) {
  confirmDailySubmitYes.addEventListener('click', function () {
    const form = document.getElementById('dailyInputForm');
    $('#confirmDailySubmitModal').modal('hide');
    if (form.requestSubmit) {
      form.requestSubmit();
    } else {
      form.submit();
    }
  });
}
</script>
<?php render_footer(); ?>
