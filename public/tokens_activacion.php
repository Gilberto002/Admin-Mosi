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
    <title>Medical Life - Tokens activacion</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
    <link rel="stylesheet" href="assets/css/estilo.css?v=20260811-3">
    <style>
        body.tema-oscuro { --bg:#F5F7FA; --bg-elevada:#fff; --bg-hover:#EEF3F7; --linea:#D8E0E7; --linea-suave:#E8EEF3; --texto:#14212B; --texto-suave:#344856; --texto-tenue:#647583; --petroleo:#087D76; --petroleo-wash:rgba(8,125,118,.10); --ambar:#9A620B; --ambar-wash:rgba(154,98,11,.12); --critico:#B42335; --critico-wash:rgba(180,35,53,.10); background:var(--bg); color:var(--texto); }
        .encabezado-pagina { display:flex; justify-content:space-between; gap:16px; flex-wrap:wrap; margin-bottom:20px; }
        .encabezado-pagina__etiqueta { color:var(--petroleo); font-size:11px; letter-spacing:.08em; text-transform:uppercase; font-weight:700; margin-bottom:8px; }
        h1 { margin:0 0 5px; font-size:24px; color:var(--texto); }
        p { margin:0; color:var(--texto-suave); font-size:13px; line-height:1.45; }
        .grid { display:grid; grid-template-columns:minmax(320px,.7fr) minmax(0,1.3fr); gap:14px; align-items:start; }
        .panel { background:var(--bg-elevada); border:1px solid var(--linea); border-radius:10px; box-shadow:0 12px 28px rgba(20,33,43,.08); }
        .panel-head { padding:16px 18px; border-bottom:1px solid var(--linea-suave); }
        .panel-head h2 { margin:0; font-size:15px; }
        .formulario { display:grid; gap:13px; padding:18px; }
        label { display:block; font-size:12px; font-weight:700; color:var(--texto-suave); margin-bottom:6px; }
        input, select { width:100%; box-sizing:border-box; border:1px solid #C8D3DC; border-radius:8px; background:#fff; color:var(--texto); font:inherit; font-size:13px; padding:10px 11px; }
        .btn { border:1px solid #C8D3DC; background:#fff; color:var(--texto); border-radius:8px; padding:9px 13px; font:inherit; font-size:13px; font-weight:700; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; gap:8px; }
        .btn:hover { border-color:var(--petroleo); color:var(--petroleo); background:#F2F7F6; }
        .btn.primario { border-color:var(--petroleo); background:var(--petroleo); color:#fff; }
        .mensaje { color:var(--texto-tenue); font-size:12px; }
        .mensaje.ok { color:var(--petroleo); }
        .mensaje.error { color:var(--critico); }
        .token-box { display:none; margin:0 18px 18px; border:1px solid var(--petroleo); background:var(--petroleo-wash); border-radius:8px; padding:13px; }
        .token-box.visible { display:block; }
        .token-box .token { font-family:monospace; font-size:16px; font-weight:700; overflow-wrap:anywhere; }
        .table-shell { overflow:auto; }
        table { width:100%; min-width:850px; border-collapse:collapse; font-size:13px; }
        th, td { padding:11px 14px; border-bottom:1px solid var(--linea-suave); text-align:left; vertical-align:top; }
        th { background:#F8FAFC; color:var(--texto-tenue); font-size:10px; text-transform:uppercase; letter-spacing:.06em; }
        .mono { font-family:monospace; font-size:12px; }
        .muted { color:var(--texto-tenue); }
        .badge { display:inline-flex; border-radius:999px; padding:3px 10px; font-size:11px; font-weight:700; white-space:nowrap; }
        .badge-ok { background:var(--petroleo-wash); color:var(--petroleo); }
        .badge-warn { background:var(--ambar-wash); color:var(--ambar); }
        .badge-danger { background:var(--critico-wash); color:var(--critico); }
        .cargando, .vacio { color:var(--texto-tenue); font-style:italic; padding:30px 14px; text-align:center; }
        @media (max-width:980px) { .grid { grid-template-columns:1fr; } }
    </style>
</head>
<body class="tema-oscuro">
<div class="app">
    <aside class="sidebar">
        <div class="marca"><img class="marca-logo" src="assets/img/logo.png" alt="Medical Life"></div>
        <?php require __DIR__ . '/partials/menu.php'; ?>
    </aside>
    <main class="principal">
        <div class="encabezado-pagina">
            <div>
                <div class="encabezado-pagina__etiqueta">Aprovisionamiento</div>
                <h1>Tokens de activacion</h1>
                <p>Genera tokens temporales para activar maquinas sin compartir el token global del servidor.</p>
            </div>
            <button class="btn" id="btn-recargar" type="button">Actualizar</button>
        </div>
        <div class="grid">
            <section class="panel">
                <div class="panel-head"><h2>Nuevo token temporal</h2></div>
                <form id="form-token" class="formulario">
                    <div><label>Descripcion</label><input id="descripcion" placeholder="Ej. Cuadrilla norte lunes"></div>
                    <div><label>Numero de serie especifico</label><input id="numero_serie" placeholder="Opcional"></div>
                    <div><label>Vigencia</label><select id="horas_vigencia"><option value="8">8 horas</option><option value="24" selected>24 horas</option><option value="72">3 dias</option><option value="168">7 dias</option></select></div>
                    <div><label>Activaciones permitidas</label><input id="usos_max" type="number" min="1" max="500" value="1"></div>
                    <div style="display:flex; justify-content:space-between; gap:10px; align-items:center;"><span id="mensaje" class="mensaje"></span><button class="btn primario" type="submit">Generar</button></div>
                </form>
                <div id="token-box" class="token-box"><div class="muted">Token temporal. Se muestra una sola vez.</div><div id="token-generado" class="token"></div></div>
            </section>
            <section class="panel">
                <div class="panel-head"><h2>Tokens recientes</h2></div>
                <div id="tabla" class="cargando">Cargando...</div>
            </section>
        </div>
    </main>
</div>
<script src="assets/js/comun.js?v=20260811-3"></script>
<script>
const $ = sel => document.querySelector(sel);
function fecha(v) { if (!v) return '-'; const d = new Date(v); return Number.isNaN(d.getTime()) ? escapeHtml(v) : d.toLocaleString('es-MX'); }
function badgeEstado(e) {
    if (e === 'activo') return '<span class="badge badge-ok">Activo</span>';
    if (e === 'vencido') return '<span class="badge badge-warn">Vencido</span>';
    return `<span class="badge badge-danger">${escapeHtml(e || 'Inactivo')}</span>`;
}
async function cargar() {
    $('#tabla').innerHTML = '<div class="cargando">Cargando...</div>';
    const json = await obtenerJSON('api/tokens_activacion.php');
    const rows = json.data || [];
    if (!rows.length) { $('#tabla').innerHTML = '<div class="vacio">Sin tokens generados.</div>'; return; }
    $('#tabla').innerHTML = `<div class="table-shell"><table><thead><tr><th>Estado</th><th>Uso</th><th>Alcance</th><th>Fechas</th><th>Accion</th></tr></thead><tbody>${rows.map(r => `<tr><td>${badgeEstado(r.estado)}<div class="muted">${escapeHtml(r.descripcion || '')}</div></td><td class="mono">${r.usos_actuales}/${r.usos_max}</td><td>${r.numero_serie ? `<span class="mono">${escapeHtml(r.numero_serie)}</span>` : 'Cualquier serie preregistrada'}</td><td><div>Expira: ${fecha(r.fecha_expiracion)}</div><div class="muted">Creado: ${fecha(r.fecha_creacion)}</div></td><td>${r.estado === 'activo' ? `<button class="btn btn-revocar" data-id="${r.id_token_activacion}" type="button">Revocar</button>` : ''}</td></tr>`).join('')}</tbody></table></div>`;
    document.querySelectorAll('.btn-revocar').forEach(btn => btn.addEventListener('click', () => revocar(Number(btn.dataset.id))));
}
async function revocar(id) {
    const ok = await mlConfirm('El token dejara de funcionar inmediatamente.', 'Revocar token', 'Revocar');
    if (!ok) return;
    const res = await fetch('api/tokens_activacion.php', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({accion:'revocar', id_token_activacion:id}) });
    const json = await res.json();
    if (!json.ok) return mlAlert(json.error || 'No se pudo revocar');
    await cargar();
}
$('#form-token').addEventListener('submit', async ev => {
    ev.preventDefault();
    $('#mensaje').textContent = 'Generando...';
    $('#token-box').classList.remove('visible');
    try {
        const res = await fetch('api/tokens_activacion.php', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({ descripcion:$('#descripcion').value.trim(), numero_serie:$('#numero_serie').value.trim(), horas_vigencia:Number($('#horas_vigencia').value), usos_max:Number($('#usos_max').value || 1) }) });
        const json = await res.json();
        if (!json.ok) throw new Error(json.error || 'No se pudo generar');
        $('#mensaje').textContent = 'Token generado.';
        $('#mensaje').className = 'mensaje ok';
        $('#token-generado').textContent = json.data.token;
        $('#token-box').classList.add('visible');
        await cargar();
    } catch (e) {
        $('#mensaje').textContent = e.message || 'No se pudo generar';
        $('#mensaje').className = 'mensaje error';
    }
});
$('#btn-recargar').addEventListener('click', () => cargar().catch(e => mlAlert(e.message)));
cargar().catch(e => $('#tabla').innerHTML = `<div class="vacio">${escapeHtml(e.message)}</div>`);
</script>
</body>
</html>
