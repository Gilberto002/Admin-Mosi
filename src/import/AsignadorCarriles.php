<?php
// src/Import/AsignadorCarriles.php
declare(strict_types=1);
namespace App\Import;
use PDO;
final class AsignadorCarriles
{
    public function __construct(private PDO $pdo) {}
    /**
     * Asigna id_producto a carriles según ubicacion_carril de staging
     * (numero_carril numérico, match exacto con vendingm.carriles.numero_carril).
     *
     * Solo toma filas de staging ya procesadas sin error (procesado=1,
     * error_procesamiento IS NULL, id_producto IS NOT NULL). Usa
     * directamente s.id_producto -- lo que CatalogoUpserter::procesar() ya
     * resolvió y guardó en staging (por clave o por codigo_barras) -- en
     * vez de volver a cruzar por codigo_barras contra vendingm.productos:
     * ese cruce fallaba en silencio para filas sin codigo_barras (Excel
     * "solo stock" que confía en la clave, ya cargada antes desde
     * productos.php). Decisión del usuario 2026-09-09.
     *
     * @return array<int, array{codigo_barras:string, ubicacion_carril:string}>
     *         filas cuya ubicacion_carril no matcheó ningún carril existente
     *         para esta máquina (útil para reportar en el script de import).
     */
public function asignar(int $idReabastecimiento, int $idMaquina, int $cabina): array
{
    $stmt = $this->pdo->prepare(
        'SELECT s.codigo_barras, s.clave, s.ubicacion_carril, s.id_producto
         FROM vendingm.staging_productos_excel s
         WHERE s.id_reabastecimiento = :idr
           AND s.procesado = 1
           AND s.error_procesamiento IS NULL
           AND s.id_producto IS NOT NULL
           AND s.cabina = :cabina
           AND s.ubicacion_carril IS NOT NULL
           AND s.ubicacion_carril <> \'\''
    );
    $stmt->execute(['idr' => $idReabastecimiento, 'cabina' => $cabina]);
    $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $upd = $this->pdo->prepare(
        'UPDATE vendingm.carriles
         SET id_producto = :idProducto, fecha_actualizacion = SYSDATETIME()
         WHERE id_maquina = :idMaquina AND numero_carril = :numeroCarril'
    );

    $noMatcheados = [];
    foreach ($filas as $fila) {
        // Identificador para el reporte: codigo_barras si vino, si no la
        // clave (para que "no matcheados" no salga en blanco en un Excel
        // "solo stock").
        $identificador = $fila['codigo_barras'] !== null && $fila['codigo_barras'] !== ''
            ? $fila['codigo_barras']
            : (string) ($fila['clave'] ?? '');
        // No usar filter_var(FILTER_VALIDATE_INT) aquí: rechaza strings con
        // cero a la izquierda (ej. "001") por ambigüedad con notación
        // octal -- exactamente el formato real de los carriles en el Excel
        // (001, 002... 063), así que rechazaba el 100% de las filas. Mismo
        // criterio que ya usaba ExcelPlanimetriaReader::convertirCarril(),
        // nunca se había corregido aquí. Bug real detectado 2026-09-10.
        $textoCarril = trim((string) $fila['ubicacion_carril']);
        $numeroCarril = ($textoCarril !== '' && ctype_digit($textoCarril)) ? (int) $textoCarril : false;
        if ($numeroCarril === false || $numeroCarril <= 0) {
            $noMatcheados[] = ['codigo_barras' => $identificador, 'ubicacion_carril' => (string) $fila['ubicacion_carril']];
            continue;
        }
        if ($cabina === 2 && $numeroCarril > 58) {
            $numeroCarril -= 100;
        }
        $upd->execute(['idProducto' => $fila['id_producto'], 'idMaquina' => $idMaquina, 'numeroCarril' => $numeroCarril]);
        if ($upd->rowCount() === 0) {
            $noMatcheados[] = ['codigo_barras' => $identificador, 'ubicacion_carril' => (string) $numeroCarril];
        }
    }
    return $noMatcheados;
}
} 