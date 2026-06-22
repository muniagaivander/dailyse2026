<?php
require __DIR__ . '/bootstrap.php';
if (current_user()) redirect('index.php');

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = normalize_email($_POST['email'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $stmt = db()->prepare("SELECT * FROM users WHERE email=? AND active=1");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if ($user && password_verify($password, $user['password_hash'])) {
        $_SESSION['user'] = [
            'id' => $user['id'],
            'email' => $user['email'],
            'role' => $user['role'],
            'kab_id' => $user['kab_id'],
            'name' => $user['name'],
        ];
        $_SESSION['show_mobile_update_modal'] = true;
        redirect('index.php');
    }
    $error = 'Email atau password salah.';
}
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Login Daily SE 2026</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
  <style>
    .login-box { width: 720px; max-width: calc(100% - 32px); }
    .login-logo-wrap {
      align-items: center;
      display: flex;
      gap: 18px;
      justify-content: center;
      padding: 20px 24px 10px;
    }
    .login-logo-bps {
      max-height: 78px;
      max-width: 52%;
      object-fit: contain;
    }
    .login-logo-se {
      max-height: 102px;
      max-width: 42%;
      object-fit: contain;
    }
    @media (max-width: 575.98px) {
      .login-logo-wrap {
        flex-direction: column;
        gap: 12px;
      }
      .login-logo-bps,
      .login-logo-se {
        max-width: 100%;
      }
    }
  </style>
</head>
<body class="hold-transition login-page">
<div class="login-box">
  <div class="card card-outline card-primary">
    <div class="login-logo-wrap">
      <img class="login-logo-bps" src="assets/img/logo-bps-kaltim.png" alt="BPS Provinsi Kalimantan Timur">
      <img class="login-logo-se" src="assets/img/logo_Sensus_Ekonomi_2026.png" alt="Sensus Ekonomi 2026">
    </div>
    <div class="card-header text-center"><b>Daily SE 2026</b></div>
    <div class="card-body">
      <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
      <form method="post">
        <div class="form-group"><input class="form-control" name="email" type="email" placeholder="Email" required></div>
        <div class="form-group"><input class="form-control" name="password" type="password" placeholder="Password" required></div>
        <button class="btn btn-primary btn-block">Login</button>
      </form>
    </div>
  </div>
</div>
</body>
</html>
