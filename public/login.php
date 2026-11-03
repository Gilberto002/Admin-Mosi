<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/bootstrap.php';

use App\Auth\Auth;
use App\Database\Connection;
use App\Security\Security;

if (Auth::user()) {
    header('Location: ' . (Auth::isCliente() ? 'cliente_dashboard.php' : 'dashboard.php'));
    exit;
}

$error = '';
$modoSetup = false;
$errorSetup = '';

try {
    $pdo = Connection::get();
    $modoSetup = (int) $pdo->query('SELECT COUNT(*) FROM vendingm.usuarios')->fetchColumn() === 0;
} catch (\Throwable $e) {
    $pdo = null;
    $errorSetup = 'Ejecuta primero scripts/sql_auth_usuarios.sql en SQL Server.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = (string) ($_POST['email'] ?? '');
    $password = (string) ($_POST['password'] ?? '');

    // Login CSRF: sin sesión iniciada, requireLogin()/verifyCsrf() (que
    // protege el resto del sitio) todavía no aplica -- se valida aquí
    // manualmente contra el token de esta misma sesión anónima. Integrado
    // del fork de Gilberto el 2026-08-24, ver src/Security/Security.php.
    $csrf = (string) ($_POST['_csrf'] ?? '');
    if ($csrf === '' || !hash_equals(Security::ensureCsrfToken(), $csrf)) {
        $error = 'Solicitud no autorizada. Recarga la pagina e intenta de nuevo.';
    } elseif ($modoSetup && $pdo) {
        $nombre = trim((string) ($_POST['nombre'] ?? ''));
        $apellido = trim((string) ($_POST['apellido'] ?? ''));

        if ($nombre !== '' && $apellido !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) && strlen($password) >= 8) {
            // sp_getapplock (con @LockOwner='Transaction') serializa dos altas
            // concurrentes del primer administrador: sin esto, dos peticiones
            // simultáneas podrían ver ambas COUNT=0 y crear dos "primeros admin".
            $pdo->beginTransaction();
            try {
                $stmtLock = $pdo->prepare(
                    "DECLARE @res INT;
                     EXEC @res = sp_getapplock @Resource = 'vendingm_setup_admin', @LockMode = 'Exclusive', @LockOwner = 'Transaction', @LockTimeout = 5000;
                     SELECT @res AS resultado;"
                );
                $stmtLock->execute();
                $resultadoLock = (int) $stmtLock->fetchColumn();
                if ($resultadoLock < 0) {
                    throw new \RuntimeException('No se pudo obtener el bloqueo de configuracion inicial.');
                }

                $totalUsuarios = (int) $pdo->query('SELECT COUNT(*) FROM vendingm.usuarios')->fetchColumn();
                if ($totalUsuarios > 0) {
                    $pdo->rollBack();
                    $modoSetup = false;
                    $error = 'Ya se configuro un administrador. Inicia sesion normalmente.';
                } else {
                    $stmt = $pdo->prepare(
                        "INSERT INTO vendingm.usuarios (nombre, apellido, email, password_hash, rol, activo)
                         VALUES (:nombre, :apellido, :email, :hash, 'admin', 1)"
                    );
                    $stmt->execute([
                        'nombre' => $nombre,
                        'apellido' => $apellido,
                        'email' => strtolower(trim($email)),
                        'hash' => password_hash($password, PASSWORD_DEFAULT),
                    ]);
                    $pdo->commit();
                    Auth::login($email, $password);
                    header('Location: dashboard.php');
                    exit;
                }
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = 'No se pudo crear el administrador. Intenta de nuevo.';
            }
        } else {
            $error = 'Completa nombre, apellido, correo valido y una contrasena de al menos 8 caracteres.';
        }
    } elseif (!$pdo) {
        $error = $errorSetup;
    } elseif (Auth::login($email, $password)) {
        header('Location: ' . Auth::homePage());
        exit;
    } else {
        $error = 'Correo o contrasena incorrectos.';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>MDM - Iniciar sesion</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/estilo.css?v=20260914-2">
    <link rel="stylesheet" href="assets/css/login.css?v=20260912-1">
</head>
<body class="login-page">
    <div class="scene">

        <!-- ============ Panel de marca ============ -->
        <section class="panel-brand">

            <img class="brand-watermark" src="assets/img/logo.png" alt="" aria-hidden="true">

            <div class="brand-lockup">
                <img src="assets/img/logo.png" alt="MDM">
                <div class="wordmark">
                    MDM
                    <span>Máquinas Dispensadoras de Medicamentos</span>
                </div>
            </div>

            <div class="brand-copy">
                <h1>Todas tus máquinas,<br>un solo panel.</h1>
                <p>Consulta existencias, estado y alertas de cada dispensador de medicamentos desde aquí.</p>
            </div>

            <div class="brand-rule">
                <i></i>
                <span>Panel interno</span>
            </div>
        </section>

        <!-- ============ Panel de acceso ============ -->
        <section class="panel-form">
            <form class="login-card" method="post" autocomplete="on">
                <?= Security::csrfInput() ?>
                <p class="eyebrow"><i></i>Acceso administrativo</p>

                <div class="login-logo-wrap">
                    <img class="login-logo" src="assets/img/logo.png" alt="MDM">
                </div>

                <h2><?= $modoSetup ? 'Crear administrador' : 'Iniciar sesion' ?></h2>
                <p class="sub"><?= $modoSetup
                    ? 'Este sera el primer administrador con acceso completo al panel.'
                    : 'Ingresa tus credenciales para entrar al panel.' ?></p>

                <?php if ($error !== '' || $errorSetup !== ''): ?>
                    <div class="msg-error">
                        <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><circle cx="8" cy="8" r="7" stroke="currentColor" stroke-width="1.4"/><path d="M8 4.5V9" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/><circle cx="8" cy="11.2" r="0.9" fill="currentColor"/></svg>
                        <span><?= htmlspecialchars($error !== '' ? $error : $errorSetup) ?></span>
                    </div>
                <?php endif; ?>

                <?php if ($modoSetup): ?>
                    <div class="form-row">
                        <label for="nombre">Nombre</label>
                        <input id="nombre" name="nombre" type="text" placeholder="Ej. Ana" required>
                    </div>
                    <div class="form-row">
                        <label for="apellido">Apellido</label>
                        <input id="apellido" name="apellido" type="text" placeholder="Ej. Torres" required>
                    </div>
                <?php endif; ?>

                <div class="form-row">
                    <label for="email">Correo electronico</label>
                        <input id="email" name="email" type="email" placeholder="usuario@mdm.mx" required>
                </div>
                <div class="form-row">
                    <label for="password">Contrasena</label>
                    <input id="password" name="password" type="password" placeholder="••••••••" required>
                </div>

                <button type="submit"><?= $modoSetup ? 'Crear administrador' : 'Entrar al panel' ?></button>

                <div class="foot">MDM &copy; <?= date('Y') ?></div>
            </form>
        </section>

    </div>
</body>
</html>
