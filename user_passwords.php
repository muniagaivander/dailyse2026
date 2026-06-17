<?php
require __DIR__ . '/layout.php';
$user = require_role(['superadmin']);

$kabId = $_GET['kab_id'] ?? '';
$activeTab = in_array($_GET['tab'] ?? 'admin', ['admin', 'pengawas', 'pencacah'], true) ? ($_GET['tab'] ?? 'admin') : 'admin';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = normalize_email($_POST['email'] ?? '');
    $newPassword = (string)($_POST['new_password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');
    $redirectQuery = [
        'kab_id' => $_POST['kab_id'] ?? '',
        'tab' => $_POST['tab'] ?? 'admin',
    ];

    if ($email === '' || $newPassword === '' || $newPassword !== $confirmPassword) {
        flash('error', 'Password baru dan ulangi password baru tidak sama.');
        redirect('user_passwords.php?' . http_build_query($redirectQuery));
    }

    $stmt = db()->prepare("SELECT role FROM users WHERE email=?");
    $stmt->execute([$email]);
    $role = $stmt->fetchColumn();
    if (!in_array($role, ['admin_kab', 'pengawas', 'pencacah'], true)) {
        flash('error', 'User tidak ditemukan atau tidak bisa direset dari menu ini.');
        redirect('user_passwords.php?' . http_build_query($redirectQuery));
    }

    db()->prepare("UPDATE users SET password_hash=? WHERE email=?")
        ->execute([password_hash($newPassword, PASSWORD_DEFAULT), $email]);
    flash('success', 'Password ' . $email . ' berhasil direset.');
    redirect('user_passwords.php?' . http_build_query($redirectQuery));
}

$kabOptions = db()->query("SELECT id value, CONCAT(id,' - ',nmkab) label FROM master_kab ORDER BY id")->fetchAll();
$rows = ['admin' => [], 'pengawas' => [], 'pencacah' => []];

