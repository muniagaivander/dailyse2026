<?php
require __DIR__ . '/layout.php';
$user = require_role(['superadmin', 'admin_kab', 'pengawas']);

function edit_filters_from_request(): array
{
    return [
        'kab_id' => $_GET['kab_id'] ?? ($_POST['kab_id'] ?? ''),
        'kec_id' => $_GET['kec_id'] ?? ($_POST['kec_id'] ?? ''),
        'desa_id' => $_GET['desa_id'] ?? ($_POST['desa_id'] ?? ''),
        'pengawas_email' => normalize_email($_GET['pengawas_email'] ?? ($_POST['pengawas_email'] ?? '')),
        'pencacah_email' => normalize_email($_GET['pencacah_email'] ?? ($_POST['pencacah_email'] ?? '')),
    ];
}

function edit_filter_where(array $user, array $filters, string $alias = 'ds'): array
{
    $where = [];
    $params = [];
    if ($user['role'] === 'admin_kab') {
        $where[] = 'k.id=?';
        $params[] = $user['kab_id'];
    } elseif ($user['role'] === 'pengawas') {
        $where[] = $alias . '.pengawas_email=?';
        $params[] = $user['email'];
    } elseif ($filters['kab_id']) {
        $where[] = 'k.id=?';
        $params[] = $filters['kab_id'];
    }
    if ($filters['kec_id']) {
        $where[] = 'kc.id=?';
        $params[] = $filters['kec_id'];
    }
    if ($filters['desa_id']) {
        $where[] = 'd.id=?';
        $params[] = $filters['desa_id'];
    }
    if ($filters['pengawas_email']) {
        $where[] = $alias . '.pengawas_email=?';
        $params[] = $filters['pengawas_email'];
    }
    if ($filters['pencacah_email']) {
        $where[] = $alias . '.pencacah_email=?';
        $params[] = $filters['pencacah_email'];
    }
    return [$where, $params];
}

function edit_options_where(array $user, array $filters, array $keys): array
{
    $where = [];
    $params = [];
    if ($user['role'] === 'admin_kab') {
        $where[] = 'k.id=?';
        $params[] = $user['kab_id'];
    } elseif ($user['role'] === 'pengawas') {
        $where[] = 'ms.pengawas_email=?';
        $params[] = $user['email'];
    }
    $map = [
        'kab_id' => 'k.id',
        'kec_id' => 'kc.id',
        'desa_id' => 'd.id',
        'pengawas_email' => 'ms.pengawas_email',
    ];
    foreach ($keys as $key) {
        if (!empty($filters[$key]) && isset($map[$key])) {
            $where[] = $map[$key] . '=?';
            $params[] = $filters[$key];
        }
    }
    return [$where ? 'WHERE ' . implode(' AND ', $where) : '', $params];
}

