<?php
// public/abasto_maquinas.php — reporte de abasto por máquina: reglas de
// docs/abasto_maquina.xlsx aplicadas a las máquinas vendingm. Región ->
// máquinas por ponderación -> medicamentos con carril y botón Resurtir
// (mismo motor de carga que Resurtir: api/resurtir_vendingm.php).
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/bootstrap.php';

use App\Auth\Auth;

Auth::requireAdmin();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>MDM - Abasto por máquina</title>
    <meta name="csrf-token" content="<?= htmlspecialchars(Auth::csrfToken()) ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/estilo.css?v=20260914-2">
    <style>
        body.tema-oscuro {
            --bg: #0A0F14; --bg-elevada: #121A21; --bg-hover: #1E2830;
            --linea: #243039; --linea-suave: #1C2630;
            --texto: #EEF4F7; --texto-suave: #9FB2BC; --texto-tenue: #5E707B;
            --petroleo: #2CD9BE; --petroleo-wash: rgba(44, 217, 190, .13);
            --ambar: #E4AC4F; --ambar-wash: rgba(228, 172, 79, .15);
            --critico: #F1707E; --critico-wash: rgba(241, 112, 126, .15);
            background: var(--bg); color: var(--texto);
        }
        body.tema-oscuro .sidebar { background: var(--bg-elevada); border-right: 1px solid var(--linea); }
        body.tema-oscuro .nav-lateral a { color: var(--texto-suave); }
        body.tema-oscuro .nav-lateral a:hover { background: var(--bg-hover); color: var(--texto); }
        body.tema-oscuro .nav-lateral a.activa { background: var(--petroleo-wash); color: var(--petroleo); }

        .encabezado-pagina h1 { font-size: 22px; margin: 0 0 4px; }
        .encabezado-pagina p { margin: 0 0 20px; color: var(--texto-suave); font-size: 13.5px; max-width: 720px; }

        .toolbar-card { background: var(--bg-elevada); border: 1px solid var(--linea); border-radius: 14px; padding: 14px 16px; margin-bottom: 14px; }
        .barra { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
        .barra label { font-size: 12.5px; color: var(--texto-suave); }
        .barra select { padding: 9px 11px; border: 1px solid var(--linea); border-radius: 8px; font-size: 13px; background: var(--bg); color: var(--texto); font-family: inherit; min-width: 260px; }
        .contador { font-size: 12px; color: var(--texto-tenue); margin-left: auto; }

        .card { background: var(--bg-elevada); border: 1px solid var(--linea); border-radius: 14px; }
        .card-scroll { max-height: 640px; overflow-y: auto; }
        .panel-dos-columnas { display: grid; grid-template-columns: 380px 1fr; gap: 14px; align-items: start; }
        @media (max-width: 1000px) { .panel-dos-columnas { grid-template-columns: 1fr; } }
        .vacio, .cargando { color: var(--texto-tenue); font-style: italic; padding: 40px 14px; text-align: center; }

        .lista-maquinas { display: grid; gap: 8px; padding: 12px; }
        .item-maquina { border: 1px solid var(--linea-suave); border-radius: 10px; padding: 10px 14px; cursor: pointer; background: var(--bg); }
        .item-maquina:hover { border-color: var(--petroleo); }
        .item-maquina.seleccionada { border-color: var(--petroleo); background: var(--petroleo-wash); }
        .item-maquina .fila { display: flex; justify-content: space-between; gap: 10px; align-items: center; }
        .item-maquina .nombre { font-weight: 700; font-size: 13px; }
        .item-maquina .ubicacion { color: var(--texto-suave); font-size: 12px; margin-top: 2px; }
        .item-maquina .pond { font-family: 'JetBrains Mono', monospace; font-weight: 700; font-size: 14px; white-space: nowrap; }
        .item-maquina .pond.alta { color: var(--critico); }
        .item-maquina .pond.media { color: var(--ambar); }
        .item-maquina .pond.nula { color: var(--texto-tenue); font-weight: 400; }
        .item-maquina .metricas { color: var(--texto-tenue); font-size: 11.5px; margin-top: 6px; display: flex; gap: 12px; flex-wrap: wrap; }

        .detalle-cabecera { padding: 14px 16px; border-bottom: 1px solid var(--linea-suave); }
        .detalle-cabecera h2 { margin: 0; font-size: 14px; }
        .detalle-cabecera .sub { color: var(--texto-suave); font-size: 12px; margin-top: 4px; }
        .detalle-cabecera { display: flex; justify-content: space-between; gap: 12px; align-items: flex-start; }
        .toggle-vista { display: flex; gap: 4px; flex-shrink: 0; }
        .toggle-vista button { border: 1px solid var(--linea); background: var(--bg); color: var(--texto-suave); font-family: inherit; font-weight: 700; font-size: 12px; padding: 7px 12px; border-radius: 8px; cursor: pointer; }
        .toggle-vista button.activo { border-color: var(--petroleo); color: var(--petroleo); background: var(--petroleo-wash); }

        /* Vista gráfica: 9 columnas por fila (carril = fila*10 + columna 1..9). */
        .mapa-maquina { padding: 16px; max-height: 640px; overflow: auto; }
        .mapa-leyenda { display: flex; gap: 14px; flex-wrap: wrap; margin-bottom: 14px; font-size: 11.5px; color: var(--texto-suave); }
        .mapa-leyenda span { display: inline-flex; align-items: center; gap: 6px; }
        .mapa-leyenda i { width: 12px; height: 12px; border-radius: 50%; display: inline-block; }
        .mapa-fila { display: grid; grid-template-columns: 28px repeat(9, minmax(0, 1fr)); gap: 8px; align-items: center; margin-bottom: 8px; }
        .mapa-fila .etq { font-family: 'JetBrains Mono', monospace; font-size: 11px; color: var(--texto-tenue); text-align: right; }
        .carril-circulo { aspect-ratio: 1 / 1; width: 100%; max-width: 52px; justify-self: center; border-radius: 50%; border: 2px solid transparent; display: flex; align-items: center; justify-content: center; font-family: 'JetBrains Mono', monospace; font-size: 12px; font-weight: 700; padding: 0; cursor: pointer; color: #06201F; }
        .carril-circulo:hover { transform: scale(1.1); }
        .carril-circulo.optimo { background: var(--petroleo); }
        .carril-circulo.bueno { background: #6CB4EE; }
        .carril-circulo.bajo { background: var(--ambar); }
        .carril-circulo.alerta { background: var(--critico); }
        .carril-circulo.vacio-slot { background: transparent; border: 2px dashed var(--linea); color: var(--texto-tenue); cursor: default; font-weight: 400; }
        .carril-circulo.vacio-slot:hover { transform: none; }
        .btn-abastecer { margin-top: 10px; width: 100%; border: 1px solid var(--petroleo); background: var(--petroleo-wash); color: var(--petroleo); font-family: inherit; font-weight: 700; font-size: 12px; padding: 8px 10px; border-radius: 8px; cursor: pointer; }
        .btn-abastecer:hover { background: var(--petroleo); color: #06201F; }
        .modal.ancho { max-width: 940px; }
        .ab-barra { display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end; margin-bottom: 12px; }
        .ab-barra .campo { margin-bottom: 0; }
        .ab-modos { display: flex; gap: 4px; }
        .ab-modos button { border: 1px solid var(--linea); background: var(--bg); color: var(--texto-suave); font-family: inherit; font-weight: 700; font-size: 12px; padding: 9px 14px; border-radius: 8px; cursor: pointer; }
        .ab-modos button.activo { border-color: var(--petroleo); color: var(--petroleo); background: var(--petroleo-wash); }
        .ab-resumen { color: var(--texto-tenue); font-size: 12px; margin-bottom: 8px; }
        .ab-lista { max-height: 50vh; overflow: auto; border: 1px solid var(--linea-suave); border-radius: 10px; }
        .ab-fila { border-bottom: 1px solid var(--linea-suave); padding: 10px 12px; }
        .ab-fila:last-child { border-bottom: none; }
        .ab-fila.marcada { background: var(--petroleo-wash); }
        .ab-cab { display: grid; grid-template-columns: 22px 54px 1fr auto auto; gap: 10px; align-items: center; cursor: pointer; }
        .ab-med { font-size: 12.5px; }
        .ab-med small { display: block; color: var(--texto-tenue); font-size: 11px; margin-top: 2px; }
        .ab-num { font-weight: 700; }
        .ab-falta { color: var(--ambar); font-size: 12px; white-space: nowrap; }
        .ab-editor { margin: 10px 0 2px 32px; }
        .ab-linea { display: grid; grid-template-columns: 1.2fr 1fr 90px 32px; gap: 8px; margin-bottom: 6px; }
        .ab-linea input { border: 1px solid var(--linea); border-radius: 7px; background: var(--bg); color: var(--texto); padding: 7px 9px; font: inherit; font-size: 12.5px; min-width: 0; }
        .ab-quitar { border: 1px solid var(--linea); background: var(--bg); color: var(--critico); border-radius: 7px; cursor: pointer; font-size: 16px; line-height: 1; }
        .ab-pie { display: flex; justify-content: space-between; align-items: center; gap: 10px; }
        .ab-mas { border: none; background: transparent; color: var(--petroleo); font-family: inherit; font-weight: 700; font-size: 12px; cursor: pointer; padding: 4px 0; }
        .ab-cuenta { font-size: 11.5px; }
        @media (max-width: 700px) { .ab-cab { grid-template-columns: 22px 44px 1fr; } .ab-cab .badge, .ab-cab .ab-falta { grid-column: 3; } .ab-linea { grid-template-columns: 1fr 1fr; } }
        .ab-puntos { display: flex; gap: 6px; margin-bottom: 10px; }
        .ab-punto { height: 6px; flex: 1; border-radius: 3px; background: var(--linea); }
        .ab-punto.hecho { background: var(--petroleo); opacity: .55; }
        .ab-punto.actual { background: var(--petroleo); }
        .ab-paso-titulo { font-size: 14px; margin-bottom: 4px; }
        .ab-paso-sub { color: var(--texto-suave); font-size: 12px; margin-bottom: 12px; }
        .ab-paso-cuerpo { border: 1px solid var(--linea-suave); border-radius: 10px; padding: 12px; max-height: 46vh; overflow: auto; }
        .ab-paso-nav { display: flex; justify-content: space-between; gap: 8px; margin-top: 12px; }
        .ab-paso-nav button { font-family: inherit; font-weight: 700; font-size: 13px; padding: 9px 16px; border-radius: 8px; cursor: pointer; }
        .ab-paso-nav .cancelar { border: 1px solid var(--linea); background: var(--bg); color: var(--texto-suave); }
        .ab-paso-nav .confirmar { border: none; background: var(--petroleo); color: #06201F; margin-left: auto; }
        .ab-nota-paso { color: var(--texto-tenue); font-size: 11.5px; margin-top: 10px; font-style: italic; }
        .ab-resumen-tabla { max-height: 44vh; overflow: auto; }
        .ab-resumen-tabla table.tabla th { position: sticky; top: 0; background: var(--bg-elevada); }
        .tabs-cabina { display: flex; gap: 4px; margin-bottom: 14px; }
        .tabs-cabina button { border: 1px solid var(--linea); background: var(--bg); color: var(--texto-suave); font-family: inherit; font-weight: 700; font-size: 12px; padding: 7px 14px; border-radius: 8px; cursor: pointer; }
        .tabs-cabina button.activo { border-color: var(--petroleo); color: var(--petroleo); background: var(--petroleo-wash); }
        .tabs-cabina .n { font-family: 'JetBrains Mono', monospace; font-weight: 500; opacity: .7; margin-left: 4px; }
        .mapa-contenido { transition: opacity .18s ease; opacity: 1; }
        .mapa-contenido.fuera { opacity: 0; }
        .mapa-titulo { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: var(--texto-suave); margin: 4px 0 10px; }
        .mapa-titulo.esclava { margin-top: 22px; padding-top: 14px; border-top: 1px dashed var(--linea); }
        .mapa-nota { color: var(--texto-tenue); font-size: 12px; font-style: italic; }
        .tag-esclava { display: inline-block; font-size: 10px; font-weight: 700; padding: 1px 6px; border-radius: 5px; background: rgba(255,255,255,.08); color: var(--texto-suave); margin-left: 4px; }
        table.tabla { width: 100%; border-collapse: collapse; }
        table.tabla th, table.tabla td { padding: 11px 14px; text-align: left; font-size: 12.5px; border-bottom: 1px solid var(--linea-suave); vertical-align: middle; }
        table.tabla th { color: var(--texto-tenue); font-size: 11px; text-transform: uppercase; letter-spacing: .04em; position: sticky; top: 0; background: var(--bg-elevada); z-index: 1; }
        .tabla-scroll { max-height: 640px; overflow: auto; }
        table.tabla tr:last-child td { border-bottom: none; }
        table.tabla tr:hover { background: var(--bg-hover); }
        .mono { font-family: 'JetBrains Mono', monospace; }
        .badge { display: inline-flex; padding: 3px 10px; border-radius: 999px; font-size: 11px; font-weight: 700; white-space: nowrap; }
        .badge-optimo { background: var(--petroleo-wash); color: var(--petroleo); }
        .badge-bueno { background: rgba(255,255,255,.08); color: var(--texto); }
        .badge-bajo { background: var(--ambar-wash); color: var(--ambar); }
        .badge-alerta { background: var(--critico-wash); color: var(--critico); }
        .btn-resurtir { border: none; background: var(--petroleo); color: #06201F; font-family: inherit; font-weight: 700; font-size: 12px; padding: 7px 14px; border-radius: 7px; cursor: pointer; white-space: nowrap; }
        .btn-resurtir:hover { filter: brightness(1.1); }

        .modal-fondo { position: fixed; inset: 0; background: rgba(0,0,0,.6); display: none; align-items: center; justify-content: center; z-index: 50; padding: 16px; }
        .modal-fondo.abierto { display: flex; }
        .modal { background: var(--bg-elevada); border: 1px solid var(--linea); border-radius: 14px; width: 100%; max-width: 440px; padding: 20px; }
        .modal h3 { margin: 0 0 4px; font-size: 16px; }
        .modal .sub { color: var(--texto-suave); font-size: 12.5px; margin-bottom: 14px; }
        .modal .campo { display: flex; flex-direction: column; gap: 5px; margin-bottom: 12px; }
        .modal .campo label { font-size: 11px; font-weight: 700; text-transform: uppercase; color: var(--texto-tenue); }
        .modal .campo input { border: 1px solid var(--linea); border-radius: 8px; background: var(--bg); color: var(--texto); padding: 9px 11px; font: inherit; font-size: 13px; }
        .modal .acciones { display: flex; gap: 8px; justify-content: flex-end; margin-top: 16px; }
        .modal .acciones button { font-family: inherit; font-weight: 700; font-size: 13px; padding: 9px 16px; border-radius: 8px; cursor: pointer; }
        .modal .acciones .cancelar { border: 1px solid var(--linea); background: var(--bg); color: var(--texto-suave); }
        .modal .acciones .confirmar { border: none; background: var(--petroleo); color: #06201F; }
        .modal .acciones button:disabled { opacity: .5; cursor: not-allowed; }
        .modal-mensaje { font-size: 12px; min-height: 16px; }
        .modal-mensaje.error { color: var(--critico); }
        .modal-mensaje.ok { color: var(--petroleo); }
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
            <h1>Abasto por máquina</h1>
            <p>Reglas del Excel de abasto aplicadas a cada máquina: &ge; 80% OPTIMO, &le; 70% BAJO, &le; 60% ALERTA TOTAL, el resto BUENO. Las máquinas se ordenan por ponderación (suma del peso de los medicamentos en BAJO o ALERTA TOTAL).</p>
        </div>

        <div class="toolbar-card">
            <div class="barra">
                <label for="select-region">Región</label>
                <select id="select-region"><option value="">Cargando…</option></select>
                <span class="contador" id="contador"></span>
            </div>
        </div>

        <div class="panel-dos-columnas">
            <div class="card card-scroll">
                <div id="lista-maquinas" class="vacio">Selecciona una región para ver sus máquinas.</div>
            </div>
            <div class="card">
                <div class="detalle-cabecera" id="detalle-cabecera" style="display:none;">
                    <div>
                        <h2 id="detalle-titulo"></h2>
                        <div class="sub" id="detalle-sub"></div>
                    </div>
                    <div class="toggle-vista">
                        <button type="button" data-vista="lista" class="activo">Lista</button>
                        <button type="button" data-vista="grafica">Gráfica</button>
                        <button type="button" data-vista="claves">Clave de salud</button>
                    </div>
                </div>
                <div id="detalle" class="vacio">Selecciona una máquina para ver sus medicamentos.</div>
            </div>
        </div>
    </main>
</div>

<div class="modal-fondo" id="modal-resurtir">
    <div class="modal">
        <h3 id="modal-titulo">Resurtir</h3>
        <div class="sub" id="modal-sub"></div>
        <div class="campo"><label for="m-cantidad">Cantidad nueva en el carril</label><input type="number" id="m-cantidad" min="0"></div>
        <div class="campo"><label for="m-lote">Número de lote</label><input type="text" id="m-lote" autocomplete="off"></div>
        <div class="campo"><label for="m-caducidad">Fecha de caducidad</label><input type="date" id="m-caducidad"></div>
        <div class="modal-mensaje" id="modal-mensaje"></div>
        <div class="acciones">
            <button type="button" class="cancelar" id="m-cancelar">Cancelar</button>
            <button type="button" class="confirmar" id="m-confirmar">Resurtir</button>
        </div>
    </div>
</div>

<div class="modal-fondo" id="modal-clave">
    <div class="modal">
        <h3 id="mc-titulo">Resurtir medicamento completo</h3>
        <div class="sub" id="mc-sub"></div>
        <div class="campo"><label for="mc-lote">Número de lote</label><input type="text" id="mc-lote" autocomplete="off"></div>
        <div class="campo"><label for="mc-caducidad">Fecha de caducidad</label><input type="date" id="mc-caducidad"></div>
        <div class="modal-mensaje" id="mc-mensaje"></div>
        <div class="acciones">
            <button type="button" class="cancelar" id="mc-cancelar">Cancelar</button>
            <button type="button" class="confirmar" id="mc-confirmar">Resurtir todo</button>
        </div>
    </div>
</div>

<div class="modal-fondo" id="modal-abastecer">
    <div class="modal ancho">
        <h3 id="ab-titulo">Abastecer</h3>
        <div class="sub"><b>Completo</b>: un asistente te pide los lotes y caducidades por clave de salud y reparte las piezas del carril más vacío al menos vacío. <b>Parcial</b>: marcas carriles y en cada uno repartes las piezas en uno o más lotes (por ejemplo 6 del lote 8 y 4 del lote 5). Los carriles se identifican como <b>M-</b> (maestra) y <b>E-</b> (esclava).</div>
        <div class="ab-barra">
            <div class="ab-modos">
                <button type="button" data-modo="completo">Completo</button>
                <button type="button" data-modo="parcial" class="activo">Parcial</button>
            </div>
            <div class="campo"><label for="ab-lote-gen">Lote general (opcional)</label><input type="text" id="ab-lote-gen" autocomplete="off" placeholder="Se usa si una línea no trae lote"></div>
            <div class="campo"><label for="ab-cad-gen">Caducidad general (opcional)</label><input type="date" id="ab-cad-gen"></div>
            <div class="campo"><label for="ab-buscar">Buscar carril / medicamento (parcial)</label><input type="text" id="ab-buscar" autocomplete="off" placeholder="Carril, nombre o clave"></div>
        </div>
        <div id="ab-parcial">
            <div class="ab-resumen" id="ab-resumen"></div>
            <div class="ab-lista" id="ab-lista"></div>
        </div>
        <div id="ab-stepper" hidden>
            <div id="ab-paso-cab"></div>
            <div class="ab-paso-cuerpo" id="ab-paso-cuerpo"></div>
            <div class="ab-paso-nav">
                <button type="button" class="cancelar" id="ab-ant">‹ Anterior</button>
                <button type="button" class="confirmar" id="ab-sig">Siguiente ›</button>
            </div>
        </div>
        <div class="modal-mensaje" id="ab-mensaje" style="margin-top:10px;"></div>
        <div class="acciones">
            <button type="button" class="cancelar" id="ab-cancelar">Cancelar</button>
            <button type="button" class="confirmar" id="ab-confirmar">Abastecer</button>
        </div>
    </div>
</div>

<script src="assets/js/comun.js?v=20260912-1"></script>
<script>
    const fmtPct = n => (n === null || n === undefined) ? '—' : `${Number(n).toFixed(1)}%`;
    const claseEstatus = e => ({ 'OPTIMO': 'badge-optimo', 'BUENO': 'badge-bueno', 'BAJO': 'badge-bajo', 'ALERTA TOTAL': 'badge-alerta' }[e] || 'badge-bueno');

    let regionActual = null;
    let maquinaActual = null;   // { id_maquina, numero_serie, ... }
    let carrilEnModal = null;
    let detalleActual = null;   // respuesta de ?accion=detalle de la máquina abierta
    let vistaActual = 'lista';  // 'lista' | 'grafica' | 'claves'
    let cabinaActual = 'maestra';  // pestaña activa de la vista Gráfica: 'maestra' | 'esclava'

    function nombreRegion(r) { return r === null ? 'Sin región' : r; }

    async function cargarRegiones() {
        const sel = document.getElementById('select-region');
        try {
            const json = await obtenerJSON('api/abasto_maquinas.php?accion=regiones');
            const regiones = json.data || [];
            sel.innerHTML = '<option value="">Selecciona una región…</option>' + regiones.map(r =>
                `<option value="${escapeHtml(r.region ?? '__sin_region__')}">${escapeHtml(nombreRegion(r.region))} (${r.total_maquinas} máquina${r.total_maquinas === 1 ? '' : 's'}${r.total_alertas_totales > 0 ? `, ${r.total_alertas_totales} en alerta total` : ''})</option>`
            ).join('');
        } catch (e) {
            console.error('abasto_maquinas regiones:', e);
            sel.innerHTML = `<option value="">No se pudieron cargar las regiones (${escapeHtml(e.message || 'error')})</option>`;
        }
    }

    function itemMaquina(m) {
        const clasePond = m.ponderacion >= 20 ? 'alta' : (m.ponderacion > 0 ? 'media' : 'nula');
        const sel = maquinaActual && maquinaActual.id_maquina === m.id_maquina ? ' seleccionada' : '';
        return `<div class="item-maquina${sel}" data-id="${m.id_maquina}">
            <div class="fila">
                <div>
                    <div class="nombre">${escapeHtml(m.nombre_maquina || m.numero_serie)}</div>
                    <div class="ubicacion">${escapeHtml(m.nombre_ubicacion || m.numero_serie)}</div>
                </div>
                <div class="pond ${clasePond}" title="Ponderación en riesgo">${Number(m.ponderacion).toFixed(1)}%</div>
            </div>
            <div class="metricas">
                <span>Abasto ${fmtPct(m.porcentaje_abasto)}</span>
                <span>${m.alertas_totales} alerta${m.alertas_totales === 1 ? '' : 's'} total${m.alertas_totales === 1 ? '' : 'es'}</span>
                <span>${m.total_medicamentos} medicamento${m.total_medicamentos === 1 ? '' : 's'}</span>
            </div>
            <button type="button" class="btn-abastecer" data-id="${m.id_maquina}">Abastecer completo/parcial</button>
        </div>`;
    }

    async function cargarMaquinas() {
        const cont = document.getElementById('lista-maquinas');
        if (regionActual === null) {
            cont.className = 'vacio';
            cont.textContent = 'Selecciona una región para ver sus máquinas.';
            return;
        }
        cont.className = 'cargando';
        cont.textContent = 'Cargando…';
        try {
            const json = await obtenerJSON(`api/abasto_maquinas.php?accion=maquinas&region=${encodeURIComponent(regionActual)}`);
            const lista = json.data || [];
            document.getElementById('contador').textContent = `${lista.length} máquina(s)`;
            if (lista.length === 0) {
                cont.className = 'vacio';
                cont.textContent = 'No hay máquinas en esta región.';
                return;
            }
            cont.className = 'lista-maquinas';
            cont.innerHTML = lista.map(itemMaquina).join('');
            cont.querySelectorAll('.btn-abastecer').forEach(btn => {
                btn.addEventListener('click', ev => {
                    ev.stopPropagation();   // no cambia la máquina seleccionada, solo abre la modal
                    abrirAbastecer(lista.find(m => String(m.id_maquina) === btn.dataset.id));
                });
            });
            cont.querySelectorAll('.item-maquina').forEach(el => {
                el.addEventListener('click', () => {
                    maquinaActual = lista.find(m => String(m.id_maquina) === el.dataset.id);
                    cont.querySelectorAll('.item-maquina').forEach(x => x.classList.toggle('seleccionada', x === el));
                    cargarDetalle();
                });
            });
        } catch (e) {
            cont.className = 'vacio';
            cont.textContent = e.message || 'No se pudo cargar el listado.';
        }
    }

    async function cargarDetalle() {
        const cont = document.getElementById('detalle');
        const cab = document.getElementById('detalle-cabecera');
        if (!maquinaActual) { return; }
        cont.className = 'cargando';
        cont.textContent = 'Cargando…';
        try {
            const json = await obtenerJSON(`api/abasto_maquinas.php?accion=detalle&id_maquina=${maquinaActual.id_maquina}`);
            const m = json.data;
            cab.style.display = '';
            document.getElementById('detalle-titulo').textContent = m.nombre_maquina || m.numero_serie;
            document.getElementById('detalle-sub').textContent =
                `${m.nombre_ubicacion || ''} · Abasto ${fmtPct(m.porcentaje_abasto)} · Ponderación en riesgo ${Number(m.ponderacion).toFixed(1)}%`;

            detalleActual = m;
            renderDetalle();
        } catch (e) {
            cont.className = 'vacio';
            cont.textContent = e.message || 'No se pudo cargar el detalle.';
        }
    }

    const etiquetaCarril = c => (c.cabina === 'esclava' ? 'E-' : 'M-') + c.numero_carril;
    const claveCarril = c => `${c.cabina}-${c.numero_carril}`;

    function renderDetalle() {
        const cont = document.getElementById('detalle');
        const m = detalleActual;
        document.querySelectorAll('.toggle-vista button').forEach(b => b.classList.toggle('activo', b.dataset.vista === vistaActual));

        if (!m.carriles.length) {
            cont.className = 'vacio';
            cont.textContent = 'Esta máquina no tiene medicamentos cargados en sus carriles.';
            return;
        }
        if (vistaActual === 'grafica') { renderMapa(cont, m); return; }
        if (vistaActual === 'claves') { renderClaves(cont, m); return; }

        cont.className = '';
        cont.innerHTML = `<div class="tabla-scroll"><table class="tabla">
            <thead><tr><th>Carril</th><th>Clave</th><th>Código</th><th>Medicamento</th><th>En carril</th><th>% abasto</th><th>Estatus</th><th></th></tr></thead>
            <tbody>${m.carriles.map((c, i) => `<tr>
                <td class="mono">${etiquetaCarril(c)}${c.cabina === 'esclava' ? '<span class="tag-esclava">esclava</span>' : ''}</td>
                <td class="mono">${escapeHtml(c.clave || '—')}</td>
                <td class="mono">${escapeHtml(c.codigo_barras || '—')}</td>
                <td>${escapeHtml(c.nombre || 'Sin nombre')}</td>
                <td class="mono">${c.stock} / ${c.capacidad}</td>
                <td class="mono">${fmtPct(c.porcentaje)}</td>
                <td><span class="badge ${claseEstatus(c.estatus)}">${escapeHtml(c.estatus)}</span></td>
                <td><button type="button" class="btn-resurtir" data-idx="${i}">Resurtir</button></td>
            </tr>`).join('')}</tbody></table></div>`;

        cont.querySelectorAll('.btn-resurtir').forEach(btn => {
            btn.addEventListener('click', () => abrirModal(m.carriles[Number(btn.dataset.idx)]));
        });
    }

    // Una cuadrícula por cabina: fila = floor(carril/10), columna = carril % 10 (1..9).
    // Los carriles múltiplo de 10 no cuentan en el abasto. Los huecos (sin producto)
    // salen punteados. La esclava (si existe) usa la misma numeración que la maestra.
    function htmlCuadricula(carriles, filas) {
        const porNumero = {};
        carriles.forEach(c => { porNumero[c.numero_carril] = c; });
        const claseCirculo = { 'OPTIMO': 'optimo', 'BUENO': 'bueno', 'BAJO': 'bajo', 'ALERTA TOTAL': 'alerta' };
        let html = '';
        for (let f = filas.min; f <= filas.max; f++) {
            html += `<div class="mapa-fila"><div class="etq">F${f + 1}</div>`;
            for (let col = 1; col <= 9; col++) {
                const n = f * 10 + col;
                const c = porNumero[n];
                if (!c) {
                    html += `<button type="button" class="carril-circulo vacio-slot" disabled title="Carril ${n}: sin producto">${n}</button>`;
                } else {
                    html += `<button type="button" class="carril-circulo ${claseCirculo[c.estatus] || 'bueno'}" data-key="${claveCarril(c)}" title="${escapeHtml(c.nombre)} · ${c.stock}/${c.capacidad} · ${escapeHtml(c.estatus)}">${n}</button>`;
                }
            }
            html += '</div>';
        }
        return html;
    }

    function renderMapa(cont, m) {
        const maestra = m.carriles.filter(c => c.cabina !== 'esclava');
        const esclava = m.carriles.filter(c => c.cabina === 'esclava');
        // Mismo rango de filas en ambas cabinas para que se vean iguales.
        const filas = { min: Infinity, max: -Infinity };
        m.carriles.forEach(c => {
            const f = Math.floor(c.numero_carril / 10);
            filas.min = Math.min(filas.min, f);
            filas.max = Math.max(filas.max, f);
        });

        let html = `<div class="mapa-leyenda">
            <span><i style="background:var(--critico)"></i>ALERTA TOTAL</span>
            <span><i style="background:var(--ambar)"></i>BAJO</span>
            <span><i style="background:#6CB4EE"></i>BUENO</span>
            <span><i style="background:var(--petroleo)"></i>OPTIMO</span>
            <span><i style="border:2px dashed var(--linea)"></i>Sin producto</span>
        </div>`;
        const hayEsclava = !!m.id_esclava;
        if (!hayEsclava) { cabinaActual = 'maestra'; }
        // Pestañas Maestra / Esclava (solo si la máquina tiene esclava).
        if (hayEsclava) {
            html += `<div class="tabs-cabina">
                <button type="button" data-cabina="maestra">Maestra <span class="n">${maestra.length}</span></button>
                <button type="button" data-cabina="esclava">Esclava <span class="n">${esclava.length}</span></button>
            </div>`;
        }
        html += '<div class="mapa-contenido" id="mapa-contenido"></div>';
        cont.className = 'mapa-maquina';
        cont.innerHTML = html;

        const contenido = cont.querySelector('#mapa-contenido');
        const porClave = {};
        m.carriles.forEach(c => { porClave[claveCarril(c)] = c; });

        function pintarCabina() {
            if (cabinaActual === 'esclava') {
                contenido.innerHTML = esclava.length
                    ? htmlCuadricula(esclava, filas)
                    : '<div class="mapa-nota">La esclava no tiene medicamentos asignados.</div>';
            } else {
                contenido.innerHTML = htmlCuadricula(maestra, filas);
            }
            cont.querySelectorAll('.tabs-cabina button').forEach(b => b.classList.toggle('activo', b.dataset.cabina === cabinaActual));
            contenido.querySelectorAll('.carril-circulo[data-key]').forEach(btn => {
                btn.addEventListener('click', () => abrirModal(porClave[btn.dataset.key]));
            });
        }
        pintarCabina();

        // Cambio de cabina con fade out / fade in.
        cont.querySelectorAll('.tabs-cabina button').forEach(b => b.addEventListener('click', () => {
            if (b.dataset.cabina === cabinaActual) { return; }
            contenido.classList.add('fuera');
            setTimeout(() => {
                cabinaActual = b.dataset.cabina;
                pintarCabina();
                contenido.classList.remove('fuera');
            }, 180);
        }));
    }

    // Un renglón por medicamento (clave de salud), sumando maestra y esclava.
    function renderClaves(cont, m) {
        const grupos = m.claves || [];
        cont.className = '';
        cont.innerHTML = `<div class="tabla-scroll"><table class="tabla">
            <thead><tr><th>Clave</th><th>Medicamento</th><th>Carriles</th><th>En máquina</th><th>% abasto</th><th>Estatus</th><th>Faltan</th><th>Lotes</th><th></th></tr></thead>
            <tbody>${grupos.map((g, i) => {
                const nM = g.carriles.filter(c => c.cabina !== 'esclava').length;
                const nE = g.carriles.length - nM;
                return `<tr>
                    <td class="mono">${escapeHtml(g.clave || '—')}</td>
                    <td>${escapeHtml(g.nombre || 'Sin nombre')}<div class="mono" style="color:var(--texto-tenue);font-size:11px;">${escapeHtml(g.codigo_barras || '')}</div></td>
                    <td class="mono">${g.carriles.length}${nE ? ` <span style="color:var(--texto-tenue)">(${nM}M + ${nE}E)</span>` : ''}</td>
                    <td class="mono">${g.stock} / ${g.capacidad}</td>
                    <td class="mono">${fmtPct(g.porcentaje)}</td>
                    <td><span class="badge ${claseEstatus(g.estatus)}">${escapeHtml(g.estatus)}</span></td>
                    <td class="mono">${g.faltan}</td>
                    <td style="font-size:11.5px;">${(g.lotes || []).length
                        ? g.lotes.map(l => `<div><span class="mono">${escapeHtml(l.numero_lote)}</span> ×${l.cantidad} <span style="color:var(--texto-tenue)">cad. ${escapeHtml(l.fecha_caducidad)}</span></div>`).join('')
                        : '<span style="color:var(--texto-tenue)">sin lote</span>'}</td>
                    <td><button type="button" class="btn-resurtir" data-idx="${i}" ${g.faltan > 0 ? '' : 'disabled style="opacity:.4;cursor:not-allowed"'}>Resurtir todo</button></td>
                </tr>`;
            }).join('')}</tbody></table></div>`;
        cont.querySelectorAll('.btn-resurtir:not([disabled])').forEach(btn => {
            btn.addEventListener('click', () => abrirModalClave(grupos[Number(btn.dataset.idx)]));
        });
    }

    function abrirModal(carril) {
        carrilEnModal = carril;
        document.getElementById('modal-titulo').textContent = `Resurtir carril ${etiquetaCarril(carril)}${carril.cabina === 'esclava' ? ' (esclava)' : ''}`;
        const faltan = Math.max(0, carril.capacidad - carril.stock);
        document.getElementById('modal-sub').innerHTML =
            `<b>${escapeHtml(carril.nombre)}</b><br>${escapeHtml(carril.codigo_barras || '')}<br>` +
            `En carril: <b>${carril.stock}</b> de ${carril.capacidad} · Faltan: <b>${faltan}</b> · ` +
            `<span class="badge ${claseEstatus(carril.estatus)}">${escapeHtml(carril.estatus)}</span><br>` +
            `Lotes en el carril: ${textoLotes(carril.lotes)}`;
        document.getElementById('m-cantidad').value = carril.capacidad;
        document.getElementById('m-lote').value = '';
        document.getElementById('m-caducidad').value = '';
        const msg = document.getElementById('modal-mensaje');
        msg.className = 'modal-mensaje';
        msg.textContent = '';
        document.getElementById('m-confirmar').disabled = false;
        document.getElementById('modal-resurtir').classList.add('abierto');
    }

    function cerrarModal() {
        document.getElementById('modal-resurtir').classList.remove('abierto');
        carrilEnModal = null;
    }

    async function postResurtir(payload) {
        const res = await fetch('api/resurtir_vendingm.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', ...csrfHeader() },
            body: JSON.stringify(payload),
        });
        return res.json();
    }

    async function confirmarResurtir() {
        const msg = document.getElementById('modal-mensaje');
        const btn = document.getElementById('m-confirmar');
        const cantidadNueva = parseInt(document.getElementById('m-cantidad').value, 10);
        const lote = document.getElementById('m-lote').value.trim();
        const caducidad = document.getElementById('m-caducidad').value;

        if (isNaN(cantidadNueva) || cantidadNueva < 0) {
            msg.className = 'modal-mensaje error';
            msg.textContent = 'Cantidad inválida.';
            return;
        }
        if (cantidadNueva === carrilEnModal.stock) {
            msg.className = 'modal-mensaje';
            msg.textContent = 'Sin cambios: la cantidad es la misma que hay en el carril.';
            return;
        }
        // Lote y caducidad solo se piden al subir stock (igual que Resurtir).
        if (cantidadNueva > carrilEnModal.stock && (!lote || !caducidad)) {
            msg.className = 'modal-mensaje error';
            msg.textContent = 'Para subir stock captura número de lote y fecha de caducidad.';
            return;
        }

        btn.disabled = true;
        msg.className = 'modal-mensaje';
        msg.textContent = 'Guardando…';
        try {
            const json = await postResurtir({
                id_maquina: carrilEnModal.id_maquina ?? maquinaActual.id_maquina,
                numero_carril: carrilEnModal.numero_carril,
                cantidad_actual: carrilEnModal.stock,
                cantidad_nueva: cantidadNueva,
                codigo_barras: carrilEnModal.codigo_barras,
                numero_lote: lote,
                fecha_caducidad: caducidad,
            });
            if (!json.ok) {
                msg.className = 'modal-mensaje error';
                msg.textContent = json.error || 'No se pudo guardar.';
                btn.disabled = false;
                return;
            }
            msg.className = 'modal-mensaje ok';
            msg.textContent = 'Carril actualizado.';
            setTimeout(async () => {
                cerrarModal();
                await cargarMaquinas();   // la ponderación de la máquina cambió
                await cargarDetalle();
            }, 700);
        } catch (e) {
            msg.className = 'modal-mensaje error';
            msg.textContent = 'No se pudo conectar con el servidor.';
            btn.disabled = false;
        }
    }

    // ---- Resurtir un medicamento completo (todos sus carriles, maestra y esclava) ----
    let grupoEnModal = null;

    function abrirModalClave(g) {
        grupoEnModal = g;
        const pendientes = g.carriles.filter(c => c.capacidad > c.stock);
        document.getElementById('mc-titulo').textContent = 'Resurtir medicamento completo';
        document.getElementById('mc-sub').innerHTML =
            `<b>${escapeHtml(g.nombre)}</b><br>Clave: ${escapeHtml(g.clave || '—')}<br>` +
            `En máquina: <b>${g.stock}</b> de ${g.capacidad} · Faltan: <b>${g.faltan}</b> pieza(s) en ` +
            `<b>${pendientes.length}</b> carril(es): ${pendientes.map(c => `${etiquetaCarril(c)} (+${c.capacidad - c.stock})`).join(', ')}<br>` +
            `Cada carril se llena hasta su capacidad con el mismo lote y caducidad.`;
        document.getElementById('mc-lote').value = '';
        document.getElementById('mc-caducidad').value = '';
        const msg = document.getElementById('mc-mensaje');
        msg.className = 'modal-mensaje';
        msg.textContent = '';
        document.getElementById('mc-confirmar').disabled = false;
        document.getElementById('modal-clave').classList.add('abierto');
    }

    function cerrarModalClave() {
        document.getElementById('modal-clave').classList.remove('abierto');
        grupoEnModal = null;
    }

    async function confirmarResurtirClave() {
        const msg = document.getElementById('mc-mensaje');
        const btn = document.getElementById('mc-confirmar');
        const lote = document.getElementById('mc-lote').value.trim();
        const caducidad = document.getElementById('mc-caducidad').value;
        if (!lote || !caducidad) {
            msg.className = 'modal-mensaje error';
            msg.textContent = 'Captura número de lote y fecha de caducidad.';
            return;
        }
        const pendientes = grupoEnModal.carriles.filter(c => c.capacidad > c.stock);
        btn.disabled = true;
        let hechos = 0;
        try {
            // Uno por uno: cada carril es su propio traspaso real. Si uno falla
            // (p. ej. sin existencia en la sucursal) se detiene y se informa
            // cuáles sí quedaron surtidos.
            for (const c of pendientes) {
                msg.className = 'modal-mensaje';
                msg.textContent = `Surtiendo carril ${etiquetaCarril(c)} (${hechos + 1} de ${pendientes.length})…`;
                const json = await postResurtir({
                    id_maquina: c.id_maquina,
                    numero_carril: c.numero_carril,
                    cantidad_actual: c.stock,
                    cantidad_nueva: c.capacidad,
                    codigo_barras: c.codigo_barras,
                    numero_lote: lote,
                    fecha_caducidad: caducidad,
                });
                if (!json.ok) {
                    msg.className = 'modal-mensaje error';
                    msg.textContent = `Carril ${etiquetaCarril(c)}: ${json.error || 'no se pudo guardar'}. ` +
                        `Surtidos antes del error: ${hechos} de ${pendientes.length}.`;
                    btn.disabled = false;
                    if (hechos > 0) { await cargarMaquinas(); await cargarDetalle(); }
                    return;
                }
                hechos++;
            }
            msg.className = 'modal-mensaje ok';
            msg.textContent = `Listo: ${hechos} carril(es) surtidos.`;
            setTimeout(async () => {
                cerrarModalClave();
                await cargarMaquinas();
                await cargarDetalle();
            }, 900);
        } catch (e) {
            msg.className = 'modal-mensaje error';
            msg.textContent = `No se pudo conectar con el servidor. Surtidos antes del error: ${hechos} de ${pendientes.length}.`;
            btn.disabled = false;
            if (hechos > 0) { await cargarMaquinas(); await cargarDetalle(); }
        }
    }

    // ---- Abastecer completo / parcial ----
    // PARCIAL: se marcan carriles a mano y en cada uno se reparten las piezas en
    // uno o más lotes (lote, caducidad, cantidad).
    // COMPLETO: un stepper pide lotes y caducidades por CLAVE DE SALUD (no por
    // carril). Las piezas de cada lote se asignan a los carriles del medicamento
    // del más vacío al menos vacío (menor % de abasto primero).
    // En ambos casos, por cada (carril, lote) se llama a api/resurtir_vendingm.php,
    // que crea su lote y su traspaso real, subiendo la cantidad acumulada del carril.
    let abastecerMaquina = null;   // detalle de la máquina abierta en la modal
    let modoAb = 'parcial';        // 'parcial' | 'completo'
    let pasosAb = [];              // pasos del stepper: grupos (claves) con faltante
    let datosPasosAb = [];         // líneas capturadas por paso: [{lote, cad, cant}]
    let pasoActualAb = 0;          // 0..pasosAb.length (el último es el resumen)

    const hoyISO = () => new Date().toISOString().slice(0, 10);

    function textoLotes(lotes) {
        if (!lotes || !lotes.length) { return 'sin lote registrado'; }
        return lotes.map(l => `${escapeHtml(l.numero_lote)} ×${l.cantidad} (cad. ${escapeHtml(l.fecha_caducidad)})`).join(' · ');
    }

    function lineaLoteHtml(cant, lote = '', cad = '') {
        return `<div class="ab-linea">
            <input type="text" class="ab-lote" placeholder="Lote" value="${escapeHtml(lote)}" autocomplete="off">
            <input type="date" class="ab-cad" value="${escapeHtml(cad)}" min="${hoyISO()}">
            <input type="number" class="ab-cant" min="1" step="1" value="${cant}" title="Piezas de este lote">
            <button type="button" class="ab-quitar" title="Quitar lote">×</button>
        </div>`;
    }

    const errorAb = texto => {
        const msg = document.getElementById('ab-mensaje');
        msg.className = 'modal-mensaje error';
        msg.textContent = texto;
    };
    const limpiarMensajeAb = () => {
        const msg = document.getElementById('ab-mensaje');
        msg.className = 'modal-mensaje';
        msg.textContent = '';
    };

    async function abrirAbastecer(maquina) {
        const modal = document.getElementById('modal-abastecer');
        const lista = document.getElementById('ab-lista');
        document.getElementById('ab-titulo').textContent = `Abastecer · ${maquina.nombre_maquina || maquina.numero_serie}`;
        limpiarMensajeAb();
        document.getElementById('ab-lote-gen').value = '';
        document.getElementById('ab-cad-gen').value = '';
        document.getElementById('ab-buscar').value = '';
        document.getElementById('ab-confirmar').disabled = false;
        lista.innerHTML = '<div class="cargando">Cargando carriles…</div>';
        document.getElementById('ab-stepper').hidden = true;
        modal.classList.add('abierto');
        try {
            const json = await obtenerJSON(`api/abasto_maquinas.php?accion=detalle&id_maquina=${maquina.id_maquina}`);
            abastecerMaquina = json.data;
            renderAbastecer();
            setModoAb('parcial');
        } catch (e) {
            lista.innerHTML = `<div class="vacio">${escapeHtml(e.message || 'No se pudo cargar la máquina.')}</div>`;
        }
    }

    // ----- Modo parcial: lista de carriles con editor de lotes -----
    function renderAbastecer() {
        const m = abastecerMaquina;
        const lista = document.getElementById('ab-lista');
        const conFalta = m.carriles.filter(c => c.capacidad > c.stock);
        const llenos = m.carriles.length - conFalta.length;
        document.getElementById('ab-resumen').textContent =
            `${conFalta.length} carril(es) con faltante` + (llenos ? ` · ${llenos} ya están llenos` : '');
        if (!conFalta.length) {
            lista.innerHTML = '<div class="vacio">Todos los carriles están llenos.</div>';
            return;
        }
        lista.innerHTML = conFalta.map(c => {
            const faltan = c.capacidad - c.stock;
            return `<div class="ab-fila" data-key="${claveCarril(c)}" data-faltan="${faltan}"
                        data-texto="${escapeHtml((etiquetaCarril(c) + ' ' + c.nombre + ' ' + (c.clave || '')).toLowerCase())}">
                <label class="ab-cab">
                    <input type="checkbox" class="ab-chk">
                    <span class="mono ab-num">${etiquetaCarril(c)}</span>
                    <span class="ab-med">${escapeHtml(c.nombre)}<small>${escapeHtml(c.clave || '')} · hoy ${c.stock}/${c.capacidad} · lotes: ${textoLotes(c.lotes)}</small></span>
                    <span class="badge ${claseEstatus(c.estatus)}">${escapeHtml(c.estatus)}</span>
                    <span class="mono ab-falta">faltan ${faltan}</span>
                </label>
                <div class="ab-editor" hidden>
                    <div class="ab-lineas"></div>
                    <div class="ab-pie">
                        <button type="button" class="ab-mas">+ Agregar lote</button>
                        <span class="ab-cuenta mono"></span>
                    </div>
                </div>
            </div>`;
        }).join('');
    }

    function sumaLineasDom(contenedor) {
        let t = 0;
        contenedor.querySelectorAll('.ab-cant').forEach(i => { t += parseInt(i.value, 10) || 0; });
        return t;
    }

    function pintarCuenta(el, asignado, objetivo) {
        el.textContent = `asignado ${asignado} de ${objetivo}`;
        el.style.color = asignado > objetivo ? 'var(--critico)' : (asignado === objetivo ? 'var(--petroleo)' : 'var(--texto-suave)');
    }

    function actualizarCuenta(fila) {
        pintarCuenta(fila.querySelector('.ab-cuenta'), sumaLineasDom(fila), parseInt(fila.dataset.faltan, 10));
    }

    function marcarFila(fila, marcada) {
        fila.querySelector('.ab-chk').checked = marcada;
        fila.querySelector('.ab-editor').hidden = !marcada;
        fila.classList.toggle('marcada', marcada);
        const lineas = fila.querySelector('.ab-lineas');
        if (marcada && !lineas.children.length) {
            lineas.insertAdjacentHTML('beforeend', lineaLoteHtml(parseInt(fila.dataset.faltan, 10)));
        }
        actualizarCuenta(fila);
    }

    document.getElementById('ab-lista').addEventListener('change', e => {
        const fila = e.target.closest('.ab-fila');
        if (fila && e.target.classList.contains('ab-chk')) { marcarFila(fila, e.target.checked); }
    });
    document.getElementById('ab-lista').addEventListener('input', e => {
        const fila = e.target.closest('.ab-fila');
        if (fila && e.target.classList.contains('ab-cant')) { actualizarCuenta(fila); }
    });
    document.getElementById('ab-lista').addEventListener('click', e => {
        const fila = e.target.closest('.ab-fila');
        if (!fila) { return; }
        if (e.target.classList.contains('ab-mas')) {
            const resta = Math.max(1, parseInt(fila.dataset.faltan, 10) - sumaLineasDom(fila));
            fila.querySelector('.ab-lineas').insertAdjacentHTML('beforeend', lineaLoteHtml(resta));
            actualizarCuenta(fila);
        } else if (e.target.classList.contains('ab-quitar')) {
            const lineas = fila.querySelector('.ab-lineas');
            e.target.closest('.ab-linea').remove();
            if (!lineas.children.length) { marcarFila(fila, false); } else { actualizarCuenta(fila); }
        }
    });
    document.getElementById('ab-buscar').addEventListener('input', e => {
        const q = e.target.value.trim().toLowerCase();
        document.querySelectorAll('#ab-lista .ab-fila').forEach(f => { f.hidden = q !== '' && !f.dataset.texto.includes(q); });
    });

    // ----- Cambio de modo -----
    function setModoAb(modo) {
        modoAb = modo;
        limpiarMensajeAb();
        document.querySelectorAll('.ab-modos button').forEach(b => b.classList.toggle('activo', b.dataset.modo === modo));
        const completo = modo === 'completo';
        document.getElementById('ab-parcial').hidden = completo;
        document.getElementById('ab-stepper').hidden = !completo;
        document.querySelectorAll('#ab-lista .ab-fila').forEach(f => marcarFila(f, false));
        if (completo) { iniciarStepper(); } else { actualizarBotonConfirmar(); }
    }

    function actualizarBotonConfirmar() {
        const btn = document.getElementById('ab-confirmar');
        // En completo solo se puede confirmar desde el resumen (último paso).
        btn.hidden = modoAb === 'completo' && pasoActualAb < pasosAb.length;
    }

    document.querySelectorAll('.ab-modos button').forEach(b => b.addEventListener('click', () => setModoAb(b.dataset.modo)));

    // ----- Modo completo: stepper por clave de salud -----
    function iniciarStepper() {
        pasosAb = (abastecerMaquina.claves || []).filter(g => g.faltan > 0);
        datosPasosAb = pasosAb.map(g => [{ lote: '', cad: '', cant: g.faltan }]);
        pasoActualAb = 0;
        renderPaso();
    }

    // Reparte las piezas de cada lote entre los carriles del medicamento, del
    // más vacío al menos vacío (menor % de abasto; a igualdad, más faltante).
    function distribuirGrupo(grupo, lineas) {
        const orden = grupo.carriles
            .map(c => ({ carril: c, resta: c.capacidad - c.stock }))
            .filter(x => x.resta > 0)
            .sort((a, b) => (a.carril.stock / a.carril.capacidad) - (b.carril.stock / b.carril.capacidad) || b.resta - a.resta);
        const porCarril = new Map();
        for (const l of lineas) {
            let pendiente = l.cant;
            for (const x of orden) {
                if (pendiente <= 0) { break; }
                const toma = Math.min(pendiente, x.resta);
                if (toma <= 0) { continue; }
                const k = claveCarril(x.carril);
                if (!porCarril.has(k)) { porCarril.set(k, { carril: x.carril, lineas: [] }); }
                porCarril.get(k).lineas.push({ lote: l.lote, cad: l.cad, cant: toma });
                x.resta -= toma;
                pendiente -= toma;
            }
        }
        return [...porCarril.values()];
    }

    // Lee del DOM las líneas del paso actual y las guarda en el estado.
    function guardarPasoDesdeDom() {
        if (pasoActualAb >= pasosAb.length) { return; }
        const lineas = [];
        document.querySelectorAll('#ab-paso-cuerpo .ab-linea').forEach(l => {
            lineas.push({
                lote: l.querySelector('.ab-lote').value.trim(),
                cad: l.querySelector('.ab-cad').value,
                cant: parseInt(l.querySelector('.ab-cant').value, 10) || 0,
            });
        });
        datosPasosAb[pasoActualAb] = lineas;
    }

    // Líneas del paso ya con el lote/caducidad general aplicados donde falten.
    function lineasEfectivas(i) {
        const loteGen = document.getElementById('ab-lote-gen').value.trim();
        const cadGen = document.getElementById('ab-cad-gen').value;
        return datosPasosAb[i].map(l => ({ lote: l.lote || loteGen, cad: l.cad || cadGen, cant: l.cant }));
    }

    function validarPaso(i) {
        const g = pasosAb[i];
        const lineas = lineasEfectivas(i);
        if (!lineas.length) { return 'Agrega al menos un lote.'; }
        for (const l of lineas) {
            if (!l.lote || !l.cad) { return 'Cada lote necesita número y caducidad (puedes usar el lote general).'; }
            if (!/^\d{4}-\d{2}-\d{2}$/.test(l.cad)) { return 'Hay una caducidad inválida.'; }
            if (l.cant < 1) { return 'La cantidad de cada lote debe ser de 1 o más.'; }
        }
        const suma = lineas.reduce((a, l) => a + l.cant, 0);
        if (suma !== g.faltan) { return `Para un abastecimiento completo asigna exactamente ${g.faltan} pieza(s); llevas ${suma}.`; }
        return null;
    }

    function renderPaso() {
        const cab = document.getElementById('ab-paso-cab');
        const cuerpo = document.getElementById('ab-paso-cuerpo');
        const total = pasosAb.length;
        limpiarMensajeAb();

        if (!total) {
            cab.innerHTML = '';
            cuerpo.innerHTML = '<div class="vacio">Todos los medicamentos están llenos.</div>';
            document.getElementById('ab-ant').hidden = true;
            document.getElementById('ab-sig').hidden = true;
            actualizarBotonConfirmar();
            return;
        }

        const puntos = Array.from({ length: total + 1 }, (_, k) =>
            `<span class="ab-punto${k === pasoActualAb ? ' actual' : (k < pasoActualAb ? ' hecho' : '')}"></span>`).join('');
        document.getElementById('ab-ant').hidden = pasoActualAb === 0;
        document.getElementById('ab-sig').hidden = pasoActualAb >= total;

        if (pasoActualAb < total) {
            const g = pasosAb[pasoActualAb];
            cab.innerHTML = `<div class="ab-puntos">${puntos}</div>
                <div class="ab-paso-titulo">Paso ${pasoActualAb + 1} de ${total} · <b>${escapeHtml(g.nombre)}</b></div>
                <div class="ab-paso-sub">Clave ${escapeHtml(g.clave || '—')} · hoy ${g.stock}/${g.capacidad} · faltan <b>${g.faltan}</b> pieza(s) en ${g.carriles.filter(c => c.capacidad > c.stock).length} carril(es) · lotes actuales: ${textoLotes(g.lotes)}</div>`;
            const lineas = datosPasosAb[pasoActualAb];
            cuerpo.innerHTML = `<div class="ab-lineas">${lineas.map(l => lineaLoteHtml(l.cant || '', l.lote, l.cad)).join('')}</div>
                <div class="ab-pie"><button type="button" class="ab-mas">+ Agregar lote</button><span class="ab-cuenta mono"></span></div>
                <div class="ab-nota-paso">Las piezas se asignan a los carriles de este medicamento del más vacío al menos vacío; no necesitas elegir carril.</div>`;
            pintarCuenta(cuerpo.querySelector('.ab-cuenta'), sumaLineasDom(cuerpo), g.faltan);
        } else {
            // Resumen: qué lote va a qué carril.
            let piezas = 0, filas = '', carriles = new Set(), lotes = 0;
            pasosAb.forEach((g, i) => {
                distribuirGrupo(g, lineasEfectivas(i)).forEach(({ carril, lineas }) => {
                    carriles.add(claveCarril(carril));
                    lineas.forEach(l => {
                        piezas += l.cant;
                        lotes++;
                        filas += `<tr><td>${escapeHtml(g.nombre)}<div class="mono" style="color:var(--texto-tenue);font-size:11px;">${escapeHtml(g.clave || '')}</div></td>
                            <td class="mono">${etiquetaCarril(carril)}</td>
                            <td class="mono">${escapeHtml(l.lote)}</td><td class="mono">${escapeHtml(l.cad)}</td>
                            <td class="mono">+${l.cant}</td></tr>`;
                    });
                });
            });
            cab.innerHTML = `<div class="ab-puntos">${puntos}</div>
                <div class="ab-paso-titulo">Resumen · <b>${carriles.size}</b> carril(es), <b>${piezas}</b> pieza(s), <b>${lotes}</b> lote(s)</div>
                <div class="ab-paso-sub">Revisa la asignación y confirma. Cada lote registra su traspaso real de inventario.</div>`;
            cuerpo.innerHTML = `<div class="ab-resumen-tabla"><table class="tabla"><thead><tr><th>Medicamento</th><th>Carril</th><th>Lote</th><th>Caducidad</th><th>Piezas</th></tr></thead><tbody>${filas}</tbody></table></div>`;
        }
        actualizarBotonConfirmar();
    }

    document.getElementById('ab-paso-cuerpo').addEventListener('input', e => {
        if (e.target.classList.contains('ab-cant')) {
            const g = pasosAb[pasoActualAb];
            pintarCuenta(document.querySelector('#ab-paso-cuerpo .ab-cuenta'), sumaLineasDom(document.getElementById('ab-paso-cuerpo')), g.faltan);
        }
    });
    document.getElementById('ab-paso-cuerpo').addEventListener('click', e => {
        if (pasoActualAb >= pasosAb.length) { return; }
        if (e.target.classList.contains('ab-mas')) {
            guardarPasoDesdeDom();
            const g = pasosAb[pasoActualAb];
            const suma = datosPasosAb[pasoActualAb].reduce((a, l) => a + l.cant, 0);
            datosPasosAb[pasoActualAb].push({ lote: '', cad: '', cant: Math.max(1, g.faltan - suma) });
            renderPaso();
        } else if (e.target.classList.contains('ab-quitar')) {
            guardarPasoDesdeDom();
            const idx = [...document.querySelectorAll('#ab-paso-cuerpo .ab-linea')].indexOf(e.target.closest('.ab-linea'));
            datosPasosAb[pasoActualAb].splice(idx, 1);
            if (!datosPasosAb[pasoActualAb].length) {
                datosPasosAb[pasoActualAb].push({ lote: '', cad: '', cant: pasosAb[pasoActualAb].faltan });
            }
            renderPaso();
        }
    });
    document.getElementById('ab-sig').addEventListener('click', () => {
        guardarPasoDesdeDom();
        const err = validarPaso(pasoActualAb);
        if (err) { return errorAb(err); }
        pasoActualAb++;
        renderPaso();
    });
    document.getElementById('ab-ant').addEventListener('click', () => {
        guardarPasoDesdeDom();
        pasoActualAb = Math.max(0, pasoActualAb - 1);
        renderPaso();
    });

    function cerrarAbastecer() {
        document.getElementById('modal-abastecer').classList.remove('abierto');
        abastecerMaquina = null;
    }

    // ----- Armado del plan y ejecución (común a parcial y completo) -----
    // plan: [{ carril, lineas: [{lote, cad, cant}] }]
    function planParcial() {
        const loteGen = document.getElementById('ab-lote-gen').value.trim();
        const cadGen = document.getElementById('ab-cad-gen').value;
        const marcadas = [...document.querySelectorAll('#ab-lista .ab-fila')].filter(f => f.querySelector('.ab-chk').checked);
        if (!marcadas.length) { return { error: 'Marca al menos un carril.' }; }
        const plan = [];
        for (const fila of marcadas) {
            const carril = abastecerMaquina.carriles.find(c => claveCarril(c) === fila.dataset.key);
            const faltan = parseInt(fila.dataset.faltan, 10);
            const lineas = [];
            for (const l of fila.querySelectorAll('.ab-linea')) {
                const lote = l.querySelector('.ab-lote').value.trim() || loteGen;
                const cad = l.querySelector('.ab-cad').value || cadGen;
                const cant = parseInt(l.querySelector('.ab-cant').value, 10);
                if (!lote || !cad) { return { error: `Carril ${etiquetaCarril(carril)}: falta lote o caducidad (puedes usar el lote general).` }; }
                if (!/^\d{4}-\d{2}-\d{2}$/.test(cad)) { return { error: `Carril ${etiquetaCarril(carril)}: caducidad inválida.` }; }
                if (isNaN(cant) || cant < 1) { return { error: `Carril ${etiquetaCarril(carril)}: la cantidad de cada lote debe ser de 1 o más.` }; }
                lineas.push({ lote, cad, cant });
            }
            const suma = lineas.reduce((a, l) => a + l.cant, 0);
            if (!lineas.length) { return { error: `Carril ${etiquetaCarril(carril)}: agrega al menos un lote.` }; }
            if (suma > faltan) { return { error: `Carril ${etiquetaCarril(carril)}: asignaste ${suma} y solo caben ${faltan}.` }; }
            plan.push({ carril, lineas });
        }
        return { plan };
    }

    function planCompleto() {
        const plan = [];
        for (let i = 0; i < pasosAb.length; i++) {
            const err = validarPaso(i);
            if (err) { return { error: `${pasosAb[i].nombre}: ${err}`, paso: i }; }
            plan.push(...distribuirGrupo(pasosAb[i], lineasEfectivas(i)));
        }
        return { plan };
    }

    async function confirmarAbastecer() {
        const btn = document.getElementById('ab-confirmar');
        if (modoAb === 'completo') { guardarPasoDesdeDom(); }
        const r = modoAb === 'completo' ? planCompleto() : planParcial();
        if (r.error) {
            if (r.paso !== undefined) { pasoActualAb = r.paso; renderPaso(); }
            return errorAb(r.error);
        }
        const plan = r.plan;
        const piezas = plan.reduce((a, p) => a + p.lineas.reduce((x, l) => x + l.cant, 0), 0);
        const lineasTotal = plan.reduce((a, p) => a + p.lineas.length, 0);

        const seguir = await mlConfirm(
            `Se surtirán ${plan.length} carril(es), ${piezas} pieza(s) en ${lineasTotal} lote(s). Cada lote registra su traspaso real de inventario.`,
            'Confirmar abastecimiento', 'Abastecer'
        );
        if (!seguir) { return; }

        // Por carril, una llamada por lote con la cantidad acumulada.
        const msg = document.getElementById('ab-mensaje');
        btn.disabled = true;
        let carrilesListos = 0, lotesListos = 0;
        try {
            for (const { carril, lineas } of plan) {
                let corriente = carril.stock;
                for (const l of lineas) {
                    msg.className = 'modal-mensaje';
                    msg.textContent = `Carril ${etiquetaCarril(carril)} · lote ${l.lote} (${lotesListos + 1} de ${lineasTotal})…`;
                    const json = await postResurtir({
                        id_maquina: carril.id_maquina,
                        numero_carril: carril.numero_carril,
                        cantidad_actual: corriente,
                        cantidad_nueva: corriente + l.cant,
                        codigo_barras: carril.codigo_barras,
                        numero_lote: l.lote,
                        fecha_caducidad: l.cad,
                    });
                    if (!json.ok) {
                        errorAb(`Carril ${etiquetaCarril(carril)}, lote ${l.lote}: ${json.error || 'no se pudo guardar'}. ` +
                            `Ya surtidos: ${carrilesListos} carril(es) completos y ${lotesListos} lote(s) en total.`);
                        btn.disabled = false;
                        if (lotesListos > 0) { await cargarMaquinas(); await cargarDetalle(); }
                        return;
                    }
                    corriente += l.cant;
                    lotesListos++;
                }
                carrilesListos++;
            }
            msg.className = 'modal-mensaje ok';
            msg.textContent = `Listo: ${carrilesListos} carril(es), ${lotesListos} lote(s), ${piezas} pieza(s).`;
            setTimeout(async () => {
                cerrarAbastecer();
                await cargarMaquinas();
                await cargarDetalle();
            }, 1100);
        } catch (e) {
            errorAb(`No se pudo conectar con el servidor. Ya surtidos: ${carrilesListos} carril(es) y ${lotesListos} lote(s).`);
            btn.disabled = false;
            if (lotesListos > 0) { await cargarMaquinas(); await cargarDetalle(); }
        }
    }

    document.getElementById('ab-cancelar').addEventListener('click', cerrarAbastecer);
    document.getElementById('ab-confirmar').addEventListener('click', confirmarAbastecer);
    document.getElementById('modal-abastecer').addEventListener('click', e => {
        if (e.target.id === 'modal-abastecer') { cerrarAbastecer(); }
    });

    document.getElementById('select-region').addEventListener('change', e => {
        regionActual = e.target.value || null;
        maquinaActual = null;
        document.getElementById('detalle-cabecera').style.display = 'none';
        document.getElementById('detalle').className = 'vacio';
        document.getElementById('detalle').textContent = 'Selecciona una máquina para ver sus medicamentos.';
        cargarMaquinas();
    });
    document.querySelectorAll('.toggle-vista button').forEach(b => b.addEventListener('click', () => {
        vistaActual = b.dataset.vista;
        if (detalleActual) { renderDetalle(); }
    }));
    document.getElementById('mc-cancelar').addEventListener('click', cerrarModalClave);
    document.getElementById('mc-confirmar').addEventListener('click', confirmarResurtirClave);
    document.getElementById('modal-clave').addEventListener('click', e => {
        if (e.target.id === 'modal-clave') { cerrarModalClave(); }
    });
    document.getElementById('m-cancelar').addEventListener('click', cerrarModal);
    document.getElementById('m-confirmar').addEventListener('click', confirmarResurtir);
    document.getElementById('modal-resurtir').addEventListener('click', e => {
        if (e.target.id === 'modal-resurtir') { cerrarModal(); }
    });

    cargarRegiones();
</script>
</body>
</html>