if ($kabId !== '') {
    $stmt = db()->prepare("SELECT email, name, role, active FROM users WHERE role='admin_kab' AND kab_id=? ORDER BY active DESC, email");
    $stmt->execute([$kabId]);
    $rows['admin'] = $stmt->fetchAll();

    $stmt = db()->prepare("SELECT u.email, u.name, u.role, u.active
        FROM users u
        JOIN (
            SELECT DISTINCT ms.pengawas_email email
            FROM master_subsls ms
            JOIN master_sls sl ON sl.id=ms.sls_id
            JOIN master_desa d ON d.id=sl.desa_id
            JOIN master_kec kc ON kc.id=d.kec_id
            WHERE kc.kab_id=? AND ms.pengawas_email IS NOT NULL AND ms.pengawas_email <> ''
        ) x ON x.email=u.email
        WHERE u.role='pengawas'
        ORDER BY u.active DESC, u.email");
    $stmt->execute([$kabId]);
    $rows['pengawas'] = $stmt->fetchAll();

    $stmt = db()->prepare("SELECT u.email, u.name, u.role, u.active
        FROM users u
        JOIN (
            SELECT DISTINCT ms.pencacah_email email
            FROM master_subsls ms
            JOIN master_sls sl ON sl.id=ms.sls_id
            JOIN master_desa d ON d.id=sl.desa_id
            JOIN master_kec kc ON kc.id=d.kec_id
            WHERE kc.kab_id=? AND ms.pencacah_email IS NOT NULL AND ms.pencacah_email <> ''
        ) x ON x.email=u.email
        WHERE u.role='pencacah'
        ORDER BY u.active DESC, u.email");
    $stmt->execute([$kabId]);
    $rows['pencacah'] = $stmt->fetchAll();
}

function render_password_user_table(array $items, string $tab, string $kabId): void {
?>
  <?php if (!$kabId): ?>
    <div class="alert alert-info mb-0">Pilih kabupaten dulu untuk menampilkan user.</div>
  <?php elseif (!$items): ?>
    <div class="alert alert-info mb-0">Tidak ada user pada tab ini.</div>
  <?php else: ?>
    <div class="table-responsive">
      <table class="table table-bordered table-sm table-striped mb-0">
        <thead><tr><th>Email</th><th>Nama</th><th>Status</th><th style="width: 150px">Aksi</th></tr></thead>
        <tbody>
        <?php foreach ($items as $item): ?>
          <tr>
            <td><?= e($item['email']) ?></td>
            <td><?= e($item['name']) ?></td>
            <td>
              <span class="badge badge-<?= (int)$item['active'] === 1 ? 'success' : 'danger' ?>">
                <?= (int)$item['active'] === 1 ? 'aktif' : 'tidak aktif' ?>
              </span>
            </td>
            <td><button class="btn btn-warning btn-sm reset-password-btn" type="button" data-email="<?= e($item['email']) ?>" data-tab="<?= e($tab) ?>">Reset Password</button></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
<?php
}

render_header('Ganti Password User');
?>
<form class="card card-body mb-3" method="get">
  <input type="hidden" name="tab" value="<?= e($activeTab) ?>">
  <div class="form-row align-items-end">
    <div class="form-group col-md-4">
      <label>Kabupaten</label>
      <select class="form-control" name="kab_id" id="kab_id" required>
        <option value="">Pilih Kabupaten</option>
        <?php foreach ($kabOptions as $o): ?><option value="<?= e($o['value']) ?>" <?= $kabId===$o['value']?'selected':'' ?>><?= e($o['label']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="form-group col-md-2"><button class="btn btn-primary">Filter</button></div>
  </div>
</form>

<div class="card">
  <div class="card-header p-0 pt-1">
    <ul class="nav nav-tabs" role="tablist">
      <li class="nav-item"><a class="nav-link <?= $activeTab==='admin'?'active':'' ?>" href="#tab-admin" data-toggle="tab" data-tab-name="admin">Admin</a></li>
      <li class="nav-item"><a class="nav-link <?= $activeTab==='pengawas'?'active':'' ?>" href="#tab-pengawas" data-toggle="tab" data-tab-name="pengawas">Pengawas</a></li>
      <li class="nav-item"><a class="nav-link <?= $activeTab==='pencacah'?'active':'' ?>" href="#tab-pencacah" data-toggle="tab" data-tab-name="pencacah">Pencacah</a></li>
    </ul>
  </div>
  <div class="card-body p-0">
    <div class="tab-content">
      <div class="tab-pane <?= $activeTab==='admin'?'active':'' ?>" id="tab-admin"><?php render_password_user_table($rows['admin'], 'admin', $kabId); ?></div>
      <div class="tab-pane <?= $activeTab==='pengawas'?'active':'' ?>" id="tab-pengawas">
        <?php if ($kabId && $rows['pengawas']): ?>
          <div class="p-3 pb-2">
            <input class="form-control user-tab-search" data-target-tab="pengawas" placeholder="Cari email atau nama pengawas">
          </div>
        <?php endif; ?>
        <?php render_password_user_table($rows['pengawas'], 'pengawas', $kabId); ?>
      </div>
      <div class="tab-pane <?= $activeTab==='pencacah'?'active':'' ?>" id="tab-pencacah">
        <?php if ($kabId && $rows['pencacah']): ?>
          <div class="p-3 pb-2">
            <input class="form-control user-tab-search" data-target-tab="pencacah" placeholder="Cari email atau nama pencacah">
          </div>
        <?php endif; ?>
        <?php render_password_user_table($rows['pencacah'], 'pencacah', $kabId); ?>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="resetPasswordModal" tabindex="-1" role="dialog" aria-labelledby="resetPasswordModalLabel" aria-hidden="true">
  <div class="modal-dialog" role="document">
    <form class="modal-content" method="post">
      <input type="hidden" name="email" id="reset_email">
      <input type="hidden" name="tab" id="reset_tab" value="<?= e($activeTab) ?>">
      <input type="hidden" name="kab_id" value="<?= e($kabId) ?>">
      <div class="modal-header">
        <h5 class="modal-title" id="resetPasswordModalLabel">Reset Password</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Tutup"><span aria-hidden="true">&times;</span></button>
      </div>
      <div class="modal-body">
        <p class="mb-3">User: <strong id="reset_email_label"></strong></p>
        <div class="form-group">
          <label>Password Baru</label>
          <div class="input-group">
            <input class="form-control" name="new_password" type="password" required autocomplete="new-password">
            <div class="input-group-append"><button class="btn btn-outline-secondary password-toggle" type="button"><i class="fas fa-eye"></i></button></div>
          </div>
        </div>
        <div class="form-group mb-0">
          <label>Ulangi Password Baru</label>
          <div class="input-group">
            <input class="form-control" name="confirm_password" type="password" required autocomplete="new-password">
            <div class="input-group-append"><button class="btn btn-outline-secondary password-toggle" type="button"><i class="fas fa-eye"></i></button></div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-primary" type="submit">Ganti</button>
        <button class="btn btn-secondary" type="button" data-dismiss="modal">Batal</button>
      </div>
    </form>
  </div>
</div>

<script>
document.querySelectorAll('[data-tab-name]').forEach(function (tab) {
  tab.addEventListener('click', function () {
    const input = document.querySelector('input[name="tab"]');
    if (input) input.value = this.getAttribute('data-tab-name');
  });
});

document.querySelectorAll('.reset-password-btn').forEach(function (button) {
  button.addEventListener('click', function () {
    const email = this.getAttribute('data-email');
    const tab = this.getAttribute('data-tab');
    document.getElementById('reset_email').value = email;
    document.getElementById('reset_tab').value = tab;
    document.getElementById('reset_email_label').textContent = email;
    $('#resetPasswordModal').modal('show');
  });
});

document.querySelectorAll('.user-tab-search').forEach(function (input) {
  input.addEventListener('input', function () {
    const tabName = this.getAttribute('data-target-tab');
    const keyword = this.value.trim().toLowerCase();
    const tabPane = document.getElementById('tab-' + tabName);
    tabPane.querySelectorAll('tbody tr').forEach(function (row) {
      row.style.display = row.textContent.toLowerCase().includes(keyword) ? '' : 'none';
    });
  });
});
</script>
<?php render_footer(); ?>
