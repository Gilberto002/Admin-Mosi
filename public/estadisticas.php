<?php
// public/estadisticas.php — ventas y mermas (caducado/dañado/merma) por
// máquina, 100% dentro de vendingm.movimientos_salida (no depende de
// cat_SucursalClinica/farmacia — ver decisión del 2026-08-24 en la memoria
// del proyecto).
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/bootstrap.php';

use App\Auth\Auth;

Auth::requireAdmin();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>MDM - Estadísticas</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/estilo.css?v=20260914-2">
    <script src="assets/js/vendor/chart.min.js"></script>
    <style>
        body.tema-oscuro {
            --bg: #0A0F14; --bg-elevada: #121A21; --bg-hover: #1E2830;
            --linea: #243039; --linea-suave: #1C2630;
            --texto: #EEF4F7; --texto-suave: #9FB2BC; --texto-tenue: #5E707B;
            --petroleo: #2CD9BE; --petroleo-wash: rgba(44, 217, 190, .13);
            --ambar: #E4AC4F; --ambar-wash: rgba(228, 172, 79, .15);
            --critico: #F1707E; --critico-wash: rgba(241, 112, 126, .15);
            /* Mismo azul que ya usa .nav-lateral a.activa en este tema (ver estilo.css,
               body.tema-mdm-oscuro .nav-lateral a.activa) -- se reutiliza para marcar la
               pestaña activa y el nivel de detalle (receta -> medicamentos). */
            --azul-activo-fondo: #17334A; --azul-activo-texto: #CBE5FA;
            --azul-activo: #5B8DEF; --azul-activo-wash: rgba(91, 141, 239, .14);
            background: var(--bg); color: var(--texto);
        }
        body.tema-oscuro .sidebar { background: var(--bg-elevada); border-right: 1px solid var(--linea); }
        body.tema-oscuro .nav-lateral a { color: var(--texto-suave); }
        body.tema-oscuro .nav-lateral a:hover { background: var(--bg-hover); color: var(--texto); }
        body.tema-oscuro .nav-lateral a.activa { background: var(--petroleo-wash); color: var(--petroleo); }

        /* Modo claro (el menu compartido quita tema-oscuro y pone tema-mdm-claro,
           ver partials/menu.php): mismas variables, verde menta en vez de azul. */
        body.tema-mdm-claro {
            --azul-activo-fondo: #D7F5E3; --azul-activo-texto: #0E6B45;
            --azul-activo: #2FBE8A; --azul-activo-wash: rgba(47, 190, 138, .16);
        }

        .encabezado-pagina h1 { font-size: 22px; margin: 0 0 4px; }
        .encabezado-pagina p { margin: 0 0 20px; color: var(--texto-suave); font-size: 13.5px; max-width: 620px; }

        .toolbar-card { background: var(--bg-elevada); border: 1px solid var(--linea); border-radius: 14px; padding: 14px 16px; margin-bottom: 18px; }
        .barra { display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end; }
        .barra .campo { display: flex; flex-direction: column; gap: 5px; }
        .barra label { font-size: 11px; color: var(--texto-tenue); text-transform: uppercase; letter-spacing: .04em; }
        .barra input, .barra select { padding: 9px 11px; border: 1px solid var(--linea); border-radius: 8px; font-size: 13px; background: var(--bg); color: var(--texto); font-family: inherit; }
        .barra button { border: none; background: var(--petroleo); color: #06201F; font-family: inherit; font-weight: 600; font-size: 13px; padding: 10px 16px; border-radius: 8px; cursor: pointer; }

        .tira-stats { display: grid; grid-template-columns: repeat(2, 1fr); gap: 14px; margin-bottom: 18px; }
        .stat { background: var(--bg-elevada); border: 1px solid var(--linea); border-radius: 14px; padding: 16px 18px; }
        .stat .lbl { font-size: 11px; text-transform: uppercase; letter-spacing: .05em; color: var(--texto-tenue); font-weight: 600; margin-bottom: 6px; }
        .stat .num { font-family: 'Space Grotesk', sans-serif; font-size: 27px; font-weight: 700; color: var(--texto); }
        .stat .subtle-note { font-size: 12px; color: var(--texto-tenue); margin-top: 3px; }

        .grid-graficas { display: grid; grid-template-columns: 1.4fr 1fr; gap: 16px; margin-bottom: 18px; }
        .card { background: var(--bg-elevada); border: 1px solid var(--linea); border-radius: 14px; padding: 16px 18px; }
        .card h2 { font-size: 14px; margin: 0 0 12px; }
        .card-tabla { padding: 0; }
        .vacio, .cargando { color: var(--texto-tenue); font-style: italic; padding: 30px 14px; text-align: center; }

        table.tabla-kardex { width: 100%; border-collapse: collapse; }
        table.tabla-kardex th, table.tabla-kardex td { padding: 10px 14px; text-align: left; font-size: 12.5px; border-bottom: 1px solid var(--linea-suave); }
        table.tabla-kardex th { color: var(--texto-tenue); font-size: 10.5px; text-transform: uppercase; letter-spacing: .04em; position: sticky; top: 0; background: var(--bg-elevada); z-index: 1; }
        table.tabla-kardex tr:hover { background: var(--bg-hover); }

        /* Scroll vertical propio para aprovechar la mayor cantidad de fila visibles
           sin que la tabla empuje el resto de la pagina hacia abajo. */
        #tabla-kardex, #tabla-recetas { max-height: 68vh; overflow-y: auto; }
        .detalle-receta-cont .tabla-kardex-scroll { max-height: 40vh; overflow-y: auto; }
        .detalle-receta-cont table.tabla-kardex th { position: sticky; top: 0; background: var(--azul-activo-fondo); }
        .badge { display: inline-flex; padding: 2px 9px; border-radius: 999px; font-size: 10.5px; font-weight: 600; }
        .badge-entrada { background: var(--petroleo-wash); color: var(--petroleo); }
        .badge-salida { background: var(--ambar-wash); color: var(--ambar); }
        .mono { font-family: 'JetBrains Mono', monospace; }

        .tabs-folder { display: flex; gap: 4px; padding: 10px 14px 0; }
        .tab-folder {
            border: 1px solid var(--linea); border-bottom: none; background: var(--bg);
            color: var(--texto-suave); font: inherit; font-size: 13px; font-weight: 600;
            padding: 9px 16px; border-radius: 10px 10px 0 0; cursor: pointer; position: relative; top: 1px;
        }
        .tab-folder:hover { color: var(--texto); }
        .tab-folder.activa { background: var(--azul-activo-fondo); color: var(--azul-activo-texto); border-color: var(--azul-activo-fondo); border-bottom: 1px solid var(--bg-elevada); }
        .tab-panel { border-top: 1px solid var(--linea-suave); }
        .tab-panel[hidden] { display: none; }

        /* Detalle de receta (medicamentos surtidos dentro de una receta): mismo azul
           solido que el item activo del menu, para distinguir que es un nivel mas profundo. */
        .fila-receta-detalle td { padding: 0 14px 14px !important; }
        .detalle-receta-cont { background: var(--azul-activo-fondo); border-radius: 8px; padding: 4px 0; }
        .detalle-receta-cont table.tabla-kardex th { color: var(--azul-activo-texto); opacity: .8; }
        .detalle-receta-cont table.tabla-kardex td { color: var(--azul-activo-texto); border-bottom-color: rgba(255,255,255,.1); }
        .detalle-receta-cont table.tabla-kardex tr:hover { background: rgba(255,255,255,.06); }
        .fila-receta { cursor: pointer; }
        .fila-receta:hover { background: var(--bg-hover); }
        .fila-receta .ind-expandir { display: inline-block; margin-right: 6px; color: var(--azul-activo); transition: transform .15s ease; }
        .fila-receta.abierta .ind-expandir { transform: rotate(90deg); }
        .fila-receta.abierta,
        .fila-receta.abierta:hover { background: var(--azul-activo-fondo); }
        .fila-receta.abierta td,
        .fila-receta.abierta .ind-expandir { color: var(--azul-activo-texto); }

        @media (max-width: 900px) { .grid-graficas { grid-template-columns: 1fr; } }
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
            <h1>Estadísticas de Máquinas dispensadoras</h1>
            <p>Ventas y mermas (caducado/dañado/merma) por máquina. Sin exportación.</p>
        </div>

        <div class="toolbar-card">
            <div class="barra">
                <div class="campo"><label>Desde</label><input type="date" id="f-desde"></div>
                <div class="campo"><label>Hasta</label><input type="date" id="f-hasta"></div>
                <div class="campo"><label>Máquina</label><select id="f-maquina"><option value="">Todas</option></select></div>
                <div class="campo"><label>Tipo de salida</label><select id="f-tipo"><option value="">Todos</option></select></div>
                <button type="button" id="btn-filtrar">Filtrar</button>
                <button type="button" id="btn-exportar">Exportar CSV</button>
            </div>
        </div>

        <div class="tira-stats">
            <div class="stat">
                <div class="lbl">Ventas</div>
                <div class="num" id="stat-ventas">—</div>
                <div class="subtle-note"><span id="stat-ventas-piezas">—</span> piezas</div>
            </div>
            <div class="stat">
                <div class="lbl">Merma (caducado/dañado/merma)</div>
                <div class="num" id="stat-merma">—</div>
                <div class="subtle-note"><span id="stat-merma-piezas">—</span> piezas</div>
            </div>
        </div>

        <div class="grid-graficas">
            <div class="card">
                <h2>Movimientos por día</h2>
                <canvas id="grafica-dia" height="110"></canvas>
            </div>
            <div class="card">
                <h2>Top 8 productos vendidos</h2>
                <canvas id="grafica-top" height="150"></canvas>
            </div>
        </div>

        <div class="card card-tabla">
            <div class="tabs-folder">
                <button type="button" class="tab-folder activa" data-tab="medicamentos">Medicamentos surtidos</button>
                <button type="button" class="tab-folder" data-tab="recetas">Recetas surtidas</button>
            </div>
            <div class="tab-panel" data-panel="medicamentos">
                <div id="tabla-kardex" class="cargando">Cargando…</div>
            </div>
            <div class="tab-panel" data-panel="recetas" hidden>
                <p style="padding: 14px 18px 0; margin: 0; color: var(--texto-suave); font-size: 13px;">Haz click en una receta para ver el detalle de medicamentos.</p>
                <div id="tabla-recetas" class="cargando">Cargando…</div>
            </div>
        </div>
    </main>
</div>

<script src="assets/js/comun.js?v=20260912-1"></script>
<script>
    let graficaDia = null;
    let graficaTop = null;

    function filtros() {
        const p = new URLSearchParams();
        const desde = document.getElementById('f-desde').value;
        const hasta = document.getElementById('f-hasta').value;
        const maquina = document.getElementById('f-maquina').value;
        const tipo = document.getElementById('f-tipo').value;
        if (desde) p.set('fechaDesde', desde);
        if (hasta) p.set('fechaHasta', hasta);
        if (maquina) p.set('idMaquina', maquina);
        if (tipo) p.set('idTipoMovimiento', tipo);
        return p;
    }

    async function cargarMaquinas() {
        try {
            const json = await obtenerJSON('api/estadisticas.php?accion=maquinas');
            const select = document.getElementById('f-maquina');
            select.innerHTML = '<option value="">Todas</option>' + json.data.map(m =>
                `<option value="${m.id_maquina}">${escapeHtml(m.numero_serie)}${m.nombre_ubicacion ? ' · ' + escapeHtml(m.nombre_ubicacion) : ''}</option>`
            ).join('');
        } catch (e) { /* silencioso */ }
    }

    async function cargarTiposMovimiento() {
        try {
            const json = await obtenerJSON('api/estadisticas.php?accion=tipos_movimiento');
            const select = document.getElementById('f-tipo');
            select.innerHTML = '<option value="">Todos</option>' + json.data.map(t =>
                `<option value="${t.id_tipo_movimiento}">${escapeHtml(t.nombre)}</option>`
            ).join('');
        } catch (e) { /* silencioso */ }
    }

    function renderGraficaDia(porDia) {
        const ctx = document.getElementById('grafica-dia');
        const labels = porDia.map(d => d.dia);
        const data = {
            labels,
            datasets: [
                { label: 'Ventas', data: porDia.map(d => d.ventas), borderColor: '#2CD9BE', backgroundColor: 'rgba(44,217,190,.15)', tension: .3 },
                { label: 'Merma', data: porDia.map(d => d.merma), borderColor: '#E4AC4F', backgroundColor: 'rgba(228,172,79,.15)', tension: .3 },
            ],
        };
        if (graficaDia) { graficaDia.data = data; graficaDia.update(); return; }
        graficaDia = new Chart(ctx, {
            type: 'line',
            data,
            options: {
                responsive: true,
                plugins: {
                    legend: { labels: { color: '#9FB2BC' } },
                },
                scales: {
                    x: { ticks: { color: '#5E707B' }, grid: { color: '#1C2630' } },
                    y: { ticks: { color: '#5E707B' }, grid: { color: '#1C2630' } },
                },
            },
        });
    }

    function renderGraficaTop(topProductos) {
        const ctx = document.getElementById('grafica-top');
        const data = {
            labels: topProductos.map(p => p.nombre),
            datasets: [{ label: 'Piezas vendidas', data: topProductos.map(p => p.piezas), backgroundColor: '#2CD9BE' }],
        };
        if (graficaTop) { graficaTop.data = data; graficaTop.update(); return; }
        graficaTop = new Chart(ctx, {
            type: 'bar',
            data,
            options: {
                indexAxis: 'y',
                responsive: true,
                plugins: { legend: { display: false } },
                scales: {
                    x: { ticks: { color: '#5E707B' }, grid: { color: '#1C2630' } },
                    y: { ticks: { color: '#9FB2BC' }, grid: { display: false } },
                },
            },
        });
    }

    async function cargarResumen() {
        try {
            const json = await obtenerJSON(`api/estadisticas.php?accion=resumen&${filtros()}`);
            const d = json.data;
            document.getElementById('stat-ventas').textContent = d.totales.ventasMovimientos;
            document.getElementById('stat-ventas-piezas').textContent = d.totales.ventasPiezas;
            document.getElementById('stat-merma').textContent = d.totales.mermaMovimientos;
            document.getElementById('stat-merma-piezas').textContent = d.totales.mermaPiezas;
            renderGraficaDia(d.porDia);
            renderGraficaTop(d.topProductos);
        } catch (e) { /* silencioso: no bloquea el kardex */ }
    }

    function badgeTipo(idTipo) {
        return Number(idTipo) === 1
            ? '<span class="badge badge-entrada">Venta</span>'
            : '<span class="badge badge-salida">Merma</span>';
    }

    function filaKardex(m) {
        return `<tr>
            <td>${m.fecha_movimiento ? String(m.fecha_movimiento).replace('T', ' ').substring(0, 19) : '—'}</td>
            <td>${badgeTipo(m.id_tipo_movimiento)}</td>
            <td>${escapeHtml(m.tipo_nombre) || '—'}</td>
            <td><b class="mono">${escapeHtml(m.clave) || '—'}</b><br><span class="muted mono">${escapeHtml(m.codigo_barras) || '—'}</span></td>
            <td>${escapeHtml(m.producto_nombre) || '—'}</td>
            <td>${m.cantidad}</td>
            <td><b class="mono">${escapeHtml(m.numero_serie)}</b> <span style="color:var(--texto-tenue)">C${escapeHtml(m.numero_carril)}${m.nombre_ubicacion ? ' · ' + escapeHtml(m.nombre_ubicacion) : ''}</span></td>
            <td>${escapeHtml(m.usuario) || '—'}</td>
        </tr>`;
    }

    async function cargarKardex() {
        const cont = document.getElementById('tabla-kardex');
        cont.className = 'cargando';
        cont.textContent = 'Cargando…';
        try {
            const json = await obtenerJSON(`api/estadisticas.php?accion=kardex&${filtros()}`);
            if (json.data.length === 0) {
                cont.className = '';
                cont.innerHTML = '<p class="vacio">No hay movimientos con esos filtros.</p>';
                return;
            }
            cont.className = '';
            cont.innerHTML = `<table class="tabla-kardex">
                <thead><tr><th>Fecha</th><th>Tipo</th><th>Motivo</th><th>Código</th><th>Producto</th><th>Cantidad</th><th>Máquina</th><th>Usuario</th></tr></thead>
                <tbody>${json.data.map(filaKardex).join('')}</tbody>
            </table>`;
        } catch (e) {
            cont.className = '';
            cont.innerHTML = '<p class="vacio">No se pudo conectar con el servidor.</p>';
        }
    }

    function fechaCorta(valor) {
        if (!valor) return '—';
        return String(valor).replace('T', ' ').substring(0, 19);
    }

    function filaReceta(r) {
        return `<tr class="fila-receta" data-id="${r.id_receta}">
            <td class="mono"><span class="ind-expandir">▸</span>${escapeHtml(r.folio)}</td>
            <td>${fechaCorta(r.fecha_entregada)}</td>
            <td><b class="mono">${escapeHtml(r.numero_serie)}</b>${r.nombre_ubicacion ? ' <span style="color:var(--texto-tenue)">· ' + escapeHtml(r.nombre_ubicacion) + '</span>' : ''}</td>
            <td>${escapeHtml(r.nombre_paciente) || '—'}</td>
            <td>${r.num_medicamentos}</td>
        </tr>
        <tr class="fila-receta-detalle" data-detalle-de="${r.id_receta}" style="display:none;">
            <td colspan="5"><div class="detalle-receta-cont">Cargando…</div></td>
        </tr>`;
    }

    function filaMedicamento(it) {
        return `<tr>
            <td><b class="mono">${escapeHtml(it.clave) || '—'}</b></td>
            <td>${escapeHtml(it.producto_nombre) || '—'}</td>
            <td><b class="mono">${escapeHtml(it.numero_serie_maquina)}</b> <span style="color:var(--texto-tenue)">C${escapeHtml(it.numero_carril)}</span></td>
            <td>${it.cantidad_entregada}</td>
        </tr>`;
    }

    async function alternarDetalleReceta(idReceta) {
        const filaDetalle = document.querySelector(`.fila-receta-detalle[data-detalle-de="${idReceta}"]`);
        const filaPadre = document.querySelector(`.fila-receta[data-id="${idReceta}"]`);
        if (!filaDetalle) return;
        const yaAbierta = filaDetalle.style.display !== 'none';
        if (yaAbierta) {
            filaDetalle.style.display = 'none';
            if (filaPadre) filaPadre.classList.remove('abierta');
            return;
        }
        filaDetalle.style.display = '';
        if (filaPadre) filaPadre.classList.add('abierta');
        const cont = filaDetalle.querySelector('.detalle-receta-cont');
        if (cont.dataset.cargado === '1') return;
        try {
            const json = await obtenerJSON(`api/recetas.php?id_receta=${idReceta}`);
            const items = json.data.items || [];
            cont.dataset.cargado = '1';
            cont.innerHTML = items.length
                ? `<div class="tabla-kardex-scroll"><table class="tabla-kardex"><thead><tr><th>Clave</th><th>Producto</th><th>Máquina</th><th>Cantidad</th></tr></thead>
                   <tbody>${items.map(filaMedicamento).join('')}</tbody></table></div>`
                : '<p class="vacio">Sin medicamentos registrados.</p>';
        } catch (e) {
            cont.innerHTML = '<p class="vacio">No se pudo cargar el detalle.</p>';
        }
    }

    async function cargarRecetasSurtidas() {
        const cont = document.getElementById('tabla-recetas');
        cont.className = 'cargando';
        cont.textContent = 'Cargando…';
        try {
            const json = await obtenerJSON(`api/estadisticas.php?accion=recetas_surtidas&${filtros()}`);
            if (json.data.length === 0) {
                cont.className = '';
                cont.innerHTML = '<p class="vacio">No hay recetas surtidas con esos filtros.</p>';
                return;
            }
            cont.className = '';
            cont.innerHTML = `<table class="tabla-kardex">
                <thead><tr><th>Folio</th><th>Fecha entregada</th><th>Máquina</th><th>Paciente</th><th># Medicamentos</th></tr></thead>
                <tbody>${json.data.map(filaReceta).join('')}</tbody>
            </table>`;
            cont.querySelectorAll('.fila-receta').forEach(function (tr) {
                tr.addEventListener('click', () => alternarDetalleReceta(tr.dataset.id));
            });
        } catch (e) {
            cont.className = '';
            cont.innerHTML = '<p class="vacio">No se pudo conectar con el servidor.</p>';
        }
    }

    function cargarTodo() {
        cargarResumen();
        cargarKardex();
        cargarRecetasSurtidas();
    }

    document.getElementById('btn-filtrar').addEventListener('click', cargarTodo);
    document.getElementById('btn-exportar').addEventListener('click', () => {
        window.location.href = `api/estadisticas.php?accion=exportar&${filtros()}`;
    });

    document.querySelectorAll('.tab-folder').forEach(function (btn) {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.tab-folder').forEach(b => b.classList.remove('activa'));
            document.querySelectorAll('.tab-panel').forEach(p => p.hidden = true);
            btn.classList.add('activa');
            document.querySelector(`.tab-panel[data-panel="${btn.dataset.tab}"]`).hidden = false;
        });
    });

    cargarMaquinas();
    cargarTiposMovimiento();
    cargarTodo();
</script>
</body>
</html>
