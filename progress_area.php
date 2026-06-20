<?php
require __DIR__ . '/layout.php';

$user = require_role(['admin_kab','superadmin','viewer_prov','viewer_kab']);
$monthOptions = [
    '06' => 'Juni',
    '07' => 'Juli',
    '08' => 'Agustus',
    '09' => 'September',
];
$filters = [
    'month' => $_GET['month'] ?? '',
    'kab_id' => $_GET['kab_id'] ?? '',
    'kec_id' => $_GET['kec_id'] ?? '',
    'desa_id' => $_GET['desa_id'] ?? '',
    'subsls_id' => $_GET['subsls_id'] ?? '',
];
if (!array_key_exists($filters['month'], $monthOptions)) {
    $filters['month'] = '';
}
if (in_array($user['role'], ['admin_kab', 'viewer_kab'], true)) {
    $filters['kab_id'] = $user['kab_id'];
}

function progress_area_kabupaten_options(array $user): array
{
    if (in_array($user['role'], ['admin_kab', 'viewer_kab'], true)) {
        $stmt = db()->prepare("SELECT id value, CONCAT(id,' - ',nmkab) label FROM master_kab WHERE id=? ORDER BY id");
        $stmt->execute([$user['kab_id']]);
        return $stmt->fetchAll();
    }
    return db()->query("SELECT id value, CONCAT(id,' - ',nmkab) label FROM master_kab ORDER BY id")->fetchAll();
}

