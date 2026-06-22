<?php
require __DIR__ . '/layout.php';
$user = require_role(['superadmin']);

$items = mobile_update_content();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedItems = $_POST['items'] ?? [];
    if (!is_array($postedItems)) {
        flash('error', 'Format konten tidak valid.');
        redirect('mobile_update.php');
    }
    $valid = true;
    for ($i = 0; $i < 3; $i++) {
        if (trim((string)($postedItems[$i]['title'] ?? '')) === '') {
            $valid = false;
        }
    }
    if (!$valid) {
        flash('error', 'Semua judul wajib diisi.');
        redirect('mobile_update.php');
    }
    if (!save_mobile_update_content($postedItems)) {
        flash('error', 'Konten gagal disimpan. Pastikan folder aplikasi bisa ditulis oleh server.');
        redirect('mobile_update.php');
    }
    flash('success', 'Konten pop-up PML/PCL berhasil diperbarui.');
    redirect('mobile_update.php');
}

render_header('Edit Pop-up Login');
?>
<form method="post" class="card card-body">
  <div class="alert alert-info">
    Konten ini muncul satu kali setelah user login. Isi keterangan satu poin per baris.
  </div>
  <?php foreach ($items as $idx => $item): ?>
    <div class="card mb-3">
      <div class="card-header"><strong>Item <?= $idx + 1 ?></strong></div>
      <div class="card-body">
        <div class="form-group">
          <label>Judul</label>
          <input class="form-control" name="items[<?= $idx ?>][title]" value="<?= e($item['title']) ?>" required>
        </div>
        <div class="form-group">
          <label>Tambahan Judul / Tanggal</label>
          <input class="form-control" name="items[<?= $idx ?>][subtitle]" value="<?= e($item['subtitle']) ?>" placeholder="Contoh: Tanggal 21 Juni 2026">
        </div>
        <div class="form-group mb-0">
          <label>Keterangan</label>
          <textarea class="form-control" name="items[<?= $idx ?>][details_text]" rows="8"><?= e(implode("\n", $item['details'])) ?></textarea>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
  <div class="d-flex justify-content-between align-items-center">
    <div class="text-muted small">Penyimpanan memakai file <code>mobile_update_content.json</code>, tidak memakai tabel database.</div>
    <button class="btn btn-primary" type="submit"><i class="fas fa-save mr-1"></i>Simpan Konten Pop-up</button>
  </div>
</form>
<?php render_footer(); ?>
