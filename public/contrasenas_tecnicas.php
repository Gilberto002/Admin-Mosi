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
    <title>Medical Life - Contraseñas técnicas</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/estilo.css?v=20260811-3">
    <style>
        body.tema-oscuro {
            --bg: #F5F7FA; --bg-elevada: #FFFFFF; --bg-hover: #EEF3F7;
            --linea: #D8E0E7; --linea-suave: #E8EEF3; --texto: #14212B;
            --texto-suave: #344856; --texto-tenue: #647583; --petroleo: #087D76;
            --petroleo-wash: rgba(8,125,118,.10); --ambar: #9A620B;
            --ambar-wash: rgba(154,98,11,.12); --critico: #B42335;
            --critico-wash: rgba(180,35,53,.10); background: var(--bg); color: var(--texto);
        }
        .encabezado-pagina { display: flex; justify-content: space-between; gap: 16px; flex-wrap: wrap; margin-bottom: 20px; }
        .encabezado-pagina__etiqueta { color: var(--petroleo); font-size: 11px; letter-spacing: .08em; text-transform: uppercase; font-weight: 700; margin-bottom: 8px; }
        h1 { margin: 0 0 5px; font-size: 24px; color: var(--texto); }
        p { margin: 0; color: var(--texto-suave); font-size: 13px; line-height: 1.45; }
        .panel { background: var(--bg-elevada); border: 1px solid var(--linea); border-radius: 10px; box-shadow: 0 12px 28px rgba(20,33,43,.08); }
        .toolbar { display: flex; gap: 10px; padding: 14px 16px; border-bottom: 1px solid var(--linea-suave); flex-wrap: wrap; }
        input, select { box-sizing: border-box; border: 1px solid #C8D3DC; border-radius: 8px; background: #fff; color: var(--texto); font: inherit; font-size: 13px; padding: 10px 11px; }
        .toolbar input { flex: 1; min-width: 240px; }
        .toolbar select { width: 190px; }
        .btn { border: 1px solid #C8D3DC; background: #fff; color: var(--texto); border-radius: 8px; padding: 9px 13px; font: inherit; font-size: 13px; font-weight: 700; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; }
        .btn:hover { border-color: var(--petroleo); color: var(--petroleo); background: #F2F7F6; }
        .btn.primario { border-color: var(--petroleo); background: var(--petroleo); color: #fff; }
        .table-shell { overflow: auto; }
        table { width: 100%; min-width: 860px; border-collapse: collapse; font-size: 13px; }
        th, td { padding: 11px 14px; border-bottom: 1px solid var(--linea-suave); text-align: left; vertical-align: top; }
        th { background: #F8FAFC; color: var(--texto-tenue); font-size: 10px; text-transform: uppercase; letter-spacing: .06em; }
        tbody tr:hover { background: var(--bg-hover); }
        .mono { font-family: 'JetBrains Mono', monospace; font-size: 12px; }
        .muted { color: var(--texto-tenue); }
        .badge { display: inline-flex; align-items: center; border-radius: 999px; padding: 3px 10px; font-size: 11px; font-weight: 700; white-space: nowrap; }
        .badge-ok { background: var(--petroleo-wash); color: var(--petroleo); }
        .badge-warn { background: var(--ambar-wash); color: var(--ambar); }
        .badge-danger { background: var(--critico-wash); color: var(--critico); }
        .vacio, .cargando { color: var(--texto-tenue); font-style: italic; padding: 30px 14px; text-align: center; }
        .nota-seguridad { margin-bottom: 14px; border: 1px solid var(--linea); background: #fff; border-radius: 10px; padding: 13px 16px; color: var(--texto-suave); font-size: 13px; }
    </style>
</head>
<body class="tema-oscuro">
<div class="app">
    <aside class="sidebar">
        <div class="marca">
            <img class="marca-logo" src="assets/img/logo.png" alt="Medical Life">
        </div>
        <?php require __DIR__ . '/partials/menu.php'; ?>
    </aside>

    <main class="principal">
        <div class="encabezado-pagina">
            <div>
                <div class="encabezado-pagina__etiqueta">Acceso local</div>
                <h1>Contraseñas técnicas</h1>
                <p>Busca una máquina y genera una nueva contraseña para el panel técnico local.</p>
            </div>
            <div style="display:flex; gap:8px; flex-wrap:wrap;">
                <button class="btn primario" id="btn-master" type="button"><i data-lucide="shield-keyhole"></i>Generar maestra</button>
                <button class="btn" id="btn-recargar" type="button"><i data-lucide="refresh-cw"></i>Actualizar</button>
            </div>
        </div>

        <div class="nota-seguridad" id="nota-master"></div>

        <section class="panel">
            <div class="toolbar">
                <input id="buscar" placeholder="Buscar serie, código, nombre o ubicación">
                <select id="estado">
                    <option value="">Todas</option>
                    <option value="pendiente">Pendientes</option>
                    <option value="activada">Activadas</option>
                    <option value="sin_password">Sin contraseña</option>
                </select>
            </div>
            <div id="tabla" class="cargando">Cargando...</div>
        </section>
    </main>
</div>

<script src="assets/js/comun.js?v=20260811-3"></script>
<script>
const $ = sel => document.querySelector(sel);
let debounce = null;

function fecha(fechaValor) {
    if (!fechaValor) return '-';
    const d = new Date(fechaValor);
    return Number.isNaN(d.getTime()) ? escapeHtml(fechaValor) : d.toLocaleString('es-MX');
}

function badge(texto, clase) {
    return `<span class="badge ${clase}">${escapeHtml(texto)}</span>`;
}

async function cargar() {
    $('#tabla').innerHTML = '<div class="cargando">Cargando...</div>';
    const params = new URLSearchParams();
    const q = $('#buscar').value.trim();
    const estado = $('#estado').value;
    if (q) params.set('q', q);
    if (estado) params.set('estado', estado);
    const json = await obtenerJSON(`api/panel_tecnico_passwords.php?${params}`);
    const maquinas = json.data.maquinas || [];
    $('#nota-master').textContent = json.data.master_password_configurada
        ? 'La contraseña maestra técnica está configurada. Las contraseñas por máquina siguen siendo independientes.'
        : 'No hay contraseña maestra técnica configurada. Usa contraseña individual por máquina o configura PANEL_TECNICO_MASTER_PASSWORD_HASH.';

    if (!maquinas.length) {
        $('#tabla').innerHTML = '<div class="vacio">No hay máquinas que coincidan.</div>';
        return;
    }

    $('#tabla').innerHTML = `<div class="table-shell"><table>
        <thead><tr>
            <th>Máquina</th><th>Activación</th><th>Contraseña técnica</th><th>Acción</th>
        </tr></thead>
        <tbody>${maquinas.map(m => `
            <tr>
                <td>
                    <strong class="mono">${escapeHtml(m.numero_serie)}</strong>
                    <div>${escapeHtml(m.nombre_maquina || '')}</div>
                    <div class="muted">${escapeHtml(m.codigo_interno || '')}${m.nombre_ubicacion ? ' · ' + escapeHtml(m.nombre_ubicacion) : ''}</div>
                </td>
                <td>
                    ${Number(m.activada) === 1 ? badge('Activada', 'badge-ok') : badge('Pendiente', 'badge-warn')}
                    <div class="muted">${fecha(m.fecha_ultima_activacion || m.fecha_activacion)}</div>
                </td>
                <td>
                    ${Number(m.password_generada) === 1 ? badge('Generada', 'badge-ok') : badge('Sin generar', 'badge-danger')}
                    <div class="muted">${fecha(m.panel_tecnico_password_generada)}</div>
                </td>
                <td><button class="btn primario btn-generar" data-id="${m.id_maquina}" type="button"><i data-lucide="key-round"></i>Generar nueva</button></td>
            </tr>
        `).join('')}</tbody>
    </table></div>`;

    document.querySelectorAll('.btn-generar').forEach(btn => {
        btn.addEventListener('click', () => generar(Number(btn.dataset.id)));
    });
    if (window.mlRenderIcons) window.mlRenderIcons();
}

async function generar(idMaquina) {
    const confirmado = await mlConfirm(
        'La contraseña anterior dejará de funcionar cuando la app vuelva a sincronizar configuración.',
        'Generar nueva contraseña técnica',
        'Generar'
    );
    if (!confirmado) return;

    try {
        const res = await fetch('api/panel_tecnico_password.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id_maquina: idMaquina }),
        });
        const json = await res.json();
        if (!json.ok) throw new Error(json.error || 'No se pudo generar la contraseña');
        await mlAlert(
            `Máquina ${json.data.numero_serie}\nContraseña técnica: ${json.data.password}\n\nSe muestra una sola vez.`,
            'success',
            'Contraseña generada'
        );
        await cargar();
    } catch (e) {
        await mlAlert(e.message || 'No se pudo generar la contraseña');
    }
}

async function generarMaestra() {
    const confirmado = await mlConfirm(
        'La contraseña maestra anterior dejará de funcionar cuando las máquinas sincronicen configuración.',
        'Generar contraseña maestra',
        'Generar'
    );
    if (!confirmado) return;

    try {
        const res = await fetch('api/panel_tecnico_master_password.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({}),
        });
        const json = await res.json();
        if (!json.ok) throw new Error(json.error || 'No se pudo generar la maestra');
        await mlAlert(
            `Contraseña maestra técnica:\n${json.data.password}\n\nSe muestra una sola vez.`,
            'success',
            'Maestra generada'
        );
        await cargar();
    } catch (e) {
        await mlAlert(e.message || 'No se pudo generar la contraseña maestra');
    }
}

$('#buscar').addEventListener('input', () => {
    clearTimeout(debounce);
    debounce = setTimeout(() => cargar().catch(e => mlAlert(e.message)), 300);
});
$('#estado').addEventListener('change', () => cargar().catch(e => mlAlert(e.message)));
$('#btn-recargar').addEventListener('click', () => cargar().catch(e => mlAlert(e.message)));
$('#btn-master').addEventListener('click', () => generarMaestra());
cargar().catch(e => {
    $('#tabla').innerHTML = `<div class="vacio">${escapeHtml(e.message)}</div>`;
});
</script>
</body>
</html>