function progress_area_filter_options(array $user, array $filters): array
{
    $out = ['kabupaten' => progress_area_kabupaten_options($user), 'kecamatan' => [], 'desa' => [], 'subsls' => []];
    if (empty($filters['kab_id'])) {
        return $out;
    }

    $stmt = db()->prepare("SELECT id value, CONCAT(kdkec,' - ',nmkec) label FROM master_kec WHERE kab_id=? ORDER BY kdkec, nmkec");
    $stmt->execute([$filters['kab_id']]);
    $out['kecamatan'] = $stmt->fetchAll();

    if (!empty($filters['kec_id'])) {
        $stmt = db()->prepare("SELECT id value, CONCAT(kddesa,' - ',nmdesa) label FROM master_desa WHERE kec_id=? ORDER BY kddesa, nmdesa");
        $stmt->execute([$filters['kec_id']]);
        $out['desa'] = $stmt->fetchAll();
    }

    if (!empty($filters['desa_id'])) {
        $stmt = db()->prepare("SELECT DISTINCT ms.id value, CONCAT(sl.kdsls, ms.kdsubsls, ' - ', ms.nmsubsls) label,
                sl.kdsls sort_sls, ms.kdsubsls sort_subsls
            FROM master_subsls ms
            JOIN master_sls sl ON sl.id=ms.sls_id
            WHERE sl.desa_id=?
            ORDER BY sort_sls, sort_subsls, value");
        $stmt->execute([$filters['desa_id']]);
        $out['subsls'] = $stmt->fetchAll();
    }

    return $out;
}

function progress_area_where(array $user, array $filters): array
{
    $where = [];
    $params = [];
    if (in_array($user['role'], ['admin_kab', 'viewer_kab'], true)) {
        $where[] = 'k.id=?';
        $params[] = $user['kab_id'];
    } elseif (!empty($filters['kab_id'])) {
        $where[] = 'k.id=?';
        $params[] = $filters['kab_id'];
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

function progress_area_current_cards(array $user, array $filters): array
{
    $where = [];
    $params = [];
    if (in_array($user['role'], ['admin_kab', 'viewer_kab'], true)) {
        $where[] = 'k.id=?';
        $params[] = $user['kab_id'];
    } elseif (!empty($filters['kab_id'])) {
        $where[] = 'k.id=?';
        $params[] = $filters['kab_id'];
    }
    foreach (['kec_id' => 'kc.id', 'desa_id' => 'd.id', 'subsls_id' => 'ms.id'] as $key => $col) {
        if (!empty($filters[$key])) {
            $where[] = "{$col}=?";
            $params[] = $filters[$key];
        }
    }
    $sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $stmt = db()->prepare("SELECT COALESCE(SUM(ss.target),0) target,
            COALESCE(SUM(ss.open_count),0) open_count,
            COALESCE(SUM(ss.draft_count),0) draft_count,
            COALESCE(SUM(ss.submitted_by_pencacah),0) submitted_by_pencacah,
            COALESCE(SUM(ss.approved_by_pengawas),0) approved_by_pengawas,
            COALESCE(SUM(ss.rejected_by_pengawas),0) rejected_by_pengawas
        FROM master_subsls ms
        JOIN master_sls sl ON sl.id=ms.sls_id
        JOIN master_desa d ON d.id=sl.desa_id
        JOIN master_kec kc ON kc.id=d.kec_id
        JOIN master_kab k ON k.id=kc.kab_id
        LEFT JOIN subsls_status ss ON ss.subsls_id=ms.id
        {$sqlWhere}");
    $stmt->execute($params);
    $row = $stmt->fetch() ?: [];
    $cards = array_fill_keys(array_merge(['target'], array_keys(status_fields())), 0);
    foreach ($cards as $key => $_) {
        $cards[$key] = (int)($row[$key] ?? 0);
    }
    return $cards;
}

$options = progress_area_filter_options($user, $filters);
if ($filters['kec_id'] && !in_array($filters['kec_id'], array_column($options['kecamatan'], 'value'), true)) {
    $filters['kec_id'] = '';
    $filters['desa_id'] = '';
    $filters['subsls_id'] = '';
}
if ($filters['desa_id'] && !in_array($filters['desa_id'], array_column($options['desa'], 'value'), true)) {
    $filters['desa_id'] = '';
    $filters['subsls_id'] = '';
}
if ($filters['subsls_id'] && !in_array($filters['subsls_id'], array_column($options['subsls'], 'value'), true)) {
    $filters['subsls_id'] = '';
}

$showProgress = ($_GET['action'] ?? '') === 'filter';
$trend = [];
$cards = array_fill_keys(array_merge(['target'], array_keys(status_fields())), 0);
if ($showProgress) {
    [$where, $params] = progress_area_where($user, $filters);
    $sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $stmt = db()->prepare("SELECT ds.tanggal, SUM(ds.target) target, SUM(ds.open_count) open_count, SUM(ds.draft_count) draft_count,
            SUM(ds.submitted_by_pencacah) submitted_by_pencacah, SUM(ds.approved_by_pengawas) approved_by_pengawas,
            SUM(ds.rejected_by_pengawas) rejected_by_pengawas
        FROM daily_status ds
        JOIN master_subsls ms ON ms.id=ds.subsls_id
        JOIN master_sls sl ON sl.id=ms.sls_id
        JOIN master_desa d ON d.id=sl.desa_id
        JOIN master_kec kc ON kc.id=d.kec_id
        JOIN master_kab k ON k.id=kc.kab_id
        {$sqlWhere}
        GROUP BY ds.tanggal
        ORDER BY ds.tanggal");
    $stmt->execute($params);
    $trend = $stmt->fetchAll();
    $cards = progress_area_current_cards($user, $filters);
}

render_header('Progress By Daerah');
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
<form class="card card-body mb-3" method="get" id="areaProgressFilterForm">
  <input type="hidden" name="action" id="areaProgressAction" value="">
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
          <option value="">Semua Kabupaten</option>
          <?php foreach ($options['kabupaten'] as $o): ?><option value="<?= e($o['value']) ?>" <?= $filters['kab_id']===$o['value']?'selected':'' ?>><?= e($o['label']) ?></option><?php endforeach; ?>
        </select>
      </div>
    <?php else: ?>
      <input type="hidden" name="kab_id" value="<?= e($filters['kab_id']) ?>">
    <?php endif; ?>

    <div class="form-group col-md-2">
      <label>Kecamatan</label>
      <select class="form-control" name="kec_id" id="kec_id" <?= $filters['kab_id'] ? '' : 'disabled' ?>>
        <option value=""><?= $filters['kab_id'] ? 'Semua Kecamatan' : 'Pilih kabupaten dulu' ?></option>
        <?php foreach ($options['kecamatan'] as $o): ?><option value="<?= e($o['value']) ?>" <?= $filters['kec_id']===$o['value']?'selected':'' ?>><?= e($o['label']) ?></option><?php endforeach; ?>
      </select>
    </div>

    <div class="form-group col-md-2">
      <label>Desa</label>
      <select class="form-control" name="desa_id" id="desa_id" <?= $filters['kec_id'] ? '' : 'disabled' ?>>
        <option value=""><?= $filters['kec_id'] ? 'Semua Desa' : 'Pilih kecamatan dulu' ?></option>
        <?php foreach ($options['desa'] as $o): ?><option value="<?= e($o['value']) ?>" <?= $filters['desa_id']===$o['value']?'selected':'' ?>><?= e($o['label']) ?></option><?php endforeach; ?>
      </select>
    </div>

    <div class="form-group col-md-2">
      <label>SubSLS</label>
      <select class="form-control" name="subsls_id" id="subsls_id" <?= $filters['desa_id'] ? '' : 'disabled' ?>>
        <option value=""><?= $filters['desa_id'] ? 'Semua SubSLS' : 'Pilih desa dulu' ?></option>
        <?php foreach ($options['subsls'] as $o): ?><option value="<?= e($o['value']) ?>" <?= $filters['subsls_id']===$o['value']?'selected':'' ?>><?= e($o['label']) ?></option><?php endforeach; ?>
      </select>
    </div>
  </div>
  <button class="btn btn-primary" type="submit" id="areaProgressFilterButton">Filter</button>
</form>

<?php if (!$showProgress): ?>
  <div class="alert alert-info">Atur filter wilayah, lalu klik tombol Filter untuk menampilkan progress.</div>
<?php else: ?>
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
const monthSelect = document.getElementById('month');
const kabSelect = document.getElementById('kab_id');
const kecSelect = document.getElementById('kec_id');
const desaSelect = document.getElementById('desa_id');
const subslsSelect = document.getElementById('subsls_id');
const areaProgressForm = document.getElementById('areaProgressFilterForm');
const areaProgressAction = document.getElementById('areaProgressAction');
const areaProgressFilterButton = document.getElementById('areaProgressFilterButton');

function reloadAreaOptions() {
  areaProgressAction.value = 'options';
  areaProgressForm.submit();
}

areaProgressFilterButton.addEventListener('click', function () {
  areaProgressAction.value = 'filter';
});

monthSelect.addEventListener('change', function () {
  if (kabSelect) kabSelect.value = '';
  kecSelect.value = '';
  desaSelect.value = '';
  subslsSelect.value = '';
  if (kabSelect) {
    kecSelect.disabled = true;
    kecSelect.options[0].textContent = 'Pilih kabupaten dulu';
  }
  desaSelect.disabled = true;
  desaSelect.options[0].textContent = 'Pilih kecamatan dulu';
  subslsSelect.disabled = true;
  subslsSelect.options[0].textContent = 'Pilih desa dulu';
  reloadAreaOptions();
});

if (kabSelect) {
  kabSelect.addEventListener('change', function () {
    kecSelect.value = '';
    desaSelect.value = '';
    subslsSelect.value = '';
    kecSelect.disabled = !this.value;
    kecSelect.options[0].textContent = this.value ? 'Semua Kecamatan' : 'Pilih kabupaten dulu';
    desaSelect.disabled = true;
    desaSelect.options[0].textContent = 'Pilih kecamatan dulu';
    subslsSelect.disabled = true;
    subslsSelect.options[0].textContent = 'Pilih desa dulu';
    reloadAreaOptions();
  });
}
kecSelect.addEventListener('change', function () {
  desaSelect.value = '';
  subslsSelect.value = '';
  desaSelect.disabled = !this.value;
  desaSelect.options[0].textContent = this.value ? 'Semua Desa' : 'Pilih kecamatan dulu';
  subslsSelect.disabled = true;
  subslsSelect.options[0].textContent = 'Pilih desa dulu';
  reloadAreaOptions();
});
desaSelect.addEventListener('change', function () {
  subslsSelect.value = '';
  subslsSelect.disabled = !this.value;
  subslsSelect.options[0].textContent = this.value ? 'Semua SubSLS' : 'Pilih desa dulu';
  reloadAreaOptions();
});
</script>
<?php render_footer(); ?>
