<?php
// Sube este archivo por FTP a tu carpeta de producción y ábrelo en el navegador.
// Te dice si el servidor tiene lo necesario para el proyecto.
// BORRA este archivo del servidor después de revisarlo (expone info del entorno).

declare(strict_types=1);

$requisitos = [
    'PHP >= 8.2'      => version_compare(PHP_VERSION, '8.2.0', '>='),
    'Extension pdo'   => extension_loaded('pdo'),
    'Extension sqlsrv'     => extension_loaded('sqlsrv'),
    'Extension pdo_sqlsrv' => extension_loaded('pdo_sqlsrv'),
    'Permite escritura en esta carpeta' => is_writable(__DIR__),
];
?>
<!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8"><title>Diagnóstico del servidor</title>
<style>
body{font-family:system-ui,sans-serif;padding:30px;background:#f4f5f7}
.card{background:#fff;border-radius:10px;padding:20px 28px;max-width:500px;margin:auto;box-shadow:0 2px 8px rgba(0,0,0,.08)}
.ok{color:#237A53;font-weight:bold} .fail{color:#9C3255;font-weight:bold}
table{width:100%;border-collapse:collapse} td{padding:8px 0;border-bottom:1px solid #eee}
</style></head>
<body>
<div class="card">
<h2>Diagnóstico del servidor</h2>
<p>PHP detectado: <b><?= PHP_VERSION ?></b></p>
<table>
<?php foreach ($requisitos as $nombre => $cumple): ?>
    <tr>
        <td><?= htmlspecialchars($nombre) ?></td>
        <td><?= $cumple ? '<span class="ok">SÍ</span>' : '<span class="fail">NO</span>' ?></td>
    </tr>
<?php endforeach; ?>
</table>
<?php if (!extension_loaded('sqlsrv') || !extension_loaded('pdo_sqlsrv')): ?>
    <p style="margin-top:16px;color:#9C3255">
        Faltan las extensiones de SQL Server. Como no tienes SSH, tienes que pedirle
        a tu proveedor de hosting/VPS que las habilite en el <code>php.ini</code> del servidor
        (son los "Microsoft Drivers for PHP for SQL Server"). Sin esto, la conexión
        nunca va a funcionar sin importar qué subas por FTP.
    </p>
<?php endif; ?>
</div>
</body>
</html>
