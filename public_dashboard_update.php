<?php
require_once __DIR__ . '/bootstrap.php';
ensure_completion_status_table();

function public_dashboard_codes(): array
{
    return ['6400', '6401', '6402', '6403', '6404', '6405', '6409', '6411', '6471', '6472', '6474'];
}

function public_dashboard_build_context(string $code): array
{
    if ($code === '6400') {
        return [
            'title' => 'Dashboard Publik SE 2026',
            'subtitle' => '6400 - Provinsi Kalimantan Timur',
            'group_label' => 'Kabupaten',
            'table_label' => 'Kabupaten',
            'total_label' => 'Total 6400 - Provinsi Kalimantan Timur',
            'where' => '',
            'params' => [],
            'label_expr' => "CONCAT(k.id,' - Kabupaten ',k.nmkab)",
            'group_expr' => 'k.id, k.nmkab',
            'order_expr' => 'k.id',
        ];
    }

    $stmt = db()->prepare("SELECT id, nmkab FROM master_kab WHERE id=?");
    $stmt->execute([$code]);
    $kab = $stmt->fetch();
    if (!$kab) {
        throw new RuntimeException('Kode kabupaten tidak ditemukan: ' . $code);
    }

    return [
        'title' => 'Dashboard Publik SE 2026',
        'subtitle' => $kab['id'] . ' - ' . $kab['nmkab'],
        'group_label' => 'Kecamatan',
        'table_label' => 'Kecamatan',
        'total_label' => 'Total ' . $kab['id'] . ' - ' . $kab['nmkab'],
        'where' => 'WHERE k.id=?',
        'params' => [$code],
        'label_expr' => "CONCAT(kc.kdkec,' - ',kc.nmkec)",
        'group_expr' => 'kc.id, kc.kdkec, kc.nmkec',
        'order_expr' => 'kc.kdkec, kc.nmkec',
    ];
}

function public_dashboard_build_rows(array $fields, array $context): array
{
    $selects = [];
    foreach (array_keys($fields) as $field) {
        $selects[] = "COALESCE(SUM(ss.$field),0) $field";
    }
    $stmt = db()->prepare("SELECT {$context['label_expr']} label,
            COALESCE(SUM(ss.target),0) target,
            " . implode(',', $selects) . ",
            COUNT(ms.id) subsls_total,
            COALESCE(SUM(CASE WHEN cs.status_selesai='Selesai' THEN 1 ELSE 0 END),0) selesai_count
        FROM master_subsls ms
        JOIN master_sls sl ON sl.id=ms.sls_id
        JOIN master_desa d ON d.id=sl.desa_id
        JOIN master_kec kc ON kc.id=d.kec_id
        JOIN master_kab k ON k.id=kc.kab_id
        LEFT JOIN subsls_status ss ON ss.subsls_id=ms.id
        LEFT JOIN subsls_completion_status cs ON cs.subsls_id=ms.id
        {$context['where']}
        GROUP BY {$context['group_expr']}
        ORDER BY {$context['order_expr']}");
    $stmt->execute($context['params']);
    return $stmt->fetchAll();
}

function public_dashboard_totals(array $rows, array $fields): array
{
    $totals = array_fill_keys(array_merge(['target', 'subsls_total', 'selesai_count'], array_keys($fields)), 0);
    foreach ($rows as $row) {
        foreach ($totals as $key => $_) {
            $totals[$key] += (int)($row[$key] ?? 0);
        }
    }
    return $totals;
}

function public_dashboard_cache_path(): string
{
    return __DIR__ . '/cache/public_dashboard.json';
}

function public_dashboard_wita_label(string $datetime): string
{
    $months = [
        '01' => 'Januari',
        '02' => 'Februari',
        '03' => 'Maret',
        '04' => 'April',
        '05' => 'Mei',
        '06' => 'Juni',
        '07' => 'Juli',
        '08' => 'Agustus',
        '09' => 'September',
        '10' => 'Oktober',
        '11' => 'November',
        '12' => 'Desember',
    ];
    $time = strtotime($datetime);
    return date('d', $time) . ' ' . $months[date('m', $time)] . ' ' . date('Y H:i', $time) . ' WITA';
}

function public_dashboard_generate_cache(string $email): array
{
    $fields = status_fields();
    $dashboards = [];
    foreach (public_dashboard_codes() as $code) {
        $context = public_dashboard_build_context($code);
        $queryContext = $context;
        unset($context['where'], $context['params'], $context['label_expr'], $context['group_expr'], $context['order_expr']);
        $rows = public_dashboard_build_rows($fields, $queryContext);
        $dashboards[$code] = [
            'context' => $context,
            'rows' => $rows,
            'totals' => public_dashboard_totals($rows, $fields),
        ];
    }

    $generatedAt = date('Y-m-d H:i:s');
    $payload = [
        'generated_at' => $generatedAt,
        'generated_at_label' => public_dashboard_wita_label($generatedAt),
        'generated_by' => $email,
        'fields' => $fields,
        'status_colors' => ['#2563eb', '#16a34a', '#dc2626', '#f59e0b', '#0f766e'],
        'range_colors' => [
            ['label' => '< 20%', 'color' => '#dc2626'],
            ['label' => '20% - < 40%', 'color' => '#f59e0b'],
            ['label' => '40% - < 75%', 'color' => '#2563eb'],
            ['label' => '75% - 100%', 'color' => '#16a34a'],
        ],
        'dashboards' => $dashboards,
    ];

    $cacheDir = dirname(public_dashboard_cache_path());
    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0775, true);
    }
    file_put_contents(
        public_dashboard_cache_path(),
        json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        LOCK_EX
    );
    return $payload;
}

