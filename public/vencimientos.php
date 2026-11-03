<?php
// public/vencimientos.php — lotes vencidos/por vencer en vendingm.lotes.
// 100% dentro de vendingm (no depende de cat_SucursalClinica/farmacia — ver
// decisión del 2026-08-24 en la memoria del proyecto). Complementa las
// alertas rápidas de dashboard.php/reportes.php con una vista dedicada y la
// acción de retirar un lote vencido.
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/bootstrap.php';

use App\Auth\Auth;

Auth::requireAdmin();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>MDM - Caducos</title>
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
        .encabezado-pagina p { margin: 0 0 20px; color: var(--texto-suave); font-size: 13.5px; max-width: 620px; }
        .aviso-esquema { background: var(--ambar-wash); border: 1px solid var(--ambar); color: var(--ambar); border-radius: 10px; padding: 10px 14px; font-size: 12.5px; margin-bottom: 18px; }

        .toolbar-card { background: var(--bg-elevada); border: 1px solid var(--linea); border-radius: 14px; padding: 14px 16px; margin-bottom: 14px; }
        .barra { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
        .barra label { font-size: 12.5px; color: var(--texto-suave); }
        .barra input[type="number"] { width: 90px; padding: 9px 11px; border: 1px solid var(--linea); border-radius: 8px; font-size: 13px; background: var(--bg); color: var(--texto); font-family: inherit; }
        .barra button { border: none; background: var(--petroleo); color: #06201F; font-family: inherit; font-weight: 600; font-size: 13px; padding: 9px 16px; border-radius: 8px; cursor: pointer; }
        .contador { font-size: 12px; color: var(--texto-tenue); margin-left: auto; white-space: nowrap; }

        .card { background: var(--bg-elevada); border: 1px solid var(--linea); border-radius: 14px; }
        .vacio, .cargando { color: var(--texto-tenue); font-style: italic; padding: 40px 14px; text-align: center; }
        table.tabla-venc { width: 100%; border-collapse: collapse; }
        table.tabla-venc th, table.tabla-venc td { padding: 12px 16px; text-align: left; font-size: 13px; border-bottom: 1px solid var(--linea-suave); }
        table.tabla-venc th { color: var(--texto-tenue); font-size: 11px; text-transform: uppercase; letter-spacing: .04em; }
        table.tabla-venc tr:last-child td { border-bottom: none; }
        table.tabla-venc tr:hover { background: var(--bg-hover); }
        .badge { display: inline-flex; align-items: center; gap: 5px; padding: 3px 10px; border-radius: 999px; font-size: 11px; font-weight: 600; }
        .badge-danger { background: var(--critico-wash); color: var(--critico); }
        .badge-warn { background: var(--ambar-wash); color: var(--ambar); }
        .btn-devolver { border: 1px solid var(--linea); background: var(--bg); color: var(--texto-suave); border-radius: 7px; padding: 6px 12px; cursor: pointer; font: inherit; font-size: 12px; }
        .btn-devolver:hover { color: var(--critico); border-color: var(--critico); }
        .mono { font-family: 'JetBrains Mono', monospace; }
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
            <h1>Caducos</h1>
            <p>Lotes vencidos o próximos a vencer en las máquinas (vendingm.lotes). "Retirar" descuenta el lote como salida por caducidad — no se puede deshacer.</p>
        </div>

        <div class="toolbar-card">
            <div class="barra">
                <label for="dias">Días de anticipación</label>
                <input type="number" id="dias" min="1" max="365" value="30">
                <button type="button" id="btn-buscar">Buscar</button>
                <span class="contador" id="contador"></span>
            </div>
        </div>

        <div class="card">
            <div id="lista-vencimientos" class="cargando">Cargando…</div>
        </div>
    </main>
</div>

<script src="assets/js/comun.js?v=20260912-1"></script>
<script>
    function badgeDias(d) {
        const dias = Number(d);
        if (dias < 0) return `<span class="badge badge-danger">Vencido hace ${Math.abs(dias)} día${Math.abs(dias) === 1 ? '' : 's'}</span>`;
        return `<span class="badge badge-warn">${dias} día${dias === 1 ? '' : 's'}</span>`;
    }

    function filaVencimiento(v) {
        return `<tr data-id="${v.id_lote}">
            <td><b class="mono">${escapeHtml(v.numero_serie)}</b><br><span class="muted">${escapeHtml(v.nombre_ubicacion) || 'Sin ubicación'}</span></td>
            <td>C${escapeHtml(v.numero_carril)}</td>
            <td>${escapeHtml(v.nombre_producto) || '—'}</td>
            <td class="mono">${escapeHtml(v.numero_lote) || '—'}</td>
            <td>${badgeDias(v.dias_restantes)}</td>
            <td>${v.cantidad_actual}</td>
            <td><button type="button" class="btn-devolver" data-id="${v.id_lote}" data-disponibles="${v.cantidad_actual}">Retirar</button></td>
        </tr>`;
    }

    async function cargar() {
        const cont = document.getElementById('lista-vencimientos');
        cont.className = 'cargando';
        cont.textContent = 'Cargando…';

        const dias = parseInt(document.getElementById('dias').value, 10) || 30;

        try {
            const json = await obtenerJSON(`api/vencimientos.php?dias=${dias}`);
            document.getElementById('contador').textContent = `${json.data.length} lote(s)`;

            if (json.data.length === 0) {
                cont.className = '';
                cont.innerHTML = '<p class="vacio">No hay lotes vencidos ni próximos a vencer en ese rango.</p>';
                return;
            }

            cont.className = '';
            cont.innerHTML = `<table class="tabla-venc">
                <thead><tr><th>Máquina</th><th>Carril</th><th>Producto</th><th>Lote</th><th>Vence en</th><th>Disponible</th><th></th></tr></thead>
                <tbody>${json.data.map(filaVencimiento).join('')}</tbody>
            </table>`;

            cont.querySelectorAll('.btn-devolver').forEach(btn => {
                btn.addEventListener('click', () => devolver(parseInt(btn.dataset.id, 10), parseInt(btn.dataset.disponibles, 10)));
            });
        } catch (e) {
            cont.className = '';
            cont.innerHTML = '<p class="vacio">No se pudo conectar con el servidor.</p>';
        }
    }

    async function devolver(idLote, disponibles) {
        const cantidadTexto = prompt(`¿Cuántas piezas retirar? (disponibles: ${disponibles})`, String(disponibles));
        if (cantidadTexto === null) return;
        const cantidad = parseInt(cantidadTexto, 10);
        if (!cantidad || cantidad <= 0 || cantidad > disponibles) {
            alert('Cantidad inválida.');
            return;
        }
        if (!confirm(`¿Retirar ${cantidad} pieza(s) por vencimiento? Esta acción no se puede deshacer.`)) return;

        try {
            const res = await fetch('api/vencimientos.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', ...csrfHeader() },
                body: JSON.stringify({ id_lote: idLote, cantidad }),
            });
            const json = await res.json();
            if (!json.ok) {
                alert(json.error || 'No se pudo registrar el retiro.');
                return;
            }
            cargar();
        } catch (e) {
            alert('No se pudo conectar con el servidor.');
        }
    }

    document.getElementById('btn-buscar').addEventListener('click', cargar);
    document.getElementById('dias').addEventListener('keydown', (ev) => { if (ev.key === 'Enter') cargar(); });

    cargar();
</script>
</body>
</html>
