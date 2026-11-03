<?php
declare(strict_types=1);

namespace App\Maquinas;

use PDO;

final class ActivacionMaquina
{
    public static function asegurarColumnas(PDO $pdo): void
    {
        $pdo->exec("
            IF COL_LENGTH('vendingm.maquinas', 'nombre_maquina') IS NULL
                ALTER TABLE vendingm.maquinas ADD nombre_maquina NVARCHAR(160) NULL;
            IF COL_LENGTH('vendingm.maquinas', 'id_sucursal_clinica') IS NULL
                ALTER TABLE vendingm.maquinas ADD id_sucursal_clinica INT NULL;
            IF COL_LENGTH('vendingm.maquinas', 'activada') IS NULL
                ALTER TABLE vendingm.maquinas ADD activada BIT NOT NULL CONSTRAINT DF_maquinas_activada DEFAULT 0;
            IF COL_LENGTH('vendingm.maquinas', 'fecha_activacion') IS NULL
                ALTER TABLE vendingm.maquinas ADD fecha_activacion DATETIME2 NULL;
            IF COL_LENGTH('vendingm.maquinas', 'fecha_ultima_activacion') IS NULL
                ALTER TABLE vendingm.maquinas ADD fecha_ultima_activacion DATETIME2 NULL;
            IF COL_LENGTH('vendingm.maquinas', 'activada_por_app_version') IS NULL
                ALTER TABLE vendingm.maquinas ADD activada_por_app_version NVARCHAR(50) NULL;
            IF COL_LENGTH('vendingm.maquinas', 'permitir_reactivacion') IS NULL
                ALTER TABLE vendingm.maquinas ADD permitir_reactivacion BIT NOT NULL CONSTRAINT DF_maquinas_permitir_reactivacion DEFAULT 0;
            IF COL_LENGTH('vendingm.maquinas', 'id_tecnico_instalador') IS NULL
                ALTER TABLE vendingm.maquinas ADD id_tecnico_instalador INT NULL;
            IF COL_LENGTH('vendingm.maquinas', 'tecnico_instalador_nombre') IS NULL
                ALTER TABLE vendingm.maquinas ADD tecnico_instalador_nombre NVARCHAR(180) NULL;
        ");
    }

    /** @return list<array{id_sucursal_clinica:int,nombre:string}> */
    public static function buscarSucursales(PDO $pdo, string $texto, int $limite = 20): array
    {
        $limite = max(1, min(50, $limite));
        $sql = "SELECT TOP {$limite}
                    id_SucursalClinica AS id_sucursal_clinica,
                    st_Nombre AS nombre
                FROM fernandoruiz.cat_SucursalClinica
                WHERE i_Activo = 1
                  AND (:texto_vacio = '' OR st_Nombre LIKE :texto)
                ORDER BY
                    CASE WHEN st_Nombre LIKE :inicio THEN 0 ELSE 1 END,
                    st_Nombre";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'texto_vacio' => $texto,
            'texto' => '%' . $texto . '%',
            'inicio' => $texto . '%',
        ]);
        return array_map(static fn(array $fila): array => [
            'id_sucursal_clinica' => (int) $fila['id_sucursal_clinica'],
            'nombre' => trim((string) $fila['nombre']),
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public static function obtenerSucursalActiva(PDO $pdo, int $idSucursal): ?array
    {
        $stmt = $pdo->prepare(
            "SELECT id_SucursalClinica AS id_sucursal_clinica, st_Nombre AS nombre
             FROM fernandoruiz.cat_SucursalClinica
             WHERE id_SucursalClinica = :id AND i_Activo = 1"
        );
        $stmt->execute(['id' => $idSucursal]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);
        return $fila ? [
            'id_sucursal_clinica' => (int) $fila['id_sucursal_clinica'],
            'nombre' => trim((string) $fila['nombre']),
        ] : null;
    }
}