if (PHP_SAPI === 'cli') {
    try {
        $payload = public_dashboard_generate_cache('cron');
        echo 'Dashboard publik berhasil di-update: ' . ($payload['generated_at_label'] ?? '-') . PHP_EOL;
        exit(0);
    } catch (Throwable $e) {
        fwrite(STDERR, 'Gagal update dashboard publik: ' . $e->getMessage() . PHP_EOL);
        exit(1);
    }
}

require __DIR__ . '/layout.php';
$user = require_role(['superadmin']);

$cacheExists = is_file(public_dashboard_cache_path());
$cacheInfo = null;
if ($cacheExists) {
    $cacheInfo = json_decode((string)file_get_contents(public_dashboard_cache_path()), true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $cacheInfo = public_dashboard_generate_cache($user['email']);
        flash('success', 'Dashboard publik berhasil di-update.');
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }
    redirect('public_dashboard_update.php');
}

render_header('Update Dashboard Publik');
?>
<div class="card">
  <div class="card-body">
    <p class="mb-2">Menu ini membuat snapshot dashboard publik. Halaman publik `/6400`, `/6401`, dan seterusnya hanya membaca file snapshot, bukan query database langsung.</p>
    <?php if ($cacheInfo): ?>
      <div class="alert alert-info">
        Update terakhir: <strong><?= e($cacheInfo['generated_at_label'] ?? '-') ?></strong>
        <?php if (!empty($cacheInfo['generated_by'])): ?> oleh <?= e($cacheInfo['generated_by']) ?><?php endif; ?>
      </div>
    <?php else: ?>
      <div class="alert alert-warning">Dashboard publik belum pernah dibuat. Klik tombol update untuk membuat snapshot pertama.</div>
    <?php endif; ?>
    <form method="post" data-progress-submit data-progress-title="Mengupdate dashboard publik..." data-progress-text="Mohon tunggu, snapshot provinsi dan kabupaten sedang dibuat.">
      <button class="btn btn-primary"><i class="fas fa-rotate mr-1"></i>Update Dashboard Publik</button>
    </form>
  </div>
</div>
<?php render_footer(); ?>
