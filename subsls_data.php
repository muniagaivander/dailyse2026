<?php
require __DIR__ . '/layout.php';
$user = require_role(['pengawas', 'pencacah']);
$fields = status_fields();
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 100;
$roleColumn = $user['role'] === 'pencacah' ? 'ms.pencacah_email' : 'ms.pengawas_email';

$countStmt = db()->prepare("SELECT COUNT(*)
    FROM master_subsls ms
    WHERE $roleColumn=?");
$countStmt->execute([$user['email']]);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$stmt = db()->prepare("SELECT ms.id subsls_id, ms.kdsubsls, ms.nmsubsls, sl.kdsls, sl.nmsls,
        d.kddesa, d.nmdesa, kc.kdkec, kc.nmkec, k.id kab_id, k.nmkab,
        ms.pengawas_email, ms.pencacah_email,
        COALESCE(ss.target,0) target,
        COALESCE(ss.open_count,0) open_count,
        COALESCE(ss.submitted_by_pencacah,0) submitted_by_pencacah,
        COALESCE(ss.rejected_by_pengawas,0) rejected_by_pengawas,
        COALESCE(ss.draft_count,0) draft_count,
        COALESCE(ss.pending_count,0) pending_count,
        COALESCE(ss.approved_by_pengawas,0) approved_by_pengawas,
        ss.last_update, ss.updated_by
    FROM master_subsls ms
    JOIN master_sls sl ON sl.id=ms.sls_id
    JOIN master_desa d ON d.id=sl.desa_id
    JOIN master_kec kc ON kc.id=d.kec_id
    JOIN master_kab k ON k.id=kc.kab_id
    LEFT JOIN subsls_status ss ON ss.subsls_id=ms.id
    WHERE $roleColumn=?
    ORDER BY ms.id
    LIMIT $perPage OFFSET $offset");
$stmt->execute([$user['email']]);
$rows = $stmt->fetchAll();

render_header('Data SubSLS');
?>
<div class="card">
  <div class="card-header py-2">
    <span>Menampilkan <?= number_format(count($rows), 0, ',', '.') ?> dari <?= number_format($totalRows, 0, ',', '.') ?> SubSLS</span>
  </div>
  <div class="card-body table-responsive p-0">
    <table class="table table-sm table-bordered table-striped mb-0">
      <thead>
        <tr>
          <th>idsubsls</th>
          <th>Kabupaten</th>
          <th>Kecamatan</th>
          <th>Desa</th>
          <th>SLS</th>
          <th>SubSLS</th>
          <th>Pengawas</th>
          <th>Pencacah</th>
          <th>Target</th>
          <?php foreach ($fields as $label): ?><th><?= e($label) ?></th><?php endforeach; ?>
          <th>Last Update</th>
          <th>Updated By</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= e($r['subsls_id']) ?></td>
          <td><?= e($r['kab_id'] . ' - ' . $r['nmkab']) ?></td>
          <td><?= e($r['kdkec'] . ' - ' . $r['nmkec']) ?></td>
          <td><?= e($r['kddesa'] . ' - ' . $r['nmdesa']) ?></td>
          <td><?= e($r['kdsls'] . ' - ' . $r['nmsls']) ?></td>
          <td><?= e($r['kdsubsls'] . ' - ' . $r['nmsubsls']) ?></td>
          <td><?= e($r['pengawas_email']) ?></td>
          <td><?= e($r['pencacah_email']) ?></td>
          <td><?= number_format((int)$r['target'], 0, ',', '.') ?></td>
          <?php foreach (array_keys($fields) as $field): ?><td><?= number_format((int)$r[$field], 0, ',', '.') ?></td><?php endforeach; ?>
          <td><?= e($r['last_update'] ?: '-') ?></td>
          <td><?= e($r['updated_by'] ?: '-') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php if ($totalPages > 1): ?>
  <nav class="mt-3">
    <ul class="pagination pagination-sm">
      <?php
        $start = max(1, $page - 2);
        $end = min($totalPages, $page + 2);
      ?>
      <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="?page=<?= max(1, $page - 1) ?>">Prev</a></li>
      <?php for ($p = $start; $p <= $end; $p++): ?>
        <li class="page-item <?= $p === $page ? 'active' : '' ?>"><a class="page-link" href="?page=<?= $p ?>"><?= $p ?></a></li>
      <?php endfor; ?>
      <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>"><a class="page-link" href="?page=<?= min($totalPages, $page + 1) ?>">Next</a></li>
    </ul>
  </nav>
<?php endif; ?>
<?php render_footer(); ?>
