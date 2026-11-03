<?php

declare(strict_types=1);

namespace App\Carrito;

use App\Auth\Auth;
use App\Catalogo\ProductoCatalogo;
use App\Vending\RecetaFolio;
use PDO;
use RuntimeException;

final class CarritoCompras
{
    private const SESSION_KEY = 'carrito_compras';

    public static function obtener(PDO $pdo): array
    {
        $estado = self::estado();
        return self::hidratar($pdo, $estado);
    }

    public static function seleccionarMaquina(PDO $pdo, int $idMaquina): array
    {
        self::validarAccesoMaquina($pdo, $idMaquina);

        $_SESSION[self::SESSION_KEY] = [
            'id_maquina' => $idMaquina,
            'items' => [],
            'fecha_actualizacion' => date(DATE_ATOM),
        ];

        return self::obtener($pdo);
    }

    public static function agregar(PDO $pdo, int $idMaquina, int $numeroCarril, int $cantidad): array
    {
        if ($cantidad < 1) {
            throw new RuntimeException('La cantidad debe ser mayor a cero.');
        }

        $estado = self::estado();
        if (($estado['id_maquina'] ?? null) !== $idMaquina) {
            self::seleccionarMaquina($pdo, $idMaquina);
            $estado = self::estado();
        } else {
            self::validarAccesoMaquina($pdo, $idMaquina);
        }

        $carril = self::obtenerCarril($pdo, $idMaquina, $numeroCarril);
        $items = $estado['items'] ?? [];
        $actual = (int) ($items[(string) $numeroCarril]['cantidad'] ?? 0);
        $nuevaCantidad = $actual + $cantidad;

        if ($nuevaCantidad > (int) $carril['cantidad_actual']) {
            throw new RuntimeException("Stock insuficiente en el carril {$numeroCarril}.");
        }

        $items[(string) $numeroCarril] = [
            'numero_carril' => $numeroCarril,
            'cantidad' => $nuevaCantidad,
        ];

        self::guardar($idMaquina, $items);
        return self::obtener($pdo);
    }

    public static function actualizar(PDO $pdo, int $numeroCarril, int $cantidad): array
    {
        $estado = self::estado();
        $idMaquina = (int) ($estado['id_maquina'] ?? 0);
        if (!$idMaquina) {
            throw new RuntimeException('Primero selecciona una maquina.');
        }

        $items = $estado['items'] ?? [];
        if ($cantidad <= 0) {
            unset($items[(string) $numeroCarril]);
            self::guardar($idMaquina, $items);
            return self::obtener($pdo);
        }

        $carril = self::obtenerCarril($pdo, $idMaquina, $numeroCarril);
        if ($cantidad > (int) $carril['cantidad_actual']) {
            throw new RuntimeException("Stock insuficiente en el carril {$numeroCarril}.");
        }

        $items[(string) $numeroCarril] = [
            'numero_carril' => $numeroCarril,
            'cantidad' => $cantidad,
        ];
        self::guardar($idMaquina, $items);

        return self::obtener($pdo);
    }

    public static function quitar(PDO $pdo, int $numeroCarril): array
    {
        $estado = self::estado();
        $idMaquina = (int) ($estado['id_maquina'] ?? 0);
        $items = $estado['items'] ?? [];
        unset($items[(string) $numeroCarril]);

        self::guardar($idMaquina ?: null, $items);
        return self::obtener($pdo);
    }

    public static function limpiar(): array
    {
        unset($_SESSION[self::SESSION_KEY]);
        return [
            'id_maquina' => null,
            'maquina' => null,
            'items' => [],
            'totales' => ['items' => 0, 'unidades' => 0],
        ];
    }

