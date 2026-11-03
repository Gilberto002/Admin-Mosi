<?php
declare(strict_types=1);

use App\Auth\Auth;
use App\Database\Connection;

$paginaActual = basename($_SERVER['SCRIPT_NAME'] ?? '');
$usuarioSoloMaquinasDashboard = Auth::isUsuarioMaquinasDashboard();
$usuarioClinico = Auth::isClinico();

$activo = static function (string $href) use ($paginaActual): string {
    return $paginaActual === $href ? ' class="activa"' : '';
};

$grupoAbierto = static function (array $paginas) use ($paginaActual): string {
    return in_array($paginaActual, $paginas, true) ? ' open' : '';
};

$alertaResurtir = false;
if (Auth::isAdmin() && !$usuarioSoloMaquinasDashboard) {
    try {
        $pdoMenu = Connection::get();
        $capacidadCarrilMenu = 10;
        $paramsAlertaResurtir = [];
        $scopeAlertaResurtir = Auth::machineScope('ca', $paramsAlertaResurtir);
        $stmtAlertaResurtir = $pdoMenu->prepare(
            "SELECT TOP 1 1
             FROM vendingm.carriles ca
             WHERE ca.numero_carril % 10 <> 0
               AND ca.cantidad_actual > 0
               AND (ca.cantidad_actual * 100.0 / :capacidad_menu) < 60
               {$scopeAlertaResurtir}"
        );
        $stmtAlertaResurtir->execute($paramsAlertaResurtir + ['capacidad_menu' => $capacidadCarrilMenu]);
        $alertaResurtir = (bool) $stmtAlertaResurtir->fetchColumn();
    } catch (\Throwable $e) {
        $alertaResurtir = false;
    }
}

