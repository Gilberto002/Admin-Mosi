<?php
// public/api/sucursales_clinica.php
// Búsqueda de sucursales reales de farmacia (cat_SucursalClinica) para el
// selector de "Sucursal vinculada" en el alta/edición de máquina
// (maquina_guardar.php). Solo admin -- igual que el resto de los módulos
// que tocan el esquema de farmacia (vencimientos.php/estadisticas.php).
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Database\Connection;
use App\Auth\Auth;
use App\Http\JsonResponse;
use App\Security\Security;
use App\Vending\TraspasoService;

Auth::requireAdmin();
header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = Connection::get();
    // Asegura que exista la columna que distingue sucursales virtuales de
    // vending (id_SucursalOrigenVending) antes de filtrarla -- por si este
    // endpoint se usa antes de que cualquier otro flujo la haya tocado.
    TraspasoService::asegurarEsquema($pdo);
    $q = trim((string) ($_GET['q'] ?? ''));

    // Se excluyen las sucursales virtuales ("VENDING - ...") que
    // TraspasoService::asegurarSucursalVirtual() crea automáticamente al
    // vincular una máquina -- son solo el destino contable del traspaso,
    // nunca una sucursal real que un admin deba poder elegir aquí.
    $where = 'WHERE i_Activo = 1 AND id_SucursalOrigenVending IS NULL';
    $params = [];
    if ($q !== '') {
        $where .= ' AND st_Nombre LIKE :q';
        $params['q'] = "%{$q}%";
    }

    $stmt = $pdo->prepare(
        "SELECT TOP 50 id_SucursalClinica, st_Nombre
         FROM cat_SucursalClinica
         {$where}
         ORDER BY st_Nombre"
    );
    $stmt->execute($params);

    JsonResponse::ok($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (\Throwable $e) {
    Security::logException($e, 'sucursales_clinica');
    JsonResponse::error('No se pudieron cargar las sucursales.', 500);
}