    public static function crearReceta(PDO $pdo, ?string $nombrePaciente, ?string $medicoSolicita): array
    {
        $pdo->exec("
            IF COL_LENGTH('vendingm.detalle_receta', 'id_maquina') IS NULL
                ALTER TABLE vendingm.detalle_receta ADD id_maquina INT NULL
        ");

        $estado = self::obtener($pdo);
        $idMaquina = (int) ($estado['id_maquina'] ?? 0);
        $items = $estado['items'] ?? [];

        if (!$idMaquina || !$items) {
            throw new RuntimeException('El carrito esta vacio.');
        }

        self::validarAccesoMaquina($pdo, $idMaquina);
        $itemsValidados = [];

        foreach ($items as $item) {
            $idMaquinaItem = (int) ($item['id_maquina'] ?? $idMaquina);
            $carril = self::obtenerCarril($pdo, $idMaquinaItem, (int) $item['numero_carril']);
            $cantidad = (int) $item['cantidad'];
            if ($cantidad > (int) $carril['cantidad_actual']) {
                throw new RuntimeException("Stock insuficiente en el carril {$item['numero_carril']}.");
            }
            $itemsValidados[] = [
                'id_maquina' => $idMaquinaItem,
                'numero_carril' => (int) $item['numero_carril'],
                'id_producto' => (int) $carril['id_producto'],
                'cantidad' => $cantidad,
            ];
        }

        $pdo->beginTransaction();
        $folioNuevo = RecetaFolio::siguiente($pdo);
        $ins = $pdo->prepare(
            "INSERT INTO vendingm.recetas (folio, codigo_receta, tipo_receta, id_maquina, nombre_paciente, medico_solicita, id_estatus, fecha_creacion)
             OUTPUT INSERTED.id_receta, INSERTED.codigo_receta AS folio
             VALUES (NEWID(), :codigo_receta, 'cliente_carrito', :id_maquina, :paciente, :medico, 1, SYSDATETIME())"
        );
        $ins->execute([
            'codigo_receta' => $folioNuevo,
            'id_maquina' => $idMaquina,
            'paciente' => $nombrePaciente,
            'medico' => $medicoSolicita,
        ]);
        $receta = $ins->fetch(PDO::FETCH_ASSOC);

        $insDetalle = $pdo->prepare(
            "INSERT INTO vendingm.detalle_receta (id_receta, id_maquina, numero_carril, id_producto, cantidad_solicitada)
             VALUES (:id_receta, :id_maquina, :numero_carril, :id_producto, :cantidad)"
        );
        foreach ($itemsValidados as $item) {
            $insDetalle->execute([
                'id_receta' => (int) $receta['id_receta'],
                'id_maquina' => $item['id_maquina'],
                'numero_carril' => $item['numero_carril'],
                'id_producto' => $item['id_producto'],
                'cantidad' => $item['cantidad'],
            ]);
        }
        $pdo->commit();

        self::limpiar();

        return [
            'id_receta' => (int) $receta['id_receta'],
            'folio' => (string) $receta['folio'],
        ];
    }

    private static function estado(): array
    {
        $estado = $_SESSION[self::SESSION_KEY] ?? [];
        return is_array($estado) ? $estado : [];
    }

    private static function guardar(?int $idMaquina, array $items): void
    {
        $_SESSION[self::SESSION_KEY] = [
            'id_maquina' => $idMaquina,
            'items' => $items,
            'fecha_actualizacion' => date(DATE_ATOM),
        ];
    }

    private static function hidratar(PDO $pdo, array $estado): array
    {
        $idMaquina = (int) ($estado['id_maquina'] ?? 0);
        $items = $estado['items'] ?? [];
        $maquina = null;
        $itemsHidratados = [];
        $unidades = 0;

        ProductoCatalogo::asegurarColumnas($pdo);

        if ($idMaquina) {
            self::validarAccesoMaquina($pdo, $idMaquina);
            $maquina = self::obtenerMaquina($pdo, $idMaquina);
        }

        foreach ($items as $item) {
            $numeroCarril = (int) ($item['numero_carril'] ?? 0);
            $cantidad = (int) ($item['cantidad'] ?? 0);
            if (!$idMaquina || !$numeroCarril || $cantidad < 1) {
                continue;
            }

            $carril = self::obtenerCarril($pdo, $idMaquina, $numeroCarril);
            $itemsHidratados[] = [
                'numero_carril' => $numeroCarril,
                'cantidad' => $cantidad,
                'stock_disponible' => (int) $carril['cantidad_actual'],
                'id_producto' => (int) $carril['id_producto'],
                'producto_nombre' => (string) $carril['producto_nombre'],
                'descripcion' => $carril['descripcion'] ?: ($carril['presentacion'] ?: null),
                'precio' => $carril['precio'] !== null ? (float) $carril['precio'] : null,
                'imagen_url' => ProductoCatalogo::urlPublica($carril['imagen_path'] ?? null),
                'codigo_barras' => $carril['codigo_barras'],
                'numero_lote' => $carril['numero_lote'],
                'fecha_caducidad' => $carril['fecha_caducidad'],
            ];
            $unidades += $cantidad;
        }

        return [
            'id_maquina' => $idMaquina ?: null,
            'maquina' => $maquina,
            'items' => $itemsHidratados,
            'totales' => [
                'items' => count($itemsHidratados),
                'unidades' => $unidades,
            ],
            'fecha_actualizacion' => $estado['fecha_actualizacion'] ?? null,
        ];
    }

    private static function validarAccesoMaquina(PDO $pdo, int $idMaquina): void
    {
        if (!Auth::canAccessMachine($pdo, $idMaquina)) {
            throw new RuntimeException('No tienes acceso a esta maquina.');
        }
    }

    private static function obtenerMaquina(PDO $pdo, int $idMaquina): ?array
    {
        $stmt = $pdo->prepare(
            "SELECT id_maquina, numero_serie, nombre_ubicacion
             FROM vendingm.maquinas
             WHERE id_maquina = :id"
        );
        $stmt->execute(['id' => $idMaquina]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);

        return $fila ?: null;
    }

    private static function obtenerCarril(PDO $pdo, int $idMaquina, int $numeroCarril): array
    {
        $stmt = $pdo->prepare(
            "SELECT ca.numero_carril, ca.cantidad_actual, l.id_producto, p.nombre AS producto_nombre,
                    p.codigo_barras, p.presentacion, p.descripcion, p.precio, p.imagen_path,
                    l.numero_lote, l.fecha_caducidad
             FROM vendingm.carriles ca
             LEFT JOIN vendingm.lotes l ON l.id_lote = ca.id_lote_actual
             LEFT JOIN vendingm.productos p ON p.id_producto = COALESCE(ca.id_producto, l.id_producto)
             WHERE ca.id_maquina = :id_maquina AND ca.numero_carril = :numero_carril"
        );
        $stmt->execute(['id_maquina' => $idMaquina, 'numero_carril' => $numeroCarril]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$fila || !$fila['id_producto']) {
            throw new RuntimeException("El carril {$numeroCarril} no tiene producto cargado.");
        }

        return $fila;
    }
}
