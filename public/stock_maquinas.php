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
    <title>MDM - Stock por máquina</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/estilo.css?v=20260914-2">
    <style>
        body.tema-oscuro {
            --bg: #F5F7FA;
            --bg-elevada: #FFFFFF;
            --bg-hover: #EEF3F7;
            --linea: #D8E0E7;
            --linea-suave: #E8EEF3;
            --texto: #14212B;
            --texto-suave: #344856;
            --texto-tenue: #647583;
            --petroleo: #087D76;
            --petroleo-wash: rgba(8, 125, 118, .10);
            --ambar: #9A620B;
            --ambar-wash: rgba(154, 98, 11, .12);
            --critico: #B42335;
            --critico-wash: rgba(180, 35, 53, .10);
            /* Mismo azul que .nav-lateral a.activa en tema-mdm-oscuro (ver estilo.css) --
               se usa para la pestaña activa cuando el tema real es oscuro. En claro
               (body.tema-mdm-claro, ver abajo) se redefine a verde menta. */
            --azul-activo-fondo: #17334A; --azul-activo-texto: #CBE5FA;
            background: var(--bg);
            color: var(--texto);
        }
        body.tema-oscuro .sidebar { background: #FFFFFF; border-right: 1px solid var(--linea); box-shadow: 12px 0 30px rgba(20,33,43,.06); }
        body.tema-oscuro .nav-lateral a { color: var(--texto-suave); }
        body.tema-oscuro .nav-lateral a:hover { background: var(--bg-hover); color: var(--texto); }
        body.tema-oscuro .nav-lateral a.activa { background: var(--petroleo-wash); color: var(--petroleo); }
        body.tema-mdm-claro {
            --azul-activo-fondo: #D7F5E3; --azul-activo-texto: #0E6B45;
        }
        h1 { margin: 0 0 6px; font-size: 24px; color: var(--texto); letter-spacing: 0; }
        p { margin: 0; color: var(--texto-suave); font-size: 13px; line-height: 1.45; }
        .encabezado-pagina { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; flex-wrap: wrap; margin-bottom: 20px; }
        .encabezado-pagina__etiqueta { color: var(--petroleo); font-size: 11px; letter-spacing: .08em; text-transform: uppercase; font-weight: 700; margin-bottom: 8px; }
        .btn {
            border: 1px solid #C8D3DC; background: #FFFFFF; color: var(--texto);
            border-radius: 8px; padding: 9px 14px; font: inherit; font-size: 13px; font-weight: 700; cursor: pointer;
            box-shadow: 0 2px 6px rgba(20,33,43,.06);
        }
        .btn:hover { border-color: var(--petroleo); color: var(--petroleo); background: #F2F7F6; }
        .btn.primario { border-color: #0B8F86; background: #0B8F86; color: #FFFFFF; }
        .stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; margin-bottom: 14px; }
        .stat, .panel { background: #FFFFFF; border: 1px solid var(--linea); border-radius: 10px; box-shadow: 0 12px 28px rgba(20,33,43,.08); }
        .stat { padding: 16px; min-height: 96px; }
        .stat .lbl { color: var(--texto-suave); font-size: 11px; text-transform: uppercase; letter-spacing: .05em; margin-bottom: 8px; }
        .stat .num { font-family: 'Space Grotesk', sans-serif; font-size: 30px; font-weight: 700; line-height: 1; }
        .stat .nota { color: var(--texto-tenue); font-size: 12px; line-height: 1.35; margin-top: 9px; }
        .stat.warn .num { color: var(--ambar); }
        .stat.ok .num { color: var(--petroleo); }
        .panel-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; padding: 15px 18px; border-bottom: 1px solid var(--linea-suave); }
        .panel-head h2 { margin: 0; font-size: 15px; color: var(--texto); }
        .panel-head .sub { color: var(--texto-tenue); font-size: 12px; margin-top: 4px; }
        .toolbar { display: grid; grid-template-columns: minmax(180px, 260px) 1fr 120px auto; gap: 8px; padding: 14px 18px; border-bottom: 1px solid var(--linea-suave); align-items: center; }
        input, select {
            width: 100%; box-sizing: border-box; border: 1px solid #C8D3DC; border-radius: 8px;
            background: #FFFFFF; color: var(--texto); font: inherit; font-size: 13px; padding: 9px 11px;
        }
        .toolbar label { display: inline-flex; align-items: center; gap: 7px; color: var(--texto-suave); font-size: 12px; white-space: nowrap; }
        .toolbar label input { width: auto; }
        .table-shell { overflow: auto; max-height: 640px; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; min-width: 920px; }
        th, td { padding: 11px 18px; border-bottom: 1px solid var(--linea-suave); text-align: left; vertical-align: top; }
        th { background: #F8FAFC; color: var(--texto-tenue); font-size: 10px; text-transform: uppercase; letter-spacing: .06em; position: sticky; top: 0; z-index: 1; }
        tbody tr:hover { background: var(--bg-hover); }
        .mono { font-family: 'JetBrains Mono', monospace; }
        .muted { color: var(--texto-tenue); }
        .badge { display: inline-flex; align-items: center; gap: 5px; padding: 3px 10px; border-radius: 999px; font-size: 11px; font-weight: 600; white-space: nowrap; }
        .badge-ok { background: var(--petroleo-wash); color: var(--petroleo); }
        .badge-warn { background: var(--ambar-wash); color: var(--ambar); }
        .badge-danger { background: var(--critico-wash); color: var(--critico); }
        .vacio, .cargando { color: var(--texto-tenue); font-style: italic; padding: 34px 14px; text-align: center; }
        .alerta-operacion {
            display: none; margin-bottom: 14px; padding: 11px 13px; border-radius: 8px;
            border: 1px solid rgba(180,35,53,.35); background: var(--critico-wash); color: var(--critico); font-size: 13px;
        }
        .alerta-operacion.visible { display: block; }

        .tabs-folder { display: flex; gap: 4px; padding: 10px 14px 0; }
        .tab-folder {
            border: 1px solid var(--linea); border-bottom: none; background: var(--bg);
            color: var(--texto-suave); font: inherit; font-size: 13px; font-weight: 700;
            padding: 9px 16px; border-radius: 10px 10px 0 0; cursor: pointer; position: relative; top: 1px;
        }
        .tab-folder:hover { color: var(--texto); }
        .tab-folder.activa { background: var(--azul-activo-fondo); color: var(--azul-activo-texto); border-color: var(--azul-activo-fondo); border-bottom: 1px solid var(--bg-elevada); }
        .tab-panel { border-top: 1px solid var(--linea-suave); }
        .tab-panel[hidden] { display: none; }
        .toolbar-resumen { display: grid; grid-template-columns: 1fr; gap: 8px; padding: 14px 18px; border-bottom: 1px solid var(--linea-suave); }

        @media (max-width: 980px) {
            .stats { grid-template-columns: repeat(2, 1fr); }
            .toolbar { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body class="tema-oscuro">
<div class="app">
    <aside class="sidebar">
        <div class="marca">
            <img class="marca-logo" src="assets/img/logo.png" alt="MDM">
        </div>
        <?php require __DIR__ . '/partials/menu.php'; ?>
    </aside>

    <main class="principal">
        <div class="encabezado-pagina">
            <div>
                <div class="encabezado-pagina__etiqueta">Consulta operativa</div>
                <h1>Stock por máquina</h1>
                <p>Revisa existencias por carril, producto, lote y caducidad de cada Máquina dispensadora.</p>
            </div>
            <button class="btn primario" id="btn-actualizar">Actualizar</button>
        </div>

        <div id="alerta-operacion" class="alerta-operacion"></div>

        <div class="stats">
            <div class="stat ok"><div class="lbl">Máquinas</div><div class="num" id="kpi-maquinas">-</div><div class="nota">Con stock visible en el filtro.</div></div>
            <div class="stat"><div class="lbl">Carriles</div><div class="num" id="kpi-carriles">-</div><div class="nota">Carriles configurados.</div></div>
            <div class="stat"><div class="lbl">Unidades</div><div class="num" id="kpi-unidades">-</div><div class="nota">Suma de piezas disponibles.</div></div>
            <div class="stat warn"><div class="lbl">Stock bajo</div><div class="num" id="kpi-bajo">-</div><div class="nota">Carriles en umbral o menor.</div></div>
        </div>

        <section class="panel">
            <div class="tabs-folder">
                <button type="button" class="tab-folder activa" data-tab="existencias">Existencias por carril</button>
                <button type="button" class="tab-folder" data-tab="resumen">Resumen por medicamento</button>
            </div>

            <div class="tab-panel" data-panel="existencias">
                <div class="panel-head">
                    <div class="sub">Filtra por máquina o busca producto, código, lote o ubicación.</div>
                </div>
                <div class="toolbar" style="grid-template-columns: minmax(180px, 260px) 1fr 140px 120px auto;">
                    <select id="filtro-maquina"><option value="">Todas las máquinas</option></select>
                    <input id="buscar-stock" placeholder="Buscar máquina, producto, código o lote">
                    <input id="filtro-clave" placeholder="Clave de salud">
                    <input id="umbral-stock" type="number" min="1" max="99" value="5" title="Umbral stock bajo">
                    <label><input type="checkbox" id="solo-bajo"> Solo bajo</label>
                </div>
                <div id="tabla-stock" class="cargando">Cargando...</div>
            </div>

            <div class="tab-panel" data-panel="resumen" hidden>
                <div class="panel-head">
                    <div class="sub">Totales por máquina (suma de todos los carriles del medicamento). Respeta los filtros de la otra pestaña.</div>
                </div>
                <div class="toolbar-resumen">
                    <input id="buscar-resumen" placeholder="Buscar por máquina, clave o producto...">
                </div>
                <div id="tabla-resumen-producto" class="cargando">Cargando...</div>
            </div>
        </section>
    </main>
</div>

<script src="assets/js/comun.js?v=20260912-1"></script>
<script>
    let maquinasCargadas = false;
    let debounceStock = null;
    let debounceResumen = null;
    let porProductoCargado = [];

    function fmt(n) { return (Number(n) || 0).toLocaleString('es-MX'); }

    function mostrarError(mensaje) {
        const alerta = document.getElementById('alerta-operacion');
        alerta.textContent = mensaje || '';
        alerta.className = mensaje ? 'alerta-operacion visible' : 'alerta-operacion';
    }

    function estadoStock(f) {
        if (Number(f.cantidad_actual) <= 0) return '<span class="badge badge-danger">Sin stock</span>';
        if (f.stock_bajo) return '<span class="badge badge-warn">Bajo</span>';
        return '<span class="badge badge-ok">OK</span>';
    }

    // % de vendingm.carriles.capacidad_maxima -- amarillo 50-60%, rojo <=49%
    // (ver App\Vending\SolicitudPedidoGenerador::colorPorPorcentaje(), mismo
    // color que ya calcula el API). Al <=30% el pedido real a CEDIS ya se
    // autogenero solo (sin aprobacion) al momento de la dispensacion -- este
    // badge es solo el indicador visual, no dispara nada por si mismo.
    function badgeCapacidad(f) {
        const cap = Number(f.capacidad_maxima) || 0;
        if (cap <= 0) return '<span class="muted">-</span>';
        const pct = Math.round((Number(f.cantidad_actual) / cap) * 100);
        if (f.estado_capacidad === 'amarillo') return `<span class="badge badge-warn">${pct}%</span>`;
        if (f.estado_capacidad === 'rojo') return `<span class="badge badge-danger">${pct}%</span>`;
        return `<span class="badge badge-ok">${pct}%</span>`;
    }

    function fechaCorta(valor) {
        if (!valor) return '-';
        return String(valor).substring(0, 10);
    }

    function paramsStock() {
        const params = new URLSearchParams();
        const maquina = document.getElementById('filtro-maquina').value;
        const q = document.getElementById('buscar-stock').value.trim();
        const clave = document.getElementById('filtro-clave').value.trim();
        const umbral = document.getElementById('umbral-stock').value || '5';
        if (maquina) params.set('id_maquina', maquina);
        if (q) params.set('q', q);
        if (clave) params.set('clave', clave);
        if (document.getElementById('solo-bajo').checked) params.set('solo_bajo', '1');
        params.set('umbral', umbral);
        return params;
    }

    function poblarMaquinas(maquinas) {
        if (maquinasCargadas) return;
        const select = document.getElementById('filtro-maquina');
        select.innerHTML = '<option value="">Todas las máquinas</option>' + maquinas.map(m => {
            const etiqueta = [m.numero_serie, m.nombre_ubicacion].filter(Boolean).join(' · ');
            return `<option value="${m.id_maquina}">${escapeHtml(etiqueta || 'Máquina #' + m.id_maquina)}</option>`;
        }).join('');
        maquinasCargadas = true;
    }

    function renderTabla(filas) {
        const cont = document.getElementById('tabla-stock');
        cont.className = '';
        if (!filas.length) {
            cont.innerHTML = '<p class="vacio">Sin stock para este filtro.</p>';
            return;
        }

        cont.innerHTML = `<div class="table-shell"><table>
            <thead><tr><th>Máquina</th><th>Carril</th><th>Producto</th><th>Código</th><th>Lote</th><th>Caduca</th><th>Stock</th><th>Estado</th><th>Capacidad</th></tr></thead>
            <tbody>${filas.map(f => `<tr>
                <td><b>${escapeHtml(f.numero_serie)}</b><br><span class="muted">${escapeHtml(f.nombre_ubicacion) || escapeHtml(f.codigo_interno) || '-'}</span></td>
                <td class="mono">${f.numero_carril ?? '-'}</td>
                <td>${escapeHtml(f.producto) || '<span class="muted">Sin producto</span>'}<br><span class="muted">${escapeHtml(f.presentacion) || ''}</span></td>
                <td class="mono">${escapeHtml(f.codigo_barras) || '-'}</td>
                <td class="mono">${escapeHtml(f.numero_lote) || '-'}</td>
                <td class="mono">${fechaCorta(f.fecha_caducidad)}</td>
                <td class="mono">${fmt(f.cantidad_actual)}</td>
                <td>${estadoStock(f)}</td>
                <td>${badgeCapacidad(f)}</td>
            </tr>`).join('')}</tbody>
        </table></div>`;
    }

    function filtrarResumenLocal(filas) {
        const q = document.getElementById('buscar-resumen').value.trim().toLowerCase();
        if (!q) return filas;
        return filas.filter(f =>
            String(f.numero_serie || '').toLowerCase().includes(q) ||
            String(f.nombre_ubicacion || '').toLowerCase().includes(q) ||
            String(f.clave || '').toLowerCase().includes(q) ||
            String(f.producto || '').toLowerCase().includes(q)
        );
    }

    function renderResumenProducto(filasCompletas) {
        if (filasCompletas) porProductoCargado = filasCompletas;
        const filas = filtrarResumenLocal(porProductoCargado);
        const cont = document.getElementById('tabla-resumen-producto');
        if (!filas.length) {
            cont.className = 'vacio';
            cont.innerHTML = porProductoCargado.length ? 'Sin medicamentos para esa búsqueda.' : 'Sin medicamentos para este filtro.';
            return;
        }
        cont.className = '';
        cont.innerHTML = `<div class="table-shell"><table>
            <thead><tr><th>Máquina</th><th>Clave</th><th>Producto</th><th>Restante</th><th>Capacidad máxima</th><th>Surtido</th></tr></thead>
            <tbody>${filas.map(f => `<tr>
                <td><b>${escapeHtml(f.numero_serie)}</b><br><span class="muted">${escapeHtml(f.nombre_ubicacion) || '-'}</span></td>
                <td class="mono">${escapeHtml(f.clave) || '-'}</td>
                <td>${escapeHtml(f.producto) || '<span class="muted">Sin producto</span>'}</td>
                <td class="mono">${fmt(f.restante_total)}</td>
                <td class="mono">${fmt(f.capacidad_maxima_total)}</td>
                <td class="mono">${fmt(f.surtido_total)}</td>
            </tr>`).join('')}</tbody>
        </table></div>`;
    }

    async function cargarStock() {
        const cont = document.getElementById('tabla-stock');
        const btn = document.getElementById('btn-actualizar');
        cont.className = 'cargando';
        cont.textContent = 'Cargando...';
        btn.disabled = true;
        mostrarError('');
        try {
            const json = await obtenerJSON(`api/stock_maquinas.php?${paramsStock()}`);
            poblarMaquinas(json.data.maquinas);
            document.getElementById('kpi-maquinas').textContent = fmt(json.data.resumen.maquinas);
            document.getElementById('kpi-carriles').textContent = fmt(json.data.resumen.carriles);
            document.getElementById('kpi-unidades').textContent = fmt(json.data.resumen.unidades);
            document.getElementById('kpi-bajo').textContent = fmt(json.data.resumen.stock_bajo);
            renderTabla(json.data.filas);
            renderResumenProducto(json.data.por_producto);
        } catch (e) {
            cont.className = '';
            cont.innerHTML = '<p class="vacio">No se pudo cargar el stock.</p>';
            mostrarError(e.message || 'No se pudo cargar el stock.');
        } finally {
            btn.disabled = false;
        }
    }

    document.getElementById('btn-actualizar').addEventListener('click', cargarStock);
    document.getElementById('filtro-maquina').addEventListener('change', cargarStock);
    document.getElementById('solo-bajo').addEventListener('change', cargarStock);
    document.getElementById('umbral-stock').addEventListener('change', cargarStock);
    document.getElementById('buscar-stock').addEventListener('input', () => {
        clearTimeout(debounceStock);
        debounceStock = setTimeout(cargarStock, 300);
    });
    document.getElementById('filtro-clave').addEventListener('input', () => {
        clearTimeout(debounceStock);
        debounceStock = setTimeout(cargarStock, 300);
    });
    document.getElementById('buscar-resumen').addEventListener('input', () => {
        clearTimeout(debounceResumen);
        debounceResumen = setTimeout(() => renderResumenProducto(), 200);
    });

    document.querySelectorAll('.tab-folder').forEach(function (btn) {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.tab-folder').forEach(b => b.classList.remove('activa'));
            document.querySelectorAll('.tab-panel').forEach(p => p.hidden = true);
            btn.classList.add('activa');
            document.querySelector(`.tab-panel[data-panel="${btn.dataset.tab}"]`).hidden = false;
        });
    });

    cargarStock();
</script>
</body>
</html>
