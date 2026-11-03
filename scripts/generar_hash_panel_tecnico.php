<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/bootstrap.php';

$password = $argv[1] ?? '';
if ($password === '') {
    fwrite(STDERR, "Uso: php scripts/generar_hash_panel_tecnico.php \"contrasena-maestra\"\n");
    exit(1);
}

echo password_hash($password, PASSWORD_DEFAULT) . PHP_EOL;
