<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/bootstrap.php';

use App\Auth\Auth;

Auth::requireAdmin();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
    <title>MDM - Colaboradores MOSI</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/estilo.css?v=20260914-2">
    <link rel="stylesheet" href="assets/css/mosi-colaboradores.css?v=20261102-1">
</head>
<body>
<div class="app">
    <aside class="sidebar"><div class="marca"><img class="marca-logo" src="assets/img/logo.png" alt="MDM"></div><?php require __DIR__ . '/partials/menu.php'; ?></aside>
    <main class="principal sybi-admin">
        <header class="sybi-head">
            <div><div class="sybi-eyebrow"><span></span>Sincronizado con Mosy.SYBI</div><h1>Colaboradores MOSI</h1><p>Controla disponibilidad, permisos y módulos de atención desde una sola tabla.</p></div>
            <div class="sybi-head-actions"><a class="sybi-btn secondary" href="mosi_operacion.php"><i data-lucide="chart-no-axes-combined"></i>Ver operación</a><button class="sybi-btn" id="refresh" type="button"><i data-lucide="refresh-cw"></i>Actualizar estados</button></div>
        </header>

        <nav class="group-tabs" aria-label="Tipo de profesional">
            <button class="active" data-group="20"><i data-lucide="stethoscope"></i><span>Médicos</span></button>
            <button data-group="24"><i data-lucide="brain"></i><span>Psicólogos</span></button>
            <button data-group="23"><i data-lucide="apple"></i><span>Nutriólogos</span></button>
        </nav>

        <section class="sybi-stats">
            <article><small>Total</small><strong id="s-total">—</strong><span>En el grupo</span></article>
            <article class="ok"><small>Activos</small><strong id="s-active">—</strong><span>Con permisos</span></article>
            <article class="ready"><small>Disponibles</small><strong id="s-ready">—</strong><span>Sesión abierta</span></article>
            <article class="busy"><small>En atención</small><strong id="s-busy">—</strong><span>Consulta o llamada</span></article>
            <article class="off"><small>Desactivados</small><strong id="s-off">—</strong><span>Sin recibir llamadas</span></article>
            <article class="warn"><small>Sin MOSI</small><strong id="s-unassigned">—</strong><span>Falta asignación</span></article>
        </section>

        <section class="sybi-panel">
            <header class="table-head">
                <div><small>Administración en tiempo real</small><h2 id="table-title">Médicos</h2><p id="last-update">Consultando SYBI…</p></div>
                <div class="table-filters"><label><i data-lucide="search"></i><input id="search" type="search" placeholder="Buscar nombre, sucursal o MOSI…"></label><select id="status-filter"><option value="">Todos los estados</option><option value="available">Disponibles</option><option value="busy">En atención</option><option value="disabled">Desactivados</option><option value="unassigned">Sin MOSI</option></select></div>
            </header>
            <div class="connection-note" id="connection-note" hidden></div>
            <div class="sybi-table-wrap">
                <table class="sybi-table">
                    <thead><tr><th>Colaborador</th><th>Horario de hoy</th><th>Estado operativo</th><th>Permisos</th><th>MOSI asignadas</th><th>Plataforma</th><th>Acciones</th></tr></thead>
                    <tbody id="collaborator-rows"><tr><td colspan="7"><div class="loading-row"></div></td></tr></tbody>
                </table>
            </div>
            <footer class="table-footer"><span id="result-count">Cargando…</span><span>Datos operativos provenientes de Mosy.SYBI</span></footer>
        </section>
    </main>
</div>

<div class="sybi-overlay" id="assignment-overlay" aria-hidden="true">
    <form class="assignment-modal" id="assignment-form">
        <header><div><small>Permisos de atención</small><h2 id="modal-name">Asignar MOSI</h2><p id="modal-status"></p></div><button class="modal-close" type="button" data-close>&times;</button></header>
        <div class="modal-body">
            <input type="hidden" id="modal-doctor">
            <div class="platform-field"><label for="modal-platform">Plataformas permitidas</label><select id="modal-platform"><option value="ALL">MOSI y aplicación</option><option value="Kiosco">Sólo MOSI / kiosco</option><option value="APP">Sólo aplicación</option></select><small>Define desde dónde puede recibir solicitudes.</small></div>
            <div class="assignment-heading"><div><b>MOSI autorizadas</b><span>Marca o desmarca los módulos que puede atender.</span></div><label><i data-lucide="search"></i><input id="modal-search" type="search" placeholder="Buscar MOSI o sucursal…"></label></div>
            <div class="mosi-checks" id="modal-mosis"></div>
        </div>
        <footer><span id="modal-message"></span><button class="sybi-btn secondary" type="button" data-close>Cancelar</button><button class="sybi-btn" type="submit"><i data-lucide="save"></i>Guardar cambios</button></footer>
    </form>
</div>

<div class="sybi-overlay" id="schedule-overlay" aria-hidden="true">
    <form class="assignment-modal schedule-modal" id="schedule-form">
        <header><div><small>Disponibilidad semanal</small><h2 id="schedule-name">Horarios y notificaciones</h2><p id="schedule-status"></p></div><button class="modal-close" type="button" data-schedule-close>&times;</button></header>
        <div class="modal-body">
            <input type="hidden" id="schedule-doctor">
            <div class="attention-box" id="attention-box">
                <div><b id="attention-title">Sin atencion activa</b><span id="attention-detail">No hay notificaciones ni consultas pendientes.</span></div>
                <button class="row-btn danger" id="release-attention" type="button" hidden><i data-lucide="bell-off"></i>Liberar atencion</button>
            </div>
            <div class="schedule-editor-wrap">
                <table class="schedule-editor">
                    <thead><tr><th>Dia</th><th>Inicio laboral</th><th>Fin laboral</th><th>Inicio comida</th><th>Fin comida</th></tr></thead>
                    <tbody id="schedule-rows"></tbody>
                </table>
            </div>
            <p class="schedule-help">Deja el inicio y fin vacios para eliminar ese horario. Los cambios se guardan directamente en Mosy.SYBI.</p>
        </div>
        <footer><span id="schedule-message"></span><button class="sybi-btn secondary" type="button" data-schedule-close>Cancelar</button><button class="sybi-btn" type="submit"><i data-lucide="save"></i>Guardar horarios</button></footer>
    </form>
</div>

<script src="assets/js/comun.js?v=20261013-2"></script>
<script src="assets/js/mosi-colaboradores.js?v=20261102-1"></script>
</body>
</html>
