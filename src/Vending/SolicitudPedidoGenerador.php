<?php
// src/Vending/SolicitudPedidoGenerador.php
declare(strict_types=1);

namespace App\Vending;

use PDO;

/**
 * Genera automáticamente una vendingm.solicitudes_pedido cuando un carril cruza
 * el umbral de stock bajo, en tiempo real dentro de la misma transacción que
 * descuenta vendingm.carriles.cantidad_actual (ver
 * App\Dispensacion\SalidaRegistrador::dispensar()).
 *
 * No depende de cat_SucursalClinica/erpanl: es exclusivo del esquema vendingm,
 * pensado para máquinas nuevas sin sucursal real vinculada todavía (a
 * diferencia de TraspasoService/estadisticas.php/vencimientos.php, que sí
 * requieren esa migración -- ver system/adminVending/sql/migracion_traspaso_vending.sql,
 * que a estas máquinas no les aplica).
 *
 * Igual que SalidaRegistrador::asegurarTablas(), las tablas se auto-crean en
 * cada llamada (IF OBJECT_ID... IS NULL) para no depender de que alguien
 * recuerde correr scripts/sql_v5_solicitudes_pedido.sql a mano -- ese script
 * sigue existiendo como documentación/para correrlo explícito si se prefiere,
 * pero no es un requisito.
 */
final class SolicitudPedidoGenerador
{
    // Mismo umbral por defecto que ya usa public/api/alertas_operacion.php
    // para clasificar un carril como "stock_bajo".
    public const UMBRAL_STOCK_BAJO = 5;

    public const ESTATUS_PENDIENTE = 1;
    public const ESTATUS_ENVIADO = 2;
    public const ESTATUS_ENTREGADO = 3;
    public const ESTATUS_CANCELADO = 4;

    // Umbrales por % de vendingm.carriles.capacidad_maxima -- independientes
    // del umbral absoluto de arriba, pedido explicito del usuario 2026-09-29
    // para Stock maquinas/Concentrado general. Sin capacidad_maxima > 0 no se
    // puede calcular %, se omite (ver colorPorPorcentaje()).
    public const PORCENTAJE_AMARILLO_MIN = 50;
    public const PORCENTAJE_AMARILLO_MAX = 60;
    public const PORCENTAJE_ROJO_MAX = 49;
    public const PORCENTAJE_AUTOPEDIDO = 30;

    /**
     * Color a mostrar en UI segun el % ya calculado (0-100). null = fuera de
     * los rangos amarillo/rojo (stock normal, o capacidad_maxima sin configurar).
     */
    public static function colorPorPct(float $pct): ?string
    {
        if ($pct >= self::PORCENTAJE_AMARILLO_MIN && $pct <= self::PORCENTAJE_AMARILLO_MAX) {
            return 'amarillo';
        }
        if ($pct <= self::PORCENTAJE_ROJO_MAX) {
            return 'rojo';
        }
        return null;
    }

    /** Conveniencia cuando se tienen cantidad_actual/capacidad_maxima en vez del % ya calculado. */
    public static function colorPorPorcentaje(int $cantidadActual, int $capacidadMaxima): ?string
    {
        if ($capacidadMaxima <= 0) {
            return null;
        }
        return self::colorPorPct((max(0, $cantidadActual) / $capacidadMaxima) * 100);
    }

