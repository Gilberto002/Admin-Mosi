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
    <title>MDM - Mantenimiento</title>
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
        .alerta-operacion {
            display: none; margin-bottom: 14px; padding: 11px 13px; border-radius: 8px;
            border: 1px solid var(--linea); background: var(--bg-elevada); color: var(--texto-suave); font-size: 13px;
        }
        .alerta-operacion.visible { display: block; }
        .alerta-operacion.error { border-color: rgba(241,112,126,.35); color: var(--critico); background: var(--critico-wash); }
        .stats { display: grid; grid-template-columns: repeat(5, 1fr); gap: 14px; margin-bottom: 14px; }
        .stat, .panel {
            background: var(--bg-elevada); border: 1px solid var(--linea); border-radius: 10px;
            box-shadow: 0 14px 26px -20px rgba(0,0,0,.65);
        }
        .stat { padding: 16px; min-height: 116px; }
        .stat .lbl { color: var(--texto-suave); font-size: 11px; text-transform: uppercase; letter-spacing: .05em; margin-bottom: 8px; }
        .stat .num { font-family: 'Space Grotesk', sans-serif; font-size: 30px; font-weight: 700; line-height: 1; }
        .stat .nota { color: var(--texto-tenue); font-size: 12px; line-height: 1.35; margin-top: 9px; }
        .stat.warn .num { color: var(--ambar); }
        .stat.danger .num { color: var(--critico); }
        .stat.ok .num { color: var(--petroleo); }
        .grid { display: grid; grid-template-columns: minmax(0, 1.25fr) minmax(320px, .75fr); gap: 14px; align-items: start; }
        .panel-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; padding: 15px 18px; border-bottom: 1px solid var(--linea-suave); }
        .panel-head h2 { margin: 0; font-size: 15px; color: var(--texto); }
        .panel-head .sub { color: var(--texto-tenue); font-size: 12px; margin-top: 4px; }
        .toolbar { display: flex; gap: 8px; padding: 14px 18px; border-bottom: 1px solid var(--linea-suave); flex-wrap: wrap; }
        input, select {
            width: 100%; box-sizing: border-box; border: 1px solid var(--linea); border-radius: 8px;
            background: var(--bg); color: var(--texto); font: inherit; font-size: 13px; padding: 9px 11px;
        }
        .toolbar input { flex: 1; min-width: 220px; }
        .toolbar select { width: 190px; }
        .table-shell { overflow: auto; max-height: 620px; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .table-shell table { min-width: 780px; }
        th, td { padding: 11px 18px; border-bottom: 1px solid var(--linea-suave); text-align: left; }
        th { color: var(--texto-suave); font-size: 10px; text-transform: uppercase; letter-spacing: .06em; }
        tbody tr:last-child td { border-bottom: none; }
        tbody tr:hover { background: var(--bg-hover); }
        .mono { font-family: 'JetBrains Mono', monospace; }
        .muted { color: var(--texto-tenue); }
        .badge { display: inline-flex; align-items: center; gap: 5px; padding: 3px 10px; border-radius: 999px; font-size: 11px; font-weight: 600; white-space: nowrap; }
        .badge-ok { background: var(--petroleo-wash); color: var(--petroleo); }
        .badge-warn { background: var(--ambar-wash); color: var(--ambar); }
        .badge-danger { background: var(--critico-wash); color: var(--critico); }
        .vacio, .cargando { color: var(--texto-tenue); font-style: italic; padding: 34px 14px; text-align: center; }
        .prioridades { display: grid; gap: 10px; padding: 18px; }
        .prioridad { border: 1px solid var(--linea-suave); border-radius: 8px; padding: 12px; background: rgba(255,255,255,.02); }
        .prioridad b { display: block; color: var(--texto); margin-bottom: 4px; }
        .prioridad p { color: var(--texto-tenue); font-size: 12px; }
        .overlay-modal {
            position: fixed; inset: 0; background: rgba(10, 12, 14, .68); display: none;
            align-items: flex-start; justify-content: center; padding: 42px 16px; overflow-y: auto; z-index: 50;
        }
        .overlay-modal.abierto { display: flex; }
        .modal {
            width: 100%; max-width: 590px; background: var(--bg-elevada); border: 1px solid var(--linea);
            border-radius: 10px; box-shadow: 0 24px 64px rgba(0,0,0,.52);
        }
        .modal-head, .modal-pie { display: flex; align-items: center; gap: 10px; padding: 16px 18px; border-bottom: 1px solid var(--linea-suave); }
        .modal-head { justify-content: space-between; }
        .modal-head h2 { margin: 0; font-size: 16px; }
        .modal-cerrar { border: none; background: transparent; color: var(--texto-tenue); font-size: 22px; line-height: 1; cursor: pointer; }
        .modal-cerrar:hover { color: var(--texto); }
        .modal-cuerpo { display: grid; grid-template-columns: 1fr 1fr; gap: 13px; padding: 18px; }
        .modal-cuerpo .full { grid-column: 1 / -1; }
        .modal-cuerpo label { display: block; color: var(--texto-suave); font-size: 12px; font-weight: 700; margin-bottom: 6px; }
        textarea {
            width: 100%; box-sizing: border-box; border: 1px solid var(--linea); border-radius: 8px;
            background: var(--bg); color: var(--texto); font: inherit; font-size: 13px; padding: 9px 11px; min-height: 74px; resize: vertical;
        }
        .modal-pie { border-top: 1px solid var(--linea-suave); border-bottom: none; justify-content: flex-end; }
        .modal-mensaje { margin-right: auto; color: var(--texto-tenue); font-size: 12px; }
        .modal-mensaje.ok { color: var(--petroleo); }
        .modal-mensaje.error { color: var(--critico); }
        @media (max-width: 980px) {
            .stats, .grid { grid-template-columns: 1fr; }
            .toolbar select { width: 100%; }
            .modal-cuerpo { grid-template-columns: 1fr; }
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
                <div class="encabezado-pagina__etiqueta">Seguimiento técnico</div>
                <h1>Mantenimiento de máquinas</h1>
                <p>Revisa unidades que requieren atención y registra mantenimientos sin salir de esta pantalla.</p>
            </div>
            <div style="display:flex; gap:8px; flex-wrap:wrap;">
                <button class="btn primario" id="btn-nuevo-mant">Registrar mantenimiento</button>
                <button class="btn" id="btn-actualizar">Actualizar</button>
            </div>
        </div>

        <div id="alerta-operacion" class="alerta-operacion"></div>

        <div class="stats">
            <div class="stat ok"><div class="lbl">Activas</div><div class="num" id="kpi-activas">-</div><div class="nota">Operando sin bloqueo registrado.</div></div>
            <div class="stat warn"><div class="lbl">En mantenimiento</div><div class="num" id="kpi-mantenimiento">-</div><div class="nota">Unidades atendidas por técnico.</div></div>
            <div class="stat danger"><div class="lbl">Paradas</div><div class="num" id="kpi-paradas">-</div><div class="nota">Requieren seguimiento prioritario.</div></div>
            <div class="stat warn"><div class="lbl">Mantenimientos abiertos</div><div class="num" id="kpi-abiertos">-</div><div class="nota">Registros sin fecha de realizado.</div></div>
            <div class="stat danger"><div class="lbl">Vencidos</div><div class="num" id="kpi-vencidos">-</div><div class="nota">Programados antes de hoy.</div></div>
        </div>

        <div class="grid">
            <section class="panel">
                <div class="panel-head">
                    <div>
                        <h2>Unidades para revisar</h2>
                        <div class="sub">Máquinas con más de 30 días sin mantenimiento o sin carga registrada.</div>
                    </div>
                </div>
                <div class="toolbar">
                    <input id="buscar-maquina" placeholder="Buscar serie, código o ubicación">
                    <select id="filtro-estado">
                        <option value="atencion">Requieren atención</option>
                        <option value="nunca">Nunca cargadas</option>
                        <option value="ok">Al día</option>
                    </select>
                </div>
                <div id="tabla-maquinas" class="cargando">Cargando...</div>
            </section>

            <section class="panel">
                <div class="panel-head">
                    <div>
                        <h2>Flujo recomendado</h2>
                        <div class="sub">Orden simple para atender la jornada.</div>
                    </div>
                </div>
                <div class="prioridades">
                    <div class="prioridad"><b>1. Atiende vencidos</b><p>Empieza con mantenimientos programados que ya pasaron su fecha.</p></div>
                    <div class="prioridad"><b>2. Revisa unidades paradas</b><p>Confirma causa, técnico asignado y refacciones necesarias.</p></div>
                    <div class="prioridad"><b>3. Registra el cierre</b><p>Entra al detalle de la máquina y captura fecha realizada para actualizar el tablero.</p></div>
                    <button class="btn primario" id="btn-nuevo-mant-flujo">Registrar mantenimiento</button>
                    <a class="btn primario" href="inventario.php">Ir a inventario de piezas</a>
                </div>
            </section>
        </div>
    </main>
</div>

<div class="overlay-modal" id="overlay-mant">
    <div class="modal">
        <div class="modal-head">
            <h2>Registrar mantenimiento</h2>
            <button class="modal-cerrar" id="mant-cerrar" type="button">&times;</button>
        </div>
        <form id="form-mant">
            <div class="modal-cuerpo">
                <div class="full">
                    <label>Máquina *</label>
                    <select id="fm-id_maquina" required></select>
                </div>
                <div>
                    <label>Tipo *</label>
                    <select id="fm-tipo_mantenimiento" required></select>
                </div>
                <div>
                    <label>Estatus *</label>
                    <select id="fm-id_estatus" required></select>
                </div>
                <div>
                    <label>Fecha programada</label>
                    <input id="fm-fecha_programada" type="date">
                </div>
                <div>
                    <label>Fecha realizado</label>
                    <input id="fm-fecha_realizado" type="date">
                </div>
                <div>
                    <label>Técnico</label>
                    <input id="fm-tecnico">
                </div>
                <div class="full">
                    <label>Descripción</label>
                    <textarea id="fm-descripcion" placeholder="Trabajo realizado, falla detectada, piezas usadas..."></textarea>
                </div>
                <?php if (Auth::isAdmin()): ?>
                    <div class="full">
                        <label>Refacción usada</label>
                        <select id="fm-id_refaccion"><option value="">Sin salida de inventario</option></select>
                    </div>
                    <div>
                        <label>Cantidad usada</label>
                        <input id="fm-cantidad_refaccion" type="number" min="1" value="1">
                    </div>
                <?php endif; ?>
            </div>
            <div class="modal-pie">
                <span class="modal-mensaje" id="mant-mensaje"></span>
                <button class="btn" type="button" id="mant-cancelar">Cancelar</button>
                <button class="btn primario" type="submit" id="mant-guardar">Guardar</button>
            </div>
        </form>
    </div>
</div>

<script src="assets/js/comun.js?v=20260912-1"></script>
<script>
    const ES_ADMIN = <?= Auth::isAdmin() ? 'true' : 'false' ?>;
    let debounce = null;
    let maquinasCache = [];
    let maquinasFormularioCache = null;
    let catalogoTiposMant = null;
    let catalogoEstatusMant = null;
    let refaccionesMantCache = null;

    function fmt(n) { return (Number(n) || 0).toLocaleString('es-MX'); }

    function estadoOperacion(mensaje, tipo = '') {
        const alerta = document.getElementById('alerta-operacion');
        alerta.textContent = mensaje || '';
        alerta.className = mensaje ? `alerta-operacion visible ${tipo}` : 'alerta-operacion';
    }

    async function cargarResumen() {
        const json = await obtenerJSON('api/mantenimiento_operacion.php?accion=resumen');
        const m = json.data.maquinas || {};
        const mant = json.data.mantenimientos || {};
        document.getElementById('kpi-activas').textContent = fmt(m.activas);
        document.getElementById('kpi-mantenimiento').textContent = fmt(m.en_mantenimiento);
        document.getElementById('kpi-paradas').textContent = fmt(m.paradas);
        document.getElementById('kpi-abiertos').textContent = fmt(mant.mantenimientos_abiertos);
        document.getElementById('kpi-vencidos').textContent = fmt(mant.vencidos);
    }

    function renderUnidades(unidades) {
        if (!unidades.length) {
            return '<p class="vacio">No hay máquinas para este filtro.</p>';
        }
        return `<div class="table-shell"><table>
            <thead><tr><th>Máquina</th><th>Ubicación</th><th>Estado</th><th>Último mantenimiento</th><th>Acción</th></tr></thead>
            <tbody>${unidades.map(u => `
                <tr>
                    <td><b class="mono">${escapeHtml(u.numero_serie)}</b><br><span class="muted">${escapeHtml(u.codigo_interno) || 'Sin código'}</span></td>
                    <td>${escapeHtml(u.nombre_ubicacion) || '<span class="muted">Sin ubicación</span>'}</td>
                    <td>${escapeHtml(u.estatus_nombre) || '<span class="muted">Sin estatus</span>'}</td>
                    <td>${badgeMantenimiento(u.dias_sin_mantenimiento === null || u.dias_sin_mantenimiento === undefined ? null : Number(u.dias_sin_mantenimiento))}</td>
                    <td><button type="button" class="btn btn-reg-mant" data-id="${u.id_maquina}">Registrar</button> <a class="btn" href="maquina.php?id=${u.id_maquina}">Detalle</a></td>
                </tr>`).join('')}
            </tbody>
        </table></div>`;
    }

    async function cargarMaquinas() {
        const cont = document.getElementById('tabla-maquinas');
        cont.className = 'cargando';
        cont.textContent = 'Cargando...';
        const params = new URLSearchParams({
            agrupado: '1',
            page_size: '100',
            estado: document.getElementById('filtro-estado').value,
        });
        const q = document.getElementById('buscar-maquina').value.trim();
        if (q) params.set('q', q);
        const json = await obtenerJSON(`api/maquinas.php?${params}`);
        maquinasCache = json.data || [];
        cont.className = '';
        cont.innerHTML = renderUnidades(maquinasCache);
        cont.querySelectorAll('.btn-reg-mant').forEach(btn => {
            btn.addEventListener('click', () => abrirModalMantenimiento(Number(btn.dataset.id)));
        });
    }

    async function cargarCatalogosFormulario() {
        if (!maquinasFormularioCache) {
            const maquinas = await obtenerJSON('api/mantenimiento_operacion.php?accion=maquinas');
            maquinasFormularioCache = maquinas.data || [];
        }
        if (!catalogoTiposMant) {
            const tipos = await obtenerJSON('api/tipos_mantenimiento.php');
            catalogoTiposMant = tipos.data || [];
        }
        if (!catalogoEstatusMant) {
            const estatus = await obtenerJSON('api/estatus_maquina.php');
            catalogoEstatusMant = estatus.data || [];
        }
        if (ES_ADMIN && !refaccionesMantCache) {
            const refacciones = await obtenerJSON('api/mantenimiento_operacion.php?accion=refacciones');
            refaccionesMantCache = refacciones.data || [];
        }

        document.getElementById('fm-id_maquina').innerHTML = maquinasFormularioCache.length
            ? maquinasFormularioCache.map(m => `<option value="${m.id_maquina}">${escapeHtml(m.numero_serie)}${m.nombre_ubicacion ? ' · ' + escapeHtml(m.nombre_ubicacion) : ''}</option>`).join('')
            : '<option value="">No hay máquinas disponibles</option>';
        document.getElementById('fm-tipo_mantenimiento').innerHTML = catalogoTiposMant.length
            ? catalogoTiposMant.map(t => `<option value="${t.id_tipo_mantenimiento}">${escapeHtml(t.nombre)}</option>`).join('')
            : '<option value="">No hay tipos configurados</option>';
        document.getElementById('fm-id_estatus').innerHTML = catalogoEstatusMant.length
            ? catalogoEstatusMant.map(e => `<option value="${e.id_estatus}">${escapeHtml(e.nombre)}</option>`).join('')
            : '<option value="">No hay estatus configurados</option>';
        const selectRefaccion = document.getElementById('fm-id_refaccion');
        if (selectRefaccion) {
            selectRefaccion.innerHTML = '<option value="">Sin salida de inventario</option>' + (refaccionesMantCache || []).map(r =>
                `<option value="${r.id_refaccion}">${escapeHtml(r.nombre)} · stock ${fmt(r.stock_actual)}</option>`
            ).join('');
        }
    }

    async function abrirModalMantenimiento(idMaquina = null) {
        const form = document.getElementById('form-mant');
        const msg = document.getElementById('mant-mensaje');
        form.reset();
        msg.textContent = '';
        msg.className = 'modal-mensaje';
        try {
            await cargarCatalogosFormulario();
            if (idMaquina) document.getElementById('fm-id_maquina').value = String(idMaquina);
            document.getElementById('overlay-mant').classList.add('abierto');
        } catch (e) {
            estadoOperacion(e.message || 'No se pudieron cargar los catálogos de mantenimiento.', 'error');
        }
    }

    function cerrarModalMantenimiento() {
        document.getElementById('overlay-mant').classList.remove('abierto');
    }

    async function actualizarTodo() {
        const boton = document.getElementById('btn-actualizar');
        boton.disabled = true;
        estadoOperacion('');
        try {
            await Promise.all([cargarResumen(), cargarMaquinas()]);
        } catch (e) {
            estadoOperacion(e.message || 'No se pudo cargar la información de mantenimiento.', 'error');
        } finally {
            boton.disabled = false;
        }
    }

    document.getElementById('btn-actualizar').addEventListener('click', actualizarTodo);
    document.getElementById('btn-nuevo-mant').addEventListener('click', () => abrirModalMantenimiento());
    document.getElementById('btn-nuevo-mant-flujo').addEventListener('click', () => abrirModalMantenimiento());
    document.getElementById('mant-cerrar').addEventListener('click', cerrarModalMantenimiento);
    document.getElementById('mant-cancelar').addEventListener('click', cerrarModalMantenimiento);
    document.getElementById('overlay-mant').addEventListener('click', (ev) => {
        if (ev.target.id === 'overlay-mant') cerrarModalMantenimiento();
    });
    document.getElementById('form-mant').addEventListener('submit', async (ev) => {
        ev.preventDefault();
        const btn = document.getElementById('mant-guardar');
        const msg = document.getElementById('mant-mensaje');
        btn.disabled = true;
        msg.textContent = '';
        msg.className = 'modal-mensaje';
        const payload = {
            id_maquina: parseInt(document.getElementById('fm-id_maquina').value, 10) || null,
            tipo_mantenimiento: parseInt(document.getElementById('fm-tipo_mantenimiento').value, 10) || null,
            id_estatus: parseInt(document.getElementById('fm-id_estatus').value, 10) || null,
            fecha_programada: document.getElementById('fm-fecha_programada').value || null,
            fecha_realizado: document.getElementById('fm-fecha_realizado').value || null,
            tecnico: document.getElementById('fm-tecnico').value.trim(),
            descripcion: document.getElementById('fm-descripcion').value.trim(),
        };
        const idRefaccion = ES_ADMIN && document.getElementById('fm-id_refaccion')
            ? parseInt(document.getElementById('fm-id_refaccion').value, 10) || null
            : null;
        try {
            const res = await fetch('api/mantenimientos.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            });
            const json = await res.json();
            if (!json.ok) {
                msg.textContent = json.error || 'No se pudo guardar.';
                msg.className = 'modal-mensaje error';
                return;
            }
            if (idRefaccion) {
                const mov = await fetch('api/mantenimiento_operacion.php?accion=movimiento', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        id_refaccion: idRefaccion,
                        tipo: 'salida',
                        cantidad: parseInt(document.getElementById('fm-cantidad_refaccion').value, 10) || 1,
                        id_maquina: payload.id_maquina,
                        tecnico: payload.tecnico,
                        motivo: payload.descripcion || 'Mantenimiento',
                    }),
                });
                const jsonMov = await mov.json();
                if (!jsonMov.ok) {
                    msg.textContent = `Mantenimiento registrado, pero inventario no se actualizó: ${jsonMov.error || 'error desconocido'}`;
                    msg.className = 'modal-mensaje error';
                    await actualizarTodo();
                    return;
                }
                refaccionesMantCache = null;
            }
            msg.textContent = 'Mantenimiento registrado.';
            msg.className = 'modal-mensaje ok';
            await actualizarTodo();
            setTimeout(cerrarModalMantenimiento, 650);
        } catch (e) {
            msg.textContent = 'No se pudo conectar con el servidor.';
            msg.className = 'modal-mensaje error';
        } finally {
            btn.disabled = false;
        }
    });
    document.getElementById('filtro-estado').addEventListener('change', cargarMaquinas);
    document.getElementById('buscar-maquina').addEventListener('input', () => {
        clearTimeout(debounce);
        debounce = setTimeout(cargarMaquinas, 300);
    });

    actualizarTodo();
</script>
</body>
</html>
