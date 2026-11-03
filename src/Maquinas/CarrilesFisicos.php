<?php
declare(strict_types=1);

namespace App\Maquinas;

use PDO;

final class CarrilesFisicos
{
    public static function esNumeroValido(int $numero, int $ultimoNumero): bool
    {
        return $numero > 0 && $numero <= $ultimoNumero && $numero % 10 !== 0;
    }

    /** @return list<int> */
    public static function numerosHasta(int $ultimoNumero): array
    {
        $numeros = [];
        for ($numero = 1; $numero <= $ultimoNumero; $numero++) {
            if (self::esNumeroValido($numero, $ultimoNumero)) {
                $numeros[] = $numero;
            }
        }
        return $numeros;
    }

    public static function cantidadHasta(int $ultimoNumero): int
    {
        return count(self::numerosHasta($ultimoNumero));
    }

    public static function crearFaltantes(PDO $pdo, int $idMaquina, int $ultimoNumero): void
    {
        if ($ultimoNumero < 1) {
            return;
        }

        $sql = "
            ;WITH Numeros AS (
                SELECT 1 AS n
                UNION ALL
                SELECT n + 1 FROM Numeros WHERE n < :ultimo
            )
            INSERT INTO vendingm.carriles (id_maquina, numero_carril)
            SELECT :id_maquina, n
            FROM Numeros
            WHERE n % 10 <> 0
              AND NOT EXISTS (
                  SELECT 1 FROM vendingm.carriles c
                  WHERE c.id_maquina = :id_maquina_busqueda AND c.numero_carril = Numeros.n
              )
            OPTION (MAXRECURSION 500)
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'ultimo' => $ultimoNumero,
            'id_maquina' => $idMaquina,
            'id_maquina_busqueda' => $idMaquina,
        ]);
    }

    public static function eliminarInvalidosVacios(PDO $pdo, ?int $idMaquina = null): int
    {
        $sql = "DELETE FROM vendingm.carriles
                WHERE numero_carril % 10 = 0
                  AND COALESCE(cantidad_actual, 0) = 0
                  AND id_producto IS NULL
                  AND id_lote_actual IS NULL";
        $params = [];
        if ($idMaquina !== null) {
            $sql .= ' AND id_maquina = :id_maquina';
            $params['id_maquina'] = $idMaquina;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public static function contarInvalidosConDatos(PDO $pdo, ?int $idMaquina = null): int
    {
        $sql = "SELECT COUNT(*) FROM vendingm.carriles
                WHERE numero_carril % 10 = 0
                  AND (COALESCE(cantidad_actual, 0) <> 0 OR id_producto IS NOT NULL OR id_lote_actual IS NOT NULL)";
        $params = [];
        if ($idMaquina !== null) {
            $sql .= ' AND id_maquina = :id_maquina';
            $params['id_maquina'] = $idMaquina;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }
}
