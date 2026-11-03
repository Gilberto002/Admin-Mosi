<?php

declare(strict_types=1);

namespace App\Vending;

use PDO;

/**
 * Traspaso real de inventario entre una sucursal de farmacia y la
 * "sucursal virtual" que representa su maquina de vending
 * (cat_SucursalClinica.id_SucursalOrigenVending). Puerto a PDO de
 * system/ML/vendingInventario.php -- el modulo que de verdad esta en uso
 * hoy (system/adminVending_v3/php/funciones.php lo llama directo en la
 * accion 'traspasoVending'), no una referencia secundaria. Misma logica de
 * negocio (FIFO por lote/caducidad, mismo rastro de auditoria en
 * tbl_ReporteTraspasoSucursalSucursal/tbl_ProductoTraspaso), con sentencias
 * preparadas en vez de mssql_query suelto.
 *
 * A diferencia de versiones anteriores de esta clase, registrarTraspaso()/
 * registrarCorreccion() YA NO lanzan cuando la existencia no alcanza para
 * cubrir la cantidad completa: mueven lo que sí hay, cierran el folio con
 * una nota de "incompleto" (igual que el legado) y devuelven
 * `completo:false` + `cantidad_movida`, para que el rastro de auditoria no
 * desaparezca si el llamador de todas formas continua y confirma su propia
 * transaccion (algo que el legado, sin transacciones reales, no necesitaba
 * resolver -- cada INSERT se autocommiteaba de inmediato).
 *
 * Tambien corrige un vacio real del sistema legado: "vaciar" un carril o
 * bajar su cantidad JAMAS acreditaba nada de vuelta al ERP (el stock
 * quedaba huerfano, descontado de la sucursal real sin que nada lo
 * recibiera). registrarCorreccion() cubre esa direccion inversa, con un
 * motivo de catalogo propio (19, ver scripts/sql_v4_resurtido.sql) para no
 * reusar el 16/17 (esos textos de catalogo son especificos de la
 * direccion "sucursal -> vending" y quedarian mal etiquetados al reves en
 * Kardex/estadisticas.php).
 *
 * Ronda 2026-08-26: se porto ademas el guard existeCiclicoAbierto() y la
 * sincronizacion de precio actualizarPrecioVentaPorProductoYSucursal(),
 * confirmados contra la definicion real de dbo/cedis.sp_TraspasoCompleto,
 * sp_TraspasoUnitario y sp_CEDActualizaPrecioVentaXProductoYSucursal
 * (compartidas por el usuario, con acceso directo a la BD).
 *
 * Ronda 2026-08-26 (2): se exploro brevemente un modelo de DOS pasos (real
 * -> sucursal virtual -> vending), con evidencia empirica real que lo
 * respaldaba (tbl_ProductoIngreso solo tenia motivo 17 en la virtual). Se
 * implemento, se desplego y se probo en produccion.
 *
 * Ronda 2026-08-27 (a): el usuario, con el modelo de dos pasos ya en
 * produccion, confirmo que como flujo de USUARIO no debia requerir un paso
 * manual previo separado: "cargar stock significa que sale de sucursal y
 * entra a la vending, no existe otro metodo o paso extra, eso seria
 * generar pasos basura". Se probo brevemente un modelo de un solo salto
 * real, sin sucursal virtual en absoluto para "cargar stock", y sin ningun
 * movimiento en el ERP para venta/merma/caducidad real.
 *
 * Ronda 2026-08-27 (b, VERSION FINAL): al probarlo, Kardex/CEDIS dejaron
 * de ver cualquier "entrada" del lado de la maquina, y el usuario aclaro
 * el punto clave que se estaba perdiendo: la sucursal virtual (id
 * SucursalOrigenVending) ES, de hecho, la unica forma en que este ERP
 * legado (que nunca se diseño para dispensadoras de medicamentos) puede
 * "representar" una maquina. La solucion no era eliminarla del todo, sino
 * que "cargar stock" nunca dependiera de que ya tuviera existencia previa
 * (eso fue lo que realmente causaba los "pasos basura"/el bug de "sin
 * existencia" del modelo de dos pasos original). Version final:
 *
 *   - cargarStockFisico() ("cargar carril"): traspaso real COMPLETO en una
 *     sola llamada -- FIFO DIRECTO de la sucursal real (sin depender de
 *     que la virtual tenga nada de antemano) + credito real a la virtual
 *     como efecto de la MISMA llamada. Motivo 20 (salida)/17 (entrada),
 *     folio completo. Practicamente identica a registrarTraspaso(), solo
 *     cambia el motivo de salida (para distinguirla en auditoria de un
 *     traspaso manual) y que acepta una referencia libre para
 *     tbl_ProductoSalida.st_Comentarios.
 *   - registrarCorreccion() (reverso, vaciar/corregir un carril): espejo
 *     exacto en sentido contrario -- FIFO de la virtual, credito a la
 *     real, folio completo. Motivo 19 en ambas direcciones.
 *   - Venta/merma/daño real: sigue SIN registrar nada en el ERP (eso no
 *     cambio) -- App\Dispensacion\SalidaRegistrador::dispensar() no llama
 *     a esta clase para esos casos.
 *   - Retiro por vencimiento: SI vuelve a registrarse en el ERP (a
 *     diferencia de venta/merma) -- ver registrarDevolucionPorVencimiento()
 *     y su llamador en public/api/vencimientos.php (llamada DESPUES de que
 *     el retiro real en vendingm ya se confirmo, no anidada en su
 *     transaccion, para evitar el problema de XACT_ABORT documentado en
 *     ejecutarTraspasoCompleto()/moverLote()).
 *
 * Todos los metodos publicos de traspaso (registrarTraspaso(),
 * registrarCorreccion(), cargarStockFisico()) comparten ahora el mismo
 * motor interno, ejecutarTraspasoCompleto().
 */
final class TraspasoService
{
    // Usuario reservado que identifica en el ledger (tbl_ProductoSalida/Ingreso)
    // cualquier movimiento generado por la vending, igual que el legado y que
    // public/api/vencimientos.php. $idOperador (el admin real de la sesion) se
    // usa unicamente para el campo de auditoria "quien autorizo" en
    // tbl_ProductoTraspaso -- en el legado ese campo SIEMPRE quedaba en 0
    // porque $_SESSION['id_Operador'] nunca se definia en ese flujo.
    public const ID_OPERADOR_VENDING = 9999;

    private const MOTIVO_SALIDA_SUCURSAL = 16;   // Traspaso a Vending
    private const MOTIVO_ENTRADA_VENDING = 17;   // Recepcion de Vending
    private const MOTIVO_DEVOLUCION_VENCIMIENTO = 18; // Devolucion de Vending por Vencimiento (ambas direcciones)
    private const MOTIVO_CORRECCION = 19;        // Correccion de Resurtido Vending (ambas direcciones)
    private const MOTIVO_CARGA_FISICA = 20;       // Carga Fisica a Maquina Vending (salida del almacen virtual)
    private const MOTIVO_RECEPCION_PEDIDO_CEDIS = 21; // Recepcion de Pedido CEDIS (entrada pura, sin salida real)

    // Sucursal "Capacitacion", usada como resguardo/cuarentena de
    // medicamentos caducados -- mismo destino que ya usa
    // system/ML/vendingInventario.php::registrarDevolucionVendingPorVencimiento().
    // El stock vencido NUNCA vuelve a la sucursal real de origen (no debe
    // volver a quedar disponible para venta).
    private const SUCURSAL_CUARENTENA = 40;

    private static bool $esquemaVerificado = false;