$linkResurtir = static function () use ($paginaActual, $alertaResurtir): void {
    $clases = array_filter([
        $paginaActual === 'resurtir.php' ? 'activa' : null,
        $alertaResurtir ? 'nav-alerta-pulso' : null,
    ]);
    $claseAttr = $clases ? ' class="' . implode(' ', $clases) . '"' : '';
    echo '<a href="resurtir.php"' . $claseAttr . '>'
        . '<i data-lucide="package-plus" class="nav-icon"></i>'
        . 'Resurtir'
        . ($alertaResurtir ? ' <span class="nav-alerta-texto">stock bajo</span>' : '')
        . '</a>';
};
?>
<nav class="nav-lateral">
    <?php if ($usuarioClinico): ?>
        <a href="mosi_operacion.php"<?= $activo('mosi_operacion.php') ?>><i data-lucide="chart-no-axes-combined" class="nav-icon"></i>Operación MOSI</a>
        <a href="personal_clinico.php"<?= $activo('personal_clinico.php') ?>><i data-lucide="users-round" class="nav-icon"></i>Mi tablero clinico</a>
        <a href="telemedicina.php"<?= $activo('telemedicina.php') ?>><i data-lucide="stethoscope" class="nav-icon"></i>Mis MOSI</a>
        <a href="logout.php"><i data-lucide="log-out" class="nav-icon"></i>Salir</a>
    <?php elseif ($usuarioSoloMaquinasDashboard): ?>
        <a href="dashboard.php"<?= $activo('dashboard.php') ?>>
            <i data-lucide="layout-dashboard" class="nav-icon"></i>
            Dashboard
        </a>
        <a href="admin.php"<?= $activo('admin.php') ?>>
            <i data-lucide="server" class="nav-icon"></i>
            Maquinas
        </a>
        <a href="logout.php">
            <i data-lucide="log-out" class="nav-icon"></i>
            Salir
        </a>
    <?php else: ?>
        <?php if ($alertaResurtir): ?>
            <?php $linkResurtir(); ?>
        <?php endif; ?>

        <a href="dashboard.php"<?= $activo('dashboard.php') ?>>
            <i data-lucide="layout-dashboard" class="nav-icon"></i>
            Dashboard
        </a>
        <a href="admin.php"<?= $activo('admin.php') ?>>
            <i data-lucide="server" class="nav-icon"></i>
            Alta maquinas
        </a>

        <details class="nav-grupo"<?= $grupoAbierto(['stock_maquinas.php', 'abasto_maquinas.php', 'concentrado_general.php', 'planeacion.php', 'reportes.php']) ?>>
            <summary>
                <i data-lucide="monitor-cog" class="nav-icon"></i>
                <span>Maquinas</span>
                <i data-lucide="chevron-down" class="nav-chevron"></i>
            </summary>
            <div class="nav-submenu">
                <a href="stock_maquinas.php"<?= $activo('stock_maquinas.php') ?>><i data-lucide="package-search" class="nav-icon"></i>Stock maquinas</a>
                <?php if (Auth::isAdmin()): ?>
                    <a href="abasto_maquinas.php"<?= $activo('abasto_maquinas.php') ?>><i data-lucide="gauge" class="nav-icon"></i>Abasto por maquina</a>
                <?php endif; ?>
                <a href="concentrado_general.php"<?= $activo('concentrado_general.php') ?>><i data-lucide="layers" class="nav-icon"></i>Concentrado general</a>
                <a href="planeacion.php"<?= $activo('planeacion.php') ?>><i data-lucide="clipboard-list" class="nav-icon"></i>Planeacion</a>
                <a href="reportes.php"<?= $activo('reportes.php') ?>><i data-lucide="file-chart-column" class="nav-icon"></i>Reportes</a>
            </div>
        </details>

        <details class="nav-grupo"<?= $grupoAbierto(['mosi_operacion.php', 'mosi_colaboradores.php', 'telemedicina.php', 'personal_clinico.php']) ?>>
            <summary>
                <i data-lucide="heart-pulse" class="nav-icon"></i>
                <span>MOSI</span>
                <i data-lucide="chevron-down" class="nav-chevron"></i>
            </summary>
            <div class="nav-submenu">
                <a href="mosi_operacion.php"<?= $activo('mosi_operacion.php') ?>><i data-lucide="chart-no-axes-combined" class="nav-icon"></i>Operación y llamadas</a>
                <a href="mosi_colaboradores.php"<?= $activo('mosi_colaboradores.php') ?>><i data-lucide="user-cog" class="nav-icon"></i>Colaboradores SYBI</a>
                <a href="telemedicina.php"<?= $activo('telemedicina.php') ?>><i data-lucide="monitor-cog" class="nav-icon"></i>Módulos MOSI</a>
                <a href="personal_clinico.php"<?= $activo('personal_clinico.php') ?>><i data-lucide="calendar-cog" class="nav-icon"></i>Personal y horarios</a>
            </div>
        </details>

        <details class="nav-grupo"<?= $grupoAbierto(['abastecimiento.php', 'distribucion.php']) ?>>
            <summary>
                <i data-lucide="truck" class="nav-icon"></i>
                <span>Logistica</span>
                <i data-lucide="chevron-down" class="nav-chevron"></i>
            </summary>
            <div class="nav-submenu">
                <a href="abastecimiento.php"<?= $activo('abastecimiento.php') ?>><i data-lucide="package-open" class="nav-icon"></i>Abastecimiento</a>
                <a href="distribucion.php"<?= $activo('distribucion.php') ?>><i data-lucide="map-pinned" class="nav-icon"></i>Distribucion maquinas</a>
            </div>
        </details>

        <details class="nav-grupo"<?= $grupoAbierto(['mantenimiento.php', 'inventario.php']) ?>>
            <summary>
                <i data-lucide="wrench" class="nav-icon"></i>
                <span>Mantenimiento</span>
                <i data-lucide="chevron-down" class="nav-chevron"></i>
            </summary>
            <div class="nav-submenu">
                <a href="mantenimiento.php"<?= $activo('mantenimiento.php') ?>><i data-lucide="wrench" class="nav-icon"></i>Mantenimientos</a>
                <a href="inventario.php"<?= $activo('inventario.php') ?>><i data-lucide="boxes" class="nav-icon"></i>Inventario</a>
            </div>
        </details>

        <details class="nav-grupo"<?= $grupoAbierto(['receta_nueva.php', 'recetas_pruebas.php', 'carrito.php', 'productos.php', 'vencimientos.php', 'resurtir.php', 'pedidos.php']) ?>>
            <summary>
                <i data-lucide="pill" class="nav-icon"></i>
                <span>Farmacia</span>
                <i data-lucide="chevron-down" class="nav-chevron"></i>
            </summary>
            <div class="nav-submenu">
                <a href="receta_nueva.php"<?= $activo('receta_nueva.php') ?>><i data-lucide="clipboard-plus" class="nav-icon"></i>Recetas</a>
                <?php if (Auth::isAdmin()): ?>
                    <a href="recetas_pruebas.php"<?= $activo('recetas_pruebas.php') ?>><i data-lucide="scan-line" class="nav-icon"></i>Pruebas recetas</a>
                    <?php if (\App\Config\Features::enabled('carrito_compras')): ?>
                        <a href="carrito.php"<?= $activo('carrito.php') ?>><i data-lucide="shopping-cart" class="nav-icon"></i>Carrito</a>
                    <?php endif; ?>
                    <a href="productos.php"<?= $activo('productos.php') ?>><i data-lucide="pill" class="nav-icon"></i>Productos</a>
                    <a href="vencimientos.php"<?= $activo('vencimientos.php') ?>><i data-lucide="calendar-clock" class="nav-icon"></i>Caducos</a>
                    <?php if (!$alertaResurtir): ?>
                        <?php $linkResurtir(); ?>
                    <?php endif; ?>
                    <a href="pedidos.php"<?= $activo('pedidos.php') ?>><i data-lucide="package-check" class="nav-icon"></i>Pedidos</a>
                <?php endif; ?>
            </div>
        </details>

        <?php if (Auth::isAdmin()): ?>
            <details class="nav-grupo"<?= $grupoAbierto(['estadisticas.php', 'actualizaciones_app.php', 'actualizaciones_app_bitacora.php', 'tecnicos_android.php', 'usuarios.php']) ?>>
                <summary>
                    <i data-lucide="settings" class="nav-icon"></i>
                    <span>Administracion</span>
                    <i data-lucide="chevron-down" class="nav-chevron"></i>
                </summary>
                <div class="nav-submenu">
                    <a href="estadisticas.php"<?= $activo('estadisticas.php') ?>><i data-lucide="chart-no-axes-column" class="nav-icon"></i>Estadisticas</a>
                    <a href="actualizaciones_app.php"<?= in_array($paginaActual, ['actualizaciones_app.php', 'actualizaciones_app_bitacora.php'], true) ? ' class="activa"' : '' ?>><i data-lucide="smartphone" class="nav-icon"></i>Actualizaciones app</a>
                    <a href="tecnicos_android.php"<?= $activo('tecnicos_android.php') ?>><i data-lucide="clipboard-check" class="nav-icon"></i>Tecnicos app</a>
                    <a href="usuarios.php"<?= $activo('usuarios.php') ?>><i data-lucide="users" class="nav-icon"></i>Usuarios</a>
                </div>
            </details>
        <?php endif; ?>

        <a href="ayuda.php"<?= $activo('ayuda.php') ?>><i data-lucide="circle-help" class="nav-icon"></i>Ayuda</a>
        <a href="logout.php">
            <i data-lucide="log-out" class="nav-icon"></i>
            Salir
        </a>
    <?php endif; ?>
