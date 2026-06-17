<?php
require __DIR__ . '/bootstrap.php';
$user = require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php');
}

$oldPassword = (string)($_POST['old_password'] ?? '');
$newPassword = (string)($_POST['new_password'] ?? '');
$confirmPassword = (string)($_POST['confirm_password'] ?? '');

if ($newPassword === '' || $newPassword !== $confirmPassword) {
    flash('error', 'Password baru dan ulangi password baru tidak sama.');
    redirect($_SERVER['HTTP_REFERER'] ?? 'index.php');
}

$stmt = db()->prepare("SELECT password_hash FROM users WHERE id=?");
$stmt->execute([$user['id']]);
$hash = $stmt->fetchColumn();

if (!$hash || !password_verify($oldPassword, $hash)) {
    flash('error', 'Password lama salah.');
    redirect($_SERVER['HTTP_REFERER'] ?? 'index.php');
}

db()->prepare("UPDATE users SET password_hash=? WHERE id=?")
    ->execute([password_hash($newPassword, PASSWORD_DEFAULT), $user['id']]);

flash('success', 'Password berhasil diganti.');
redirect($_SERVER['HTTP_REFERER'] ?? 'index.php');
