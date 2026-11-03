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
    <title>MDM - Productos</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/estilo.css?v=20260914-2">
    <style>
        .encabezado-pagina { display: flex; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
        .btn {
            border: 1px solid var(--linea); background: var(--bg-elevada); color: var(--texto);
            border-radius: 8px; padding: 9px 14px; font: inherit; font-size: 13px; font-weight: 700; cursor: pointer;
        }
        .btn:hover { border-color: var(--petroleo); color: var(--petroleo); }
        .btn.primario { background: var(--petroleo); border-color: var(--petroleo); color: #fff; }
        .toolbar { display: flex; gap: 8px; padding: 14px 18px; border-bottom: 1px solid var(--linea-suave); flex-wrap: wrap; }
        .toolbar input { flex: 1; min-width: 240px; padding: 9px 11px; border: 1px solid var(--linea); border-radius: 8px; font: inherit; }
        .table-shell { overflow-x: auto; }
        .table-shell table { min-width: 860px; }
        .producto-img {
            width: 46px; height: 46px; border-radius: 8px; object-fit: cover; border: 1px solid var(--linea);
            background: var(--bg-hover); display: block;
        }
        .producto-img.vacia { display: flex; align-items: center; justify-content: center; color: var(--texto-tenue); font-weight: 800; }
        .acciones-tabla { display: flex; gap: 8px; justify-content: flex-end; }
        .overlay-modal {
            position: fixed; inset: 0; display: none; align-items: flex-start; justify-content: center; overflow-y: auto;
            padding: 34px 16px; background: rgba(20,33,43,.24); backdrop-filter: blur(2px); z-index: 60;
        }
        .overlay-modal.abierto { display: flex; }
        .modal { width: min(720px, 100%); background: var(--bg-elevada); border: 1px solid var(--linea); border-radius: 10px; box-shadow: 0 24px 70px rgba(20,33,43,.22); }
        .modal-head { display: flex; justify-content: space-between; gap: 12px; padding: 16px 18px; border-bottom: 1px solid var(--linea-suave); }
        .modal-head h2 { margin: 0; font-size: 15px; }
        .modal-close { border: 1px solid var(--linea); background: var(--bg); color: var(--texto); border-radius: 8px; width: 32px; height: 32px; cursor: pointer; }
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; padding: 18px; }
        .form-grid .full { grid-column: 1 / -1; }
        .form-grid label { display: block; color: var(--texto-suave); font-size: 12px; font-weight: 700; margin-bottom: 6px; }
        .form-grid input, .form-grid textarea {
            width: 100%; box-sizing: border-box; border: 1px solid var(--linea); border-radius: 8px;
            background: var(--bg); color: var(--texto); font: inherit; font-size: 13px; padding: 9px 11px;
        }
        .form-grid textarea { resize: vertical; min-height: 82px; }
        .check-row { display: flex; align-items: center; gap: 8px; color: var(--texto-suave); font-weight: 700; }
        .check-row input { width: auto; }
        .form-actions { grid-column: 1 / -1; display: flex; justify-content: flex-end; gap: 8px; align-items: center; }
        .mensaje { color: var(--texto-tenue); font-size: 12px; margin-right: auto; }
        .mensaje.error { color: var(--critico); }
        .mensaje.ok { color: var(--petroleo); }
        @media (max-width: 760px) { .form-grid { grid-template-columns: 1fr; } }
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
                <h1>Catalogo de productos</h1>
                <p>Administra medicamentos, precios e imagenes usados por el carrito y la app Android.</p>
            </div>
            <div style="display:flex; gap:8px;">
                <button class="btn" id="btn-cargar-excel">Cargar Excel</button>
                <button class="btn primario" id="btn-nuevo">Nuevo producto</button>
            </div>
        </div>

        <section class="card">
            <div class="card-encabezado"><h2>Productos</h2></div>
            <div class="toolbar">
                <input id="buscar" placeholder="Buscar por nombre, codigo o presentacion">
                <button class="btn" id="btn-actualizar">Actualizar</button>
            </div>
            <div id="tabla-productos" class="cargando">Cargando...</div>
        </section>
    </main>
</div>

<div class="overlay-modal" id="overlay-producto">
    <section class="modal">
        <div class="modal-head">
            <h2 id="modal-titulo">Producto</h2>
            <button class="modal-close" type="button" id="cerrar-modal">x</button>
        </div>
        <form class="form-grid" id="form-producto">
            <input type="hidden" id="p-id_producto">
            <div><label>Codigo de barras</label><input id="p-codigo_barras" required></div>
            <div><label>Clave</label><input id="p-clave" placeholder="010.000.5165.00"></div>
            <div><label>Precio</label><input id="p-precio" type="number" min="0" step="0.01"></div>
            <div class="full"><label>Nombre</label><input id="p-nombre" required></div>
            <div class="full"><label>Presentacion</label><input id="p-presentacion" placeholder="500 mg - caja, frasco, blister..."></div>
            <div class="full"><label>Descripcion</label><textarea id="p-descripcion"></textarea></div>
            <div class="full"><label>Imagen</label><input id="p-imagen" type="file" accept="image/png,image/jpeg,image/webp"></div>
            <label class="check-row"><input id="p-eliminar_imagen" type="checkbox"> Eliminar imagen actual</label>
            <label class="check-row"><input id="p-activo" type="checkbox" checked> Activo</label>
            <div class="form-actions">
                <span class="mensaje" id="mensaje"></span>
                <button class="btn" type="button" id="cancelar">Cancelar</button>
                <button class="btn primario" type="submit">Guardar</button>
            </div>
        </form>
    </section>
</div>

<div class="overlay-modal" id="overlay-excel">
    <section class="modal">
        <div class="modal-head">
            <h2>Cargar Excel de medicamentos</h2>
            <button class="modal-close" type="button" id="cerrar-modal-excel">x</button>
        </div>
        <form class="form-grid" id="form-excel">
            <div class="full">
                <p class="muted" style="margin-top:0;">
                    Columnas reconocidas (solo hace falta <b>Clave</b> y <b>Nombre</b> o <b>Descripcion</b>, el resto es opcional):
                    <b>Clave, Codigo de Barras, Nombre/Descripcion, Presentacion, Precio, Sustancia Activa</b>.
                    Si la Clave ya existe en el catalogo, ese producto se actualiza (sin borrar datos que no vengan en esta fila); si no existe, se crea uno nuevo.
                    Puedes usar el mismo Excel que subes en "Cargar stock" -- aqui solo se toca el catalogo, sin tocar carriles ni cantidades.
                </p>
            </div>
            <div class="full"><label>Archivo (.xlsx / .xls)</label><input id="excel-archivo" type="file" accept=".xlsx,.xls" required></div>
            <div class="form-actions">
                <span class="mensaje" id="mensaje-excel"></span>
                <button class="btn" type="button" id="cancelar-excel">Cancelar</button>
                <button class="btn primario" type="submit" id="btn-subir-excel">Cargar</button>
            </div>
        </form>
        <div id="resultado-excel" style="padding: 0 18px 18px; display:none;"></div>
    </section>
</div>

<script src="assets/js/comun.js?v=20260912-1"></script>
<script>
    let productos = [];
    let debounce = null;
    const overlay = document.getElementById('overlay-producto');
    const mensaje = document.getElementById('mensaje');

    function fmtPrecio(n) {
        if (n === null || n === undefined || n === '') return '-';
        return Number(n).toLocaleString('es-MX', { style: 'currency', currency: 'MXN' });
    }

    async function cargarProductos() {
        const cont = document.getElementById('tabla-productos');
        cont.className = 'cargando';
        cont.textContent = 'Cargando...';
        const params = new URLSearchParams();
        const q = document.getElementById('buscar').value.trim();
        if (q) params.set('q', q);
        const json = await obtenerJSON(`api/productos.php?${params}`);
        productos = json.data;
        cont.className = '';
        if (!productos.length) {
            cont.innerHTML = '<p class="vacio">Sin productos para este filtro.</p>';
            return;
        }
        cont.innerHTML = `<div class="table-shell"><table>
            <thead><tr><th>Imagen</th><th>Producto</th><th>Codigo</th><th>Clave</th><th>Precio</th><th>Estado</th><th></th></tr></thead>
            <tbody>${productos.map(p => `<tr>
                <td>${p.imagen_url ? `<img class="producto-img" src="${escapeHtml(p.imagen_url)}" alt="">` : '<span class="producto-img vacia">+</span>'}</td>
                <td><b>${escapeHtml(p.nombre)}</b><br><span class="muted">${escapeHtml(p.presentacion) || escapeHtml(p.descripcion) || 'Sin descripcion'}</span></td>
                <td class="mono">${escapeHtml(p.codigo_barras)}</td>
                <td class="mono">${p.clave ? escapeHtml(p.clave) : '<span class="muted">-</span>'}</td>
                <td class="mono">${fmtPrecio(p.precio)}</td>
                <td>${p.activo ? '<span class="badge badge-ok">Activo</span>' : '<span class="badge badge-neutro">Inactivo</span>'}</td>
                <td><div class="acciones-tabla"><button class="btn" data-id="${p.id_producto}" type="button">Editar</button></div></td>
            </tr>`).join('')}</tbody>
        </table></div>`;
        cont.querySelectorAll('button[data-id]').forEach(btn => btn.addEventListener('click', () => abrirEditar(Number(btn.dataset.id))));
    }

    function limpiar() {
        document.getElementById('form-producto').reset();
        document.getElementById('p-id_producto').value = '';
        document.getElementById('p-activo').checked = true;
        document.getElementById('p-eliminar_imagen').checked = false;
        mensaje.textContent = '';
        mensaje.className = 'mensaje';
    }

    function abrirNuevo() {
        limpiar();
        document.getElementById('modal-titulo').textContent = 'Nuevo producto';
        overlay.classList.add('abierto');
    }

    function abrirEditar(id) {
        const p = productos.find(x => Number(x.id_producto) === id);
        if (!p) return;
        limpiar();
        document.getElementById('modal-titulo').textContent = 'Editar producto';
        for (const campo of ['id_producto', 'codigo_barras', 'clave', 'nombre', 'presentacion', 'descripcion', 'precio']) {
            const el = document.getElementById(`p-${campo}`);
            if (el) el.value = p[campo] ?? '';
        }
        document.getElementById('p-activo').checked = !!p.activo;
        overlay.classList.add('abierto');
    }

    function cerrar() { overlay.classList.remove('abierto'); }

    document.getElementById('btn-nuevo').addEventListener('click', abrirNuevo);
    document.getElementById('cerrar-modal').addEventListener('click', cerrar);
    document.getElementById('cancelar').addEventListener('click', cerrar);
    overlay.addEventListener('click', ev => { if (ev.target === overlay) cerrar(); });

    // --- Carga masiva por Excel ---
    const overlayExcel = document.getElementById('overlay-excel');
    const mensajeExcel = document.getElementById('mensaje-excel');
    const resultadoExcel = document.getElementById('resultado-excel');

    function abrirExcel() {
        document.getElementById('form-excel').reset();
        mensajeExcel.textContent = '';
        mensajeExcel.className = 'mensaje';
        resultadoExcel.style.display = 'none';
        resultadoExcel.innerHTML = '';
        overlayExcel.classList.add('abierto');
    }
    function cerrarExcel() { overlayExcel.classList.remove('abierto'); }

    document.getElementById('btn-cargar-excel').addEventListener('click', abrirExcel);
    document.getElementById('cerrar-modal-excel').addEventListener('click', cerrarExcel);
    document.getElementById('cancelar-excel').addEventListener('click', cerrarExcel);
    overlayExcel.addEventListener('click', ev => { if (ev.target === overlayExcel) cerrarExcel(); });

    document.getElementById('form-excel').addEventListener('submit', async ev => {
        ev.preventDefault();
        const archivo = document.getElementById('excel-archivo').files[0];
        if (!archivo) return;

        const btn = document.getElementById('btn-subir-excel');
        btn.disabled = true;
        mensajeExcel.textContent = 'Procesando...';
        mensajeExcel.className = 'mensaje';
        resultadoExcel.style.display = 'none';

        const fd = new FormData();
        fd.append('archivo', archivo);

        try {
            const res = await fetch('api/productos_importar.php', { method: 'POST', body: fd });
            const json = await res.json();
            if (!json.ok) {
                mensajeExcel.textContent = json.error || 'No se pudo procesar el archivo.';
                mensajeExcel.className = 'mensaje error';
                return;
            }
            const d = json.data;
            mensajeExcel.textContent = `Listo: ${d.filas_leidas} filas leidas.`;
            mensajeExcel.className = 'mensaje ok';

            let html = `<p><b>${d.creados}</b> creados, <b>${d.actualizados}</b> actualizados, <b>${d.errores.length}</b> con error.</p>`;
            if (d.errores.length) {
                html += '<div class="table-shell"><table><thead><tr><th>Fila</th><th>Clave</th><th>Motivo</th></tr></thead><tbody>';
                html += d.errores.map(e => `<tr><td>${e.fila}</td><td class="mono">${escapeHtml(e.clave)}</td><td>${escapeHtml(e.motivo)}</td></tr>`).join('');
                html += '</tbody></table></div>';
            }
            resultadoExcel.innerHTML = html;
            resultadoExcel.style.display = '';
            await cargarProductos();
        } catch (e) {
            mensajeExcel.textContent = 'No se pudo conectar con el servidor.';
            mensajeExcel.className = 'mensaje error';
        } finally {
            btn.disabled = false;
        }
    });
    document.getElementById('btn-actualizar').addEventListener('click', cargarProductos);
    document.getElementById('buscar').addEventListener('input', () => {
        clearTimeout(debounce);
        debounce = setTimeout(cargarProductos, 300);
    });

    document.getElementById('form-producto').addEventListener('submit', async ev => {
        ev.preventDefault();
        const fd = new FormData();
        for (const campo of ['id_producto', 'codigo_barras', 'clave', 'nombre', 'presentacion', 'descripcion', 'precio']) {
            fd.append(campo, document.getElementById(`p-${campo}`).value);
        }
        fd.append('activo', document.getElementById('p-activo').checked ? '1' : '0');
        fd.append('eliminar_imagen', document.getElementById('p-eliminar_imagen').checked ? '1' : '0');
        const imagen = document.getElementById('p-imagen').files[0];
        if (imagen) fd.append('imagen', imagen);

        try {
            const res = await fetch('api/productos.php', { method: 'POST', body: fd });
            const json = await res.json();
            if (!json.ok) {
                mensaje.textContent = json.error || 'No se pudo guardar.';
                mensaje.className = 'mensaje error';
                return;
            }
            mensaje.textContent = `Producto ${json.data.accion}.`;
            mensaje.className = 'mensaje ok';
            await cargarProductos();
            setTimeout(cerrar, 600);
        } catch (e) {
            mensaje.textContent = 'No se pudo conectar con el servidor.';
            mensaje.className = 'mensaje error';
        }
    });

    cargarProductos().catch(e => {
        document.getElementById('tabla-productos').className = '';
        document.getElementById('tabla-productos').innerHTML = `<p class="vacio">${escapeHtml(e.message)}</p>`;
    });
</script>
</body>
</html>