</nav>
<div class="mdm-sidebar-footer">
    <button class="mdm-tema-toggle" id="mdm-tema-toggle" type="button" aria-label="Cambiar tema" title="Cambiar tema">
        <i data-lucide="moon" class="nav-icon mdm-tema-icono"></i>
        <span><b>Modo oscuro</b><small>Preferencia de visualización</small></span>
    </button>
    <div class="mdm-version"><span></span> Plataforma MDM</div>
</div>
<script>
    (function configurarTemaMdm() {
        var claveTema = 'mdm-tema';
        var temaGuardado = null;
        var temaForzado = document.body.dataset.mdmThemeLock === 'oscuro' ? 'oscuro' : null;
        if (!document.querySelector('link[data-mdm-favicon]')) {
            var favicon = document.createElement('link');
            favicon.rel = 'icon';
            favicon.href = 'assets/img/logo.png';
            favicon.dataset.mdmFavicon = 'true';
            document.head.appendChild(favicon);
        }
        try { temaGuardado = localStorage.getItem(claveTema); } catch (_) {}
        var tema = temaForzado || (temaGuardado === 'oscuro' ? 'oscuro' : 'claro');

        function aplicarTema(nuevoTema) {
            tema = temaForzado || (nuevoTema === 'oscuro' ? 'oscuro' : 'claro');
            document.body.classList.toggle('tema-oscuro', tema === 'oscuro');
            document.body.classList.toggle('tema-mdm-oscuro', tema === 'oscuro');
            document.body.classList.toggle('tema-mdm-claro', tema === 'claro');
            document.documentElement.style.colorScheme = tema === 'oscuro' ? 'dark' : 'light';
            var boton = document.getElementById('mdm-tema-toggle');
            if (boton) {
                boton.style.display = temaForzado ? 'none' : '';
                boton.setAttribute('aria-pressed', tema === 'oscuro' ? 'true' : 'false');
                boton.setAttribute('title', tema === 'oscuro' ? 'Cambiar a modo claro' : 'Cambiar a modo oscuro');
                boton.innerHTML = '<i data-lucide="' + (tema === 'oscuro' ? 'sun' : 'moon') + '" class="nav-icon mdm-tema-icono"></i><span><b>' + (tema === 'oscuro' ? 'Modo claro' : 'Modo oscuro') + '</b><small>Preferencia de visualización</small></span>';
            }
            if (!temaForzado) {
                try { localStorage.setItem(claveTema, tema); } catch (_) {}
            }
            if (window.Chart) {
                window.Chart.defaults.color = tema === 'oscuro' ? '#C0CDD4' : '#29404F';
                window.Chart.defaults.borderColor = tema === 'oscuro' ? 'rgba(185,203,213,.16)' : 'rgba(74,94,107,.16)';
                var instancias = window.Chart.instances ? Object.values(window.Chart.instances) : [];
                instancias.forEach(function (grafica) { if (grafica && grafica.update) grafica.update('none'); });
            }
            if (window.mlRenderIcons) window.mlRenderIcons();
            window.dispatchEvent(new CustomEvent('mdmthemechange', { detail: { tema: tema } }));
        }

        var marca = document.querySelector('.marca');
        if (marca && !marca.querySelector('.mdm-marca-texto')) {
            var identidad = document.createElement('div');
            identidad.className = 'mdm-marca-texto';
            identidad.innerHTML = '<strong>MDM</strong><small>Máquinas dispensadoras<br>de medicamentos</small>';
            marca.appendChild(identidad);
            var logo = marca.querySelector('img');
            if (logo) logo.alt = 'MDM';
        }

        aplicarTema(tema);
        document.getElementById('mdm-tema-toggle')?.addEventListener('click', function () {
            if (temaForzado) return;
            aplicarTema(tema === 'oscuro' ? 'claro' : 'oscuro');
        });
    })();

    document.body.classList.add('nav-icons-lucide');
    window.mlRenderIcons = function () {
        if (window.lucide && typeof window.lucide.createIcons === 'function') {
            window.lucide.createIcons();
        }
    };
    if (!window.lucide) {
        var mlLucideScript = document.createElement('script');
        mlLucideScript.src = 'assets/vendor/lucide/lucide.min.js';
        mlLucideScript.onload = window.mlRenderIcons;
        document.head.appendChild(mlLucideScript);
    }
    document.addEventListener('DOMContentLoaded', window.mlRenderIcons);
</script>