    /**
     * Si $cantidadNueva ya está en el umbral (o en cero), crea una solicitud
     * de pedido para ese carril -- salvo que ya exista una pendiente, para no
     * duplicar si el carril sigue bajando en dispensaciones sucesivas.
     */
    public static function generarSiAplica(PDO $pdo, int $idCarril, int $cantidadNueva): void
    {
        if ($cantidadNueva > self::UMBRAL_STOCK_BAJO) {
            return;
        }

        self::asegurarTablas($pdo);

        $stmtExistente = $pdo->prepare(
            'SELECT 1 FROM vendingm.solicitudes_pedido
             WHERE id_carril = :carril AND id_estatus = :pendiente'
        );
        $stmtExistente->execute(['carril' => $idCarril, 'pendiente' => self::ESTATUS_PENDIENTE]);
        if ($stmtExistente->fetchColumn() !== false) {
            return; // ya hay una solicitud abierta para este carril
        }

        $stmtCarril = $pdo->prepare(
            'SELECT ca.id_maquina, l.id_producto
             FROM vendingm.carriles ca
             LEFT JOIN vendingm.lotes l ON l.id_lote = ca.id_lote_actual
             WHERE ca.id_carril = :id'
        );
        $stmtCarril->execute(['id' => $idCarril]);
        $carril = $stmtCarril->fetch(PDO::FETCH_ASSOC);
        if (!$carril) {
            return; // el carril ya no existe -- nada que solicitar
        }

        $stmtInsert = $pdo->prepare(
            'INSERT INTO vendingm.solicitudes_pedido
                (id_maquina, id_carril, id_producto, cantidad_detectada, id_estatus)
             VALUES (:id_maquina, :id_carril, :id_producto, :cantidad, :estatus)'
        );
        $stmtInsert->execute([
            'id_maquina' => (int) $carril['id_maquina'],
            'id_carril' => $idCarril,
            'id_producto' => $carril['id_producto'] !== null ? (int) $carril['id_producto'] : null,
            'cantidad' => $cantidadNueva,
            'estatus' => self::ESTATUS_PENDIENTE,
        ]);
    }

