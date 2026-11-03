<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/bootstrap.php';

use App\Auth\Auth;

Auth::requireLogin();
$esAdmin = Auth::isAdmin() && !Auth::isUsuarioMaquinasDashboard();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
    <title>MDM - Telemedicina / MOSI</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/estilo.css?v=20260914-2">
    <style>
        body.tema-oscuro { --bg:#0A0F14; --bg-elevada:#121A21; --bg-elevada-2:#18222B; --bg-hover:#1E2830; --linea:#243039; --linea-suave:#1C2630; --texto:#EEF4F7; --texto-suave:#9FB2BC; --texto-tenue:#617581; --petroleo:#2CD9BE; --petroleo-fuerte:#159485; --petroleo-wash:rgba(44,217,190,.13); --ambar:#E4AC4F; --ambar-wash:rgba(228,172,79,.15); --critico:#F1707E; --critico-wash:rgba(241,112,126,.15); background:radial-gradient(900px 480px at 12% -10%,rgba(44,217,190,.07),transparent 60%),var(--bg); color:var(--texto); }
        body.tema-oscuro .sidebar { background:var(--bg-elevada); border-right:1px solid var(--linea); }
        .cabecera { display:flex; justify-content:space-between; align-items:flex-start; gap:18px; flex-wrap:wrap; margin-bottom:22px; }
        .eyebrow { color:var(--petroleo); font-size:11px; font-weight:700; letter-spacing:.09em; text-transform:uppercase; display:flex; gap:8px; align-items:center; margin-bottom:8px; }
        .eyebrow::before { content:""; width:7px; height:7px; border-radius:50%; background:var(--petroleo); box-shadow:0 0 0 5px var(--petroleo-wash); }
        .cabecera h1 { margin:0 0 5px; font:700 25px 'Space Grotesk',sans-serif; }
        .cabecera p { margin:0; color:var(--texto-suave); font-size:13.5px; max-width:640px; }
        .acciones { display:flex; gap:9px; flex-wrap:wrap; }
        button { font-family:inherit; }
        .btn { border:1px solid var(--linea); border-radius:8px; padding:10px 15px; cursor:pointer; background:var(--bg-elevada); color:var(--texto); display:inline-flex; align-items:center; gap:7px; font-size:13px; font-weight:650; text-decoration:none; }
        .btn:hover { border-color:var(--petroleo); color:var(--petroleo); }
        .btn.primario { background:var(--petroleo); border-color:var(--petroleo); color:#06201F; }
        .btn.primario:hover { background:#49E8D0; color:#06201F; }
        .stats { display:grid; grid-template-columns:repeat(4,1fr); gap:12px; margin-bottom:16px; }
        .stat { background:var(--bg-elevada); border:1px solid var(--linea); border-radius:13px; padding:15px 16px; }
        .stat small { display:block; text-transform:uppercase; letter-spacing:.06em; color:var(--texto-tenue); font-size:10px; font-weight:700; margin-bottom:7px; }
        .stat strong { font:700 27px 'Space Grotesk',sans-serif; }
        .stat.online strong { color:var(--petroleo); } .stat.offline strong { color:var(--critico); } .stat.mant strong { color:var(--ambar); }
        .toolbar { background:var(--bg-elevada); border:1px solid var(--linea); border-radius:13px; padding:13px; display:grid; grid-template-columns:minmax(240px,1fr) 210px 190px auto; gap:10px; margin-bottom:15px; }
        input, select, textarea { box-sizing:border-box; width:100%; background:var(--bg); border:1px solid var(--linea); border-radius:8px; color:var(--texto); padding:10px 12px; font:13px Inter,sans-serif; }
        input:focus, select:focus, textarea:focus { outline:none; border-color:var(--petroleo-fuerte); box-shadow:0 0 0 3px var(--petroleo-wash); }
        .contador { color:var(--texto-tenue); font-size:12px; display:flex; align-items:center; justify-content:flex-end; }
        .grid-mosi { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:14px; }
        .mosi { position:relative; background:var(--bg-elevada); border:1px solid var(--linea); border-radius:14px; padding:17px; cursor:pointer; transition:transform .15s,border-color .15s; min-width:0; }
        .mosi:hover { transform:translateY(-2px); border-color:#35505c; }
        .mosi-top { display:flex; justify-content:space-between; gap:12px; align-items:flex-start; }
        .mosi-icon { width:38px; height:38px; display:grid; place-items:center; border-radius:10px; background:var(--petroleo-wash); color:var(--petroleo); flex:none; }
        .mosi-titulo { min-width:0; flex:1; }
        .mosi h3 { margin:0 0 4px; font-size:15px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .mono { font-family:'JetBrains Mono',monospace; }
        .serie { color:var(--texto-tenue); font-size:11px; }
        .badge { display:inline-flex; align-items:center; gap:6px; border-radius:999px; padding:4px 8px; white-space:nowrap; font-size:10.5px; font-weight:700; }
        .badge::before { content:""; width:6px; height:6px; border-radius:50%; background:currentColor; }
        .badge.online { color:var(--petroleo); background:var(--petroleo-wash); }
        .badge.offline { color:var(--critico); background:var(--critico-wash); }
        .badge.nunca { color:var(--texto-tenue); background:var(--bg); border:1px solid var(--linea); }
        .mosi-meta { display:grid; grid-template-columns:1fr 1fr; gap:10px; border-top:1px solid var(--linea-suave); margin-top:14px; padding-top:13px; }
        .dato small { display:block; color:var(--texto-tenue); text-transform:uppercase; font-size:9px; letter-spacing:.05em; margin-bottom:4px; }
        .dato span { color:var(--texto-suave); font-size:12px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; display:block; }
        .links { display:flex; align-items:center; gap:6px; margin-top:13px; min-height:25px; flex-wrap:wrap; }
        .vending-chip { border:1px solid var(--linea); background:var(--bg); border-radius:999px; padding:4px 8px; color:var(--texto-suave); font-size:10.5px; }
        .vending-chip b { color:var(--petroleo); }
        .vending-chip.alerta { color:var(--critico); border-color:rgba(241,112,126,.35); }
        .vending-chip.pendiente { color:var(--ambar); border-color:rgba(228,172,79,.35); }
        .vacio { grid-column:1/-1; padding:50px 20px; border:1px dashed var(--linea); border-radius:14px; text-align:center; color:var(--texto-tenue); }
        .paginacion { display:flex; justify-content:flex-end; align-items:center; gap:10px; margin-top:18px; color:var(--texto-tenue); font-size:12px; }
        .overlay { display:none; position:fixed; inset:0; z-index:1000; background:rgba(2,8,12,.78); backdrop-filter:blur(3px); align-items:center; justify-content:center; padding:20px; }
        .overlay.abierto { display:flex; }
        .modal { width:min(760px,100%); max-height:92vh; overflow:auto; background:var(--bg-elevada); border:1px solid var(--linea); border-radius:16px; box-shadow:0 28px 80px rgba(0,0,0,.5); }
        .modal.chico { width:min(520px,100%); }
        .modal-head { display:flex; justify-content:space-between; align-items:center; padding:17px 20px; border-bottom:1px solid var(--linea); }
        .modal-head h2 { margin:0; font-size:17px; }
        .cerrar { border:0; background:transparent; color:var(--texto-tenue); font-size:24px; cursor:pointer; }
        .modal-body { padding:19px 20px; }
        .form-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
        .campo.completo { grid-column:1/-1; }
        .campo label { display:block; color:var(--texto-suave); font-size:11.5px; font-weight:600; margin-bottom:6px; }
        textarea { min-height:74px; resize:vertical; }
        .maquinas-box { border:1px solid var(--linea); border-radius:9px; padding:10px; max-height:190px; overflow:auto; background:var(--bg); }
        .maquina-check { display:flex; gap:9px; align-items:flex-start; padding:8px; border-radius:7px; cursor:pointer; }
        .maquina-check:hover { background:var(--bg-hover); }
        .maquina-check input { width:auto; margin-top:2px; }
        .maquina-check span { font-size:12px; } .maquina-check small { display:block; color:var(--texto-tenue); margin-top:2px; }
        .modal-foot { display:flex; align-items:center; justify-content:flex-end; gap:9px; padding:14px 20px; border-top:1px solid var(--linea); }
        .mensaje { margin-right:auto; color:var(--critico); font-size:12px; }
        .detalle-hero { display:grid; grid-template-columns:54px 1fr auto; align-items:center; gap:14px; padding-bottom:17px; border-bottom:1px solid var(--linea); }
        .detalle-hero .mosi-icon { width:54px; height:54px; }
        .detalle-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:10px; margin-top:16px; }
        .detalle-grid .dato { background:var(--bg); border:1px solid var(--linea-suave); border-radius:9px; padding:11px; }
        .vinculos-detalle { margin-top:18px; }
        .vinculo { display:grid; grid-template-columns:1fr auto; gap:10px; padding:11px 0; border-bottom:1px solid var(--linea-suave); color:inherit; text-decoration:none; }
        .vinculo:hover strong { color:var(--petroleo); }
        .vinculo small { color:var(--texto-tenue); display:block; margin-top:3px; }
        .sensor-grid { display:grid; grid-template-columns:repeat(2,1fr); gap:8px; }
        .sensor { display:flex; justify-content:space-between; gap:10px; padding:10px 11px; border:1px solid var(--linea-suave); border-radius:9px; background:var(--bg); font-size:12px; }
        .sensor em { color:var(--texto-tenue); font-style:normal; }
        .evento { border-left:3px solid var(--linea); padding:8px 11px; margin-bottom:8px; background:var(--bg); border-radius:0 8px 8px 0; }
        .evento.error { border-color:var(--critico); } .evento.warning { border-color:var(--ambar); }
        .evento small { display:block; color:var(--texto-tenue); margin-top:3px; }
        @media(max-width:1050px){.grid-mosi{grid-template-columns:repeat(2,1fr)}.stats{grid-template-columns:repeat(3,1fr)}}
        @media(max-width:700px){.grid-mosi{grid-template-columns:1fr}.stats{grid-template-columns:repeat(2,1fr)}.toolbar{grid-template-columns:1fr}.contador{justify-content:flex-start}.form-grid,.detalle-grid{grid-template-columns:1fr}.campo.completo{grid-column:auto}.detalle-hero{grid-template-columns:48px 1fr}.detalle-hero>.badge{grid-column:1/-1}}
    </style>
</head>
<body class="tema-oscuro">
<div class="app">
    <aside class="sidebar">
        <div class="marca"><img class="marca-logo" src="assets/img/logo.png" alt="MDM"></div>
        <?php require __DIR__ . '/partials/menu.php'; ?>
    </aside>
    <main class="principal">
        <header class="cabecera">
            <div><div class="eyebrow">Red clínica conectada</div><h1>Telemedicina / MOSI</h1><p>Administra los módulos de consulta, ordénalos por grupos y consulta qué vendings surten las recetas de cada unidad.</p></div>
            <div class="acciones"><?php if ($esAdmin): ?><button class="btn" id="nuevo-grupo"><i data-lucide="folder-plus"></i>Nuevo grupo</button><button class="btn primario" id="nuevo-mosi"><i data-lucide="plus"></i>Dar de alta MOSI</button><?php endif; ?></div>
        </header>

        <section class="stats">
            <article class="stat"><small>Total MOSI</small><strong id="s-total">—</strong></article>
            <article class="stat online"><small>En línea</small><strong id="s-online">—</strong></article>
            <article class="stat offline"><small>Sin conexión</small><strong id="s-offline">—</strong></article>
            <article class="stat"><small>Sin primer latido</small><strong id="s-nunca">—</strong></article>
            <article class="stat mant"><small>Mantenimiento</small><strong id="s-mant">—</strong></article>
            <article class="stat mant"><small>Pendientes de sincronizar</small><strong id="s-pendientes">—</strong></article>
            <article class="stat offline"><small>Errores recientes</small><strong id="s-errores">—</strong></article>
            <article class="stat"><small>Actualizaciones</small><strong id="s-actualizaciones">—</strong></article>
        </section>

        <section class="toolbar">
            <input type="search" id="buscar" placeholder="Buscar MOSI, serie, ubicación o vending…">
            <select id="f-grupo"><option value="">Todos los grupos</option></select>
            <select id="f-conexion"><option value="">Toda conectividad</option><option value="online">En línea</option><option value="offline">Sin conexión</option><option value="nunca">Nunca conectada</option></select>
            <div class="contador" id="contador">Cargando…</div>
        </section>
        <section class="grid-mosi" id="lista"><div class="vacio">Cargando módulos MOSI…</div></section>
        <div class="paginacion" id="paginacion" hidden><button class="btn" id="anterior">Anterior</button><span id="pag-texto"></span><button class="btn" id="siguiente">Siguiente</button></div>
    </main>
</div>

<div class="overlay" id="modal-mosi"><form class="modal" id="form-mosi">
    <div class="modal-head"><h2 id="form-titulo">Dar de alta MOSI</h2><button type="button" class="cerrar" data-cerrar>&times;</button></div>
    <div class="modal-body"><div class="form-grid">
        <input type="hidden" id="m-id">
        <div class="campo"><label>Nombre del módulo *</label><input id="m-nombre" maxlength="140" required></div>
        <div class="campo"><label>Número de serie *</label><input id="m-serie" maxlength="100" required></div>
        <div class="campo"><label>Grupo</label><select id="m-grupo"><option value="">Sin grupo</option></select></div>
        <div class="campo"><label>Estatus</label><select id="m-estatus"><option value="activo">Activo</option><option value="mantenimiento">Mantenimiento</option><option value="baja">Baja</option></select></div>
        <div class="campo"><label>Fabricante</label><input id="m-fabricante" maxlength="100"></div>
        <div class="campo"><label>Modelo</label><input id="m-modelo" maxlength="100"></div>
        <div class="campo"><label>Ubicación</label><input id="m-ubicacion" maxlength="180"></div>
        <div class="campo"><label>Consultorio</label><input id="m-consultorio" maxlength="100"></div>
        <div class="campo"><label>IP local</label><input id="m-ip" maxlength="64" placeholder="192.168.1.20"></div>
        <div class="campo"><label>MAC address</label><input id="m-mac" maxlength="32"></div>
        <div class="campo"><label>Tipo de conexión</label><select id="m-conexion"><option value="">Sin especificar</option><option>Ethernet</option><option>WiFi</option><option>4G</option><option>5G</option></select></div>
        <div class="campo"><label>Versión de software</label><input id="m-version" maxlength="60"></div>
        <div class="campo completo"><label>Vendings enlazadas</label><input type="search" id="buscar-maquina" placeholder="Filtrar por serie o nombre" style="margin-bottom:8px"><div class="maquinas-box" id="maquinas-box"></div></div>
        <div class="campo completo"><label>Notas técnicas</label><textarea id="m-notas" maxlength="700"></textarea></div>
    </div></div>
    <div class="modal-foot"><span class="mensaje" id="m-mensaje"></span><button type="button" class="btn" data-cerrar>Cancelar</button><button class="btn primario" type="submit">Guardar MOSI</button></div>
</form></div>

<div class="overlay" id="modal-grupo"><form class="modal chico" id="form-grupo">
    <div class="modal-head"><h2>Nuevo grupo MOSI</h2><button type="button" class="cerrar" data-cerrar>&times;</button></div>
    <div class="modal-body"><div class="form-grid">
        <div class="campo"><label>Clave *</label><input id="g-clave" maxlength="30" required placeholder="NORTE-01"></div>
        <div class="campo"><label>Nombre *</label><input id="g-nombre" maxlength="120" required></div>
        <div class="campo completo"><label>Responsable</label><input id="g-responsable" maxlength="120"></div>
        <div class="campo completo"><label>Descripción</label><textarea id="g-descripcion" maxlength="400"></textarea></div>
    </div></div>
    <div class="modal-foot"><span class="mensaje" id="g-mensaje"></span><button type="button" class="btn" data-cerrar>Cancelar</button><button class="btn primario" type="submit">Guardar grupo</button></div>
</form></div>

<div class="overlay" id="modal-detalle"><div class="modal">
    <div class="modal-head"><h2>Ficha técnica MOSI</h2><button type="button" class="cerrar" data-cerrar>&times;</button></div>
    <div class="modal-body" id="detalle"></div>
    <div class="modal-foot"><?php if ($esAdmin): ?><button class="btn" id="activar-mosi"><i data-lucide="key-round"></i>Generar activación</button><button class="btn" id="editar-mosi"><i data-lucide="pencil"></i>Editar</button><?php endif; ?><button class="btn primario" data-cerrar>Cerrar</button></div>
</div></div>

<script src="assets/js/comun.js?v=20261013-1"></script>
<script>
const ES_ADMIN = <?= $esAdmin ? 'true' : 'false' ?>;
const PAGE_SIZE = 24;
let pagina = 1, total = 0, catalogos = {grupos:[], maquinas:[]}, seleccionada = null, debounce;
const $ = id => document.getElementById(id);
const valor = id => $(id).value.trim();

function estadoBadge(m) {
    if (m.estado_conexion === 'online') return '<span class="badge online">En línea</span>';
    if (m.estado_conexion === 'offline') return '<span class="badge offline">Sin conexión</span>';
    return '<span class="badge nunca">Nunca conectada</span>';
}
function tiempoConexion(m) {
    if (m.estado_conexion === 'nunca_conectada') return 'Sin primer latido';
    const s = Math.max(0, Number(m.segundos_desde_conexion)||0);
    if (s < 60) return 'Hace menos de 1 min';
    if (s < 3600) return `Hace ${Math.floor(s/60)} min`;
    if (s < 86400) return `Hace ${Math.floor(s/3600)} h`;
    return `Hace ${Math.floor(s/86400)} d`;
}
function tarjeta(m) {
    const chips = m.maquinas.length ? m.maquinas.slice(0,3).map(v=>`<span class="vending-chip"><b>V</b> ${escapeHtml(v.numero_serie)}</span>`).join('') : '<span class="serie">Sin vending enlazada</span>';
    const operacion = `${Number(m.pendientes_sincronizacion)>0?`<span class="vending-chip pendiente">${Number(m.pendientes_sincronizacion)} por sincronizar</span>`:''}${Number(m.errores_recientes)>0?`<span class="vending-chip alerta">${Number(m.errores_recientes)} error${Number(m.errores_recientes)===1?'':'es'}</span>`:''}`;
    return `<article class="mosi" data-id="${m.id_mosi}" tabindex="0" role="button" aria-label="Ver ${escapeHtml(m.nombre)}">
        <div class="mosi-top"><div class="mosi-icon"><i data-lucide="heart-pulse"></i></div><div class="mosi-titulo"><h3>${escapeHtml(m.nombre)}</h3><div class="serie mono">${escapeHtml(m.numero_serie)}</div></div>${estadoBadge(m)}</div>
        <div class="mosi-meta"><div class="dato"><small>Grupo</small><span>${escapeHtml(m.grupo_nombre)||'Sin grupo'}</span></div><div class="dato"><small>Última señal</small><span>${tiempoConexion(m)}</span></div><div class="dato"><small>Ubicación</small><span>${escapeHtml(m.ubicacion)||'Sin ubicación'}</span></div><div class="dato"><small>Conexión</small><span>${escapeHtml(m.tipo_conexion)||'No indicada'}</span></div></div>
        <div class="links">${chips}${m.maquinas.length>3?`<span class="serie">+${m.maquinas.length-3}</span>`:''}${operacion}</div>
    </article>`;
}
function llenarCatalogos() {
    const opts = catalogos.grupos.map(g=>`<option value="${g.id_grupo}">${escapeHtml(g.nombre)} (${escapeHtml(g.clave)})</option>`).join('');
    const filtroActual = $('f-grupo').value, formActual = $('m-grupo').value;
    $('f-grupo').innerHTML = '<option value="">Todos los grupos</option>'+opts;
    $('m-grupo').innerHTML = '<option value="">Sin grupo</option>'+opts;
    $('f-grupo').value = filtroActual; $('m-grupo').value = formActual;
    renderMaquinas('');
}
function renderMaquinas(q, elegidas = null) {
    if (!ES_ADMIN) return;
    const actuales = elegidas || new Set([...document.querySelectorAll('#maquinas-box input:checked')].map(x=>Number(x.value)));
    const term = q.toLowerCase();
    const lista = catalogos.maquinas.filter(m => `${m.numero_serie} ${m.nombre_maquina||''} ${m.nombre_ubicacion||''}`.toLowerCase().includes(term));
    $('maquinas-box').innerHTML = lista.length ? lista.map(m=>`<label class="maquina-check"><input type="checkbox" value="${m.id_maquina}" ${actuales.has(Number(m.id_maquina))?'checked':''}><span><b class="mono">${escapeHtml(m.numero_serie)}</b><small>${escapeHtml(m.nombre_maquina)||'Sin nombre'} · ${Number(m.rol_maquina)===2?'Esclava':'Principal'}</small></span></label>`).join('') : '<div class="serie">No hay coincidencias.</div>';
}
async function cargar() {
    const params = new URLSearchParams({page:pagina,page_size:PAGE_SIZE});
    if (valor('buscar')) params.set('q',valor('buscar'));
    if (valor('f-grupo')) params.set('id_grupo',valor('f-grupo'));
    if (valor('f-conexion')) params.set('conexion',valor('f-conexion'));
    try {
        const j = await obtenerJSON(`api/mosi.php?${params}`);
        catalogos=j.catalogos; total=Number(j.meta.total)||0;
        llenarCatalogos();
        $('contador').textContent=`${total} módulo${total===1?'':'s'} MOSI`;
        $('s-total').textContent=j.stats.total||0; $('s-online').textContent=j.stats.online||0; $('s-offline').textContent=j.stats.offline||0; $('s-nunca').textContent=j.stats.nunca||0; $('s-mant').textContent=j.stats.mantenimiento||0; $('s-pendientes').textContent=j.stats.pendientes_sync||0; $('s-errores').textContent=j.stats.errores||0; $('s-actualizaciones').textContent=j.stats.actualizaciones||0;
        $('lista').innerHTML=j.data.length?j.data.map(tarjeta).join(''):'<div class="vacio">No hay módulos MOSI que coincidan con los filtros.</div>';
        const paginas=Math.max(1,Math.ceil(total/PAGE_SIZE)); $('paginacion').hidden=total<=PAGE_SIZE; $('pag-texto').textContent=`${pagina} / ${paginas}`; $('anterior').disabled=pagina<=1; $('siguiente').disabled=pagina>=paginas;
        $('lista').querySelectorAll('.mosi').forEach(card=>{ const abrir=()=>verDetalle(Number(card.dataset.id)); card.onclick=abrir; card.onkeydown=e=>{if(e.key==='Enter'||e.key===' '){e.preventDefault();abrir();}}; });
        window.mlRenderIcons?.();
    } catch(e) { $('lista').innerHTML=`<div class="vacio">${escapeHtml(e.message||'No se pudo cargar el módulo.')}</div>`; }
}
async function verDetalle(id) {
    try { const j=await obtenerJSON(`api/mosi.php?id_mosi=${id}`); seleccionada=j.data; renderDetalle(seleccionada); abrir('modal-detalle'); }
    catch(e){ mlAlert(e.message||'No se pudo abrir la ficha.'); }
}
function renderDetalle(m) {
    const enlaces=m.maquinas.length?m.maquinas.map(v=>`<a class="vinculo" href="maquina.php?id=${v.id_maquina}"><div><strong class="mono">${escapeHtml(v.numero_serie)}</strong><small>${escapeHtml(v.nombre_maquina)||'Vending'} · ${escapeHtml(v.nombre_ubicacion)||'Sin ubicación'}</small></div><i data-lucide="chevron-right"></i></a>`).join(''):'<div class="vacio" style="padding:22px">Esta MOSI todavía no tiene vendings enlazadas.</div>';
    const siNo=v=>v===null||v===''?'Sin reporte':Number(v)?'Sí':'No';
    const sensores=m.sensores?.length?`<div class="sensor-grid">${m.sensores.map(s=>`<div class="sensor"><span>${escapeHtml(s.nombre)}</span><em>${s.conectado===null?(escapeHtml(s.estado)||'Sin estado'):(Number(s.conectado)?'Conectado':'Desconectado')}</em></div>`).join('')}</div>`:'<div class="serie">La cabina aún no ha reportado sensores.</div>';
    const eventos=m.eventos?.length?m.eventos.map(e=>`<div class="evento ${escapeHtml(e.nivel)}"><strong>${escapeHtml(e.codigo)||escapeHtml(e.nivel)}</strong> · ${escapeHtml(e.mensaje)}<small>${escapeHtml(e.fecha_evento)}</small></div>`).join(''):'<div class="serie">Sin eventos recientes.</div>';
    const historial=m.historial_telemetria?.length?m.historial_telemetria.slice(0,10).map(h=>`<div class="evento"><strong>${escapeHtml(h.version_name)||'Sin versión'}${h.version_code!==null?' ('+escapeHtml(h.version_code)+')':''}</strong> · ${escapeHtml(h.tipo_conexion)||'Sin red'}<small>${escapeHtml(h.fecha_recepcion)} · ${Number(h.pendientes_sincronizacion)||0} pendientes · ${Number(h.errores_recientes)||0} errores</small></div>`).join(''):'<div class="serie">Todavía no hay historial de telemetría.</div>';
    $('detalle').innerHTML=`<div class="detalle-hero"><div class="mosi-icon"><i data-lucide="heart-pulse"></i></div><div><h2 style="margin:0 0 4px">${escapeHtml(m.nombre)}</h2><span class="mono serie">${escapeHtml(m.numero_serie)}</span></div>${estadoBadge(m)}</div>
      <div class="detalle-grid">${[['Grupo',m.grupo_nombre||'Sin grupo'],['Última conexión',tiempoConexion(m)],['App activada',Number(m.tokens_activos)>0?'Sí':'No'],['Último uso del token',m.ultimo_uso_token||'Sin uso'],['Instalada por',m.instalador_nombre||'No indicado'],['Fecha de instalación',m.fecha_instalacion||'No indicada'],['Internet',siNo(m.internet_disponible)],['Wi-Fi',siNo(m.wifi_conectado)],['Ethernet',siNo(m.ethernet_conectado)],['Nombre de red',m.nombre_red||'No indicado'],['Tipo de red',m.tipo_conexion||'No indicado'],['IP local',m.ip_local||'No indicada'],['MAC address',m.mac_address||'No indicada'],['Software',m.version_software||'No indicado'],['version_code',m.version_code??'No indicado'],['Última sincronización',m.ultima_sincronizacion||'Sin reporte'],['Device Owner',siNo(m.device_owner)],['Actualización',Number(m.actualizacion_disponible)?`Disponible${m.version_disponible?' · '+m.version_disponible:''}`:'Al día / sin reporte'],['Pendientes',String(m.pendientes_sincronizacion||0)],['Errores recientes',String(m.errores_recientes||0)],['Última medición',m.ultima_medicion||'Sin reporte'],['Cliente',m.cliente_nombre||m.id_cliente_externo||'No indicado'],['Sucursal',m.sucursal_nombre||m.id_sucursal_externa||'No indicada'],['Pantalla VideoLoop',m.videoloop_host?`${m.videoloop_host}:${m.videoloop_port||8080}`:'No configurada'],['Clave VideoLoop',Number(m.videoloop_clave_configurada)?'Configurada (protegida)':'No configurada'],['Correo VideoLoop',m.videoloop_email||'No indicado'],['Impresora IPP',m.impresora_ip?`${m.impresora_ip}:${m.impresora_puerto||631}`:'No configurada'],['Fabricante',m.fabricante||'No indicado'],['Modelo',m.modelo||'No indicado'],['Consultorio',m.consultorio||'No indicado'],['Ubicación',m.ubicacion||'No indicada'],['Estatus',m.estatus],['Vendings',String(m.maquinas.length)]].map(d=>`<div class="dato"><small>${d[0]}</small><span>${escapeHtml(d[1])}</span></div>`).join('')}</div>
      ${m.notas?`<div class="vinculos-detalle"><div class="dato"><small>Notas técnicas</small><span style="white-space:normal">${escapeHtml(m.notas)}</span></div></div>`:''}<div class="vinculos-detalle"><h3 style="font-size:14px">Historial de telemetría</h3>${historial}</div><div class="vinculos-detalle"><h3 style="font-size:14px">Sensores reportados</h3>${sensores}</div><div class="vinculos-detalle"><h3 style="font-size:14px">Eventos y errores recientes</h3>${eventos}</div><div class="vinculos-detalle"><h3 style="font-size:14px">Vendings enlazadas</h3>${enlaces}</div>`;
    window.mlRenderIcons?.();
}
function abrir(id){$(id).classList.add('abierto');}
function cerrar(el){el.closest('.overlay').classList.remove('abierto');}
document.querySelectorAll('[data-cerrar]').forEach(b=>b.onclick=()=>cerrar(b));
document.querySelectorAll('.overlay').forEach(o=>o.onclick=e=>{if(e.target===o)o.classList.remove('abierto');});

function abrirFormulario(m=null) {
    $('form-mosi').reset(); $('m-id').value=m?.id_mosi||''; $('form-titulo').textContent=m?'Editar MOSI':'Dar de alta MOSI'; $('m-mensaje').textContent='';
    if(m){ const map={nombre:'m-nombre',numero_serie:'m-serie',id_grupo:'m-grupo',estatus:'m-estatus',fabricante:'m-fabricante',modelo:'m-modelo',ubicacion:'m-ubicacion',consultorio:'m-consultorio',ip_local:'m-ip',mac_address:'m-mac',tipo_conexion:'m-conexion',version_software:'m-version',notas:'m-notas'}; Object.entries(map).forEach(([k,id])=>$(id).value=m[k]??''); }
    renderMaquinas('',new Set((m?.maquinas||[]).map(v=>Number(v.id_maquina)))); abrir('modal-mosi');
}
$('nuevo-mosi')?.addEventListener('click',()=>abrirFormulario());
$('nuevo-grupo')?.addEventListener('click',()=>{$('form-grupo').reset();$('g-mensaje').textContent='';abrir('modal-grupo');});
$('editar-mosi')?.addEventListener('click',()=>{ $('modal-detalle').classList.remove('abierto'); abrirFormulario(seleccionada); });
$('activar-mosi')?.addEventListener('click',async()=>{
    if(!seleccionada)return;
    if(!await mlConfirm(`Se revocará cualquier código de activación pendiente para ${seleccionada.numero_serie}. El token actual del equipo solo se reemplaza cuando la app use el nuevo código.`,`Activar ${seleccionada.nombre}`,'Generar código'))return;
    try{
        const r=await fetch('api/mosi_token_activacion.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id_mosi:seleccionada.id_mosi,horas:24})});
        const j=await r.json();if(!j.ok)throw new Error(j.error||'No se pudo generar el código.');
        const Swal=await cargarSweetAlert();
        await Swal.fire({icon:'success',title:'Código de activación',html:`<p>Captúralo en la app MOSI. Vence en 24 horas y funciona una sola vez.</p><input id="codigo-mosi-generado" class="swal2-input" readonly value="${escapeHtml(j.data.codigo_activacion)}"><small>Serie: ${escapeHtml(j.data.numero_serie)}<br>Expira: ${escapeHtml(j.data.expira_en)}</small>`,showCancelButton:true,confirmButtonText:'Copiar código',cancelButtonText:'Cerrar',confirmButtonColor:'#159485',preConfirm:async()=>{const c=document.getElementById('codigo-mosi-generado').value;await navigator.clipboard.writeText(c);return c;}});
    }catch(err){mlAlert(err.message||'No se pudo generar el código.');}
});
$('buscar-maquina').addEventListener('input',e=>renderMaquinas(e.target.value));
$('form-mosi').addEventListener('submit',async e=>{e.preventDefault();$('m-mensaje').textContent='Guardando…'; const body={id_mosi:valor('m-id')||null,nombre:valor('m-nombre'),numero_serie:valor('m-serie'),id_grupo:valor('m-grupo')||null,estatus:valor('m-estatus'),fabricante:valor('m-fabricante'),modelo:valor('m-modelo'),ubicacion:valor('m-ubicacion'),consultorio:valor('m-consultorio'),ip_local:valor('m-ip'),mac_address:valor('m-mac'),tipo_conexion:valor('m-conexion'),version_software:valor('m-version'),notas:valor('m-notas'),maquinas:[...document.querySelectorAll('#maquinas-box input:checked')].map(x=>Number(x.value))}; try{const r=await fetch('api/mosi_guardar.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)});const j=await r.json();if(!j.ok)throw new Error(j.error);$('modal-mosi').classList.remove('abierto');await cargar();}catch(err){$('m-mensaje').textContent=err.message||'No se pudo guardar.';}});
$('form-grupo').addEventListener('submit',async e=>{e.preventDefault();$('g-mensaje').textContent='Guardando…';try{const r=await fetch('api/mosi_guardar.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({tipo:'grupo',clave:valor('g-clave'),nombre:valor('g-nombre'),responsable:valor('g-responsable'),descripcion:valor('g-descripcion')})});const j=await r.json();if(!j.ok)throw new Error(j.error);$('modal-grupo').classList.remove('abierto');await cargar();}catch(err){$('g-mensaje').textContent=err.message||'No se pudo guardar.';}});
$('buscar').addEventListener('input',()=>{clearTimeout(debounce);debounce=setTimeout(()=>{pagina=1;cargar();},300);});
$('f-grupo').addEventListener('change',()=>{pagina=1;cargar();}); $('f-conexion').addEventListener('change',()=>{pagina=1;cargar();});
$('anterior').onclick=()=>{pagina--;cargar();}; $('siguiente').onclick=()=>{pagina++;cargar();};
cargar(); setInterval(()=>{if(!document.hidden&&!document.querySelector('.overlay.abierto'))cargar();},60000);
</script>
</body>
</html>
