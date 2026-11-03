<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Auth\Auth;
use App\Database\Connection;

Auth::requireAdmin();
header('Content-Type: application/json; charset=utf-8');

$pdo = Connection::get();
asegurarRolesTecnicos($pdo);
$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        $id = filter_input(INPUT_GET, 'id_usuario', FILTER_VALIDATE_INT);

        if ($id) {
            $stmt = $pdo->prepare(
                "SELECT id_usuario, nombre, apellido, email, rol, activo, ultimo_acceso
                 FROM vendingm.usuarios
                 WHERE id_usuario = :id"
            );
            $stmt->execute(['id' => $id]);
            $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$usuario) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => 'Usuario no encontrado'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $stmtM = $pdo->prepare('SELECT id_maquina FROM vendingm.usuarios_maquinas WHERE id_usuario = :id');
            $stmtM->execute(['id' => $id]);
            $usuario['maquinas'] = array_map('intval', $stmtM->fetchAll(PDO::FETCH_COLUMN));

            echo json_encode(['ok' => true, 'data' => $usuario], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $stmt = $pdo->query(
            "SELECT u.id_usuario, u.nombre, u.apellido, u.email, u.rol, u.activo, u.ultimo_acceso,
                    COUNT(um.id_maquina) AS maquinas_asignadas
             FROM vendingm.usuarios u
             LEFT JOIN vendingm.usuarios_maquinas um ON um.id_usuario = u.id_usuario
             GROUP BY u.id_usuario, u.nombre, u.apellido, u.email, u.rol, u.activo, u.ultimo_acceso
             ORDER BY u.nombre, u.apellido"
        );
        echo json_encode(['ok' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Metodo no permitido'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) {
        $body = $_POST;
    }

    $id = filter_var($body['id_usuario'] ?? null, FILTER_VALIDATE_INT) ?: null;
    $nombre = trim((string) ($body['nombre'] ?? ''));
    $apellido = trim((string) ($body['apellido'] ?? ''));
    $email = strtolower(trim((string) ($body['email'] ?? '')));
    $password = (string) ($body['password'] ?? '');
    $rol = (string) ($body['rol'] ?? 'colaborador');
    $activo = !empty($body['activo']) ? 1 : 0;
    $maquinas = array_values(array_unique(array_map('intval', $body['maquinas'] ?? [])));

    $errores = [];
    if ($nombre === '') $errores[] = 'nombre es obligatorio';
    if ($apellido === '') $errores[] = 'apellido es obligatorio';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errores[] = 'email no es valido';
    if (!in_array($rol, ['admin', 'cliente', 'colaborador', 'medico', 'psicologo', 'nutriologo', 'tecnico_instalador', 'tecnico_soporte'], true)) $errores[] = 'rol no es valido';
    if ($id === null && strlen($password) < 8) $errores[] = 'password debe tener al menos 8 caracteres';
    if ($id !== null && $password !== '' && strlen($password) < 8) $errores[] = 'password debe tener al menos 8 caracteres';

    if ($errores) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => implode('; ', $errores)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmtDup = $pdo->prepare('SELECT id_usuario FROM vendingm.usuarios WHERE email = :email AND id_usuario <> :id');
    $stmtDup->execute(['email' => $email, 'id' => $id ?? 0]);
    if ($stmtDup->fetchColumn()) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'Ya existe un usuario con ese correo'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pdo->beginTransaction();

    if ($id === null) {
        $stmt = $pdo->prepare(
            "INSERT INTO vendingm.usuarios (nombre, apellido, email, password_hash, rol, activo)
             OUTPUT INSERTED.id_usuario
             VALUES (:nombre, :apellido, :email, :hash, :rol, :activo)"
        );
        $stmt->execute([
            'nombre' => $nombre,
            'apellido' => $apellido,
            'email' => $email,
            'hash' => password_hash($password, PASSWORD_DEFAULT),
            'rol' => $rol,
            'activo' => $activo,
        ]);
        $id = (int) $stmt->fetchColumn();
        $accion = 'creado';
    } else {
        $sqlPassword = $password !== '' ? ', password_hash = :hash' : '';
        $stmt = $pdo->prepare(
            "UPDATE vendingm.usuarios
             SET nombre = :nombre, apellido = :apellido, email = :email, rol = :rol,
                 activo = :activo, fecha_actualizacion = SYSDATETIME() {$sqlPassword}
             WHERE id_usuario = :id"
        );
        $params = [
            'nombre' => $nombre,
            'apellido' => $apellido,
            'email' => $email,
            'rol' => $rol,
            'activo' => $activo,
            'id' => $id,
        ];
        if ($password !== '') {
            $params['hash'] = password_hash($password, PASSWORD_DEFAULT);
        }
        $stmt->execute($params);
        $accion = 'actualizado';
    }

    $pdo->prepare('DELETE FROM vendingm.usuarios_maquinas WHERE id_usuario = :id')->execute(['id' => $id]);
    if (in_array($rol, ['cliente', 'colaborador'], true) && $maquinas) {
        $stmtM = $pdo->prepare(
            "INSERT INTO vendingm.usuarios_maquinas (id_usuario, id_maquina)
             SELECT :id_usuario, id_maquina
             FROM vendingm.maquinas
             WHERE id_maquina = :id_maquina AND rol_maquina = 1"
        );
        foreach ($maquinas as $idMaquina) {
            $stmtM->execute(['id_usuario' => $id, 'id_maquina' => $idMaquina]);
        }
    }

    $pdo->commit();
    echo json_encode(['ok' => true, 'data' => ['id_usuario' => $id, 'accion' => $accion]], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => \App\Security\Security::publicError($e, 'api')], JSON_UNESCAPED_UNICODE);
}

function asegurarRolesTecnicos(PDO $pdo): void
{
    $pdo->exec("
        IF EXISTS (
            SELECT 1
            FROM sys.check_constraints
            WHERE name = 'CK_usuarios_rol'
              AND parent_object_id = OBJECT_ID('vendingm.usuarios')
              AND (definition NOT LIKE '%tecnico_instalador%' OR definition NOT LIKE '%cliente%' OR definition NOT LIKE '%medico%' OR definition NOT LIKE '%psicologo%' OR definition NOT LIKE '%nutriologo%')
        )
            ALTER TABLE vendingm.usuarios DROP CONSTRAINT CK_usuarios_rol;

        IF NOT EXISTS (
            SELECT 1
            FROM sys.check_constraints
            WHERE name = 'CK_usuarios_rol'
              AND parent_object_id = OBJECT_ID('vendingm.usuarios')
        )
            ALTER TABLE vendingm.usuarios
            ADD CONSTRAINT CK_usuarios_rol
            CHECK (rol IN ('admin', 'cliente', 'colaborador', 'medico', 'psicologo', 'nutriologo', 'tecnico_instalador', 'tecnico_soporte'));
    ");
}