    /**
     * Al tocar 30% de capacidad_maxima (o menos), crea el pedido REAL en
     * CEDIS de inmediato, SIN aprobacion de un admin -- decision explicita
     * del usuario 2026-09-29 (distinto del umbral absoluto de arriba, que
     * solo deja la solicitud PENDIENTE para que un admin la mande a mano).
     * Reutiliza el mismo motor que public/api/solicitud_pedido_manual.php
     * (resolucion de producto por codigo_barras -> UPC,
     * PedidoCedisService::crearPedido()) y la cantidad a pedir es lo que
     * falta para llenar el carril (capacidad_maxima - cantidad actual).
     *
     * IMPORTANTE: este metodo abre su propia transaccion via
     * PedidoCedisService::crearPedido() -- el llamador NUNCA debe invocarlo
     * dentro de una transaccion ya abierta en $pdo (SQL Server/PDO no
     * soporta transacciones anidadas). Ver SalidaRegistrador::dispensar(),
     * que lo llama justo DESPUES de comitear la suya.
     *
     * Guard de duplicados: si ya hay una solicitud ENVIADA para este carril
     * sin fecha_resuelta (pedido anterior todavia en transito/sin recibir),
     * no crea otra -- evita mandar un pedido nuevo en cada dispensacion
     * mientras el carril se mantiene por debajo del 30%.
     */
    public static function autopedirSiTocaUmbral(PDO $pdo, int $idCarril, int $cantidadNueva): void
    {
        $cantidadNueva = max(0, $cantidadNueva);

        $stmtCarril = $pdo->prepare(
            'SELECT ca.capacidad_maxima, ca.id_maquina, m.id_sucursal_clinica,
                    COALESCE(l.id_producto, ca.id_producto) AS id_producto
             FROM vendingm.carriles ca
             INNER JOIN vendingm.maquinas m ON m.id_maquina = ca.id_maquina
             LEFT JOIN vendingm.lotes l ON l.id_lote = ca.id_lote_actual
             WHERE ca.id_carril = :id'
        );
        $stmtCarril->execute(['id' => $idCarril]);
        $carril = $stmtCarril->fetch(PDO::FETCH_ASSOC);
        if (!$carril) {
            return;
        }

        $capacidadMaxima = (int) $carril['capacidad_maxima'];
        if ($capacidadMaxima <= 0) {
            return; // sin capacidad configurada, no se puede evaluar %
        }
        if (($cantidadNueva / $capacidadMaxima) * 100 > self::PORCENTAJE_AUTOPEDIDO) {
            return; // todavia no toca el umbral de autopedido
        }

        self::asegurarTablas($pdo);

        $stmtEnviada = $pdo->prepare(
            'SELECT 1 FROM vendingm.solicitudes_pedido
             WHERE id_carril = :carril AND id_estatus = :enviado AND fecha_resuelta IS NULL'
        );
        $stmtEnviada->execute(['carril' => $idCarril, 'enviado' => self::ESTATUS_ENVIADO]);
        if ($stmtEnviada->fetchColumn() !== false) {
            return; // ya hay un pedido en transito para este carril
        }

        $idSucursalClinica = $carril['id_sucursal_clinica'] !== null ? (int) $carril['id_sucursal_clinica'] : null;
        $idProducto = $carril['id_producto'] !== null ? (int) $carril['id_producto'] : null;
        if ($idSucursalClinica === null || $idProducto === null) {
            return; // sin sucursal vinculada o sin producto identificado -- se revisa a mano
        }

        $stmtCB = $pdo->prepare('SELECT codigo_barras FROM vendingm.productos WHERE id_producto = :id');
        $stmtCB->execute(['id' => $idProducto]);
        $codigoBarras = trim((string) $stmtCB->fetchColumn());
        if ($codigoBarras === '') {
            return;
        }

        $stmtProd = $pdo->prepare('SELECT id_ProductoAlmacen FROM cat_ProductosAlmacenMaster WHERE id_UPC = :upc');
        $stmtProd->execute(['upc' => $codigoBarras]);
        $idProductoAlmacen = $stmtProd->fetchColumn();
        if (!$idProductoAlmacen) {
            return;
        }

        $cantidadPedir = $capacidadMaxima - $cantidadNueva;
        if ($cantidadPedir <= 0) {
            return;
        }

        // Reutiliza la solicitud PENDIENTE que generarSiAplica() ya haya
        // creado para este carril (si el umbral absoluto de 5 unidades
        // tambien aplico), en vez de abrir una segunda -- mismo criterio
        // que solicitud_pedido_manual.php.
        $stmtPendiente = $pdo->prepare(
            'SELECT id_solicitud FROM vendingm.solicitudes_pedido
             WHERE id_carril = :carril AND id_estatus = :pendiente'
        );
        $stmtPendiente->execute(['carril' => $idCarril, 'pendiente' => self::ESTATUS_PENDIENTE]);
        $idSolicitud = $stmtPendiente->fetchColumn();

        if ($idSolicitud === false) {
            $stmtInsert = $pdo->prepare(
                'INSERT INTO vendingm.solicitudes_pedido
                    (id_maquina, id_carril, id_producto, cantidad_detectada, id_estatus)
                 OUTPUT INSERTED.id_solicitud
                 VALUES (:id_maquina, :id_carril, :id_producto, :cantidad, :estatus)'
            );
            $stmtInsert->execute([
                'id_maquina' => (int) $carril['id_maquina'],
                'id_carril' => $idCarril,
                'id_producto' => $idProducto,
                'cantidad' => $cantidadNueva,
                'estatus' => self::ESTATUS_PENDIENTE,
            ]);
            $idSolicitud = (int) $stmtInsert->fetchColumn();
        } else {
            $idSolicitud = (int) $idSolicitud;
        }

        try {
            PedidoCedisService::crearPedido($pdo, $idSucursalClinica, [
                $idSolicitud => ['id_producto_almacen' => (int) $idProductoAlmacen, 'cantidad' => $cantidadPedir],
            ]);
        } catch (\Throwable $e) {
            // La solicitud se queda PENDIENTE si el pedido a CEDIS fallo --
            // se puede reintentar a mano desde Pedidos, "Enviar a CEDIS".
            // No se relanza para no afectar al llamador: la dispensacion
            // que disparo esto ya se comiteo antes de llegar aqui.
        }
    }

