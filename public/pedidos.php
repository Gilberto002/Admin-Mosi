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
    <title>MDM - Pedidos</title>
    <meta name="csrf-token" content="<?= htmlspecialchars(Auth::csrfToken()) ?>">
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
            border-radius: 8px; padding: 9px 14px; font: inherit; font-size: 13px; font-weight: 700; cursor: pointer; text-decoration: none;
        }
        .btn:hover { border-color: var(--petroleo); color: var(--petroleo); }
        .btn.primario { border: none; background: var(--petroleo); color: #06201F; }
        .btn.peligro:hover { border-color: var(--critico); color: var(--critico); }
        .alerta-operacion {
            display: none; margin-bottom: 14px; padding: 11px 13px; border-radius: 8px;
            border: 1px solid var(--linea); background: var(--bg-elevada); color: var(--texto-suave); font-size: 13px;
        }
        .alerta-operacion.visible { display: block; }
        .alerta-operacion.error { border-color: rgba(241,112,126,.35); color: var(--critico); background: var(--critico-wash); }
        .stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; margin-bottom: 14px; }
        .stat, .panel {
            background: var(--bg-elevada); border: 1px solid var(--linea); border-radius: 10px;
            box-shadow: 0 14px 26px -20px rgba(0,0,0,.65);
        }
        .stat { padding: 16px; min-height: 96px; }
        .stat .lbl { color: var(--texto-suave); font-size: 11px; text-transform: uppercase; letter-spacing: .05em; margin-bottom: 8px; }
        .stat .num { font-family: 'Space Grotesk', sans-serif; font-size: 30px; font-weight: 700; line-height: 1; }
        .stat.warn .num { color: var(--ambar); }
        .stat.ok .num { color: var(--petroleo); }
        .panel-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; padding: 15px 18px; border-bottom: 1px solid var(--linea-suave); }
        .panel-head h2 { margin: 0; font-size: 15px; color: var(--texto); }
        .panel-head .sub { color: var(--texto-tenue); font-size: 12px; margin-top: 4px; }
        .toolbar { display: flex; gap: 8px; padding: 14px 18px; border-bottom: 1px solid var(--linea-suave); flex-wrap: wrap; }
        select {
            box-sizing: border-box; border: 1px solid var(--linea); border-radius: 8px;
            background: var(--bg); color: var(--texto); font: inherit; font-size: 13px; padding: 9px 11px; width: 220px;
        }
        .table-shell { overflow: auto; max-height: 640px; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .table-shell table { min-width: 900px; }
        th, td { padding: 11px 18px; border-bottom: 1px solid var(--linea-suave); text-align: left; }
        th { color: var(--texto-suave); font-size: 10px; text-transform: uppercase; letter-spacing: .06em; }
        tbody tr:last-child td { border-bottom: none; }
        tbody tr:hover { background: var(--bg-hover); }
        .mono { font-family: 'JetBrains Mono', monospace; }
        .muted { color: var(--texto-tenue); }
        .sustancia-activa { font-size: 11px; color: var(--texto-suave); }
        .folio-cedis { color: #00c2a8; }
        .badge { display: inline-flex; align-items: center; gap: 5px; padding: 3px 10px; border-radius: 999px; font-size: 11px; font-weight: 600; white-space: nowrap; }
        .badge-ok { background: var(--petroleo-wash); color: var(--petroleo); }
        .badge-warn { background: var(--ambar-wash); color: var(--ambar); }
        .badge-danger { background: var(--critico-wash); color: var(--critico); }
        .vacio, .cargando { color: var(--texto-tenue); font-style: italic; padding: 34px 14px; text-align: center; }
        .acciones-fila { display: flex; gap: 6px; flex-wrap: wrap; }
        .overlay-modal {
            position: fixed; inset: 0; background: rgba(10, 12, 14, .68); display: none;
            align-items: flex-start; justify-content: center; padding: 42px 16px; overflow-y: auto; z-index: 50;
        }
        .overlay-modal.abierto { display: flex; }
        .modal {
            width: 100%; max-width: 620px; background: var(--bg-elevada); border: 1px solid var(--linea);
            border-radius: 10px; box-shadow: 0 24px 64px rgba(0,0,0,.52);
        }
        .modal-head, .modal-pie { display: flex; align-items: center; gap: 10px; padding: 16px 18px; border-bottom: 1px solid var(--linea-suave); }
        .modal-head { justify-content: space-between; }
        .modal-head h2 { margin: 0; font-size: 16px; }
        .modal-cerrar { border: none; background: transparent; color: var(--texto-tenue); font-size: 22px; line-height: 1; cursor: pointer; }
        .modal-cerrar:hover { color: var(--texto); }
        .modal-cuerpo { padding: 16px 18px; display: grid; gap: 10px; max-height: 50vh; overflow-y: auto; }
        .linea-cedis { display: grid; grid-template-columns: 1fr 110px; gap: 10px; align-items: center; padding: 10px 12px; border: 1px solid var(--linea-suave); border-radius: 8px; }
        .linea-cedis .info { font-size: 12.5px; }
        .linea-cedis .info b { display: block; color: var(--texto); }
        .linea-cedis input[type="number"] {
            width: 100%; box-sizing: border-box; border: 1px solid var(--linea); border-radius: 8px;
            background: var(--bg); color: var(--texto); font: inherit; font-size: 13px; padding: 8px 10px;
        }
        .linea-cedis.resultado-ok { border-color: rgba(44,217,190,.4); }
        .linea-cedis.resultado-error { border-color: rgba(241,112,126,.4); }
        .linea-cedis .resultado { font-size: 11.5px; margin-top: 4px; }
        .modal-pie { border-top: 1px solid var(--linea-suave); border-bottom: none; justify-content: flex-end; }
        .modal-mensaje { margin-right: auto; color: var(--texto-tenue); font-size: 12px; }
        .modal-mensaje.error { color: var(--critico); }
        @media (max-width: 980px) {
            .stats { grid-template-columns: 1fr 1fr; }
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
                <div class="encabezado-pagina__etiqueta">Reabastecimiento</div>
                <h1>Solicitudes de pedido</h1>
                <p>Generadas automáticamente cuando un carril baja del umbral de stock (<?= \App\Vending\SolicitudPedidoGenerador::UMBRAL_STOCK_BAJO ?> unidades) o queda vacío. Selecciona pendientes para enviarlas como pedido real a CEDIS.</p>
            </div>
            <div style="display:flex; gap:8px; flex-wrap:wrap;">
                <button class="btn primario" id="btn-enviar-cedis" style="display:none;">Enviar a CEDIS (<span id="contador-seleccion">0</span>)</button>
                <button class="btn" id="btn-actualizar">Actualizar</button>
            </div>
        </div>

        <div id="alerta-operacion" class="alerta-operacion"></div>

        <div class="stats">
            <div class="stat warn"><div class="lbl">Pendientes</div><div class="num" id="kpi-pendientes">-</div></div>
            <div class="stat"><div class="lbl">Enviadas</div><div class="num" id="kpi-enviadas">-</div></div>
            <div class="stat ok"><div class="lbl">Entregadas</div><div class="num" id="kpi-entregadas">-</div></div>
            <div class="stat"><div class="lbl">Canceladas</div><div class="num" id="kpi-canceladas">-</div></div>
        </div>

        <section class="panel">
            <div class="panel-head">
                <div>
                    <h2>Solicitudes</h2>
                    <div class="sub">Ordenadas por estatus (pendientes primero) y fecha de creación.</div>
                </div>
            </div>
            <div class="toolbar">
                <select id="filtro-estatus">
                    <option value="">Todas</option>
                    <option value="1">Pendientes</option>
                    <option value="2">Enviadas</option>
                    <option value="3">Entregadas</option>
                    <option value="4">Canceladas</option>
                </select>
            </div>
            <div id="tabla-solicitudes" class="cargando">Cargando...</div>
        </section>
    </main>
</div>

<div class="overlay-modal" id="overlay-cedis">
    <div class="modal">
        <div class="modal-head">
            <h2>Enviar a CEDIS</h2>
            <button class="modal-cerrar" id="cedis-cerrar" type="button">&times;</button>
        </div>
        <div class="modal-cuerpo" id="cedis-lineas"></div>
        <div class="modal-pie">
            <span class="modal-mensaje" id="cedis-mensaje"></span>
            <button class="btn" type="button" id="cedis-cancelar">Cancelar</button>
            <button class="btn primario" type="button" id="cedis-confirmar">Confirmar envío</button>
        </div>
    </div>
</div>

<script src="assets/js/comun.js?v=20260912-1"></script>
  <script>
    function estadoOperacion(mensaje, tipo = '') {
        const alerta = document.getElementById('alerta-operacion');
        alerta.textContent = mensaje || '';
        alerta.className = mensaje ? `alerta-operacion visible ${tipo}` : 'alerta-operacion';
    }

    function badgeEstatus(idEstatus, nombre) {
        const clase = { 1: 'badge-danger', 2: 'badge-warn', 3: 'badge-ok', 4: 'badge' }[idEstatus] || 'badge';
        return `<span class="badge ${clase}">${escapeHtml(nombre || '—')}</span>`;
    }

    function fechaCorta(f) {
        if (!f) return '<span class="muted">—</span>';
        return new Date(f).toLocaleString('es-MX', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });
    }

    let solicitudesCache = [];
    const seleccionCedis = new Set();

    function accionesFila(s) {
        const botones = [];
        if (s.id_estatus === 1) {
            // "Marcar enviado" (cambio de estatus manual, sin crear ningun
            // pedido real en CEDIS) se retiro -- dejaba solicitudes que
            // parecian enviadas sin ninguna conexion real detras. La unica     
            // vía para enviar de verdad es seleccionar con la casilla y usar
            // "Enviar a CEDIS" arriba (llama a PedidoCedisService).
            botones.push(`<button type="button" class="btn peligro btn-resolver" data-id="${s.id_solicitud}" data-estatus="4">Cancelar</button>`);
        } else if (s.id_estatus === 2) {
            if (s.id_pedido_cedis1) {
                // Tiene un pedido real de CEDIS ligado
                // (App\Vending\PedidoCedisService) -- dispara la recepción
                // real (App\Vending\RecepcionPedidoService), vía de respaldo
                // por si el colaborador no puede confirmarla desde Resurtir.
                botones.push(`<button type="button" class="btn primario btn-recibir-cedis" data-id="${s.id_solicitud}">Recibir pedido CEDIS</button>`);
            } else {
                // Huérfana: quedó en Enviado sin pedido real detrás (rastro
                // del extinto "Marcar enviado", o cualquier otra forma en
                // que se haya perdido la conexión). No hay nada que recibir
                // -- reintentarCedis() la regresa a Pendiente y la manda por
                // el flujo real (pedido_cedis.php) en un solo clic.
                botones.push(`<button type="button" class="btn primario btn-reintentar-cedis" data-id="${s.id_solicitud}" data-cantidad="${s.cantidad_detectada}">Reintentar por CEDIS</button>`);
            }
        }
        return `<div class="acciones-fila">${botones.join('')}</div>`;
    }

    function renderTabla(solicitudes) {
        if (!solicitudes.length) {
            return '<p class="vacio">No hay solicitudes para este filtro.</p>';
        }
        return `<div class="table-shell"><table>
            <thead><tr><th></th><th>Máquina</th><th>Carril</th><th>Producto</th><th>Detectado</th><th>Estatus</th><th>Folio CEDIS</th><th>Creada</th><th>Resuelta</th><th>Acciones</th></tr></thead>
            <tbody>${solicitudes.map(s => `
                <tr>
                    <td>${s.id_estatus === 1 ? `<input type="checkbox" class="chk-cedis" data-id="${s.id_solicitud}" ${seleccionCedis.has(s.id_solicitud) ? 'checked' : ''}>` : ''}</td>
                    <td><b class="mono">${escapeHtml(s.numero_serie)}</b><br><span class="muted">${escapeHtml(s.nombre_ubicacion) || 'Sin ubicación'}</span></td>
                    <td>C${escapeHtml(s.numero_carril)}</td>
                    <td>${s.producto ? `${escapeHtml(s.producto)}${s.sustancia_activa ? `<br><span class="sustancia-activa">${escapeHtml(s.sustancia_activa)}</span>` : ''}` : '<span class="muted">Sin producto activo</span>'}</td>
                    <td>${s.cantidad_detectada}</td>
                    <td>${badgeEstatus(s.id_estatus, s.estatus_nombre)}</td>
                    <td>${s.folio_cedis ? `<span class="mono folio-cedis">${escapeHtml(s.folio_cedis)}</span>` : '<span class="muted">—</span>'}</td>
                    <td>${fechaCorta(s.fecha_creacion)}</td>
                    <td>${fechaCorta(s.fecha_resuelta)}</td>
                    <td>${accionesFila(s)}</td>
                </tr>`).join('')}
            </tbody>
        </table></div>`;
    }

    function actualizarBotonCedis() {
        const btn = document.getElementById('btn-enviar-cedis');
        document.getElementById('contador-seleccion').textContent = seleccionCedis.size;
        btn.style.display = seleccionCedis.size > 0 ? '' : 'none';
    }

    async function cargarSolicitudes() {
        const cont = document.getElementById('tabla-solicitudes');
        cont.className = 'cargando';
        cont.textContent = 'Cargando...';
        const idEstatus = document.getElementById('filtro-estatus').value;
        const params = idEstatus ? `?id_estatus=${idEstatus}` : '';
        const json = await obtenerJSON(`api/solicitudes_pedido.php${params}`);
        const solicitudes = json.data || [];
        solicitudesCache = solicitudes;
        // Una solicitud que ya no está en pantalla (cambió de filtro) o que
        // dejó de estar pendiente no debe seguir "seleccionada" en silencio.
        const idsPendientesVisibles = new Set(solicitudes.filter(s => s.id_estatus === 1).map(s => s.id_solicitud));
        [...seleccionCedis].forEach(id => { if (!idsPendientesVisibles.has(id)) seleccionCedis.delete(id); });

        cont.className = '';
        cont.innerHTML = renderTabla(solicitudes);

        document.getElementById('kpi-pendientes').textContent = solicitudes.filter(s => s.id_estatus === 1).length;
        document.getElementById('kpi-enviadas').textContent = solicitudes.filter(s => s.id_estatus === 2).length;
        document.getElementById('kpi-entregadas').textContent = solicitudes.filter(s => s.id_estatus === 3).length;
        document.getElementById('kpi-canceladas').textContent = solicitudes.filter(s => s.id_estatus === 4).length;

        cont.querySelectorAll('.btn-resolver').forEach(btn => {
            btn.addEventListener('click', () => resolverSolicitud(Number(btn.dataset.id), Number(btn.dataset.estatus)));
        });
        cont.querySelectorAll('.btn-recibir-cedis').forEach(btn => {
            btn.addEventListener('click', () => recibirPedidoCedis(btn));
        });
        cont.querySelectorAll('.btn-reintentar-cedis').forEach(btn => {
            btn.addEventListener('click', () => reintentarCedis(btn));
        });
        cont.querySelectorAll('.chk-cedis').forEach(chk => {
            chk.addEventListener('change', () => {
                const id = Number(chk.dataset.id);
                if (chk.checked) seleccionCedis.add(id); else seleccionCedis.delete(id);
                actualizarBotonCedis();
            });
        });
        actualizarBotonCedis();
    }

    async function resolverSolicitud(idSolicitud, idEstatus) {
        estadoOperacion('');
        try {
            const res = await fetch('api/solicitudes_pedido.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', ...csrfHeader() },
                body: JSON.stringify({ id_solicitud: idSolicitud, id_estatus: idEstatus }),
            });
            const json = await res.json();
            if (!json.ok) {
                estadoOperacion(json.error || 'No se pudo actualizar la solicitud.', 'error');
                return;
            }
            await cargarSolicitudes();
        } catch (e) {
            estadoOperacion('No se pudo conectar con el servidor.', 'error');
        }
    }

    // Vía de respaldo: confirma la recepción real del pedido en CEDIS desde
    // este panel (sin pasar por la carga física de un carril en Resurtir).
    // No toca stock -- ver public/api/solicitud_recibir_pedido.php.
    async function recibirPedidoCedis(btn) {
        estadoOperacion('');
        btn.disabled = true;
        btn.textContent = 'Recibiendo…';
        try {
            const res = await fetch('api/solicitud_recibir_pedido.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', ...csrfHeader() },
                body: JSON.stringify({ id_solicitud: Number(btn.dataset.id) }),
            });
            const json = await res.json();
            if (!json.ok) {
                estadoOperacion(json.error || 'No se pudo confirmar la recepción del pedido.', 'error');
                btn.disabled = false;
                btn.textContent = 'Recibir pedido CEDIS';
                return;
            }
            estadoOperacion('Pedido recibido correctamente.', '');
            await cargarSolicitudes();
        } catch (e) {
            estadoOperacion('No se pudo conectar con el servidor.', 'error');
            btn.disabled = false;
            btn.textContent = 'Recibir pedido CEDIS';
        }
    }

    // Solicitud "huérfana" (quedó en Enviado sin pedido real de CEDIS
    // detrás -- rastro del extinto botón "Marcar enviado"). En un solo
    // clic: 1) la regresa a Pendiente (solicitudes_pedido.php, ya valida
    // que solo aplique a huérfanas) y 2) la manda por el flujo real
    // (pedido_cedis.php -- el mismo que usa "Enviar a CEDIS" arriba), con
    // la cantidad detectada original.
    async function reintentarCedis(btn) {
        estadoOperacion('');
        const idSolicitud = Number(btn.dataset.id);
        const cantidad = Number(btn.dataset.cantidad) || 1;
        btn.disabled = true;
        btn.textContent = 'Conectando…';
        try {
            const resPendiente = await fetch('api/solicitudes_pedido.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', ...csrfHeader() },
                body: JSON.stringify({ id_solicitud: idSolicitud, id_estatus: 1 }),
            });
            const jsonPendiente = await resPendiente.json();
            if (!jsonPendiente.ok) {
                estadoOperacion(jsonPendiente.error || 'No se pudo preparar la solicitud para reenviar.', 'error');
                btn.disabled = false;
                btn.textContent = 'Reintentar por CEDIS';
                return;
            }

            const resCedis = await fetch('api/pedido_cedis.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', ...csrfHeader() },
                body: JSON.stringify({ lineas: [{ id_solicitud: idSolicitud, cantidad }] }),
            });
            const jsonCedis = await resCedis.json();
            const resultado = jsonCedis.ok ? jsonCedis.data[idSolicitud] : null;
            if (!resultado || !resultado.ok) {
                estadoOperacion((resultado && resultado.error) || jsonCedis.error || 'No se pudo conectar con CEDIS.', 'error');
                btn.disabled = false;
                btn.textContent = 'Reintentar por CEDIS';
                return;
            }

            estadoOperacion(`Conectado a CEDIS -- folio ${resultado.folio}.`, '');
            await cargarSolicitudes();
        } catch (e) {
            estadoOperacion('No se pudo conectar con el servidor.', 'error');
            btn.disabled = false;
            btn.textContent = 'Reintentar por CEDIS';
        }
    }

    function abrirModalCedis() {
        const cont = document.getElementById('cedis-lineas');
        const msg = document.getElementById('cedis-mensaje');
        msg.textContent = '';
        msg.className = 'modal-mensaje';

        const seleccionadas = solicitudesCache.filter(s => seleccionCedis.has(s.id_solicitud));
        cont.innerHTML = seleccionadas.map(s => `
            <div class="linea-cedis" data-id="${s.id_solicitud}">
                <div class="info">
                    <b>${escapeHtml(s.producto) || 'Producto sin nombre'}</b>
                    ${escapeHtml(s.numero_serie)} · C${escapeHtml(s.numero_carril)} · detectado con ${s.cantidad_detectada}
                    <div class="resultado"></div>
                </div>
                <input type="number" class="cedis-cantidad" min="1" placeholder="Cantidad">
            </div>
        `).join('');

        document.getElementById('overlay-cedis').classList.add('abierto');
    }

    function cerrarModalCedis() {
        document.getElementById('overlay-cedis').classList.remove('abierto');
    }

    async function confirmarEnvioCedis() {
        const msg = document.getElementById('cedis-mensaje');
        const btn = document.getElementById('cedis-confirmar');
        msg.textContent = '';
        msg.className = 'modal-mensaje';

        const lineas = [];
        let faltaCantidad = false;
        document.querySelectorAll('#cedis-lineas .linea-cedis').forEach(div => {
            const cantidad = parseInt(div.querySelector('.cedis-cantidad').value, 10);
            if (!cantidad || cantidad <= 0) {
                faltaCantidad = true;
                return;
            }
            lineas.push({ id_solicitud: Number(div.dataset.id), cantidad });
        });

        if (faltaCantidad || lineas.length === 0) {
            msg.textContent = 'Captura una cantidad válida en cada línea.';
            msg.className = 'modal-mensaje error';
            return;
        }

        btn.disabled = true;
        try {
            const res = await fetch('api/pedido_cedis.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', ...csrfHeader() },
                body: JSON.stringify({ lineas }),
            });
            const json = await res.json();
            if (!json.ok) {
                msg.textContent = json.error || 'No se pudo enviar el pedido.';
                msg.className = 'modal-mensaje error';
                return;
            }

            let huboExito = false;
            let huboError = false;
            document.querySelectorAll('#cedis-lineas .linea-cedis').forEach(div => {
                const id = Number(div.dataset.id);
                const r = json.data[id];
                const resultadoEl = div.querySelector('.resultado');
                if (!r) return;
                if (r.ok) {
                    huboExito = true;
                    div.classList.add('resultado-ok');
                    resultadoEl.innerHTML = `<span style="color:var(--petroleo)">Enviado — folio CEDIS ${r.folio}</span>`;
                    seleccionCedis.delete(id);
                } else {
                    huboError = true;
                    div.classList.add('resultado-error');
                    resultadoEl.innerHTML = `<span style="color:var(--critico)">${escapeHtml(r.error)}</span>`;
                }
            });

            msg.textContent = huboError
                ? (huboExito ? 'Algunas líneas se enviaron, otras necesitan corregirse.' : 'No se pudo enviar ninguna línea.')
                : 'Todo enviado correctamente.';
            msg.className = huboError ? 'modal-mensaje error' : 'modal-mensaje';

            await cargarSolicitudes();
            if (!huboError) {
                setTimeout(cerrarModalCedis, 900);
            }
        } catch (e) {
            msg.textContent = 'No se pudo conectar con el servidor.';
            msg.className = 'modal-mensaje error';
        } finally {
            btn.disabled = false;
        }
    }

    document.getElementById('btn-actualizar').addEventListener('click', cargarSolicitudes);
    document.getElementById('filtro-estatus').addEventListener('change', cargarSolicitudes);
    document.getElementById('btn-enviar-cedis').addEventListener('click', abrirModalCedis);
    document.getElementById('cedis-cerrar').addEventListener('click', cerrarModalCedis);
    document.getElementById('cedis-cancelar').addEventListener('click', cerrarModalCedis);
    document.getElementById('overlay-cedis').addEventListener('click', (ev) => {
        if (ev.target.id === 'overlay-cedis') cerrarModalCedis();
    });
    document.getElementById('cedis-confirmar').addEventListener('click', confirmarEnvioCedis);

    cargarSolicitudes();
</script>
</body>
</html>
