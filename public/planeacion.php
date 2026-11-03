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
    <title>MDM - Planeacion</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/estilo.css?v=20260914-2">
    <style>
        .encabezado-pagina {
            display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap;
            padding-bottom: 4px;
        }
        .encabezado-pagina__etiqueta {
            display: inline-flex; align-items: center; gap: 7px; color: var(--petroleo);
            text-transform: uppercase; letter-spacing: .08em; font-size: 11px; font-weight: 800; margin-bottom: 9px;
        }
        .acciones-head { display: flex; gap: 10px; flex-wrap: wrap; }
        .btn {
            border: 1px solid var(--linea); background: #fff; color: var(--texto);
            border-radius: 8px; padding: 9px 14px; font: inherit; font-size: 13px; font-weight: 700; cursor: pointer;
            box-shadow: 0 2px 6px rgba(20,33,43,.06);
        }
        .btn:hover { border-color: var(--petroleo); color: var(--petroleo); background: #F2F7F6; }
        .btn.primario { background: #0B8F86 !important; color: #fff !important; border-color: #0B8F86 !important; box-shadow: 0 8px 18px rgba(8,125,118,.22); }
        .btn.primario:hover { background: #087D76 !important; color: #fff !important; }
        .kpis { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 14px; margin-bottom: 16px; }
        .kpi {
            position: relative; overflow: hidden; background: #fff; border: 1px solid var(--linea); border-radius: 10px; padding: 16px;
            box-shadow: 0 12px 28px rgba(20,33,43,.08);
        }
        .kpi::before { content: ""; position: absolute; inset: 0 0 auto 0; height: 3px; background: var(--kpi-color, var(--petroleo)); opacity: .82; }
        .kpi .lbl { color: var(--texto-tenue); text-transform: uppercase; font-size: 11px; font-weight: 800; margin-bottom: 8px; }
        .kpi .num { font-family: 'Space Grotesk', sans-serif; font-size: 30px; font-weight: 700; color: var(--kpi-color, var(--texto)); line-height: 1; }
        .kpi .subkpi { color: var(--texto-suave); font-size: 12px; margin-top: 7px; min-height: 16px; }
        .resumen-operativo {
            display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; margin-bottom: 16px;
        }
        .estado-resumen {
            display: flex; justify-content: space-between; align-items: center; gap: 10px;
            background: #fff; border: 1px solid var(--linea); border-radius: 10px; padding: 12px 14px;
            box-shadow: 0 8px 18px rgba(20,33,43,.06);
        }
        .estado-resumen span { color: var(--texto-suave); font-size: 12px; font-weight: 700; }
        .estado-resumen b { font-family: 'JetBrains Mono', monospace; font-size: 17px; color: var(--texto); }
        .panel {
            background: #fff; border: 1px solid var(--linea); border-radius: 10px;
            box-shadow: 0 12px 28px rgba(20,33,43,.08); overflow: hidden;
        }
        .panel-head { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; padding: 16px 18px; border-bottom: 1px solid var(--linea-suave); }
        .panel-head h2 { margin: 0; font-size: 15px; color: var(--texto); }
        .panel-head .sub { color: var(--texto-suave); font-size: 12px; margin-top: 4px; }
        .panel-actions { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
        .rotacion-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; padding: 14px 18px 18px; }
        .rotacion-card { border: 1px solid var(--linea-suave); border-radius: 8px; padding: 13px 14px; background: #F8FAFC; }
        .rotacion-card h3 { margin: 0 0 8px; font-size: 13px; color: var(--texto); }
        .rotacion-card .dato { display: block; color: var(--texto); font-weight: 800; margin-top: 4px; }
        .rotacion-card .recomienda { margin-top: 10px; color: var(--petroleo-fuerte); font-size: 12px; font-weight: 800; line-height: 1.35; }
        .quick-filter {
            border: 1px solid var(--linea); background: #fff; color: var(--texto-suave);
            border-radius: 999px; padding: 7px 12px; font: inherit; font-size: 12px; font-weight: 800; cursor: pointer;
        }
        .quick-filter.activo { border-color: var(--petroleo) !important; background: var(--petroleo-wash); color: var(--petroleo-fuerte) !important; }
        .toolbar { display: grid; grid-template-columns: minmax(240px, 1fr) 190px 180px; gap: 10px; padding: 14px 18px; border-bottom: 1px solid var(--linea-suave); }
        .toolbar input, .toolbar select {
            border: 1px solid #C8D3DC; border-radius: 8px; background: #fff; color: var(--texto);
            padding: 9px 11px; font: inherit; font-size: 13px;
        }
        .toolbar input { min-width: 0; }
        .resultados-meta {
            display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap;
            padding: 10px 18px; border-bottom: 1px solid var(--linea-suave); color: var(--texto-suave); font-size: 12px; font-weight: 700;
            background: #F8FAFC;
        }
        .resultados-meta .leyenda { display: flex; gap: 12px; flex-wrap: wrap; }
        .leyenda i { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 5px; vertical-align: 1px; background: currentColor; }
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; min-width: 1080px; }
        th, td { padding: 11px 14px; border-bottom: 1px solid var(--linea-suave); text-align: left; vertical-align: top; }
        th { background: #F8FAFC; color: var(--texto-tenue); text-transform: uppercase; font-size: 10px; letter-spacing: .06em; }
        td { color: var(--texto-suave); }
        tbody tr:hover { background: var(--bg-hover); }
        .fila-rojo { box-shadow: inset 3px 0 0 var(--critico); }
        .fila-amarillo { box-shadow: inset 3px 0 0 var(--ambar); }
        .fila-verde { box-shadow: inset 3px 0 0 var(--petroleo); }
        .mono { font-family: 'JetBrains Mono', monospace; }
        .mini { color: var(--texto-tenue); font-size: 12px; margin-top: 3px; }
        .producto-nombre { color: var(--texto); font-weight: 800; }
        .sugerido-num { display: block; color: var(--texto); font-size: 18px; font-weight: 800; line-height: 1; }
        .coverage {
            width: 112px; height: 7px; background: #E3EBF1; border-radius: 999px; overflow: hidden; margin-top: 7px;
        }
        .coverage span { display: block; height: 100%; width: var(--pct, 0%); border-radius: inherit; background: var(--color, var(--petroleo)); }
        .badge { display: inline-flex; align-items: center; gap: 5px; padding: 3px 10px; border-radius: 999px; font-size: 11px; font-weight: 700; white-space: nowrap; }
        .badge::before { content: ""; width: 6px; height: 6px; border-radius: 50%; background: currentColor; }
        .badge-danger { background: var(--critico-wash); color: var(--critico); }
        .badge-warn { background: var(--ambar-wash); color: var(--ambar); }
        .badge-ok { background: var(--petroleo-wash); color: var(--petroleo); }
        .badge-neutro { background: #F8FAFC; color: var(--texto-tenue); border: 1px solid var(--linea); }
        .estatus-pill { text-transform: capitalize; }
        .acciones-tabla { display: flex; gap: 6px; flex-wrap: wrap; }
        .acciones-tabla button { border: 1px solid #C8D3DC; background: #fff; color: var(--texto); border-radius: 7px; padding: 6px 8px; font: inherit; font-size: 12px; font-weight: 800; cursor: pointer; }
        .acciones-tabla button:hover { border-color: var(--petroleo); color: var(--petroleo); }
        .paginacion { display: flex; justify-content: center; align-items: center; gap: 12px; padding: 14px; color: var(--texto-suave); }
        .vacio, .cargando { color: var(--texto-tenue); font-style: italic; padding: 34px 14px; text-align: center; }
        .overlay-modal {
            position: fixed; inset: 0; display: none; align-items: flex-start; justify-content: center;
            overflow-y: auto; padding: 34px 16px; background: rgba(20,33,43,.24); backdrop-filter: blur(2px); z-index: 60;
        }
        .overlay-modal.abierto { display: flex; }
        .modal { width: min(620px, 100%); background: #fff; border: 1px solid var(--linea); border-radius: 10px; box-shadow: 0 24px 70px rgba(20,33,43,.22); }
        .modal-head { display: flex; justify-content: space-between; align-items: center; padding: 16px 18px; border-bottom: 1px solid var(--linea-suave); }
        .modal-head h2 { margin: 0; font-size: 15px; }
        .modal-body { padding: 18px; display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .modal-body .full { grid-column: 1 / -1; }
        .modal-body label { display: block; color: var(--texto-suave); font-size: 12px; font-weight: 700; margin-bottom: 6px; }
        .modal-body input, .modal-body textarea { width: 100%; border: 1px solid #C8D3DC; border-radius: 8px; padding: 9px 11px; font: inherit; }
        .modal-body textarea { min-height: 70px; resize: vertical; }
        .modal-actions { grid-column: 1 / -1; display: flex; justify-content: flex-end; gap: 8px; align-items: center; }
        .msg { margin-right: auto; color: var(--texto-tenue); font-size: 12px; }
        .msg.error { color: var(--critico); }
        .msg.ok { color: var(--petroleo); }
        @media (max-width: 1000px) { .toolbar { grid-template-columns: 1fr 1fr; } .toolbar input { grid-column: 1 / -1; } }
        @media (max-width: 900px) { .kpis, .resumen-operativo, .rotacion-grid { grid-template-columns: repeat(2, 1fr); } .modal-body { grid-template-columns: 1fr; } }
        @media (max-width: 620px) { .kpis, .resumen-operativo, .rotacion-grid, .toolbar { grid-template-columns: 1fr; } }
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
                <div class="encabezado-pagina__etiqueta"><span class="punto-vivo"></span>Abasto recomendado</div>
                <h1>Planeacion y demanda</h1>
                <p>Prioriza que surtir, en que maquina y con cuantas piezas para evitar quiebres de stock sin revisar unidad por unidad.</p>
            </div>
            <div class="acciones-head">
                <?php if (Auth::isAdmin()): ?><button class="btn primario" id="btn-generar">Generar plan</button><?php endif; ?>
                <button class="btn" id="btn-actualizar">Actualizar</button>
            </div>
        </div>

        <section class="kpis">
            <div class="kpi"><div class="lbl">Maquinas en plan</div><div class="num" id="kpi-maquinas">-</div><div class="subkpi" id="kpi-productos">Productos: -</div></div>
            <div class="kpi" style="--kpi-color:var(--critico)"><div class="lbl">Criticos</div><div class="num" id="kpi-rojos">-</div><div class="subkpi">Sin stock o bajo seguridad</div></div>
            <div class="kpi" style="--kpi-color:var(--ambar)"><div class="lbl">Preventivos</div><div class="num" id="kpi-amarillos">-</div><div class="subkpi">Cobertura proxima a riesgo</div></div>
            <div class="kpi" style="--kpi-color:var(--petroleo)"><div class="lbl">Piezas sugeridas</div><div class="num" id="kpi-piezas">-</div><div class="subkpi" id="kpi-total">Recomendaciones: -</div></div>
        </section>

        <section class="resumen-operativo" aria-label="Resumen por estatus">
            <div class="estado-resumen"><span>Pendientes</span><b id="kpi-pendientes">-</b></div>
            <div class="estado-resumen"><span>Programados</span><b id="kpi-programados">-</b></div>
            <div class="estado-resumen"><span>Surtidos</span><b id="kpi-surtidos">-</b></div>
            <div class="estado-resumen"><span>Omitidos</span><b id="kpi-omitidos">-</b></div>
        </section>

        <section class="panel" style="margin-bottom:16px;">
            <div class="panel-head">
                <div>
                    <h2>Rotacion por maquina</h2>
                    <div class="sub">Compara productos de alta y baja salida para proponer cambios por maquina.</div>
                </div>
                <div class="panel-actions">
                    <input id="rotacion-q" placeholder="Buscar maquina o ubicacion" style="border:1px solid #C8D3DC;border-radius:8px;padding:8px 10px;font:inherit;font-size:12px;">
                    <input id="rotacion-estado" placeholder="Estado, ej. Baja California" style="border:1px solid #C8D3DC;border-radius:8px;padding:8px 10px;font:inherit;font-size:12px;">
                    <select id="rotacion-dias" style="border:1px solid #C8D3DC;border-radius:8px;padding:8px 10px;font:inherit;font-size:12px;">
                        <option value="7">7 dias</option>
                        <option value="30" selected>30 dias</option>
                        <option value="90">90 dias</option>
                    </select>
                    <button class="btn" id="btn-rotacion" type="button">Analizar</button>
                </div>
            </div>
            <div id="rotacion-lista" class="cargando">Cargando...</div>
        </section>

        <section class="panel">
            <div class="panel-head">
                <div>
                    <h2>Reabastecimiento sugerido</h2>
                    <div class="sub" id="plan-meta">Sin plan generado.</div>
                </div>
                <div class="panel-actions">
                    <button type="button" class="quick-filter activo" data-prioridad="">Todo</button>
                    <button type="button" class="quick-filter" data-prioridad="rojo">Criticos</button>
                    <button type="button" class="quick-filter" data-prioridad="amarillo">Preventivos</button>
                </div>
            </div>
            <div class="toolbar">
                <input id="buscar" placeholder="Buscar maquina, ubicacion, producto o codigo">
                <select id="filtro-prioridad">
                    <option value="">Todas las prioridades</option>
                    <option value="rojo">Criticos</option>
                    <option value="amarillo">Preventivos</option>
                    <option value="verde">Verdes</option>
                    <option value="sin_historial">Sin historial</option>
                </select>
                <select id="filtro-estatus">
                    <option value="">Todos los estatus</option>
                    <option value="pendiente">Pendiente</option>
                    <option value="programado">Programado</option>
                    <option value="surtido">Surtido</option>
                    <option value="omitido">Omitido</option>
                </select>
            </div>
            <div class="resultados-meta" id="resultados-meta" style="display:none;">
                <span id="texto-resultados"></span>
                <span class="leyenda">
                    <span style="color:var(--critico)"><i></i>Critico</span>
                    <span style="color:var(--ambar)"><i></i>Preventivo</span>
                    <span style="color:var(--petroleo)"><i></i>Verde</span>
                </span>
            </div>
            <div id="tabla-plan" class="cargando">Cargando...</div>
            <div class="paginacion" id="paginacion" style="display:none;">
                <button class="btn" id="btn-ant">Anterior</button>
                <span id="texto-pagina"></span>
                <button class="btn" id="btn-sig">Siguiente</button>
            </div>
        </section>
    </main>
</div>

<div class="overlay-modal" id="overlay-generar">
    <div class="modal">
        <div class="modal-head">
            <h2>Generar plan</h2>
            <button class="btn" id="cerrar-modal" type="button">Cerrar</button>
        </div>
        <form class="modal-body" id="form-generar">
            <div>
                <label>Fecha del plan</label>
                <input id="g-fecha_plan" type="date" required>
            </div>
            <div>
                <label>Horizonte dias</label>
                <input id="g-horizonte" type="number" min="1" max="45" value="7" required>
            </div>
            <div>
                <label>Stock seguridad dias</label>
                <input id="g-seguridad" type="number" min="1" max="30" value="3" required>
            </div>
            <div>
                <label>Historial dias</label>
                <input id="g-historial" type="number" min="7" max="90" value="30" required>
            </div>
            <div class="full">
                <label>Notas</label>
                <textarea id="g-notas"></textarea>
            </div>
            <div class="modal-actions">
                <span class="msg" id="msg-generar"></span>
                <button class="btn primario" id="guardar-plan" type="submit">Generar</button>
            </div>
        </form>
    </div>
</div>

<script src="assets/js/comun.js?v=20260912-1"></script>
<script>
    const ES_ADMIN = <?= Auth::isAdmin() ? 'true' : 'false' ?>;
    const PAGE_SIZE = 50;
    let planActual = null;
    let pagina = 1;
    let total = 0;
    let debounce = null;
    const overlay = document.getElementById('overlay-generar');

    function fmt(n, dec = 0) {
        if (n === null || n === undefined || n === '') return '0';
        return Number(n).toLocaleString('es-MX', { maximumFractionDigits: dec });
    }

    function badgePrioridad(p) {
        if (p === 'rojo') return '<span class="badge badge-danger">Critico</span>';
        if (p === 'amarillo') return '<span class="badge badge-warn">Preventivo</span>';
        if (p === 'verde') return '<span class="badge badge-ok">Verde</span>';
        return '<span class="badge badge-neutro">Sin historial</span>';
    }

    function badgeEstatus(estatus) {
        if (estatus === 'programado') return '<span class="badge badge-warn estatus-pill">Programado</span>';
        if (estatus === 'surtido') return '<span class="badge badge-ok estatus-pill">Surtido</span>';
        if (estatus === 'omitido') return '<span class="badge badge-neutro estatus-pill">Omitido</span>';
        return '<span class="badge badge-danger estatus-pill">Pendiente</span>';
    }

    function coverageHtml(dias) {
        if (dias === null || dias === undefined || dias === '') {
            return '<div class="coverage" title="Sin cobertura calculada"><span style="--pct:0%;--color:var(--texto-tenue);"></span></div>';
        }
        const n = Number(dias);
        const pct = Math.max(4, Math.min(100, (n / 14) * 100));
        const color = n <= 3 ? 'var(--critico)' : (n <= 6 ? 'var(--ambar)' : 'var(--petroleo)');
        return `<div class="coverage" title="${fmt(n, 1)} dias de cobertura"><span style="--pct:${pct}%;--color:${color};"></span></div>`;
    }

    function sincronizarQuickFilters() {
        const actual = document.getElementById('filtro-prioridad').value;
        document.querySelectorAll('.quick-filter').forEach(btn => {
            btn.classList.toggle('activo', btn.dataset.prioridad === actual);
        });
    }

    async function cargarPlan() {
        const json = await obtenerJSON('api/planeacion.php?accion=ultimo');
        planActual = json.data;
        if (!planActual) {
            document.getElementById('plan-meta').textContent = 'Sin plan generado.';
            return;
        }
        const autor = planActual.generado_por ? ` - ${planActual.generado_por}` : '';
        document.getElementById('plan-meta').textContent = `Plan #${planActual.id_plan} - ${planActual.fecha_plan} - horizonte ${planActual.horizonte_dias}d - seguridad ${planActual.stock_seguridad_dias}d - historial ${planActual.dias_historial}d${autor}`;
    }

    async function cargarResumen() {
        if (!planActual) {
            ['kpi-maquinas', 'kpi-rojos', 'kpi-amarillos', 'kpi-piezas', 'kpi-pendientes', 'kpi-programados', 'kpi-surtidos', 'kpi-omitidos'].forEach(id => document.getElementById(id).textContent = '0');
            document.getElementById('kpi-productos').textContent = 'Productos: 0';
            document.getElementById('kpi-total').textContent = 'Recomendaciones: 0';
            return;
        }
        const json = await obtenerJSON(`api/planeacion.php?accion=resumen&id_plan=${planActual.id_plan}`);
        const d = json.data || {};
        document.getElementById('kpi-maquinas').textContent = fmt(d.maquinas);
        document.getElementById('kpi-rojos').textContent = fmt(d.rojos);
        document.getElementById('kpi-amarillos').textContent = fmt(d.amarillos);
        document.getElementById('kpi-piezas').textContent = fmt(d.piezas_sugeridas);
        document.getElementById('kpi-productos').textContent = `Productos: ${fmt(d.productos)}`;
        document.getElementById('kpi-total').textContent = `Recomendaciones: ${fmt(d.total)}`;
        document.getElementById('kpi-pendientes').textContent = fmt(d.pendientes);
        document.getElementById('kpi-programados').textContent = fmt(d.programados);
        document.getElementById('kpi-surtidos').textContent = fmt(d.surtidos);
        document.getElementById('kpi-omitidos').textContent = fmt(d.omitidos);
    }

    async function cargarDetalle() {
        const cont = document.getElementById('tabla-plan');
        const pag = document.getElementById('paginacion');
        const meta = document.getElementById('resultados-meta');
        cont.className = 'cargando';
        cont.textContent = 'Cargando...';
        pag.style.display = 'none';
        meta.style.display = 'none';
        if (!planActual) {
            cont.className = '';
            cont.innerHTML = '<p class="vacio">Genera el primer plan para ver recomendaciones.</p>';
            return;
        }
        const params = new URLSearchParams({
            accion: 'detalle',
            id_plan: planActual.id_plan,
            page: pagina,
            page_size: PAGE_SIZE,
        });
        const q = document.getElementById('buscar').value.trim();
        const prioridad = document.getElementById('filtro-prioridad').value;
        const estatus = document.getElementById('filtro-estatus').value;
        if (q) params.set('q', q);
        if (prioridad) params.set('prioridad', prioridad);
        if (estatus) params.set('estatus', estatus);

        try {
            const json = await obtenerJSON(`api/planeacion.php?${params}`);
            total = json.meta.total;
            if (!json.data.length) {
                cont.className = '';
                cont.innerHTML = '<p class="vacio">No hay recomendaciones para este filtro.</p>';
                return;
            }
            cont.className = '';
            const desde = ((pagina - 1) * PAGE_SIZE) + 1;
            const hasta = Math.min(pagina * PAGE_SIZE, total);
            document.getElementById('texto-resultados').textContent = `${fmt(total)} recomendacion(es) - mostrando ${fmt(desde)}-${fmt(hasta)}`;
            meta.style.display = 'flex';
            cont.innerHTML = `<div class="table-wrap"><table>
                <thead><tr><th>Prioridad</th><th>Maquina</th><th>Producto</th><th>Demanda</th><th>Stock y cobertura</th><th>Sugerido</th><th>Estatus</th><th>Acciones</th></tr></thead>
                <tbody>${json.data.map(f => `
                    <tr class="fila-${escapeHtml(f.prioridad)}">
                        <td>${badgePrioridad(f.prioridad)}<div class="mini">${escapeHtml(f.motivo)}</div></td>
                        <td><span class="mono">${escapeHtml(f.numero_serie)}</span><div class="mini">${escapeHtml(f.nombre_ubicacion) || 'Sin ubicacion'}</div></td>
                        <td><div class="producto-nombre">${escapeHtml(f.producto)}</div><div class="mini mono">${escapeHtml(f.codigo_barras)}</div></td>
                        <td class="mono">${fmt(f.demanda_diaria, 2)} / dia<div class="mini">7d: ${fmt(f.consumo_7d)} - hist: ${fmt(f.consumo_30d)}</div></td>
                        <td class="mono">${fmt(f.stock_actual)} pieza(s)<div class="mini">Cobertura: ${f.dias_cobertura === null ? '-' : fmt(f.dias_cobertura, 1) + 'd'}</div>${coverageHtml(f.dias_cobertura)}</td>
                        <td class="mono"><span class="sugerido-num">${fmt(f.cantidad_sugerida)}</span><div class="mini">Objetivo ${fmt(f.stock_objetivo)}</div></td>
                        <td>${badgeEstatus(f.estatus)}</td>
                        <td><div class="acciones-tabla">
                            ${ES_ADMIN && f.estatus !== 'programado' ? `<button data-id="${f.id_recomendacion}" data-estatus="programado">Programar</button>` : ''}
                            ${ES_ADMIN && f.estatus !== 'surtido' ? `<button data-id="${f.id_recomendacion}" data-estatus="surtido">Surtido</button>` : ''}
                            ${ES_ADMIN && f.estatus !== 'omitido' ? `<button data-id="${f.id_recomendacion}" data-estatus="omitido">Omitir</button>` : ''}
                        </div></td>
                    </tr>`).join('')}
                </tbody></table></div>`;
            cont.querySelectorAll('[data-estatus]').forEach(btn => {
                btn.addEventListener('click', () => cambiarEstatus(Number(btn.dataset.id), btn.dataset.estatus));
            });
            const totalPaginas = Math.max(1, Math.ceil(total / PAGE_SIZE));
            document.getElementById('texto-pagina').textContent = `${pagina} / ${totalPaginas}`;
            document.getElementById('btn-ant').disabled = pagina <= 1;
            document.getElementById('btn-sig').disabled = pagina >= totalPaginas;
            pag.style.display = 'flex';
        } catch (e) {
            cont.className = '';
            cont.innerHTML = `<p class="vacio">${escapeHtml(e.message)}</p>`;
        }
    }

    async function cargarRotacion() {
        const cont = document.getElementById('rotacion-lista');
        const params = new URLSearchParams({
            accion: 'rotacion',
            dias: document.getElementById('rotacion-dias').value || '30',
            page_size: '12',
        });
        const q = document.getElementById('rotacion-q').value.trim();
        const estado = document.getElementById('rotacion-estado').value.trim();
        if (q) params.set('q', q);
        if (estado) params.set('estado_geo', estado);
        cont.className = 'cargando';
        cont.textContent = 'Cargando...';
        try {
            const json = await obtenerJSON(`api/planeacion.php?${params}`);
            if (!json.data.length) {
                cont.className = '';
                cont.innerHTML = '<p class="vacio">Sin datos de rotacion para estos filtros.</p>';
                return;
            }
            cont.className = 'rotacion-grid';
            cont.innerHTML = json.data.map(f => `
                <article class="rotacion-card">
                    <h3><span class="mono">${escapeHtml(f.numero_serie)}</span></h3>
                    <div class="mini">${escapeHtml(f.nombre_ubicacion) || escapeHtml(f.direccion) || 'Sin ubicacion'}</div>
                    <div class="mini">Mayor rotacion <span class="dato">${escapeHtml(f.producto_alta) || '-'}</span><span class="mono">${fmt(f.unidades_alta)} piezas</span></div>
                    <div class="mini">Menor rotacion con stock <span class="dato">${escapeHtml(f.producto_baja) || '-'}</span><span class="mono">${fmt(f.unidades_baja)} piezas - stock ${fmt(f.stock_baja)}</span></div>
                    <div class="recomienda">${escapeHtml(f.recomendacion)}</div>
                </article>
            `).join('');
        } catch (e) {
            cont.className = '';
            cont.innerHTML = `<p class="vacio">${escapeHtml(e.message || 'No se pudo calcular rotacion.')}</p>`;
        }
    }

    async function cambiarEstatus(id, estatus) {
        const res = await fetch('api/planeacion.php?accion=estatus', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id_recomendacion: id, estatus }),
        });
        const json = await res.json();
        if (!json.ok) {
            await mlAlert(json.error || 'No se pudo actualizar');
            return;
        }
        await Promise.all([cargarResumen(), cargarDetalle()]);
    }

    async function actualizarTodo() {
        await cargarPlan();
        await Promise.all([cargarResumen(), cargarDetalle(), cargarRotacion()]);
    }

    function abrirModal() {
        const hoy = new Date().toISOString().slice(0, 10);
        document.getElementById('g-fecha_plan').value = hoy;
        document.getElementById('msg-generar').textContent = '';
        overlay.classList.add('abierto');
    }
    function cerrarModal() { overlay.classList.remove('abierto'); }

    document.getElementById('btn-generar')?.addEventListener('click', abrirModal);
    document.getElementById('cerrar-modal').addEventListener('click', cerrarModal);
    overlay.addEventListener('click', ev => { if (ev.target === overlay) cerrarModal(); });
    document.getElementById('btn-actualizar').addEventListener('click', actualizarTodo);
    document.getElementById('btn-ant').addEventListener('click', () => { pagina--; cargarDetalle(); });
    document.getElementById('btn-sig').addEventListener('click', () => { pagina++; cargarDetalle(); });
    document.getElementById('btn-rotacion').addEventListener('click', cargarRotacion);
    document.getElementById('rotacion-dias').addEventListener('change', cargarRotacion);
    document.getElementById('filtro-prioridad').addEventListener('change', () => { pagina = 1; sincronizarQuickFilters(); cargarDetalle(); });
    document.getElementById('filtro-estatus').addEventListener('change', () => { pagina = 1; cargarDetalle(); });
    document.querySelectorAll('.quick-filter').forEach(btn => {
        btn.addEventListener('click', () => {
            document.getElementById('filtro-prioridad').value = btn.dataset.prioridad;
            pagina = 1;
            sincronizarQuickFilters();
            cargarDetalle();
        });
    });
    document.getElementById('buscar').addEventListener('input', () => {
        clearTimeout(debounce);
        debounce = setTimeout(() => { pagina = 1; cargarDetalle(); }, 300);
    });

    document.getElementById('form-generar').addEventListener('submit', async (ev) => {
        ev.preventDefault();
        const btn = document.getElementById('guardar-plan');
        const msg = document.getElementById('msg-generar');
        btn.disabled = true;
        msg.textContent = 'Generando...';
        msg.className = 'msg';
        try {
            const payload = {
                fecha_plan: document.getElementById('g-fecha_plan').value,
                horizonte_dias: Number(document.getElementById('g-horizonte').value),
                stock_seguridad_dias: Number(document.getElementById('g-seguridad').value),
                dias_historial: Number(document.getElementById('g-historial').value),
                notas: document.getElementById('g-notas').value.trim(),
            };
            const res = await fetch('api/planeacion.php?accion=generar', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            });
            const json = await res.json();
            if (!json.ok) throw new Error(json.error || 'No se pudo generar');
            msg.textContent = `Plan generado con ${fmt(json.data.total_recomendaciones)} recomendaciones.`;
            msg.className = 'msg ok';
            pagina = 1;
            await actualizarTodo();
            setTimeout(cerrarModal, 700);
        } catch (e) {
            msg.textContent = e.message;
            msg.className = 'msg error';
        } finally {
            btn.disabled = false;
        }
    });

    actualizarTodo();
</script>
</body>
</html>
