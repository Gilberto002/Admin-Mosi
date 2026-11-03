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
    <title>MDM - Reportes</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/estilo.css?v=20260914-2">
    <style>
        body.tema-oscuro {
            --bg: #111820;
            --bg-elevada: #17212B;
            --bg-hover: #22303D;
            --linea: #2C3A47;
            --linea-suave: #24313D;
            --texto: #EDF4F7;
            --texto-suave: #AAB8C2;
            --texto-tenue: #738391;
            --petroleo: #2CB7A5;
            --petroleo-wash: rgba(44, 183, 165, .14);
            --ambar: #D6A34A;
            --critico: #E06C78;
            background: var(--bg);
            color: var(--texto);
        }
        .reportes-hero {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 18px;
        }
        .reportes-hero h1 { margin: 0 0 5px; font-size: 24px; }
        .reportes-hero p { margin: 0; color: var(--texto-suave); max-width: 680px; line-height: 1.45; }
        .panel-reportes {
            display: grid;
            grid-template-columns: 330px minmax(0, 1fr);
            gap: 16px;
            align-items: start;
        }
        .reporte-form,
        .reporte-preview {
            background: var(--bg-elevada);
            border: 1px solid var(--linea);
            border-radius: 10px;
            box-shadow: 0 14px 34px rgba(0,0,0,.18);
        }
        .reporte-form { padding: 18px; }
        .reporte-form h2,
        .reporte-preview h2 { margin: 0; font-size: 15px; }
        .reporte-form .grupo { margin-top: 14px; }
        .reporte-form label {
            display: block;
            color: var(--texto-suave);
            font-weight: 600;
            font-size: 12px;
            margin-bottom: 6px;
        }
        .reporte-form input,
        .reporte-form select {
            width: 100%;
            border: 1px solid var(--linea);
            background: var(--bg);
            color: var(--texto);
            border-radius: 8px;
            padding: 10px 11px;
            font: inherit;
            font-size: 13px;
        }
        .dos-campos { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        .acciones-reporte { display: grid; gap: 8px; margin-top: 18px; }
        .acciones-reporte button {
            border: 1px solid var(--linea);
            border-radius: 8px;
            padding: 10px 12px;
            font: inherit;
            font-weight: 700;
            cursor: pointer;
        }
        .acciones-reporte .primario { background: var(--petroleo); color: #06201F; border-color: var(--petroleo); }
        .acciones-reporte .secundario { background: var(--bg); color: var(--texto-suave); }
        .acciones-reporte button:disabled { opacity: .5; cursor: default; }
        .ayuda {
            margin-top: 12px;
            color: var(--texto-tenue);
            font-size: 12px;
            line-height: 1.45;
        }
        .preview-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 16px 18px;
            border-bottom: 1px solid var(--linea-suave);
        }
        .preview-meta { color: var(--texto-tenue); font-size: 12px; }
        .table-wrap { overflow: auto; max-height: 620px; }
        body.tema-oscuro table { min-width: 900px; }
        body.tema-oscuro th,
        body.tema-oscuro td { white-space: nowrap; }
        .estado-vacio {
            padding: 52px 18px;
            text-align: center;
            color: var(--texto-tenue);
            font-style: italic;
        }
        @media (max-width: 980px) {
            .panel-reportes { grid-template-columns: 1fr; }
            .reportes-hero { flex-direction: column; }
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
        <div class="reportes-hero">
            <div>
                <h1>Reportes</h1>
                <p>Genera archivos CSV de operación, máquinas, medicamentos, stock y caducidades para revisar o abrir en Excel.</p>
            </div>
        </div>

        <div class="panel-reportes">
            <form class="reporte-form" id="form-reportes">
                <h2>Generador</h2>

                <div class="grupo">
                    <label for="tipo">Tipo de reporte</label>
                    <select id="tipo" name="tipo">
                        <option value="maquinas_estado">Estado de máquinas</option>
                        <option value="stock_actual">Stock actual por carril</option>
                        <option value="caducidades">Caducidades</option>
                        <option value="medicamentos_movimiento">Medicamentos con más movimiento</option>
                        <option value="resumen_red">Resumen general de red</option>
                    </select>
                </div>

                <div class="grupo">
                    <label for="q">Buscar</label>
                    <input id="q" name="q" type="text" placeholder="Serie, medicamento, código o lote">
                </div>

                <div class="grupo dos-campos" id="grupo-fechas">
                    <div>
                        <label for="desde">Desde</label>
                        <input id="desde" name="desde" type="date">
                    </div>
                    <div>
                        <label for="hasta">Hasta</label>
                        <input id="hasta" name="hasta" type="date">
                    </div>
                </div>

                <div class="grupo dos-campos">
                    <div id="grupo-dias">
                        <label for="dias">Caduca en días</label>
                        <input id="dias" name="dias" type="number" min="0" value="30">
                    </div>
                    <div>
                        <label for="limit">Límite</label>
                        <input id="limit" name="limit" type="number" min="1" max="5000" value="1000">
                    </div>
                </div>

                <div class="acciones-reporte">
                    <button type="submit" class="primario" id="btn-generar">Generar vista previa</button>
                    <button type="button" class="secundario" id="btn-descargar" disabled>Descargar CSV</button>
                </div>

                <div class="ayuda" id="ayuda-reporte">
                    El reporte respeta los permisos de máquinas asignadas al usuario.
                </div>
            </form>

            <section class="reporte-preview">
                <div class="preview-head">
                    <h2 id="preview-titulo">Vista previa</h2>
                    <span class="preview-meta" id="preview-meta">Sin reporte generado</span>
                </div>
                <div id="preview" class="estado-vacio">Elige un tipo de reporte y presiona Generar vista previa.</div>
            </section>
        </div>
    </main>
</div>

<script src="assets/js/comun.js?v=20260912-1"></script>
<script>
    let reporteActual = null;

    const nombres = {
        resumen_red: 'Resumen general de red',
        maquinas_estado: 'Estado de maquinas',
        stock_actual: 'Stock actual por carril',
        caducidades: 'Caducidades',
        medicamentos_movimiento: 'Medicamentos con mas movimiento',
    };

    function configurarCampos() {
        const tipo = document.getElementById('tipo').value;
        document.getElementById('grupo-fechas').style.display = tipo === 'medicamentos_movimiento' ? 'grid' : 'none';
        document.getElementById('grupo-dias').style.display = tipo === 'caducidades' ? '' : 'none';
    }

    function csvValor(valor) {
        return `"${String(valor ?? '').replace(/"/g, '""')}"`;
    }

    function descargarCsv(nombre, columns, rows) {
        const lineas = [columns, ...rows].map(fila => fila.map(csvValor).join(',')).join('\r\n');
        const blob = new Blob([lineas], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = nombre;
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(url);
    }

    function renderTabla(columns, rows) {
        if (!rows.length) {
            return '<div class="estado-vacio">No hay datos para estos filtros.</div>';
        }
        const muestra = rows.slice(0, 100);
        return `<div class="table-wrap"><table>
            <thead><tr>${columns.map(c => `<th>${escapeHtml(c)}</th>`).join('')}</tr></thead>
            <tbody>${muestra.map(row => `<tr>${row.map(v => `<td>${escapeHtml(v)}</td>`).join('')}</tr>`).join('')}</tbody>
        </table></div>`;
    }

    async function generar(ev) {
        ev.preventDefault();
        const btn = document.getElementById('btn-generar');
        const btnDescargar = document.getElementById('btn-descargar');
        const preview = document.getElementById('preview');
        const tipo = document.getElementById('tipo').value;
        const params = new URLSearchParams(new FormData(document.getElementById('form-reportes')));

        btn.disabled = true;
        btnDescargar.disabled = true;
        preview.className = 'estado-vacio';
        preview.textContent = 'Generando reporte...';

        try {
            const json = await obtenerJSON(`api/reportes.php?${params}`);
            reporteActual = { tipo, columns: json.columns, rows: json.data };
            document.getElementById('preview-titulo').textContent = nombres[tipo] || 'Reporte';
            document.getElementById('preview-meta').textContent = `${json.data.length} fila(s) generadas`;
            preview.className = '';
            preview.innerHTML = renderTabla(json.columns, json.data);
            btnDescargar.disabled = false;
        } catch (e) {
            reporteActual = null;
            preview.className = 'estado-vacio';
            preview.textContent = e.message || 'No se pudo generar el reporte.';
        } finally {
            btn.disabled = false;
        }
    }

    document.getElementById('tipo').addEventListener('change', configurarCampos);
    document.getElementById('form-reportes').addEventListener('submit', generar);
    document.getElementById('btn-descargar').addEventListener('click', () => {
        if (!reporteActual) return;
        const fecha = new Date().toISOString().slice(0, 10);
        descargarCsv(`medical-life-${reporteActual.tipo}-${fecha}.csv`, reporteActual.columns, reporteActual.rows);
    });

    configurarCampos();
</script>
</body>
</html>
