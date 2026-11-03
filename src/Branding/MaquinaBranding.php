<?php
declare(strict_types=1);

namespace App\Branding;

use PDO;
use RuntimeException;

final class MaquinaBranding
{
    private const DIR_RELATIVO = 'uploads/branding';
    private const MAX_LOGO_BYTES = 5_242_880; // 5 MB
    private const MAX_VIDEO_BYTES = 209_715_200; // 200 MB

    public static function asegurarTabla(PDO $pdo): void
    {
        $pdo->exec("
            IF OBJECT_ID('vendingm.maquina_branding', 'U') IS NULL
            BEGIN
                CREATE TABLE vendingm.maquina_branding (
                    id_maquina INT NOT NULL PRIMARY KEY,
                    logo_path NVARCHAR(500) NULL,
                    video_path NVARCHAR(500) NULL,
                    color_primario CHAR(7) NULL,
                    color_secundario CHAR(7) NULL,
                    color_acento CHAR(7) NULL,
                    activo BIT NOT NULL CONSTRAINT DF_maquina_branding_activo DEFAULT 1,
                    fecha_creacion DATETIME2 NOT NULL CONSTRAINT DF_maquina_branding_creacion DEFAULT SYSDATETIME(),
                    fecha_actualizacion DATETIME2 NULL
                );
            END
        ");
    }

    public static function obtener(PDO $pdo, int $idMaquina): ?array
    {
        self::asegurarTabla($pdo);

        $stmt = $pdo->prepare(
            'SELECT logo_path, video_path, color_primario, color_secundario, color_acento, activo
             FROM vendingm.maquina_branding
             WHERE id_maquina = :id'
        );
        $stmt->execute(['id' => $idMaquina]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);

        return $fila ?: null;
    }

    public static function obtenerParaAndroid(PDO $pdo, int $idMaquina): ?array
    {
        $stmtUnidad = $pdo->prepare(
            'SELECT CASE
                    WHEN rol_maquina = 2 AND id_maquina_padre IS NOT NULL THEN id_maquina_padre
                    ELSE id_maquina
                 END AS id_unidad
             FROM vendingm.maquinas
             WHERE id_maquina = :id'
        );
        $stmtUnidad->execute(['id' => $idMaquina]);
        $idUnidad = (int) ($stmtUnidad->fetchColumn() ?: $idMaquina);

        $fila = self::obtener($pdo, $idUnidad);
        if (!$fila || !(bool) $fila['activo']) {
            return null;
        }

        $logoUrl = self::urlPublica($fila['logo_path'] ?? null);
        $videoUrl = self::urlPublica($fila['video_path'] ?? null);

        if (
            $logoUrl === null
            && $videoUrl === null
            && empty($fila['color_primario'])
            && empty($fila['color_secundario'])
            && empty($fila['color_acento'])
        ) {
            return null;
        }

        return [
            'activo' => true,
            'logo_url' => $logoUrl,
            'video_url' => $videoUrl,
            'colores' => [
                'primario' => $fila['color_primario'] ?: null,
                'secundario' => $fila['color_secundario'] ?: null,
                'acento' => $fila['color_acento'] ?: null,
            ],
        ];
    }

    public static function guardarDesdeRequest(PDO $pdo, int $idMaquina, array $body, array $files): void
    {
        self::asegurarTabla($pdo);
        $actual = self::obtener($pdo, $idMaquina) ?? [];

        $logoPath = $actual['logo_path'] ?? null;
        $videoPath = $actual['video_path'] ?? null;

        if (self::archivoSubido($files, 'logo')) {
            $logoPath = self::guardarArchivo($files['logo'], 'logo', ['image/png', 'image/jpeg', 'image/webp'], self::MAX_LOGO_BYTES);
        }
        if (self::archivoSubido($files, 'video')) {
            $videoPath = self::guardarArchivo($files['video'], 'video', ['video/mp4', 'video/webm', 'video/quicktime'], self::MAX_VIDEO_BYTES);
        }

        $colorPrimario = self::color($body['color_primario'] ?? ($actual['color_primario'] ?? null));
        $colorSecundario = self::color($body['color_secundario'] ?? ($actual['color_secundario'] ?? null));
        $colorAcento = self::color($body['color_acento'] ?? ($actual['color_acento'] ?? null));
        $activoSolicitado = array_key_exists('branding_activo', $body)
            ? filter_var($body['branding_activo'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
            : null;
        $tieneContenido = $logoPath || $videoPath || $colorPrimario || $colorSecundario || $colorAcento;
        $activo = $activoSolicitado === null ? ($tieneContenido ? 1 : 0) : ($activoSolicitado && $tieneContenido ? 1 : 0);

        $params = [
            'id_maquina' => $idMaquina,
            'logo_path' => $logoPath,
            'video_path' => $videoPath,
            'color_primario' => $colorPrimario,
            'color_secundario' => $colorSecundario,
            'color_acento' => $colorAcento,
            'activo' => $activo,
        ];

        $existe = $pdo->prepare('SELECT COUNT(*) FROM vendingm.maquina_branding WHERE id_maquina = :id');
        $existe->execute(['id' => $idMaquina]);

        if ((int) $existe->fetchColumn() > 0) {
            $stmt = $pdo->prepare(
                'UPDATE vendingm.maquina_branding
                 SET logo_path = :logo_path,
                     video_path = :video_path,
                     color_primario = :color_primario,
                     color_secundario = :color_secundario,
                     color_acento = :color_acento,
                     activo = :activo,
                     fecha_actualizacion = SYSDATETIME()
                 WHERE id_maquina = :id_maquina'
            );
            $stmt->execute($params);
            return;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO vendingm.maquina_branding
             (id_maquina, logo_path, video_path, color_primario, color_secundario, color_acento, activo)
             VALUES (:id_maquina, :logo_path, :video_path, :color_primario, :color_secundario, :color_acento, :activo)'
        );
        $stmt->execute($params);
    }

    public static function serializarParaAdmin(?array $fila): array
    {
        if (!$fila) {
            return [
                'logo_url' => null,
                'video_url' => null,
                'color_primario' => null,
                'color_secundario' => null,
                'color_acento' => null,
                'activo' => false,
            ];
        }

        return [
            'logo_url' => self::urlPublica($fila['logo_path'] ?? null),
            'video_url' => self::urlPublica($fila['video_path'] ?? null),
            'color_primario' => $fila['color_primario'] ?: null,
            'color_secundario' => $fila['color_secundario'] ?: null,
            'color_acento' => $fila['color_acento'] ?: null,
            'activo' => (bool) $fila['activo'],
        ];
    }

    private static function archivoSubido(array $files, string $campo): bool
    {
        return isset($files[$campo]) && is_array($files[$campo]) && ($files[$campo]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    }

    private static function guardarArchivo(array $archivo, string $prefijo, array $mimesPermitidos, int $maxBytes): string
    {
        if (($archivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException("No se pudo subir el archivo {$prefijo}");
        }
        if (($archivo['size'] ?? 0) > $maxBytes) {
            throw new RuntimeException("El archivo {$prefijo} excede el tamano permitido");
        }

        $tmp = (string) ($archivo['tmp_name'] ?? '');
        $mime = mime_content_type($tmp) ?: '';
        if (!in_array($mime, $mimesPermitidos, true)) {
            throw new RuntimeException("Tipo de archivo {$prefijo} no permitido");
        }

        $extensiones = [
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            'video/mp4' => 'mp4',
            'video/webm' => 'webm',
            'video/quicktime' => 'mov',
        ];
        $extension = $extensiones[$mime] ?? 'bin';

        $dirPublico = dirname(__DIR__, 2) . '/public/' . self::DIR_RELATIVO;
        if (!is_dir($dirPublico) && !mkdir($dirPublico, 0775, true) && !is_dir($dirPublico)) {
            throw new RuntimeException('No se pudo crear la carpeta de branding');
        }

        $nombre = sprintf('%s_%s.%s', $prefijo, bin2hex(random_bytes(12)), $extension);
        $destino = $dirPublico . '/' . $nombre;
        if (!move_uploaded_file($tmp, $destino)) {
            throw new RuntimeException("No se pudo guardar el archivo {$prefijo}");
        }

        return self::DIR_RELATIVO . '/' . $nombre;
    }

    private static function color(?string $valor): ?string
    {
        $valor = trim((string) $valor);
        if ($valor === '') {
            return null;
        }
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $valor)) {
            throw new RuntimeException('Los colores deben tener formato hexadecimal #RRGGBB');
        }
        return strtoupper($valor);
    }

    private static function urlPublica(?string $path): ?string
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
        foreach (['/api', '/android_clientes'] as $apiDir) {
            if (str_ends_with($scriptDir, $apiDir)) {
                $scriptDir = substr($scriptDir, 0, -strlen($apiDir));
                break;
            }
        }

        return "{$scheme}://{$host}{$scriptDir}/" . ltrim($path, '/');
    }
}
