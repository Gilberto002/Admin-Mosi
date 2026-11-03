<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Auth\Auth;
use App\Database\Connection;
use App\Security\Security;
use App\Telemedicina\Schema;

Auth::requireAdmin();
header('Content-Type: application/json; charset=utf-8');

$body = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'JSON invalido'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = Connection::get();
    Schema::ensure($pdo);
    $tipo = (string) ($body['tipo'] ?? 'mosi');

    if ($tipo === 'grupo') {
        $id = !empty($body['id_grupo']) ? (int) $body['id_grupo'] : null;
        $clave = strtoupper(trim((string) ($body['clave'] ?? '')));
        $nombre = trim((string) ($body['nombre'] ?? ''));
        if ($clave === '' || $nombre === '') {
            throw new InvalidArgumentException('La clave y el nombre del grupo son obligatorios.');
        }
        if ($id) {
            $stmt = $pdo->prepare('UPDATE vendingm.telemedicina_grupos SET clave = :clave, nombre = :nombre, descripcion = :descripcion, responsable = :responsable, fecha_actualizacion = SYSDATETIME() WHERE id_grupo = :id');
            $stmt->execute(['clave' => $clave, 'nombre' => $nombre, 'descripcion' => selfNull($body['descripcion'] ?? null), 'responsable' => selfNull($body['responsable'] ?? null), 'id' => $id]);
        } else {
            $stmt = $pdo->prepare('INSERT INTO vendingm.telemedicina_grupos (clave, nombre, descripcion, responsable) OUTPUT INSERTED.id_grupo VALUES (:clave, :nombre, :descripcion, :responsable)');
            $stmt->execute(['clave' => $clave, 'nombre' => $nombre, 'descripcion' => selfNull($body['descripcion'] ?? null), 'responsable' => selfNull($body['responsable'] ?? null)]);
            $id = (int) $stmt->fetchColumn();
        }
        echo json_encode(['ok' => true, 'data' => ['id_grupo' => $id]], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $id = !empty($body['id_mosi']) ? (int) $body['id_mosi'] : null;
    $serie = strtoupper(trim((string) ($body['numero_serie'] ?? '')));
    $nombre = trim((string) ($body['nombre'] ?? ''));
    $estatus = (string) ($body['estatus'] ?? 'activo');
    $idGrupo = !empty($body['id_grupo']) ? (int) $body['id_grupo'] : null;
    $maquinas = array_values(array_unique(array_filter(array_map('intval', (array) ($body['maquinas'] ?? [])))));

    if ($serie === '' || $nombre === '') {
        throw new InvalidArgumentException('El numero de serie y el nombre son obligatorios.');
    }
    if (!in_array($estatus, ['activo', 'mantenimiento', 'baja'], true)) {
        throw new InvalidArgumentException('Estatus de MOSI no valido.');
    }
    if ($idGrupo) {
        $check = $pdo->prepare('SELECT COUNT(*) FROM vendingm.telemedicina_grupos WHERE id_grupo = :id AND activo = 1');
        $check->execute(['id' => $idGrupo]);
        if (!(int) $check->fetchColumn()) {
            throw new InvalidArgumentException('El grupo seleccionado no existe.');
        }
    }
    if ($maquinas) {
        $marks = implode(',', array_fill(0, count($maquinas), '?'));
        $check = $pdo->prepare("SELECT COUNT(*) FROM vendingm.maquinas WHERE id_maquina IN ({$marks}) AND id_estatus <> 3");
        $check->execute($maquinas);
        if ((int) $check->fetchColumn() !== count($maquinas)) {
            throw new InvalidArgumentException('Una de las maquinas seleccionadas no existe o esta dada de baja.');
        }
    }

    $pdo->beginTransaction();
    $fields = [
        'grupo' => $idGrupo, 'serie' => $serie, 'nombre' => $nombre,
        'modelo' => selfNull($body['modelo'] ?? null), 'fabricante' => selfNull($body['fabricante'] ?? null),
        'ubicacion' => selfNull($body['ubicacion'] ?? null), 'consultorio' => selfNull($body['consultorio'] ?? null),
        'ip' => selfNull($body['ip_local'] ?? null), 'mac' => selfNull($body['mac_address'] ?? null),
        'version' => selfNull($body['version_software'] ?? null), 'conexion' => selfNull($body['tipo_conexion'] ?? null),
        'estatus' => $estatus, 'notas' => selfNull($body['notas'] ?? null),
    ];
    if ($id) {
        $stmt = $pdo->prepare('UPDATE vendingm.telemedicina_mosi SET id_grupo=:grupo, numero_serie=:serie, nombre=:nombre, modelo=:modelo, fabricante=:fabricante, ubicacion=:ubicacion, consultorio=:consultorio, ip_local=:ip, mac_address=:mac, version_software=:version, tipo_conexion=:conexion, estatus=:estatus, notas=:notas, fecha_actualizacion=SYSDATETIME() WHERE id_mosi=:id');
        $stmt->execute($fields + ['id' => $id]);
        if ($stmt->rowCount() === 0) {
            $exists = $pdo->prepare('SELECT COUNT(*) FROM vendingm.telemedicina_mosi WHERE id_mosi = :id');
            $exists->execute(['id' => $id]);
            if (!(int) $exists->fetchColumn()) {
                throw new InvalidArgumentException('La MOSI no existe.');
            }
        }
    } else {
        $stmt = $pdo->prepare('INSERT INTO vendingm.telemedicina_mosi (id_grupo, numero_serie, nombre, modelo, fabricante, ubicacion, consultorio, ip_local, mac_address, version_software, tipo_conexion, estatus, notas) OUTPUT INSERTED.id_mosi VALUES (:grupo,:serie,:nombre,:modelo,:fabricante,:ubicacion,:consultorio,:ip,:mac,:version,:conexion,:estatus,:notas)');
        $stmt->execute($fields);
        $id = (int) $stmt->fetchColumn();
    }
    $pdo->prepare('DELETE FROM vendingm.telemedicina_mosi_maquinas WHERE id_mosi = :id')->execute(['id' => $id]);
    $link = $pdo->prepare('INSERT INTO vendingm.telemedicina_mosi_maquinas (id_mosi, id_maquina, vinculado_por) VALUES (:mosi, :maquina, :usuario)');
    foreach ($maquinas as $idMaquina) {
        $link->execute(['mosi' => $id, 'maquina' => $idMaquina, 'usuario' => Auth::id()]);
    }
    $pdo->commit();

    echo json_encode(['ok' => true, 'data' => ['id_mosi' => $id]], JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    if ($e->getCode() === '23000') {
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'La serie o la clave ya se encuentra registrada.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => Security::publicError($e, 'mosi_guardar')], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => Security::publicError($e, 'mosi_guardar')], JSON_UNESCAPED_UNICODE);
}

function selfNull(mixed $value): ?string
{
    $text = trim((string) $value);
    return $text === '' ? null : $text;
}
