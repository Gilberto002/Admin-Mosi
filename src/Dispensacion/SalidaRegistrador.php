<?php
// src/Dispensacion/SalidaRegistrador.php
declare(strict_types=1);

namespace App\Dispensacion;

use App\Vending\SolicitudPedidoGenerador;
use PDO;

final class SalidaRegistrador
{
    // vendingm.tipos_movimiento — solo los que tienen es_salida = 1.
    // Devolución (id 4, es_salida = 0) es una ENTRADA a la máquina, no pasa por
    // esta clase: repone stock igual que un resurtido, no lo descuenta.
    public const TIPO_VENTA     = 1;
    public const TIPO_CADUCADO  = 2;
    public const TIPO_DANADO    = 3;
    public const TIPO_MERMA     = 5;

    /** @var array<int,bool> cache de es_salida por id_tipo_movimiento, para no repetir el SELECT en cada llamada */
    private array $cacheEsSalida = [];

    public function __construct(private PDO $pdo) {}

    /**
     * Descuenta $cantidadSolicitada unidades del carril indicado, aplicando FEFO:
     * toma primero del lote (dentro de ese carril) con fecha_caducidad más próxima,
     * y si no alcanza, sigue con el siguiente lote en orden de caducidad.
     *
     * No asume que solo hay un lote por carril: si un resurtido dejó lotes viejo
     * y nuevo mezclados en el mismo carril, una sola llamada puede generar varias
     * filas en movimientos_salida (una por lote tocado).
     *
     * Usa locks (UPDLOCK, ROWLOCK) dentro de la transacción para que dos
     * dispensaciones simultáneas del mismo carril no lean el mismo stock dos veces.
     *
     * @return array<int, array{id_movimiento:int, id_lote:int, cantidad:int, fecha_caducidad:string}>
     * @throws \RuntimeException si el carril no existe, no tiene stock suficiente,
     *         o si $idTipoMovimiento no está marcado como es_salida=1 en vendingm.tipos_movimiento
     */
    public function dispensar(
        int $idCarril,
        int $cantidadSolicitada,
        int $idTipoMovimiento = self::TIPO_VENTA,
        ?string $observaciones = null,
        ?string $usuario = null
    ): array {
        if ($cantidadSolicitada <= 0) {
            throw new \InvalidArgumentException('La cantidad a dispensar debe ser mayor a 0');
        }

        self::asegurarTablas($this->pdo);

        if (!$this->esTipoDeSalida($idTipoMovimiento)) {
            throw new \InvalidArgumentException(
                "id_tipo_movimiento {$idTipoMovimiento} no está marcado como es_salida=1 en vendingm.tipos_movimiento. " .
                "Si es una entrada (ej. Devolución), esta no es la clase que la maneja: esa lógica va por el lado de resurtido/reposición."
            );
        }

        $movimientos = [];
        $iniciarTransaccion = !$this->pdo->inTransaction();

        if ($iniciarTransaccion) {
            $this->pdo->beginTransaction();
        }
        try {
            $stmtCarril = $this->pdo->prepare(
                'SELECT id_maquina, cantidad_actual FROM vendingm.carriles WITH (UPDLOCK, ROWLOCK)
                 WHERE id_carril = :id'
            );
            $stmtCarril->execute(['id' => $idCarril]);
            $carril = $stmtCarril->fetch(PDO::FETCH_ASSOC);

            if (!$carril) {
                throw new \RuntimeException("Carril {$idCarril} no existe");
            }
            if ((int) $carril['cantidad_actual'] < $cantidadSolicitada) {
                throw new \RuntimeException(
                    "Stock insuficiente en carril {$idCarril}: disponible {$carril['cantidad_actual']}, solicitado {$cantidadSolicitada}"
                );
            }

            $idMaquina = (int) $carril['id_maquina'];

            // Sin reconciliacion contra el ERP legado a proposito (se usaba
            // antes, ver historial): confirmado con el usuario 2026-08-27
            // que la dispensacion real ya NO registra ningun movimiento del
            // lado del ERP -- el stock se dio de baja del ERP en el momento
            // en que salio de la sucursal real hacia la maquina
            // (App\Vending\TraspasoService::cargarStockFisico()), no aqui.
            // vendingm.carriles/lotes es la unica fuente de verdad de la
            // dispensacion real.
            $stmtLotes = $this->pdo->prepare(
                'SELECT id_lote, id_producto, cantidad_actual, fecha_caducidad
                 FROM vendingm.lotes WITH (UPDLOCK, ROWLOCK)
                 WHERE id_carril = :carril AND cantidad_actual > 0
                 ORDER BY fecha_caducidad ASC, id_lote ASC'
            );
            $stmtLotes->execute(['carril' => $idCarril]);
            $lotes = $stmtLotes->fetchAll(PDO::FETCH_ASSOC);

            $restante = $cantidadSolicitada;

            foreach ($lotes as $lote) {
                if ($restante <= 0) {
                    break;
                }

                $tomar = min($restante, (int) $lote['cantidad_actual']);

                $updLote = $this->pdo->prepare(
                    'UPDATE vendingm.lotes SET cantidad_actual = cantidad_actual - :cant WHERE id_lote = :id'
                );
                $updLote->execute(['cant' => $tomar, 'id' => $lote['id_lote']]);

                $ins = $this->pdo->prepare(
                    'INSERT INTO vendingm.movimientos_salida
                     (id_maquina, id_carril, id_producto, id_lote, cantidad, id_tipo_movimiento, usuario, observaciones)
                     OUTPUT INSERTED.id_movimiento
                     VALUES (:maquina, :carril, :producto, :lote, :cantidad, :tipo, :usuario, :observaciones)'
                );
                $ins->execute([
                    'maquina'      => $idMaquina,
                    'carril'       => $idCarril,
                    'producto'     => $lote['id_producto'],
                    'lote'         => $lote['id_lote'],
                    'cantidad'     => $tomar,
                    'tipo'         => $idTipoMovimiento,
                    'usuario'      => $usuario,
                    'observaciones'=> $observaciones,
                ]);

                $movimientos[] = [
                    'id_movimiento'   => (int) $ins->fetchColumn(),
                    'id_lote'         => (int) $lote['id_lote'],
                    'cantidad'        => $tomar,
                    'fecha_caducidad' => $lote['fecha_caducidad'],
                ];

                $restante -= $tomar;
            }

            if ($restante > 0) {
                // Solo puede pasar si carriles.cantidad_actual quedó desincronizada
                // de la suma real de sus lotes. Lo tratamos como bug de datos, no lo
                // dejamos pasar en silencio.
                throw new \RuntimeException(
                    "Inconsistencia de stock: faltaron {$restante} unidades por descontar de lotes en carril {$idCarril}. " .
                    "Revisa que carriles.cantidad_actual coincida con SUM(lotes.cantidad_actual) para ese carril."
                );
            }

            $updCarril = $this->pdo->prepare(
                'UPDATE vendingm.carriles
                 SET cantidad_actual = cantidad_actual - :cant, fecha_actualizacion = SYSDATETIME()
                 WHERE id_carril = :id'
            );
            $updCarril->execute(['cant' => $cantidadSolicitada, 'id' => $idCarril]);

            $cantidadRestanteCarril = (int) $carril['cantidad_actual'] - $cantidadSolicitada;

            // Dispara la solicitud de pedido automática si el carril quedó en
            // el umbral de stock bajo (o en cero) -- misma transacción, para
            // que no se pueda dispensar sin que quede registrada la alerta.
            SolicitudPedidoGenerador::generarSiAplica(
                $this->pdo,
                $idCarril,
                $cantidadRestanteCarril
            );

            if ($iniciarTransaccion) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($iniciarTransaccion && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        // Fuera de la transacción a propósito: PedidoCedisService::crearPedido()
        // (llamado desde autopedirSiTocaUmbral()) abre la suya propia, y SQL
        // Server/PDO no soporta transacciones anidadas -- nunca se puede llamar
        // mientras la de arriba sigue abierta. Solo se intenta cuando esta
        // llamada fue la que controló la transacción (iniciarTransaccion=true);
        // si dispensar() corrió dentro de una transacción más grande manejada
        // por el llamador, la solicitud ya quedó registrada arriba (PENDIENTE)
        // y se manda a CEDIS después, a mano desde Pedidos.
        if ($iniciarTransaccion) {
            SolicitudPedidoGenerador::autopedirSiTocaUmbral($this->pdo, $idCarril, $cantidadRestanteCarril);
        }

        return $movimientos;
    }

    /**
     * Conveniencia para cuando la máquina/API solo conoce id_maquina + codigo_barras
     * (p.ej. un botón que no expone directamente el número de carril). Si el producto
     * está en más de un carril de esa máquina, exige indicar cuál explícitamente
     * (numero_carril) para no adivinar de dónde descontar físicamente.
     */
    public function dispensarPorProducto(
        int $idMaquina,
        string $codigoBarras,
        int $cantidadSolicitada,
        ?int $numeroCarril = null,
        int $idTipoMovimiento = self::TIPO_VENTA,
        ?string $observaciones = null,
        ?string $usuario = null
    ): array {
        $sql = 'SELECT DISTINCT c.id_carril, c.numero_carril
                FROM vendingm.carriles c
                INNER JOIN vendingm.lotes l ON l.id_carril = c.id_carril AND l.cantidad_actual > 0
                INNER JOIN vendingm.productos p ON p.id_producto = l.id_producto
                WHERE c.id_maquina = :maquina AND p.codigo_barras = :codigo';
        $params = ['maquina' => $idMaquina, 'codigo' => $codigoBarras];

        if ($numeroCarril !== null) {
            $sql .= ' AND c.numero_carril = :numcarril';
            $params['numcarril'] = $numeroCarril;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $carriles = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($carriles) === 0) {
            throw new \RuntimeException("No hay carril con stock de '{$codigoBarras}' en la máquina {$idMaquina}");
        }
        if (count($carriles) > 1) {
            $nums = implode(', ', array_column($carriles, 'numero_carril'));
            throw new \RuntimeException(
                "El producto '{$codigoBarras}' está en más de un carril ({$nums}) en la máquina {$idMaquina}; " .
                "especifica numero_carril para no adivinar de cuál descontar."
            );
        }

        return $this->dispensar(
            (int) $carriles[0]['id_carril'],
            $cantidadSolicitada,
            $idTipoMovimiento,
            $observaciones,
            $usuario
        );
    }

    private function esTipoDeSalida(int $idTipoMovimiento): bool
    {
        if (isset($this->cacheEsSalida[$idTipoMovimiento])) {
            return $this->cacheEsSalida[$idTipoMovimiento];
        }

        $stmt = $this->pdo->prepare(
            'SELECT es_salida FROM vendingm.tipos_movimiento WHERE id_tipo_movimiento = :id'
        );
        $stmt->execute(['id' => $idTipoMovimiento]);
        $esSalida = $stmt->fetchColumn();

        if ($esSalida === false) {
            throw new \RuntimeException("id_tipo_movimiento {$idTipoMovimiento} no existe en vendingm.tipos_movimiento");
        }

        return $this->cacheEsSalida[$idTipoMovimiento] = (bool) $esSalida;
    }

    public static function asegurarTablas(PDO $pdo): void
    {
        $pdo->exec("
            IF OBJECT_ID('vendingm.tipos_movimiento', 'U') IS NULL
            BEGIN
                CREATE TABLE vendingm.tipos_movimiento (
                    id_tipo_movimiento INT NOT NULL PRIMARY KEY,
                    nombre NVARCHAR(80) NOT NULL,
                    es_salida BIT NOT NULL,
                    descripcion NVARCHAR(250) NULL
                );
            END
        ");

        $pdo->exec("
            IF NOT EXISTS (SELECT 1 FROM vendingm.tipos_movimiento WHERE id_tipo_movimiento = 1)
                INSERT INTO vendingm.tipos_movimiento (id_tipo_movimiento, nombre, es_salida, descripcion)
                VALUES (1, 'Venta / dispensacion', 1, 'Salida normal por dispensacion de medicamento');
            IF NOT EXISTS (SELECT 1 FROM vendingm.tipos_movimiento WHERE id_tipo_movimiento = 2)
                INSERT INTO vendingm.tipos_movimiento (id_tipo_movimiento, nombre, es_salida, descripcion)
                VALUES (2, 'Caducado', 1, 'Salida por producto caducado');
            IF NOT EXISTS (SELECT 1 FROM vendingm.tipos_movimiento WHERE id_tipo_movimiento = 3)
                INSERT INTO vendingm.tipos_movimiento (id_tipo_movimiento, nombre, es_salida, descripcion)
                VALUES (3, 'Danado', 1, 'Salida por producto danado');
            IF NOT EXISTS (SELECT 1 FROM vendingm.tipos_movimiento WHERE id_tipo_movimiento = 4)
                INSERT INTO vendingm.tipos_movimiento (id_tipo_movimiento, nombre, es_salida, descripcion)
                VALUES (4, 'Devolucion', 0, 'Entrada por devolucion o reposicion');
            IF NOT EXISTS (SELECT 1 FROM vendingm.tipos_movimiento WHERE id_tipo_movimiento = 5)
                INSERT INTO vendingm.tipos_movimiento (id_tipo_movimiento, nombre, es_salida, descripcion)
                VALUES (5, 'Merma', 1, 'Salida por merma operativa');
        ");

        $pdo->exec("
            IF OBJECT_ID('vendingm.movimientos_salida', 'U') IS NULL
            BEGIN
                CREATE TABLE vendingm.movimientos_salida (
                    id_movimiento INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
                    id_maquina INT NOT NULL,
                    id_carril INT NOT NULL,
                    id_producto INT NOT NULL,
                    id_lote INT NOT NULL,
                    cantidad INT NOT NULL,
                    id_tipo_movimiento INT NOT NULL,
                    usuario NVARCHAR(150) NULL,
                    observaciones NVARCHAR(300) NULL,
                    fecha_movimiento DATETIME2 NOT NULL CONSTRAINT DF_mov_salida_fecha DEFAULT SYSDATETIME()
                );
            END
        ");
    }
}
