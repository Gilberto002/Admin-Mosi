<?php
// src/Import/ExcelColumnasFaltantesException.php
declare(strict_types=1);

namespace App\Import;

/**
 * Se lanza cuando el Excel de planimetría/stock no trae, ni siquiera por
 * alias (ver EncabezadoAlias), una columna que el resto del flujo de
 * "Cargar stock" necesita para funcionar -- ver
 * ExcelPlanimetriaReader::validarColumnasRequeridas().
 *
 * Antes de esto, una columna faltante no truena: cada campo cae en su
 * valor por defecto ('' o 0) en silencio (ver
 * ExcelPlanimetriaReader::normalizarRegistro()), y el problema solo se
 * manifestaba mucho más abajo en el pipeline como decenas de filas
 * individuales rechazadas (ej. "Cantidad inválida" en TODAS las filas si
 * faltaba la columna de cantidad) -- confuso para diagnosticar. Con esta
 * excepción se detecta upfront, antes de leer ninguna fila, con un mensaje
 * único y claro. Decisión del usuario 2026-09-10.
 */
final class ExcelColumnasFaltantesException extends \RuntimeException
{
    /** @param list<string> $columnasFaltantes nombres legibles de las columnas que faltan */
    public function __construct(public readonly array $columnasFaltantes)
    {
        $lista = implode('", "', $columnasFaltantes);
        parent::__construct(
            "Archivo invalidado por falta de \"{$lista}\", por favor verifique el documento y reinténtelo después."
        );
    }
}
