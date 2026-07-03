<?php
require __DIR__ . '/layout.php';
require_once __DIR__ . '/performance_cache.php';
$user = require_role(['superadmin']);

$cache = performance_cache_read();
$cacheVersionCurrent = (int)($cache['version'] ?? 0) >= 5;

render_header('Update Data Performa');
?>
<div class="card">
  <div class="card-body">
    <p>Menu ini menghitung seluruh Performa Pengawas dan Pencacah dalam satu snapshot. Dashboard performa hanya membaca hasil snapshot ini dan tidak menghitung ulang <code>daily_status</code> ketika dibuka.</p>
    <?php if ($cache): ?>
      <div class="alert <?= performance_cache_is_today($cache) && $cacheVersionCurrent ? 'alert-success' : 'alert-warning' ?>">
        Update terakhir: <strong><?= e(performance_cache_generated_label($cache)) ?></strong>
        <?php if (!empty($cache['generated_by'])): ?> oleh <?= e($cache['generated_by']) ?><?php endif; ?>.
        <?php if (!performance_cache_is_today($cache)): ?><br>Data performa belum diperbarui hari ini.<?php endif; ?>
        <?php if (!$cacheVersionCurrent): ?><br>Format cache lama. Jalankan update agar Capaian Hari Ini, Target Hari Ini, dan jumlah SubSLS pada Wilayah Kerja tersedia.<?php endif; ?>
      </div>
      <table class="table table-sm table-bordered mb-3">
        <tbody>
          <tr><th style="width:240px">Jumlah Pengawas</th><td><?= number_format((int)($cache['summary']['pengawas'] ?? 0), 0, ',', '.') ?></td></tr>
          <tr><th>Jumlah Pencacah</th><td><?= number_format((int)($cache['summary']['pencacah'] ?? 0), 0, ',', '.') ?></td></tr>
          <tr><th>Periode Mingguan</th><td><?= e($cache['week_label'] ?? '-') ?></td></tr>
        </tbody>
      </table>
    <?php else: ?>
      <div class="alert alert-warning">Data performa belum pernah dibuat. Klik tombol di bawah untuk membuat snapshot pertama.</div>
    <?php endif; ?>

    <table class="table table-sm table-bordered mb-3">
      <tbody>
        <tr><th style="width:240px">Path cache privat</th><td><?= e(performance_cache_path()) ?></td></tr>
        <tr><th>Folder cache ada</th><td><?= is_dir(performance_cache_dir()) ? 'Ya' : 'Belum' ?></td></tr>
        <tr><th>Folder writable</th><td><?= is_dir(performance_cache_dir()) && is_writable(performance_cache_dir()) ? 'Ya' : 'Belum dapat diperiksa' ?></td></tr>
      </tbody>
    </table>

    <form method="post" action="index.php?action=generate_performance_cache"
          data-progress-submit
          data-progress-title="Mengupdate data performa..."
          data-progress-text="Mohon tunggu, Performa Pengawas dan Pencacah seluruh wilayah sedang dihitung.">
      <button class="btn btn-primary" type="submit"><i class="fas fa-rotate mr-1"></i>Update Data Performa</button>
    </form>
  </div>
</div>
<?php render_footer(); ?>
