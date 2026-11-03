<?php
declare(strict_types=1);

namespace App\Maquinas;

use PDO;

final class ConexionMaquina
{
    public const MINUTOS_ONLINE = 10;

    public static function asegurarColumnas(PDO $pdo): void
    {
        $pdo->exec("
            IF COL_LENGTH('vendingm.maquinas', 'ultima_conexion') IS NULL
                ALTER TABLE vendingm.maquinas ADD ultima_conexion DATETIME2 NULL;
            IF COL_LENGTH('vendingm.maquinas', 'tipo_conexion') IS NULL
                ALTER TABLE vendingm.maquinas ADD tipo_conexion NVARCHAR(50) NULL;
            IF COL_LENGTH('vendingm.maquinas', 'version_app') IS NULL
                ALTER TABLE vendingm.maquinas ADD version_app NVARCHAR(50) NULL;
        ");
    }

    public static function registrarLatido(
        PDO $pdo,
        int $idMaquina,
        ?string $tipoConexion,
        ?string $versionApp
    ): string {
        $stmt = $pdo->prepare(
            "UPDATE vendingm.maquinas
             SET ultima_conexion = SYSDATETIME(),
                 tipo_conexion = :tipo_conexion,
                 version_app = :version_app,
                 fecha_actualizacion = SYSDATETIME()
             OUTPUT CONVERT(NVARCHAR(33), INSERTED.ultima_conexion, 126)
             WHERE id_maquina = :id_maquina"
        );
        $stmt->execute([
            'tipo_conexion' => $tipoConexion,
            'version_app' => $versionApp,
            'id_maquina' => $idMaquina,
        ]);
        return (string) $stmt->fetchColumn();
    }
}
