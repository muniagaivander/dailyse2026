<?php
require __DIR__ . '/layout.php';
$user = require_role(['superadmin']);
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

function public_dashboard_performance_rows(string $roleField, array $context): array
{
    $stmt = db()->prepare("SELECT ms.$roleField email,
            u.name petugas_name,
            GROUP_CONCAT(DISTINCT d.nmdesa ORDER BY d.nmdesa SEPARATOR ', ') desa_names,
            GROUP_CONCAT(DISTINCT k.nmkab ORDER BY k.nmkab SEPARATOR ', ') kab_names,
            COALESCE(SUM(ss.target),0) target,
            COALESCE(SUM(ss.submitted_by_pencacah),0) submitted_by_pencacah,
            COALESCE(SUM(ss.rejected_by_pengawas),0) rejected_by_pengawas,
            COALESCE(SUM(ss.draft_count),0) draft_count,
            COALESCE(SUM(ss.pending_count),0) pending_count,
            COALESCE(SUM(ss.approved_by_pengawas),0) approved_by_pengawas,
            COUNT(ms.id) subsls_total,
            COALESCE(SUM(CASE WHEN cs.status_selesai='Selesai' THEN 1 ELSE 0 END),0) selesai_count,
            CASE WHEN COALESCE(SUM(ss.target),0)>0
                THEN ROUND((COALESCE(SUM(ss.submitted_by_pencacah),0)+COALESCE(SUM(ss.rejected_by_pengawas),0)+COALESCE(SUM(ss.pending_count),0)+COALESCE(SUM(ss.approved_by_pengawas),0))/COALESCE(SUM(ss.target),0)*100,2)
                ELSE 0 END progress_pendataan_pct,
            CASE WHEN COUNT(ms.id)>0
                THEN ROUND(COALESCE(SUM(CASE WHEN cs.status_selesai='Selesai' THEN 1 ELSE 0 END),0)/COUNT(ms.id)*100,2)
                ELSE 0 END selesai_pct
        FROM master_subsls ms
        JOIN master_sls sl ON sl.id=ms.sls_id
        JOIN master_desa d ON d.id=sl.desa_id
        JOIN master_kec kc ON kc.id=d.kec_id
        JOIN master_kab k ON k.id=kc.kab_id
        LEFT JOIN subsls_status ss ON ss.subsls_id=ms.id
        LEFT JOIN subsls_completion_status cs ON cs.subsls_id=ms.id
        LEFT JOIN users u ON u.email=ms.$roleField
        {$context['where']}
        " . ($context['where'] ? 'AND' : 'WHERE') . " ms.$roleField IS NOT NULL AND ms.$roleField <> ''
        GROUP BY ms.$roleField, u.name
        ORDER BY progress_pendataan_pct DESC, selesai_pct DESC, petugas_name ASC, email ASC
        LIMIT 10");
    $stmt->execute($context['params']);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $area = trim((string)($row['desa_names'] ?? ''));
        if ($area === '') {
            $area = trim((string)($row['kab_names'] ?? ''));
        } elseif (!empty($row['kab_names']) && substr_count($area, ',') === 0) {
            $area .= ', ' . $row['kab_names'];
        }
        $row['display_name'] = petugas_short_area_label($row['petugas_name'] ?: $row['email'], $area);
    }
    unset($row);
    return $rows;
}

function public_dashboard_cache_path(): string
{
    return __DIR__ . '/cache/public_dashboard.json';
}

function public_dashboard_cache_dir(): string
{
    return dirname(public_dashboard_cache_path());
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
            'top_pengawas' => public_dashboard_performance_rows('pengawas_email', $queryContext),
            'top_pencacah' => public_dashboard_performance_rows('pencacah_email', $queryContext),
        ];
    }

    $generatedAt = date('Y-m-d H:i:s');
    $payload = [
        'generated_at' => $generatedAt,
        'generated_at_label' => public_dashboard_wita_label($generatedAt),
        'generated_by' => $email,
        'fields' => $fields,
        'status_colors' => ['#2563eb', '#f59e0b', '#16a34a', '#dc2626', '#7c3aed', '#0f766e'],
        'range_colors' => [
            ['label' => '< 20%', 'color' => '#dc2626'],
            ['label' => '20% - < 40%', 'color' => '#f59e0b'],
            ['label' => '40% - < 75%', 'color' => '#2563eb'],
            ['label' => '75% - 100%', 'color' => '#16a34a'],
        ],
        'dashboards' => $dashboards,
    ];

    $cacheDir = public_dashboard_cache_dir();
    if (!is_dir($cacheDir)) {
        if (!mkdir($cacheDir, 0775, true) && !is_dir($cacheDir)) {
            throw new RuntimeException('Folder cache gagal dibuat: ' . $cacheDir);
        }
    }
    if (!is_writable($cacheDir)) {
        throw new RuntimeException('Folder cache tidak bisa ditulis oleh PHP/web server: ' . $cacheDir);
    }

    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new RuntimeException('Gagal membuat JSON dashboard publik.');
    }

    $cachePath = public_dashboard_cache_path();
    $tmpPath = $cachePath . '.tmp';
    if (file_put_contents($tmpPath, $json, LOCK_EX) === false) {
        throw new RuntimeException('File cache sementara gagal ditulis: ' . $tmpPath);
    }
    if (!rename($tmpPath, $cachePath)) {
        @unlink($tmpPath);
        throw new RuntimeException('File cache gagal dipindahkan ke: ' . $cachePath);
    }
    return $payload;
}

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
    <table class="table table-sm table-bordered mb-3">
      <tbody>
        <tr><th style="width:220px">Path cache</th><td><?= e(public_dashboard_cache_path()) ?></td></tr>
        <tr><th>Folder cache ada</th><td><?= is_dir(public_dashboard_cache_dir()) ? 'Ya' : 'Tidak' ?></td></tr>
        <tr><th>Folder cache writable</th><td><?= is_dir(public_dashboard_cache_dir()) && is_writable(public_dashboard_cache_dir()) ? 'Ya' : 'Tidak' ?></td></tr>
      </tbody>
    </table>
    <form method="post" data-progress-submit data-progress-title="Mengupdate dashboard publik..." data-progress-text="Mohon tunggu, snapshot provinsi dan kabupaten sedang dibuat.">
      <button class="btn btn-primary"><i class="fas fa-rotate mr-1"></i>Update Dashboard Publik</button>
    </form>
  </div>
</div>
<?php render_footer(); ?>
