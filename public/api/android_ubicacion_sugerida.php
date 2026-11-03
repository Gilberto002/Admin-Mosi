<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Api\AndroidApi;
use App\Database\Connection;
use App\Maquinas\ActivacionMaquina;

AndroidApi::requirePost();
$body = AndroidApi::body();

try {
    $numeroSerie = trim((string) ($body['numero_serie'] ?? ''));
    if ($numeroSerie === '') {
        AndroidApi::responder(['ok' => false, 'error' => 'Falta numero_serie'], 422);
    }
    $pdo = Connection::get();
    ActivacionMaquina::asegurarColumnas($pdo);
    $stmtMaquina = $pdo->prepare(
        'SELECT 1 FROM vendingm.maquinas
         WHERE numero_serie = :numero_serie
           AND ISNULL(id_estatus, 1) <> 3'
    );
    $stmtMaquina->execute(['numero_serie' => $numeroSerie]);
    if (!$stmtMaquina->fetchColumn()) {
        AndroidApi::responder([
            'ok' => false,
            'error' => 'La maquina no esta preregistrada o esta fuera de servicio',
        ], 404);
    }

    $latitud = numeroUbicacion($body['latitud'] ?? null);
    $longitud = numeroUbicacion($body['longitud'] ?? null);
    $origen = 'gps';
    $detalleIp = null;

    if ($latitud === null || $longitud === null) {
        $origen = 'ip';
        $ip = trim((string) ($body['ip_publica'] ?? ($_SERVER['REMOTE_ADDR'] ?? '')));
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            AndroidApi::responder([
                'ok' => true,
                'data' => [
                    'disponible' => false,
                    'origen' => 'ip',
                    'precision' => 'aproximada',
                    'mensaje' => 'No se recibió una IP pública utilizable. Envía coordenadas GPS desde Android.',
                ],
            ]);
        }

        $endpointIp = trim((string) ($_ENV['IP_GEOLOCATION_ENDPOINT'] ?? getenv('IP_GEOLOCATION_ENDPOINT') ?: 'https://ipwho.is/{ip}'));
        if (!str_starts_with($endpointIp, 'https://') || !str_contains($endpointIp, '{ip}')) {
            throw new RuntimeException('IP_GEOLOCATION_ENDPOINT no tiene una configuración segura');
        }
        $campos = 'success,message,ip,city,region,country,postal,latitude,longitude,connection';
        $urlIp = str_replace('{ip}', rawurlencode($ip), $endpointIp);
        $urlIp .= (str_contains($urlIp, '?') ? '&' : '?') . 'fields=' . rawurlencode($campos);
        $geoIp = solicitarJson($urlIp);
        if (!$geoIp || empty($geoIp['success'])) {
            throw new RuntimeException((string) ($geoIp['message'] ?? 'No se pudo estimar la ubicación desde la IP'));
        }
        $latitud = numeroUbicacion($geoIp['latitude'] ?? null);
        $longitud = numeroUbicacion($geoIp['longitude'] ?? null);
        $detalleIp = [
            'ip' => $geoIp['ip'] ?? $ip,
            'ciudad' => $geoIp['city'] ?? null,
            'estado' => $geoIp['region'] ?? null,
            'pais' => $geoIp['country'] ?? null,
            'codigo_postal' => $geoIp['postal'] ?? null,
            'proveedor' => $geoIp['connection']['isp'] ?? null,
        ];
    }

    if ($latitud === null || $longitud === null || $latitud < -90 || $latitud > 90 || $longitud < -180 || $longitud > 180) {
        AndroidApi::responder(['ok' => false, 'error' => 'Coordenadas no válidas'], 422);
    }

    $direccion = direccionGoogle($latitud, $longitud);
    if ($direccion === null && $detalleIp) {
        $direccion = implode(', ', array_filter([
            $detalleIp['ciudad'],
            $detalleIp['estado'],
            $detalleIp['codigo_postal'],
            $detalleIp['pais'],
        ]));
    }

    AndroidApi::responder([
        'ok' => true,
        'data' => [
            'disponible' => true,
            'origen' => $origen,
            'precision' => $origen === 'gps' ? 'coordenadas_dispositivo' : 'aproximada_por_ip',
            'latitud' => round($latitud, 7),
            'longitud' => round($longitud, 7),
            'direccion_sugerida' => $direccion,
            'confirmacion_requerida' => true,
            'detalle_ip' => $detalleIp,
        ],
    ]);
} catch (\Throwable $e) {
    AndroidApi::responder(['ok' => false, 'error' => \App\Security\Security::publicError($e, 'api')], 500);
}

function numeroUbicacion(mixed $valor): ?float
{
    if ($valor === null || $valor === '' || !is_numeric($valor)) {
        return null;
    }
    return (float) $valor;
}

function direccionGoogle(float $latitud, float $longitud): ?string
{
    $apiKey = trim((string) ($_ENV['GOOGLE_MAPS_API_KEY'] ?? getenv('GOOGLE_MAPS_API_KEY') ?: ''));
    if ($apiKey === '') {
        return null;
    }
    $url = 'https://maps.googleapis.com/maps/api/geocode/json?' . http_build_query([
        'latlng' => $latitud . ',' . $longitud,
        'language' => 'es',
        'region' => 'mx',
        'key' => $apiKey,
    ]);
    $respuesta = solicitarJson($url);
    return isset($respuesta['results'][0]['formatted_address'])
        ? trim((string) $respuesta['results'][0]['formatted_address'])
        : null;
}

function solicitarJson(string $url): ?array
{
    $contexto = stream_context_create(['http' => [
        'method' => 'GET',
        'timeout' => 8,
        'ignore_errors' => true,
        'header' => "Accept: application/json\r\nUser-Agent: MDMActivation/1.0\r\n",
    ]]);
    $raw = @file_get_contents($url, false, $contexto);
    if (($raw === false || $raw === '') && function_exists('curl_init')) {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'User-Agent: MDMActivation/1.0'],
        ]);
        $resultado = curl_exec($curl);
        if (is_string($resultado) && $resultado !== '') {
            $raw = $resultado;
        }
        curl_close($curl);
    }
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    $json = json_decode($raw, true);
    return is_array($json) ? $json : null;
}
