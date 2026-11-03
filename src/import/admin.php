<?php
// public/admin.php — listado de la red de máquinas, agrupado por unidad (maestra + esclavas)
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/bootstrap.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Medical Life - Máquinas</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/estilo.css?v=20260811-3">
    <style>
        /* Específico de esta vista: tarjeta de unidad (maestra + esclavas) */
        .lista-unidades { display: flex; flex-direction: column; }
        .unidad {
            display: grid;
            grid-template-columns: 1.4fr 1.6fr auto auto auto;
            align-items: center;
            gap: 18px;
            padding: 16px 20px;
            border-bottom: 1px solid var(--line-soft);
        }
        .unidad:last-child { border-bottom: none; }
        .unidad:hover { background: #FAFBFC; }

        .unidad .maestra-col { display: flex; align-items: center; gap: 10px; min-width: 0; cursor: pointer; }
        .unidad .maestra-col .rol-punto { width: 7px; height: 7px; border-radius: 50%; background: var(--teal); flex-shrink: 0; }
        .unidad .maestra-col .info { min-width: 0; }
        .unidad .maestra-col .serie { font-weight: 600; font-size: 13.5px; }
        .unidad .maestra-col .ubic { color: var(--ink-soft); font-size: 12px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

        .unidad .esclavas-col { display: flex; flex-wrap: wrap; gap: 6px; min-width: 0; }
        .unidad .esclavas-col .sin-esclava { color: var(--ink-faint); font-size: 12px; font-style: italic; }

        .unidad .fecha-col { font-size: 12.5px; color: var(--ink-soft); white-space: nowrap; cursor: pointer; }
        .unidad .estado-col { justify-self: end; cursor: pointer; }

        .unidad .acciones-col { display: flex; gap: 6px; justify-self: end; }
        .unidad .acciones-col button {
            border: 1px solid var(--line-soft); background: var(--surface); border-radius: 6px;
            width: 28px; height: 28px; display: flex; align-items: center; justify-content: center;
            cursor: pointer; color: var(--ink-soft);
        }
        .unidad .acciones-col button:hover { color: var(--ink); border-color: var(--ink-faint); }
        .unidad .acciones-col button.btn-baja:hover { color: var(--rose); border-color: var(--rose); }

        .encabezado-pagina { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; }
        .acciones-encabezado { display: flex; gap: 10px; flex-shrink: 0; }
        .btn-nueva-maquina, .btn-cargar-stock {
            border: none; color: #fff; font-family: inherit; font-weight: 600;
            font-size: 13px; padding: 10px 16px; border-radius: 8px; cursor: pointer; white-space: nowrap;
        }
        .btn-nueva-maquina { background: var(--teal-dark); }
        .btn-nueva-maquina:hover { opacity: .92; }
        .btn-cargar-stock { background: var(--ink-soft); }
        .btn-cargar-stock:hover { opacity: .92; }

        /* --- Modal crear/editar --- */
        .overlay-modal {
            position: fixed; inset: 0; background: rgba(15, 23, 32, .45); display: none;
            align-items: flex-start; justify-content: center; padding: 40px 16px; overflow-y: auto; z-index: 50;
        }
        .overlay-modal.abierto { display: flex; }
        .modal {
            background: var(--surface); border-radius: 12px; width: 100%; max-width: 560px;
            box-shadow: 0 20px 50px rgba(0,0,0,.2);
        }
        .modal-encabezado {
            display: flex; align-items: center; justify-content: space-between;
            padding: 16px 20px; border-bottom: 1px solid var(--line-soft);
        }
        .modal-encabezado h2 { margin: 0; font-size: 16px; }
        .modal-cerrar { border: none; background: none; cursor: pointer; font-size: 18px; color: var(--ink-soft); line-height: 1; }
        .modal-cuerpo { padding: 20px; display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        .modal-cuerpo .completo { grid-column: 1 / -1; }
        .modal-cuerpo .form-row label { display: block; font-size: 12px; font-weight: 500; color: var(--ink-soft); margin-bottom: 4px; }
        .modal-cuerpo .form-row input,
        .modal-cuerpo .form-row select {
            width: 100%; box-sizing: border-box; padding: 8px 10px; border: 1px solid var(--line-soft);
            border-radius: 7px; font-family: inherit; font-size: 13.5px; background: var(--surface); color: var(--ink);
        }
        .modal-pie {
            padding: 16px 20px; border-top: 1px solid var(--line-soft); display: flex;
            justify-content: flex-end; gap: 10px; align-items: center;
        }
        .modal-mensaje { font-size: 12.5px; margin-right: auto; }
        .modal-mensaje.error { color: var(--rose); }
        .modal-mensaje.ok { color: var(--moss); }
        .modal-pie button { font-family: inherit; font-size: 13px; font-weight: 600; padding: 9px 16px; border-radius: 8px; cursor: pointer; }
        .modal-pie .btn-cancelar { border: 1px solid var(--line-soft); background: var(--surface); color: var(--ink-soft); }
        .modal-pie .btn-guardar { border: none; background: var(--teal-dark); color: #fff; }
        .modal-pie .btn-guardar:disabled { opacity: .6; cursor: default; }

        /* --- Modal cargar stock (Excel) --- */
        .modal-cuerpo.una-col { grid-template-columns: 1fr; }
        .dropzone {
            border: 1.5px dashed var(--line-soft); border-radius: 10px; padding: 22px 16px;
            text-align: center; color: var(--ink-soft); font-size: 13px; cursor: pointer;
        }
        .dropzone.arrastrando { border-color: var(--teal-dark); color: var(--ink); background: #FAFBFC; }
        .dropzone .nombre-archivo { font-weight: 600; color: var(--ink); margin-top: 4px; }
        .resultado-importacion { font-size: 12.5px; line-height: 1.6; }
        .resultado-importacion h4 { margin: 12px 0 4px; font-size: 12.5px; }
        .resultado-importacion .resumen-ok { color: var(--moss); font-weight: 600; }
        .resultado-importacion ul { margin: 0; padding-left: 18px; max-height: 140px; overflow-y: auto; }
        .resultado-importacion .mono { font-family: 'JetBrains Mono', monospace; font-size: 11.5px; }

        @media (max-width: 760px) {
            .unidad { grid-template-columns: 1fr; row-gap: 8px; }
            .unidad .estado-col, .unidad .acciones-col { justify-self: start; }
            .modal-cuerpo { grid-template-columns: 1fr; }
            .encabezado-pagina { flex-direction: column; }
        }
    </style>
</head>
<body>
<div class="app">
    <aside class="sidebar">
        <div class="marca">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" class="icono-enlace">
                <rect x="2.5" y="7" width="9" height="9" rx="2.5" stroke="currentColor" stroke-width="1.6"/>
                <rect x="12.5" y="7" width="9" height="9" rx="2.5" stroke="currentColor" stroke-width="1.6" opacity=".45"/>
                <path d="M11.3 11.5H12.7" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
            </svg>
            <span class="texto">Medical Life<small>Red de máquinas</small></span>
        </div>
        <?php require dirname(__DIR__, 2) . '/public/partials/menu.php'; ?>
    </aside>

    <main class="principal">
        <div class="encabezado-pagina">
            <div>
                <h1>Red de máquinas</h1>
                <p>Cada unidad agrupa una maestra con sus esclavas. Haz clic en una fila para ver el detalle completo, cargar stock y revisar el estado.</p>
            </div>
            <div class="acciones-encabezado">
                <button class="btn-cargar-stock" id="btn-cargar-stock">⇪ Cargar stock (Excel)</button>
                <button class="btn-nueva-maquina" id="btn-nueva-maquina">+ Nueva máquina</button>
            </div>
        </div>

        <div class="tira-stats" id="tira-stats">
            <div class="stat"><div class="num">—</div><div class="lbl">Unidades totales</div></div>
            <div class="stat acento-rose"><div class="num">—</div><div class="lbl">Requieren atención</div></div>
            <div class="stat acento-amber"><div class="num">—</div><div class="lbl">Nunca cargadas</div></div>
            <div class="stat acento-moss"><div class="num">—</div><div class="lbl">Al día</div></div>
        </div>

        <div class="barra">
            <input type="text" id="buscar" placeholder="Buscar por serie, código o ubicación (maestra o esclava)…">
            <select id="filtro-estado">
                <option value="">Cualquier estado</option>
                <option value="atencion">Requiere atención</option>
                <option value="nunca">Nunca cargada</option>
                <option value="ok">Al día</option>
            </select>
            <span class="contador" id="contador"></span>
        </div>

        <div class="card">
            <div id="lista-unidades" class="cargando">Cargando…</div>
            <div class="paginacion" id="paginacion" style="display:none;">
                <button id="btn-anterior">&larr; Anterior</button>
                <span id="texto-pagina"></span>
                <button id="btn-siguiente">Siguiente &rarr;</button>
            </div>
        </div>
    </main>
</div>

<!-- Modal crear/editar máquina -->
<div class="overlay-modal" id="overlay-modal">
    <div class="modal">
        <div class="modal-encabezado">
            <h2 id="modal-titulo">Nueva máquina</h2>
            <button class="modal-cerrar" id="modal-cerrar">&times;</button>
        </div>
        <form id="form-maquina">
            <div class="modal-cuerpo">
                <input type="hidden" id="f-id_maquina" value="">

                <div class="form-row">
                    <label>Rol</label>
                    <select id="f-rol_maquina">
                        <option value="1">Maestra</option>
                        <option value="2">Esclava</option>
                    </select>
                </div>
                <div class="form-row" id="fila-padre" style="display:none;">
                    <label>Máquina maestra (padre)</label>
                    <select id="f-id_maquina_padre"></select>
                </div>

                <div class="form-row">
                    <label>Número de serie *</label>
                    <input type="text" id="f-numero_serie" required>
                </div>
                <div class="form-row">
                    <label>Código interno *</label>
                    <input type="text" id="f-codigo_interno" required>
                </div>

                <div class="form-row completo">
                    <label>Ubicación</label>
                    <input type="text" id="f-nombre_ubicacion">
                </div>
                <div class="form-row completo">
                    <label>Dirección</label>
                    <input type="text" id="f-direccion">
                </div>

                <div class="form-row">
                    <label>Latitud</label>
                    <input type="text" id="f-latitud" inputmode="decimal">
                </div>
                <div class="form-row">
                    <label>Longitud</label>
                    <input type="text" id="f-longitud" inputmode="decimal">
                </div>

                <div class="form-row">
                    <label>Número de carriles</label>
                    <input type="number" id="f-numero_carriles" min="0" placeholder="Default: 69 maestra / 58 esclava">
                </div>
                <div class="form-row">
                    <label>Tipo de máquina</label>
                    <input type="text" id="f-tipo_maquina">
                </div>

                <div class="form-row">
                    <label>Fecha de instalación</label>
                    <input type="date" id="f-fecha_instalacion">
                </div>
                <div class="form-row">
                    <label>Estatus</label>
                    <select id="f-id_estatus">
                        <option value="1">Activa</option>
                        <option value="2">Mantenimiento</option>
                        <option value="3">Fuera de servicio</option>
                    </select>
                </div>
            </div>
            <div class="modal-pie">
                <span class="modal-mensaje" id="modal-mensaje"></span>
                <button type="button" class="btn-cancelar" id="btn-cancelar">Cancelar</button>
                <button type="submit" class="btn-guardar" id="btn-guardar">Guardar</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal cargar stock (Excel) -->
<div class="overlay-modal" id="overlay-stock">
    <div class="modal">
        <div class="modal-encabezado">
            <h2>Cargar stock desde Excel</h2>
            <button class="modal-cerrar" id="stock-cerrar">&times;</button>
        </div>
        <form id="form-stock">
            <div class="modal-cuerpo una-col">
                <div class="form-row">
                    <label>Máquina principal (maestra)</label>
                    <select id="stock-id_maquina" required></select>
                </div>
                <div class="form-row">
                    <label>Archivo de planimetría (.xlsx / .xls)</label>
                    <div class="dropzone" id="stock-dropzone">
                        <div>Arrastra el archivo aquí o haz clic para elegirlo</div>
                        <div class="nombre-archivo" id="stock-nombre-archivo"></div>
                    </div>
                    <input type="file" id="stock-archivo" accept=".xlsx,.xls" style="display:none;" required>
                </div>
                <div class="resultado-importacion" id="stock-resultado"></div>
            </div>
            <div class="modal-pie">
                <span class="modal-mensaje" id="stock-mensaje"></span>
                <button type="button" class="btn-cancelar" id="stock-cancelar">Cerrar</button>
                <button type="submit" class="btn-guardar" id="stock-guardar">Importar</button>
            </div>
        </form>
    </div>
</div>

<script src="assets/js/comun.js?v=20260811-3"></script>
<script>
    const PAGE_SIZE = 50;
    let pagina = 1;
    let total = 0;
    let debounceTimer = null;
    let maestrasCache = []; // para poblar el <select> de "máquina padre" y el modal de stock

    function chipEsclava(e) {
        return `<span class="chip-unidad es-esclava">
            <span class="rol-punto"></span>
            <span class="mono">${escapeHtml(e.numero_serie)}</span>
        </span>`;
    }

    function filaUnidad(u) {
        const dias = u.esclavas.map(e => e.dias_sin_mantenimiento === null || e.dias_sin_mantenimiento === undefined ? null : Number(e.dias_sin_mantenimiento));
        dias.push(u.dias_sin_mantenimiento === null || u.dias_sin_mantenimiento === undefined ? null : Number(u.dias_sin_mantenimiento));
        const peor = peorDias(dias);

        const colEsclavas = u.esclavas.length
            ? u.esclavas.map(chipEsclava).join('')
            : '<span class="sin-esclava">Sin esclavas asociadas</span>';

        return `<div class="unidad" data-id="${u.id_maquina}">
            <div class="maestra-col ir-detalle" data-id="${u.id_maquina}">
                <span class="rol-punto"></span>
                <div class="info">
                    <div class="serie mono">${escapeHtml(u.numero_serie)}</div>
                    <div class="ubic">${escapeHtml(u.nombre_ubicacion) || 'Sin ubicación'}</div>
                </div>
            </div>
            <div class="esclavas-col">${colEsclavas}</div>
            <div class="fecha-col ir-detalle" data-id="${u.id_maquina}">${peor === null ? 'Nunca cargada' : `Hace ${peor} día${peor === 1 ? '' : 's'}`}</div>
            <div class="estado-col ir-detalle" data-id="${u.id_maquina}">${badgeMantenimiento(peor)}</div>
            <div class="acciones-col">
                <button type="button" class="btn-editar" data-id="${u.id_maquina}" title="Editar">✎</button>
                <button type="button" class="btn-baja" data-id="${u.id_maquina}" title="Dar de baja">🗑</button>
            </div>
        </div>`;
    }

    async function cargarStats() {
        try {
            const [rAten, rOk, rTodas, rNunca] = await Promise.all([
                obtenerJSON('api/maquinas.php?agrupado=1&estado=atencion&page_size=1'),
                obtenerJSON('api/maquinas.php?agrupado=1&estado=ok&page_size=1'),
                obtenerJSON('api/maquinas.php?agrupado=1&page_size=1'),
                obtenerJSON('api/maquinas.php?agrupado=1&estado=nunca&page_size=1'),
            ]);
            const stats = document.querySelectorAll('#tira-stats .stat .num');
            stats[0].textContent = rTodas.meta.total;
            stats[1].textContent = rAten.meta.total;
            stats[3].textContent = rOk.meta.total;
            stats[2].textContent = rNunca.meta.total;
        } catch (e) { /* silencioso: la tira de stats no bloquea el listado */ }
    }

    async function cargar() {
        const cont = document.getElementById('lista-unidades');
        const paginacion = document.getElementById('paginacion');
        cont.className = 'cargando';
        cont.textContent = 'Cargando…';
        paginacion.style.display = 'none';

        const q = document.getElementById('buscar').value.trim();
        const estado = document.getElementById('filtro-estado').value;

        const params = new URLSearchParams({ agrupado: '1', page: pagina, page_size: PAGE_SIZE });
        if (q) params.set('q', q);
        if (estado) params.set('estado', estado);

        try {
            const json = await obtenerJSON(`api/maquinas.php?${params}`);
            total = json.meta.total;
            document.getElementById('contador').textContent = `${total} unidad${total === 1 ? '' : 'es'}`;

            // Guardamos las maestras de esta página para el <select> del modal "esclava"
            maestrasCache = json.data.map(u => ({ id_maquina: u.id_maquina, numero_serie: u.numero_serie }));

            if (json.data.length === 0) {
                cont.className = '';
                cont.innerHTML = '<p class="vacio">No hay unidades que coincidan con la búsqueda.</p>';
                return;
            }

            cont.className = 'lista-unidades';
            cont.innerHTML = json.data.map(filaUnidad).join('');

            cont.querySelectorAll('.ir-detalle').forEach(el => {
                el.addEventListener('click', () => { window.location.href = `maquina.php?id=${el.dataset.id}`; });
            });
            cont.querySelectorAll('.btn-editar').forEach(btn => {
                btn.addEventListener('click', (ev) => { ev.stopPropagation(); abrirModalEditar(parseInt(btn.dataset.id, 10)); });
            });
            cont.querySelectorAll('.btn-baja').forEach(btn => {
                btn.addEventListener('click', (ev) => { ev.stopPropagation(); darDeBaja(parseInt(btn.dataset.id, 10)); });
            });

            const totalPaginas = Math.max(1, Math.ceil(total / PAGE_SIZE));
            document.getElementById('texto-pagina').textContent = `Página ${pagina} de ${totalPaginas}`;
            document.getElementById('btn-anterior').disabled = pagina <= 1;
            document.getElementById('btn-siguiente').disabled = pagina >= totalPaginas;
            paginacion.style.display = 'flex';
        } catch (e) {
            cont.className = '';
            cont.innerHTML = '<p class="vacio">No se pudo conectar con el servidor.</p>';
        }
    }

    function reiniciarYBuscar() { pagina = 1; cargar(); }

    document.getElementById('buscar').addEventListener('input', () => {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(reiniciarYBuscar, 350);
    });
    document.getElementById('filtro-estado').addEventListener('change', reiniciarYBuscar);
    document.getElementById('btn-anterior').addEventListener('click', () => { pagina--; cargar(); });
    document.getElementById('btn-siguiente').addEventListener('click', () => { pagina++; cargar(); });

    // ------------------------------------------------------------------
    // Modal crear / editar
    // ------------------------------------------------------------------
    const overlay = document.getElementById('overlay-modal');
    const form = document.getElementById('form-maquina');
    const mensaje = document.getElementById('modal-mensaje');
    const selectRol = document.getElementById('f-rol_maquina');
    const filaPadre = document.getElementById('fila-padre');
    const selectPadre = document.getElementById('f-id_maquina_padre');

    function poblarSelectPadre(idExcluir) {
        selectPadre.innerHTML = maestrasCache
            .filter(m => m.id_maquina !== idExcluir)
            .map(m => `<option value="${m.id_maquina}">${escapeHtml(m.numero_serie)}</option>`)
            .join('');
    }

    function actualizarVisibilidadPadre() {
        filaPadre.style.display = selectRol.value === '2' ? '' : 'none';
    }
    selectRol.addEventListener('change', actualizarVisibilidadPadre);

    function limpiarFormulario() {
        form.reset();
        document.getElementById('f-id_maquina').value = '';
        document.getElementById('f-id_estatus').value = '1';
        mensaje.textContent = '';
        mensaje.className = 'modal-mensaje';
        actualizarVisibilidadPadre();
    }

    function abrirModalCrear() {
        limpiarFormulario();
        document.getElementById('modal-titulo').textContent = 'Nueva máquina';
        poblarSelectPadre(null);
        overlay.classList.add('abierto');
    }

    async function abrirModalEditar(idMaquina) {
        limpiarFormulario();
        document.getElementById('modal-titulo').textContent = 'Editar máquina';
        overlay.classList.add('abierto');
        mensaje.textContent = 'Cargando datos…';

        try {
            const json = await obtenerJSON(`api/maquina.php?id_maquina=${idMaquina}`);
            const m = json.data;

            poblarSelectPadre(idMaquina);

            document.getElementById('f-id_maquina').value = m.id_maquina;
            document.getElementById('f-numero_serie').value = m.numero_serie || '';
            document.getElementById('f-codigo_interno').value = m.codigo_interno || '';
            document.getElementById('f-nombre_ubicacion').value = m.nombre_ubicacion || '';
            document.getElementById('f-direccion').value = m.direccion || '';
            document.getElementById('f-latitud').value = m.latitud ?? '';
            document.getElementById('f-longitud').value = m.longitud ?? '';
            document.getElementById('f-numero_carriles').value = m.numero_carriles ?? '';
            document.getElementById('f-tipo_maquina').value = m.tipo_maquina || '';
            document.getElementById('f-fecha_instalacion').value = m.fecha_instalacion ? m.fecha_instalacion.substring(0, 10) : '';
            document.getElementById('f-id_estatus').value = m.id_estatus ?? '1';
            selectRol.value = String(m.rol_maquina ?? '1');
            if (m.id_maquina_padre) {
                selectPadre.value = String(m.id_maquina_padre);
            }
            actualizarVisibilidadPadre();
            mensaje.textContent = '';
        } catch (e) {
            mensaje.textContent = 'No se pudo cargar la máquina: ' + e.message;
            mensaje.className = 'modal-mensaje error';
        }
    }

    function cerrarModal() { overlay.classList.remove('abierto'); }

    document.getElementById('btn-nueva-maquina').addEventListener('click', abrirModalCrear);
    document.getElementById('modal-cerrar').addEventListener('click', cerrarModal);
    document.getElementById('btn-cancelar').addEventListener('click', cerrarModal);
    overlay.addEventListener('click', (ev) => { if (ev.target === overlay) cerrarModal(); });

    form.addEventListener('submit', async (ev) => {
        ev.preventDefault();
        const btnGuardar = document.getElementById('btn-guardar');
        btnGuardar.disabled = true;
        mensaje.textContent = '';
        mensaje.className = 'modal-mensaje';

        const idMaquina = document.getElementById('f-id_maquina').value;
        const payload = {
            id_maquina: idMaquina ? parseInt(idMaquina, 10) : null,
            numero_serie: document.getElementById('f-numero_serie').value.trim(),
            codigo_interno: document.getElementById('f-codigo_interno').value.trim(),
            nombre_ubicacion: document.getElementById('f-nombre_ubicacion').value.trim(),
            direccion: document.getElementById('f-direccion').value.trim(),
            latitud: document.getElementById('f-latitud').value.trim(),
            longitud: document.getElementById('f-longitud').value.trim(),
            rol_maquina: parseInt(selectRol.value, 10),
            id_maquina_padre: selectRol.value === '2' ? parseInt(selectPadre.value, 10) || null : null,
            numero_carriles: document.getElementById('f-numero_carriles').value || null,
            tipo_maquina: document.getElementById('f-tipo_maquina').value.trim(),
            fecha_instalacion: document.getElementById('f-fecha_instalacion').value || null,
        };

        try {
            const res = await fetch('api/maquina_guardar.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            });
            const json = await res.json();
            if (!json.ok) {
                mensaje.textContent = json.error || 'No se pudo guardar.';
                mensaje.className = 'modal-mensaje error';
                return;
            }
            mensaje.textContent = json.aviso ? json.aviso : `Máquina ${json.data.accion}.`;
            mensaje.className = 'modal-mensaje ok';
            await cargar();
            setTimeout(cerrarModal, json.aviso ? 2200 : 700);
        } catch (e) {
            mensaje.textContent = 'No se pudo conectar con el servidor.';
            mensaje.className = 'modal-mensaje error';
        } finally {
            btnGuardar.disabled = false;
        }
    });

    // ------------------------------------------------------------------
    // Dar de baja (borrado lógico)
    // ------------------------------------------------------------------
    async function darDeBaja(idMaquina) {
        const confirmado = await mlConfirm(
            'Quedara marcada como fuera de servicio y se ocultara del listado normal. Si es maestra, tambien se daran de baja sus esclavas.',
            'Dar de baja maquina',
            'Dar de baja'
        );
        if (!confirmado) return;

        try {
            const res = await fetch('api/maquina_eliminar.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id_maquina: idMaquina }),
            });
            const json = await res.json();
            if (!json.ok) {
                await mlAlert(json.error || 'No se pudo dar de baja la maquina.');
                return;
            }
            await mlAlert(`Maquina dada de baja. Registros afectados: ${json.data?.maquinas_afectadas || 1}.`, 'success', 'Listo');
            await cargar();
            await cargarStats();
        } catch (e) {
            await mlAlert('No se pudo conectar con el servidor.');
        }
    }

    // ------------------------------------------------------------------
    // Modal "Cargar stock" (Excel)
    // ------------------------------------------------------------------
    const overlayStock = document.getElementById('overlay-stock');
    const formStock = document.getElementById('form-stock');
    const stockMensaje = document.getElementById('stock-mensaje');
    const stockResultado = document.getElementById('stock-resultado');
    const stockSelectMaquina = document.getElementById('stock-id_maquina');
    const stockDropzone = document.getElementById('stock-dropzone');
    const stockInputArchivo = document.getElementById('stock-archivo');
    const stockNombreArchivo = document.getElementById('stock-nombre-archivo');
    const stockBtnGuardar = document.getElementById('stock-guardar');

    // Trae TODAS las máquinas maestras (no solo la página actual) para el <select>.
    async function poblarSelectMaquinasStock() {
        stockSelectMaquina.innerHTML = '<option value="">Cargando máquinas…</option>';
        try {
            const json = await obtenerJSON('api/maquinas.php?agrupado=1&page_size=1000');
            const maestras = json.data.map(u => ({ id_maquina: u.id_maquina, numero_serie: u.numero_serie, ubicacion: u.nombre_ubicacion }));
            stockSelectMaquina.innerHTML = maestras.length
                ? maestras.map(m => `<option value="${escapeHtml(m.numero_serie)}">${escapeHtml(m.numero_serie)}${m.ubicacion ? ' — ' + escapeHtml(m.ubicacion) : ''}</option>`).join('')
                : '<option value="">No hay máquinas registradas</option>';
        } catch (e) {
            stockSelectMaquina.innerHTML = '<option value="">No se pudo cargar el listado</option>';
        }
    }

    function limpiarFormularioStock() {
        formStock.reset();
        stockNombreArchivo.textContent = '';
        stockResultado.innerHTML = '';
        stockMensaje.textContent = '';
        stockMensaje.className = 'modal-mensaje';
        stockInputArchivo.value = '';
    }

    function abrirModalStock() {
        limpiarFormularioStock();
        poblarSelectMaquinasStock();
        overlayStock.classList.add('abierto');
    }
    function cerrarModalStock() { overlayStock.classList.remove('abierto'); }

    document.getElementById('btn-cargar-stock').addEventListener('click', abrirModalStock);
    document.getElementById('stock-cerrar').addEventListener('click', cerrarModalStock);
    document.getElementById('stock-cancelar').addEventListener('click', cerrarModalStock);
    overlayStock.addEventListener('click', (ev) => { if (ev.target === overlayStock) cerrarModalStock(); });

    stockDropzone.addEventListener('click', () => stockInputArchivo.click());
    stockDropzone.addEventListener('dragover', (ev) => { ev.preventDefault(); stockDropzone.classList.add('arrastrando'); });
    stockDropzone.addEventListener('dragleave', () => stockDropzone.classList.remove('arrastrando'));
    stockDropzone.addEventListener('drop', (ev) => {
        ev.preventDefault();
        stockDropzone.classList.remove('arrastrando');
        if (ev.dataTransfer.files.length) {
            stockInputArchivo.files = ev.dataTransfer.files;
            stockNombreArchivo.textContent = ev.dataTransfer.files[0].name;
        }
    });
    stockInputArchivo.addEventListener('change', () => {
        stockNombreArchivo.textContent = stockInputArchivo.files[0]?.name || '';
    });

    function renderResultadoStock(data) {
        const partes = [];
        partes.push(`<div class="resumen-ok">Importación completa: ${data.filas_leidas} fila(s) leídas del Excel.</div>`);
        partes.push(`<div>id_reabastecimiento: <span class="mono">${data.id_reabastecimiento}</span></div>`);
        partes.push(`<div>Máquina principal: <span class="mono">id_maquina=${data.id_maquina_principal}</span>${data.id_maquina_esclava ? ` · Esclava: <span class="mono">id_maquina=${data.id_maquina_esclava}</span>` : ' · Sin esclava configurada'}</div>`);

        const bloque = (titulo, items, render) => {
            if (!items || !items.length) return;
            partes.push(`<h4>${titulo} (${items.length})</h4><ul>${items.map(render).join('')}</ul>`);
        };
        bloque('Carriles sin match', data.no_asignados, n => `<li>código=${escapeHtml(n.codigo_barras || '')} ubicación=${escapeHtml(n.ubicacion_carril || '')}</li>`);
        bloque('Filas sin fecha de caducidad válida', data.sin_fecha, f => `<li>código=${escapeHtml(f.codigo_barras || '')}</li>`);
        bloque('Lotes sin carril match', data.sin_carril, c => `<li>producto_id=${c.id_producto} carril=${c.numero_carril}</li>`);
        bloque('Filas con error', data.errores, e => `<li>código=${escapeHtml(e.codigo_barras || '')} — ${escapeHtml(e.error_procesamiento || '')}</li>`);

        stockResultado.innerHTML = partes.join('');
    }

    formStock.addEventListener('submit', async (ev) => {
        ev.preventDefault();

        if (!stockInputArchivo.files.length) {
            stockMensaje.textContent = 'Elige un archivo Excel.';
            stockMensaje.className = 'modal-mensaje error';
            return;
        }
        if (!stockSelectMaquina.value) {
            stockMensaje.textContent = 'Elige la máquina principal.';
            stockMensaje.className = 'modal-mensaje error';
            return;
        }

        stockBtnGuardar.disabled = true;
        stockMensaje.textContent = 'Importando… esto puede tardar unos segundos.';
        stockMensaje.className = 'modal-mensaje';
        stockResultado.innerHTML = '';

        const fd = new FormData();
        fd.append('archivo', stockInputArchivo.files[0]);
        fd.append('numero_serie', stockSelectMaquina.value);

        try {
            const res = await fetch('api/maquina_importar_stock.php', { method: 'POST', body: fd });
            const json = await res.json();

            if (!json.ok) {
                stockMensaje.textContent = json.error || 'No se pudo importar el archivo.';
                stockMensaje.className = 'modal-mensaje error';
                return;
            }

            stockMensaje.textContent = '';
            renderResultadoStock(json.data);
            cargar();
            cargarStats();
        } catch (e) {
            stockMensaje.textContent = 'No se pudo conectar con el servidor.';
            stockMensaje.className = 'modal-mensaje error';
        } finally {
            stockBtnGuardar.disabled = false;
        }
    });

    cargarStats();
    cargar();
</script>
</body>
</html>
