<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Auth\Auth;
use App\Database\Connection;
use App\Http\JsonResponse;
use App\Maquinas\PanelTecnicoAcceso;

Auth::requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    JsonResponse::error('Metodo no permitido', 405);
}

try {
    $pdo = Connection::get();
    $credencial = PanelTecnicoAcceso::regenerarMaestra($pdo);

    JsonResponse::ok([
        'password' => $credencial['password'],
        'hash' => $credencial['hash'],
        'nota' => 'Esta contrasena maestra se muestra una sola vez. El servidor guarda solo el hash.',
    ]);
} catch (\Throwable $e) {
    JsonResponse::serverError($e, 'panel_tecnico_master_password');
}
