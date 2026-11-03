<?php

declare(strict_types=1);

namespace App\Database;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Conexion unica (singleton) a SQL Server via PDO + driver sqlsrv.
 *
 * Requisitos en el servidor:
 *  - Extension "sqlsrv" y "pdo_sqlsrv" habilitadas en php.ini
 *    (Microsoft Drivers for PHP for SQL Server)
 *  - ODBC Driver 17 o 18 para SQL Server instalado en el sistema operativo
 */
final class Connection
{
    private static ?PDO $instance = null;

    // No se permite instanciar ni clonar: es un singleton.
    private function __construct()
    {
    }

    public static function get(): PDO
    {
        if (self::$instance === null) {
            self::$instance = self::crearConexion();
        }

        return self::$instance;
    }

    private static function crearConexion(): PDO
    {
        $host = $_ENV['DB_HOST'] ?? '127.0.0.1';
        $port = $_ENV['DB_PORT'] ?? '1433';
        $dbName = $_ENV['DB_NAME'] ?? '';
        $user = $_ENV['DB_USER'] ?? '';
        $pass = $_ENV['DB_PASS'] ?? '';
        $instance = $_ENV['DB_INSTANCE'] ?? '';
        $trustCert = filter_var($_ENV['DB_TRUST_SERVER_CERTIFICATE'] ?? 'true', FILTER_VALIDATE_BOOLEAN);

        // Construye el nombre del servidor (con instancia opcional, ej: HOST\SQLEXPRESS)
        $servidor = $instance !== '' ? "{$host}\\{$instance}" : $host;

        $dsn = sprintf(
            'sqlsrv:Server=%s,%s;Database=%s;TrustServerCertificate=%s',
            $servidor,
            $port,
            $dbName,
            $trustCert ? 'true' : 'false'
        );

        try {
            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);

            return $pdo;
        } catch (PDOException $e) {
            // No exponemos el mensaje crudo de PDO en produccion (puede filtrar datos de conexion).
            self::registrarError($e);

            $esDev = ($_ENV['APP_ENV'] ?? 'production') === 'development';

            throw new RuntimeException(
                $esDev
                    ? 'Error de conexion a la base de datos: ' . $e->getMessage()
                    : 'No fue posible conectar con la base de datos. Intenta mas tarde.'
            );
        }
    }

    private static function registrarError(PDOException $e): void
    {
        $logPath = dirname(__DIR__, 2) . '/logs/db_errors.log';
        $linea = sprintf(
            "[%s] %s%s",
            date('Y-m-d H:i:s'),
            $e->getMessage(),
            PHP_EOL
        );

        // error_log silencioso: si logs/ no es escribible no debe tumbar la app.
        @file_put_contents($logPath, $linea, FILE_APPEND);
    }

    private function __clone(): void
    {
    }

    public function __wakeup(): void
    {
        throw new RuntimeException('No se permite deserializar Connection.');
    }
}
