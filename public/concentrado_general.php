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
    <title>MDM - Concentrado general</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/estilo.css?v=20260914-2">
    <style>
        body.tema-oscuro {
            --bg: #F5F7FA;
            --bg-elevada: #FFFFFF;
            --bg-hover: #EEF3F7;
            --linea: #D8E0E7;
            --linea-suave: #E8EEF3;
            --texto: #14212B;
            --texto-suave: #344856;
            --texto-tenue: #647583;
            --petroleo: #087D76;
            --petroleo-wash: rgba(8, 125, 118, .10);
            --ambar: #9A620B;
            --ambar-wash: rgba(154, 98, 11, .12);
            --critico: #B42335;
            --critico-wash: rgba(180, 35, 53, .10);
            background: var(--bg);
            color: var(--texto);
        }
        body.tema-oscuro .sidebar { background: #FFFFFF; border-right: 1px solid var(--linea); box-shadow: 12px 0 30px rgba(20,33,43,.06); }
        body.tema-oscuro .nav-lateral a { color: var(--texto-suave); }
        body.tema-oscuro .nav-lateral a:hover { background: var(--bg-hover); color: var(--texto); }
        body.tema-oscuro .nav-lateral a.activa { background: var(--petroleo-wash); color: var(--petroleo); }
        h1 { margin: 0 0 6px; font-size: 24px; color: var(--texto); letter-spacing: 0; }
        p { margin: 0; color: var(--texto-suave); font-size: 13px; line-height: 1.45; }
        .encabezado-pagina { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; flex-wrap: wrap; margin-bottom: 20px; }
        .encabezado-pagina__etiqueta { color: var(--petroleo); font-size: 11px; letter-spacing: .08em; text-transform: uppercase; font-weight: 700; margin-bottom: 8px; }
        .btn {
            border: 1px solid #C8D3DC; background: #FFFFFF; color: var(--texto);
            border-radius: 8px; padding: 9px 14px; font: inherit; font-size: 13px; font-weight: 700; cursor: pointer;
            box-shadow: 0 2px 6px rgba(20,33,43,.06);
        }
        .btn:hover { border-color: var(--petroleo); color: var(--petroleo); background: #F2F7F6; }
        .btn.primario { border-color: #0B8F86; background: #0B8F86; color: #FFFFFF; }
        .stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; margin-bottom: 14px; }
        .stat, .panel { background: #FFFFFF; border: 1px solid var(--linea); border-radius: 10px; box-shadow: 0 12px 28px rgba(20,33,43,.08); }
        .stat { padding: 16px; min-height: 96px; }
        .stat .lbl { color: var(--texto-suave); font-size: 11px; text-transform: uppercase; letter-spacing: .05em; margin-bottom: 8px; }
        .stat .num { font-family: 'Space Grotesk', sans-serif; font-size: 30px; font-weight: 700; line-height: 1; }
        .stat .nota { color: var(--texto-tenue); font-size: 12px; line-height: 1.35; margin-top: 9px; }
        .stat.ok .num { color: var(--petroleo); }
        .stat-col { display: flex; flex-direction: column; gap: 14px; }
        .stat-mini { padding: 12px 16px; min-height: 0; }
        .stat-mini .lbl { margin-bottom: 6px; }
        .stat-mini-fila { display: flex; gap: 18px; }
        .stat-mini-item { display: flex; flex-direction: column; gap: 2px; }
        .stat-mini-item .num-mini { font-family: 'Space Grotesk', sans-serif; font-size: 19px; font-weight: 700; color: var(--texto); }
        .stat-mini-item .num-mini.en-linea { color: var(--petroleo); }
        .stat-mini-item .etiqueta-mini { color: var(--texto-tenue); font-size: 10.5px; text-transform: uppercase; letter-spacing: .04em; }
        .panel-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; padding: 15px 18px; border-bottom: 1px solid var(--linea-suave); }
        .panel-head h2 { margin: 0; font-size: 15px; color: var(--texto); }
        .panel-head .sub { color: var(--texto-tenue); font-size: 12px; margin-top: 4px; }
        .toolbar { display: grid; grid-template-columns: 1fr auto; gap: 8px; padding: 14px 18px; border-bottom: 1px solid var(--linea-suave); align-items: center; }
        input, select {
            width: 100%; box-sizing: border-box; border: 1px solid #C8D3DC; border-radius: 8px;
            background: #FFFFFF; color: var(--texto); font: inherit; font-size: 13px; padding: 9px 11px;
        }
        .table-shell { overflow: auto; max-height: 68vh; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; min-width: 760px; }
        th, td { padding: 11px 18px; border-bottom: 1px solid var(--linea-suave); text-align: left; vertical-align: top; }
        th { background: #F8FAFC; color: var(--texto-tenue); font-size: 10px; text-transform: uppercase; letter-spacing: .06em; position: sticky; top: 0; z-index: 1; }
        tbody tr:hover { background: var(--bg-hover); }
        .mono { font-family: 'JetBrains Mono', monospace; }
        .muted { color: var(--texto-tenue); }
        .vacio, .cargando { color: var(--texto-tenue); font-style: italic; padding: 34px 14px; text-align: center; }
        .alerta-operacion {
            display: none; margin-bottom: 14px; padding: 11px 13px; border-radius: 8px;
            border: 1px solid rgba(180,35,53,.35); background: var(--critico-wash); color: var(--critico); font-size: 13px;
        }
        .alerta-operacion.visible { display: block; }
        @media (max-width: 980px) {
            .stats { grid-template-columns: repeat(2, 1fr); }
            .toolbar { grid-template-columns: 1fr; }
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
                <div class="encabezado-pagina__etiqueta">Consulta operativa</div>
                <h1>Concentrado general</h1>
                <p>Totales por clave de salud sumando todas las Máquinas dispensadoras: capacidad, disponible y dispensado.</p>
            </div>
            <button class="btn primario" id="btn-actualizar">Actualizar</button>
        </div>

        <div id="alerta-operacion" class="alerta-operacion"></div>

        <div class="stats">
            <div class="stat-col">
                <div class="stat ok"><div class="lbl">Medicamentos</div><div class="num" id="kpi-medicamentos">-</div><div class="nota">Claves distintas con stock o dispensadas.</div></div>
                <div class="stat stat-mini">
                    <div class="lbl">Máquinas</div>
                    <div class="stat-mini-fila">
                        <div class="stat-mini-item"><span class="num-mini en-linea" id="kpi-en-linea">-</span><span class="etiqueta-mini">En línea</span></div>
                        <div class="stat-mini-item"><span class="num-mini" id="kpi-maestras">-</span><span class="etiqueta-mini">Maestras</span></div>
                    </div>
                </div>
            </div>
            <div class="stat"><div class="lbl">Disponible total</div><div class="num" id="kpi-disponible">-</div><div class="nota">Suma de existencias en todas las máquinas.</div></div>
            <div class="stat"><div class="lbl">Dispensado total</div><div class="num" id="kpi-dispensado">-</div><div class="nota">Ventas históricas, todas las máquinas.</div></div>
        </div>

        <section class="panel">
            <div class="panel-head">
                <div>
                    <h2>Concentrado por medicamento</h2>
                    <div class="sub">Una fila por clave de salud, sumando todas las Máquinas dispensadoras que lo manejan.</div>
                </div>
            </div>
            <div class="toolbar">
                <input id="buscar-concentrado" placeholder="Buscar por clave, producto o código de barras">
                <button class="btn" id="btn-limpiar">Limpiar</button>
            </div>
            <div id="tabla-concentrado" class="cargando">Cargando...</div>
        </section>
    </main>
</div>

<script src="assets/js/comun.js?v=20260912-1"></script>
<script>
    let debounceConcentrado = null;

    function fmt(n) { return (Number(n) || 0).toLocaleString('es-MX'); }

    function mostrarError(mensaje) {
        const alerta = document.getElementById('alerta-operacion');
        alerta.textContent = mensaje || '';
        alerta.className = mensaje ? 'alerta-operacion visible' : 'alerta-operacion';
    }

    function paramsConcentrado() {
        const params = new URLSearchParams();
        const q = document.getElementById('buscar-concentrado').value.trim();
        if (q) params.set('q', q);
        return params;
    }

    // Estado por la maquina MAS CRITICA de este medicamento, no por el
    // agregado total (ver api/concentrado_general.php, pct_minimo) --
    // amarillo 50-60%, rojo <=30%. Al <=30% el pedido real a CEDIS de esa
    // maquina/carril ya se autogenero solo (sin aprobacion) al dispensar --
    // este badge es solo el indicador visual, no dispara nada por si mismo.
    function badgeEstadoCapacidad(f) {
        if (f.estado_capacidad === 'amarillo') return '<span class="badge badge-warn">Stock bajo</span>';
        if (f.estado_capacidad === 'rojo') return '<span class="badge badge-danger">Critico</span>';
        return '<span class="badge badge-ok">OK</span>';
    }

    function renderTabla(filas) {
        const cont = document.getElementById('tabla-concentrado');
        cont.className = '';
        if (!filas.length) {
            cont.innerHTML = '<p class="vacio">Sin medicamentos para este filtro.</p>';
            return;
        }

        cont.innerHTML = `<div class="table-shell"><table>
            <thead><tr><th>Clave</th><th>Producto</th><th>Capacidad total</th><th>Disponible</th><th>Dispensado</th><th>Máquinas</th><th>Estado</th></tr></thead>
            <tbody>${filas.map(f => `<tr>
                <td class="mono">${escapeHtml(f.clave) || '-'}</td>
                <td>${escapeHtml(f.producto) || '<span class="muted">Sin producto</span>'}<br><span class="muted">${escapeHtml(f.presentacion) || ''}</span></td>
                <td class="mono">${fmt(f.capacidad_maxima_total)}</td>
                <td class="mono">${fmt(f.restante_total)}</td>
                <td class="mono">${fmt(f.dispensado_total)}</td>
                <td class="mono">${fmt(f.maquinas_con_producto)}</td>
                <td>${badgeEstadoCapacidad(f)}</td>
            </tr>`).join('')}</tbody>
        </table></div>`;
    }

    async function cargarConcentrado() {
        const cont = document.getElementById('tabla-concentrado');
        const btn = document.getElementById('btn-actualizar');
        cont.className = 'cargando';
        cont.textContent = 'Cargando...';
        btn.disabled = true;
        mostrarError('');
        try {
            const json = await obtenerJSON(`api/concentrado_general.php?${paramsConcentrado()}`);
            document.getElementById('kpi-medicamentos').textContent = fmt(json.data.resumen.medicamentos);
            document.getElementById('kpi-disponible').textContent = fmt(json.data.resumen.restante_total);
            document.getElementById('kpi-dispensado').textContent = fmt(json.data.resumen.dispensado_total);
            document.getElementById('kpi-en-linea').textContent = fmt(json.data.resumen.maquinas_en_linea) + ' / ' + fmt(json.data.resumen.maquinas_total);
            document.getElementById('kpi-maestras').textContent = fmt(json.data.resumen.maquinas_maestras);
            renderTabla(json.data.filas);
        } catch (e) {
            cont.className = '';
            cont.innerHTML = '<p class="vacio">No se pudo cargar el concentrado.</p>';
            mostrarError(e.message || 'No se pudo cargar el concentrado.');
        } finally {
            btn.disabled = false;
        }
    }

    document.getElementById('btn-actualizar').addEventListener('click', cargarConcentrado);
    document.getElementById('btn-limpiar').addEventListener('click', () => {
        document.getElementById('buscar-concentrado').value = '';
        cargarConcentrado();
    });
    document.getElementById('buscar-concentrado').addEventListener('input', () => {
        clearTimeout(debounceConcentrado);
        debounceConcentrado = setTimeout(cargarConcentrado, 300);
    });

    cargarConcentrado();
</script>
</body>
</html>