    /**
     * Autoheal del esquema que necesita el traspaso -- mismo patrón
     * asegurarTablas()/asegurarColumnas() que ya usa el resto del proyecto
     * (AndroidApi, MaquinaBranding, ProductoCatalogo, SolicitudPedidoGenerador),
     * portado de las migraciones legadas que nunca se corrieron a mano en
     * este ambiente (system/adminVending/sql/migracion_traspaso_vending.sql,
     * scripts/sql_v4_resurtido.sql). Solo agrega la columna y siembra
     * catálogo -- NO crea sucursales virtuales (eso lo hace
     * asegurarSucursalVirtual(), bajo demanda, por sucursal).
     */
    public static function asegurarEsquema(PDO $pdo): void
    {
        if (self::$esquemaVerificado) {
            return;
        }

        // OBJECT_ID SIN prefijo de esquema a propósito: tiene que resolver
        // exactamente la misma tabla que el ALTER TABLE de abajo (también sin
        // prefijo), vía el esquema default de la conexión -- que en este
        // ambiente NO es "dbo". Calificar uno sí y el otro no hace que el
        // chequeo mire una tabla distinta a la que en verdad se altera, y el
        // ALTER se repite cada request creyendo que la columna no existe
        // (bug real encontrado en pruebas: "Column ... specified more than once").
        $pdo->exec("
            IF NOT EXISTS (
                SELECT 1 FROM sys.columns
                WHERE object_id = OBJECT_ID('cat_SucursalClinica') AND name = 'id_SucursalOrigenVending'
            )
            BEGIN
                ALTER TABLE cat_SucursalClinica ADD id_SucursalOrigenVending INT NULL;
            END
        ");

        $pdo->exec("
            IF NOT EXISTS (SELECT 1 FROM Cat_TipoMovimientoCiclico WHERE id_TipoMovimiento = 16 AND id_EntradaSalida = 2)
                INSERT INTO Cat_TipoMovimientoCiclico (id_EntradaSalida, id_TipoMovimiento, st_TipoMovimientoCiclico, dt_FechaRegistro)
                VALUES (2, 16, 'Traspaso a Vending', GETDATE());
            IF NOT EXISTS (SELECT 1 FROM Cat_TipoMovimientoCiclico WHERE id_TipoMovimiento = 17 AND id_EntradaSalida = 1)
                INSERT INTO Cat_TipoMovimientoCiclico (id_EntradaSalida, id_TipoMovimiento, st_TipoMovimientoCiclico, dt_FechaRegistro)
                VALUES (1, 17, 'Recepcion de Vending', GETDATE());
            IF NOT EXISTS (SELECT 1 FROM Cat_TipoMovimientoCiclico WHERE id_TipoMovimiento = 19 AND id_EntradaSalida = 2)
                INSERT INTO Cat_TipoMovimientoCiclico (id_EntradaSalida, id_TipoMovimiento, st_TipoMovimientoCiclico, dt_FechaRegistro)
                VALUES (2, 19, 'Correccion de Resurtido Vending', GETDATE());
            IF NOT EXISTS (SELECT 1 FROM Cat_TipoMovimientoCiclico WHERE id_TipoMovimiento = 19 AND id_EntradaSalida = 1)
                INSERT INTO Cat_TipoMovimientoCiclico (id_EntradaSalida, id_TipoMovimiento, st_TipoMovimientoCiclico, dt_FechaRegistro)
                VALUES (1, 19, 'Recepcion de Correccion de Resurtido', GETDATE());
            IF NOT EXISTS (SELECT 1 FROM Cat_TipoMovimientoCiclico WHERE id_TipoMovimiento = 20 AND id_EntradaSalida = 2)
                INSERT INTO Cat_TipoMovimientoCiclico (id_EntradaSalida, id_TipoMovimiento, st_TipoMovimientoCiclico, dt_FechaRegistro)
                VALUES (2, 20, 'Carga Fisica a Maquina Vending', GETDATE());
            IF NOT EXISTS (SELECT 1 FROM Cat_TipoMovimientoCiclico WHERE id_TipoMovimiento = 21 AND id_EntradaSalida = 1)
                INSERT INTO Cat_TipoMovimientoCiclico (id_EntradaSalida, id_TipoMovimiento, st_TipoMovimientoCiclico, dt_FechaRegistro)
                VALUES (1, 21, 'Recepcion de Pedido CEDIS', GETDATE());
        ");

        self::$esquemaVerificado = true;
    }

    /**
     * Asegura que $idSucursalOrigen tenga su "sucursal virtual" de vending
     * (id_SucursalOrigenVending) para poder traspasarle inventario -- si no
     * existe, la crea clonando la fila real (mismo criterio que la sección 3
     * de migracion_traspaso_vending.sql, que hasta ahora solo cubría
     * sucursales con máquina Intecco ya ligada). Se llama al vincular una
     * máquina vendingm a una sucursal (maquina_guardar.php) para que el
     * admin nunca tenga que tocar SQL a mano.
     *
     * @return int id_SucursalClinica de la fila virtual
     */
    public static function asegurarSucursalVirtual(PDO $pdo, int $idSucursalOrigen): int
    {
        self::asegurarEsquema($pdo);

        $stmt = $pdo->prepare('SELECT id_SucursalClinica FROM cat_SucursalClinica WHERE id_SucursalOrigenVending = :id');
        $stmt->execute(['id' => $idSucursalOrigen]);
        $idVirtual = $stmt->fetchColumn();
        if ($idVirtual) {
            return (int) $idVirtual;
        }

        $ins = $pdo->prepare(
            "INSERT INTO cat_SucursalClinica (
                id_TipoSucursal, st_Nombre, i_Activo, st_Domicilio, st_Telefonos, st_Colonia, id_Ciudad, id_estado, st_cp, st_RFC,
                id_ListaPrecio, id_cliente, st_almacenes, id_RhModeloNegocio, st_Prefix, id_ZonaHoraria, st_clues,
                id_TipoSucursalOperacion, id_TipoPedidoSucursal, id_laboratorio, st_cabinaMedica, st_vendingMachine, f_lat, f_lng,
                id_SucursalOrigenVending
            )
            OUTPUT INSERTED.id_SucursalClinica
            SELECT
                src.id_TipoSucursal, 'VENDING - ' + src.st_Nombre, 1, src.st_Domicilio, src.st_Telefonos, src.st_Colonia,
                src.id_Ciudad, src.id_estado, src.st_cp, src.st_RFC,
                src.id_ListaPrecio, src.id_cliente, src.st_almacenes, src.id_RhModeloNegocio, 'V' + ISNULL(src.st_Prefix, ''),
                src.id_ZonaHoraria, src.st_clues,
                src.id_TipoSucursalOperacion, src.id_TipoPedidoSucursal, src.id_laboratorio, src.st_cabinaMedica,
                src.st_vendingMachine, src.f_lat, src.f_lng,
                src.id_SucursalClinica
            FROM cat_SucursalClinica src
            WHERE src.id_SucursalClinica = :id
              AND NOT EXISTS (SELECT 1 FROM cat_SucursalClinica v WHERE v.id_SucursalOrigenVending = src.id_SucursalClinica)"
        );
        $ins->execute(['id' => $idSucursalOrigen]);
        $idVirtualNuevo = $ins->fetchColumn();

        if (!$idVirtualNuevo) {
            // Carrera con otra petición concurrente que ya la creó (o la
            // sucursal de origen no existe) -- se relee en vez de fallar.
            $stmt->execute(['id' => $idSucursalOrigen]);
            $idVirtualNuevo = $stmt->fetchColumn();
        }

        if (!$idVirtualNuevo) {
            throw new \RuntimeException("No se pudo crear la sucursal virtual de vending para la sucursal {$idSucursalOrigen} (¿existe esa sucursal?).");
        }

        return (int) $idVirtualNuevo;
    }

    /**
     * Resurtido: descuenta FIFO de la sucursal real y acredita a su
     * maquina virtual. Motivo 16 (salida) / 17 (entrada). Delegado en
     * ejecutarTraspasoCompleto() -- ver ese metodo para el detalle del
     * comportamiento de "incompleto".
     *
     * @return array{id_traspaso:int, cantidad_solicitada:int, cantidad_movida:int, completo:bool}
     */
    public static function registrarTraspaso(PDO $pdo, int $idProductoAlmacen, int $cantidad, int $idSucursalOrigen, int $idOperador): array
    {
        if ($cantidad <= 0) {
            throw new \InvalidArgumentException('La cantidad a traspasar debe ser mayor a cero');
        }

        $idSucursalVirtual = self::resolverSucursalVirtual($pdo, $idSucursalOrigen);

        return self::ejecutarTraspasoCompleto(
            $pdo, $idProductoAlmacen, $cantidad, $idSucursalOrigen, $idSucursalVirtual,
            self::MOTIVO_SALIDA_SUCURSAL, self::MOTIVO_ENTRADA_VENDING, 'Traspaso a Vending', $idOperador
        );
    }

    /**
     * Correccion (vaciar/bajar cantidad en el panel, fuera de una
     * dispensacion/merma real -- esa siempre se trata como perdida
     * permanente, no como corregible, confirmado con el usuario
     * 2026-08-27): reverso exacto de cargarStockFisico(), FIFO de la
     * sucursal virtual de vuelta a la real. Motivo 19 en ambas direcciones.
     * Delegado en ejecutarTraspasoCompleto().
     *
     * @return array{id_traspaso:int, cantidad_solicitada:int, cantidad_movida:int, completo:bool}
     */
    public static function registrarCorreccion(PDO $pdo, int $idProductoAlmacen, int $cantidad, int $idSucursalOrigen, int $idOperador): array
    {
        if ($cantidad <= 0) {
            throw new \InvalidArgumentException('La cantidad a corregir debe ser mayor a cero');
        }

        $idSucursalVirtual = self::resolverSucursalVirtual($pdo, $idSucursalOrigen);

        return self::ejecutarTraspasoCompleto(
            $pdo, $idProductoAlmacen, $cantidad, $idSucursalVirtual, $idSucursalOrigen,
            self::MOTIVO_CORRECCION, self::MOTIVO_CORRECCION, 'Correccion de Resurtido Vending', $idOperador
        );
    }

    /**
     * Carga fisica de un carril: traspaso real completo, en una sola
     * llamada -- FIFO DIRECTO de la sucursal real (sin depender de que la
     * virtual tenga nada de antemano, eso fue lo que causaba "sin
     * existencia" en la ronda anterior), y acredita a la sucursal virtual
     * como efecto de la MISMA llamada, no como paso previo separado.
     * Confirmado con el usuario 2026-08-27: 824 (la virtual) es, de hecho,
     * la unica forma en que este ERP legado puede "representar" una
     * maquina de vending -- por eso SI debe quedar una entrada real ahi,
     * a diferencia de la ronda anterior (donde cargarStockFisico() era
     * salida pura, sin virtual). El admin sigue dando un solo clic; la
     * diferencia con registrarTraspaso() es solo el motivo de salida (20,
     * "Carga Fisica a Maquina Vending", para distinguirla en auditoria de
     * un traspaso manual via traspaso_guardar.php) y que acepta una
     * referencia libre para tbl_ProductoSalida.st_Comentarios (ej. "Maquina
     * 15, carril 3").
     *
     * Delegado en ejecutarTraspasoCompleto().
     *
     * @return array{id_traspaso:int, cantidad_solicitada:int, cantidad_movida:int, completo:bool}
     */
    public static function cargarStockFisico(PDO $pdo, int $idProductoAlmacen, int $cantidad, int $idSucursalOrigen, int $idOperador, string $referencia = ''): array
    {
        if ($cantidad <= 0) {
            throw new \InvalidArgumentException('La cantidad a cargar debe ser mayor a cero');
        }

        $idSucursalVirtual = self::resolverSucursalVirtual($pdo, $idSucursalOrigen);

        return self::ejecutarTraspasoCompleto(
            $pdo, $idProductoAlmacen, $cantidad, $idSucursalOrigen, $idSucursalVirtual,
            self::MOTIVO_CARGA_FISICA, self::MOTIVO_ENTRADA_VENDING, 'Carga Fisica a Maquina Vending', $idOperador,
            $referencia !== '' ? $referencia : null
        );
    }

    /**
     * SIN LLAMADOR desde 2026-09-08 (ver comentario de cabecera de
     * RecepcionPedidoService.php, rediseño de ese día): el camino "directo
     * a 23" que llamaba a este método se reemplazó por
     * generarTraspasoPedidoCedis() + el flujo real 22->23, así que ya no
     * hace falta esta entrada sintética con lote "PEDCEDIS-X". Se deja el
     * método por si se necesita retomar -- mismo criterio que
     * registrarSalidaVending() en esta misma clase.
     *
     * Acredita a la sucursal REAL vinculada a una vending la cantidad que
     * CEDIS confirmó para una línea de un pedido (tbl_PedidosCedis1Detalle)
     * -- decisión del usuario 2026-09-07, tras descartar dos alternativas:
     *
     * 1) Acreditar la sucursal VIRTUAL en vez de la real: se descartó
     *    porque la virtual es un acumulado histórico que nunca se vacía
     *    solo (nada la descuenta en operación normal desde que la
     *    dispensación real dejó de tocar el ERP, 2026-08-27) -- no sirve
     *    como "pool disponible" aislado por pedido.
     * 2) Una tabla propia de seguimiento por pedido (cantidad confirmada
     *    vs. cantidad ya cargada): descartada por decisión explícita del
     *    usuario ("se debe trabajar con lo que tenemos", sin tablas nuevas).
     *
     * Por eso esto es una ENTRADA pura a la sucursal real (no un traspaso
     * de dos lados: no hay ninguna sucursal de CEDIS que decrementemos en
     * este ERP -- el pedido mismo, con su folio/detalle/historial de
     * estatus en tbl_CEDEvStatusPedido, ES la trazabilidad de negocio, más
     * robusta que la de un traspaso normal entre sucursales). Aun así SÍ se
     * abre un folio de traspaso auto-referenciado (origen=destino=la misma
     * sucursal) -- descubierto en producción 2026-09-08:
     * tbl_ProductoIngreso.id_ReporteTraspasoSucursalSucursal no admite NULL,
     * así que la tabla exige el folio aunque conceptualmente no haya un
     * segundo lado real; es un requisito de esquema, no un traspaso de
     * negocio de verdad. El lote es identificable
     * como proveniente de un pedido CEDIS (prefijo "PEDCEDIS-"), nunca un
     * lote físico real -- ese se captura después, a mano, cuando el
     * colaborador surte el carril en Resurtir (vendingm.lotes conserva el
     * dato real ahí, sin cambios). La caducidad es un marcador de 1 año
     * (suficiente para ser FIFO-elegible hasta que Resurtir lo consuma),
     * nunca se presenta como fecha real de caducidad.
     *
     * Como la sucursal real también acumula existencia de otras fuentes
     * (igual que la virtual), esto NO garantiza un tope estricto de "nunca
     * cargar más de lo que este pedido trajo" -- el candado de "sin
     * existencia" de cargarStockFisico() sigue siendo el mismo de hoy,
     * best-effort. Aceptado explícitamente por el usuario: en la vida real
     * CEDIS también puede mandar más o menos de lo solicitado.
     *
     * Solo debe llamarse para el camino de recepción directa (10/15 -> 23,
     * ver App\Vending\RecepcionPedidoService) -- el camino completo (22,
     * con traspaso real generado por almacén) NO debe pasar por aquí,
     * porque ese flujo ya tiene su propio mecanismo real de entrada (fuera
     * del alcance de lo que este proyecto pudo verificar) y acreditar
     * también aquí duplicaría la cantidad.
     *
     * @return int id_TipoProducto del lote recién creado en la sucursal real
     */
    public static function acreditarRecepcionPedidoCedis(
        PDO $pdo,
        int $idProductoAlmacen,
        int $cantidad,
        int $idSucursalDestino,
        string $referenciaPedido,
        int $idOperador
    ): int {
        if ($cantidad <= 0) {
            throw new \InvalidArgumentException('La cantidad a acreditar debe ser mayor a cero');
        }

        self::asegurarEsquema($pdo);

        $idTipoProductoOrigen = self::resolverTipoProductoPlantilla($pdo, $idProductoAlmacen, $idSucursalDestino);

        $stmtPrecio = $pdo->prepare('SELECT i_Precio FROM cat_TipoProducto WHERE id_TipoProducto = :id');
        $stmtPrecio->execute(['id' => $idTipoProductoOrigen]);
        $iPrecio = (float) $stmtPrecio->fetchColumn();

        $stLote = 'PEDCEDIS-' . $referenciaPedido;
        $dtFechaVigencia = (new \DateTimeImmutable('+1 year'))->format('Y-m-d');
        $observaciones = "Recepcion de pedido CEDIS #{$referenciaPedido}";

        // tbl_ProductoIngreso.id_ReporteTraspasoSucursalSucursal NO admite NULL (bug real
        // encontrado en producción 2026-09-08) -- aunque esta es una entrada pura sin ninguna
        // sucursal de origen real que decrementar (ver comentario de clase arriba), la tabla
        // exige un folio de traspaso válido. Se abre uno auto-referenciado (origen=destino=la
        // misma sucursal real) solo para satisfacer esa columna y dejar la entrada visible en el
        // reporte de traspasos -- el pedido CEDIS (folio/detalle/tbl_CEDEvStatusPedido) sigue
        // siendo la trazabilidad real de negocio; este folio es un requisito de esquema.
        $idTraspaso = self::abrirTraspaso($pdo, $idSucursalDestino, $idSucursalDestino, self::ID_OPERADOR_VENDING);

        try {
            // $stUbicacion ('' aquí) se clona a cat_TipoProducto.st_Lote2/st_Ubicacion --
            // columnas pensadas para una ubicación física corta (ej. "A1"), no una oración. Un
            // texto largo ahí truena con "String or binary data would be truncated" (mismo bug).
            // El texto descriptivo va en $observaciones, que se clona a st_CRMObservaciones
            // (campo libre pensado para esto).
            $idTipoProductoNuevo = self::registrarEntradaVirtual(
                $pdo, $idProductoAlmacen, $dtFechaVigencia, $stLote,
                '', $cantidad,
                $idTipoProductoOrigen, $idSucursalDestino, self::MOTIVO_RECEPCION_PEDIDO_CEDIS,
                $observaciones, $idTraspaso, $iPrecio
            );

            // Sin esto, el PDF/reporte de traspasos que lee
            // tbl_ReporteTraspasoSucursalSucursalDetail muestra el folio con la tabla de
            // productos vacía (mismo motivo que en moverLote()). $idTipoProductoOrigen (la
            // plantilla clonada, no un lote físico real que se haya descontado) se usa como
            // "old" -- es honesto: de ahí salieron los metadatos del lote nuevo.
            [$costoIVA, $precioMaximo] = self::obtenerCostoProducto($pdo, $idProductoAlmacen);
            self::registrarDetalleTraspaso(
                $pdo, $idTraspaso, $idTipoProductoOrigen, $idTipoProductoNuevo, $cantidad,
                $idSucursalDestino, $idSucursalDestino, $iPrecio, $iPrecio,
                $costoIVA, $precioMaximo, $idOperador, $observaciones
            );
        } catch (\Throwable $e) {
            self::cerrarTraspaso($pdo, $idTraspaso, "{$observaciones} (incompleto: " . $e->getMessage() . ')', self::ID_OPERADOR_VENDING);
            throw $e;
        }

        self::cerrarTraspaso($pdo, $idTraspaso, $observaciones, self::ID_OPERADOR_VENDING);

        return $idTipoProductoNuevo;
    }

    /**
     * Genera el traspaso REAL de un pedido CEDIS que el propio sistema de
     * vending está recibiendo (tbl_ReporteTraspasoCedisSucursal + su
     * detalle + un cat_TipoProducto INACTIVO por línea, listo para que
     * App\Vending\RecepcionPedidoService::recibirConTraspaso() lo active al
     * confirmar la recepción) -- decisión del usuario 2026-09-08, reemplaza
     * el mecanismo de entrada sintética directa (acreditarRecepcionPedidoCedis(),
     * ver comentario ahí).
     *
     * Idempotente por pedido: si YA existe un traspaso para este
     * id_PedidoCedis1 (ej. porque almacén generó uno a mano en el tramo
     * 16-21 que se descubrió el 2026-09-07, o por un reintento previo de
     * este mismo método), lo reutiliza en vez de duplicar.
     *
     * No pretende replicar el paso exacto que usa almacén internamente
     * (nunca confirmado contra una referencia real, ver comentario de
     * RecepcionPedidoService) -- genera un traspaso válido y suficiente
     * para que el resto del flujo real, ya probado, opere. El usuario
     * aceptó explícitamente esta limitación: "la responsabilidad es
     * enteramente de administradores el confirmar por los medios que ellos
     * consideren necesarios adicionales al sistema".
     *
     * @return int id_ReporteTraspasoCedisSucursal (nuevo o ya existente)
     */
    public static function generarTraspasoPedidoCedis(PDO $pdo, int $idPedidoCedis1, int $idSucursal, int $idOperador): int
    {
        $stmtExistente = $pdo->prepare(
            'SELECT TOP 1 id_ReporteTraspasoCedisSucursal FROM tbl_ReporteTraspasoCedisSucursal WHERE id_PedidoCedis1 = :id'
        );
        $stmtExistente->execute(['id' => $idPedidoCedis1]);
        $idTraspasoExistente = $stmtExistente->fetchColumn();
        if ($idTraspasoExistente) {
            return (int) $idTraspasoExistente;
        }

        $insHeader = $pdo->prepare(
            'INSERT INTO tbl_ReporteTraspasoCedisSucursal (id_Sucursal, id_UsuarioSistema, id_PedidoCedis1)
             OUTPUT INSERTED.id_ReporteTraspasoCedisSucursal
             VALUES (:sucursal, :operador, :pedido)'
        );
        $insHeader->execute([
            'sucursal' => $idSucursal,
            'operador' => self::ID_OPERADOR_VENDING,
            'pedido' => $idPedidoCedis1,
        ]);
        $idTraspaso = (int) $insHeader->fetchColumn();
        if (!$idTraspaso) {
            throw new \RuntimeException("No se pudo generar el traspaso formal del pedido CEDIS {$idPedidoCedis1}.");
        }

        $stmtDetalle = $pdo->prepare(
            'SELECT id_ProductoAlmacen, st_Cantidad FROM tbl_PedidosCedis1Detalle WHERE id_PedidoCedis1 = :id'
        );
        $stmtDetalle->execute(['id' => $idPedidoCedis1]);
        foreach ($stmtDetalle->fetchAll(PDO::FETCH_ASSOC) as $linea) {
            $cantidad = (int) $linea['st_Cantidad'];
            if ($cantidad <= 0) {
                continue;
            }
            self::clonarProductoParaTraspasoPedido(
                $pdo, (int) $linea['id_ProductoAlmacen'], $cantidad, $idSucursal, $idTraspaso, (string) $idPedidoCedis1
            );
        }

        return $idTraspaso;
    }

    /**
     * Clona un producto (plantilla, sin lote físico real todavía -- igual
     * criterio que acreditarRecepcionPedidoCedis()) hacia $idSucursalDestino
     * como cat_TipoProducto INACTIVO (id_Status=0) etiquetado a
     * $idTraspaso, más su línea en tbl_ReporteTraspasoCedisSucursalDetail.
     * NO activa nada -- eso lo hace
     * RecepcionPedidoService::recibirConTraspaso() al confirmar la
     * recepción (UPDATE cat_TipoProducto SET id_Status=1 WHERE
     * id_ReporteTraspasoCedisSucursal=:idTraspaso), igual que si almacén
     * hubiera generado el traspaso.
     */
    private static function clonarProductoParaTraspasoPedido(
        PDO $pdo,
        int $idProductoAlmacen,
        int $cantidad,
        int $idSucursalDestino,
        int $idTraspaso,
        string $referenciaPedido
    ): void {
        $idTipoProductoOrigen = self::resolverTipoProductoPlantilla($pdo, $idProductoAlmacen, $idSucursalDestino);

        $stmtPrecio = $pdo->prepare('SELECT i_Precio FROM cat_TipoProducto WHERE id_TipoProducto = :id');
        $stmtPrecio->execute(['id' => $idTipoProductoOrigen]);
        $iPrecio = (float) $stmtPrecio->fetchColumn();

        $stLote = 'PEDCEDIS-' . $referenciaPedido;
        $dtFechaVigencia = (new \DateTimeImmutable('+1 year'))->format('Y-m-d');
        $observaciones = "Traspaso formal del pedido CEDIS #{$referenciaPedido} (pendiente de recepcion)";

        // id_Status=0 (inactivo) a propósito -- este producto NO debe verse
        // como existencia disponible hasta que recibirConTraspaso() lo
        // active. id_ReporteTraspasoCedisSucursal etiqueta el clon con el
        // traspaso al que pertenece, la misma columna que esa activación
        // filtra.
        $insClon = $pdo->prepare(
            'INSERT INTO cat_TipoProducto (
                st_NombreProducto, i_Precio, id_CodigoSAP, id_Sucursal, id_Status, st_NombreTicekt, i_PrecioNomiembro,
                id_TipoMedicamento, i_Punto, i_DineroElectronico, i_Descuento, i_AbonoDineroElectronico, dt_FechaVigencia,
                id_ProductoAlmacen, i_EdicionUnitaria, st_NombreComercial, st_Sustancia, id_UPC, st_Lote, Costo, st_Lote2,
                st_Ubicacion, CodigoProducto, st_CRMObservaciones, i_Costo, i_CostoIVA, id_AlmacenIngresos,
                id_ReporteTraspasoCedisSucursal
            )
            OUTPUT INSERTED.id_TipoProducto
            SELECT
                t1.st_NombreProducto, t1.i_Precio, t1.id_CodigoSAP, :idSucursalDestino, 0, t1.st_NombreTicekt, t1.i_PrecioNomiembro,
                t1.id_TipoMedicamento, t1.i_Punto, 0.10, t1.i_Descuento, t1.i_AbonoDineroElectronico, :dtFechaVigencia,
                t1.id_ProductoAlmacen, t1.i_EdicionUnitaria, t1.st_NombreComercial, t1.st_Sustancia, t1.id_UPC, :stLote, t1.Costo, \'\',
                \'\', t1.CodigoProducto, :stObservaciones, ISNULL(t2.i_Costo, 0), ISNULL(t2.i_CostoIVA, 0), ISNULL(t2.id_AlmacenIngresos, 0),
                :idTraspaso
            FROM cat_TipoProducto t1
            LEFT JOIN view_CEDProductoAlmacenCosto t2 ON t1.id_ProductoAlmacen = t2.id_ProductoAlmacen
            WHERE t1.id_TipoProducto = :idTipoProductoOrigen'
        );
        $insClon->execute([
            'idSucursalDestino' => $idSucursalDestino,
            'dtFechaVigencia' => $dtFechaVigencia,
            'stLote' => $stLote,
            'stObservaciones' => $observaciones,
            'idTipoProductoOrigen' => $idTipoProductoOrigen,
            'idTraspaso' => $idTraspaso,
        ]);
        $idTipoProductoNuevo = (int) $insClon->fetchColumn();
        if (!$idTipoProductoNuevo) {
            throw new \RuntimeException('No se pudo clonar el producto para el traspaso del pedido CEDIS');
        }

        [$costoIVA, $precioMaximo] = self::obtenerCostoProducto($pdo, $idProductoAlmacen);

        $insDetalle = $pdo->prepare(
            'INSERT INTO tbl_ReporteTraspasoCedisSucursalDetail (
                id_ReporteTraspasoCedisSucursal, id_Sucursal, id_Producto, st_Ubicacion, st_Lote, dt_FechaCaducidad,
                st_Cantidad, st_PrecioVenta, id_UsuarioSistema, i_Costo, i_PrecioMax, id_ProductoAlmacenPedido, id_AlmacenIngresos
            ) VALUES (
                :idTraspaso, :idSucursal, :idProducto, :stUbicacion, :stLote, :dtFechaCaducidad,
                :cantidad, :precioVenta, :operador, :costo, :precioMax, :idProductoAlmacenPedido, 0
            )'
        );
        $insDetalle->execute([
            'idTraspaso' => $idTraspaso,
            'idSucursal' => $idSucursalDestino,
            'idProducto' => $idTipoProductoNuevo,
            'stUbicacion' => '99-99-99',
            'stLote' => $stLote,
            'dtFechaCaducidad' => $dtFechaVigencia,
            'cantidad' => $cantidad,
            'precioVenta' => $iPrecio,
            'operador' => self::ID_OPERADOR_VENDING,
            'costo' => $costoIVA,
            'precioMax' => $precioMaximo,
            'idProductoAlmacenPedido' => $idProductoAlmacen,
        ]);
    }

    /**
     * Busca una fila de catálogo (cat_TipoProducto) existente para clonar
     * metadatos (nombre/precio/etc.) de un producto, sin pretender ser un
     * lote físico real -- solo la plantilla que registrarEntradaVirtual()
     * necesita para poder dar de alta el lote nuevo. Preferimos una fila ya
     * activa en la sucursal destino (mismos precios que ya usa); si nunca
     * ha manejado este producto, cae a cualquier fila activa del catálogo.
     */
    private static function resolverTipoProductoPlantilla(PDO $pdo, int $idProductoAlmacen, int $idSucursalDestino): int
    {
        $stmt = $pdo->prepare(
            'SELECT TOP 1 id_TipoProducto FROM cat_TipoProducto
             WHERE id_ProductoAlmacen = :producto AND id_Sucursal = :sucursal AND id_Status = 1
             ORDER BY dt_FechaVigencia DESC'
        );
        $stmt->execute(['producto' => $idProductoAlmacen, 'sucursal' => $idSucursalDestino]);
        $id = $stmt->fetchColumn();
        if ($id) {
            return (int) $id;
        }

        $stmt = $pdo->prepare(
            'SELECT TOP 1 id_TipoProducto FROM cat_TipoProducto
             WHERE id_ProductoAlmacen = :producto AND id_Status = 1
             ORDER BY dt_FechaVigencia DESC'
        );
        $stmt->execute(['producto' => $idProductoAlmacen]);
        $id = $stmt->fetchColumn();
        if (!$id) {
            throw new \RuntimeException(
                "No se encontró ninguna referencia de catálogo (cat_TipoProducto) para el producto {$idProductoAlmacen} -- no se puede acreditar la recepción del pedido CEDIS."
            );
        }

        return (int) $id;
    }

    /**
     * Motor compartido por registrarTraspaso()/registrarCorreccion()/
     * cargarStockFisico(): abre folio, descuenta FIFO de $idSucursalOrigen,
     * acredita a $idSucursalDestino lote por lote (con detalle de
     * auditoria), cierra el folio. YA NO lanza por existencia insuficiente
     * -- mueve lo que si hay, cierra el folio con nota de "incompleto"
     * (igual que el legado) y devuelve completo:false + cantidad_movida,
     * para que el rastro de auditoria no desaparezca si el llamador de
     * todas formas continua y confirma su propia transaccion. Solo lanza
     * para errores genuinos (fallo SQL a medio camino) -- en ese caso
     * tambien cierra con nota antes de relanzar, para no dejar un folio
     * abierto huerfano.
     *
     * @return array{id_traspaso:int, cantidad_solicitada:int, cantidad_movida:int, completo:bool}
     */
    private static function ejecutarTraspasoCompleto(
        PDO $pdo,
        int $idProductoAlmacen,
        int $cantidad,
        int $idSucursalOrigen,
        int $idSucursalDestino,
        int $motivoSalida,
        int $motivoEntrada,
        string $observaciones,
        int $idOperador,
        ?string $referencia = null
    ): array {
        self::verificarSinCiclicoAbierto($pdo, $idSucursalOrigen, $idSucursalDestino);

        $lotes = self::obtenerLotesFifo($pdo, $idProductoAlmacen, $idSucursalOrigen);
        [$costoIVA, $precioMaximo] = self::obtenerCostoProducto($pdo, $idProductoAlmacen);

        $idTraspaso = self::abrirTraspaso($pdo, $idSucursalOrigen, $idSucursalDestino, self::ID_OPERADOR_VENDING);

        $cantidadFaltante = $cantidad;
        try {
            foreach ($lotes as $lote) {
                if ($cantidadFaltante <= 0) {
                    break;
                }
                $cantidadLote = min($cantidadFaltante, (int) $lote['disponibles']);
                if ($cantidadLote <= 0) {
                    continue;
                }

                self::moverLote(
                    $pdo,
                    idTraspaso: $idTraspaso,
                    idTipoProductoOrigen: (int) $lote['id_TipoProducto'],
                    idProductoAlmacen: $idProductoAlmacen,
                    cantidad: $cantidadLote,
                    dtFechaVigencia: $lote['dt_FechaVigencia'],
                    stLote: (string) $lote['st_Lote'],
                    stUbicacion: (string) $lote['st_Ubicacion'],
                    iPrecio: $lote['i_Precio'],
                    idSucursalDestino: $idSucursalDestino,
                    idSucursalOrigenAudit: $idSucursalOrigen,
                    motivoSalida: $motivoSalida,
                    motivoEntrada: $motivoEntrada,
                    observaciones: $observaciones,
                    costoIVA: $costoIVA,
                    precioMaximo: $precioMaximo,
                    idOperadorAudit: $idOperador,
                    referencia: $referencia,
                );

                $cantidadFaltante -= $cantidadLote;
            }
        } catch (\Throwable $e) {
            self::cerrarTraspaso($pdo, $idTraspaso, "{$observaciones} (incompleto: " . $e->getMessage() . ')', self::ID_OPERADOR_VENDING);
            throw $e;
        }

        $cantidadMovida = $cantidad - $cantidadFaltante;
        $completo = $cantidadFaltante <= 0;

        $nota = $completo
            ? $observaciones
            : "{$observaciones} (incompleto: faltaron {$cantidadFaltante} pieza(s))" . self::diagnosticoFaltante($pdo, $idSucursalOrigen, $idProductoAlmacen);
        self::cerrarTraspaso($pdo, $idTraspaso, $nota, self::ID_OPERADOR_VENDING);

        return [
            'id_traspaso' => $idTraspaso,
            'cantidad_solicitada' => $cantidad,
            'cantidad_movida' => $cantidadMovida,
            'completo' => $completo,
        ];
    }

    /**
     * Valida que una sucursal tenga configurada su "sucursal virtual" de
     * vending (id_SucursalOrigenVending) antes de intentar cualquier
     * traspaso -- pensado para llamarse por adelantado (ej. antes de
     * arrancar una importación de Excel completa) y así no dejar cargar
     * stock sin trazabilidad ni fallar a medio archivo. Lanza la misma
     * excepción que registrarTraspaso()/registrarCorreccion() si no está
     * configurada.
     */
    public static function verificarSucursalConfigurada(PDO $pdo, int $idSucursalOrigen): void
    {
        self::resolverSucursalVirtual($pdo, $idSucursalOrigen);
    }

    /**
     * Resuelve el id_ProductoAlmacen real (catálogo de CEDIS) a partir de un
     * código de barras de vendingm.productos, con el mismo criterio que ya
     * usa resurtir_carril.php: el UPC de cat_ProductosAlmacenMaster es el
     * mismo código de barras que se captura en vendingm.productos, no hay
     * una tabla de mapeo aparte. Devuelve null (no lanza) para que el
     * llamador pueda tratarlo como "esta fila no se puede traspasar" sin
     * abortar el resto del lote.
     */
    public static function resolverIdProductoAlmacenPorUpc(PDO $pdo, string $codigoBarras): ?int
    {
        $stmt = $pdo->prepare('SELECT id_ProductoAlmacen FROM cat_ProductosAlmacenMaster WHERE id_UPC = :upc');
        $stmt->execute(['upc' => $codigoBarras]);
        $id = $stmt->fetchColumn();

        return $id ? (int) $id : null;
    }

    /**
     * SIN LLAMADOR desde 2026-08-27 (ver comentario de clase): la
     * dispensacion real ya no registra nada en el ERP. Se deja el metodo
     * por si se necesita retomar.
     *
     * Salida real de la "sucursal virtual" cuando de verdad se dispensa un
     * medicamento (venta/merma/daño) -- puerto de
     * system/ML/vendingInventario.php::registrarSalidaVending(). Sin este
     * método, un traspaso que acredita stock a la sucursal virtual nunca
     * tenía contraparte de descuento: la sucursal virtual acumulaba
     * inventario fantasma para siempre, sin relación con lo que
     * App\Dispensacion\SalidaRegistrador ya descontaba en vendingm.
     *
     * A propósito NO abre/cierra un traspaso sucursal-sucursal (a diferencia
     * de registrarTraspaso()): es una salida real y punto, el producto se
     * fue (se vendió/se perdió), no se movió a ningún otro lado.
     *
     * Best-effort por diseño: el llamador (SalidaRegistrador) debe tratar
     * cualquier excepción de este método como no-bloqueante para la
     * dispensación real -- vendingm.carriles/lotes es la fuente de verdad
     * operativa, esta sucursal virtual es solo para trazabilidad/reportes
     * del lado de CEDIS.
     */
    public static function registrarSalidaVending(PDO $pdo, int $idSucursalOrigen, int $idProductoAlmacen, int $cantidad, int $idOperador): void
    {
        if ($cantidad <= 0) {
            throw new \InvalidArgumentException('La cantidad a descontar debe ser mayor a cero');
        }

        $idSucursalVirtual = self::resolverSucursalVirtual($pdo, $idSucursalOrigen);

        $stmtLote = $pdo->prepare(
            'SELECT TOP 1 id_TipoProducto
             FROM cat_TipoProducto t1
             INNER JOIN INVENTARIO_Global t2 ON t1.id_TipoProducto = t2.id_TipoProducto
             WHERE t1.id_Sucursal = :idSucursalVirtual
               AND t1.id_Status = 1
               AND t1.i_Precio IS NOT NULL
               AND t1.id_ProductoAlmacen = :idProductoAlmacen
               AND t2.disponibles <> 0
               AND t1.i_Precio >= 0
               AND t1.dt_FechaVigencia > GETDATE()
             ORDER BY t1.dt_FechaVigencia, t1.st_Lote, t1.st_Ubicacion'
        );
        $stmtLote->execute(['idSucursalVirtual' => $idSucursalVirtual, 'idProductoAlmacen' => $idProductoAlmacen]);
        $idTipoProducto = $stmtLote->fetchColumn();

        if (!$idTipoProducto) {
            throw new \RuntimeException("No se encontró producto vigente en el inventario virtual de la sucursal {$idSucursalVirtual} para descontar");
        }

        $insSalida = $pdo->prepare(
            'INSERT INTO tbl_ProductoSalida (id_UsuarioWeb, id_Producto, i_Cantidad, i_Venta, id_OperadorCC)
             VALUES (0, :idProducto, :cantidad, 1, :operador)'
        );
        $insSalida->execute([
            'idProducto' => $idTipoProducto,
            'cantidad' => $cantidad,
            'operador' => self::ID_OPERADOR_VENDING,
        ]);

        $updSalida = $pdo->prepare(
            "UPDATE tbl_ProductoIngreso SET i_Salida = 1, id_MotivoSalida = 1, dt_FechaSalida = GETDATE(), id_OperadorVenta = :operador
             WHERE id_Producto = :idProducto"
        );
        $updSalida->execute(['operador' => self::ID_OPERADOR_VENDING, 'idProducto' => $idTipoProducto]);
    }

    /**
     * Reactivado 2026-08-27 (llamador: public/api/vencimientos.php, DESPUES
     * de que el retiro real en vendingm ya se confirmo, no anidado en esa
     * transaccion -- ver comentario ahi sobre XACT_ABORT). A diferencia de
     * venta/merma real (que ya NO se registra en el ERP, ver
     * App\Dispensacion\SalidaRegistrador), el retiro por caducidad SI debe
     * seguir acreditandose a la sucursal virtual.
     *
     * Devuelve a la sucursal de cuarentena (40, "Capacitacion") el
     * inventario virtual de un producto cuando se retira por vencimiento
     * desde la pantalla de Caducos -- puerto de
     * system/ML/vendingInventario.php::registrarDevolucionVendingPorVencimiento(),
     * adaptado para resolver el lote virtual por (sucursal, producto) FIFO
     * en vez de recibir un id_TipoProducto puntual ya conocido (aquí no hay
     * una pantalla de "próximos a vencer" propia que lo entregue -- el
     * llamador es App\Dispensacion\SalidaRegistrador con motivo
     * TIPO_CADUCADO, que solo conoce producto y cantidad).
     *
     * Motivo 18 en ambas direcciones. Igual que registrarSalidaVending(),
     * best-effort: nunca debe bloquear el retiro real en vendingm.
     */
    public static function registrarDevolucionPorVencimiento(PDO $pdo, int $idSucursalOrigen, int $idProductoAlmacen, int $cantidad, int $idOperador): void
    {
        if ($cantidad <= 0) {
            throw new \InvalidArgumentException('La cantidad a devolver debe ser mayor a cero');
        }

        $idSucursalVirtual = self::resolverSucursalVirtual($pdo, $idSucursalOrigen);
        self::verificarSinCiclicoAbierto($pdo, $idSucursalVirtual, self::SUCURSAL_CUARENTENA);

        $stmtLote = $pdo->prepare(
            'SELECT TOP 1 t1.id_TipoProducto, t1.dt_FechaVigencia, t1.st_Lote, t1.st_Ubicacion, t1.i_Precio, t2.disponibles
             FROM cat_TipoProducto t1
             INNER JOIN INVENTARIO_Global t2 ON t1.id_TipoProducto = t2.id_TipoProducto
             WHERE t1.id_Sucursal = :idSucursalVirtual
               AND t1.id_Status = 1
               AND t1.id_ProductoAlmacen = :idProductoAlmacen
               AND t2.disponibles > 0
             ORDER BY t1.dt_FechaVigencia, t1.st_Lote, t1.st_Ubicacion'
        );
        $stmtLote->execute(['idSucursalVirtual' => $idSucursalVirtual, 'idProductoAlmacen' => $idProductoAlmacen]);
        $lote = $stmtLote->fetch();

        if (!$lote) {
            throw new \RuntimeException("No se encontró lote vigente en el inventario virtual de la sucursal {$idSucursalVirtual} para devolver por vencimiento");
        }
        if ($cantidad > (int) $lote['disponibles']) {
            throw new \RuntimeException("Existencia insuficiente en la máquina virtual para devolver esa cantidad (disponible: {$lote['disponibles']})");
        }

        $idTipoProductoVirtual = (int) $lote['id_TipoProducto'];

        $idTraspaso = self::abrirTraspaso($pdo, $idSucursalVirtual, self::SUCURSAL_CUARENTENA, self::ID_OPERADOR_VENDING);

        try {
            $insSalida = $pdo->prepare(
                'INSERT INTO tbl_ProductoSalida (id_UsuarioWeb, id_Producto, i_Cantidad, i_Venta, id_OperadorCC, id_MotivoSalida, id_ProductoAlmacen, id_ReporteTraspasoSucursalSucursal)
                 VALUES (0, :idProducto, :cantidad, 1, :operador, :motivo, :idProductoAlmacen, :idTraspaso)'
            );
            $insSalida->execute([
                'idProducto' => $idTipoProductoVirtual,
                'cantidad' => $cantidad,
                'operador' => self::ID_OPERADOR_VENDING,
                'motivo' => self::MOTIVO_DEVOLUCION_VENCIMIENTO,
                'idProductoAlmacen' => $idProductoAlmacen,
                'idTraspaso' => $idTraspaso,
            ]);

            $updSalida = $pdo->prepare(
                'UPDATE tbl_ProductoIngreso SET i_Salida = 1, id_MotivoSalida = :motivo, dt_FechaSalida = GETDATE(), id_OperadorVenta = :operador
                 WHERE id_Producto = :idProducto'
            );
            $updSalida->execute([
                'motivo' => self::MOTIVO_DEVOLUCION_VENCIMIENTO,
                'operador' => self::ID_OPERADOR_VENDING,
                'idProducto' => $idTipoProductoVirtual,
            ]);

            $idTipoProductoNuevo = self::registrarEntradaVirtual(
                $pdo,
                $idProductoAlmacen,
                $lote['dt_FechaVigencia'],
                (string) $lote['st_Lote'],
                (string) $lote['st_Ubicacion'],
                $cantidad,
                $idTipoProductoVirtual,
                self::SUCURSAL_CUARENTENA,
                self::MOTIVO_DEVOLUCION_VENCIMIENTO,
                'Devolucion de Vending por Vencimiento',
                $idTraspaso,
                (float) $lote['i_Precio']
            );

            [$costoIVA, $precioMaximo] = self::obtenerCostoProducto($pdo, $idProductoAlmacen);
            self::registrarDetalleTraspaso(
                $pdo,
                $idTraspaso,
                $idTipoProductoVirtual,
                $idTipoProductoNuevo,
                $cantidad,
                $idSucursalVirtual,
                self::SUCURSAL_CUARENTENA,
                (float) $lote['i_Precio'],
                (float) $lote['i_Precio'],
                $costoIVA,
                $precioMaximo,
                $idOperador,
                'Devolucion de Vending por Vencimiento'
            );
        } catch (\Throwable $e) {
            self::cerrarTraspaso($pdo, $idTraspaso, 'Devolucion de Vending por Vencimiento (incompleto: ' . $e->getMessage() . ')', self::ID_OPERADOR_VENDING);
            throw $e;
        }

        self::cerrarTraspaso($pdo, $idTraspaso, 'Devolucion de Vending por Vencimiento', self::ID_OPERADOR_VENDING);
    }

    private static function resolverSucursalVirtual(PDO $pdo, int $idSucursalOrigen): int
    {
        // Se asegura aquí (no solo en asegurarSucursalVirtual()) para que
        // registrarTraspaso()/registrarCorreccion()/verificarSucursalConfigurada()
        // nunca truenen con un error crudo de "columna no existe" si el
        // esquema todavía no se tocó en este ambiente.
        self::asegurarEsquema($pdo);

        $stmt = $pdo->prepare(
            'SELECT id_SucursalClinica FROM cat_SucursalClinica
             WHERE id_SucursalOrigenVending = :id AND i_Activo = 1'
        );
        $stmt->execute(['id' => $idSucursalOrigen]);
        $idVirtual = $stmt->fetchColumn();

        if (!$idVirtual) {
            throw new \RuntimeException("La sucursal {$idSucursalOrigen} no tiene una maquina de vending virtual configurada (ver system/adminVending/sql/migracion_traspaso_vending.sql)");
        }

        return (int) $idVirtual;
    }

    /**
     * Bloquea el traspaso si $idSucursalA o $idSucursalB tienen un
     * inventario ciclico abierto -- puerto de existeCiclicoAbierto() del
     * motor real de CEDIS (traspasosucursales.php), confirmado contra
     * tbl_InventarioCiclico_Cedis1 (dt_FechaCierre IS NULL AND id_Status=1).
     * Sin este guard, un traspaso de vending podria mover stock a mitad de
     * un conteo fisico activo en alguna de las dos sucursales, corrompiendolo.
     */
    private static function verificarSinCiclicoAbierto(PDO $pdo, int $idSucursalA, int $idSucursalB): void
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM tbl_InventarioCiclico_Cedis1
             WHERE dt_FechaCierre IS NULL AND id_Status = 1
               AND (id_Sucursal = :a OR id_Sucursal = :b)'
        );
        $stmt->execute(['a' => $idSucursalA, 'b' => $idSucursalB]);

        if ((int) $stmt->fetchColumn() > 0) {
            throw new \RuntimeException(
                "No se puede traspasar: la sucursal {$idSucursalA} o la {$idSucursalB} tienen un inventario cíclico abierto"
            );
        }
    }

    /** @return list<array<string,mixed>> */
    private static function obtenerLotesFifo(PDO $pdo, int $idProductoAlmacen, int $idSucursal): array
    {
        $stmt = $pdo->prepare(
            'SELECT t1.id_TipoProducto, t1.dt_FechaVigencia, t1.st_Lote, t1.st_Ubicacion, t1.i_Precio, t2.disponibles
             FROM cat_TipoProducto t1
             INNER JOIN INVENTARIO_Global t2 ON t1.id_TipoProducto = t2.id_TipoProducto
             WHERE t1.id_Sucursal = :idSucursal
               AND t1.id_Status = 1
               AND t1.id_ProductoAlmacen = :idProductoAlmacen
               AND t2.disponibles > 0
               AND t1.dt_FechaVigencia > GETDATE()
             ORDER BY t1.dt_FechaVigencia, t1.st_Lote, t1.st_Ubicacion'
        );
        $stmt->execute(['idSucursal' => $idSucursal, 'idProductoAlmacen' => $idProductoAlmacen]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array{0: float, 1: float} [iCostoIVA, iPrecioMaximo] */
    private static function obtenerCostoProducto(PDO $pdo, int $idProductoAlmacen): array
    {
        $stmt = $pdo->prepare('SELECT i_CostoIVA, i_PrecioMaximo FROM view_CEDProductoAlmacenCosto WHERE id_ProductoAlmacen = :id');
        $stmt->execute(['id' => $idProductoAlmacen]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);

        return [(float) ($fila['i_CostoIVA'] ?? 0), (float) ($fila['i_PrecioMaximo'] ?? 0)];
    }

    private static function abrirTraspaso(PDO $pdo, int $idSucursalOrigen, int $idSucursalDestino, int $idOperadorSistema): int
    {
        $stmt = $pdo->prepare(
            'INSERT INTO tbl_ReporteTraspasoSucursalSucursal (id_SucursalOrigen, id_SucursalDestino, id_Operador)
             OUTPUT INSERTED.id_ReporteTraspasoSucursalSucursal
             VALUES (:origen, :destino, :operador)'
        );
        $stmt->execute(['origen' => $idSucursalOrigen, 'destino' => $idSucursalDestino, 'operador' => $idOperadorSistema]);
        $id = $stmt->fetchColumn();

        if (!$id) {
            throw new \RuntimeException('No se pudo abrir el traspaso sucursal-sucursal');
        }

        return (int) $id;
    }

    private static function cerrarTraspaso(PDO $pdo, int $idTraspaso, string $observaciones, int $idOperadorSistema): void
    {
        $stmt = $pdo->prepare(
            'UPDATE tbl_ReporteTraspasoSucursalSucursal
             SET id_Status = 2, dt_FechaCierre = GETDATE(), st_Observaciones = :obs, id_Operador = :operador
             WHERE id_ReporteTraspasoSucursalSucursal = :id'
        );
        $stmt->execute(['obs' => $observaciones, 'operador' => $idOperadorSistema, 'id' => $idTraspaso]);
    }

    /**
     * Mueve un lote puntual: salida real del origen + clon/entrada real al
     * destino (mismo lote/caducidad) + detalle de auditoria del traspaso.
     * $idOperadorAudit es el unico lugar donde se usa el admin real de la
     * sesion (Auth::id()) en vez de ID_OPERADOR_VENDING -- ver comentario
     * de clase.
     */
    private static function moverLote(
        PDO $pdo,
        int $idTraspaso,
        int $idTipoProductoOrigen,
        int $idProductoAlmacen,
        int $cantidad,
        mixed $dtFechaVigencia,
        string $stLote,
        string $stUbicacion,
        mixed $iPrecio,
        int $idSucursalDestino,
        int $idSucursalOrigenAudit,
        int $motivoSalida,
        int $motivoEntrada,
        string $observaciones,
        float $costoIVA,
        float $precioMaximo,
        int $idOperadorAudit,
        ?string $referencia = null,
    ): void {
        $idOperadorSistema = self::ID_OPERADOR_VENDING;

        // 1. Salida real del origen.
        $insSalida = $pdo->prepare(
            'INSERT INTO tbl_ProductoSalida (id_UsuarioWeb, id_Producto, i_Cantidad, i_Venta, id_OperadorCC, id_MotivoSalida, id_ProductoAlmacen, id_ReporteTraspasoSucursalSucursal, st_Comentarios)
             VALUES (0, :idProducto, :cantidad, 1, :operador, :motivo, :idProductoAlmacen, :idTraspaso, :comentarios)'
        );
        $insSalida->execute([
            'idProducto' => $idTipoProductoOrigen,
            'cantidad' => $cantidad,
            'operador' => $idOperadorSistema,
            'motivo' => $motivoSalida,
            'idProductoAlmacen' => $idProductoAlmacen,
            'idTraspaso' => $idTraspaso,
            'comentarios' => $referencia !== null && $referencia !== '' ? substr($referencia, 0, 250) : null,
        ]);

        $updSalida = $pdo->prepare(
            'UPDATE tbl_ProductoIngreso SET i_Salida = 1, id_MotivoSalida = :motivo, dt_FechaSalida = GETDATE(), id_OperadorVenta = :operador
             WHERE id_Producto = :idProducto'
        );
        $updSalida->execute(['motivo' => $motivoSalida, 'operador' => $idOperadorSistema, 'idProducto' => $idTipoProductoOrigen]);

        // 2. Clona el producto hacia el destino (mismo lote/caducidad) y registra la entrada real.
        $idTipoProductoNuevo = self::registrarEntradaVirtual(
            $pdo, $idProductoAlmacen, $dtFechaVigencia, $stLote, $stUbicacion, $cantidad,
            $idTipoProductoOrigen, $idSucursalDestino, $motivoEntrada, $observaciones, $idTraspaso,
            (float) $iPrecio
        );

        // 3. Detalle de auditoria (sin esto, el PDF de traspasos que lee
        //    tbl_ReporteTraspasoSucursalSucursalDetail muestra el folio con la
        //    tabla de productos vacia, dando la impresion de que no se efectuo).
        self::registrarDetalleTraspaso(
            $pdo, $idTraspaso, $idTipoProductoOrigen, $idTipoProductoNuevo, $cantidad,
            $idSucursalOrigenAudit, $idSucursalDestino, (float) $iPrecio, (float) $iPrecio,
            $costoIVA, $precioMaximo, $idOperadorAudit, $observaciones
        );
    }

    /**
     * Clona un producto (mismo lote/caducidad) hacia $idSucursalDestino y
     * registra su entrada real. Compartido por moverLote() (traspaso normal)
     * y registrarDevolucionPorVencimiento() (devolucion a cuarentena).
     *
     * @return int id_TipoProducto del clon recien creado en el destino
     */
    private static function registrarEntradaVirtual(
        PDO $pdo,
        int $idProductoAlmacen,
        mixed $dtFechaVigencia,
        string $stLote,
        string $stUbicacion,
        int $cantidad,
        int $idTipoProductoOrigen,
        int $idSucursalDestino,
        int $motivoIngreso,
        string $observaciones,
        ?int $idTraspaso,
        float $iPrecio
    ): int {
        $idOperadorSistema = self::ID_OPERADOR_VENDING;

        $insClon = $pdo->prepare(
            'INSERT INTO cat_TipoProducto (
                st_NombreProducto, i_Precio, id_CodigoSAP, id_Sucursal, id_Status, st_NombreTicekt, i_PrecioNomiembro,
                id_TipoMedicamento, i_Punto, i_DineroElectronico, i_Descuento, i_AbonoDineroElectronico, dt_FechaVigencia,
                id_ProductoAlmacen, i_EdicionUnitaria, st_NombreComercial, st_Sustancia, id_UPC, st_Lote, Costo, st_Lote2,
                st_Ubicacion, CodigoProducto, st_CRMObservaciones, i_Costo, i_CostoIVA, id_AlmacenIngresos
            )
            OUTPUT INSERTED.id_TipoProducto
            SELECT
                t1.st_NombreProducto, t1.i_Precio, t1.id_CodigoSAP, :idSucursalDestino, 1, t1.st_NombreTicekt, t1.i_PrecioNomiembro,
                t1.id_TipoMedicamento, t1.i_Punto, 0.10, t1.i_Descuento, t1.i_AbonoDineroElectronico, :dtFechaVigencia,
                t1.id_ProductoAlmacen, t1.i_EdicionUnitaria, t1.st_NombreComercial, t1.st_Sustancia, t1.id_UPC, :stLote, t1.Costo, :stUbicacion,
                :stUbicacion2, t1.CodigoProducto, :stObservaciones, ISNULL(t2.i_Costo, 0), ISNULL(t2.i_CostoIVA, 0), ISNULL(t2.id_AlmacenIngresos, 0)
            FROM cat_TipoProducto t1
            LEFT JOIN view_CEDProductoAlmacenCosto t2 ON t1.id_ProductoAlmacen = t2.id_ProductoAlmacen
            WHERE t1.id_TipoProducto = :idTipoProductoOrigen'
        );
        $insClon->execute([
            'idSucursalDestino' => $idSucursalDestino,
            'dtFechaVigencia' => $dtFechaVigencia,
            'stLote' => $stLote,
            'stUbicacion' => $stUbicacion,
            'stUbicacion2' => $stUbicacion,
            'stObservaciones' => $observaciones,
            'idTipoProductoOrigen' => $idTipoProductoOrigen,
        ]);
        $idTipoProductoNuevo = $insClon->fetchColumn();
        if (!$idTipoProductoNuevo) {
            throw new \RuntimeException('No se pudo clonar el producto hacia el destino del traspaso');
        }
        $idTipoProductoNuevo = (int) $idTipoProductoNuevo;

        $insEntrada = $pdo->prepare(
            'INSERT INTO tbl_ProductoIngreso (
                id_Producto, i_Cantidad, dt_FechaEntrada, id_OperadorCC, id_ProductoAlmacen, id_Status,
                dt_FechaIngreso, id_OperadorCCIngreso, id_MotivoIngreso, dt_FechaCaducidad, id_Sucursal, id_ReporteTraspasoSucursalSucursal
            ) VALUES (
                :idProducto, :cantidad, GETDATE(), :operador1, :idProductoAlmacen, 1,
                GETDATE(), :operador2, :motivo, :dtFechaCaducidad, :idSucursalDestino, :idTraspaso
            )'
        );
        $insEntrada->execute([
            'idProducto' => $idTipoProductoNuevo,
            'cantidad' => $cantidad,
            'operador1' => $idOperadorSistema,
            'idProductoAlmacen' => $idProductoAlmacen,
            'operador2' => $idOperadorSistema,
            'motivo' => $motivoIngreso,
            'dtFechaCaducidad' => $dtFechaVigencia,
            'idSucursalDestino' => $idSucursalDestino,
            'idTraspaso' => $idTraspaso,
        ]);

        // Puerto de sp_TraspasoUnitario: justo despues de dar de alta el
        // lote, sincroniza el precio de venta de TODOS los demas lotes
        // activos (o con pedido CEDIS pendiente) de este producto en la
        // misma sucursal destino -- sin esto, un resurtido repetido a
        // precios distintos dejaria lotes viejos de la sucursal virtual
        // vendiendose "a la antigua".
        self::actualizarPrecioVentaPorProductoYSucursal($pdo, $idSucursalDestino, $idProductoAlmacen, $iPrecio);

        return $idTipoProductoNuevo;
    }

    /**
     * Puerto de dbo.sp_CEDActualizaPrecioVentaXProductoYSucursal (confirmado
     * por definicion real via sys.objects): actualiza i_Precio/i_PrecioNomiembro
     * de todos los cat_TipoProducto activos, o con un pedido CEDIS pendiente
     * de recibir, de ese producto en esa sucursal -- para que nunca se venda
     * el mismo producto a dos precios distintos segun que lote le toque.
     */
    private static function actualizarPrecioVentaPorProductoYSucursal(PDO $pdo, int $idSucursal, int $idProductoAlmacen, float $iPrecio): void
    {
        $stmt = $pdo->prepare(
            'UPDATE cat_TipoProducto SET i_Precio = :precio, i_PrecioNomiembro = :precio2
             WHERE id_ProductoAlmacen = :idProductoAlmacen AND id_Sucursal = :idSucursal
               AND (
                 id_Status = 1 OR
                 id_ReporteTraspasoCedisSucursal IN (
                     SELECT t1.id_ReporteTraspasoCedisSucursal FROM tbl_ReporteTraspasoCedisSucursal t1
                     INNER JOIN fernandoruiz.cat_SucursalClinica t2 ON t1.id_Sucursal = t2.id_SucursalClinica
                     WHERE t1.i_Recibido = 0 AND t1.id_PedidoCedis1 <> 0
                       AND t2.i_Activo = 1 AND t1.id_Sucursal = :idSucursal2
                 )
               )'
        );
        $stmt->execute([
            'precio' => $iPrecio,
            'precio2' => $iPrecio,
            'idProductoAlmacen' => $idProductoAlmacen,
            'idSucursal' => $idSucursal,
            'idSucursal2' => $idSucursal,
        ]);
    }

    /**
     * Inserta el detalle de auditoria del traspaso
     * (tbl_ProductoTraspaso + tbl_ReporteTraspasoSucursalSucursalDetail).
     * Compartido por moverLote() y registrarDevolucionPorVencimiento().
     */
    private static function registrarDetalleTraspaso(
        PDO $pdo,
        int $idTraspaso,
        int $idTipoProductoOld,
        int $idTipoProductoNew,
        int $cantidad,
        int $idSucursalOld,
        int $idSucursalNew,
        float $iPrecioOrigen,
        float $iPrecioDestino,
        float $costoIVA,
        float $precioMaximo,
        int $idOperadorAudit,
        string $observaciones
    ): void {
        $insDetalleTraspaso = $pdo->prepare(
            'INSERT INTO tbl_ProductoTraspaso (
                id_TipoProductoOld, id_TipoProductoNew, nt_Cantidad, id_SucursalOld, id_SucursalNew,
                id_Operador, id_Autoriza, st_NotasTraspaso, id_TraspasoSucursales
            )
            OUTPUT INSERTED.id_ProductoTraspaso
            VALUES (
                :old, :new, :cantidad, :sucOld, :sucNew,
                :operador, :autoriza, :notas, :idTraspaso
            )'
        );
        $insDetalleTraspaso->execute([
            'old' => $idTipoProductoOld,
            'new' => $idTipoProductoNew,
            'cantidad' => $cantidad,
            'sucOld' => $idSucursalOld,
            'sucNew' => $idSucursalNew,
            // Unico lugar donde se usa el admin real de la sesion (fix vs.
            // legado, que aqui siempre grababa 0 -- ver comentario de clase).
            // id_Operador e id_Autoriza llevan el mismo valor, pero con
            // nombres de parametro distintos: el driver nativo de SQL
            // Server (PDO::ATTR_EMULATE_PREPARES => false) no tolera
            // reusar un mismo :nombre dos veces en el mismo SQL (produce
            // "COUNT field incorrect or syntax error").
            'operador' => $idOperadorAudit,
            'autoriza' => $idOperadorAudit,
            'notas' => $observaciones,
            'idTraspaso' => $idTraspaso,
        ]);
        $idProductoTraspaso = $insDetalleTraspaso->fetchColumn();
        if (!$idProductoTraspaso) {
            throw new \RuntimeException('No se pudo registrar el detalle del traspaso (tbl_ProductoTraspaso)');
        }

        $insDetalleReporte = $pdo->prepare(
            'INSERT INTO tbl_ReporteTraspasoSucursalSucursalDetail (
                id_ReporteTraspasoSucursalSucursal, id_Producto, i_Cantidad, id_Traspaso,
                i_PrecioSucOrigen, i_PrecioSucDestino, i_Costo, i_PrecioMax
            ) VALUES (
                :idTraspaso, :idProducto, :cantidad, :idProductoTraspaso,
                :precioOrigen, :precioDestino, :costoIVA, :precioMaximo
            )'
        );
        $insDetalleReporte->execute([
            'idTraspaso' => $idTraspaso,
            'idProducto' => $idTipoProductoNew,
            'cantidad' => $cantidad,
            'idProductoTraspaso' => $idProductoTraspaso,
            'precioOrigen' => $iPrecioOrigen,
            'precioDestino' => $iPrecioDestino,
            'costoIVA' => $costoIVA,
            'precioMaximo' => $precioMaximo,
        ]);
    }

    /**
     * Cuando el FIFO no alcanza, explica si el faltante existe pero esta
     * excluido (vencido/inactivo) del traspaso real -- mismo diagnostico
     * util que ya trae el legado (evita el "pero el badge decia que si
     * habia" sin explicacion).
     */
    private static function diagnosticoFaltante(PDO $pdo, int $idSucursalOrigen, int $idProductoAlmacen): string
    {
        $stmt = $pdo->prepare(
            'SELECT
                SUM(CASE WHEN t1.id_Status = 1 AND t1.dt_FechaVigencia <= GETDATE() THEN t2.disponibles ELSE 0 END) AS vencido,
                SUM(CASE WHEN t1.id_Status <> 1 THEN t2.disponibles ELSE 0 END) AS inactivo
             FROM cat_TipoProducto t1
             INNER JOIN INVENTARIO_Global t2 ON t1.id_TipoProducto = t2.id_TipoProducto
             WHERE t1.id_Sucursal = :idSucursal
               AND t1.id_ProductoAlmacen = :idProductoAlmacen
               AND t2.disponibles > 0'
        );
        $stmt->execute(['idSucursal' => $idSucursalOrigen, 'idProductoAlmacen' => $idProductoAlmacen]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$fila) {
            return '';
        }

        $nota = '';
        if ((int) $fila['vencido'] > 0) {
            $nota .= ' Hay ' . (int) $fila['vencido'] . ' pieza(s) adicionales en lotes ya vencidos (no se pueden traspasar).';
        }
        if ((int) $fila['inactivo'] > 0) {
            $nota .= ' Hay ' . (int) $fila['inactivo'] . ' pieza(s) en lotes marcados como inactivos.';
        }

        return $nota;
    }
}