function edit_area_options(array $user, array $filters): array
{
    $out = ['kabupaten' => [], 'kecamatan' => [], 'desa' => [], 'pengawas' => [], 'pencacah' => []];
    if ($user['role'] === 'superadmin') {
        $out['kabupaten'] = db()->query("SELECT id value, CONCAT(id,' - ',nmkab) label FROM master_kab ORDER BY id")->fetchAll();
    }

    [$whereKec, $paramsKec] = edit_options_where($user, $filters, ['kab_id']);
    $stmt = db()->prepare("SELECT DISTINCT kc.id value, CONCAT(kc.kdkec,' - ',kc.nmkec) label
        FROM master_subsls ms
        JOIN master_sls sl ON sl.id=ms.sls_id
        JOIN master_desa d ON d.id=sl.desa_id
        JOIN master_kec kc ON kc.id=d.kec_id
        JOIN master_kab k ON k.id=kc.kab_id
        $whereKec
        ORDER BY kc.kdkec, kc.nmkec");
    $stmt->execute($paramsKec);
    $out['kecamatan'] = $stmt->fetchAll();

    [$whereDesa, $paramsDesa] = edit_options_where($user, $filters, ['kab_id', 'kec_id']);
    $stmt = db()->prepare("SELECT DISTINCT d.id value, CONCAT(d.kddesa,' - ',d.nmdesa) label
        FROM master_subsls ms
        JOIN master_sls sl ON sl.id=ms.sls_id
        JOIN master_desa d ON d.id=sl.desa_id
        JOIN master_kec kc ON kc.id=d.kec_id
        JOIN master_kab k ON k.id=kc.kab_id
        $whereDesa
        ORDER BY d.kddesa, d.nmdesa");
    $stmt->execute($paramsDesa);
    $out['desa'] = $stmt->fetchAll();

    if ($user['role'] !== 'pengawas') {
        [$wherePengawas, $paramsPengawas] = edit_options_where($user, $filters, ['kab_id', 'kec_id', 'desa_id']);
        $wherePengawas .= $wherePengawas ? " AND ms.pengawas_email IS NOT NULL AND ms.pengawas_email <> ''" : "WHERE ms.pengawas_email IS NOT NULL AND ms.pengawas_email <> ''";
        $stmt = db()->prepare("SELECT DISTINCT ms.pengawas_email value, ms.pengawas_email label
            FROM master_subsls ms
            JOIN master_sls sl ON sl.id=ms.sls_id
            JOIN master_desa d ON d.id=sl.desa_id
            JOIN master_kec kc ON kc.id=d.kec_id
            JOIN master_kab k ON k.id=kc.kab_id
            $wherePengawas
            ORDER BY ms.pengawas_email");
        $stmt->execute($paramsPengawas);
        $out['pengawas'] = $stmt->fetchAll();
    }

    [$wherePencacah, $paramsPencacah] = edit_options_where($user, $filters, ['kab_id', 'kec_id', 'desa_id', 'pengawas_email']);
    $wherePencacah .= $wherePencacah ? " AND ms.pencacah_email IS NOT NULL AND ms.pencacah_email <> ''" : "WHERE ms.pencacah_email IS NOT NULL AND ms.pencacah_email <> ''";
    $stmt = db()->prepare("SELECT DISTINCT ms.pencacah_email value, ms.pencacah_email label
        FROM master_subsls ms
        JOIN master_sls sl ON sl.id=ms.sls_id
        JOIN master_desa d ON d.id=sl.desa_id
        JOIN master_kec kc ON kc.id=d.kec_id
        JOIN master_kab k ON k.id=kc.kab_id
        $wherePencacah
        ORDER BY ms.pencacah_email");
    $stmt->execute($paramsPencacah);
    $out['pencacah'] = $stmt->fetchAll();

    return $out;
}

function edit_daily_dates(array $user, array $filters): array
{
    [$where, $params] = edit_filter_where($user, $filters);
    $sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $stmt = db()->prepare("SELECT DISTINCT ds.tanggal
        FROM daily_status ds
        JOIN master_subsls ms ON ms.id=ds.subsls_id
        JOIN master_sls sl ON sl.id=ms.sls_id
        JOIN master_desa d ON d.id=sl.desa_id
        JOIN master_kec kc ON kc.id=d.kec_id
        JOIN master_kab k ON k.id=kc.kab_id
        $sqlWhere
        ORDER BY ds.tanggal DESC");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function edit_refresh_subsls_status(string $subslsId): void
{
    $stmt = db()->prepare("SELECT open_count,draft_count,submitted_by_pencacah,approved_by_pengawas,rejected_by_pengawas,target,updated_at,updated_by
        FROM daily_status
        WHERE subsls_id=?
        ORDER BY tanggal DESC, updated_at DESC, id DESC
        LIMIT 1");
    $stmt->execute([$subslsId]);
    $latest = $stmt->fetch();
    if (!$latest) {
        return;
    }
    db()->prepare("REPLACE INTO subsls_status (subsls_id,open_count,draft_count,submitted_by_pencacah,approved_by_pengawas,rejected_by_pengawas,target,last_update,updated_by)
        VALUES (?,?,?,?,?,?,?,?,?)")
        ->execute([
            $subslsId,
            $latest['open_count'],
            $latest['draft_count'],
            $latest['submitted_by_pencacah'],
            $latest['approved_by_pengawas'],
            $latest['rejected_by_pengawas'],
            $latest['target'],
            $latest['updated_at'],
            $latest['updated_by'],
        ]);
}

function edit_user_can_touch_row(array $user, string $date, string $subslsId): bool
{
    $where = ['ds.tanggal=?', 'ds.subsls_id=?'];
    $params = [$date, $subslsId];
    if ($user['role'] === 'admin_kab') {
        $where[] = 'k.id=?';
        $params[] = $user['kab_id'];
    } elseif ($user['role'] === 'pengawas') {
        $where[] = 'ds.pengawas_email=?';
        $params[] = $user['email'];
    }
    $stmt = db()->prepare("SELECT COUNT(*)
        FROM daily_status ds
        JOIN master_subsls ms ON ms.id=ds.subsls_id
        JOIN master_sls sl ON sl.id=ms.sls_id
        JOIN master_desa d ON d.id=sl.desa_id
        JOIN master_kec kc ON kc.id=d.kec_id
        JOIN master_kab k ON k.id=kc.kab_id
        WHERE " . implode(' AND ', $where));
    $stmt->execute($params);
    return (int)$stmt->fetchColumn() > 0;
}

function save_edit_daily(string $date, string $subslsId, array $user, array $post, int $i): void
{
    if (!edit_user_can_touch_row($user, $date, $subslsId)) {
        return;
    }
    $open = max(0, (int)($post['open_count'][$i] ?? 0));
    $draft = max(0, (int)($post['draft_count'][$i] ?? 0));
    $submitted = max(0, (int)($post['submitted_by_pencacah'][$i] ?? 0));
    $approved = max(0, (int)($post['approved_by_pengawas'][$i] ?? 0));
    $rejected = max(0, (int)($post['rejected_by_pengawas'][$i] ?? 0));
    $target = $open + $draft + $submitted + $approved + $rejected;

    db()->prepare("UPDATE daily_status
        SET target=?, open_count=?, draft_count=?, submitted_by_pencacah=?, approved_by_pengawas=?, rejected_by_pengawas=?, updated_by=?
        WHERE tanggal=? AND subsls_id=?")
        ->execute([$target, $open, $draft, $submitted, $approved, $rejected, $user['email'], $date, $subslsId]);
    edit_refresh_subsls_status($subslsId);
}

$filters = edit_filters_from_request();
if ($user['role'] === 'admin_kab') {
    $filters['kab_id'] = $user['kab_id'];
}
if ($user['role'] === 'pengawas') {
    $filters['pengawas_email'] = $user['email'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $date = $_POST['tanggal'] ?? '';
    $ids = $_POST['subsls_id'] ?? [];
    $redirectQuery = ['filter' => 1, 'tanggal' => $date] + $filters;
    db()->beginTransaction();
    try {
        foreach ($ids as $i => $id) {
            save_edit_daily($date, (string)$id, $user, $_POST, $i);
        }
        db()->commit();
        flash('success', 'Data tanggal ini berhasil diedit.');
    } catch (Throwable $e) {
        db()->rollBack();
        flash('error', $e->getMessage());
    }
    redirect('edit.php?' . http_build_query($redirectQuery));
}

$opts = edit_area_options($user, $filters);
$dates = isset($_GET['filter']) ? edit_daily_dates($user, $filters) : [];
$date = $_GET['tanggal'] ?? ($dates[0]['tanggal'] ?? '');
$rows = [];
$groups = [];
if (isset($_GET['filter']) && $date) {
    [$where, $params] = edit_filter_where($user, $filters);
    $where[] = 'ds.tanggal=?';
    $params[] = $date;
    $stmt = db()->prepare("SELECT ds.*, ms.kdsubsls, ms.nmsubsls, sl.kdsls, sl.nmsls, d.nmdesa
        FROM daily_status ds
        JOIN master_subsls ms ON ms.id=ds.subsls_id
        JOIN master_sls sl ON sl.id=ms.sls_id
        JOIN master_desa d ON d.id=sl.desa_id
        JOIN master_kec kc ON kc.id=d.kec_id
        JOIN master_kab k ON k.id=kc.kab_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY ds.pencacah_email, d.nmdesa, sl.kdsls, ms.kdsubsls");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    foreach ($rows as $row) {
        $groups[$row['pencacah_email'] ?: 'Tanpa Pencacah'][] = $row;
    }
}

render_header('Edit Harian');
?>
<?php if ($msg = flash('success')): ?><div class="alert alert-success"><?= e($msg) ?></div><?php endif; ?>
<?php if ($msg = flash('error')): ?><div class="alert alert-danger"><?= e($msg) ?></div><?php endif; ?>

<form class="card card-body mb-3" method="get">
  <input type="hidden" name="filter" value="1">
  <div class="form-row align-items-end">
    <?php if ($user['role'] === 'superadmin'): ?>
      <div class="form-group col-md-2">
        <label>Kabupaten</label>
        <select class="form-control" name="kab_id" id="kab_id">
          <option value="">Semua Kabupaten</option>
          <?php foreach ($opts['kabupaten'] as $o): ?><option value="<?= e($o['value']) ?>" <?= $filters['kab_id']===$o['value']?'selected':'' ?>><?= e($o['label']) ?></option><?php endforeach; ?>
        </select>
      </div>
    <?php else: ?>
      <input type="hidden" name="kab_id" value="<?= e($filters['kab_id']) ?>">
    <?php endif; ?>
    <div class="form-group col-md-2">
      <label>Kecamatan</label>
      <select class="form-control" name="kec_id" id="kec_id">
        <option value="">Semua Kecamatan</option>
        <?php foreach ($opts['kecamatan'] as $o): ?><option value="<?= e($o['value']) ?>" <?= $filters['kec_id']===$o['value']?'selected':'' ?>><?= e($o['label']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="form-group col-md-2">
      <label>Desa</label>
      <select class="form-control" name="desa_id" id="desa_id">
        <option value="">Semua Desa</option>
        <?php foreach ($opts['desa'] as $o): ?><option value="<?= e($o['value']) ?>" <?= $filters['desa_id']===$o['value']?'selected':'' ?>><?= e($o['label']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <?php if ($user['role'] !== 'pengawas'): ?>
      <div class="form-group col-md-2">
        <label>Pengawas</label>
        <select class="form-control" name="pengawas_email" id="pengawas_email">
          <option value="">Semua Pengawas</option>
          <?php foreach ($opts['pengawas'] as $o): ?><option value="<?= e($o['value']) ?>" <?= $filters['pengawas_email']===$o['value']?'selected':'' ?>><?= e($o['label']) ?></option><?php endforeach; ?>
        </select>
      </div>
    <?php endif; ?>
    <div class="form-group col-md-2">
      <label>Pencacah</label>
      <select class="form-control" name="pencacah_email" id="pencacah_email">
        <option value="">Semua Pencacah</option>
        <?php foreach ($opts['pencacah'] as $o): ?><option value="<?= e($o['value']) ?>" <?= $filters['pencacah_email']===$o['value']?'selected':'' ?>><?= e($o['label']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="form-group col-md-2">
      <label>Tanggal</label>
      <select class="form-control" name="tanggal" <?= $dates ? '' : 'disabled' ?> required>
        <?php if (!$dates): ?>
          <option value="">Klik filter dulu</option>
        <?php else: ?>
          <?php foreach ($dates as $d): ?><option value="<?= e($d['tanggal']) ?>" <?= $date===$d['tanggal']?'selected':'' ?>><?= e($d['tanggal']) ?></option><?php endforeach; ?>
        <?php endif; ?>
      </select>
    </div>
    <div class="form-group col-md-2"><button class="btn btn-primary">Filter</button></div>
  </div>
</form>

<?php if (isset($_GET['filter']) && !$dates): ?>
  <div class="alert alert-info">Tidak ada tanggal input harian pada filter ini.</div>
<?php endif; ?>

<?php if ($rows): ?>
<style>
.edit-pencacah-tabs .nav-link {
  border: 1px solid #86efac;
  color: #111827;
  margin: 0 6px 6px 0;
}
.edit-pencacah-tabs .nav-link.active {
  background: #dcfce7;
  border-color: #22c55e;
  color: #111827;
}
</style>
<form method="post" data-progress-submit data-progress-title="Menyimpan edit harian..." data-progress-text="Mohon tunggu, perubahan sedang disimpan.">
  <input type="hidden" name="kab_id" value="<?= e($filters['kab_id']) ?>">
  <input type="hidden" name="kec_id" value="<?= e($filters['kec_id']) ?>">
  <input type="hidden" name="desa_id" value="<?= e($filters['desa_id']) ?>">
  <input type="hidden" name="pengawas_email" value="<?= e($filters['pengawas_email']) ?>">
  <input type="hidden" name="pencacah_email" value="<?= e($filters['pencacah_email']) ?>">
  <input type="hidden" name="tanggal" value="<?= e($date) ?>">
  <div class="card">
    <div class="card-header p-2 d-flex justify-content-between align-items-center">
      <ul class="nav nav-pills edit-pencacah-tabs" role="tablist">
        <?php $tabIndex = 0; foreach ($groups as $pencacah => $items): ?>
          <?php $tabId = 'edit-pencacah-' . $tabIndex; ?>
          <li class="nav-item">
            <a class="nav-link <?= $tabIndex === 0 ? 'active' : '' ?>" data-toggle="tab" href="#<?= e($tabId) ?>" role="tab">
              <?= e($pencacah) ?> <span class="badge badge-light ml-1"><?= count($items) ?></span>
            </a>
          </li>
        <?php $tabIndex++; endforeach; ?>
      </ul>
      <span class="text-muted small">Tanggal: <?= e($date) ?></span>
    </div>
    <div class="card-body p-0">
      <div class="tab-content">
        <?php $tabIndex = 0; foreach ($groups as $pencacah => $items): ?>
          <?php $tabId = 'edit-pencacah-' . $tabIndex; ?>
          <div class="tab-pane fade <?= $tabIndex === 0 ? 'show active' : '' ?>" id="<?= e($tabId) ?>" role="tabpanel">
            <div class="table-responsive">
              <table class="table table-sm table-bordered mb-0">
                <thead>
                  <tr>
                    <th>Desa</th><th>SLS</th><th>Kode SubSLS</th><th>SubSLS</th><th>Target</th>
                    <?php foreach (status_fields() as $label): ?><th><?= e($label) ?></th><?php endforeach; ?>
                  </tr>
                </thead>
                <tbody>
                <?php foreach ($items as $r): ?>
                  <tr>
                    <td><?= e($r['nmdesa']) ?></td>
                    <td><?= e($r['kdsls'] . ' - ' . $r['nmsls']) ?></td>
                    <td><?= e($r['kdsls'] . $r['kdsubsls']) ?><input type="hidden" name="subsls_id[]" value="<?= e($r['subsls_id']) ?>"></td>
                    <td><?= e($r['nmsubsls']) ?></td>
                    <td><input class="form-control form-control-sm target" disabled value="<?= e($r['target']) ?>"></td>
                    <?php foreach (array_keys(status_fields()) as $f): ?>
                      <td><input class="form-control form-control-sm status-input" type="number" min="0" name="<?= $f ?>[]" value="<?= e($r[$f]) ?>"></td>
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
  <button class="btn btn-success mb-4 mt-3">Edit Data Tanggal Ini</button>
</form>
<?php elseif (isset($_GET['filter']) && $date): ?>
  <div class="alert alert-info">Tidak ada data harian pada tanggal dan filter ini.</div>
<?php endif; ?>

<script>
document.querySelectorAll('.status-input').forEach(input => input.addEventListener('input', () => {
  const tr = input.closest('tr');
  tr.querySelector('.target').value = Array.from(tr.querySelectorAll('.status-input')).reduce((s, el) => s + Number(el.value || 0), 0);
}));
const kabupaten = document.getElementById('kab_id');
if (kabupaten) {
  kabupaten.addEventListener('change', function () {
    document.getElementById('kec_id').value = '';
    document.getElementById('desa_id').value = '';
    const pengawas = document.getElementById('pengawas_email');
    if (pengawas) pengawas.value = '';
    document.getElementById('pencacah_email').value = '';
    this.form.submit();
  });
}
document.getElementById('kec_id').addEventListener('change', function () {
  document.getElementById('desa_id').value = '';
  const pengawas = document.getElementById('pengawas_email');
  if (pengawas) pengawas.value = '';
  document.getElementById('pencacah_email').value = '';
  this.form.submit();
});
document.getElementById('desa_id').addEventListener('change', function () {
  const pengawas = document.getElementById('pengawas_email');
  if (pengawas) pengawas.value = '';
  document.getElementById('pencacah_email').value = '';
  this.form.submit();
});
const pengawas = document.getElementById('pengawas_email');
if (pengawas) {
  pengawas.addEventListener('change', function () {
    document.getElementById('pencacah_email').value = '';
    this.form.submit();
  });
}
</script>
<?php render_footer(); ?>
