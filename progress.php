<?php
require __DIR__ . '/layout.php';

$user = require_role(['admin_kab','superadmin','viewer_prov','viewer_kab','pengawas','pencacah']);
$type = ($_GET['type'] ?? 'pengawas') === 'pencacah' ? 'pencacah' : 'pengawas';
if (in_array($user['role'], ['pengawas', 'pencacah'], true)) {
    $type = 'pencacah';
}
$filters = [
    'month' => $_GET['month'] ?? '',
    'kab_id' => $_GET['kab_id'] ?? '',
    'email' => normalize_email($_GET['email'] ?? ''),
    'kec_id' => $_GET['kec_id'] ?? '',
    'desa_id' => $_GET['desa_id'] ?? '',
    'subsls_id' => $_GET['subsls_id'] ?? '',
];
$monthOptions = [
    '06' => 'Juni',
    '07' => 'Juli',
    '08' => 'Agustus',
    '09' => 'September',
];
if (!array_key_exists($filters['month'], $monthOptions)) {
    $filters['month'] = '';
}
if (in_array($user['role'], ['admin_kab', 'viewer_kab'], true)) {
    $filters['kab_id'] = $user['kab_id'];
}
if ($user['role'] === 'pengawas') {
    $stmt = db()->prepare("SELECT k.id
        FROM master_subsls ms
        JOIN master_sls sl ON sl.id=ms.sls_id
        JOIN master_desa d ON d.id=sl.desa_id
        JOIN master_kec kc ON kc.id=d.kec_id
        JOIN master_kab k ON k.id=kc.kab_id
        WHERE ms.pengawas_email=?
        ORDER BY k.id
        LIMIT 1");
    $stmt->execute([$user['email']]);
    $filters['kab_id'] = (string)($stmt->fetchColumn() ?: '');
}
if ($user['role'] === 'pencacah') {
    $stmt = db()->prepare("SELECT k.id
        FROM master_subsls ms
        JOIN master_sls sl ON sl.id=ms.sls_id
        JOIN master_desa d ON d.id=sl.desa_id
        JOIN master_kec kc ON kc.id=d.kec_id
        JOIN master_kab k ON k.id=kc.kab_id
        WHERE ms.pencacah_email=?
        ORDER BY k.id
        LIMIT 1");
    $stmt->execute([$user['email']]);
    $filters['kab_id'] = (string)($stmt->fetchColumn() ?: '');
    $filters['email'] = $user['email'];
}

function progress_email_field(string $type, string $alias = 'ms'): string
{
    return $type === 'pencacah' ? "{$alias}.pencacah_email" : "{$alias}.pengawas_email";
}

function progress_kabupaten_options(array $user): array
{
    if (in_array($user['role'], ['admin_kab', 'viewer_kab'], true)) {
        $stmt = db()->prepare("SELECT id value, CONCAT(id,' - ',nmkab) label FROM master_kab WHERE id=? ORDER BY id");
        $stmt->execute([$user['kab_id']]);
        return $stmt->fetchAll();
    }
    return db()->query("SELECT id value, CONCAT(id,' - ',nmkab) label FROM master_kab ORDER BY id")->fetchAll();
}

function progress_email_options(array $user, string $type, array $filters): array
{
    if (empty($filters['kab_id'])) {
        return [];
    }
    $field = progress_email_field($type);
    $where = ['k.id=?', "{$field} IS NOT NULL", "{$field} <> ''"];
    $params = [$filters['kab_id']];
    if (in_array($user['role'], ['admin_kab', 'viewer_kab'], true)) {
        $params[0] = $user['kab_id'];
    }
    if ($user['role'] === 'pengawas') {
        $where[] = 'ms.pengawas_email=?';
        $params[] = $user['email'];
    }
    if ($user['role'] === 'pencacah') {
        $where[] = 'ms.pencacah_email=?';
        $params[] = $user['email'];
    }
    $stmt = db()->prepare("SELECT DISTINCT {$field} email
        FROM master_subsls ms
        JOIN master_sls sl ON sl.id=ms.sls_id
        JOIN master_desa d ON d.id=sl.desa_id
        JOIN master_kec kc ON kc.id=d.kec_id
        JOIN master_kab k ON k.id=kc.kab_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY email");
    $stmt->execute($params);
    return array_column($stmt->fetchAll(), 'email');
}

function progress_area_options(array $user, string $type, array $filters): array
{
    $out = ['kecamatan' => [], 'desa' => [], 'subsls' => []];
    $canUseAllPencacah = $user['role'] === 'pengawas' && $type === 'pencacah';
    if (empty($filters['kab_id']) || (empty($filters['email']) && !$canUseAllPencacah)) {
        return $out;
    }

    $field = progress_email_field($type);
    $base = "FROM master_subsls ms
        JOIN master_sls sl ON sl.id=ms.sls_id
        JOIN master_desa d ON d.id=sl.desa_id
        JOIN master_kec kc ON kc.id=d.kec_id
        JOIN master_kab k ON k.id=kc.kab_id
        WHERE k.id=?";
    $baseParams = [$filters['kab_id']];
    if (!empty($filters['email'])) {
        $base .= " AND {$field}=?";
        $baseParams[] = $filters['email'];
    }
    if ($user['role'] === 'pengawas') {
        $base .= " AND ms.pengawas_email=?";
        $baseParams[] = $user['email'];
    }
    if ($user['role'] === 'pencacah') {
        $base .= " AND ms.pencacah_email=?";
        $baseParams[] = $user['email'];
    }

    $stmt = db()->prepare("SELECT DISTINCT kc.id value, CONCAT(kc.kdkec,' - ',kc.nmkec) label
        {$base}
        ORDER BY label");
    $stmt->execute($baseParams);
    $out['kecamatan'] = $stmt->fetchAll();

    if (!empty($filters['kec_id'])) {
        $stmt = db()->prepare("SELECT DISTINCT d.id value, CONCAT(d.kddesa,' - ',d.nmdesa) label
            {$base} AND kc.id=?
            ORDER BY label");
        $stmt->execute(array_merge($baseParams, [$filters['kec_id']]));
        $out['desa'] = $stmt->fetchAll();
    }

    if (!empty($filters['desa_id'])) {
        $stmt = db()->prepare("SELECT DISTINCT ms.id value, CONCAT(sl.kdsls, ms.kdsubsls, ' - ', ms.nmsubsls) label,
                sl.kdsls sort_sls, ms.kdsubsls sort_subsls
            {$base} AND d.id=?
            ORDER BY sort_sls, sort_subsls, value");
        $stmt->execute(array_merge($baseParams, [$filters['desa_id']]));
        $out['subsls'] = $stmt->fetchAll();
    }

    return $out;
}

function progress_trend_where(array $user, string $type, array $filters): array
{
    $field = $type === 'pencacah' ? 'ds.pencacah_email' : 'ds.pengawas_email';
    $where = ['k.id=?'];
    $params = [$filters['kab_id']];
    if (!empty($filters['email'])) {
        $where[] = "{$field}=?";
        $params[] = $filters['email'];
    }
    if (in_array($user['role'], ['admin_kab', 'viewer_kab'], true)) {
        $params[0] = $user['kab_id'];
    }
    if ($user['role'] === 'pengawas') {
        $where[] = 'ds.pengawas_email=?';
        $params[] = $user['email'];
    }
    if ($user['role'] === 'pencacah') {
        $where[] = 'ds.pencacah_email=?';
        $params[] = $user['email'];
    }
    if (!empty($filters['month'])) {
        $where[] = 'MONTH(ds.tanggal)=?';
        $params[] = (int)$filters['month'];
    }
    foreach (['kec_id' => 'kc.id', 'desa_id' => 'd.id', 'subsls_id' => 'ms.id'] as $key => $col) {
        if (!empty($filters[$key])) {
            $where[] = "{$col}=?";
            $params[] = $filters[$key];
        }
    }
    return [$where, $params];
}

function progress_current_cards(array $user, string $type, array $filters): array
{
    $field = progress_email_field($type);
    $where = ['k.id=?'];
    $params = [$filters['kab_id']];
    if (!empty($filters['email'])) {
        $where[] = "{$field}=?";
        $params[] = $filters['email'];
    }
    if (in_array($user['role'], ['admin_kab', 'viewer_kab'], true)) {
        $params[0] = $user['kab_id'];
    }
    if ($user['role'] === 'pengawas') {
        $where[] = 'ms.pengawas_email=?';
        $params[] = $user['email'];
    }
    if ($user['role'] === 'pencacah') {
        $where[] = 'ms.pencacah_email=?';
        $params[] = $user['email'];
    }
    foreach (['kec_id' => 'kc.id', 'desa_id' => 'd.id', 'subsls_id' => 'ms.id'] as $key => $col) {
        if (!empty($filters[$key])) {
            $where[] = "{$col}=?";
            $params[] = $filters[$key];
        }
    }
    $stmt = db()->prepare("SELECT COALESCE(SUM(ss.target),0) target,
            COALESCE(SUM(ss.open_count),0) open_count,
            COALESCE(SUM(ss.draft_count),0) draft_count,
            COALESCE(SUM(ss.submitted_by_pencacah),0) submitted_by_pencacah,
            COALESCE(SUM(ss.approved_by_pengawas),0) approved_by_pengawas,
            COALESCE(SUM(ss.rejected_by_pengawas),0) rejected_by_pengawas,
            COALESCE(SUM(ss.pending_count),0) pending_count
        FROM master_subsls ms
        JOIN master_sls sl ON sl.id=ms.sls_id
        JOIN master_desa d ON d.id=sl.desa_id
        JOIN master_kec kc ON kc.id=d.kec_id
        JOIN master_kab k ON k.id=kc.kab_id
        LEFT JOIN subsls_status ss ON ss.subsls_id=ms.id
        WHERE " . implode(' AND ', $where));
    $stmt->execute($params);
    $row = $stmt->fetch() ?: [];
    $cards = array_fill_keys(array_merge(['target'], array_keys(status_fields())), 0);
    foreach ($cards as $key => $_) {
        $cards[$key] = (int)($row[$key] ?? 0);
    }
    return $cards;
}

$kabupatenOptions = progress_kabupaten_options($user);
$emails = progress_email_options($user, $type, $filters);
if ($filters['email'] && !in_array($filters['email'], $emails, true)) {
    $filters['email'] = '';
    $filters['kec_id'] = '';
    $filters['desa_id'] = '';
    $filters['subsls_id'] = '';
}

$areaOptions = progress_area_options($user, $type, $filters);
if ($filters['kec_id'] && !in_array($filters['kec_id'], array_column($areaOptions['kecamatan'], 'value'), true)) {
    $filters['kec_id'] = '';
    $filters['desa_id'] = '';
    $filters['subsls_id'] = '';
}
if ($filters['desa_id'] && !in_array($filters['desa_id'], array_column($areaOptions['desa'], 'value'), true)) {
    $filters['desa_id'] = '';
    $filters['subsls_id'] = '';
}
if ($filters['subsls_id'] && !in_array($filters['subsls_id'], array_column($areaOptions['subsls'], 'value'), true)) {
    $filters['subsls_id'] = '';
}

$trend = [];
$cards = array_fill_keys(array_merge(['target'], array_keys(status_fields())), 0);
$emailReady = !empty($filters['email']) || ($user['role'] === 'pengawas' && $type === 'pencacah');
$showProgress = ($_GET['action'] ?? '') === 'filter' && $emailReady && !empty($filters['kab_id']);
if ($showProgress) {
    [$trendWhere, $trendParams] = progress_trend_where($user, $type, $filters);
    $stmt = db()->prepare("SELECT ds.tanggal, SUM(ds.target) target, SUM(ds.open_count) open_count, SUM(ds.draft_count) draft_count,
            SUM(ds.submitted_by_pencacah) submitted_by_pencacah, SUM(ds.approved_by_pengawas) approved_by_pengawas,
            SUM(ds.rejected_by_pengawas) rejected_by_pengawas, SUM(ds.pending_count) pending_count
        FROM daily_status ds
        JOIN master_subsls ms ON ms.id=ds.subsls_id
        JOIN master_sls sl ON sl.id=ms.sls_id
        JOIN master_desa d ON d.id=sl.desa_id
        JOIN master_kec kc ON kc.id=d.kec_id
        JOIN master_kab k ON k.id=kc.kab_id
        WHERE " . implode(' AND ', $trendWhere) . "
        GROUP BY ds.tanggal
        ORDER BY ds.tanggal");
    $stmt->execute($trendParams);
    $trend = $stmt->fetchAll();
    $cards = progress_current_cards($user, $type, $filters);
}

$EXTRA_HEAD = '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">' . "\n"
    . '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@ttskch/select2-bootstrap4-theme@1.5.2/dist/select2-bootstrap4.min.css">';
$EXTRA_FOOTER_SCRIPTS = '<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>';

render_header('Progress ' . ucfirst($type));
?>
<style>
  .progress-chart-wrap {
    height: 380px;
    position: relative;
    width: 100%;
  }
  @media (max-width: 767.98px) {
    .progress-chart-wrap { height: 320px; }
  }
</style>
<form class="card card-body mb-3" method="get" id="progressFilterForm">
  <input type="hidden" name="type" value="<?= e($type) ?>">
  <div class="form-row align-items-end">
    <div class="form-group col-md-2">
      <label>Bulan</label>
      <select class="form-control" name="month" id="month">
        <option value="">Semua Bulan</option>
        <?php foreach ($monthOptions as $value => $label): ?><option value="<?= e($value) ?>" <?= $filters['month']===$value?'selected':'' ?>><?= e($label) ?></option><?php endforeach; ?>
      </select>
    </div>
    <?php if (in_array($user['role'], ['superadmin','viewer_prov'], true)): ?>
      <div class="form-group col-md-2">
        <label>Kabupaten</label>
        <select class="form-control" name="kab_id" id="kab_id">
          <option value="">Pilih Kabupaten</option>
          <?php foreach ($kabupatenOptions as $o): ?><option value="<?= e($o['value']) ?>" <?= $filters['kab_id']===$o['value']?'selected':'' ?>><?= e($o['label']) ?></option><?php endforeach; ?>
        </select>
      </div>
    <?php else: ?>
      <input type="hidden" name="kab_id" value="<?= e($filters['kab_id']) ?>">
    <?php endif; ?>

    <?php if ($user['role'] === 'pencacah'): ?>
      <input type="hidden" name="email" id="email" value="<?= e($filters['email']) ?>">
    <?php else: ?>
      <div class="form-group col-md-2">
        <label>Email <?= e($type) ?></label>
        <select class="form-control select2-email" name="email" id="email" <?= $filters['kab_id'] ? '' : 'disabled' ?>>
          <option value=""><?= $user['role'] === 'pengawas' ? 'Semua Pencacah' : ($filters['kab_id'] ? 'Pilih email ' . e($type) : 'Pilih kabupaten dulu') ?></option>
          <?php foreach ($emails as $email): ?><option value="<?= e($email) ?>" <?= $filters['email']===$email?'selected':'' ?>><?= e($email) ?></option><?php endforeach; ?>
        </select>
      </div>
    <?php endif; ?>

    <div class="form-group col-md-2">
      <label>Kecamatan</label>
      <select class="form-control" name="kec_id" id="kec_id" <?= $emailReady ? '' : 'disabled' ?>>
        <option value=""><?= $emailReady ? 'Semua Kecamatan' : 'Pilih petugas dulu' ?></option>
        <?php foreach ($areaOptions['kecamatan'] as $o): ?><option value="<?= e($o['value']) ?>" <?= $filters['kec_id']===$o['value']?'selected':'' ?>><?= e($o['label']) ?></option><?php endforeach; ?>
      </select>
    </div>

    <div class="form-group col-md-2">
      <label>Desa</label>
      <select class="form-control" name="desa_id" id="desa_id" <?= $filters['kec_id'] ? '' : 'disabled' ?>>
        <option value=""><?= $filters['kec_id'] ? 'Semua Desa' : 'Pilih kecamatan dulu' ?></option>
        <?php foreach ($areaOptions['desa'] as $o): ?><option value="<?= e($o['value']) ?>" <?= $filters['desa_id']===$o['value']?'selected':'' ?>><?= e($o['label']) ?></option><?php endforeach; ?>
      </select>
    </div>

    <div class="form-group col-md-2">
      <label>SubSLS</label>
      <select class="form-control" name="subsls_id" id="subsls_id" <?= $filters['desa_id'] ? '' : 'disabled' ?>>
        <option value=""><?= $filters['desa_id'] ? 'Semua SubSLS' : 'Pilih desa dulu' ?></option>
        <?php foreach ($areaOptions['subsls'] as $o): ?><option value="<?= e($o['value']) ?>" <?= $filters['subsls_id']===$o['value']?'selected':'' ?>><?= e($o['label']) ?></option><?php endforeach; ?>
      </select>
    </div>
  </div>
  <button class="btn btn-primary" name="action" value="filter">Filter</button>
</form>

<?php if (!$emailReady): ?>
  <div class="alert alert-info">Pilih <?= in_array($user['role'], ['superadmin','viewer_prov'], true) ? 'kabupaten dan ' : '' ?>email <?= e($type) ?> untuk menampilkan progress.</div>
<?php endif; ?>

<?php if ($emailReady && !$showProgress): ?>
  <div class="alert alert-info">Atur filter wilayah, lalu klik tombol Filter untuk menampilkan progress.</div>
<?php endif; ?>

<?php if ($showProgress): ?>
<div class="row"><?php foreach (array_merge(['target'=>'Target'], status_fields()) as $field=>$label): ?><div class="col-md"><div class="small-box bg-light"><div class="inner"><h3><?= number_format((int)$cards[$field],0,',','.') ?></h3><p><?= e($label) ?></p></div></div></div><?php endforeach; ?></div>
<div class="card"><div class="card-body"><div class="progress-chart-wrap"><canvas id="lineChart"></canvas></div></div></div>
<script>
const rows = <?= json_encode($trend) ?>;
const fields = <?= json_encode(array_keys(status_fields())) ?>;
const labels = <?= json_encode(array_values(status_fields())) ?>;
const colors = ['#2563eb','#f59e0b','#16a34a','#dc2626','#7c3aed','#0f766e'];
new Chart(document.getElementById('lineChart'), {
  type:'line',
  data:{ labels: rows.map(r=>r.tanggal), datasets: fields.map((f,i)=>({ label:labels[i], data:rows.map(r=>Number(r.target)?Math.round(Number(r[f])/Number(r.target)*10000)/100:0), borderColor:colors[i], backgroundColor:colors[i], tension:.2 })) },
  options:{ responsive:true, maintainAspectRatio:false, scales:{ y:{min:0,max:100,ticks:{callback:v=>v+'%'}} } }
});
</script>
<?php endif; ?>

<script>
const form = document.getElementById('progressFilterForm');
const monthSelect = document.getElementById('month');
const kabSelect = document.getElementById('kab_id');
const emailSelect = document.getElementById('email');
const kecSelect = document.getElementById('kec_id');
const desaSelect = document.getElementById('desa_id');
const subslsSelect = document.getElementById('subsls_id');
let progressFilterSubmitting = false;

function submitProgressFilter() {
  if (progressFilterSubmitting) return;
  progressFilterSubmitting = true;
  form.submit();
}

if (kabSelect) {
  kabSelect.addEventListener('change', function () {
    emailSelect.value = '';
    kecSelect.value = '';
    desaSelect.value = '';
    subslsSelect.value = '';
    submitProgressFilter();
  });
}
if (emailSelect && emailSelect.tagName === 'SELECT') {
  emailSelect.addEventListener('change', function () {
    kecSelect.value = '';
    desaSelect.value = '';
    subslsSelect.value = '';
    submitProgressFilter();
  });
}
kecSelect.addEventListener('change', function () {
  desaSelect.value = '';
  subslsSelect.value = '';
  submitProgressFilter();
});
desaSelect.addEventListener('change', function () {
  subslsSelect.value = '';
  submitProgressFilter();
});
window.addEventListener('load', function () {
  if (window.jQuery && jQuery.fn.select2 && emailSelect && emailSelect.tagName === 'SELECT') {
    jQuery(emailSelect).select2({
      theme: 'bootstrap4',
      width: '100%',
      placeholder: 'Pilih email <?= e($type) ?>'
    }).on('select2:select select2:clear change', function () {
      kecSelect.value = '';
      desaSelect.value = '';
      subslsSelect.value = '';
      submitProgressFilter();
    });
  }
});
</script>
<?php render_footer(); ?>
