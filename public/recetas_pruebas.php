<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/bootstrap.php';

use App\Auth\Auth;

Auth::requireAdmin();

$hoy = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>MDM - Pruebas de recetas</title>
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
            --ambar: #E4AC4F; --critico: #F1707E;
            background: var(--bg); color: var(--texto);
        }
        .encabezado-pagina { display: flex; justify-content: space-between; gap: 16px; align-items: flex-start; margin-bottom: 18px; }
        .encabezado-pagina h1 { font-size: 22px; margin: 0 0 4px; }
        .encabezado-pagina p { margin: 0; color: var(--texto-suave); font-size: 13.5px; max-width: 720px; line-height: 1.45; }
        .toolbar-card, .panel { background: var(--bg-elevada); border: 1px solid var(--linea); border-radius: 10px; }
        .toolbar-card { padding: 14px 16px; margin-bottom: 16px; }
        .barra { display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end; }
        .campo { display: flex; flex-direction: column; gap: 5px; }
        .campo label { color: var(--texto-tenue); font-size: 11px; font-weight: 700; text-transform: uppercase; }
        .campo input, .campo select { min-width: 160px; border: 1px solid var(--linea); border-radius: 8px; background: var(--bg); color: var(--texto); padding: 9px 11px; font: inherit; font-size: 13px; }
        .campo.buscar input { min-width: 250px; }
        button { border: 1px solid var(--petroleo); background: var(--petroleo); color: #06201F; border-radius: 8px; padding: 10px 14px; font: inherit; font-size: 13px; font-weight: 800; cursor: pointer; }
        button.secundario { background: var(--bg); border-color: var(--linea); color: var(--texto-suave); }
        .kpis { display: grid; grid-template-columns: repeat(6, minmax(0, 1fr)); gap: 12px; margin-bottom: 16px; }
        .kpi { background: var(--bg-elevada); border: 1px solid var(--linea); border-radius: 10px; padding: 14px 16px; }
        .kpi span { display: block; color: var(--texto-tenue); font-size: 11px; text-transform: uppercase; font-weight: 700; }
        .kpi strong { display: block; font-family: 'Space Grotesk', sans-serif; font-size: 25px; margin-top: 5px; }
        .grid { display: grid; grid-template-columns: 1.25fr 1fr; gap: 16px; margin-bottom: 16px; }
        .panel h2 { margin: 0; padding: 15px 17px 0; font-size: 14px; }
        .panel-body { padding: 14px 17px 17px; }
        .listas { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 9px 11px; border-bottom: 1px solid var(--linea-suave); text-align: left; font-size: 12px; }
        th { color: var(--texto-tenue); text-transform: uppercase; font-size: 10.5px; }
        tr:hover { background: var(--bg-hover); }
        .mono { font-family: 'JetBrains Mono', monospace; }
        .vacio, .cargando { color: var(--texto-tenue); font-style: italic; text-align: center; padding: 32px 12px; }
        @media (max-width: 1000px) { .kpis { grid-template-columns: repeat(2, 1fr); } .grid, .listas { grid-template-columns: 1fr; } }
        @media (max-width: 620px) { .kpis { grid-template-columns: 1fr; } .campo, .campo input, .campo select { width: 100%; min-width: 0; } }
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
                <h1>Pruebas de recetas</h1>
                <p>Analisis separado de recetas de prueba: medicamentos generados contra piezas realmente surtidas.</p>
            </div>
        </div>

        <div class="toolbar-card">
            <div class="barra">
                <div class="campo"><label>Desde</label><input type="date" id="desde" value="<?= htmlspecialchars($hoy, ENT_QUOTES, 'UTF-8') ?>"></div>
                <div class="campo"><label>Hasta</label><input type="date" id="hasta" value="<?= htmlspecialchars($hoy, ENT_QUOTES, 'UTF-8') ?>"></div>
                <div class="campo"><label>Modo</label><select id="modo"><option value="pruebas">Solo recetas de prueba</option><option value="todas">Todas las recetas surtidas</option></select></div>
                <div class="campo"><label>Maquina</label><select id="maquina"><option value="">Todas</option></select></div>
                <div class="campo buscar"><label>Buscar</label><input type="search" id="q" placeholder="Medicamento, codigo, maquina o folio"></div>
                <button type="button" id="btn-filtrar">Analizar</button>
                <button type="button" class="secundario" id="btn-exportar">Exportar CSV</button>
            </div>
        </div>

        <div class="kpis">
            <div class="kpi"><span>Recetas</span><strong id="k-recetas">-</strong></div>
            <div class="kpi"><span>Generados</span><strong id="k-generados">-</strong></div>
            <div class="kpi"><span>Piezas</span><strong id="k-piezas">-</strong></div>
            <div class="kpi"><span>Movimientos</span><strong id="k-movimientos">-</strong></div>
            <div class="kpi"><span>Maquinas</span><strong id="k-maquinas">-</strong></div>
            <div class="kpi"><span>Productos</span><strong id="k-productos">-</strong></div>
        </div>

        <div class="grid">
            <section class="panel">
                <h2>Medicamentos mas surtidos</h2>
                <div class="panel-body"><canvas id="grafica-productos" height="125"></canvas></div>
            </section>
            <section class="panel">
                <h2>Surtidos por hora</h2>
                <div class="panel-body"><canvas id="grafica-horas" height="150"></canvas></div>
            </section>
        </div>

        <div class="listas">
            <section class="panel">
                <h2>Maquinas con mas surtido</h2>
                <div id="tabla-maquinas" class="cargando">Cargando...</div>
            </section>
            <section class="panel">
                <h2>Carriles mas usados</h2>
                <div id="tabla-carriles" class="cargando">Cargando...</div>
            </section>
        </div>

        <section class="panel" style="margin-bottom:16px;">
            <h2>Generados vs surtidos por medicamento</h2>
            <div id="tabla-comparativo" class="cargando">Cargando...</div>
        </section>

        <section class="panel">
            <h2>Detalle recuperado</h2>
            <div id="tabla-detalle" class="cargando">Cargando...</div>
        </section>
    </main>
</div>

<script src="assets/js/comun.js?v=20260912-1"></script>
<script>
    let graficaProductos = null;
    let graficaHoras = null;

    const fmt = n => new Intl.NumberFormat('es-MX').format(Number(n) || 0);

    function params() {
        const p = new URLSearchParams();
        ['desde', 'hasta', 'modo', 'q'].forEach(id => {
            const v = document.getElementById(id).value.trim();
            if (v) p.set(id, v);
        });
        const maquina = document.getElementById('maquina').value;
        if (maquina) p.set('id_maquina', maquina);
        return p;
    }

    function renderChart(ref, chart, type, labels, data, label, color) {
        const ctx = document.getElementById(ref);
        const config = {
            type,
            data: { labels, datasets: [{ label, data, backgroundColor: color, borderColor: color, tension: .3 }] },
            options: {
                indexAxis: type === 'bar' ? 'y' : 'x',
                responsive: true,
                plugins: { legend: { display: type !== 'bar', labels: { color: '#9FB2BC' } } },
                scales: {
                    x: { ticks: { color: '#5E707B' }, grid: { color: '#1C2630' } },
                    y: { ticks: { color: '#9FB2BC' }, grid: { color: '#1C2630' } },
                },
            },
        };
        if (chart) {
            chart.data = config.data;
            chart.options = config.options;
            chart.update();
            return chart;
        }
        return new Chart(ctx, config);
    }

    function tabla(columns, rows) {
        if (!rows.length) return '<div class="vacio">Sin datos con estos filtros.</div>';
        return `<div style="overflow:auto"><table><thead><tr>${columns.map(c => `<th>${escapeHtml(c)}</th>`).join('')}</tr></thead><tbody>${rows.map(row => `<tr>${row.map(v => `<td>${escapeHtml(v ?? '-')}</td>`).join('')}</tr>`).join('')}</tbody></table></div>`;
    }

    async function cargarMaquinas() {
        try {
            const json = await obtenerJSON('api/recetas_pruebas_reporte.php?accion=maquinas');
            document.getElementById('maquina').innerHTML = '<option value="">Todas</option>' + json.data.map(m =>
                `<option value="${m.id_maquina}">${escapeHtml(m.numero_serie)}${m.nombre_ubicacion ? ' - ' + escapeHtml(m.nombre_ubicacion) : ''}</option>`
            ).join('');
        } catch (e) { /* no bloquea el reporte */ }
    }

    async function cargarReporte() {
        ['tabla-maquinas', 'tabla-carriles', 'tabla-comparativo', 'tabla-detalle'].forEach(id => {
            const el = document.getElementById(id);
            el.className = 'cargando';
            el.textContent = 'Cargando...';
        });

        try {
            const json = await obtenerJSON(`api/recetas_pruebas_reporte.php?accion=resumen&${params()}`);
            const d = json.data;
            document.getElementById('k-recetas').textContent = fmt(d.totales.recetas);
            document.getElementById('k-generados').textContent = fmt(d.totales.piezas_generadas);
            document.getElementById('k-piezas').textContent = fmt(d.totales.piezas);
            document.getElementById('k-movimientos').textContent = fmt(d.totales.movimientos);
            document.getElementById('k-maquinas').textContent = fmt(d.totales.maquinas);
            document.getElementById('k-productos').textContent = fmt(d.totales.productos);

            graficaProductos = renderChart(
                'grafica-productos',
                graficaProductos,
                'bar',
                d.productos.map(x => x.producto || 'Sin producto'),
                d.productos.map(x => Number(x.piezas) || 0),
                'Piezas',
                '#2CD9BE'
            );
            graficaHoras = renderChart(
                'grafica-horas',
                graficaHoras,
                'line',
                d.horas.map(x => `${String(x.hora).padStart(2, '0')}:00`),
                d.horas.map(x => Number(x.piezas) || 0),
                'Piezas',
                '#E4AC4F'
            );

            document.getElementById('tabla-maquinas').className = '';
            document.getElementById('tabla-maquinas').innerHTML = tabla(
                ['Maquina', 'Ubicacion', 'Piezas', 'Recetas', 'Productos'],
                d.maquinas.map(x => [x.numero_serie, x.nombre_ubicacion, fmt(x.piezas), fmt(x.recetas), fmt(x.productos)])
            );
            document.getElementById('tabla-carriles').className = '';
            document.getElementById('tabla-carriles').innerHTML = tabla(
                ['Maquina', 'Carril', 'Producto', 'Piezas'],
                d.carriles.map(x => [x.numero_serie, x.numero_carril, x.producto, fmt(x.piezas)])
            );
            document.getElementById('tabla-comparativo').className = '';
            document.getElementById('tabla-comparativo').innerHTML = tabla(
                ['Medicamento', 'Codigo', 'Generados', 'Entregados receta', 'Movimientos', 'Pendientes'],
                (d.comparativo_productos || []).map(x => [
                    x.producto,
                    x.codigo_barras,
                    fmt(x.generadas),
                    fmt(x.entregadas_receta),
                    fmt(x.surtidas),
                    fmt(Math.max(0, Number(x.generadas || 0) - Number(x.surtidas || 0))),
                ])
            );
            document.getElementById('tabla-detalle').className = '';
            document.getElementById('tabla-detalle').innerHTML = tabla(
                ['Fecha', 'Folio', 'Maquina', 'Ubicacion', 'Carril', 'Codigo', 'Producto', 'Cantidad', 'Usuario'],
                d.detalle.map(x => [
                    x.fecha_movimiento ? String(x.fecha_movimiento).replace('T', ' ').substring(0, 19) : '',
                    x.folio_receta,
                    x.numero_serie,
                    x.nombre_ubicacion,
                    x.numero_carril,
                    x.codigo_barras,
                    x.producto,
                    fmt(x.cantidad),
                    x.usuario,
                ])
            );
        } catch (e) {
            ['tabla-maquinas', 'tabla-carriles', 'tabla-comparativo', 'tabla-detalle'].forEach(id => {
                const el = document.getElementById(id);
                el.className = 'vacio';
                el.textContent = e.message || 'No se pudo recuperar la informacion.';
            });
        }
    }

    document.getElementById('btn-filtrar').addEventListener('click', cargarReporte);
    document.getElementById('btn-exportar').addEventListener('click', () => {
        window.location.href = `api/recetas_pruebas_reporte.php?accion=exportar&${params()}`;
    });

    cargarMaquinas();
    cargarReporte();
</script>
</body>
</html>
