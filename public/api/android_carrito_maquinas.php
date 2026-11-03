<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Api\AndroidApi;
use App\Database\Connection;

if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) {
    AndroidApi::responder(['ok' => false, 'error' => 'Metodo no permitido'], 405);
}

$body = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? AndroidApi::body()
    : [
        'id_maquina' => filter_input(INPUT_GET, 'id_maquina', FILTER_VALIDATE_INT) ?: null,
        'numero_serie' => trim((string) ($_GET['numero_serie'] ?? '')),
    ];

try {
    $pdo = Connection::get();
    $maquinaAuth = AndroidApi::autenticar($pdo, $body);
    $params = [];
    $where = 'WHERE 1 = 1';

    if (!empty($maquinaAuth)) {
        $id = (int) $maquinaAuth['id_maquina'];
        $idPadre = $maquinaAuth['id_maquina_padre'] !== null ? (int) $maquinaAuth['id_maquina_padre'] : null;
        $idUnidad = ((int) $maquinaAuth['rol_maquina'] === 2 && $idPadre) ? $idPadre : $id;
        $where .= ' AND (m.id_maquina = :id_unidad OR m.id_maquina_padre = :id_unidad_padre)';
        $params['id_unidad'] = $idUnidad;
        $params['id_unidad_padre'] = $idUnidad;
    }

    $stmt = $pdo->prepare(
        "SELECT m.id_maquina, m.numero_serie, m.nombre_ubicacion, m.rol_maquina, m.id_maquina_padre,
                m.id_estatus, em.nombre AS estatus_nombre
         FROM vendingm.maquinas m
         LEFT JOIN vendingm.estatus_maquina em ON em.id_estatus = m.id_estatus
         {$where}
         ORDER BY CASE WHEN m.rol_maquina = 1 THEN 0 ELSE 1 END, m.numero_serie"
    );
    $stmt->execute($params);
    $maquinas = array_map(static function (array $m): array {
        return [
            'id_maquina' => (int) $m['id_maquina'],
            'numero_serie' => (string) $m['numero_serie'],
            'nombre' => $m['nombre_ubicacion'] ?: null,
            'rol_maquina' => (int) $m['rol_maquina'],
            'id_maquina_padre' => $m['id_maquina_padre'] !== null ? (int) $m['id_maquina_padre'] : null,
            'id_estatus' => $m['id_estatus'] !== null ? (int) $m['id_estatus'] : null,
            'estatus_nombre' => $m['estatus_nombre'] ?: null,
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));

    AndroidApi::responder(['ok' => true, 'data' => ['maquinas' => $maquinas]]);
} catch (\Throwable $e) {
    AndroidApi::responder(['ok' => false, 'error' => \App\Security\Security::publicError($e, 'api')], 500);
}
