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
    <title>MDM - Inventario</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/estilo.css?v=20260914-2">
    <style>
        body.tema-oscuro {
            --bg: #0A0F14;
            --bg-elevada: #121A21;
            --bg-hover: #1E2830;
            --linea: #243039;
            --linea-suave: #1C2630;
            --texto: #EEF4F7;
            --texto-suave: #B7C7CF;
            --texto-tenue: #82919C;
            --petroleo: #2CD9BE;
            --petroleo-wash: rgba(44,217,190,.13);
            --ambar: #E4AC4F;
            --ambar-wash: rgba(228,172,79,.15);
            --critico: #F1707E;
            --critico-wash: rgba(241,112,126,.15);
            background: var(--bg);
            color: var(--texto);
        }
        body.tema-oscuro .sidebar { background: var(--bg-elevada); border-right: 1px solid var(--linea); }
        body.tema-oscuro .nav-lateral a { color: var(--texto-suave); }
        body.tema-oscuro .nav-lateral a:hover { background: var(--bg-hover); color: var(--texto); }
        body.tema-oscuro .nav-lateral a.activa { background: var(--petroleo-wash); color: var(--petroleo); }
        h1 { margin: 0 0 6px; font-size: 24px; color: var(--texto); letter-spacing: 0; }
        p { margin: 0; color: var(--texto-suave); font-size: 13px; line-height: 1.45; }
        .encabezado-pagina { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; flex-wrap: wrap; margin-bottom: 20px; }
        .encabezado-pagina__etiqueta { color: var(--petroleo); font-size: 11px; letter-spacing: .08em; text-transform: uppercase; font-weight: 700; margin-bottom: 8px; }
        .btn {
            border: 1px solid var(--linea); background: var(--bg-elevada); color: var(--texto);
            border-radius: 8px; padding: 9px 14px; font: inherit; font-size: 13px; font-weight: 700; cursor: pointer;
        }
        .btn:hover { border-color: var(--petroleo); color: var(--petroleo); }
        .btn.primario { border: none; background: var(--petroleo); color: #06201F; }
        .btn.secundario { background: var(--bg); color: var(--texto-suave); }
        .alerta-operacion {
            display: none; margin-bottom: 14px; padding: 11px 13px; border-radius: 8px;
            border: 1px solid var(--linea); background: var(--bg-elevada); color: var(--texto-suave); font-size: 13px;
        }
        .alerta-operacion.visible { display: block; }
        .alerta-operacion.error { border-color: rgba(241,112,126,.35); color: var(--critico); background: var(--critico-wash); }
        .stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; margin-bottom: 14px; }
        .stat, .panel {
            background: var(--bg-elevada); border: 1px solid var(--linea); border-radius: 10px;
            box-shadow: 0 14px 26px -20px rgba(0,0,0,.65);
        }
        .stat { padding: 16px; min-height: 104px; }
        .stat .lbl { color: var(--texto-suave); font-size: 11px; text-transform: uppercase; letter-spacing: .05em; margin-bottom: 8px; }
        .stat .num { font-family: 'Space Grotesk', sans-serif; font-size: 30px; font-weight: 700; line-height: 1; }
        .stat .nota { color: var(--texto-tenue); font-size: 12px; line-height: 1.35; margin-top: 9px; }
        .stat.warn .num { color: var(--ambar); }
        .stat.ok .num { color: var(--petroleo); }
        .grid { display: grid; grid-template-columns: 1fr; gap: 14px; align-items: start; }
        .panel-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; padding: 15px 18px; border-bottom: 1px solid var(--linea-suave); }
        .panel-head h2 { margin: 0; font-size: 15px; color: var(--texto); }
        .panel-head .sub { color: var(--texto-tenue); font-size: 12px; margin-top: 4px; }
        .bloque-ayuda { padding: 14px 18px; border-bottom: 1px solid var(--linea-suave); color: var(--texto-tenue); font-size: 12px; line-height: 1.45; }
        .toolbar { display: flex; gap: 8px; padding: 14px 18px; border-bottom: 1px solid var(--linea-suave); flex-wrap: wrap; }
        input, select {
            width: 100%; box-sizing: border-box; border: 1px solid var(--linea); border-radius: 8px;
            background: var(--bg); color: var(--texto); font: inherit; font-size: 13px; padding: 9px 11px;
        }
        .toolbar input { flex: 1; min-width: 220px; }
        .toolbar label { display: inline-flex; align-items: center; gap: 7px; color: var(--texto-suave); font-size: 12px; white-space: nowrap; }
        .toolbar label input { width: auto; }
        .table-shell { overflow: auto; max-height: 520px; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .table-shell table { min-width: 740px; }
        th, td { padding: 11px 18px; border-bottom: 1px solid var(--linea-suave); text-align: left; }
        th { color: var(--texto-suave); font-size: 10px; text-transform: uppercase; letter-spacing: .06em; }
        tbody tr:last-child td { border-bottom: none; }
        tbody tr:hover { background: var(--bg-hover); }
        tbody tr.fila-click { cursor: pointer; }
        .mono { font-family: 'JetBrains Mono', monospace; }
        .muted { color: var(--texto-tenue); }
        .badge { display: inline-flex; align-items: center; gap: 5px; padding: 3px 10px; border-radius: 999px; font-size: 11px; font-weight: 600; white-space: nowrap; }
        .badge-ok { background: var(--petroleo-wash); color: var(--petroleo); }
        .badge-warn { background: var(--ambar-wash); color: var(--ambar); }
        .badge-danger { background: var(--critico-wash); color: var(--critico); }
        .vacio, .cargando { color: var(--texto-tenue); font-style: italic; padding: 34px 14px; text-align: center; }
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; padding: 18px; }
        .form-grid .full { grid-column: 1 / -1; }
        .form-grid label { display: block; color: var(--texto-suave); font-size: 12px; font-weight: 600; margin-bottom: 6px; }
        .form-grid.enfocado { outline: 1px solid var(--petroleo); outline-offset: -1px; background: rgba(44,217,190,.04); }
        .form-actions { grid-column: 1 / -1; display: flex; justify-content: flex-end; gap: 8px; align-items: center; }
        .mensaje { color: var(--texto-tenue); font-size: 12px; margin-right: auto; }
        .mensaje.error { color: var(--critico); }
        .mensaje.ok { color: var(--petroleo); }
        .solo-consulta { padding: 18px; color: var(--texto-suave); }
        .solo-consulta b { color: var(--texto); }
        .acciones-admin { display: flex; gap: 10px; flex-wrap: wrap; }
        .overlay-modal {
            position: fixed; inset: 0; display: none; align-items: flex-start; justify-content: center;
            overflow-y: auto; padding: 34px 16px; background: rgba(20, 33, 43, .32);
            backdrop-filter: blur(2px); z-index: 60;
        }
        .overlay-modal.abierto { display: flex; }
        .modal {
            width: min(760px, 100%); background: var(--bg-elevada); border: 1px solid var(--linea);
            border-radius: 10px; box-shadow: 0 24px 70px rgba(20, 33, 43, .22); position: relative;
        }
        .modal-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 16px 18px; border-bottom: 1px solid var(--linea-suave); }
        .modal-head h2 { margin: 0; font-size: 15px; color: var(--texto); }
        .modal-close {
            position: absolute; top: 12px; right: 14px;
            width: 32px; height: 32px; border-radius: 8px; border: 1px solid var(--linea);
            background: var(--bg); color: var(--texto-suave); cursor: pointer; font-size: 18px; line-height: 1;
        }
        .modal-close:hover { color: var(--texto); border-color: var(--petroleo); }
        @media (max-width: 980px) {
            .stats, .grid { grid-template-columns: 1fr; }
            .form-grid { grid-template-columns: 1fr; }
        }

        /* Legibilidad: tema claro real para esta vista */
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
            background: var(--bg);
            color: var(--texto);
        }
        body.tema-oscuro .sidebar {
            background: #FFFFFF;
            border-right-color: var(--linea);
            box-shadow: 12px 0 30px rgba(20,33,43,.06);
        }
        h1, h2, h3, .panel-head h2 { color: var(--texto); }
        p, td, .muted, .panel-head .sub, .stat .nota { color: var(--texto-suave); }
        .encabezado-pagina__etiqueta, .stat.ok .num { color: var(--petroleo); }
        .stat, .panel, .modal, .alerta-operacion {
            background: #FFFFFF;
            border-color: var(--linea);
            box-shadow: 0 12px 28px rgba(20,33,43,.08);
        }
        .panel-head, .toolbar, th, td, .bloque-ayuda {
            border-color: var(--linea-suave);
        }
        th {
            background: #F8FAFC;
            color: var(--texto-tenue);
        }
        tbody tr:hover { background: var(--bg-hover); }
        input, select {
            background: #FFFFFF;
            border-color: #C8D3DC;
            color: var(--texto);
        }
        .toolbar label, .form-grid label, .stat .lbl {
            color: var(--texto-suave);
        }
        .btn {
            background: #FFFFFF;
            color: var(--texto);
            border-color: #C8D3DC;
            box-shadow: 0 2px 6px rgba(20,33,43,.06);
        }
        .btn:hover {
            background: #F2F7F6;
            color: var(--petroleo);
            border-color: var(--petroleo);
        }
        .btn.primario {
            background: #0B8F86;
            border-color: #0B8F86;
            color: #FFFFFF;
            box-shadow: 0 8px 18px rgba(8,125,118,.22);
        }
        .btn.primario:hover {
            background: #087D76;
            color: #FFFFFF;
        }
        .btn.secundario, .modal-close {
            background: #FFFFFF;
            color: var(--texto);
            border-color: #C8D3DC;
        }
        .overlay-modal { background: rgba(20,33,43,.24); }
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
                <div class="encabezado-pagina__etiqueta">Almacén de refacciones</div>
                <h1>Inventario de piezas</h1>
                <p>Controla existencias, mínimos, ubicación de almacén y movimientos de entrada o salida.</p>
            </div>
            <div class="acciones-admin">
                <?php if (Auth::isAdmin()): ?>
                    <button class="btn primario" id="btn-nueva-pieza">Nueva pieza</button>
                    <button class="btn" id="btn-nuevo-mov">Registrar movimiento</button>
                <?php endif; ?>
                <button class="btn" id="btn-actualizar">Actualizar</button>
            </div>
        </div>

        <div id="alerta-operacion" class="alerta-operacion"></div>

        <div class="stats">
            <div class="stat ok"><div class="lbl">Refacciones activas</div><div class="num" id="kpi-total-ref">-</div><div class="nota">Piezas disponibles en catálogo.</div></div>
            <div class="stat warn"><div class="lbl">Stock bajo</div><div class="num" id="kpi-ref-bajas">-</div><div class="nota">Stock actual en mínimo o menor.</div></div>
            <div class="stat"><div class="lbl">Piezas en almacén</div><div class="num" id="kpi-piezas">-</div><div class="nota">Suma de existencias actuales.</div></div>
        </div>

        <div class="grid">
            <section class="panel">
                <div class="panel-head">
                    <div>
                        <h2>Refacciones</h2>
                        <div class="sub"><?= Auth::isAdmin() ? 'Haz clic en una fila para editar la pieza.' : 'Consulta stock, ubicación y estado de cada pieza.' ?></div>
                    </div>
                    <?php if (Auth::isAdmin()): ?><button class="btn primario" id="btn-limpiar-ref">Nueva pieza</button><?php endif; ?>
                </div>
                <div class="toolbar">
                    <input id="buscar-ref" placeholder="Buscar pieza, categoría, modelo o ubicación">
                    <label><input type="checkbox" id="solo-bajas"> Solo bajas</label>
                </div>
                <div id="tabla-refacciones" class="cargando">Cargando...</div>
            </section>

            <div class="overlay-modal" id="overlay-inventario">
            <section class="modal">
                <div class="panel-head">
                    <div>
                        <h2>Administración de inventario</h2>
                        <div class="sub">Alta de piezas y registro de entradas, salidas o ajustes.</div>
                    </div>
                </div>
                    <button class="modal-close" id="cerrar-inventario" type="button" aria-label="Cerrar">x</button>
                <?php if (!Auth::isAdmin()): ?>
                    <div class="solo-consulta">
                        <b>Modo consulta.</b>
                        <p>Tu usuario puede revisar inventario y movimientos, pero las altas y movimientos de almacén están reservados a administradores.</p>
                    </div>
                <?php else: ?>
                    <div class="bloque-ayuda">Primero crea o selecciona una refacción. Después registra el movimiento para mantener el stock actualizado.</div>
                    <div class="panel-head"><h2>Alta / edición de pieza</h2></div>
                    <form class="form-grid" id="form-refaccion">
                        <input type="hidden" id="r-id_refaccion">
                        <div class="full"><label>Nombre</label><input id="r-nombre" required></div>
                        <div><label>Categoría</label><input id="r-categoria" placeholder="Motor, sensor, tarjeta..."></div>
                        <div><label>Modelo compatible</label><input id="r-modelo_compatible"></div>
                        <div><label>Stock actual</label><input id="r-stock_actual" type="number" min="0" value="0" required></div>
                        <div><label>Stock mínimo</label><input id="r-stock_minimo" type="number" min="0" value="0" required></div>
                        <div><label>Ubicación almacén</label><input id="r-ubicacion_almacen"></div>
                        <div><label>Proveedor</label><input id="r-proveedor"></div>
                        <div><label>Costo unitario</label><input id="r-costo_unitario" type="number" min="0" step="0.01"></div>
                        <div class="form-actions">
                            <span class="mensaje" id="msg-ref"></span>
                            <button type="button" class="btn secundario" id="btn-cancelar-edicion">Limpiar</button>
                            <button type="submit" class="btn primario">Guardar pieza</button>
                        </div>
                    </form>

                    <div class="panel-head"><h2>Movimiento de almacén</h2></div>
                    <form class="form-grid" id="form-mov">
                        <div class="full"><label>Pieza</label><select id="m-id_refaccion" required></select></div>
                        <div><label>Tipo</label><select id="m-tipo"><option value="entrada">Entrada</option><option value="salida">Salida</option><option value="ajuste">Ajuste positivo</option></select></div>
                        <div><label>Cantidad</label><input id="m-cantidad" type="number" min="1" value="1" required></div>
                        <div class="full"><label>Máquina relacionada</label><select id="m-id_maquina"><option value="">Sin máquina</option></select></div>
                        <div><label>Técnico</label><input id="m-tecnico"></div>
                        <div><label>Motivo</label><input id="m-motivo" placeholder="Mantenimiento, compra, ajuste..."></div>
                        <div class="form-actions">
                            <span class="mensaje" id="msg-mov"></span>
                            <button type="submit" class="btn primario">Registrar movimiento</button>
                        </div>
                    </form>
                <?php endif; ?>
            </section>
            </div>
        </div>

        <section class="panel" style="margin-top:14px;">
            <div class="panel-head">
                <div>
                    <h2>Últimos movimientos</h2>
                    <div class="sub">Historial reciente de entradas, salidas y ajustes de almacén.</div>
                </div>
            </div>
            <div id="tabla-movimientos" class="cargando">Cargando...</div>
        </section>
    </main>
