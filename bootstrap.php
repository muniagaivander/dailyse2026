<?php
session_start();
require __DIR__ . '/config.php';

function db(): PDO {
    static $pdo;
    global $DB_HOST, $DB_NAME, $DB_USER, $DB_PASS;
    if (!$pdo) {
        $pdo = new PDO("mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4", $DB_USER, $DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}

function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function redirect($url): void {
    header("Location: {$url}");
    exit;
}

function current_user(): ?array {
    return $_SESSION['user'] ?? null;
}

function require_login(): array {
    $user = current_user();
    if (!$user) redirect('login.php');
    return $user;
}

function require_role(array $roles): array {
    $user = require_login();
    if (!in_array($user['role'], $roles, true)) {
        http_response_code(403);
        exit('Akses ditolak');
    }
    return $user;
}

function user_active_status(?string $email): bool {
    if (!$email) return false;
    $stmt = db()->prepare("SELECT active FROM users WHERE email=?");
    $stmt->execute([$email]);
    return (bool)$stmt->fetchColumn();
}

function sync_petugas_user_active_status(): void {
    db()->exec("UPDATE users SET active=0 WHERE role IN ('pengawas','pencacah')");
    db()->exec("UPDATE users u
        JOIN (SELECT DISTINCT pengawas_email email FROM master_subsls WHERE pengawas_email IS NOT NULL AND pengawas_email <> '') x
          ON x.email=u.email
        SET u.active=1
        WHERE u.role='pengawas'");
    db()->exec("UPDATE users u
        JOIN (SELECT DISTINCT pencacah_email email FROM master_subsls WHERE pencacah_email IS NOT NULL AND pencacah_email <> '') x
          ON x.email=u.email
        SET u.active=1
        WHERE u.role='pencacah'");
}

function ensure_completion_status_table(): void {
    db()->exec("CREATE TABLE IF NOT EXISTS subsls_completion_status (
        subsls_id VARCHAR(20) PRIMARY KEY,
        status_selesai ENUM('Belum Selesai','Selesai') NOT NULL DEFAULT 'Belum Selesai',
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        updated_by VARCHAR(150) NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function flash($key, $message = null) {
    if ($message !== null) {
        $_SESSION['flash'][$key] = $message;
        return null;
    }
    $value = $_SESSION['flash'][$key] ?? null;
    unset($_SESSION['flash'][$key]);
    return $value;
}

function admin_kab_codes(): array {
    return ['01','02','03','04','05','09','11','71','72','74'];
}

function ensure_default_admins(): void {
    $stmt = db()->prepare("INSERT INTO users (email,password_hash,role,kab_id,name,active)
        VALUES (?,?,?,?,?,1)
        ON DUPLICATE KEY UPDATE name=VALUES(name), role=VALUES(role), kab_id=VALUES(kab_id)");
    db()->prepare("INSERT INTO users (email,password_hash,role,kab_id,name,active)
        VALUES (?,?,?,?,?,1)
        ON DUPLICATE KEY UPDATE name=VALUES(name), role=VALUES(role), kab_id=VALUES(kab_id), active=1")
        ->execute([
            'viewer6400@bps.go.id',
            password_hash('123', PASSWORD_DEFAULT),
            'viewer_prov',
            null,
            'Viewer Provinsi',
        ]);
    foreach (admin_kab_codes() as $kdkab) {
        $kabId = '64' . $kdkab;
        $stmt->execute([
            $kabId . '@bps.go.id',
            password_hash('123', PASSWORD_DEFAULT),
            'admin_kab',
            $kabId,
            'Admin Kab ' . $kabId,
        ]);
        $stmt->execute([
            'viewer' . $kabId . '@bps.go.id',
            password_hash('123', PASSWORD_DEFAULT),
            'viewer_kab',
            $kabId,
            'Viewer Kab ' . $kabId,
        ]);
    }
}

function total_status(array $row): int {
    return (int)$row['open_count'] + (int)$row['draft_count'] + (int)$row['submitted_by_pencacah'] + (int)$row['approved_by_pengawas'] + (int)$row['rejected_by_pengawas'];
}

function normalize_email($email): string {
    return strtolower(trim((string)$email));
}

function today(): string {
    return date('Y-m-d');
}

function status_fields(): array {
    return [
        'open_count' => 'Open',
        'submitted_by_pencacah' => 'Submit',
        'rejected_by_pengawas' => 'Reject',
        'draft_count' => 'Pending',
        'approved_by_pengawas' => 'Approved',
    ];
}

function fetch_area_options(array $user, array $filters = []): array {
    $where = [];
    $params = [];
    if ($user['role'] === 'admin_kab') {
        $where[] = 'k.id = ?';
        $params[] = $user['kab_id'];
    }
    foreach (['kab_id' => 'k.id', 'kec_id' => 'kc.id', 'desa_id' => 'd.id'] as $key => $col) {
        if (!empty($filters[$key])) {
            $where[] = "$col = ?";
            $params[] = $filters[$key];
        }
    }
    $sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $base = "FROM master_subsls ms
        JOIN master_sls sl ON sl.id=ms.sls_id
        JOIN master_desa d ON d.id=sl.desa_id
        JOIN master_kec kc ON kc.id=d.kec_id
        JOIN master_kab k ON k.id=kc.kab_id
        JOIN master_prov p ON p.id=k.prov_id
        $sqlWhere";
    $out = [];
    foreach ([
        'kabupaten' => "SELECT DISTINCT k.id value, CONCAT(k.id,' - ',k.nmkab) label $base ORDER BY label",
        'kecamatan' => "SELECT DISTINCT kc.id value, CONCAT(kc.kdkec,' - ',kc.nmkec) label $base ORDER BY label",
        'desa' => "SELECT DISTINCT d.id value, CONCAT(d.kddesa,' - ',d.nmdesa) label $base ORDER BY label",
        'sls' => "SELECT DISTINCT sl.id value, CONCAT(sl.kdsls,' - ',sl.nmsls) label $base ORDER BY label",
    ] as $key => $sql) {
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $out[$key] = $stmt->fetchAll();
    }
    return $out;
}

function joined_master_sql(): string {
    return "FROM master_subsls ms
        JOIN master_sls sl ON sl.id=ms.sls_id
        JOIN master_desa d ON d.id=sl.desa_id
        JOIN master_kec kc ON kc.id=d.kec_id
        JOIN master_kab k ON k.id=kc.kab_id
        JOIN master_prov p ON p.id=k.prov_id
        LEFT JOIN subsls_status ss ON ss.subsls_id=ms.id";
}
