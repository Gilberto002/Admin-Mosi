<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/bootstrap.php';

use App\Actualizaciones\AppActualizaciones;
use App\Auth\Auth;

Auth::requireAdmin();

$canalServidor = AppActualizaciones::canalServidor();
$canalEtiqueta = 'Clientes';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>MDM - Bitacora <?= htmlspecialchars($canalEtiqueta, ENT_QUOTES, 'UTF-8') ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/estilo.css?v=20260914-2">
    <style>
        body.bitacora-page {
            --bg: #eef3f7;
            --card: #fff;
            --ink: #102a43;
            --muted: #526b7a;
            --line: #cbd8e1;
            --blue: #1457b8;
            --blue-soft: #eaf2ff;
            --green: #20733b;
            --green-soft: #eaf7ed;
            --amber: #8a5700;
            --amber-soft: #fff4d8;
            --red: #a32636;
            --red-soft: #fff0f2;
            background: var(--bg) !important;
            color: var(--ink) !important;
            font-family: Inter, system-ui, sans-serif;
        }
        .bitacora-principal { width: 100%; max-width: 1540px; padding-bottom: 44px; }
        .hero-bitacora {
            display: flex; align-items: center; justify-content: space-between; gap: 20px;
            margin-bottom: 18px; padding: 22px 24px; border-radius: 12px;
            color: #fff; background: linear-gradient(115deg, #102a43, #123c70 66%, #1457b8);
            box-shadow: 0 10px 28px rgba(16,42,67,.18);
        }
        .hero-copy { display: flex; align-items: center; gap: 16px; min-width: 0; }
        .hero-icon { display: grid; place-items: center; width: 46px; height: 46px; flex: 0 0 46px; border-radius: 12px; background: rgba(255,255,255,.13); }
        .hero-icon svg { width: 23px; height: 23px; }
        .scope { display: flex; align-items: center; gap: 7px; margin-bottom: 5px; color: #bce0ff; font-size: 11px; font-weight: 800; letter-spacing: .09em; text-transform: uppercase; }
        .scope::before { content: ''; width: 8px; height: 8px; border-radius: 50%; background: #61d58a; box-shadow: 0 0 0 4px rgba(97,213,138,.18); }
        h1 { margin: 0 0 4px; color: #fff; font-size: clamp(22px, 2.2vw, 31px); line-height: 1.1; }
        .hero-bitacora p { margin: 0; color: #d9e8f5; font-size: 13px; }
        .acciones { display: flex; gap: 9px; flex-wrap: wrap; }
        .btn-bitacora { display: inline-flex; align-items: center; justify-content: center; gap: 7px; min-height: 39px; padding: 9px 13px; border: 1px solid rgba(255,255,255,.34); border-radius: 8px; color: #fff; background: rgba(255,255,255,.08); font: inherit; font-size: 13px; font-weight: 700; text-decoration: none; cursor: pointer; }
        .btn-bitacora:hover { color: #102a43; background: #fff; }
        .btn-bitacora.primario { color: #123c70; background: #fff; border-color: #fff; }
        .btn-bitacora svg { width: 16px; height: 16px; }
        .resumen { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; margin-bottom: 14px; }
        .stat { position: relative; overflow: hidden; min-height: 104px; padding: 16px 18px; border: 1px solid var(--line); border-radius: 10px; background: var(--card); box-shadow: 0 5px 18px rgba(16,42,67,.06); }
        .stat::after { content: ''; position: absolute; inset: 0 auto 0 0; width: 4px; background: var(--stat-color, var(--blue)); }
        .stat .lbl { margin-bottom: 8px; color: var(--muted); font-size: 11px; font-weight: 800; letter-spacing: .065em; text-transform: uppercase; }
        .stat .num { color: var(--ink); font-size: 31px; font-weight: 800; line-height: 1; }
        .stat.consulta { --stat-color: var(--blue); } .stat.descarga { --stat-color: #6c47b8; }
        .stat.instalacion { --stat-color: var(--green); } .stat.error { --stat-color: var(--red); }
        .panel-bitacora { overflow: hidden; border: 1px solid var(--line); border-radius: 10px; background: var(--card); box-shadow: 0 8px 24px rgba(16,42,67,.07); }
        .panel-head { display: flex; align-items: flex-end; justify-content: space-between; gap: 16px; padding: 17px 19px; border-bottom: 1px solid var(--line); background: #f8fafc; }
        .panel-head h2 { margin: 0 0 3px; color: var(--ink); font-size: 17px; }
        .panel-head .sub { color: var(--muted); font-size: 12px; }
        .filtros { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 12px 16px; border-bottom: 1px solid #dde6ec; background: #fff; }
        .chips { display: flex; gap: 6px; flex-wrap: wrap; }
        .chip { padding: 7px 10px; border: 1px solid #c7d4dd; border-radius: 999px; color: #38505f; background: #fff; font: inherit; font-size: 11px; font-weight: 700; cursor: pointer; }
        .chip:hover, .chip.activo { color: #fff; border-color: var(--blue); background: var(--blue); }
        .buscar { position: relative; width: min(310px, 100%); }
        .buscar svg { position: absolute; top: 10px; left: 11px; width: 15px; height: 15px; color: #6a7a86; }
        .buscar input { width: 100%; min-height: 36px; box-sizing: border-box; padding: 8px 11px 8px 34px; border: 1px solid #bfcdd7; border-radius: 8px; color: var(--ink); background: #fff; font: inherit; font-size: 12px; }
        .buscar input:focus { outline: 3px solid rgba(20,87,184,.13); border-color: var(--blue); }
        .table-shell { max-height: 68vh; overflow: auto; }
        table { width: 100%; min-width: 1040px; border-collapse: separate; border-spacing: 0; color: var(--ink); font-size: 13px; }
        th { position: sticky; top: 0; z-index: 2; padding: 11px 14px; border-bottom: 1px solid #afc0cc; color: #38505f; background: #e8eff4; font-size: 10px; font-weight: 800; letter-spacing: .06em; text-align: left; text-transform: uppercase; }
        td { padding: 13px 14px; border-bottom: 1px solid #dbe4ea; color: #19374a; background: #fff; vertical-align: top; }
        tbody tr:nth-child(even) td { background: #f8fafc; }
        tbody tr:hover td { background: #edf5ff; }
        tbody tr.row-error td:first-child { box-shadow: inset 4px 0 var(--red); }
        tbody tr.row-ok td:first-child { box-shadow: inset 4px 0 var(--green); }
        .fecha { white-space: nowrap; font-weight: 600; }
        .fecha small { display: block; margin-top: 3px; color: var(--muted); font-weight: 500; }
        .maquina strong { display: block; color: var(--ink); font-size: 13.5px; }
        .secundario { display: block; margin-top: 3px; color: var(--muted); font-size: 11.5px; }
        .version { font-family: 'JetBrains Mono', monospace; font-size: 11.5px; white-space: nowrap; }
        .version strong { display: block; color: var(--ink); font-size: 13px; }
        .badge { display: inline-flex; align-items: center; gap: 6px; padding: 5px 9px; border: 1px solid transparent; border-radius: 999px; font-size: 10.5px; font-weight: 800; text-transform: capitalize; white-space: nowrap; }
        .badge::before { content: ''; width: 6px; height: 6px; border-radius: 50%; background: currentColor; }
        .badge-consulta { color: var(--blue); background: var(--blue-soft); border-color: #bfd4f2; }
        .badge-descarga { color: #6741aa; background: #f1ecff; border-color: #d6c7f5; }
        .badge-instalacion { color: var(--green); background: var(--green-soft); border-color: #b9ddc2; }
        .badge-error { color: var(--red); background: var(--red-soft); border-color: #efbec6; }
        .detalle { max-width: 430px; color: #38505f; }
        details { margin-top: 6px; }
        details summary { width: fit-content; color: var(--blue); font-size: 11.5px; font-weight: 700; cursor: pointer; }
        .json-box { max-height: 230px; overflow: auto; margin: 7px 0 0; padding: 10px; border: 1px solid #c8d6df; border-radius: 7px; color: #d9f2ee; background: #10232d; font: 11px/1.5 'JetBrains Mono', monospace; white-space: pre-wrap; overflow-wrap: anywhere; }
        .estado-tabla { padding: 42px 18px; color: var(--muted); text-align: center; }
        @media (max-width: 900px) { .resumen { grid-template-columns: 1fr 1fr; } .hero-bitacora, .filtros { align-items: stretch; flex-direction: column; } .acciones .btn-bitacora { flex: 1; } .buscar { width: 100%; } }
        @media (max-width: 560px) { .hero-bitacora { padding: 18px; } .hero-icon { display: none; } .resumen { grid-template-columns: 1fr 1fr; gap: 8px; } .stat { min-height: 82px; padding: 13px 15px; } .stat .num { font-size: 26px; } }
    </style>
</head>
<body class="bitacora-page">
<div class="app">
    <aside class="sidebar">
        <div class="marca"><img class="marca-logo" src="assets/img/logo.png" alt="MDM"></div>
        <?php require __DIR__ . '/partials/menu.php'; ?>
    </aside>

    <main class="principal bitacora-principal">
        <header class="hero-bitacora">
            <div class="hero-copy">
                <div class="hero-icon"><i data-lucide="scroll-text"></i></div>
                <div>
                    <div class="scope">Servidor <?= htmlspecialchars($canalEtiqueta, ENT_QUOTES, 'UTF-8') ?></div>
                    <h1>Bitacora de actualizaciones</h1>
                    <p>Esta vista muestra unicamente maquinas de <?= htmlspecialchars($canalEtiqueta, ENT_QUOTES, 'UTF-8') ?>.</p>
                </div>
            </div>
            <div class="acciones">
                <a class="btn-bitacora" href="actualizaciones_app.php"><i data-lucide="arrow-left"></i>Volver</a>
                <button class="btn-bitacora primario" id="btn-recargar" type="button"><i data-lucide="refresh-cw"></i>Actualizar</button>
            </div>
        </header>

        <div class="resumen">
            <div class="stat consulta"><div class="lbl">Consultas</div><div class="num" id="kpi-consulta">0</div></div>
            <div class="stat descarga"><div class="lbl">Descargas</div><div class="num" id="kpi-descarga">0</div></div>
            <div class="stat instalacion"><div class="lbl">Instalaciones</div><div class="num" id="kpi-instalacion">0</div></div>
            <div class="stat error"><div class="lbl">Errores</div><div class="num" id="kpi-error">0</div></div>
        </div>

        <section class="panel-bitacora">
            <div class="panel-head">
                <div><h2>Eventos recientes</h2><div class="sub" id="conteo-eventos">Ultimos 200 registros, del mas reciente al mas antiguo.</div></div>
            </div>
            <div class="filtros">
                <div class="chips" aria-label="Filtrar por evento">
                    <button class="chip activo" type="button" data-tipo="">Todos</button>
                    <button class="chip" type="button" data-tipo="error">Errores</button>
                    <button class="chip" type="button" data-tipo="instalacion">Instalaciones</button>
                    <button class="chip" type="button" data-tipo="descarga">Descargas</button>
                    <button class="chip" type="button" data-tipo="consulta">Consultas</button>
                </div>
                <label class="buscar"><i data-lucide="search"></i><input id="buscar-evento" type="search" placeholder="Buscar serie, maquina o mensaje" autocomplete="off"></label>
            </div>
            <div id="tabla-eventos" class="estado-tabla">Cargando eventos...</div>
        </section>
    </main>
</div>

<script src="assets/js/comun.js?v=20260912-1"></script>
<script>
const estado = { eventos: [], tipo: '', busqueda: '' };
const $ = selector => document.querySelector(selector);
const esc = valor => String(valor ?? '').replace(/[&<>'"]/g, caracter => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[caracter]));

function fechaPartes(valor) {
    if (!valor) return { fecha: '-', hora: '' };
    const d = new Date(valor);
    if (Number.isNaN(d.getTime())) return { fecha: esc(valor), hora: '' };
    return {
        fecha: d.toLocaleDateString('es-MX', { day: '2-digit', month: 'short', year: 'numeric' }),
        hora: d.toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit', second: '2-digit' }),
    };
}

function jsonBonito(valor) {
    if (!valor) return '';
    try { return JSON.stringify(JSON.parse(valor), null, 2); } catch (_) { return String(valor); }
}

function renderKpis(eventos) {
    const totales = { consulta: 0, descarga: 0, instalacion: 0, error: 0 };
    eventos.forEach(ev => { if (Object.hasOwn(totales, ev.tipo_evento)) totales[ev.tipo_evento]++; });
    Object.entries(totales).forEach(([tipo, total]) => { $(`#kpi-${tipo}`).textContent = total; });
}

function eventosFiltrados() {
    const texto = estado.busqueda.trim().toLocaleLowerCase('es');
    return estado.eventos.filter(ev => {
        if (estado.tipo && ev.tipo_evento !== estado.tipo) return false;
        if (!texto) return true;
        return [ev.numero_serie, ev.nombre_maquina, ev.nombre_ubicacion, ev.codigo_interno, ev.codigo_error, ev.mensaje]
            .some(valor => String(valor ?? '').toLocaleLowerCase('es').includes(texto));
    });
}

function renderEventos() {
    const eventos = eventosFiltrados();
    const contenedor = $('#tabla-eventos');
    $('#conteo-eventos').textContent = `${eventos.length} de ${estado.eventos.length} eventos visibles.`;
    if (!eventos.length) {
        contenedor.className = 'estado-tabla';
        contenedor.textContent = estado.eventos.length ? 'No hay resultados con esos filtros.' : 'No hay eventos registrados para este servidor.';
        return;
    }

    contenedor.className = 'table-shell';
    contenedor.innerHTML = `<table>
        <thead><tr><th>Fecha y hora</th><th>Evento</th><th>Maquina</th><th>Version instalada</th><th>Version objetivo</th><th>Detalle tecnico</th></tr></thead>
        <tbody>${eventos.map(ev => {
            const fecha = fechaPartes(ev.fecha);
            const nombre = ev.nombre_maquina || ev.nombre_ubicacion || ev.codigo_interno || 'Sin nombre registrado';
            const detalle = ev.codigo_error || ev.mensaje || '';
            return `<tr class="${ev.tipo_evento === 'error' ? 'row-error' : (ev.tipo_evento === 'instalacion' ? 'row-ok' : '')}">
                <td class="fecha">${fecha.fecha}<small>${fecha.hora}</small></td>
                <td><span class="badge badge-${esc(ev.tipo_evento || 'consulta')}">${esc(ev.tipo_evento || 'consulta')}</span></td>
                <td class="maquina"><strong>${esc(ev.numero_serie || 'Sin serie')}</strong><span class="secundario">${esc(nombre)}</span></td>
                <td class="version"><strong>${esc(ev.version_name_actual || '-')}</strong><span class="secundario">Codigo ${esc(ev.version_code_actual || '-')}</span></td>
                <td class="version"><strong>${esc(ev.version_code_objetivo || '-')}</strong></td>
                <td class="detalle">
                    ${detalle ? esc(detalle) : '<span class="secundario">Sin incidencias</span>'}
                    ${ev.payload_json ? `<details><summary>Solicitud enviada</summary><pre class="json-box">${esc(jsonBonito(ev.payload_json))}</pre></details>` : ''}
                    ${ev.respuesta_json ? `<details><summary>Respuesta del servidor</summary><pre class="json-box">${esc(jsonBonito(ev.respuesta_json))}</pre></details>` : ''}
                </td>
            </tr>`;
        }).join('')}</tbody>
    </table>`;
}

async function cargar() {
    const contenedor = $('#tabla-eventos');
    contenedor.className = 'estado-tabla';
    contenedor.textContent = 'Cargando eventos...';
    $('#btn-recargar').disabled = true;
    try {
        const json = await obtenerJSON('api/app_actualizaciones_eventos.php?limite=200');
        estado.eventos = Array.isArray(json.data) ? json.data : [];
        renderKpis(estado.eventos);
        renderEventos();
    } catch (error) {
        contenedor.className = 'estado-tabla';
        contenedor.textContent = error.message || 'No se pudo cargar la bitacora.';
    } finally {
        $('#btn-recargar').disabled = false;
    }
}

document.querySelectorAll('[data-tipo]').forEach(boton => boton.addEventListener('click', () => {
    estado.tipo = boton.dataset.tipo || '';
    document.querySelectorAll('[data-tipo]').forEach(item => item.classList.toggle('activo', item === boton));
    renderEventos();
}));
$('#buscar-evento').addEventListener('input', evento => { estado.busqueda = evento.target.value; renderEventos(); });
$('#btn-recargar').addEventListener('click', cargar);
cargar();
</script>
</body>
</html>