    public static function asegurarTablas(PDO $pdo): void
    {
        static $verificado = false;
        if ($verificado) {
            return;
        }

        $pdo->exec("
            IF OBJECT_ID('vendingm.estatus_solicitud_pedido', 'U') IS NULL
            BEGIN
                CREATE TABLE vendingm.estatus_solicitud_pedido (
                    id_estatus TINYINT NOT NULL PRIMARY KEY,
                    nombre NVARCHAR(40) NOT NULL
                );
            END
        ");

        $pdo->exec("
            IF NOT EXISTS (SELECT 1 FROM vendingm.estatus_solicitud_pedido WHERE id_estatus = 1)
                INSERT INTO vendingm.estatus_solicitud_pedido (id_estatus, nombre) VALUES (1, 'Pendiente');
            IF NOT EXISTS (SELECT 1 FROM vendingm.estatus_solicitud_pedido WHERE id_estatus = 2)
                INSERT INTO vendingm.estatus_solicitud_pedido (id_estatus, nombre) VALUES (2, 'Enviado');
            IF NOT EXISTS (SELECT 1 FROM vendingm.estatus_solicitud_pedido WHERE id_estatus = 3)
                INSERT INTO vendingm.estatus_solicitud_pedido (id_estatus, nombre) VALUES (3, 'Entregado');
            IF NOT EXISTS (SELECT 1 FROM vendingm.estatus_solicitud_pedido WHERE id_estatus = 4)
                INSERT INTO vendingm.estatus_solicitud_pedido (id_estatus, nombre) VALUES (4, 'Cancelado');
        ");

        $pdo->exec("
            IF OBJECT_ID('vendingm.solicitudes_pedido', 'U') IS NULL
            BEGIN
                CREATE TABLE vendingm.solicitudes_pedido (
                    id_solicitud INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_solicitudes_pedido PRIMARY KEY,
                    id_maquina INT NOT NULL CONSTRAINT FK_solicitudes_maquina REFERENCES vendingm.maquinas(id_maquina),
                    id_carril INT NOT NULL CONSTRAINT FK_solicitudes_carril REFERENCES vendingm.carriles(id_carril),
                    id_producto INT NULL CONSTRAINT FK_solicitudes_producto REFERENCES vendingm.productos(id_producto),
                    cantidad_detectada INT NOT NULL,
                    id_estatus TINYINT NOT NULL CONSTRAINT DF_solicitudes_estatus DEFAULT 1
                        CONSTRAINT FK_solicitudes_estatus REFERENCES vendingm.estatus_solicitud_pedido(id_estatus),
                    fecha_creacion DATETIME2(0) NOT NULL CONSTRAINT DF_solicitudes_fecha_creacion DEFAULT SYSDATETIME(),
                    fecha_resuelta DATETIME2(0) NULL,
                    resuelto_por INT NULL CONSTRAINT FK_solicitudes_resuelto_por REFERENCES vendingm.usuarios(id_usuario),
                    notas NVARCHAR(500) NULL
                );
                CREATE INDEX IX_solicitudes_pedido_estatus ON vendingm.solicitudes_pedido (id_estatus, fecha_creacion);
            END
        ");

        // id_pedido_cedis1: referencia al pedido real creado en
        // tbl_PedidosCedis1 (App\Vending\PedidoCedisService), para poder
        // encontrarlo despues y confirmar su recepcion real
        // (App\Vending\RecepcionPedidoService) sin depender de parsear el
        // folio de la columna notas. Columna agregada en caliente (ALTER)
        // para no requerir una migracion aparte en instalaciones que ya
        // tengan la tabla creada -- mismo patron self-healing que el resto
        // de este metodo.
        $pdo->exec("
            IF COL_LENGTH('vendingm.solicitudes_pedido', 'id_pedido_cedis1') IS NULL
            BEGIN
                ALTER TABLE vendingm.solicitudes_pedido ADD id_pedido_cedis1 INT NULL;
            END
        ");

        $verificado = true;
    }
}
