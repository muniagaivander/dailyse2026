<?php
require_once __DIR__ . '/bootstrap.php';

function backup_sql_identifier(string $name): string {
    return '`' . str_replace('`', '``', $name) . '`';
}

function backup_sql_value(PDO $pdo, $value): string {
    if ($value === null) {
        return 'NULL';
    }
    return $pdo->quote((string)$value);
}

function backup_stream_database(array $user): void {
    global $DB_NAME, $APP_TIMEZONE;

    if (function_exists('set_time_limit')) {
        set_time_limit(0);
    }

    $pdo = db();
    $filename = 'dailyse2026_backup_' . date('Ymd_His') . '.sql';

    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    echo "-- Backup Database Daily SE 2026\n";
    echo "-- Database: {$DB_NAME}\n";
    echo "-- Dibuat oleh: " . ($user['email'] ?? '-') . "\n";
    echo "-- Waktu: " . date('Y-m-d H:i:s') . " {$APP_TIMEZONE}\n\n";
    echo "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n";
    echo "SET time_zone = \"+00:00\";\n";
    echo "SET FOREIGN_KEY_CHECKS = 0;\n\n";

    $tableStmt = $pdo->query('SHOW FULL TABLES');
    $tables = [];
    while ($row = $tableStmt->fetch(PDO::FETCH_NUM)) {
        if (($row[1] ?? '') === 'BASE TABLE') {
            $tables[] = $row[0];
        }
    }

    foreach ($tables as $table) {
        $quotedTable = backup_sql_identifier($table);

        echo "\n-- --------------------------------------------------------\n";
        echo "-- Struktur tabel {$quotedTable}\n";
        echo "-- --------------------------------------------------------\n\n";
        echo "DROP TABLE IF EXISTS {$quotedTable};\n";

        $createStmt = $pdo->query('SHOW CREATE TABLE ' . $quotedTable);
        $createRow = $createStmt->fetch(PDO::FETCH_ASSOC);
        echo ($createRow['Create Table'] ?? array_values($createRow)[1] ?? '') . ";\n\n";

        echo "-- Data tabel {$quotedTable}\n\n";
        $dataStmt = $pdo->query('SELECT * FROM ' . $quotedTable);
        while ($data = $dataStmt->fetch(PDO::FETCH_ASSOC)) {
            $columns = array_map('backup_sql_identifier', array_keys($data));
            $values = array_map(fn($value) => backup_sql_value($pdo, $value), array_values($data));
            echo 'INSERT INTO ' . $quotedTable . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ");\n";
        }
        echo "\n";

        if (function_exists('flush')) {
            flush();
        }
    }

    echo "SET FOREIGN_KEY_CHECKS = 1;\n";
    exit;
}

$user = require_role(['superadmin']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'download') {
    backup_stream_database($user);
}

require __DIR__ . '/layout.php';
render_header('Backup Database');
?>
<div class="card">
  <div class="card-body">
    <h5 class="card-title mb-3">Download Backup SQL</h5>
    <p class="text-muted">
      File ini berisi struktur tabel dan isi database saat tombol ditekan. Simpan file SQL ini bersama backup kode di GitHub.
    </p>
    <form method="post">
      <input type="hidden" name="action" value="download">
      <button type="submit" class="btn btn-primary">
        <i class="fas fa-database mr-1"></i> Download Backup SQL
      </button>
    </form>
  </div>
</div>
<div class="alert alert-warning">
  Backup bisa memakan waktu kalau data harian sudah banyak. Jangan tutup tab sampai download mulai berjalan.
</div>
<?php render_footer(); ?>
