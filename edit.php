<?php
require __DIR__ . '/layout.php';
$user = require_role(['pengawas']);

function save_edit_daily($date, $subslsId, $user, $post, $i): void {
    $open = (int)($post['open_count'][$i] ?? 0);
    $draft = (int)($post['draft_count'][$i] ?? 0);
    $submitted = (int)($post['submitted_by_pencacah'][$i] ?? 0);
    $approved = (int)($post['approved_by_pengawas'][$i] ?? 0);
    $rejected = (int)($post['rejected_by_pengawas'][$i] ?? 0);
    $target = $open + $draft + $submitted + $approved + $rejected;
    $stmt = db()->prepare("SELECT ms.*, k.kab_id FROM master_subsls ms JOIN master_sls sl ON sl.id=ms.sls_id JOIN master_desa d ON d.id=sl.desa_id JOIN master_kec k ON k.id=d.kec_id WHERE ms.id=? AND ms.pengawas_email=?");
    $stmt->execute([$subslsId, $user['email']]);
    $m = $stmt->fetch();
    if (!$m) return;
    db()->prepare("INSERT INTO daily_status (tanggal,subsls_id,kab_id,pengawas_email,pencacah_email,target,open_count,draft_count,submitted_by_pencacah,approved_by_pengawas,rejected_by_pengawas,submitted_at,updated_by)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE target=VALUES(target),open_count=VALUES(open_count),draft_count=VALUES(draft_count),submitted_by_pencacah=VALUES(submitted_by_pencacah),approved_by_pengawas=VALUES(approved_by_pengawas),rejected_by_pengawas=VALUES(rejected_by_pengawas),updated_by=VALUES(updated_by)")
        ->execute([$date,$subslsId,$m['kab_id'],$user['email'],$m['pencacah_email'],$target,$open,$draft,$submitted,$approved,$rejected,date('Y-m-d H:i:s'),$user['email']]);
    db()->prepare("REPLACE INTO subsls_status (subsls_id,open_count,draft_count,submitted_by_pencacah,approved_by_pengawas,rejected_by_pengawas,target,last_update,updated_by)
        VALUES (?,?,?,?,?,?,?,?,?)")
        ->execute([$subslsId,$open,$draft,$submitted,$approved,$rejected,$target,date('Y-m-d H:i:s'),$user['email']]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $date = $_POST['tanggal'] ?? '';
    $ids = $_POST['subsls_id'] ?? [];
    db()->beginTransaction();
    try {
        foreach ($ids as $i => $id) save_edit_daily($date, $id, $user, $_POST, $i);
        db()->commit();
        flash('success', 'Data tanggal ini berhasil diedit.');
    } catch (Throwable $e) {
        db()->rollBack();
        flash('error', $e->getMessage());
    }
    redirect('edit.php?tanggal=' . urlencode($date));
}

$datesStmt = db()->prepare("SELECT DISTINCT tanggal FROM daily_status WHERE pengawas_email=? ORDER BY tanggal DESC");
$datesStmt->execute([$user['email']]);
$dates = $datesStmt->fetchAll();
$date = $_GET['tanggal'] ?? ($dates[0]['tanggal'] ?? '');
$rows = [];
if ($date) {
    $stmt = db()->prepare("SELECT ds.*, ms.kdsubsls, ms.nmsubsls, sl.nmsls, d.nmdesa
        FROM daily_status ds
        JOIN master_subsls ms ON ms.id=ds.subsls_id
        JOIN master_sls sl ON sl.id=ms.sls_id
        JOIN master_desa d ON d.id=sl.desa_id
        WHERE ds.pengawas_email=? AND ds.tanggal=?
        ORDER BY ds.pencacah_email, d.nmdesa, sl.nmsls, ms.kdsubsls");
    $stmt->execute([$user['email'], $date]);
    $rows = $stmt->fetchAll();
}

render_header('Edit Harian');
?>
<?php if ($msg = flash('success')): ?><div class="alert alert-success"><?= e($msg) ?></div><?php endif; ?>
<?php if ($msg = flash('error')): ?><div class="alert alert-danger"><?= e($msg) ?></div><?php endif; ?>
<form class="card card-body mb-3" method="get">
  <div class="form-row align-items-end">
    <div class="form-group col-md-3"><label>Tanggal yang pernah diinput</label><select class="form-control" name="tanggal"><?php foreach ($dates as $d): ?><option value="<?= e($d['tanggal']) ?>" <?= $date===$d['tanggal']?'selected':'' ?>><?= e($d['tanggal']) ?></option><?php endforeach; ?></select></div>
    <div class="form-group col-md-3"><button class="btn btn-primary">Tampilkan Form Edit</button></div>
  </div>
</form>
<?php if ($rows): ?>
<form method="post">
  <input type="hidden" name="tanggal" value="<?= e($date) ?>">
  <div class="card"><div class="card-body table-responsive p-0">
    <table class="table table-sm table-bordered">
      <thead><tr><th>Desa</th><th>SLS</th><th>kdsubsls</th><th>Pencacah</th><th>Target</th><?php foreach (status_fields() as $label): ?><th><?= e($label) ?></th><?php endforeach; ?></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= e($r['nmdesa']) ?></td><td><?= e($r['nmsls']) ?></td><td><?= e($r['kdsubsls']) ?></td><td><?= e($r['pencacah_email']) ?></td>
          <td><input class="form-control form-control-sm target" disabled value="<?= e($r['target']) ?>"></td>
          <input type="hidden" name="subsls_id[]" value="<?= e($r['subsls_id']) ?>">
          <?php foreach (array_keys(status_fields()) as $f): ?>
            <td><input class="form-control form-control-sm status-input" type="number" min="0" name="<?= $f ?>[]" value="<?= e($r[$f]) ?>"></td>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div></div>
  <button class="btn btn-success mb-4">Edit Data Tanggal Ini</button>
</form>
<script>
document.querySelectorAll('.status-input').forEach(input => input.addEventListener('input', () => {
  const tr = input.closest('tr');
  tr.querySelector('.target').value = Array.from(tr.querySelectorAll('.status-input')).reduce((s, el) => s + Number(el.value || 0), 0);
}));
</script>
<?php endif; ?>
<?php render_footer(); ?>
