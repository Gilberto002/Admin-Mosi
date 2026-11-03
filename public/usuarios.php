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
    <title>MDM - Usuarios</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/estilo.css?v=20260914-2">
    <style>
        /* ================================================================
           Usuarios — mismo sistema visual que dashboard.php
           Paleta: carbón #0A0F14, texto #EEF4F7, acento petróleo #159485/#2CD9BE
           ================================================================ */
        body.tema-oscuro {
            --bg: #0A0F14;
            --bg-elevada: #121A21;
            --bg-elevada-2: #18222B;
            --bg-hover: #1E2830;
            --linea: #243039;
            --linea-suave: #1C2630;
            --texto: #EEF4F7;
            --texto-suave: #9FB2BC;
            --texto-tenue: #5E707B;

            --petroleo: #2CD9BE;
            --petroleo-fuerte: #159485;
            --petroleo-wash: rgba(44, 217, 190, .13);

            --ambar: #E4AC4F;
            --ambar-wash: rgba(228, 172, 79, .15);

            --critico: #F1707E;
            --critico-wash: rgba(241, 112, 126, .15);

            background:
                radial-gradient(1100px 550px at 12% -12%, rgba(44, 217, 190, .06), transparent 60%),
                radial-gradient(900px 500px at 100% 0%, rgba(228, 172, 79, .03), transparent 55%),
                var(--bg);
            color: var(--texto);
        }
        body.tema-oscuro .sidebar { background: var(--bg-elevada); border-right: 1px solid var(--linea); }
        body.tema-oscuro .marca .icono-enlace { color: var(--petroleo); }
        body.tema-oscuro .marca .texto small { color: var(--texto-tenue); }
        body.tema-oscuro .nav-lateral a { color: var(--texto-suave); }
        body.tema-oscuro .nav-lateral a:hover { background: var(--bg-hover); color: var(--texto); }
        body.tema-oscuro .nav-lateral a.activa { background: var(--petroleo-wash); color: var(--petroleo); }
        body.tema-oscuro h1, body.tema-oscuro h2 { color: var(--texto); }
        body.tema-oscuro .card {
            background: var(--bg-elevada); border: 1px solid var(--linea); border-radius: var(--radius-lg, 14px);
            box-shadow: 0 1px 0 rgba(255,255,255,.02) inset, 0 14px 26px -20px rgba(0,0,0,.65);
        }
        body.tema-oscuro table { width: 100%; border-collapse: collapse; font-size: 13px; }
        body.tema-oscuro th, body.tema-oscuro td { text-align: left; padding: 11px 20px; border-bottom: 1px solid var(--linea-suave); }
        body.tema-oscuro th { background: transparent; color: var(--texto-tenue); font-weight: 600; text-transform: uppercase; font-size: 10px; letter-spacing: .06em; }
        body.tema-oscuro tbody tr:last-child td { border-bottom: none; }
        body.tema-oscuro tbody tr:hover { background: var(--bg-hover); }
        body.tema-oscuro .mono { font-family: 'JetBrains Mono', monospace; }
        body.tema-oscuro .subtle-note { color: var(--texto-tenue); }
        body.tema-oscuro .vacio, body.tema-oscuro .cargando { color: var(--texto-tenue); font-style: italic; padding: 40px 14px; text-align: center; }

        body.tema-oscuro .badge { display: inline-flex; align-items: center; gap: 5px; padding: 3px 10px; border-radius: 999px; font-size: 11px; font-weight: 600; white-space: nowrap; }
        body.tema-oscuro .badge::before { content: ""; width: 6px; height: 6px; border-radius: 50%; background: currentColor; }
        body.tema-oscuro .badge-ok { background: var(--petroleo-wash); color: var(--petroleo); }
        body.tema-oscuro .badge-neutro { background: rgba(255,255,255,.05); color: var(--texto-tenue); }

        /* -------- Encabezado -------- */
        .encabezado-pagina { display: flex; justify-content: space-between; gap: 16px; align-items: flex-start; margin-bottom: 20px; }
        .encabezado-pagina__etiqueta {
            display: inline-flex; align-items: center; gap: 7px; font-size: 11px; letter-spacing: .08em;
            text-transform: uppercase; color: var(--petroleo); font-weight: 700; margin-bottom: 9px;
        }
        .punto-vivo { width: 7px; height: 7px; border-radius: 50%; background: var(--petroleo); }
        .encabezado-pagina h1 { font-size: 22px; margin: 0 0 4px; letter-spacing: -.01em; }
        .encabezado-pagina p { margin: 0; color: var(--texto-suave); font-size: 13.5px; max-width: 52ch; }

        .btn {
            border: 1px solid var(--linea); background: var(--bg); color: var(--texto); border-radius: var(--radius-sm, 8px);
            padding: 9px 16px; font: inherit; font-size: 13px; font-weight: 600; cursor: pointer;
            transition: border-color .15s ease, background .15s ease, transform .1s ease;
        }
        .btn:hover { border-color: var(--texto-tenue); }
        .btn:active { transform: scale(.97); }
        .btn.primaria { background: var(--petroleo); color: #06201F; border: none; }
        .btn.primaria:hover { background: #45E6CD; }
        .acciones-tabla { display: flex; gap: 6px; }
        .btn-editar { padding: 6px 13px; font-size: 12.5px; }

        /* -------- Resumen: tarjetas estilo bento -------- */
        .usuarios-resumen { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; margin-bottom: 16px; }
        .tarjeta-stat {
            display: flex; align-items: center; gap: 14px;
            background: var(--bg-elevada); border: 1px solid var(--linea); border-radius: var(--radius-lg, 14px);
            padding: 18px 20px;
            box-shadow: 0 1px 0 rgba(255,255,255,.02) inset, 0 14px 26px -20px rgba(0,0,0,.65);
        }
        .tarjeta-stat__icono {
            width: 38px; height: 38px; border-radius: 10px; display: flex; align-items: center; justify-content: center;
            flex-shrink: 0; background: rgba(255,255,255,.04); border: 1px solid var(--linea-suave);
        }
        .tarjeta-stat .lbl { font-size: 12px; color: var(--texto-tenue); text-transform: uppercase; letter-spacing: .04em; font-weight: 600; }
        .tarjeta-stat .num { font-family: 'Space Grotesk', sans-serif; font-size: 26px; font-weight: 700; line-height: 1.3; color: var(--texto); }
        .tarjeta-stat .subtle-note { font-size: 12px; margin-top: 1px; }

        .panel-cabecera-osc {
            display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;
            padding: 16px 20px; border-bottom: 1px solid var(--linea-suave);
        }
        .panel-cabecera-osc h2 { font-size: 14.5px; margin: 0; }
        .panel-cabecera-osc .subtle-note { font-size: 12px; }

        /* -------- Modal -------- */
        .overlay-modal {
            position: fixed; inset: 0; background: rgba(6,9,12,.72); backdrop-filter: blur(2px);
            display: none; align-items: flex-start; justify-content: center; padding: 40px 16px; overflow-y: auto; z-index: 50;
        }
        .overlay-modal.abierto { display: flex; animation: aparecer-overlay .15s ease; }
        @keyframes aparecer-overlay { from { opacity: 0; } to { opacity: 1; } }
        .modal {
            background: var(--bg-elevada); border: 1px solid var(--linea); border-radius: var(--radius-lg, 14px);
            width: 100%; max-width: 780px; box-shadow: 0 30px 60px -25px rgba(0,0,0,.7);
            animation: aparecer-modal .18s ease;
        }
        @keyframes aparecer-modal { from { opacity: 0; transform: translateY(6px) scale(.99); } to { opacity: 1; transform: none; } }
        .modal-encabezado, .modal-pie {
            padding: 16px 22px; border-bottom: 1px solid var(--linea-suave);
            display: flex; justify-content: space-between; gap: 12px; align-items: center;
        }
        .modal-encabezado h2 { font-size: 16px; }
        .modal-pie { border-top: 1px solid var(--linea-suave); border-bottom: none; justify-content: flex-end; gap: 10px; }
        .modal-cuerpo { padding: 22px; display: grid; grid-template-columns: 1fr 1fr; gap: 16px 14px; }
        .modal-cuerpo .completo { grid-column: 1 / -1; }
        .modal-seccion-titulo {
            grid-column: 1 / -1; font-size: 11px; text-transform: uppercase; letter-spacing: .06em;
            color: var(--texto-tenue); font-weight: 700; margin: 6px 0 -4px; padding-top: 10px; border-top: 1px solid var(--linea-suave);
        }
        .modal-cuerpo .modal-seccion-titulo:first-child { border-top: none; padding-top: 0; margin-top: 0; }

        .form-row { display: flex; flex-direction: column; gap: 6px; }
        .form-row label { font-size: 12.5px; font-weight: 600; color: var(--texto-suave); }
        .form-row label span { font-weight: 400; color: var(--texto-tenue); }

        body.tema-oscuro input, body.tema-oscuro select {
            background: var(--bg); border: 1px solid var(--linea); color: var(--texto);
            border-radius: var(--radius-sm, 8px); padding: 9px 11px; width: 100%; font: inherit; font-size: 13px;
            transition: border-color .15s ease, box-shadow .15s ease;
        }
        body.tema-oscuro input:focus, body.tema-oscuro select:focus {
            outline: none; border-color: var(--petroleo); box-shadow: 0 0 0 3px var(--petroleo-wash);
        }

        .maquinas-grid {
            display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap: 8px;
            max-height: 260px; overflow: auto; padding: 10px;
            background: var(--bg); border: 1px solid var(--linea); border-radius: var(--radius-sm, 8px);
        }
        .check-maquina {
            display: flex; align-items: center; gap: 8px; color: var(--texto-suave); font-size: 12px;
            padding: 7px 9px; border-radius: 7px; border: 1px solid transparent; cursor: pointer;
            transition: background .12s ease, border-color .12s ease;
        }
        .check-maquina:hover { background: var(--bg-hover); }
        .check-maquina:has(input:checked) { background: var(--petroleo-wash); border-color: rgba(44,217,190,.3); color: var(--texto); }
        .check-maquina input { width: auto; accent-color: var(--petroleo); }

        .asignacion-toolbar { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; }
        .asignacion-toolbar input { flex: 1; }
        .pill-count {
            color: var(--petroleo); font-size: 11.5px; font-weight: 700; white-space: nowrap;
            background: var(--petroleo-wash); padding: 5px 11px; border-radius: 999px;
        }
        .modal-mensaje { margin-right: auto; font-size: 12.5px; color: var(--texto-suave); }
        .modal-mensaje.error { color: var(--critico); font-weight: 600; }

        @media (max-width: 760px) {
            .modal-cuerpo { grid-template-columns: 1fr; }
            .usuarios-resumen { grid-template-columns: 1fr; }
            .encabezado-pagina { flex-wrap: wrap; }
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
                <div class="encabezado-pagina__etiqueta"><span class="punto-vivo"></span>Control de acceso</div>
                <h1>Usuarios</h1>
                <p>Administra clientes y colaboradores, y define exactamente qué máquinas puede consultar cada uno.</p>
            </div>
            <button class="btn primaria" id="btn-nuevo">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" style="vertical-align:-2px; margin-right:6px;"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                Nuevo usuario
            </button>
        </div>

        <div class="usuarios-resumen">
            <div class="tarjeta-stat">
                <div class="tarjeta-stat__icono" style="color:var(--petroleo);">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M5 12.5l4.2 4.2L19 6.8" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </div>
                <div>
                    <div class="lbl">Usuarios activos</div>
                    <div class="num" id="stat-activos">—</div>
                    <div class="subtle-note">Con acceso permitido</div>
                </div>
            </div>
            <div class="tarjeta-stat">
                <div class="tarjeta-stat__icono" style="color:var(--texto-suave);">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><circle cx="9" cy="8" r="3.2" stroke="currentColor" stroke-width="1.6"/><path d="M3.5 19c0-3.3 2.6-5.5 5.5-5.5s5.5 2.2 5.5 5.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><path d="M15.5 5.2a3.2 3.2 0 0 1 0 6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><path d="M15 13.6c2.6.4 4.5 2.4 4.5 5.4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                </div>
                <div>
                    <div class="lbl">Operativos y técnicos</div>
                    <div class="num" id="stat-colaboradores">—</div>
                    <div class="subtle-note">Acceso por máquinas</div>
                </div>
            </div>
            <div class="tarjeta-stat">
                <div class="tarjeta-stat__icono" style="color:var(--ambar);">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M12 3.5l7 2.6v5.4c0 4.6-3 7.9-7 9-4-1.1-7-4.4-7-9V6.1l7-2.6Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg>
                </div>
                <div>
                    <div class="lbl">Administradores</div>
                    <div class="num" id="stat-admins">—</div>
                    <div class="subtle-note">Acceso completo</div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="panel-cabecera-osc">
                <h2>Todos los usuarios</h2>
                <span class="subtle-note">Clic en "Editar" para modificar rol, estatus o máquinas asignadas</span>
            </div>
            <div id="tabla-usuarios" class="cargando">Cargando...</div>
        </div>
    </main>
</div>

<div class="overlay-modal" id="overlay">
    <form class="modal" id="form-usuario">
        <div class="modal-encabezado">
            <h2 id="modal-titulo">Nuevo usuario</h2>
            <button type="button" class="btn" id="btn-cerrar">Cerrar</button>
        </div>
        <div class="modal-cuerpo">
            <input type="hidden" id="id_usuario">

            <div class="modal-seccion-titulo">Datos personales</div>
            <div class="form-row"><label>Nombre *</label><input id="nombre" required></div>
            <div class="form-row"><label>Apellido *</label><input id="apellido" required></div>
            <div class="form-row completo"><label>Correo electrónico *</label><input id="email" type="email" required></div>

            <div class="modal-seccion-titulo">Acceso</div>
            <div class="form-row"><label>Contraseña <span id="pass-help">(requerida al crear)</span></label><input id="password" type="password" minlength="8"></div>
            <div class="form-row"><label>Rol</label><select id="rol"><option value="cliente">Cliente</option><option value="colaborador">Colaborador</option><option value="tecnico_instalador">Técnico instalador (puede activar máquinas)</option><option value="tecnico_soporte">Técnico soporte</option><option value="admin">Administrador (puede activar máquinas)</option></select></div>
            <div class="form-row"><label>Estatus</label><select id="activo"><option value="1">Activo</option><option value="0">Inactivo</option></select></div>

            <div class="form-row completo" id="bloque-maquinas">
                <label>Máquinas visibles</label>
                <div class="asignacion-toolbar">
                    <input id="buscar-maquina" type="text" placeholder="Filtrar máquinas por serie o ubicación">
                    <span class="pill-count" id="conteo-maquinas">0 seleccionadas</span>
                </div>
                <div class="maquinas-grid" id="maquinas-grid"></div>
            </div>
        </div>
        <div class="modal-pie">
            <span class="modal-mensaje" id="mensaje"></span>
            <button type="button" class="btn" id="btn-cancelar">Cancelar</button>
            <button type="submit" class="btn primaria">Guardar</button>
        </div>
    </form>
</div>

<script src="assets/js/comun.js?v=20260912-1"></script>
<script>
let maquinas = [];
let seleccionActual = [];

document.getElementById('rol').querySelector('option[value="tecnico_instalador"]')?.insertAdjacentHTML(
    'beforebegin',
    '<option value="medico">Médico</option><option value="psicologo">Psicólogo</option><option value="nutriologo">Nutriólogo</option>'
);

async function cargarMaquinas() {
    const json = await obtenerJSON('api/maquinas.php?agrupado=1&page_size=5000');
    maquinas = json.data;
}

async function cargarUsuarios() {
    const cont = document.getElementById('tabla-usuarios');
    cont.className = 'cargando';
    cont.textContent = 'Cargando...';
    try {
        const json = await obtenerJSON('api/usuarios.php');
        document.getElementById('stat-activos').textContent = json.data.filter(u => Number(u.activo)).length;
        document.getElementById('stat-colaboradores').textContent = json.data.filter(u => u.rol !== 'admin').length;
        document.getElementById('stat-admins').textContent = json.data.filter(u => u.rol === 'admin').length;
        if (!json.data.length) {
            cont.className = '';
            cont.innerHTML = '<p class="vacio">No hay usuarios registrados.</p>';
            return;
        }
        cont.className = '';
        cont.innerHTML = `<table><thead><tr><th>Usuario</th><th>Correo</th><th>Rol</th><th>Maquinas</th><th>Estatus</th><th></th></tr></thead><tbody>
            ${json.data.map(u => `<tr>
                <td>${escapeHtml(u.nombre)} ${escapeHtml(u.apellido)}</td>
                <td class="mono">${escapeHtml(u.email)}</td>
                <td>${rolNombre(u.rol)}</td>
                <td>${u.rol === 'admin' ? 'Todas' : (['cliente', 'colaborador'].includes(u.rol) ? u.maquinas_asignadas : 'App técnica')}</td>
                <td>${Number(u.activo) ? '<span class="badge badge-ok">Activo</span>' : '<span class="badge badge-neutro">Inactivo</span>'}</td>
                <td><div class="acciones-tabla"><button class="btn btn-editar" data-id="${u.id_usuario}" type="button">Editar</button></div></td>
            </tr>`).join('')}
        </tbody></table>`;
        cont.querySelectorAll('.btn-editar').forEach(btn => btn.addEventListener('click', () => abrirEditar(Number(btn.dataset.id))));
    } catch (e) {
        document.getElementById('stat-activos').textContent = '—';
        document.getElementById('stat-colaboradores').textContent = '—';
        document.getElementById('stat-admins').textContent = '—';
        cont.className = '';
        cont.innerHTML = '<p class="vacio">No se pudo cargar usuarios.</p>';
    }
}

function rolNombre(rol) {
    const roles = {
        admin: 'Administrador',
        cliente: 'Cliente',
        colaborador: 'Colaborador',
        medico: 'Médico',
        psicologo: 'Psicólogo',
        nutriologo: 'Nutriólogo',
        tecnico_instalador: 'Técnico instalador',
        tecnico_soporte: 'Técnico soporte',
    };
    return roles[rol] || rol || '-';
}

function renderMaquinas(seleccionadas = []) {
    seleccionActual = seleccionadas.map(Number);
    const filtro = (document.getElementById('buscar-maquina')?.value || '').toLowerCase();
    const set = new Set(seleccionActual);
    const visibles = maquinas.filter(m =>
        !filtro ||
        String(m.numero_serie || '').toLowerCase().includes(filtro) ||
        String(m.nombre_ubicacion || '').toLowerCase().includes(filtro)
    );
    document.getElementById('conteo-maquinas').textContent = `${set.size} seleccionada${set.size === 1 ? '' : 's'}`;
    document.getElementById('maquinas-grid').innerHTML = visibles.map(m => `
        <label class="check-maquina">
            <input type="checkbox" value="${m.id_maquina}" ${set.has(Number(m.id_maquina)) ? 'checked' : ''}>
            <span><span class="mono">${escapeHtml(m.numero_serie)}</span><br><span class="subtle-note">${escapeHtml(m.nombre_ubicacion) || 'Sin ubicación'}</span></span>
        </label>
    `).join('');
    document.querySelectorAll('#maquinas-grid input').forEach(input => {
        input.addEventListener('change', () => {
            const id = Number(input.value);
            if (input.checked && !seleccionActual.includes(id)) seleccionActual.push(id);
            if (!input.checked) seleccionActual = seleccionActual.filter(x => x !== id);
            renderMaquinas(seleccionActual);
        });
    });
}

function limpiar() {
    document.getElementById('form-usuario').reset();
    document.getElementById('id_usuario').value = '';
    document.getElementById('modal-titulo').textContent = 'Nuevo usuario';
    document.getElementById('pass-help').textContent = '(requerida al crear)';
    document.getElementById('mensaje').textContent = '';
    renderMaquinas([]);
    actualizarBloqueMaquinas();
}

function actualizarBloqueMaquinas() {
    document.getElementById('bloque-maquinas').style.display = ['cliente', 'colaborador'].includes(document.getElementById('rol').value) ? '' : 'none';
}

function abrirCrear() {
    limpiar();
    document.getElementById('overlay').classList.add('abierto');
}

async function abrirEditar(id) {
    limpiar();
    document.getElementById('modal-titulo').textContent = 'Editar usuario';
    document.getElementById('pass-help').textContent = '(solo si deseas cambiarla)';
    document.getElementById('overlay').classList.add('abierto');
    const json = await obtenerJSON(`api/usuarios.php?id_usuario=${id}`);
    const u = json.data;
    document.getElementById('id_usuario').value = u.id_usuario;
    document.getElementById('nombre').value = u.nombre || '';
    document.getElementById('apellido').value = u.apellido || '';
    document.getElementById('email').value = u.email || '';
    document.getElementById('rol').value = u.rol || 'colaborador';
    document.getElementById('activo').value = Number(u.activo) ? '1' : '0';
    renderMaquinas(u.maquinas || []);
    actualizarBloqueMaquinas();
}

function cerrar() { document.getElementById('overlay').classList.remove('abierto'); }

document.getElementById('btn-nuevo').addEventListener('click', abrirCrear);
document.getElementById('btn-cerrar').addEventListener('click', cerrar);
document.getElementById('btn-cancelar').addEventListener('click', cerrar);
document.getElementById('rol').addEventListener('change', actualizarBloqueMaquinas);
document.getElementById('buscar-maquina').addEventListener('input', () => renderMaquinas(seleccionActual));

document.getElementById('form-usuario').addEventListener('submit', async ev => {
    ev.preventDefault();
    const mensaje = document.getElementById('mensaje');
    mensaje.textContent = '';
    mensaje.className = 'modal-mensaje';
    const payload = {
        id_usuario: document.getElementById('id_usuario').value ? Number(document.getElementById('id_usuario').value) : null,
        nombre: document.getElementById('nombre').value.trim(),
        apellido: document.getElementById('apellido').value.trim(),
        email: document.getElementById('email').value.trim(),
        password: document.getElementById('password').value,
        rol: document.getElementById('rol').value,
        activo: document.getElementById('activo').value === '1',
        maquinas: seleccionActual,
    };
    try {
        const res = await fetch('api/usuarios.php', { method:'POST', headers:{ 'Content-Type':'application/json' }, body:JSON.stringify(payload) });
        const json = await res.json();
        if (!json.ok) {
            mensaje.textContent = json.error || 'No se pudo guardar.';
            mensaje.className = 'modal-mensaje error';
            return;
        }
        await cargarUsuarios();
        cerrar();
    } catch (e) {
        mensaje.textContent = 'No se pudo conectar con el servidor.';
        mensaje.className = 'modal-mensaje error';
    }
});

Promise.all([cargarMaquinas(), cargarUsuarios()]);
</script>
</body>
</html>
