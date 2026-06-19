<?php
require __DIR__ . '/layout.php';
$user = require_role(['admin_kab','superadmin','viewer_prov','viewer_kab']);
$type = ($_GET['type'] ?? 'pengawas') === 'pencacah' ? 'pencacah' : 'pengawas';
$filters = ['kab_id'=>$_GET['kab_id'] ?? '', 'kec_id'=>$_GET['kec_id'] ?? '', 'desa_id'=>$_GET['desa_id'] ?? ''];
if (in_array($user['role'], ['admin_kab', 'viewer_kab'], true)) $filters['kab_id'] = $user['kab_id'];

function progress_filter_options(array $user, array $filters): array
{
    $out = ['kabupaten' => [], 'kecamatan' => [], 'desa' => []];
    if (in_array($user['role'], ['admin_kab', 'viewer_kab'], true)) {
        $stmt = db()->prepare("SELECT id value, CONCAT(id,' - ',nmkab) label FROM master_kab WHERE id=? ORDER BY id");
        $stmt->execute([$user['kab_id']]);
    } else {
        $stmt = db()->query("SELECT id value, CONCAT(id,' - ',nmkab) label FROM master_kab ORDER BY id");
    }
    $out['kabupaten'] = $stmt->fetchAll();

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

$opts = progress_filter_options($user, $filters);
$where = [];
$params = [];
if (in_array($user['role'], ['admin_kab', 'viewer_kab'], true)) { $where[] = 'k.id=?'; $params[] = $user['kab_id']; }
foreach (['kab_id'=>'k.id','kec_id'=>'kc.id','desa_id'=>'d.id'] as $key=>$col) {
    if (!empty($filters[$key])) { $where[]="$col=?"; $params[]=$filters[$key]; }
}
$emailField = $type === 'pencacah' ? 'ms.pencacah_email' : 'ms.pengawas_email';
$where[] = "$emailField <> ''";
$sqlWhere = 'WHERE ' . implode(' AND ', $where);
$emails = [];
if (!empty($filters['desa_id'])) {
    $stmt = db()->prepare("SELECT DISTINCT $emailField email " . joined_master_sql() . " $sqlWhere ORDER BY email");
    $stmt->execute($params);
    $emails = array_column($stmt->fetchAll(), 'email');
}
$selected = $_GET['email'] ?? '';
if ($selected && !in_array($selected, $emails, true)) {
    $selected = '';
}
$trend = [];
$cards = array_fill_keys(array_merge(['target'], array_keys(status_fields())), 0);
if ($selected) {
    $field = $type === 'pencacah' ? 'pencacah_email' : 'pengawas_email';
    $trendWhere = ["ds.$field=?"];
    $trendParams = [$selected];
    if (in_array($user['role'], ['admin_kab', 'viewer_kab'], true)) {
        $trendWhere[] = 'k.id=?';
        $trendParams[] = $user['kab_id'];
    }
    foreach (['kab_id'=>'k.id','kec_id'=>'kc.id','desa_id'=>'d.id'] as $key=>$col) {
        if (!empty($filters[$key])) {
            $trendWhere[] = "$col=?";
            $trendParams[] = $filters[$key];
        }
    }
    $trendSqlWhere = 'WHERE ' . implode(' AND ', $trendWhere);
    $stmt = db()->prepare("SELECT ds.tanggal, SUM(ds.target) target, SUM(ds.open_count) open_count, SUM(ds.draft_count) draft_count,
            SUM(ds.submitted_by_pencacah) submitted_by_pencacah, SUM(ds.approved_by_pengawas) approved_by_pengawas,
            SUM(ds.rejected_by_pengawas) rejected_by_pengawas
        FROM daily_status ds
        JOIN master_subsls ms ON ms.id=ds.subsls_id
        JOIN master_sls sl ON sl.id=ms.sls_id
        JOIN master_desa d ON d.id=sl.desa_id
        JOIN master_kec kc ON kc.id=d.kec_id
        JOIN master_kab k ON k.id=kc.kab_id
        $trendSqlWhere
        GROUP BY ds.tanggal
        ORDER BY ds.tanggal");
    $stmt->execute($trendParams);
    $trend = $stmt->fetchAll();
    foreach ($trend as $r) foreach ($cards as $k=>$_) $cards[$k] += (int)$r[$k];
}
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
<form class="card card-body mb-3" method="get">
  <input type="hidden" name="type" value="<?= e($type) ?>">
  <div class="form-row align-items-end">
    <div class="form-group col-md-2"><label>Kabupaten</label><select class="form-control" name="kab_id" id="kab_id" <?= in_array($user['role'], ['admin_kab','viewer_kab'], true) ? 'disabled' : '' ?>><option value="">Pilih Kabupaten</option><?php foreach ($opts['kabupaten'] as $o): ?><option value="<?= e($o['value']) ?>" <?= $filters['kab_id']===$o['value']?'selected':'' ?>><?= e($o['label']) ?></option><?php endforeach; ?></select><?php if (in_array($user['role'], ['admin_kab','viewer_kab'], true)): ?><input type="hidden" name="kab_id" value="<?= e($filters['kab_id']) ?>"><?php endif; ?></div>
    <div class="form-group col-md-2"><label>Kecamatan</label><select class="form-control" name="kec_id" id="kec_id" <?= $filters['kab_id'] ? '' : 'disabled' ?>><option value="">Pilih Kecamatan</option><?php foreach ($opts['kecamatan'] as $o): ?><option value="<?= e($o['value']) ?>" <?= $filters['kec_id']===$o['value']?'selected':'' ?>><?= e($o['label']) ?></option><?php endforeach; ?></select></div>
    <div class="form-group col-md-2"><label>Desa</label><select class="form-control" name="desa_id" id="desa_id" <?= $filters['kec_id'] ? '' : 'disabled' ?>><option value="">Pilih Desa</option><?php foreach ($opts['desa'] as $o): ?><option value="<?= e($o['value']) ?>" <?= $filters['desa_id']===$o['value']?'selected':'' ?>><?= e($o['label']) ?></option><?php endforeach; ?></select></div>
    <div class="form-group col-md-3"><label>Email <?= e($type) ?></label><select class="form-control" name="email" id="email" <?= $filters['desa_id'] ? '' : 'disabled' ?>><option value="">Pilih email <?= e($type) ?></option><?php foreach ($emails as $email): ?><option value="<?= e($email) ?>" <?= $selected===$email?'selected':'' ?>><?= e($email) ?></option><?php endforeach; ?></select></div>
    <div class="form-group col-md-2"><button class="btn btn-primary">Filter</button></div>
  </div>
</form>
<?php if (!$selected): ?>
  <div class="alert alert-info">Pilih filter wilayah lalu pilih email <?= e($type) ?> untuk menampilkan progress.</div>
<?php endif; ?>
<?php if ($selected): ?>
<div class="row"><?php foreach (array_merge(['target'=>'Target'], status_fields()) as $field=>$label): ?><div class="col-md"><div class="small-box bg-light"><div class="inner"><h3><?= number_format((int)$cards[$field],0,',','.') ?></h3><p><?= e($label) ?></p></div></div></div><?php endforeach; ?></div>
<div class="card"><div class="card-body"><div class="progress-chart-wrap"><canvas id="lineChart"></canvas></div></div></div>
<script>
const rows = <?= json_encode($trend) ?>;
const fields = <?= json_encode(array_keys(status_fields())) ?>;
const labels = <?= json_encode(array_values(status_fields())) ?>;
const colors = ['#2563eb','#16a34a','#dc2626','#f59e0b','#0f766e'];
new Chart(document.getElementById('lineChart'), {
  type:'line',
  data:{ labels: rows.map(r=>r.tanggal), datasets: fields.map((f,i)=>({ label:labels[i], data:rows.map(r=>Number(r.target)?Math.round(Number(r[f])/Number(r.target)*10000)/100:0), borderColor:colors[i], backgroundColor:colors[i], tension:.2 })) },
  options:{ responsive:true, maintainAspectRatio:false, scales:{ y:{min:0,max:100,ticks:{callback:v=>v+'%'}} } }
});
</script>
<?php endif; ?>
<script>
const kabSelect = document.getElementById('kab_id');
if (kabSelect && !kabSelect.disabled) {
  kabSelect.addEventListener('change', function () {
    document.getElementById('kec_id').value = '';
    document.getElementById('desa_id').value = '';
    document.getElementById('email').value = '';
    this.form.submit();
  });
}
document.getElementById('kec_id').addEventListener('change', function () {
  document.getElementById('desa_id').value = '';
  document.getElementById('email').value = '';
  this.form.submit();
});
document.getElementById('desa_id').addEventListener('change', function () {
  document.getElementById('email').value = '';
  this.form.submit();
});
</script>
<?php render_footer(); ?>
