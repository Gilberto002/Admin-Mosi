<?php
// public/resurtir.php — consola de resurtido (sucursal real -> máquina de
// vending), portada de system/adminVending/ (panel "Administrador Vending
// Machine"). Fase 1: modal de un carril (para carriles vacíos) + edición
// inline en tabla (para carriles con producto). El modal masivo de "bajo
// stock" y la carga por Excel del panel viejo quedan para una fase 2.
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/bootstrap.php';

use App\Auth\Auth;

Auth::requireAdmin();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>MDM - Resurtir Máquina dispensadora</title>
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
        .encabezado-pagina p { margin: 0 0 20px; color: var(--texto-suave); font-size: 13.5px; max-width: 640px; }

        .toolbar-card { background: var(--bg-elevada); border: 1px solid var(--linea); border-radius: 14px; padding: 14px 16px; margin-bottom: 14px; }
        .barra { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
        .barra label { font-size: 12.5px; color: var(--texto-suave); }
        .barra select { padding: 9px 11px; border: 1px solid var(--linea); border-radius: 8px; font-size: 13px; background: var(--bg); color: var(--texto); font-family: inherit; min-width: 260px; }
        .barra input[type="text"] { padding: 9px 11px; border: 1px solid var(--linea); border-radius: 8px; font-size: 13px; background: var(--bg); color: var(--texto); font-family: inherit; min-width: 220px; }
        .badge-neutro { background: rgba(255,255,255,.06); color: var(--texto-suave); }
        .contador { font-size: 12px; color: var(--texto-tenue); margin-left: auto; white-space: nowrap; }
        .btn-secundario { border: 1px solid var(--linea); background: var(--bg); color: var(--texto-suave); font-family: inherit; font-weight: 600; font-size: 12.5px; padding: 8px 12px; border-radius: 8px; cursor: pointer; white-space: nowrap; }
        .btn-secundario:hover { border-color: var(--petroleo); color: var(--petroleo); }
        .toolbar-card.activo-seleccion { border-color: var(--petroleo); background: var(--petroleo-wash); }
        .breadcrumb-texto { font-weight: 700; font-size: 13.5px; color: var(--petroleo); }

        .lista-vendings { display: grid; gap: 8px; padding: 12px; }
        .item-vending { display: flex; align-items: center; justify-content: space-between; gap: 10px; border: 1px solid var(--linea-suave); border-radius: 10px; padding: 10px 14px; cursor: pointer; background: var(--bg); }
        .item-vending:hover { border-color: var(--petroleo); }
        .item-vending.seleccionada { border-color: var(--petroleo); background: var(--petroleo-wash); }
        .item-vending .nombre { font-weight: 700; font-size: 13px; }
        .item-vending .ubicacion { color: var(--texto-suave); font-size: 12px; margin-top: 2px; }
        .item-vending .alerta { font-family: 'JetBrains Mono', monospace; font-weight: 700; font-size: 13px; white-space: nowrap; }
        .item-vending .alerta.sin-alerta { color: var(--texto-tenue); font-weight: 400; }

        .card { background: var(--bg-elevada); border: 1px solid var(--linea); border-radius: 14px; }
        .card-scroll { max-height: 420px; overflow-y: auto; }

        .panel-dos-columnas { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-top: 14px; align-items: start; }
        @media (max-width: 900px) { .panel-dos-columnas { grid-template-columns: 1fr; } }
        .vacio, .cargando { color: var(--texto-tenue); font-style: italic; padding: 40px 14px; text-align: center; }
        table.tabla-carriles { width: 100%; border-collapse: collapse; }
        table.tabla-carriles th, table.tabla-carriles td { padding: 12px 16px; text-align: left; font-size: 13px; border-bottom: 1px solid var(--linea-suave); vertical-align: middle; }
        table.tabla-carriles th { color: var(--texto-tenue); font-size: 11px; text-transform: uppercase; letter-spacing: .04em; }
        table.tabla-carriles tr:last-child td { border-bottom: none; }
        table.tabla-carriles tr:hover { background: var(--bg-hover); }
        table.tabla-carriles tr.fila-vacia td { color: var(--texto-tenue); }
        .badge { display: inline-flex; align-items: center; gap: 5px; padding: 3px 10px; border-radius: 999px; font-size: 11px; font-weight: 600; }
        .badge-danger { background: var(--critico-wash); color: var(--critico); }
        .badge-ok { background: var(--petroleo-wash); color: var(--petroleo); }
        .fila-editor { display: flex; gap: 6px; align-items: center; }
        .fila-editor input[type="text"], .fila-editor input[type="number"] { width: 90px; padding: 7px 9px; border: 1px solid var(--linea); border-radius: 7px; font-size: 12.5px; background: var(--bg); color: var(--texto); font-family: inherit; }
        .fila-editor input.upc { width: 130px; font-family: 'JetBrains Mono', monospace; }
        .fila-editor button { border: none; background: var(--petroleo); color: #06201F; font-family: inherit; font-weight: 600; font-size: 12px; padding: 7px 12px; border-radius: 7px; cursor: pointer; }
        .fila-editor button:disabled { opacity: .5; cursor: not-allowed; }
        .fila-mensaje { font-size: 11.5px; margin-top: 4px; }
        .fila-mensaje.error { color: var(--critico); }
        .fila-mensaje.ok { color: var(--petroleo); }

        table.tabla-bajo-stock { width: 100%; border-collapse: collapse; }
        table.tabla-bajo-stock th, table.tabla-bajo-stock td { padding: 12px 16px; text-align: left; font-size: 13px; border-bottom: 1px solid var(--linea-suave); vertical-align: middle; }
        table.tabla-bajo-stock th { color: var(--texto-tenue); font-size: 11px; text-transform: uppercase; letter-spacing: .04em; }
        table.tabla-bajo-stock tr:last-child td { border-bottom: none; }
        table.tabla-bajo-stock tr:hover { background: var(--bg-hover); }
        .btn-ver-cercanas { border: none; background: var(--petroleo-wash); color: var(--petroleo); border-radius: 6px; padding: 6px 12px; font-family: inherit; font-weight: 600; font-size: 12px; cursor: pointer; white-space: nowrap; }
        .btn-ver-cercanas:hover { background: var(--petroleo); color: #06201F; }
        table.tabla-bajo-stock tr.fila-seleccionada { background: var(--petroleo-wash); }
        table.tabla-bajo-stock tr.fila-seleccionada td { color: var(--texto); }

        .lista-cercanas { display: grid; gap: 10px; }
        .item-cercana { border: 1px solid var(--linea-suave); border-radius: 10px; padding: 12px 14px; }
        .item-cercana .cabecera { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
        .item-cercana .nombre { font-weight: 700; font-size: 13.5px; }
        .item-cercana .badge-cedis { background: var(--ambar-wash); color: var(--ambar); border-radius: 999px; padding: 2px 9px; font-size: 10.5px; font-weight: 700; text-transform: uppercase; }
        .item-cercana .direccion { color: var(--texto-suave); font-size: 12px; margin-top: 4px; }
        .item-cercana .disponibles { color: var(--petroleo); font-family: 'JetBrains Mono', monospace; font-weight: 700; font-size: 13px; white-space: nowrap; }
        .item-cercana .acciones { display: flex; gap: 8px; margin-top: 10px; }
        .item-cercana .acciones button { border: 1px solid var(--linea); background: var(--bg); color: var(--texto); font-family: inherit; font-weight: 600; font-size: 12px; padding: 6px 12px; border-radius: 6px; cursor: pointer; }
        .item-cercana .acciones button:hover { border-color: var(--petroleo); color: var(--petroleo); }
        .item-cercana .acciones button.activo { background: var(--petroleo-wash); border-color: var(--petroleo); color: var(--petroleo); }
        .form-accion { margin-top: 10px; padding-top: 10px; border-top: 1px dashed var(--linea-suave); display: none; }
        .form-accion.abierto { display: grid; gap: 8px; grid-template-columns: repeat(2, 1fr); }
        .form-accion input { border: 1px solid var(--linea); border-radius: 7px; padding: 7px 9px; font: inherit; font-size: 12.5px; background: var(--bg); color: var(--texto); }
        .form-accion .fila-completa { grid-column: 1 / -1; }
        .form-accion .btn-confirmar { border: none; background: var(--petroleo); color: #06201F; font-family: inherit; font-weight: 700; font-size: 12.5px; padding: 8px 12px; border-radius: 7px; cursor: pointer; }
        .form-accion .form-mensaje { font-size: 11.5px; }
        .form-accion .form-mensaje.error { color: var(--critico); }
        .form-accion .form-mensaje.ok { color: var(--petroleo); }
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
            <h1>Resurtir Máquina dispensadora</h1>
            <p>Traspasa producto de una sucursal real a su Máquina dispensadora. Actualiza el carril y registra el movimiento real de inventario en el mismo paso.</p>
        </div>

        <div class="toolbar-card" id="tarjeta-region">
            <div class="barra">
                <label for="select-region">Región</label>
                <select id="select-region"><option value="">Cargando…</option></select>
                <button type="button" class="btn-secundario" id="btn-buscar-directo" style="margin-left:auto;">Buscar directo / Intecco</button>
            </div>
        </div>

        <div class="toolbar-card activo-seleccion" id="tarjeta-seleccion-activa" style="display:none;">
            <div class="barra">
                <span id="breadcrumb-seleccion" class="breadcrumb-texto"></span>
            </div>
        </div>

        <div class="toolbar-card" id="tarjeta-directo" style="display:none;">
            <div class="barra">
                <label for="buscador-selector">Buscar (incluye Intecco)</label>
                <input type="text" id="buscador-selector" placeholder="Buscar…">
                <select id="selector-sucursal"><option value="">Selecciona…</option></select>
                <span class="contador" id="contador"></span>
                <button type="button" class="btn-secundario" id="btn-cerrar-directo">Volver a región</button>
            </div>
        </div>

        <div class="panel-dos-columnas" id="panel-listado-carriles">
            <div class="card card-scroll" id="tarjeta-listado-vendings">
                <div id="listado-vendings" class="vacio">Selecciona una región para ver sus Máquinas dispensadoras.</div>
            </div>
            <div class="card card-scroll" id="tarjeta-carriles">
                <div id="lista-carriles" class="vacio">Selecciona una Máquina dispensadora de la lista para ver sus carriles.</div>
            </div>
        </div>

        <div class="panel-dos-columnas" id="panel-dos-columnas" style="display:none;">
            <div class="card" id="panel-bajo-stock">
                <div class="panel-head" style="display:flex;align-items:center;justify-content:space-between;padding:14px 16px;border-bottom:1px solid var(--linea-suave);">
                    <div>
                        <h2 style="margin:0;font-size:14px;">Medicamentos con stock bajo</h2>
                        <div class="sub" style="color:var(--texto-suave);font-size:12px;margin-top:4px;">Carriles por debajo del umbral de capacidad.</div>
                    </div>
                    <div style="display:flex;gap:8px;align-items:center;">
                        <label style="font-size:12px;color:var(--texto-suave);">Umbral</label>
                        <select id="bajo-stock-umbral" style="border:1px solid var(--linea);border-radius:8px;padding:7px 9px;font:inherit;font-size:12px;background:var(--bg);color:var(--texto);">
                            <option value="30">30%</option>
                            <option value="50" selected>50%</option>
                            <option value="70">70%</option>
                        </select>
                    </div>
                </div>
                <div style="display:flex;gap:8px;align-items:center;padding:10px 16px;border-bottom:1px solid var(--linea-suave);flex-wrap:wrap;">
                    <button type="button" class="btn-secundario" id="btn-pedido-masivo">Pedido de todos los faltantes</button>
                    <button type="button" class="btn-secundario" id="btn-traspaso-masivo">Traspaso de todos los faltantes</button>
                    <span class="fila-mensaje" id="masivo-mensaje" style="margin-left:4px;"></span>
                </div>
                <div id="lista-bajo-stock" class="vacio card-scroll">Selecciona una Máquina dispensadora para ver sus medicamentos con stock bajo.</div>
            </div>

            <div class="card" id="panel-cercanas">
                <div class="panel-head" style="padding:14px 16px;border-bottom:1px solid var(--linea-suave);">
                    <h2 id="cercanas-titulo" style="margin:0;font-size:14px;">Disponibilidad cercana</h2>
                    <div class="sub" id="cercanas-mensaje" style="color:var(--texto-suave);font-size:12px;margin-top:4px;"></div>
                </div>
                <div id="cercanas-contenido" class="vacio">Selecciona un medicamento de la izquierda para ver dónde hay existencia cercana.</div>
            </div>
        </div>
    </main>
</div>

<script src="assets/js/comun.js?v=20260912-1"></script>
<script>
    // seleccionActual: { tipo: 'intecco'|'vendingm', id } -- 'intecco' es un
    // id_SucursalClinica (real, con st_vendingMachine); 'vendingm' es un
    // id_maquina de vendingm.maquinas (sin relación con Intecco).
    let seleccionActual = null;
    let listaCompleta = [];

    function nombreProducto(idUpc, productos) {
        const p = productos.find(x => String(x.idProduct) === String(idUpc));
        return p ? p.name : null;
    }

    function filaCarril(carril, productos, enSucursal) {
        const lane = carril.laneDetails && carril.laneDetails.length > 0 ? carril.laneDetails[0] : null;
        const idUpc = lane && lane.warehouseDetail ? lane.warehouseDetail.idProduct : null;
        const cantidadActual = lane ? (parseInt(lane.quantity, 10) || 0) : 0;
        const ocupado = !!idUpc && cantidadActual > 0;
        const nombre = idUpc ? nombreProducto(idUpc, productos) : null;
        const infoSucursal = idUpc ? enSucursal[String(idUpc)] : null;
        const idCarril = `${carril.idRow}-${carril.idColumn}-${carril.idMod ?? 0}`;

        return `<tr data-id-row="${carril.idRow}" data-id-column="${carril.idColumn}" data-id-mod="${carril.idMod ?? 0}" data-cantidad-actual="${cantidadActual}" data-id-upc="${idUpc ? escapeHtml(idUpc) : ''}" class="${ocupado ? '' : 'fila-vacia'}">
            <td>Fila ${carril.idRow} · Col ${carril.idColumn}</td>
            <td>${ocupado ? (escapeHtml(nombre) || `UPC ${escapeHtml(idUpc)}`) : '<span class="badge badge-danger">Vacío</span>'}</td>
            <td>${ocupado ? cantidadActual : '—'}</td>
            <td>${infoSucursal ? `<span class="badge badge-ok">${infoSucursal.disponibles}</span>` : '—'}</td>
            <td>
                <div class="fila-editor">
                    ${ocupado ? '' : `<input type="text" class="upc" placeholder="UPC" data-campo="upc">`}
                    <input type="number" min="0" class="cantidad" placeholder="Cantidad" value="${ocupado ? cantidadActual : ''}" data-campo="cantidad">
                    <button type="button" class="btn-guardar-carril" data-id-carril="${idCarril}">Guardar</button>
                </div>
                <div class="fila-mensaje" data-mensaje="${idCarril}"></div>
            </td>
        </tr>`;
    }

    async function cargarCarrilesIntecco() {
        const cont = document.getElementById('lista-carriles');
        cont.className = 'cargando';
        cont.textContent = 'Cargando…';

        try {
            const json = await obtenerJSON(`api/resurtir_carril.php?accion=carriles&id_sucursal_origen=${seleccionActual.id}`);
            const { carriles, productos, en_sucursal: enSucursal } = json.data;

            document.getElementById('contador').textContent = `${carriles.length} carril(es) · máquina ${json.data.id_machine}`;

            if (carriles.length === 0) {
                cont.className = '';
                cont.innerHTML = '<p class="vacio">Esta máquina no tiene carriles configurados en Intecco.</p>';
                return;
            }

            cont.className = '';
            cont.innerHTML = `<table class="tabla-carriles">
                <thead><tr><th>Carril</th><th>Producto</th><th>En carril</th><th>En sucursal</th><th>Ajustar</th></tr></thead>
                <tbody>${carriles.map(c => filaCarril(c, productos, enSucursal)).join('')}</tbody>
            </table>`;

            cont.querySelectorAll('.btn-guardar-carril').forEach(btn => {
                btn.addEventListener('click', () => guardarCarrilIntecco(btn));
            });
        } catch (e) {
            cont.className = '';
            cont.innerHTML = `<p class="vacio">${escapeHtml(e.message) || 'No se pudo conectar con el servidor.'}</p>`;
        }
    }

    async function guardarCarrilIntecco(btn) {
        const fila = btn.closest('tr');
        const idRow = parseInt(fila.dataset.idRow, 10);
        const idColumn = parseInt(fila.dataset.idColumn, 10);
        const idMod = parseInt(fila.dataset.idMod, 10) || 0;
        const cantidadActual = parseInt(fila.dataset.cantidadActual, 10) || 0;

        const inputCantidad = fila.querySelector('[data-campo="cantidad"]');
        const inputUpc = fila.querySelector('[data-campo="upc"]');
        const idCarril = btn.dataset.idCarril;
        const mensaje = fila.querySelector(`[data-mensaje="${idCarril}"]`);

        const cantidadNueva = parseInt(inputCantidad.value, 10);
        if (isNaN(cantidadNueva) || cantidadNueva < 0) {
            mensaje.className = 'fila-mensaje error';
            mensaje.textContent = 'Cantidad inválida.';
            return;
        }

        let idUpc = null;
        if (cantidadActual === 0) {
            idUpc = inputUpc ? inputUpc.value.trim() : '';
            if (!idUpc) {
                mensaje.className = 'fila-mensaje error';
                mensaje.textContent = 'Este carril está vacío: ingresa el UPC del producto a surtir.';
                return;
            }
        } else {
            // Carril ocupado: el UPC no cambia, solo la cantidad — se toma del
            // producto ya asignado (guardado en el render, ver filaCarril()).
            idUpc = fila.dataset.idUpc || null;
        }

        if (cantidadNueva === cantidadActual) {
            mensaje.className = 'fila-mensaje';
            mensaje.textContent = 'Sin cambios.';
            return;
        }

        btn.disabled = true;
        mensaje.className = 'fila-mensaje';
        mensaje.textContent = 'Guardando…';

        try {
            const res = await fetch('api/resurtir_carril.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', ...csrfHeader() },
                body: JSON.stringify({
                    id_sucursal_origen: seleccionActual.id,
                    id_upc: idUpc,
                    id_column: idColumn,
                    id_row: idRow,
                    id_mod: idMod,
                    cantidad_actual: cantidadActual,
                    cantidad_nueva: cantidadNueva,
                }),
            });
            const json = await res.json();

            if (!json.ok) {
                mensaje.className = 'fila-mensaje error';
                mensaje.textContent = json.intecco_ya_actualizado
                    ? `El carril SÍ se actualizó en la máquina, pero: ${json.error}`
                    : (json.error || 'No se pudo guardar.');
                btn.disabled = false;
                return;
            }

            mensaje.className = 'fila-mensaje ok';
            mensaje.textContent = 'Guardado correctamente.';
            setTimeout(cargarCarrilesIntecco, 900);
        } catch (e) {
            mensaje.className = 'fila-mensaje error';
            mensaje.textContent = 'No se pudo conectar con el servidor.';
            btn.disabled = false;
        }
    }

    // ---------------- Carriles vendingm (sin Intecco) ----------------
    function filaCarrilVendingm(c) {
        const cantidadActual = Number(c.cantidad_actual) || 0;
        const ocupado = cantidadActual > 0;
        const idCarril = `v-${c.numero_carril}`;
        // Si este carril tiene una solicitud ya enviada a CEDIS pendiente de
        // recepción (ver detalle_maquina.php), se ofrece ligarla aquí mismo:
        // al confirmar la carga física se manda id_solicitud y el backend
        // intenta la recepción real -- ver guardarCarrilVendingm().
        const solicitudLigable = c.id_solicitud ? `
            <label class="fila-solicitud-cedis" style="display:flex;align-items:center;gap:6px;font-size:11.5px;color:var(--texto-tenue);margin-top:4px;">
                <input type="checkbox" data-campo="ligar_solicitud" checked>
                Confirmar recepción del pedido CEDIS (solicitud #${c.id_solicitud}, enviada el ${escapeHtml(c.solicitud_fecha || '')})
            </label>` : '';

        return `<tr data-numero-carril="${c.numero_carril}" data-cantidad-actual="${cantidadActual}" data-id-solicitud="${c.id_solicitud || ''}" class="${ocupado ? '' : 'fila-vacia'}">
            <td>Carril ${c.numero_carril}</td>
            <td>${ocupado ? (escapeHtml(c.nombre) || '<span class="muted">Sin nombre</span>') : '<span class="badge badge-danger">Vacío</span>'}</td>
            <td>${ocupado ? cantidadActual : '—'}</td>
            <td>${c.fecha_caducidad ? `<span class="badge badge-neutro mono">${escapeHtml(c.fecha_caducidad)}</span>` : '—'}</td>
            <td>
                <div class="fila-editor">
                    ${ocupado ? '' : `<input type="text" class="upc" placeholder="Código barras" data-campo="codigo_barras">`}
                    <input type="number" min="0" class="cantidad" placeholder="Cantidad" value="${ocupado ? cantidadActual : ''}" data-campo="cantidad">
                    <input type="text" placeholder="Lote" data-campo="numero_lote" style="width:80px;">
                    <input type="date" data-campo="fecha_caducidad" style="width:135px;">
                    <button type="button" class="btn-guardar-carril-vm" data-id-carril="${idCarril}">Guardar</button>
                </div>
                ${solicitudLigable}
                <div class="fila-mensaje" data-mensaje="${idCarril}"></div>
            </td>
        </tr>`;
    }

    async function cargarCarrilesVendingm() {
        const cont = document.getElementById('lista-carriles');
        cont.className = 'cargando';
        cont.textContent = 'Cargando…';

        try {
            const json = await obtenerJSON(`api/detalle_maquina.php?id_maquina=${seleccionActual.id}`);
            const carriles = json.data || [];

            document.getElementById('contador').textContent = `${carriles.length} carril(es) · Máquina dispensadora ${seleccionActual.id}`;

            if (carriles.length === 0) {
                cont.className = '';
                cont.innerHTML = '<p class="vacio">Esta máquina no tiene carriles configurados.</p>';
                return;
            }

            cont.className = '';
            cont.innerHTML = `<table class="tabla-carriles">
                <thead><tr><th>Carril</th><th>Producto</th><th>En carril</th><th>Caduca</th><th>Ajustar (lote/caducidad solo al surtir)</th></tr></thead>
                <tbody>${carriles.map(filaCarrilVendingm).join('')}</tbody>
            </table>`;

            cont.querySelectorAll('.btn-guardar-carril-vm').forEach(btn => {
                btn.addEventListener('click', () => guardarCarrilVendingm(btn));
            });
        } catch (e) {
            cont.className = '';
            cont.innerHTML = `<p class="vacio">${escapeHtml(e.message) || 'No se pudo conectar con el servidor.'}</p>`;
        }
    }

    async function guardarCarrilVendingm(btn) {
        const fila = btn.closest('tr');
        const numeroCarril = parseInt(fila.dataset.numeroCarril, 10);
        const cantidadActual = parseInt(fila.dataset.cantidadActual, 10) || 0;

        const cantidadNueva = parseInt(fila.querySelector('[data-campo="cantidad"]').value, 10);
        const idCarril = btn.dataset.idCarril;
        const mensaje = fila.querySelector(`[data-mensaje="${idCarril}"]`);

        if (isNaN(cantidadNueva) || cantidadNueva < 0) {
            mensaje.className = 'fila-mensaje error';
            mensaje.textContent = 'Cantidad inválida.';
            return;
        }
        if (cantidadNueva === cantidadActual) {
            mensaje.className = 'fila-mensaje';
            mensaje.textContent = 'Sin cambios.';
            return;
        }

        const inputCB = fila.querySelector('[data-campo="codigo_barras"]');
        const payload = {
            id_maquina: seleccionActual.id,
            numero_carril: numeroCarril,
            cantidad_actual: cantidadActual,
            cantidad_nueva: cantidadNueva,
            codigo_barras: inputCB ? inputCB.value.trim() : '',
            numero_lote: fila.querySelector('[data-campo="numero_lote"]').value.trim(),
            fecha_caducidad: fila.querySelector('[data-campo="fecha_caducidad"]').value,
        };

        // Si esta carga responde a una solicitud enviada a CEDIS y el
        // colaborador dejó marcada la casilla de confirmación, se liga aquí
        // -- el backend intenta la recepción real dentro de la misma
        // transacción (ver App\Vending\RecepcionPedidoService). Si el
        // pedido en CEDIS todavía no está listo, TODA la carga se rechaza
        // (json.ok === false) para no dejar la solicitud a medias.
        const idSolicitud = fila.dataset.idSolicitud;
        const chkLigar = fila.querySelector('[data-campo="ligar_solicitud"]');
        if (idSolicitud && (!chkLigar || chkLigar.checked)) {
            payload.id_solicitud = Number(idSolicitud);
        }

        btn.disabled = true;
        mensaje.className = 'fila-mensaje';
        mensaje.textContent = 'Guardando…';

        try {
            const res = await fetch('api/resurtir_vendingm.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', ...csrfHeader() },
                body: JSON.stringify(payload),
            });
            const json = await res.json();

            if (!json.ok) {
                mensaje.className = 'fila-mensaje error';
                mensaje.textContent = json.error || 'No se pudo guardar.';
                btn.disabled = false;
                return;
            }

            mensaje.className = 'fila-mensaje ok';
            mensaje.textContent = 'Guardado correctamente.';
            setTimeout(cargarCarrilesVendingm, 900);
        } catch (e) {
            mensaje.className = 'fila-mensaje error';
            mensaje.textContent = 'No se pudo conectar con el servidor.';
            btn.disabled = false;
        }
    }

    // ---------------- Stock bajo + disponibilidad cercana (solo vendingm) ----------------
    // Medicamento actualmente elegido en "stock bajo" -- contexto para los
    // botones de Traspaso/Pedido de cada tarjeta de Disponibilidad cercana.
    let medicamentoSeleccionado = null;
    // Último listado de faltantes cargado -- lo usan el Pedido y el Traspaso
    // masivos para saber qué carriles incluir sin volver a consultar.
    let bajoStockCache = [];

    function filaBajoStock(f) {
        return `<tr data-codigo="${escapeHtml(f.codigo_barras)}" data-nombre="${escapeHtml(f.nombre)}">
            <td>Carril ${f.numero_carril}</td>
            <td>${escapeHtml(f.sustancia_activa) || escapeHtml(f.nombre) || '<span class="muted">Sin nombre</span>'}<br><span class="mono" style="color:var(--texto-tenue);font-size:11px;">${escapeHtml(f.clave) || '—'} · ${escapeHtml(f.codigo_barras) || '—'}</span></td>
            <td>${f.cantidad_actual}</td>
            <td>${escapeHtml(f.numero_lote) || '<span class="muted">—</span>'}${f.fecha_caducidad ? `<br><span class="mono" style="color:var(--texto-tenue);font-size:11px;">${escapeHtml(f.fecha_caducidad)}</span>` : ''}</td>
            <td><span class="badge badge-danger">Faltan ${f.cantidad_faltante}</span><br><span style="color:var(--texto-tenue);font-size:11px;">${f.porcentaje_capacidad}%</span></td>
            <td><button type="button" class="btn-ver-cercanas" data-codigo="${escapeHtml(f.codigo_barras)}" data-nombre="${escapeHtml(f.nombre)}" data-carril="${f.numero_carril}" data-cantidad="${f.cantidad_actual}" data-id-solicitud="${f.id_solicitud || ''}" data-solicitud-fecha="${escapeHtml(f.solicitud_fecha || '')}">Ver disponibilidad cercana</button></td>
        </tr>`;
    }

    async function cargarBajoStock() {
        const contenedor = document.getElementById('panel-dos-columnas');
        const cont = document.getElementById('lista-bajo-stock');
        document.getElementById('masivo-mensaje').textContent = '';
        bajoStockCache = [];
        if (!seleccionActual || seleccionActual.tipo !== 'vendingm') {
            contenedor.style.display = 'none';
            return;
        }
        contenedor.style.display = '';
        reiniciarCercanas();
        cont.className = 'cargando card-scroll';
        cont.textContent = 'Cargando…';

        const umbral = document.getElementById('bajo-stock-umbral').value || '50';
        try {
            const json = await obtenerJSON(`api/disponibilidad_cercana.php?accion=bajo_stock&id_maquina=${seleccionActual.id}&umbral_porcentaje=${umbral}`);
            const filas = json.data || [];
            bajoStockCache = filas;
            if (filas.length === 0) {
                cont.className = 'card-scroll';
                cont.innerHTML = '<p class="vacio">Ningún medicamento de esta máquina está por debajo del umbral seleccionado.</p>';
                return;
            }
            cont.className = 'card-scroll';
            cont.innerHTML = `<table class="tabla-bajo-stock">
                <thead><tr><th>Carril</th><th>Medicamento</th><th>En carril</th><th>Lote</th><th>Faltante</th><th></th></tr></thead>
                <tbody>${filas.map(filaBajoStock).join('')}</tbody>
            </table>`;
            cont.querySelectorAll('.btn-ver-cercanas').forEach(btn => {
                btn.addEventListener('click', () => mostrarCercanas(btn));
            });
        } catch (e) {
            cont.className = 'card-scroll';
            cont.innerHTML = `<p class="vacio">${escapeHtml(e.message) || 'No se pudo consultar el stock bajo.'}</p>`;
        }
    }

    function itemCercana(f) {
        const idSucursal = f.id_SucursalClinica;
        return `<div class="item-cercana" data-id-sucursal="${idSucursal}">
            <div class="cabecera">
                <span class="nombre">${escapeHtml(f.st_Nombre)}</span>
                ${f.tipo_nombre !== 'Venta' ? `<span class="badge-cedis">${escapeHtml(f.tipo_nombre)}</span>` : ''}
            </div>
            <div class="direccion">${escapeHtml(f.st_Domicilio) || 'Sin domicilio registrado'}${f.municipio_nombre ? ' · ' + escapeHtml(f.municipio_nombre) : ''}${f.estado_nombre ? ', ' + escapeHtml(f.estado_nombre) : ''}${f.st_Telefonos ? ' · ' + escapeHtml(f.st_Telefonos) : ''}</div>
            <div class="disponibles">${f.disponibles} disponibles</div>
            <div class="acciones">
                <button type="button" class="btn-accion" data-tipo="traspaso" data-id-sucursal="${idSucursal}">Traspaso</button>
                <button type="button" class="btn-accion" data-tipo="pedido" data-id-sucursal="${idSucursal}">Pedido</button>
            </div>
            <div class="form-accion" data-id-sucursal="${idSucursal}"></div>
        </div>`;
    }

    function formularioTraspaso() {
        const solicitudLigable = medicamentoSeleccionado && medicamentoSeleccionado.idSolicitud ? `
            <label class="fila-completa" style="display:flex;align-items:center;gap:6px;font-size:11.5px;color:var(--texto-suave);">
                <input type="checkbox" data-campo="ligar_solicitud" checked>
                Confirmar recepción del pedido CEDIS (solicitud #${medicamentoSeleccionado.idSolicitud}, enviada el ${escapeHtml(medicamentoSeleccionado.solicitudFecha)})
            </label>` : '';
        return `
            <input type="number" min="1" placeholder="Carril destino" data-campo="numero_carril" required>
            <input type="number" min="1" placeholder="Cantidad a agregar" data-campo="cantidad" required>
            <input type="text" placeholder="Número de lote" data-campo="numero_lote" required>
            <input type="date" data-campo="fecha_caducidad" required>
            ${solicitudLigable}
            <div class="fila-completa" style="display:flex;align-items:center;gap:10px;">
                <button type="button" class="btn-confirmar" data-accion="confirmar-traspaso">Confirmar traspaso</button>
                <span class="form-mensaje"></span>
            </div>`;
    }

    function formularioPedido() {
        return `
            <div class="fila-completa" style="font-size:12px;color:var(--texto-suave);">
                Carril ${medicamentoSeleccionado ? medicamentoSeleccionado.numeroCarril : '-'} ·
                actualmente ${medicamentoSeleccionado ? medicamentoSeleccionado.cantidadActual : '-'} pieza(s).
            </div>
            <input type="number" min="0" placeholder="Cantidad a pedir" data-campo="cantidad" value="${medicamentoSeleccionado ? medicamentoSeleccionado.cantidadActual : ''}" required>
            <div class="fila-completa" style="display:flex;align-items:center;gap:10px;">
                <button type="button" class="btn-confirmar" data-accion="confirmar-pedido">Crear solicitud de pedido</button>
                <span class="form-mensaje"></span>
            </div>`;
    }

    function alternarFormAccion(btn) {
        const tarjeta = btn.closest('.item-cercana');
        const form = tarjeta.querySelector('.form-accion');
        const tipo = btn.dataset.tipo;
        const yaAbierto = form.classList.contains('abierto') && form.dataset.tipoActivo === tipo;

        tarjeta.querySelectorAll('.btn-accion').forEach(b => b.classList.remove('activo'));
        form.classList.remove('abierto');
        form.innerHTML = '';

        if (yaAbierto) {
            return;
        }

        form.dataset.tipoActivo = tipo;
        form.innerHTML = tipo === 'traspaso' ? formularioTraspaso() : formularioPedido();
        form.classList.add('abierto');
        btn.classList.add('activo');

        const idSucursal = btn.dataset.idSucursal;
        const confirmar = form.querySelector('.btn-confirmar');
        confirmar.addEventListener('click', () => {
            if (tipo === 'traspaso') {
                enviarTraspaso(form, idSucursal);
            } else {
                enviarPedido(form, idSucursal);
            }
        });
    }

    async function enviarTraspaso(form, idSucursal) {
        const mensaje = form.querySelector('.form-mensaje');
        const numeroCarril = parseInt(form.querySelector('[data-campo="numero_carril"]').value, 10);
        const cantidadAgregar = parseInt(form.querySelector('[data-campo="cantidad"]').value, 10);
        const numeroLote = form.querySelector('[data-campo="numero_lote"]').value.trim();
        const fechaCaducidad = form.querySelector('[data-campo="fecha_caducidad"]').value;

        if (!numeroCarril || !cantidadAgregar || cantidadAgregar < 1 || !numeroLote || !fechaCaducidad) {
            mensaje.className = 'form-mensaje error';
            mensaje.textContent = 'Completa carril, cantidad, lote y caducidad.';
            return;
        }

        const btn = form.querySelector('.btn-confirmar');
        btn.disabled = true;
        mensaje.className = 'form-mensaje';
        mensaje.textContent = 'Consultando carril…';

        try {
            // Necesitamos la cantidad ACTUAL real del carril destino (puede
            // no ser el mismo carril que el medicamento bajo de stock -- el
            // usuario captura el carril a mano) para calcular cantidad_nueva.
            const detalle = await obtenerJSON(`api/detalle_maquina.php?id_maquina=${seleccionActual.id}`);
            const carril = (detalle.data || []).find(c => Number(c.numero_carril) === numeroCarril);
            if (!carril) {
                mensaje.className = 'form-mensaje error';
                mensaje.textContent = `El carril ${numeroCarril} no existe en esta máquina.`;
                btn.disabled = false;
                return;
            }
            const cantidadActual = Number(carril.cantidad_actual) || 0;

            mensaje.textContent = 'Guardando…';
            const payload = {
                id_maquina: seleccionActual.id,
                numero_carril: numeroCarril,
                cantidad_actual: cantidadActual,
                cantidad_nueva: cantidadActual + cantidadAgregar,
                codigo_barras: medicamentoSeleccionado ? medicamentoSeleccionado.codigo : '',
                numero_lote: numeroLote,
                fecha_caducidad: fechaCaducidad,
                id_sucursal_origen: idSucursal,
            };

            // Solo se liga la solicitud si el carril destino capturado es
            // el MISMO carril que la originó -- si el admin decide traspasar
            // a otro carril distinto, ligarla ahí sería incorrecto (la
            // solicitud/pedido real de CEDIS es para el carril original).
            const chkLigar = form.querySelector('[data-campo="ligar_solicitud"]');
            if (medicamentoSeleccionado && medicamentoSeleccionado.idSolicitud
                && numeroCarril === medicamentoSeleccionado.numeroCarril
                && (!chkLigar || chkLigar.checked)) {
                payload.id_solicitud = medicamentoSeleccionado.idSolicitud;
            }

            const res = await fetch('api/resurtir_vendingm.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', ...csrfHeader() },
                body: JSON.stringify(payload),
            });
            const json = await res.json();
            if (!json.ok) {
                mensaje.className = 'form-mensaje error';
                mensaje.textContent = json.error || 'No se pudo registrar el traspaso.';
                btn.disabled = false;
                return;
            }
            mensaje.className = 'form-mensaje ok';
            mensaje.textContent = 'Traspaso registrado.';
            setTimeout(() => { cargarCarrilesVendingm(); cargarBajoStock(); }, 900);
        } catch (e) {
            mensaje.className = 'form-mensaje error';
            mensaje.textContent = 'No se pudo conectar con el servidor.';
            btn.disabled = false;
        }
    }

    async function enviarPedido(form) {
        const mensaje = form.querySelector('.form-mensaje');
        const cantidad = parseInt(form.querySelector('[data-campo="cantidad"]').value, 10);

        if (!medicamentoSeleccionado || isNaN(cantidad) || cantidad < 0) {
            mensaje.className = 'form-mensaje error';
            mensaje.textContent = 'Captura una cantidad válida.';
            return;
        }

        const btn = form.querySelector('.btn-confirmar');
        btn.disabled = true;
        mensaje.className = 'form-mensaje';
        mensaje.textContent = 'Guardando…';

        try {
            const res = await fetch('api/solicitud_pedido_manual.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', ...csrfHeader() },
                body: JSON.stringify({
                    id_maquina: seleccionActual.id,
                    numero_carril: medicamentoSeleccionado.numeroCarril,
                    cantidad,
                }),
            });
            const json = await res.json();
            if (!json.ok) {
                mensaje.className = 'form-mensaje error';
                mensaje.textContent = json.error || 'No se pudo crear la solicitud.';
                btn.disabled = false;
                return;
            }
            const d = json.data || {};
            if (d.enviada_cedis) {
                mensaje.className = 'form-mensaje ok';
                mensaje.textContent = `Enviado a CEDIS -- folio ${d.folio}.`;
            } else {
                mensaje.className = 'form-mensaje';
                const base = d.actualizada
                    ? 'Ya había una solicitud pendiente para este carril -- se actualizó la cantidad.'
                    : 'Solicitud de pedido creada.';
                mensaje.textContent = base + (json.meta && json.meta.aviso ? ' ' + json.meta.aviso : ' No se pudo enviar a CEDIS todavía -- inténtalo desde Pedidos.');
            }
        } catch (e) {
            mensaje.className = 'form-mensaje error';
            mensaje.textContent = 'No se pudo conectar con el servidor.';
            btn.disabled = false;
        }
    }

    function reiniciarCercanas() {
        medicamentoSeleccionado = null;
        document.getElementById('cercanas-titulo').textContent = 'Disponibilidad cercana';
        document.getElementById('cercanas-mensaje').textContent = '';
        const cont = document.getElementById('cercanas-contenido');
        cont.className = 'vacio';
        cont.textContent = 'Selecciona un medicamento de la izquierda para ver dónde hay existencia cercana.';
    }

    async function mostrarCercanas(btn) {
        const { codigo: codigoBarras, nombre: nombreProducto, carril, cantidad, idSolicitud, solicitudFecha } = btn.dataset;
        medicamentoSeleccionado = {
            codigo: codigoBarras,
            nombre: nombreProducto,
            numeroCarril: parseInt(carril, 10),
            cantidadActual: parseInt(cantidad, 10) || 0,
            // Solicitud ya enviada a CEDIS para este mismo carril (si la
            // hay) -- se ofrece ligarla al confirmar el traspaso, ver
            // formularioTraspaso()/enviarTraspaso().
            idSolicitud: idSolicitud ? Number(idSolicitud) : null,
            solicitudFecha: solicitudFecha || '',
        };
        const cont = document.getElementById('cercanas-contenido');
        const mensaje = document.getElementById('cercanas-mensaje');
        document.getElementById('cercanas-titulo').textContent = `Disponibilidad cercana — ${nombreProducto}`;
        mensaje.textContent = '';
        cont.className = 'cargando';
        cont.textContent = 'Buscando…';

        document.querySelectorAll('#lista-bajo-stock tr').forEach(tr => tr.classList.remove('fila-seleccionada'));
        btn.closest('tr')?.classList.add('fila-seleccionada');

        if (!codigoBarras) {
            cont.className = '';
            cont.innerHTML = '<p class="vacio">Este medicamento no tiene código de barras registrado.</p>';
            return;
        }

        try {
            const json = await obtenerJSON(`api/disponibilidad_cercana.php?accion=cercanas&id_maquina=${seleccionActual.id}&codigo_barras=${encodeURIComponent(codigoBarras)}`);
            const filas = json.data || [];
            if (json.meta && json.meta.aviso) {
                mensaje.textContent = json.meta.aviso;
            }
            if (filas.length === 0) {
                cont.className = '';
                cont.innerHTML = '<p class="vacio">No se encontraron farmacias ni CEDIS cercanos con existencia disponible.</p>';
                return;
            }
            cont.className = 'lista-cercanas';
            cont.innerHTML = filas.map(itemCercana).join('');
            cont.querySelectorAll('.btn-accion').forEach(b => {
                b.addEventListener('click', () => alternarFormAccion(b));
            });
        } catch (e) {
            cont.className = '';
            cont.innerHTML = `<p class="vacio">${escapeHtml(e.message) || 'No se pudo consultar la disponibilidad cercana.'}</p>`;
        }
    }

    document.getElementById('bajo-stock-umbral').addEventListener('change', cargarBajoStock);

    // ---------------- Pedido de todos los faltantes (un clic, un solo pedido real) ----------------
    document.getElementById('btn-pedido-masivo').addEventListener('click', async () => {
        const msg = document.getElementById('masivo-mensaje');
        if (!seleccionActual || seleccionActual.tipo !== 'vendingm') return;
        if (bajoStockCache.length === 0) {
            msg.className = 'fila-mensaje error';
            msg.textContent = 'No hay medicamentos faltantes para pedir.';
            return;
        }
        const btn = document.getElementById('btn-pedido-masivo');
        btn.disabled = true;
        msg.className = 'fila-mensaje';
        msg.textContent = 'Generando pedido…';
        try {
            const umbral = document.getElementById('bajo-stock-umbral').value || '50';
            const res = await fetch('api/pedido_masivo_maquina.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', ...csrfHeader() },
                body: JSON.stringify({ id_maquina: seleccionActual.id, umbral_porcentaje: Number(umbral) }),
            });
            const json = await res.json();
            if (!json.ok) {
                msg.className = 'fila-mensaje error';
                msg.textContent = json.error || 'No se pudo generar el pedido.';
                return;
            }
            const omitidas = (json.meta && json.meta.omitidas) || [];
            msg.className = 'fila-mensaje ok';
            msg.textContent = `Pedido creado -- folio ${json.data.folio} (${json.data.total_lineas} producto(s))`
                + (omitidas.length ? `. ${omitidas.length} se omitieron: ${omitidas.join('; ')}` : '.');
            await cargarBajoStock();
        } catch (e) {
            msg.className = 'fila-mensaje error';
            msg.textContent = 'No se pudo conectar con el servidor.';
        } finally {
            btn.disabled = false;
        }
    });

    // ---------------- Traspaso de todos los faltantes (una sucursal de origen, un mini-formulario por producto) ----------------
    document.getElementById('btn-traspaso-masivo').addEventListener('click', () => {
        const msg = document.getElementById('masivo-mensaje');
        if (!seleccionActual || seleccionActual.tipo !== 'vendingm') return;
        if (bajoStockCache.length === 0) {
            msg.className = 'fila-mensaje error';
            msg.textContent = 'No hay medicamentos faltantes para traspasar.';
            return;
        }
        msg.textContent = '';
        mostrarTraspasoMasivo();
    });

    function mostrarTraspasoMasivo() {
        medicamentoSeleccionado = null;
        document.getElementById('cercanas-titulo').textContent = 'Traspaso masivo de faltantes';
        document.getElementById('cercanas-mensaje').textContent = 'Busca la sucursal de origen y captura lote/caducidad de cada producto -- sigue siendo obligatorio capturarlos a mano, del empaque físico.';
        const cont = document.getElementById('cercanas-contenido');
        cont.className = '';
        cont.innerHTML = `
            <div style="padding:14px 16px;">
                <input type="text" id="tm-buscar-sucursal" placeholder="Buscar sucursal de origen…" style="width:100%;box-sizing:border-box;padding:9px 11px;border:1px solid var(--linea);border-radius:8px;font:inherit;font-size:13px;background:var(--bg);color:var(--texto);margin-bottom:8px;">
                <select id="tm-sucursal" style="width:100%;box-sizing:border-box;padding:9px 11px;border:1px solid var(--linea);border-radius:8px;font:inherit;font-size:13px;background:var(--bg);color:var(--texto);margin-bottom:12px;"><option value="">Escribe para buscar…</option></select>
                <div id="tm-lineas" style="display:grid;gap:10px;"></div>
                <div style="display:flex;align-items:center;gap:10px;margin-top:12px;">
                    <button type="button" id="tm-guardar-todo" style="border:none;background:var(--petroleo);color:#06201F;font-family:inherit;font-weight:700;font-size:12.5px;padding:8px 12px;border-radius:7px;cursor:pointer;">Guardar todo</button>
                    <span class="form-mensaje" id="tm-mensaje"></span>
                </div>
            </div>`;

        document.getElementById('tm-lineas').innerHTML = bajoStockCache.map(f => `
            <div class="linea-tm" data-carril="${f.numero_carril}" data-codigo="${escapeHtml(f.codigo_barras)}" style="border:1px solid var(--linea-suave);border-radius:8px;padding:10px 12px;">
                <div style="font-size:12.5px;margin-bottom:6px;"><b>${escapeHtml(f.nombre) || 'Producto sin nombre'}</b> · Carril ${f.numero_carril} · faltan ${f.cantidad_faltante}</div>
                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                    <input type="number" min="1" class="tm-cantidad" value="${f.cantidad_faltante}" placeholder="Cantidad" style="width:90px;padding:7px 9px;border:1px solid var(--linea);border-radius:7px;font-size:12.5px;background:var(--bg);color:var(--texto);">
                    <input type="text" class="tm-lote" placeholder="Número de lote" style="width:120px;padding:7px 9px;border:1px solid var(--linea);border-radius:7px;font-size:12.5px;background:var(--bg);color:var(--texto);">
                    <input type="date" class="tm-caducidad" style="padding:7px 9px;border:1px solid var(--linea);border-radius:7px;font-size:12.5px;background:var(--bg);color:var(--texto);">
                </div>
                <div class="tm-resultado" style="font-size:11.5px;margin-top:4px;"></div>
            </div>`).join('');

        let debounceTm = null;
        document.getElementById('tm-buscar-sucursal').addEventListener('input', (ev) => {
            clearTimeout(debounceTm);
            const q = ev.target.value.trim();
            debounceTm = setTimeout(async () => {
                const select = document.getElementById('tm-sucursal');
                if (q.length < 2) { select.innerHTML = '<option value="">Escribe para buscar…</option>'; return; }
                try {
                    const json = await obtenerJSON(`api/sucursales_clinica.php?q=${encodeURIComponent(q)}`);
                    const sucursales = json.data || [];
                    select.innerHTML = '<option value="">Selecciona…</option>' + sucursales.map(s =>
                        `<option value="${s.id_SucursalClinica}">${escapeHtml(s.st_Nombre)}</option>`
                    ).join('');
                } catch (e) {
                    select.innerHTML = '<option value="">No se pudo buscar</option>';
                }
            }, 300);
        });

        document.getElementById('tm-guardar-todo').addEventListener('click', () => guardarTraspasoMasivo());
    }

    async function guardarTraspasoMasivo() {
        const idSucursal = document.getElementById('tm-sucursal').value;
        const msg = document.getElementById('tm-mensaje');
        if (!idSucursal) {
            msg.className = 'form-mensaje error';
            msg.textContent = 'Selecciona la sucursal de origen.';
            return;
        }
        const btn = document.getElementById('tm-guardar-todo');
        btn.disabled = true;
        msg.className = 'form-mensaje';
        msg.textContent = 'Guardando…';

        let huboExito = false;
        let huboError = false;

        for (const div of document.querySelectorAll('.linea-tm')) {
            const numeroCarril = parseInt(div.dataset.carril, 10);
            const cantidad = parseInt(div.querySelector('.tm-cantidad').value, 10);
            const numeroLote = div.querySelector('.tm-lote').value.trim();
            const fechaCaducidad = div.querySelector('.tm-caducidad').value;
            const resultadoEl = div.querySelector('.tm-resultado');

            if (!cantidad || cantidad < 1 || !numeroLote || !fechaCaducidad) {
                resultadoEl.innerHTML = '<span style="color:var(--critico)">Falta cantidad, lote o caducidad -- se omitió.</span>';
                huboError = true;
                continue;
            }

            try {
                // Cantidad actual real del carril, releída justo antes de
                // cada guardado por si otra línea de este mismo lote ya
                // afectó el mismo carril (no debería, pero es más seguro
                // que asumir el valor cacheado).
                const detalle = await obtenerJSON(`api/detalle_maquina.php?id_maquina=${seleccionActual.id}`);
                const carril = (detalle.data || []).find(c => Number(c.numero_carril) === numeroCarril);
                const cantidadActual = carril ? (Number(carril.cantidad_actual) || 0) : 0;

                const original = bajoStockCache.find(f => Number(f.numero_carril) === numeroCarril);
                const payload = {
                    id_maquina: seleccionActual.id,
                    numero_carril: numeroCarril,
                    cantidad_actual: cantidadActual,
                    cantidad_nueva: cantidadActual + cantidad,
                    codigo_barras: div.dataset.codigo,
                    numero_lote: numeroLote,
                    fecha_caducidad: fechaCaducidad,
                    id_sucursal_origen: idSucursal,
                };
                if (original && original.id_solicitud) {
                    payload.id_solicitud = original.id_solicitud;
                }

                const res = await fetch('api/resurtir_vendingm.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', ...csrfHeader() },
                    body: JSON.stringify(payload),
                });
                const json = await res.json();
                if (!json.ok) {
                    resultadoEl.innerHTML = `<span style="color:var(--critico)">${escapeHtml(json.error || 'No se pudo registrar.')}</span>`;
                    huboError = true;
                } else {
                    resultadoEl.innerHTML = '<span style="color:var(--petroleo)">Traspaso registrado.</span>';
                    huboExito = true;
                }
            } catch (e) {
                resultadoEl.innerHTML = '<span style="color:var(--critico)">No se pudo conectar con el servidor.</span>';
                huboError = true;
            }
        }

        msg.className = huboError ? 'form-mensaje error' : 'form-mensaje ok';
        msg.textContent = huboError
            ? (huboExito ? 'Algunos se guardaron, otros necesitan revisión.' : 'No se pudo guardar ninguno.')
            : 'Todos los traspasos se registraron.';
        btn.disabled = false;

        if (huboExito) {
            setTimeout(() => { cargarCarrilesVendingm(); cargarBajoStock(); }, 900);
        }
    }

    // ---------------- Selector combinado (Intecco + vendingm) ----------------
    function cargarCarriles() {
        const cont = document.getElementById('lista-carriles');
        if (!seleccionActual) {
            cont.className = 'vacio';
            cont.textContent = 'Selecciona una sucursal o máquina para ver sus carriles.';
            document.getElementById('panel-dos-columnas').style.display = 'none';
            return;
        }
        cargarBajoStock();
        return seleccionActual.tipo === 'vendingm' ? cargarCarrilesVendingm() : cargarCarrilesIntecco();
    }

    function poblarSelector(lista) {
        const select = document.getElementById('selector-sucursal');
        select.innerHTML = '<option value="">Selecciona…</option>' + lista.map(item =>
            `<option value="${item.tipo}:${item.id}">${item.tipo === 'vendingm' ? '🖥 ' : ''}${escapeHtml(item.nombre)}</option>`
        ).join('');
    }

    async function cargarSelector() {
        const select = document.getElementById('selector-sucursal');
        try {
            const [intecco, vendingm] = await Promise.all([
                obtenerJSON('api/resurtir_carril.php?accion=sucursales'),
                obtenerJSON('api/estadisticas.php?accion=maquinas'),
            ]);
            const itemsIntecco = (intecco.data || []).map(s => ({ tipo: 'intecco', id: s.id_SucursalClinica, nombre: s.st_Nombre }));
            const itemsVendingm = (vendingm.data || []).map(m => ({
                tipo: 'vendingm',
                id: m.id_maquina,
                nombre: `${m.numero_serie}${m.nombre_ubicacion ? ' · ' + m.nombre_ubicacion : ''}`,
            }));
            listaCompleta = [...itemsIntecco, ...itemsVendingm].sort((a, b) => a.nombre.localeCompare(b.nombre));
            poblarSelector(listaCompleta);
        } catch (e) {
            select.insertAdjacentHTML('afterend', '<p class="vacio">No se pudieron cargar las sucursales/máquinas.</p>');
        }
    }

    // ---------------- Selector por región (solo vendingm -- Intecco fuera de
    // este flujo, decisión del usuario 2026-09-04) ----------------
    // Sustituye al buscador plano como punto de entrada principal. El
    // buscador de siempre (con Intecco incluido) se conserva como respaldo
    // detrás de "Buscar directo / Intecco", por si alguna vending todavía
    // no tiene región resuelta o alguien necesita gestionar Intecco.
    let regionActual = '';

    function etiquetaRegion(r) {
        if (r.region === null) return 'Sin región';
        return r.region;
    }

    function etiquetaRegionActual() {
        if (!regionActual) return '';
        return regionActual === '__sin_region__' ? 'Sin región' : regionActual;
    }

    // Fila propia debajo del selector de región: "Región » NúmeroSerie
    // (Ubicación)" -- refleja la selección vigente en todo momento (región
    // sola, o región + vending), con el mismo estilo "activo" que la
    // tarjeta de la vending seleccionada en el listado.
    function actualizarBreadcrumb() {
        const tarjeta = document.getElementById('tarjeta-seleccion-activa');
        const el = document.getElementById('breadcrumb-seleccion');
        const region = etiquetaRegionActual();
        if (!region) {
            tarjeta.style.display = 'none';
            return;
        }
        tarjeta.style.display = '';
        if (seleccionActual && seleccionActual.tipo === 'vendingm' && seleccionActual.numeroSerie) {
            el.textContent = `${region} » ${seleccionActual.numeroSerie} (${seleccionActual.nombreUbicacion || 'Sin ubicación'})`;
        } else {
            el.textContent = `${region} » selecciona una Máquina dispensadora`;
        }
    }

    async function cargarRegiones() {
        const select = document.getElementById('select-region');
        try {
            const json = await obtenerJSON('api/vendings_region.php?accion=regiones');
            const regiones = json.data || [];
            if (regiones.length === 0) {
                select.innerHTML = '<option value="">Sin Máquinas dispensadoras registradas</option>';
                return;
            }
            select.innerHTML = '<option value="">Selecciona una región…</option>' + regiones.map(r =>
                `<option value="${escapeHtml(r.region ?? '__sin_region__')}">${escapeHtml(etiquetaRegion(r))} (${r.total_maquinas} ${r.total_maquinas === 1 ? 'Máquina dispensadora' : 'Máquinas dispensadoras'}${r.total_carriles_en_alerta > 0 ? `, ${r.total_carriles_en_alerta} carril(es) en alerta` : ''})</option>`
            ).join('');
        } catch (e) {
            select.innerHTML = '<option value="">No se pudieron cargar las regiones</option>';
        }
    }

    function itemVending(v) {
        const enAlerta = v.carriles_en_alerta > 0;
        return `<div class="item-vending${seleccionActual && seleccionActual.tipo === 'vendingm' && String(seleccionActual.id) === String(v.id_maquina) ? ' seleccionada' : ''}" data-id-maquina="${v.id_maquina}">
            <div>
                <div class="nombre">${escapeHtml(v.numero_serie)}</div>
                <div class="ubicacion">${escapeHtml(v.nombre_ubicacion) || 'Sin ubicación'}</div>
            </div>
            <div class="alerta${enAlerta ? '' : ' sin-alerta'}">${enAlerta ? `${v.carriles_en_alerta} en alerta` : 'Sin alertas'}</div>
        </div>`;
    }

    async function cargarListadoVendings(region) {
        const cont = document.getElementById('listado-vendings');
        if (!region) {
            cont.className = 'vacio';
            cont.textContent = 'Selecciona una región para ver sus Máquinas dispensadoras.';
            return;
        }
        cont.className = 'cargando';
        cont.textContent = 'Cargando…';
        try {
            const json = await obtenerJSON(`api/vendings_region.php?accion=listado&region=${encodeURIComponent(region)}`);
            const vendings = json.data || [];
            if (vendings.length === 0) {
                cont.className = '';
                cont.innerHTML = '<p class="vacio">No hay Máquinas dispensadoras en esta región.</p>';
                return;
            }
            cont.className = 'lista-vendings';
            cont.innerHTML = vendings.map(itemVending).join('');
            cont.querySelectorAll('.item-vending').forEach(el => {
                el.addEventListener('click', () => {
                    const v = vendings.find(x => String(x.id_maquina) === el.dataset.idMaquina);
                    seleccionActual = {
                        tipo: 'vendingm',
                        id: Number(el.dataset.idMaquina),
                        numeroSerie: v ? v.numero_serie : '',
                        nombreUbicacion: v ? v.nombre_ubicacion : '',
                    };
                    cont.querySelectorAll('.item-vending').forEach(x => x.classList.remove('seleccionada'));
                    el.classList.add('seleccionada');
                    actualizarBreadcrumb();
                    cargarCarriles();
                });
            });
        } catch (e) {
            cont.className = '';
            cont.innerHTML = `<p class="vacio">${escapeHtml(e.message) || 'No se pudo cargar el listado.'}</p>`;
        }
    }

    document.getElementById('select-region').addEventListener('change', (ev) => {
        regionActual = ev.target.value;
        // Cambiar de región invalida la vending seleccionada (puede no
        // pertenecer a la región nueva) -- se limpia junto con todo lo que
        // depende de ella (carriles, breadcrumb, stock bajo/cercanas).
        seleccionActual = null;
        actualizarBreadcrumb();
        cargarListadoVendings(regionActual);
        cargarCarriles();
    });

    document.getElementById('btn-buscar-directo').addEventListener('click', () => {
        document.getElementById('tarjeta-region').style.display = 'none';
        document.getElementById('tarjeta-seleccion-activa').style.display = 'none';
        document.getElementById('panel-listado-carriles').style.display = 'none';
        document.getElementById('tarjeta-directo').style.display = '';
        if (listaCompleta.length === 0) cargarSelector();
    });

    document.getElementById('btn-cerrar-directo').addEventListener('click', () => {
        document.getElementById('tarjeta-directo').style.display = 'none';
        document.getElementById('tarjeta-region').style.display = '';
        document.getElementById('panel-listado-carriles').style.display = '';
        actualizarBreadcrumb();
    });

    document.getElementById('buscador-selector').addEventListener('input', (ev) => {
        const q = ev.target.value.trim().toLowerCase();
        poblarSelector(q ? listaCompleta.filter(item => item.nombre.toLowerCase().includes(q)) : listaCompleta);
    });

    document.getElementById('selector-sucursal').addEventListener('change', (ev) => {
        const [tipo, id] = (ev.target.value || '').split(':');
        seleccionActual = tipo ? { tipo, id } : null;
        cargarCarriles();
    });

    cargarRegiones();
</script>
</body>
</html>
