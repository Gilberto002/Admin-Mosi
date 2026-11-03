<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/bootstrap.php';

use App\Auth\Auth;

Auth::requireLogin();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
    <title>MDM - Operación MOSI</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/estilo.css?v=20260914-2">
    <link rel="stylesheet" href="assets/css/mosi-operacion.css?v=20261030-1">
</head>
<body>
<div class="app">
    <aside class="sidebar">
        <div class="marca"><img class="marca-logo" src="assets/img/logo.png" alt="MDM"></div>
        <?php require __DIR__ . '/partials/menu.php'; ?>
    </aside>
    <main class="principal mosi-op">
        <header class="op-head">
            <div>
                <div class="op-eyebrow"><span></span>Centro de operación clínica</div>
                <h1>Operación MOSI</h1>
                <p>Actividad, desempeño del equipo médico y trazabilidad de llamadas en un solo lugar.</p>
            </div>
            <div class="op-head-actions">
                <?php if (Auth::isAdmin() && !Auth::isUsuarioMaquinasDashboard()): ?><a class="op-btn secondary" href="mosi_colaboradores.php"><i data-lucide="user-cog"></i>Colaboradores SYBI</a><?php endif; ?>
                <a class="op-btn secondary" href="personal_clinico.php"><i data-lucide="calendar-cog"></i>Personal y horarios</a>
                <a class="op-btn" href="telemedicina.php"><i data-lucide="monitor-cog"></i>Administrar MOSI</a>
            </div>
        </header>

        <section class="op-control" aria-label="Filtros del tablero">
            <label class="op-mosi-select">
                <span>Módulo MOSI</span>
                <select id="op-mosi"><option value="">Cargando módulos…</option></select>
            </label>
            <div class="op-date-group">
                <label><span>Desde</span><input id="op-desde" type="date"></label>
                <label><span>Hasta</span><input id="op-hasta" type="date"></label>
            </div>
            <div class="op-presets" aria-label="Periodos rápidos">
                <button type="button" data-days="7">7 días</button>
                <button type="button" data-days="30" class="active">30 días</button>
                <button type="button" data-days="90">90 días</button>
            </div>
            <button class="op-btn apply" id="op-aplicar" type="button"><i data-lucide="refresh-cw"></i>Actualizar</button>
        </section>

        <section class="op-context" id="op-context" aria-live="polite">
            <div class="op-context-icon"><i data-lucide="radio"></i></div>
            <div class="op-context-copy"><small>MOSI seleccionada</small><h2>Preparando tablero…</h2><p>Consultando inventario y operación clínica.</p></div>
            <div class="op-context-meta"></div>
        </section>

        <div class="op-notice" id="op-notice" hidden></div>

        <section class="op-kpis" aria-label="Indicadores principales">
            <article class="op-kpi total"><div class="op-kpi-icon"><i data-lucide="phone-call"></i></div><div><small>Llamadas recibidas</small><strong id="k-total">—</strong><span id="k-periodo">En el periodo</span></div></article>
            <article class="op-kpi success"><div class="op-kpi-icon"><i data-lucide="phone-forwarded"></i></div><div><small>Atendidas</small><strong id="k-atendidas">—</strong><span id="k-tasa">— de atención</span></div></article>
            <article class="op-kpi danger"><div class="op-kpi-icon"><i data-lucide="phone-missed"></i></div><div><small>No atendidas</small><strong id="k-perdidas">—</strong><span>Requieren seguimiento</span></div></article>
            <article class="op-kpi warning"><div class="op-kpi-icon"><i data-lucide="repeat-2"></i></div><div><small>Reasignadas</small><strong id="k-reasignadas">—</strong><span>Entre profesionales</span></div></article>
            <article class="op-kpi time"><div class="op-kpi-icon"><i data-lucide="timer"></i></div><div><small>Espera promedio</small><strong id="k-espera">—</strong><span>Hasta conectar</span></div></article>
            <article class="op-kpi time"><div class="op-kpi-icon"><i data-lucide="clock-3"></i></div><div><small>Duración promedio</small><strong id="k-duracion">—</strong><span>De llamada atendida</span></div></article>
        </section>

        <section class="op-grid">
            <article class="op-panel op-chart-panel">
                <header><div><small>Tendencia de actividad</small><h2>Llamadas por día</h2></div><div class="op-legend"><span class="answered">Atendidas</span><span class="missed">No atendidas</span></div></header>
                <div class="op-chart-wrap"><canvas id="op-chart"></canvas><div class="op-empty" id="chart-empty" hidden><i data-lucide="chart-no-axes-column"></i><b>Sin llamadas en el periodo</b><span>Prueba con un rango de fechas más amplio.</span></div></div>
            </article>
            <article class="op-panel op-team-summary">
                <header><div><small>Cobertura actual</small><h2>Equipo asignado</h2></div><a id="team-link" href="personal_clinico.php">Gestionar</a></header>
                <div class="team-summary-body" id="team-summary"><div class="op-skeleton tall"></div></div>
            </article>
        </section>

        <section class="op-panel op-doctors-panel">
            <header class="op-section-head">
                <div><small>Rendimiento individual</small><h2>Actividad por profesional</h2><p>Selecciona una MOSI para comparar carga y nivel de atención.</p></div>
                <div class="op-table-tools"><input id="doctor-search" type="search" placeholder="Buscar profesional…"><button type="button" id="export-doctors" title="Exportar CSV"><i data-lucide="download"></i>Exportar</button></div>
            </header>
            <div class="op-table-wrap">
                <table class="op-table">
                    <thead><tr><th>Profesional</th><th>Estado</th><th class="num">Recibidas</th><th class="num">Atendidas</th><th class="num">No atendidas</th><th class="num">Nivel de atención</th><th>Promedios</th><th>Última llamada</th></tr></thead>
                    <tbody id="doctor-rows"><tr><td colspan="8"><div class="op-skeleton"></div></td></tr></tbody>
                </table>
            </div>
        </section>

        <section class="op-panel op-calls-panel">
            <header class="op-section-head">
                <div><small>Trazabilidad sin datos del paciente</small><h2>Llamadas recientes</h2><p>Origen, resultado y tiempos operativos de las últimas llamadas.</p></div>
                <div class="op-call-filters"><button class="active" data-call-filter="all">Todas</button><button data-call-filter="atendida">Atendidas</button><button data-call-filter="perdida">No atendidas</button></div>
            </header>
            <div class="op-table-wrap">
                <table class="op-table calls"><thead><tr><th>Fecha y hora</th><th>Profesional</th><th>Origen</th><th>Resultado</th><th>Espera</th><th>Duración</th></tr></thead><tbody id="call-rows"><tr><td colspan="6"><div class="op-skeleton"></div></td></tr></tbody></table>
            </div>
        </section>
    </main>
</div>
<script src="assets/js/comun.js?v=20261013-2"></script>
<script src="assets/js/vendor/chart.min.js"></script>
<script src="assets/js/mosi-operacion.js?v=20261030-1"></script>
</body>
</html>
