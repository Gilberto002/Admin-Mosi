<?php

declare(strict_types=1);

namespace App\Catalogo;

use PDO;
use RuntimeException;

final class ProductoCatalogo
{
    private const DIR_RELATIVO = 'uploads/productos';
    private const MAX_IMAGEN_BYTES = 5_242_880;

    public static function asegurarColumnas(PDO $pdo): void
    {
        // Cada sentencia en su propio exec() -- no todas juntas en un solo
        // lote con ';' -- para que si una falla (permisos, dependencia,
        // etc.) las demas igual corran, y para poder diagnosticar cual
        // fallo. Se comprobo 2026-09-09 que el ALTER COLUMN de ensanchar
        // "clave" (ver mas abajo) no estaba surtiendo efecto en el lote
        // combinado -- confirmado con el usuario via INFORMATION_SCHEMA
        // real: seguia en VARCHAR(15) pese a que este metodo ya se habia
        // ejecutado varias veces.
        $pdo->exec("
            IF COL_LENGTH('vendingm.productos', 'descripcion') IS NULL
                ALTER TABLE vendingm.productos ADD descripcion NVARCHAR(500) NULL;
        ");
        $pdo->exec("
            IF COL_LENGTH('vendingm.productos', 'precio') IS NULL
                ALTER TABLE vendingm.productos ADD precio DECIMAL(12,2) NULL;
        ");
        $pdo->exec("
            IF COL_LENGTH('vendingm.productos', 'imagen_path') IS NULL
                ALTER TABLE vendingm.productos ADD imagen_path NVARCHAR(500) NULL;
        ");
        $pdo->exec("
            IF COL_LENGTH('vendingm.productos', 'activo') IS NULL
                ALTER TABLE vendingm.productos ADD activo BIT NOT NULL CONSTRAINT DF_productos_activo DEFAULT 1;
        ");
        // Clave del Cuadro Basico y Catalogo de Insumos del Sector Salud
        // (formato 'NNN.NNN.NNNN.NN', exactamente 15 caracteres, ej.
        // '010.000.5165.00') -- distinta de codigo_barras. Decision del
        // usuario 2026-09-09. VARCHAR(15) es el tamano correcto (asi la
        // habia definido el usuario originalmente): un intento anterior de
        // este archivo la ensancho a VARCHAR(30) por asumir mal que el
        // truncamiento SQLSTATE 22001 visto en produccion venia de aqui --
        // confirmado despues, con datos reales, que la clave SIEMPRE cabe
        // justa en 15 caracteres y el truncamiento real era por
        // nombre/presentacion (demasiado largos, ver CatalogoUpserter/
        // ProductosCatalogoImportador). No volver a ensanchar esta columna
        // sin evidencia real de que haga falta.
        $pdo->exec("
            IF COL_LENGTH('vendingm.productos', 'clave') IS NULL
                ALTER TABLE vendingm.productos ADD clave VARCHAR(15) NULL;
        ");
    }

    public static function urlPublica(?string $path): ?string
    {
        $path = trim((string) $path);
        if ($path === '') {
            return null;
        }
        if (preg_match('/^https?:\/\//i', $path)) {
            return $path;
        }

        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $scheme = $https ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if ($host === '') {
            return $path;
        }

        $scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
        if (str_ends_with($scriptDir, '/api')) {
            $scriptDir = substr($scriptDir, 0, -4);
        }

        return "{$scheme}://{$host}{$scriptDir}/" . ltrim($path, '/');
    }

    public static function guardarImagen(array $archivo): string
    {
        if (($archivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('No se pudo subir la imagen.');
        }
        if (($archivo['size'] ?? 0) > self::MAX_IMAGEN_BYTES) {
            throw new RuntimeException('La imagen excede el tamano permitido de 5 MB.');
        }

        $tmp = (string) ($archivo['tmp_name'] ?? '');
        $mime = mime_content_type($tmp) ?: '';
        $extensiones = [
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
        ];
        if (!isset($extensiones[$mime])) {
            throw new RuntimeException('La imagen debe ser PNG, JPG o WEBP.');
        }

        $dirPublico = dirname(__DIR__, 2) . '/public/' . self::DIR_RELATIVO;
        if (!is_dir($dirPublico) && !mkdir($dirPublico, 0775, true) && !is_dir($dirPublico)) {
            throw new RuntimeException('No se pudo crear la carpeta de productos.');
        }

        $nombre = sprintf('producto_%s.%s', bin2hex(random_bytes(12)), $extensiones[$mime]);
        $destino = $dirPublico . '/' . $nombre;
        if (!move_uploaded_file($tmp, $destino)) {
            throw new RuntimeException('No se pudo guardar la imagen.');
        }

        return self::DIR_RELATIVO . '/' . $nombre;
    }
}
