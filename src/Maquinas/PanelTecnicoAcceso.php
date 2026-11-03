<?php
declare(strict_types=1);

namespace App\Maquinas;

use PDO;

final class PanelTecnicoAcceso
{
    private const MASTER_PASSWORD_SEED_HASH = '$2y$12$0RjZ6dyHrfin8YrXuIX/w.EL5ZtD5etUU4yhUbErjKHRkJLE7D76q';

    public static function asegurarColumnas(PDO $pdo): void
    {
        $pdo->exec("
            IF COL_LENGTH('vendingm.maquinas', 'panel_tecnico_password_hash') IS NULL
                ALTER TABLE vendingm.maquinas ADD panel_tecnico_password_hash NVARCHAR(255) NULL;
            IF COL_LENGTH('vendingm.maquinas', 'panel_tecnico_password_generada') IS NULL
                ALTER TABLE vendingm.maquinas ADD panel_tecnico_password_generada DATETIME2 NULL;
        ");

        $pdo->exec("
            IF OBJECT_ID('vendingm.panel_tecnico_configuracion', 'U') IS NULL
            BEGIN
                CREATE TABLE vendingm.panel_tecnico_configuracion (
                    id_configuracion INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
                    clave NVARCHAR(80) NOT NULL,
                    valor NVARCHAR(MAX) NOT NULL,
                    fecha_creacion DATETIME2 NOT NULL CONSTRAINT DF_panel_tecnico_config_creacion DEFAULT SYSDATETIME(),
                    fecha_actualizacion DATETIME2 NULL,
                    CONSTRAINT UQ_panel_tecnico_config_clave UNIQUE (clave)
                );
            END
        ");

        $stmt = $pdo->prepare(
            "IF NOT EXISTS (SELECT 1 FROM vendingm.panel_tecnico_configuracion WHERE clave = 'master_password_hash')
             BEGIN
                 INSERT INTO vendingm.panel_tecnico_configuracion (clave, valor)
                 VALUES ('master_password_hash', :hash)
             END"
        );
        $stmt->execute(['hash' => self::MASTER_PASSWORD_SEED_HASH]);
    }

    public static function asegurarCredencial(PDO $pdo, int $idMaquina): array
    {
        self::asegurarColumnas($pdo);
        $stmt = $pdo->prepare(
            "SELECT panel_tecnico_password_hash
             FROM vendingm.maquinas
             WHERE id_maquina = :id"
        );
        $stmt->execute(['id' => $idMaquina]);
        $hash = trim((string) ($stmt->fetchColumn() ?: ''));
        if ($hash !== '') {
            return ['hash' => $hash, 'password' => null, 'generada' => false];
        }

        return self::regenerarCredencial($pdo, $idMaquina);
    }

    public static function regenerarCredencial(PDO $pdo, int $idMaquina): array
    {
        self::asegurarColumnas($pdo);
        $password = self::generarPassword();
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare(
            "UPDATE vendingm.maquinas
             SET panel_tecnico_password_hash = :hash,
                 panel_tecnico_password_generada = SYSDATETIME(),
                 fecha_actualizacion = SYSDATETIME()
             WHERE id_maquina = :id"
        );
        $stmt->execute(['hash' => $hash, 'id' => $idMaquina]);

        return ['hash' => $hash, 'password' => $password, 'generada' => true];
    }

    public static function regenerarMaestra(PDO $pdo): array
    {
        self::asegurarColumnas($pdo);
        $password = self::generarPassword();
        $hash = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $pdo->prepare(
            "IF EXISTS (SELECT 1 FROM vendingm.panel_tecnico_configuracion WHERE clave = 'master_password_hash')
             BEGIN
                 UPDATE vendingm.panel_tecnico_configuracion
                 SET valor = :hash_update,
                     fecha_actualizacion = SYSDATETIME()
                 WHERE clave = 'master_password_hash'
             END
             ELSE
             BEGIN
                 INSERT INTO vendingm.panel_tecnico_configuracion (clave, valor)
                 VALUES ('master_password_hash', :hash_insert)
             END"
        );
        $stmt->execute([
            'hash_update' => $hash,
            'hash_insert' => $hash,
        ]);

        return ['hash' => $hash, 'password' => $password];
    }

    public static function hashMaestro(?PDO $pdo = null): ?string
    {
        if ($pdo !== null) {
            self::asegurarColumnas($pdo);
            $stmt = $pdo->prepare(
                "SELECT TOP 1 valor
                 FROM vendingm.panel_tecnico_configuracion
                 WHERE clave = 'master_password_hash'"
            );
            $stmt->execute();
            $hashDb = trim((string) ($stmt->fetchColumn() ?: ''));
            if ($hashDb !== '') {
                return $hashDb;
            }
        }

        $hash = trim((string) ($_ENV['PANEL_TECNICO_MASTER_PASSWORD_HASH'] ?? getenv('PANEL_TECNICO_MASTER_PASSWORD_HASH') ?: ''));
        return $hash !== '' ? $hash : null;
    }

    private static function generarPassword(): string
    {
        $alfabeto = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $partes = [];
        for ($bloque = 0; $bloque < 3; $bloque++) {
            $texto = '';
            for ($i = 0; $i < 4; $i++) {
                $texto .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
            }
            $partes[] = $texto;
        }

        return implode('-', $partes);
    }
}
