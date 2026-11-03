<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/bootstrap.php';

use App\Auth\Auth;
use App\Config\Features;

Auth::requireLogin();

if (!Features::enabled('carrito_compras')) {
    http_response_code(404);
    echo 'Modulo de carrito desactivado.';
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>MDM - Carrito</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/estilo.css?v=20260914-2">
    <style>
        .layout-carrito { display: grid; grid-template-columns: 1.35fr .75fr; gap: 16px; align-items: start; }
        .toolbar-carrito { display: grid; grid-template-columns: minmax(220px, 320px) 1fr; gap: 10px; padding: 16px; border-bottom: 1px solid var(--linea-suave); }
        .toolbar-carrito input, .toolbar-carrito select { width: 100%; padding: 9px 11px; border: 1px solid var(--linea); border-radius: 8px; font: inherit; }
        .tabla-carrito-wrap { overflow-x: auto; }
        .tabla-carrito-wrap table { min-width: 720px; }
        .acciones-carril { display: flex; gap: 8px; align-items: center; justify-content: flex-end; }
        .acciones-carril input { width: 68px; padding: 7px 8px; border: 1px solid var(--linea); border-radius: 8px; font: inherit; }
        .btn { border: 1px solid var(--linea); background: var(--bg-elevada); color: var(--texto); border-radius: 8px; padding: 8px 12px; font: inherit; font-size: 13px; font-weight: 700; cursor: pointer; }
        .btn:hover { border-color: var(--petroleo); color: var(--petroleo); }
        .btn.primario { background: var(--petroleo); border-color: var(--petroleo); color: #fff; }
        .btn:disabled { opacity: .5; cursor: default; }
        .resumen-carrito { display: flex; flex-direction: column; gap: 10px; padding: 16px; }
        .item-carrito { display: grid; grid-template-columns: 1fr auto auto; gap: 10px; align-items: center; padding: 10px 0; border-bottom: 1px solid var(--linea-suave); }
        .item-carrito:last-child { border-bottom: none; }
        .item-carrito b { display: block; }
        .item-carrito small { color: var(--texto-tenue); }
        .cantidad-carrito { font-family: 'JetBrains Mono', monospace; font-weight: 700; }
        .alerta-operacion { display: none; margin-bottom: 14px; padding: 11px 13px; border-radius: 8px; border: 1px solid var(--linea); background: var(--bg-elevada); color: var(--texto-suave); font-size: 13px; }
        .alerta-operacion.visible { display: block; }
        .alerta-operacion.error { color: var(--critico); background: var(--critico-wash); }
        .alerta-operacion.ok { color: var(--petroleo); background: var(--petroleo-wash); }
        @media (max-width: 980px) {
            .layout-carrito, .toolbar-carrito { grid-template-columns: 1fr; }
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
            <h1>Carrito de compras</h1>
            <p>Selecciona una maquina, agrega productos disponibles por carril y genera la receta cuando el pedido este listo.</p>
        </div>

        <div id="alerta-operacion" class="alerta-operacion"></div>

        <div class="layout-carrito">
            <section class="card">
                <div class="card-encabezado"><h2>Productos disponibles</h2></div>
                <div class="toolbar-carrito">
                    <select id="c-maquina"><option value="">Cargando maquinas...</option></select>
                    <input id="c-buscar" placeholder="Buscar por producto, codigo o lote" disabled>
                </div>
                <div id="c-carriles" class="cargando">Cargando...</div>
            </section>

            <section class="card">
                <div class="card-encabezado"><h2>Resumen</h2></div>
                <div class="resumen-carrito">
                    <div id="c-resumen" class="vacio">Sin productos agregados.</div>
                    <input id="c-paciente" placeholder="Paciente opcional">
                    <input id="c-medico" placeholder="Medico solicitante opcional">
                    <button class="btn primario" id="c-crear-receta" type="button" disabled>Generar receta</button>
                    <button class="btn" id="c-limpiar" type="button">Limpiar carrito</button>
                </div>
            </section>
        </div>
    </main>
</div>

<script src="assets/js/comun.js?v=20260912-1"></script>
<script>
    let maquinasPlanas = [];
    let carriles = [];
    let carrito = { items: [], totales: { items: 0, unidades: 0 } };
    let filtro = '';

    const selectMaquina = document.getElementById('c-maquina');
    const inputBuscar = document.getElementById('c-buscar');
    const contCarriles = document.getElementById('c-carriles');
    const contResumen = document.getElementById('c-resumen');
    const btnCrear = document.getElementById('c-crear-receta');

    function estadoOperacion(mensaje, tipo = '') {
        const alerta = document.getElementById('alerta-operacion');
        alerta.textContent = mensaje || '';
        alerta.className = mensaje ? `alerta-operacion visible ${tipo}` : 'alerta-operacion';
    }

    async function postCarrito(accion, payload = {}) {
        const res = await fetch(`api/carrito.php?accion=${accion}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        const json = await res.json();
        if (!json.ok) throw new Error(json.error || 'No se pudo actualizar el carrito.');
        carrito = json.data;
        renderResumen();
        renderCarriles();
        return json.data;
    }

    async function iniciar() {
        const [maquinas, estado] = await Promise.all([
            obtenerJSON('api/maquinas.php?agrupado=1&page_size=1000'),
            obtenerJSON('api/carrito.php'),
        ]);

        maquinasPlanas = [];
        maquinas.data.forEach(u => {
            maquinasPlanas.push({ id_maquina: u.id_maquina, numero_serie: u.numero_serie, nombre_ubicacion: u.nombre_ubicacion });
            (u.esclavas || []).forEach(e => maquinasPlanas.push({ id_maquina: e.id_maquina, numero_serie: e.numero_serie, nombre_ubicacion: u.nombre_ubicacion }));
        });

        carrito = estado.data;
        selectMaquina.innerHTML = '<option value="">Selecciona una maquina...</option>' + maquinasPlanas.map(m =>
            `<option value="${m.id_maquina}">${escapeHtml(m.numero_serie)}${m.nombre_ubicacion ? ' - ' + escapeHtml(m.nombre_ubicacion) : ''}</option>`
        ).join('');
        if (carrito.id_maquina) {
            selectMaquina.value = String(carrito.id_maquina);
            await cargarCarriles(carrito.id_maquina);
        }
        renderResumen();
    }

    async function cargarCarriles(idMaquina) {
        inputBuscar.disabled = !idMaquina;
        carriles = [];
        if (!idMaquina) {
            contCarriles.innerHTML = '<p class="vacio">Selecciona una maquina para ver productos disponibles.</p>';
            return;
        }
        contCarriles.className = 'cargando';
        contCarriles.textContent = 'Cargando...';
        const json = await obtenerJSON(`api/detalle_maquina.php?id_maquina=${idMaquina}`);
        carriles = json.data.filter(f => f.nombre);
        renderCarriles();
    }

    function renderCarriles() {
        const items = carrito.items || [];
        let filas = carriles;
        if (filtro) {
            filas = filas.filter(f =>
                String(f.nombre || '').toLowerCase().includes(filtro) ||
                String(f.codigo_barras || '').toLowerCase().includes(filtro) ||
                String(f.numero_lote || '').toLowerCase().includes(filtro)
            );
        }
        contCarriles.className = '';
        if (!filas.length) {
            contCarriles.innerHTML = '<p class="vacio">No hay productos disponibles para este filtro.</p>';
            return;
        }
        contCarriles.innerHTML = `<div class="tabla-carrito-wrap"><table>
            <thead><tr><th>Carril</th><th>Producto</th><th>Stock</th><th></th></tr></thead>
            <tbody>${filas.map(f => {
                const enCarrito = items.find(i => Number(i.numero_carril) === Number(f.numero_carril));
                const disponible = Number(f.cantidad_actual || 0) - Number(enCarrito?.cantidad || 0);
                return `<tr>
                    <td class="mono">${f.numero_carril}</td>
                    <td><b>${escapeHtml(f.nombre)}</b><br><span class="muted mono">${escapeHtml(f.codigo_barras) || escapeHtml(f.numero_lote) || '-'}</span></td>
                    <td class="mono">${disponible}</td>
                    <td>
                        <div class="acciones-carril">
                            <input type="number" min="1" max="${Math.max(disponible, 1)}" value="1" id="cant-${f.numero_carril}" ${disponible <= 0 ? 'disabled' : ''}>
                            <button class="btn" data-carril="${f.numero_carril}" ${disponible <= 0 ? 'disabled' : ''}>Agregar</button>
                        </div>
                    </td>
                </tr>`;
            }).join('')}</tbody>
        </table></div>`;
        contCarriles.querySelectorAll('button[data-carril]').forEach(btn => {
            btn.addEventListener('click', async () => {
                const numeroCarril = Number(btn.dataset.carril);
                const cantidad = Number(document.getElementById(`cant-${numeroCarril}`).value || 1);
                try {
                    await postCarrito('agregar', { id_maquina: Number(selectMaquina.value), numero_carril: numeroCarril, cantidad });
                    estadoOperacion('');
                } catch (e) {
                    estadoOperacion(e.message, 'error');
                }
            });
        });
    }

    function renderResumen() {
        const items = carrito.items || [];
        btnCrear.disabled = !items.length;
        if (!items.length) {
            contResumen.className = 'vacio';
            contResumen.textContent = 'Sin productos agregados.';
            return;
        }
        contResumen.className = '';
        contResumen.innerHTML = items.map(i => `<div class="item-carrito">
            <div><b>${escapeHtml(i.producto_nombre)}</b><small>Carril ${i.numero_carril}${i.codigo_barras ? ' - ' + escapeHtml(i.codigo_barras) : ''}</small></div>
            <span class="cantidad-carrito">x${i.cantidad}</span>
            <button class="btn" data-quitar="${i.numero_carril}" type="button">Quitar</button>
        </div>`).join('') + `<p class="muted">Total: ${(carrito.totales?.unidades || 0).toLocaleString('es-MX')} unidad(es)</p>`;
        contResumen.querySelectorAll('button[data-quitar]').forEach(btn => {
            btn.addEventListener('click', () => postCarrito('quitar', { numero_carril: Number(btn.dataset.quitar) }).catch(e => estadoOperacion(e.message, 'error')));
        });
    }

    selectMaquina.addEventListener('change', async () => {
        estadoOperacion('');
        try {
            if (!selectMaquina.value) {
                await postCarrito('limpiar');
                await cargarCarriles(null);
                return;
            }
            await postCarrito('seleccionar_maquina', { id_maquina: Number(selectMaquina.value) });
            await cargarCarriles(selectMaquina.value);
        } catch (e) {
            estadoOperacion(e.message, 'error');
        }
    });

    let debounce = null;
    inputBuscar.addEventListener('input', () => {
        clearTimeout(debounce);
        debounce = setTimeout(() => {
            filtro = inputBuscar.value.trim().toLowerCase();
            renderCarriles();
        }, 220);
    });

    document.getElementById('c-limpiar').addEventListener('click', () => postCarrito('limpiar').catch(e => estadoOperacion(e.message, 'error')));
    btnCrear.addEventListener('click', async () => {
        btnCrear.disabled = true;
        try {
            const res = await fetch('api/carrito.php?accion=crear_receta', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    nombre_paciente: document.getElementById('c-paciente').value.trim(),
                    medico_solicita: document.getElementById('c-medico').value.trim(),
                }),
            });
            const json = await res.json();
            if (!json.ok) throw new Error(json.error || 'No se pudo generar la receta.');
            const receta = json.data;
            carrito = { items: [], totales: { items: 0, unidades: 0 } };
            estadoOperacion(`Receta generada: ${receta.folio}`, 'ok');
            document.getElementById('c-paciente').value = '';
            document.getElementById('c-medico').value = '';
            await cargarCarriles(selectMaquina.value);
        } catch (e) {
            estadoOperacion(e.message, 'error');
        } finally {
            renderResumen();
        }
    });

    iniciar().catch(e => estadoOperacion(e.message || 'No se pudo cargar el modulo.', 'error'));
</script>
</body>
</html>
