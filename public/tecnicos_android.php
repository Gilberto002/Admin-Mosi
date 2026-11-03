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
    <title>MDM - Técnicos app</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="assets/css/estilo.css?v=20260914-2">
    <script src="assets/js/comun.js?v=20260912-1"></script>
    <style>
        body.tema-oscuro { --bg:#F5F7FA; --bg-elevada:#fff; --linea:#D8E0E7; --texto:#14212B; --texto-suave:#344856; --texto-tenue:#647583; --petroleo:#087D76; --petroleo-wash:rgba(8,125,118,.10); --critico:#B42335; --critico-wash:rgba(180,35,53,.10); background:var(--bg); color:var(--texto); }
        .encabezado-pagina { display:flex; justify-content:space-between; gap:16px; align-items:flex-start; margin-bottom:18px; }
        .encabezado-pagina h1 { margin:0 0 4px; font-size:24px; }
        .encabezado-pagina p { margin:0; color:var(--texto-suave); }
        .card { background:var(--bg-elevada); border:1px solid var(--linea); border-radius:8px; }
        .barra { display:grid; grid-template-columns:minmax(220px,1fr) 190px 160px auto; gap:10px; padding:14px; border-bottom:1px solid var(--linea); }
        input, select, button { font:inherit; border-radius:8px; border:1px solid var(--linea); padding:9px 11px; background:#fff; color:var(--texto); }
        button { cursor:pointer; background:var(--petroleo); border-color:var(--petroleo); color:#fff; font-weight:700; }
        table { width:100%; border-collapse:collapse; font-size:13px; }
        th, td { text-align:left; padding:11px 14px; border-bottom:1px solid #E8EEF3; vertical-align:top; }
        th { color:var(--texto-tenue); font-size:11px; text-transform:uppercase; }
        .mono { font-family:ui-monospace, SFMono-Regular, Consolas, monospace; }
        .muted { color:var(--texto-tenue); font-size:12px; }
        .badge { display:inline-flex; padding:3px 9px; border-radius:999px; font-size:12px; font-weight:700; }
        .ok { background:var(--petroleo-wash); color:var(--petroleo); }
        .error { background:var(--critico-wash); color:var(--critico); }
        .vacio { color:var(--texto-tenue); padding:28px; text-align:center; }
        @media (max-width: 760px) { .barra { grid-template-columns:1fr; } th:nth-child(4), td:nth-child(4), th:nth-child(6), td:nth-child(6) { display:none; } }
    </style>
</head>
<body class="tema-oscuro">
<div class="app">
    <aside class="sidebar">
        <div class="marca"><img class="marca-logo" src="assets/img/logo.png" alt="MDM"></div>
        <?php require __DIR__ . '/partials/menu.php'; ?>
    </aside>
    <main class="principal">
        <div class="encabezado-pagina">
            <div>
                <h1>Técnicos app</h1>
                <p>Bitácora de accesos, activaciones y eventos enviados desde el panel técnico Android.</p>
            </div>
        </div>

        <section class="card">
            <div class="barra">
                <input id="q" placeholder="Buscar técnico, correo o serie">
                <select id="accion">
                    <option value="">Todas las acciones</option>
                    <option value="login_tecnico">Login técnico</option>
                    <option value="activar_maquina">Activación</option>
                    <option value="entrar_panel_tecnico">Entrada panel técnico</option>
                    <option value="salir_panel_tecnico">Salida panel técnico</option>
                    <option value="error_panel_tecnico">Error panel técnico</option>
                </select>
                <select id="resultado">
                    <option value="">Todo resultado</option>
                    <option value="ok">OK</option>
                    <option value="error">Error</option>
                </select>
                <button id="btn-buscar" type="button">Actualizar</button>
            </div>
            <div id="tabla" class="vacio">Cargando...</div>
        </section>
    </main>
</div>
<script>
const $ = s => document.querySelector(s);

function fecha(v) {
    if (!v) return '-';
    const d = new Date(v);
    return Number.isNaN(d.getTime()) ? v : d.toLocaleString();
}

function nombreTecnico(e) {
    const nombre = `${e.nombre || ''} ${e.apellido || ''}`.trim();
    return nombre || e.email || '-';
}

async function cargar() {
    const params = new URLSearchParams({ limite: '300' });
    if ($('#q').value.trim()) params.set('q', $('#q').value.trim());
    if ($('#accion').value) params.set('accion', $('#accion').value);
    if ($('#resultado').value) params.set('resultado', $('#resultado').value);
    $('#tabla').className = 'vacio';
    $('#tabla').textContent = 'Cargando...';
    try {
        const json = await obtenerJSON(`api/android_tecnico_eventos.php?${params}`);
        if (!json.data.length) {
            $('#tabla').textContent = 'Sin eventos registrados.';
            return;
        }
        $('#tabla').className = '';
        $('#tabla').innerHTML = `<table><thead><tr><th>Fecha</th><th>Técnico</th><th>Máquina</th><th>Acción</th><th>Resultado</th><th>Detalle</th></tr></thead><tbody>${json.data.map(e => `
            <tr>
                <td>${escapeHtml(fecha(e.fecha))}</td>
                <td>${escapeHtml(nombreTecnico(e))}<div class="muted">${escapeHtml(e.email || '')}</div></td>
                <td><span class="mono">${escapeHtml(e.numero_serie || '-')}</span><div class="muted">${escapeHtml(e.nombre_maquina || '')}</div></td>
                <td>${escapeHtml(e.accion)}</td>
                <td><span class="badge ${e.resultado === 'error' ? 'error' : 'ok'}">${escapeHtml(e.resultado)}</span></td>
                <td>${escapeHtml(e.mensaje || '')}<div class="muted">${escapeHtml(e.app_version || '')}</div></td>
            </tr>`).join('')}</tbody></table>`;
    } catch (e) {
        $('#tabla').textContent = e.message || 'No se pudo cargar la bitácora.';
    }
}

$('#btn-buscar').addEventListener('click', cargar);
$('#q').addEventListener('keydown', ev => { if (ev.key === 'Enter') cargar(); });
cargar();
</script>
</body>
</html>
