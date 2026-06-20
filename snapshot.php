<?php
require __DIR__ . '/layout.php';
$user = require_role(['superadmin']);

function snapshot_scope_where(array $user, string $kabAlias = 'k'): array
{
    return ['1=1', []];
}

function snapshot_available_dates(array $user): array
{
    [$scope, $params] = snapshot_scope_where($user, 'k');
    $stmt = db()->prepare("SELECT DISTINCT ds.tanggal
        FROM daily_status ds
        JOIN master_subsls ms ON ms.id=ds.subsls_id
        JOIN master_sls sl ON sl.id=ms.sls_id
        JOIN master_desa d ON d.id=sl.desa_id
        JOIN master_kec kc ON kc.id=d.kec_id
        JOIN master_kab k ON k.id=kc.kab_id
        WHERE ds.tanggal <= ? AND {$scope}
        ORDER BY ds.tanggal DESC");
    $stmt->execute(array_merge([today()], $params));
    return $stmt->fetchAll();
}

function snapshot_summary(string $tanggal, array $user): array
{
    [$scope, $params] = snapshot_scope_where($user, 'k');
    $base = "FROM master_subsls ms
        JOIN master_sls sl ON sl.id=ms.sls_id
        JOIN master_desa d ON d.id=sl.desa_id
        JOIN master_kec kc ON kc.id=d.kec_id
        JOIN master_kab k ON k.id=kc.kab_id
        WHERE {$scope}";

    $stmt = db()->prepare("SELECT COUNT(*) {$base}");
    $stmt->execute($params);
    $totalMaster = (int)$stmt->fetchColumn();

    $stmt = db()->prepare("SELECT COUNT(*)
        FROM daily_status ds
        JOIN master_subsls ms ON ms.id=ds.subsls_id
        JOIN master_sls sl ON sl.id=ms.sls_id
        JOIN master_desa d ON d.id=sl.desa_id
        JOIN master_kec kc ON kc.id=d.kec_id
        JOIN master_kab k ON k.id=kc.kab_id
        WHERE ds.tanggal=? AND {$scope}");
    $stmt->execute(array_merge([$tanggal], $params));
    $existing = (int)$stmt->fetchColumn();

    $stmt = db()->prepare("SELECT COUNT(*)
        FROM daily_status ds
        JOIN master_subsls ms ON ms.id=ds.subsls_id
        JOIN master_sls sl ON sl.id=ms.sls_id
        JOIN master_desa d ON d.id=sl.desa_id
        JOIN master_kec kc ON kc.id=d.kec_id
        JOIN master_kab k ON k.id=kc.kab_id
        WHERE ds.tanggal=? AND ds.updated_by LIKE 'system_snapshot:%' AND {$scope}");
    $stmt->execute(array_merge([$tanggal], $params));
    $existingSnapshot = (int)$stmt->fetchColumn();

    $stmt = db()->prepare("SELECT COUNT(*) {$base}
        AND NOT EXISTS (
            SELECT 1 FROM daily_status ds
            WHERE ds.subsls_id=ms.id AND ds.tanggal=?
        )
        AND EXISTS (
            SELECT 1 FROM daily_status prev
            WHERE prev.subsls_id=ms.id AND prev.tanggal<?
        )");
    $stmt->execute(array_merge($params, [$tanggal, $tanggal]));
    $missingWithPrevious = (int)$stmt->fetchColumn();

    $missing = max(0, $totalMaster - $existing);
    $missingWithoutPrevious = max(0, $missing - $missingWithPrevious);

    return [
        'total_master' => $totalMaster,
        'existing' => $existing,
        'existing_snapshot' => $existingSnapshot,
        'missing' => $missing,
        'missing_with_previous' => $missingWithPrevious,
        'missing_without_previous' => $missingWithoutPrevious,
    ];
}

function snapshot_missing_master_rows(string $tanggal, array $user): array
{
    [$scope, $params] = snapshot_scope_where($user, 'k');
    $stmt = db()->prepare("SELECT ms.id subsls_id, k.id kab_id,
            COALESCE(ms.pengawas_email, '') pengawas_email,
            COALESCE(ms.pencacah_email, '') pencacah_email
        FROM master_subsls ms
        JOIN master_sls sl ON sl.id=ms.sls_id
        JOIN master_desa d ON d.id=sl.desa_id
        JOIN master_kec kc ON kc.id=d.kec_id
        JOIN master_kab k ON k.id=kc.kab_id
        WHERE {$scope}
          AND NOT EXISTS (
              SELECT 1 FROM daily_status ds
              WHERE ds.subsls_id=ms.id AND ds.tanggal=?
          )
        ORDER BY ms.id");
    $stmt->execute(array_merge($params, [$tanggal]));
    return $stmt->fetchAll();
}

function snapshot_previous_rows(string $tanggal, array $user): array
{
    [$scope, $params] = snapshot_scope_where($user, 'k');
    $stmt = db()->prepare("SELECT ds.*
        FROM daily_status ds
        JOIN (
            SELECT ds2.subsls_id, MAX(ds2.tanggal) max_tanggal
            FROM daily_status ds2
            JOIN master_subsls ms ON ms.id=ds2.subsls_id
            JOIN master_sls sl ON sl.id=ms.sls_id
            JOIN master_desa d ON d.id=sl.desa_id
            JOIN master_kec kc ON kc.id=d.kec_id
            JOIN master_kab k ON k.id=kc.kab_id
            WHERE ds2.tanggal < ? AND {$scope}
            GROUP BY ds2.subsls_id
        ) latest ON latest.subsls_id=ds.subsls_id AND latest.max_tanggal=ds.tanggal");
    $stmt->execute(array_merge([$tanggal], $params));
    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $rows[$row['subsls_id']] = $row;
    }
    return $rows;
}

function fill_daily_snapshot(string $tanggal, array $user): array
{
    if ($tanggal > today()) {
        throw new RuntimeException('Tanggal snapshot tidak boleh melebihi hari ini.');
    }

    $missingRows = snapshot_missing_master_rows($tanggal, $user);
    if (!$missingRows) {
        return ['inserted' => 0, 'from_previous' => 0, 'zero_filled' => 0];
    }

    $previousRows = snapshot_previous_rows($tanggal, $user);
    $updatedBy = substr('system_snapshot:' . $user['email'], 0, 150);
    $inserted = 0;
    $fromPrevious = 0;
    $zeroFilled = 0;

    db()->beginTransaction();
    try {
        $stmt = db()->prepare("INSERT INTO daily_status
            (tanggal,subsls_id,kab_id,pengawas_email,pencacah_email,target,open_count,draft_count,submitted_by_pencacah,approved_by_pengawas,rejected_by_pengawas,submitted_at,updated_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
        foreach ($missingRows as $master) {
            $prev = $previousRows[$master['subsls_id']] ?? null;
            if ($prev) {
                $target = (int)$prev['target'];
                $open = (int)$prev['open_count'];
                $draft = (int)$prev['draft_count'];
                $submit = (int)$prev['submitted_by_pencacah'];
                $approve = (int)$prev['approved_by_pengawas'];
                $reject = (int)$prev['rejected_by_pengawas'];
                $kabId = $prev['kab_id'] ?: $master['kab_id'];
                $pengawas = $prev['pengawas_email'] ?: $master['pengawas_email'];
                $pencacah = $prev['pencacah_email'] ?: $master['pencacah_email'];
                $fromPrevious++;
            } else {
                $target = $open = $draft = $submit = $approve = $reject = 0;
                $kabId = $master['kab_id'];
                $pengawas = $master['pengawas_email'];
                $pencacah = $master['pencacah_email'];
                $zeroFilled++;
            }
            $stmt->execute([
                $tanggal,
                $master['subsls_id'],
                $kabId,
                $pengawas,
                $pencacah,
                $target,
                $open,
                $draft,
                $submit,
                $approve,
                $reject,
                null,
                $updatedBy,
            ]);
            $inserted++;
        }
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
    }

    return ['inserted' => $inserted, 'from_previous' => $fromPrevious, 'zero_filled' => $zeroFilled];
}

$dates = snapshot_available_dates($user);
$selectedDate = $_GET['tanggal'] ?? '';
$summary = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tanggal = $_POST['tanggal'] ?? '';
    if (!$tanggal || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
        flash('error', 'Pilih tanggal snapshot terlebih dahulu.');
        redirect('snapshot.php');
    }
    try {
        $result = fill_daily_snapshot($tanggal, $user);
        flash('success', "Isi Daily Snapshot tanggal {$tanggal} selesai.\nRow ditambahkan: " . number_format($result['inserted'], 0, ',', '.') . "\nPakai data terakhir sebelum tanggal itu: " . number_format($result['from_previous'], 0, ',', '.') . "\nDiisi nol karena belum ada histori sebelumnya: " . number_format($result['zero_filled'], 0, ',', '.'));
    } catch (Throwable $e) {
        flash('error', 'Gagal mengisi Daily Snapshot: ' . $e->getMessage());
    }
    redirect('snapshot.php?tanggal=' . urlencode($tanggal));
}

if ($selectedDate !== '') {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate) || $selectedDate > today()) {
        flash('error', 'Tanggal tidak valid atau melebihi hari ini.');
        redirect('snapshot.php');
    }
    $summary = snapshot_summary($selectedDate, $user);
}

render_header('Isi Snapshot Tanggal');
?>
<form class="card card-body mb-3" method="get">
  <div class="form-row align-items-end">
    <div class="form-group col-md-4">
      <label>Tanggal Daily Status</label>
      <select class="form-control" name="tanggal" required>
        <option value="">Pilih tanggal</option>
        <?php foreach ($dates as $row): ?>
          <option value="<?= e($row['tanggal']) ?>" <?= $selectedDate === $row['tanggal'] ? 'selected' : '' ?>><?= e($row['tanggal']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group col-md-2">
      <button class="btn btn-primary" type="submit"><i class="fas fa-filter mr-1"></i>Filter</button>
    </div>
  </div>
  <div class="text-muted small">Tanggal yang muncul hanya tanggal hari ini ke bawah dan sudah memiliki isian di daily_status.</div>
</form>

<?php if ($summary): ?>
  <div class="card">
    <div class="card-header"><strong>Ringkasan Tanggal <?= e($selectedDate) ?></strong></div>
    <div class="card-body">
      <div class="row">
        <div class="col-md-2 col-sm-6 mb-2">
          <div class="small-box bg-light mb-0"><div class="inner"><h4><?= number_format($summary['total_master'], 0, ',', '.') ?></h4><p>Total Master SubSLS</p></div></div>
        </div>
        <div class="col-md-2 col-sm-6 mb-2">
          <div class="small-box bg-light mb-0"><div class="inner"><h4><?= number_format($summary['existing'], 0, ',', '.') ?></h4><p>Sudah Ada di Daily</p></div></div>
        </div>
        <div class="col-md-2 col-sm-6 mb-2">
          <div class="small-box bg-light mb-0"><div class="inner"><h4><?= number_format($summary['missing'], 0, ',', '.') ?></h4><p>Masih Kosong</p></div></div>
        </div>
        <div class="col-md-2 col-sm-6 mb-2">
          <div class="small-box bg-light mb-0"><div class="inner"><h4><?= number_format($summary['missing_with_previous'], 0, ',', '.') ?></h4><p>Ada Histori Sebelumnya</p></div></div>
        </div>
        <div class="col-md-2 col-sm-6 mb-2">
          <div class="small-box bg-light mb-0"><div class="inner"><h4><?= number_format($summary['missing_without_previous'], 0, ',', '.') ?></h4><p>Belum Ada Histori</p></div></div>
        </div>
        <div class="col-md-2 col-sm-6 mb-2">
          <div class="small-box bg-light mb-0"><div class="inner"><h4><?= number_format($summary['existing_snapshot'], 0, ',', '.') ?></h4><p>Row Snapshot</p></div></div>
        </div>
      </div>
      <div class="alert alert-info mb-3">
        Tombol di bawah hanya menambahkan SubSLS yang belum punya row pada tanggal <?= e($selectedDate) ?>. Row yang sudah ada tidak akan diubah.
      </div>
      <form method="post" data-progress-submit data-progress-title="Mengisi Daily Snapshot..." data-progress-text="Sistem sedang menambahkan SubSLS yang kosong pada tanggal ini.">
        <input type="hidden" name="tanggal" value="<?= e($selectedDate) ?>">
        <button class="btn btn-success" type="submit" <?= $summary['missing'] <= 0 ? 'disabled' : '' ?> onclick="return confirm('Isi Daily Snapshot tanggal <?= e($selectedDate) ?> untuk SubSLS yang masih kosong?')">
          <i class="fas fa-wand-magic-sparkles mr-1"></i>Isi Daily Snapshot Tanggal <?= e($selectedDate) ?>
        </button>
      </form>
    </div>
  </div>
<?php endif; ?>
<?php render_footer(); ?>
