<?php
require __DIR__ . '/bootstrap.php';

@ini_set('memory_limit', '512M');
@set_time_limit(120);

function map_json_error(int $status, string $message): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $message]);
    exit;
}

$level = $_GET['level'] ?? 'kabupaten';
$allowed = ['kabupaten', 'kecamatan', 'desa', 'subsls'];
if (!in_array($level, $allowed, true)) {
    map_json_error(400, 'Level peta tidak valid');
}

$mapFiles = [
    'kabupaten' => __DIR__ . '/assets/maps/kaltim_kabupaten.geojson',
    'kecamatan' => __DIR__ . '/assets/maps/kaltim_kecamatan.geojson',
    'desa' => __DIR__ . '/assets/maps/kaltim_desa.geojson',
    'subsls' => __DIR__ . '/assets/maps/kaltim_sls.geojson',
];

$path = $mapFiles[$level];
if (!is_file($path)) {
    map_json_error(404, 'File peta tidak ditemukan: assets/maps/' . basename($path));
}
if (!is_readable($path)) {
    map_json_error(403, 'File peta tidak bisa dibaca: assets/maps/' . basename($path));
}

$kabId = (string)($_GET['kab_id'] ?? '');
$kecId = (string)($_GET['kec_id'] ?? '');
$desaId = (string)($_GET['desa_id'] ?? '');
$subslsId = (string)($_GET['subsls_id'] ?? '');

function map_prop_id(array $props, string $level): string
{
    if ($level === 'kabupaten') {
        return (string)($props['idkab'] ?? (($props['kdprov'] ?? '') . ($props['kdkab'] ?? '')));
    }
    if ($level === 'kecamatan') {
        return (string)($props['idkec'] ?? (($props['kdprov'] ?? '') . ($props['kdkab'] ?? '') . ($props['kdkec'] ?? '')));
    }
    if ($level === 'desa') {
        return (string)($props['iddesa'] ?? (($props['kdprov'] ?? '') . ($props['kdkab'] ?? '') . ($props['kdkec'] ?? '') . ($props['kddesa'] ?? '')));
    }
    return (string)($props['idsubsls'] ?? '');
}

function map_feature_matches(array $feature, string $level, string $kabId, string $kecId, string $desaId, string $subslsId): bool
{
    $props = $feature['properties'] ?? [];
    if ($level === 'kecamatan' && $kabId !== '' && map_prop_id($props, 'kabupaten') !== $kabId) {
        return false;
    }
    if ($level === 'desa' && $kecId !== '' && map_prop_id($props, 'kecamatan') !== $kecId) {
        return false;
    }
    if ($level === 'subsls' && $desaId !== '' && map_prop_id($props, 'desa') !== $desaId) {
        return false;
    }
    if ($level === 'subsls' && $subslsId !== '' && map_prop_id($props, 'subsls') !== $subslsId) {
        return false;
    }
    return true;
}

function map_stream_filtered_features(string $path, string $level, string $kabId, string $kecId, string $desaId, string $subslsId): void
{
    $handle = fopen($path, 'rb');
    if (!$handle) {
        map_json_error(500, 'File peta gagal dibuka');
    }

    header('Content-Type: application/geo+json; charset=utf-8');
    header('Cache-Control: public, max-age=3600');
    echo '{"type":"FeatureCollection","features":[';

    $buffer = '';
    $insideFeatures = false;
    $insideFeature = false;
    $feature = '';
    $depth = 0;
    $inString = false;
    $escaped = false;
    $first = true;

    while (!feof($handle)) {
        $chunk = fread($handle, 65536);
        if ($chunk === false) {
            break;
        }

        if (!$insideFeatures) {
            $buffer .= $chunk;
            $featuresPos = strpos($buffer, '"features"');
            if ($featuresPos === false) {
                $buffer = substr($buffer, -32);
                continue;
            }
            $bracketPos = strpos($buffer, '[', $featuresPos);
            if ($bracketPos === false) {
                $buffer = substr($buffer, $featuresPos);
                continue;
            }
            $insideFeatures = true;
            $chunk = substr($buffer, $bracketPos + 1);
            $buffer = '';
        }

        $length = strlen($chunk);
        for ($i = 0; $i < $length; $i++) {
            $char = $chunk[$i];
            if (!$insideFeature) {
                if ($char === '{') {
                    $insideFeature = true;
                    $feature = '{';
                    $depth = 1;
                    $inString = false;
                    $escaped = false;
                } elseif ($char === ']') {
                    break 2;
                }
                continue;
            }

            $feature .= $char;
            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $inString = false;
                }
                continue;
            }

            if ($char === '"') {
                $inString = true;
            } elseif ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    $decoded = json_decode($feature, true);
                    if (is_array($decoded) && map_feature_matches($decoded, $level, $kabId, $kecId, $desaId, $subslsId)) {
                        echo $first ? $feature : ',' . $feature;
                        $first = false;
                    }
                    $insideFeature = false;
                    $feature = '';
                }
            }
        }
    }

    fclose($handle);
    echo ']}';
}

map_stream_filtered_features($path, $level, $kabId, $kecId, $desaId, $subslsId);