</div>

<script src="assets/js/comun.js?v=20260912-1"></script>
<script>
    const ES_ADMIN = <?= Auth::isAdmin() ? 'true' : 'false' ?>;
    let refaccionesCache = [];
    let debounce = null;
    const overlayInventario = document.getElementById('overlay-inventario');

    function fmt(n) { return (Number(n) || 0).toLocaleString('es-MX'); }

    function estadoOperacion(mensaje, tipo = '') {
        const alerta = document.getElementById('alerta-operacion');
        alerta.textContent = mensaje || '';
        alerta.className = mensaje ? `alerta-operacion visible ${tipo}` : 'alerta-operacion';
    }

    function badgeStock(f) {
        const actual = Number(f.stock_actual) || 0;
        const minimo = Number(f.stock_minimo) || 0;
        if (actual <= 0) return '<span class="badge badge-danger">Sin stock</span>';
        if (actual <= minimo) return '<span class="badge badge-warn">Bajo</span>';
        return '<span class="badge badge-ok">OK</span>';
    }

    async function cargarResumen() {
        const json = await obtenerJSON('api/mantenimiento_operacion.php?accion=resumen');
        const r = json.data.refacciones || {};
        document.getElementById('kpi-total-ref').textContent = fmt(r.total_refacciones);
        document.getElementById('kpi-ref-bajas').textContent = fmt(r.refacciones_bajas);
        document.getElementById('kpi-piezas').textContent = fmt(r.piezas_en_almacen);
    }

    async function cargarRefacciones() {
        const cont = document.getElementById('tabla-refacciones');
        cont.className = 'cargando';
        cont.textContent = 'Cargando...';
        const params = new URLSearchParams({ accion: 'refacciones' });
        const q = document.getElementById('buscar-ref').value.trim();
        if (q) params.set('q', q);
        if (document.getElementById('solo-bajas').checked) params.set('bajas', '1');
        const json = await obtenerJSON(`api/mantenimiento_operacion.php?${params}`);
        refaccionesCache = json.data;
        poblarSelectRefacciones();
        cont.className = '';
        if (!json.data.length) {
            cont.innerHTML = '<p class="vacio">Sin piezas para este filtro.</p>';
            return;
        }
        cont.innerHTML = `<div class="table-shell"><table><thead><tr><th>Pieza</th><th>Stock</th><th>Ubicación</th><th>Estado</th></tr></thead><tbody>${json.data.map(f => `
            <tr class="${ES_ADMIN ? 'fila-click' : ''}" data-id="${f.id_refaccion}">
                <td><b>${escapeHtml(f.nombre)}</b><br><span class="muted">${escapeHtml(f.categoria) || 'Sin categoría'} · ${escapeHtml(f.modelo_compatible) || 'Cualquier modelo'}</span></td>
                <td class="mono">${fmt(f.stock_actual)} / min ${fmt(f.stock_minimo)}</td>
                <td>${escapeHtml(f.ubicacion_almacen) || '<span class="muted">Sin ubicación</span>'}</td>
                <td>${badgeStock(f)}</td>
            </tr>`).join('')}</tbody></table></div>`;
        cont.querySelectorAll('tr[data-id]').forEach(tr => tr.addEventListener('click', () => editarRefaccion(Number(tr.dataset.id))));
    }

    async function cargarMovimientos() {
        const json = await obtenerJSON('api/mantenimiento_operacion.php?accion=movimientos');
        const cont = document.getElementById('tabla-movimientos');
        cont.className = '';
        if (!json.data.length) {
            cont.innerHTML = '<p class="vacio">Sin movimientos registrados.</p>';
            return;
        }
        cont.innerHTML = `<div class="table-shell"><table><thead><tr><th>Fecha</th><th>Pieza</th><th>Tipo</th><th>Máquina</th><th>Detalle</th></tr></thead><tbody>${json.data.map(f => `
            <tr>
                <td class="mono">${String(f.fecha_movimiento || '').substring(0, 16).replace('T', ' ')}</td>
                <td>${escapeHtml(f.refaccion)}</td>
                <td class="mono">${escapeHtml(f.tipo)} ${fmt(f.cantidad)}</td>
                <td class="mono">${escapeHtml(f.numero_serie) || '-'}</td>
                <td>${escapeHtml(f.tecnico) || '-'}<br><span class="muted">${escapeHtml(f.motivo) || ''}</span></td>
            </tr>`).join('')}</tbody></table></div>`;
    }

    async function cargarMaquinas() {
        if (!ES_ADMIN) return;
        const json = await obtenerJSON('api/mantenimiento_operacion.php?accion=maquinas');
        const select = document.getElementById('m-id_maquina');
        select.innerHTML = '<option value="">Sin máquina</option>' + json.data.map(m =>
            `<option value="${m.id_maquina}">${escapeHtml(m.numero_serie)}${m.nombre_ubicacion ? ' · ' + escapeHtml(m.nombre_ubicacion) : ''}</option>`
        ).join('');
    }

    function poblarSelectRefacciones() {
        if (!ES_ADMIN) return;
        const select = document.getElementById('m-id_refaccion');
        const botonMovimiento = document.querySelector('#form-mov button[type="submit"]');
        if (!refaccionesCache.length) {
            select.innerHTML = '<option value="">Primero guarda una pieza</option>';
            if (botonMovimiento) botonMovimiento.disabled = true;
            return;
        }
        select.innerHTML = refaccionesCache.map(r => `<option value="${r.id_refaccion}">${escapeHtml(r.nombre)} · stock ${fmt(r.stock_actual)}</option>`).join('');
        if (botonMovimiento) botonMovimiento.disabled = false;
    }

    function enfocarFormularioRefaccion() {
        const form = document.getElementById('form-refaccion');
        if (!form) return;
        abrirModalInventario('pieza');
        form.classList.add('enfocado');
        setTimeout(() => form.classList.remove('enfocado'), 1100);
        document.getElementById('r-nombre')?.focus();
    }

    function abrirModalInventario(seccion = 'pieza') {
        if (!overlayInventario) return;
        overlayInventario.classList.add('abierto');
        setTimeout(() => {
            if (seccion === 'movimiento') {
                document.getElementById('form-mov')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
                document.getElementById('m-id_refaccion')?.focus();
            } else {
                document.getElementById('form-refaccion')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
                document.getElementById('r-nombre')?.focus();
            }
        }, 30);
    }

    function cerrarModalInventario() {
        overlayInventario?.classList.remove('abierto');
    }

    function limpiarRefaccion(enfocar = false) {
        const form = document.getElementById('form-refaccion');
        if (!form) return;
        form.reset();
        document.getElementById('r-id_refaccion').value = '';
        document.getElementById('r-stock_actual').value = '0';
        document.getElementById('r-stock_minimo').value = '0';
        document.getElementById('msg-ref').textContent = enfocar ? 'Captura los datos de la nueva pieza.' : '';
        document.getElementById('msg-ref').className = enfocar ? 'mensaje ok' : 'mensaje';
        if (enfocar) enfocarFormularioRefaccion();
    }

    function editarRefaccion(id) {
        const f = refaccionesCache.find(x => Number(x.id_refaccion) === id);
        if (!f || !ES_ADMIN) return;
        for (const campo of ['id_refaccion', 'nombre', 'categoria', 'modelo_compatible', 'stock_actual', 'stock_minimo', 'ubicacion_almacen', 'proveedor', 'costo_unitario']) {
            const el = document.getElementById(`r-${campo}`);
            if (el) el.value = f[campo] ?? '';
        }
        document.getElementById('msg-ref').textContent = 'Editando pieza seleccionada.';
        document.getElementById('msg-ref').className = 'mensaje ok';
        abrirModalInventario('pieza');
    }

    document.getElementById('form-refaccion')?.addEventListener('submit', async (ev) => {
        ev.preventDefault();
        const msg = document.getElementById('msg-ref');
        const payload = {};
        for (const campo of ['id_refaccion', 'nombre', 'categoria', 'modelo_compatible', 'stock_actual', 'stock_minimo', 'ubicacion_almacen', 'proveedor', 'costo_unitario']) {
            payload[campo] = document.getElementById(`r-${campo}`).value;
        }
        const res = await fetch('api/mantenimiento_operacion.php?accion=refaccion', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
        const json = await res.json();
        if (json.ok) {
            const idGuardado = json.data.id_refaccion;
            limpiarRefaccion();
            msg.textContent = `Pieza ${json.data.accion}.`;
            msg.className = 'mensaje ok';
            await Promise.all([cargarResumen(), cargarRefacciones()]);
            if (idGuardado) document.getElementById('m-id_refaccion').value = String(idGuardado);
            cerrarModalInventario();
        } else {
            msg.textContent = json.error || 'No se pudo guardar.';
            msg.className = 'mensaje error';
        }
    });

    document.getElementById('form-mov')?.addEventListener('submit', async (ev) => {
        ev.preventDefault();
        const msg = document.getElementById('msg-mov');
        const payload = {
            id_refaccion: document.getElementById('m-id_refaccion').value,
            tipo: document.getElementById('m-tipo').value,
            cantidad: document.getElementById('m-cantidad').value,
            id_maquina: document.getElementById('m-id_maquina').value || null,
            tecnico: document.getElementById('m-tecnico').value,
            motivo: document.getElementById('m-motivo').value,
        };
        const res = await fetch('api/mantenimiento_operacion.php?accion=movimiento', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
        const json = await res.json();
        msg.textContent = json.ok ? 'Movimiento registrado.' : (json.error || 'No se pudo registrar.');
        msg.className = json.ok ? 'mensaje ok' : 'mensaje error';
        if (json.ok) { ev.target.reset(); document.getElementById('m-cantidad').value = '1'; await actualizarTodo(); cerrarModalInventario(); }
    });

    document.getElementById('btn-limpiar-ref')?.addEventListener('click', () => limpiarRefaccion(true));
    document.getElementById('btn-nueva-pieza')?.addEventListener('click', () => limpiarRefaccion(true));
    document.getElementById('btn-nuevo-mov')?.addEventListener('click', () => abrirModalInventario('movimiento'));
    document.getElementById('cerrar-inventario')?.addEventListener('click', cerrarModalInventario);
    overlayInventario?.addEventListener('click', (ev) => { if (ev.target === overlayInventario) cerrarModalInventario(); });
    document.getElementById('btn-cancelar-edicion')?.addEventListener('click', () => limpiarRefaccion());
    document.getElementById('btn-actualizar').addEventListener('click', actualizarTodo);
    document.getElementById('buscar-ref').addEventListener('input', () => {
        clearTimeout(debounce);
        debounce = setTimeout(cargarRefacciones, 300);
    });
    document.getElementById('solo-bajas').addEventListener('change', cargarRefacciones);

    async function actualizarTodo() {
        const boton = document.getElementById('btn-actualizar');
        boton.disabled = true;
        estadoOperacion('');
        try {
            await Promise.all([cargarResumen(), cargarRefacciones(), cargarMovimientos(), cargarMaquinas()]);
        } catch (e) {
            estadoOperacion(e.message || 'No se pudo cargar la información de inventario.', 'error');
        } finally {
            boton.disabled = false;
        }
    }

    actualizarTodo();
</script>
</body>
</html>
