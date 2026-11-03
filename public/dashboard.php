<?php
// public/dashboard.php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/bootstrap.php';

use App\Auth\Auth;

Auth::requireLogin();
if (Auth::isCliente()) {
    header('Location: cliente_dashboard.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>MDM - Dashboard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/estilo.css?v=20260914-2">
    <style>
        /* ================================================================
           Dashboard — "sala de monitoreo clínico"
           Paleta: carbón #0A0F14, texto #EEF4F7, acento petróleo #159485/#2CD9BE
           Firma visual: línea de pulso (ECG) animada en el encabezado —
           referencia directa a "salud de la red" / MDM.
           Se aplica solo a esta vista mediante body.tema-oscuro para no
           afectar admin.php ni maquina.php.
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
                radial-gradient(1100px 550px at 12% -12%, rgba(44, 217, 190, .07), transparent 60%),
                radial-gradient(900px 500px at 100% 0%, rgba(228, 172, 79, .04), transparent 55%),
                var(--bg);
            color: var(--texto);
        }
        body.tema-oscuro .sidebar {
            background: var(--bg-elevada);
            border-right: 1px solid var(--linea);
        }
        body.tema-oscuro .marca .icono-enlace { color: var(--petroleo); }
        body.tema-oscuro .marca .texto small { color: var(--texto-tenue); }
        body.tema-oscuro .nav-lateral a { color: var(--texto-suave); }
        body.tema-oscuro .nav-lateral a:hover { background: var(--bg-hover); color: var(--texto); }
        body.tema-oscuro .nav-lateral a.activa { background: var(--petroleo-wash); color: var(--petroleo); }

        body.tema-oscuro h1, body.tema-oscuro h2, body.tema-oscuro h3 { color: var(--texto); }

        body.tema-oscuro .btn-actualizar {
            display: inline-flex; align-items: center; gap: 8px;
            background: var(--petroleo-wash); border: 1px solid rgba(44,217,190,.35);
            color: var(--petroleo); padding: 9px 16px; border-radius: var(--radius-sm);
            font-family: inherit; font-size: 13px; font-weight: 600; cursor: pointer;
            transition: background .15s ease, transform .1s ease;
        }
        body.tema-oscuro .btn-actualizar:hover { background: rgba(44,217,190,.22); }
        body.tema-oscuro .btn-actualizar:active { transform: scale(.97); }
        body.tema-oscuro .btn-actualizar:disabled { opacity: .5; cursor: default; }
        body.tema-oscuro .btn-actualizar svg { transition: transform .5s ease; }
        body.tema-oscuro .btn-actualizar.girando svg { animation: girar .7s linear; }
        @keyframes girar { to { transform: rotate(360deg); } }
        body.tema-oscuro .hora-actualizacion { color: var(--texto-tenue); font-size: 11.5px; margin-top: 6px; text-align: right; }
        .acciones-dashboard { display: flex; gap: 8px; justify-content: flex-end; flex-wrap: wrap; }
        body.tema-oscuro .btn-reporte {
            display: inline-flex; align-items: center; gap: 8px;
            background: rgba(255,255,255,.04); border: 1px solid var(--linea);
            color: var(--texto-suave); padding: 9px 16px; border-radius: var(--radius-sm);
            font-family: inherit; font-size: 13px; font-weight: 600; cursor: pointer;
        }
        body.tema-oscuro .btn-reporte:hover { border-color: var(--petroleo); color: var(--petroleo); }

        /* ==================== HERO — panel de pulso ==================== */
        .hero-pulso {
            position: relative;
            background: linear-gradient(160deg, var(--bg-elevada) 0%, var(--bg-elevada-2) 100%);
            border: 1px solid var(--linea);
            border-radius: var(--radius-lg);
            padding: 24px 26px 20px;
            margin-bottom: 18px;
            overflow: hidden;
        }
        .hero-pulso::before {
            content: ""; position: absolute; inset: 0 0 auto 0; height: 1px;
            background: linear-gradient(90deg, transparent, var(--petroleo) 45%, transparent);
            opacity: .55;
        }
        .hero-pulso__encabezado {
            display: flex; align-items: flex-start; justify-content: space-between;
            gap: 16px; flex-wrap: wrap;
        }
        .hero-pulso__etiqueta {
            display: inline-flex; align-items: center; gap: 7px;
            font-size: 11px; letter-spacing: .08em; text-transform: uppercase;
            color: var(--petroleo); font-weight: 700; margin-bottom: 9px;
        }
        .punto-vivo {
            width: 7px; height: 7px; border-radius: 50%; background: var(--petroleo);
            box-shadow: 0 0 0 0 rgba(44,217,190,.55);
            animation: latir-punto 1.8s ease-in-out infinite;
        }
        @keyframes latir-punto {
            0%, 100% { box-shadow: 0 0 0 0 rgba(44,217,190,.55); }
            50% { box-shadow: 0 0 0 6px rgba(44,217,190,0); }
        }
        .hero-pulso h1 { font-size: 23px; margin: 0 0 4px; letter-spacing: -.01em; }
        .hero-pulso p { margin: 0; color: var(--texto-suave); font-size: 13.5px; max-width: 48ch; }

        .hero-pulso__linea { display: none; }
        .ecg-svg { width: 100%; height: 100%; display: block; }
        .ecg-grupo { transform-box: fill-box; animation: desplazar-ecg 7s linear infinite; }
        @keyframes desplazar-ecg { from { transform: translateX(0); } to { transform: translateX(-50%); } }
        .ecg-linea-sombra {
            fill: none; stroke: var(--petroleo); stroke-width: 7;
            stroke-linecap: round; stroke-linejoin: round; opacity: .16; filter: blur(4px);
        }
        .ecg-linea {
            fill: none; stroke: var(--petroleo); stroke-width: 2.4;
            stroke-linecap: round; stroke-linejoin: round;
            filter: drop-shadow(0 0 5px rgba(44,217,190,.5));
        }
        .ecg-base { stroke: var(--linea-suave); stroke-width: 1; }

        .hero-pulso__chips { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; }
        .chip-estado {
            display: flex; align-items: center; gap: 11px;
            background: rgba(255,255,255,.03); border: 1px solid var(--linea-suave);
            border-radius: var(--radius-sm); padding: 11px 14px;
        }
        .chip-estado__icono {
            width: 32px; height: 32px; border-radius: 9px; flex-shrink: 0;
            display: flex; align-items: center; justify-content: center;
            background: rgba(255,255,255,.04);
        }
        .chip-estado b { display: block; font-size: 12.5px; color: var(--texto); margin-bottom: 1px; }
        .chip-estado span { font-size: 12px; color: var(--texto-suave); line-height: 1.3; }

        @media (max-width: 720px) {
            .hero-pulso__chips { grid-template-columns: 1fr; }
        }

        /* Resumen transversal del sistema */
        .resumen-sistema {
            display: grid; grid-template-columns: repeat(6, minmax(0, 1fr));
            gap: 10px; margin-bottom: 14px;
        }
        .metrica-sistema {
            min-width: 0; padding: 14px; border: 1px solid var(--linea); border-radius: 8px;
            background: var(--bg-elevada); color: inherit; text-decoration: none;
            transition: border-color .15s ease, background .15s ease;
        }
        .metrica-sistema:hover { border-color: var(--petroleo); background: var(--bg-hover); }
        .metrica-sistema__cabecera { display: flex; align-items: center; justify-content: space-between; gap: 8px; color: var(--texto-tenue); }
        .metrica-sistema__cabecera span { font-size: 10px; text-transform: uppercase; font-weight: 700; }
        .metrica-sistema__valor { font-family: 'Space Grotesk', sans-serif; font-size: 27px; line-height: 1; font-weight: 700; margin-top: 12px; }
        .metrica-sistema__detalle { color: var(--texto-tenue); font-size: 11px; margin-top: 5px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .metrica-sistema.alerta .metrica-sistema__valor { color: var(--critico); }
        .metrica-sistema.correcta .metrica-sistema__valor { color: var(--petroleo); }
        .metrica-sistema.aviso .metrica-sistema__valor { color: var(--ambar); }

        .panorama-operativo { display: grid; grid-template-columns: 1.15fr 1fr 1.25fr; gap: 14px; margin-bottom: 14px; }
        .panorama-panel { background: var(--bg-elevada); border: 1px solid var(--linea); border-radius: 8px; min-width: 0; }
        .panorama-panel__cabecera { display: flex; align-items: center; justify-content: space-between; padding: 14px 16px; border-bottom: 1px solid var(--linea-suave); }
        .panorama-panel__cabecera h2 { margin: 0; font-size: 14px; }
        .panorama-panel__cabecera a { color: var(--petroleo); font-size: 11px; font-weight: 700; text-decoration: none; }
        .panorama-panel__cuerpo { padding: 14px 16px; }
        .conexion-barra { display: flex; height: 8px; overflow: hidden; border-radius: 4px; background: var(--linea-suave); margin-bottom: 13px; }
        .conexion-barra span { height: 100%; min-width: 0; transition: width .3s ease; }
        .resumen-lineas { display: grid; gap: 8px; }
        .resumen-linea { display: flex; align-items: center; justify-content: space-between; gap: 12px; font-size: 12px; color: var(--texto-suave); }
        .resumen-linea strong { color: var(--texto); font-family: 'JetBrains Mono', monospace; }
        .indicador { width: 7px; height: 7px; border-radius: 50%; display: inline-block; margin-right: 7px; }
        .lista-compacta { margin: 13px -16px -14px; border-top: 1px solid var(--linea-suave); }
        .item-compacto { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 9px 16px; border-bottom: 1px solid var(--linea-suave); font-size: 11.5px; }
        a.item-compacto { color: inherit; text-decoration: none; }
        a.item-compacto:hover { background: var(--bg-hover); }
        .item-compacto:last-child { border-bottom: none; }
        .item-compacto b { display: block; color: var(--texto); font-size: 12px; }
        .item-compacto small { color: var(--texto-tenue); }
        .item-compacto .dato { color: var(--texto-suave); text-align: right; white-space: nowrap; }
        .sin-datos-compacto { color: var(--texto-tenue); font-size: 12px; padding: 12px 0; }

        /* -------- Bento: fila superior -------- */
        .bento-superior {
            display: grid;
            grid-template-columns: 1.7fr 1fr 1fr;
            gap: 14px;
            margin-bottom: 14px;
        }
        .bento-card {
            background: var(--bg-elevada);
            border: 1px solid var(--linea);
            border-radius: var(--radius-lg);
            padding: 20px;
            box-shadow: 0 1px 0 rgba(255,255,255,.02) inset, 0 14px 26px -20px rgba(0,0,0,.65);
        }
        .bento-card .titulo-card {
            font-size: 12px; text-transform: uppercase; letter-spacing: .05em;
            color: var(--texto-tenue); font-weight: 600; margin-bottom: 14px;
        }

        /* Card de salud global con donut */
        .card-salud { display: flex; align-items: center; gap: 22px; }
        .donut-envoltura {
            position: relative; width: 128px; height: 128px; flex-shrink: 0;
            filter: drop-shadow(0 0 14px rgba(44,217,190,.14));
        }
        .donut {
            width: 128px; height: 128px; border-radius: 50%;
            background: conic-gradient(
                var(--petroleo) 0 calc(var(--pct-ok, 85) * 1%),
                var(--ambar) calc(var(--pct-ok, 85) * 1%) calc((var(--pct-ok, 85) + var(--pct-warn, 10)) * 1%),
                var(--critico) calc((var(--pct-ok, 85) + var(--pct-warn, 10)) * 1%) 100%
            );
            mask: radial-gradient(farthest-side, transparent calc(100% - 18px), #000 calc(100% - 17px));
            -webkit-mask: radial-gradient(farthest-side, transparent calc(100% - 18px), #000 calc(100% - 17px));
        }
        .donut-centro {
            position: absolute; inset: 0; display: flex; flex-direction: column;
            align-items: center; justify-content: center;
        }
        .donut-centro .num { font-family: 'Space Grotesk', sans-serif; font-size: 25px; font-weight: 700; line-height: 1; }
        .donut-centro .lbl { font-size: 10px; color: var(--texto-tenue); margin-top: 2px; }
        .leyenda-donut { display: flex; flex-direction: column; gap: 8px; font-size: 12.5px; flex: 1; }
        .leyenda-donut .item { display: flex; align-items: center; gap: 8px; color: var(--texto-suave); }
        .leyenda-donut .punto { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
        .leyenda-donut .valor { color: var(--texto); font-weight: 600; margin-left: auto; font-family: 'JetBrains Mono', monospace; }

        .distribution-bar {
            display: flex; height: 8px; border-radius: 999px; overflow: hidden;
            background: var(--linea-suave); margin-top: 4px;
        }
        .distribution-bar span { display: block; height: 100%; transition: width .4s ease; }

        /* Cards KPI simples */
        .bento-card .kpi-num {
            font-family: 'Space Grotesk', sans-serif; font-size: 34px; font-weight: 700;
            color: var(--petroleo); line-height: 1;
        }
        .bento-card .kpi-sub { font-size: 12.5px; color: var(--texto-suave); margin-top: 8px; }
        .kpi-card-doble { display: grid; gap: 14px; align-content: space-between; }
        .kpi-card-doble .kpi-icono {
            width: 34px; height: 34px; border-radius: 8px; display: flex; align-items: center; justify-content: center;
            background: rgba(255,255,255,.04); border: 1px solid var(--linea-suave); margin-bottom: 6px;
        }

        /* -------- Bento: fila media -------- */
        .bento-media { display: grid; grid-template-columns: 2fr 1fr; gap: 14px; margin-bottom: 14px; align-items: start; }

        .panel-oscuro {
            background: var(--bg-elevada); border: 1px solid var(--linea); border-radius: var(--radius-lg);
            box-shadow: 0 1px 0 rgba(255,255,255,.02) inset, 0 14px 26px -20px rgba(0,0,0,.65);
        }
        .panel-cabecera-osc {
            display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;
            padding: 16px 20px; border-bottom: 1px solid var(--linea-suave);
        }
        .panel-cabecera-osc h2 { font-size: 14.5px; margin: 0; }

        .tabs-filtro { display: flex; gap: 4px; background: var(--bg); border-radius: 999px; padding: 3px; }
        .tabs-filtro button {
            border: none; background: none; color: var(--texto-suave); font-family: inherit;
            font-size: 12px; font-weight: 600; padding: 6px 13px; border-radius: 999px; cursor: pointer;
            transition: background .15s ease, color .15s ease;
        }
        .tabs-filtro button.activo { background: var(--petroleo-wash); color: var(--petroleo); }

        .campo-osc {
            padding: 7px 11px; border: 1px solid var(--linea); border-radius: var(--radius-sm);
            font-size: 12.5px; font-family: inherit; background: var(--bg); color: var(--texto);
        }
        .campo-osc::placeholder { color: var(--texto-tenue); }

        body.tema-oscuro table { width: 100%; border-collapse: collapse; font-size: 13px; }
        body.tema-oscuro th, body.tema-oscuro td { text-align: left; padding: 11px 20px; border-bottom: 1px solid var(--linea-suave); }
        body.tema-oscuro th {
            color: var(--texto-tenue); font-weight: 600; text-transform: uppercase;
            font-size: 10px; letter-spacing: .06em; background: transparent;
        }
        body.tema-oscuro tbody tr:last-child td { border-bottom: none; }
        body.tema-oscuro .fila-clic { cursor: pointer; transition: background .12s ease; }
        body.tema-oscuro .fila-clic:hover { background: var(--bg-hover); }
        body.tema-oscuro .rol-tag { color: var(--texto-tenue); }
        body.tema-oscuro .mono { font-family: 'JetBrains Mono', monospace; }

        body.tema-oscuro .badge { display: inline-flex; align-items: center; gap: 5px; padding: 3px 10px; border-radius: 999px; font-size: 11px; font-weight: 600; white-space: nowrap; }
        body.tema-oscuro .badge::before { content: ""; width: 6px; height: 6px; border-radius: 50%; background: currentColor; }
        body.tema-oscuro .badge-ok { background: var(--petroleo-wash); color: var(--petroleo); }
        body.tema-oscuro .badge-warn { background: var(--ambar-wash); color: var(--ambar); }
        body.tema-oscuro .badge-danger { background: var(--critico-wash); color: var(--critico); }

        /* Acciones sugeridas */
        .lista-acciones { list-style: none; margin: 0; padding: 4px 20px 18px; display: flex; flex-direction: column; gap: 4px; }
        .lista-acciones li {
            display: flex; align-items: flex-start; gap: 10px; padding: 10px 0;
            border-bottom: 1px solid var(--linea-suave); font-size: 13px;
        }
        .lista-acciones li:last-child { border-bottom: none; }
        .lista-acciones .icono-accion {
            width: 22px; height: 22px; border-radius: 6px; background: var(--petroleo-wash);
            color: var(--petroleo); display: flex; align-items: center; justify-content: center; flex-shrink: 0; margin-top: 1px;
            font-family: 'JetBrains Mono', monospace; font-size: 11px; font-weight: 600;
        }
        .lista-acciones .texto-accion { color: var(--texto-suave); }
        .lista-acciones .texto-accion b { color: var(--texto); font-weight: 600; }
        .lista-acciones .vacio-acciones { padding: 20px; color: var(--texto-tenue); font-style: italic; font-size: 12.5px; }

        /* -------- Gráfico de barras: productos con más movimiento -------- */
        .grafico-productos { padding: 6px 4px 10px; }
        .fila-grafico {
            display: grid; grid-template-columns: 28px 1fr 200px; align-items: center;
            gap: 16px; padding: 11px 20px; border-bottom: 1px solid var(--linea-suave);
        }
        .fila-grafico:last-child { border-bottom: none; }
        .fila-grafico__rango {
            font-family: 'JetBrains Mono', monospace; font-size: 11px; color: var(--texto-tenue);
            font-weight: 600;
        }
        .fila-grafico__nombre { font-size: 13px; color: var(--texto); font-weight: 500; }
        .fila-grafico__meta { font-size: 11.5px; color: var(--texto-tenue); margin-top: 2px; }
        .fila-grafico__medida { display: flex; align-items: center; gap: 12px; }
        .fila-grafico__fondo {
            flex: 1; height: 9px; border-radius: 999px; background: var(--linea-suave); overflow: hidden;
        }
        .fila-grafico__fondo > span {
            display: block; height: 100%; border-radius: 999px; min-width: 4px;
            background: linear-gradient(90deg, var(--petroleo-fuerte), var(--petroleo));
            box-shadow: 0 0 8px rgba(44,217,190,.35);
            transition: width .4s ease;
        }
        .fila-grafico__valor {
            font-family: 'JetBrains Mono', monospace; font-size: 12.5px; color: var(--texto);
            min-width: 56px; text-align: right; font-weight: 600;
        }
        @media (max-width: 720px) {
            .fila-grafico { grid-template-columns: 24px 1fr; row-gap: 8px; }
            .fila-grafico__medida { grid-column: 1 / -1; }
        }

        /* -------- Bento: tabla inferior (máquinas) -------- */
        .barra-inferior { padding: 16px 20px; display: flex; gap: 10px; flex-wrap: wrap; align-items: center; border-bottom: 1px solid var(--linea-suave); }
        .barra-inferior .campo-osc.buscar { flex: 1; min-width: 220px; }

        .barra-mini { display: flex; align-items: center; gap: 8px; }
        .barra-mini .fondo { flex: 1; height: 6px; border-radius: 999px; background: var(--linea-suave); overflow: hidden; }
        .barra-mini .fondo > span { display: block; height: 100%; border-radius: 999px; transition: width .35s ease; }
        .barra-mini .num { font-size: 12px; color: var(--texto-suave); min-width: 18px; text-align: right; }

        body.tema-oscuro .vacio, body.tema-oscuro .cargando { color: var(--texto-tenue); font-style: italic; padding: 40px 14px; text-align: center; }

        body.tema-oscuro .paginacion { color: var(--texto-suave); }
        body.tema-oscuro .paginacion button { background: var(--bg); border: 1px solid var(--linea); color: var(--texto); }
        body.tema-oscuro .paginacion button:not(:disabled):hover { border-color: var(--petroleo); color: var(--petroleo); }
        body.tema-oscuro .paginacion button:disabled { opacity: .35; }

        @media (max-width: 1000px) {
            .resumen-sistema { grid-template-columns: repeat(3, 1fr); }
            .panorama-operativo { grid-template-columns: 1fr; }
            .bento-superior { grid-template-columns: 1fr; }
            .bento-media { grid-template-columns: 1fr; }
        }
        @media (max-width: 620px) { .resumen-sistema { grid-template-columns: repeat(2, 1fr); } }

        /* Dashboard índigo: analítica clara sobre superficies blanco crema. */
        body.dashboard-indigo.tema-oscuro {
            --bg: #F2EFE9 !important;
            --bg-elevada: #FFFCF7 !important;
            --bg-elevada-2: #F8F5F0 !important;
            --bg-hover: #F0EEFA !important;
            --linea: #DDD9E7 !important;
            --linea-suave: #EAE6F0 !important;
            --texto: #25233F !important;
            --texto-suave: #5E5A70 !important;
            --texto-tenue: #817C91 !important;
            --petroleo: #4F46E5 !important;
            --petroleo-fuerte: #3730A3 !important;
            --petroleo-wash: rgba(79,70,229,.10) !important;
            --ambar: #B7791F !important;
            --ambar-wash: rgba(183,121,31,.12) !important;
            --critico: #BE3F57 !important;
            --critico-wash: rgba(190,63,87,.11) !important;
            background:
                radial-gradient(900px 430px at 12% -8%,rgba(99,102,241,.13),transparent 62%),
                radial-gradient(760px 380px at 100% 0%,rgba(255,255,255,.86),transparent 60%),
                #F2EFE9 !important;
            color: var(--texto) !important;
        }
        body.dashboard-indigo.tema-oscuro .principal { background: transparent !important; }
        body.dashboard-indigo.tema-oscuro .sidebar {
            background: #FFFCF7 !important;
            border-right-color: #DDD9E7 !important;
            box-shadow: 10px 0 30px rgba(49,46,129,.045);
        }
        body.dashboard-indigo.tema-oscuro .nav-lateral a { color: #565268 !important; }
        body.dashboard-indigo.tema-oscuro .nav-lateral a:hover { color: #312E81 !important; background: #F2F0FB !important; }
        body.dashboard-indigo.tema-oscuro .nav-lateral a.activa { color: #4338CA !important; background: #ECEAFB !important; }
        body.dashboard-indigo.tema-oscuro h1,
        body.dashboard-indigo.tema-oscuro h2,
        body.dashboard-indigo.tema-oscuro h3 { color: var(--texto) !important; }

        body.dashboard-indigo .hero-pulso {
            background:
                radial-gradient(circle at 90% 0%,rgba(99,102,241,.13),transparent 34%),
                linear-gradient(145deg,#FFFCF7,#F8F5FF) !important;
            border-color: #D8D3E7;
            border-radius: 16px;
            box-shadow: 0 14px 34px rgba(49,46,129,.08);
        }
        body.dashboard-indigo .hero-pulso::before {
            height: 3px;
            opacity: 1;
            background: linear-gradient(90deg,#3730A3,#6366F1 58%,#A5B4FC);
        }
        body.dashboard-indigo .hero-pulso__etiqueta { color: #4338CA; }
        body.dashboard-indigo .punto-vivo { background: #6366F1; box-shadow: 0 0 0 0 rgba(99,102,241,.42); }
        body.dashboard-indigo .hero-pulso p { color: #625E74 !important; }
        body.dashboard-indigo .chip-estado {
            background: rgba(255,255,255,.72);
            border-color: #E0DCEB;
            box-shadow: 0 5px 14px rgba(49,46,129,.04);
        }
        body.dashboard-indigo .chip-estado__icono { background: #F0EEFB; border: 1px solid #E0DCF1; }
        body.dashboard-indigo .chip-estado b { color: #2D2A4A !important; }
        body.dashboard-indigo .chip-estado span { color: #686378 !important; }
        body.dashboard-indigo.tema-oscuro .btn-actualizar {
            color: #FFFFFF;
            background: #4F46E5;
            border-color: #4F46E5;
            border-radius: 9px;
            box-shadow: 0 7px 18px rgba(79,70,229,.18);
        }
        body.dashboard-indigo.tema-oscuro .btn-actualizar:hover { background: #4338CA; }
        body.dashboard-indigo.tema-oscuro .btn-reporte {
            color: #373457;
            background: #FFFCF7;
            border-color: #CEC9DB;
            border-radius: 9px;
        }
        body.dashboard-indigo.tema-oscuro .btn-reporte:hover { color: #4338CA; border-color: #6366F1; }

        body.dashboard-indigo .metrica-sistema,
        body.dashboard-indigo .panorama-panel,
        body.dashboard-indigo .bento-card,
        body.dashboard-indigo .panel-oscuro {
            color: var(--texto);
            background: #FFFCF7;
            border-color: #DDD9E7;
            border-radius: 13px;
            box-shadow: 0 8px 22px rgba(49,46,129,.055);
        }
        body.dashboard-indigo .metrica-sistema { position: relative; overflow: hidden; }
        body.dashboard-indigo .metrica-sistema::before {
            content: "";
            position: absolute;
            inset: 0 auto 0 0;
            width: 3px;
            background: #6366F1;
        }
        body.dashboard-indigo .metrica-sistema.alerta::before { background: var(--critico); }
        body.dashboard-indigo .metrica-sistema.aviso::before { background: var(--ambar); }
        body.dashboard-indigo .metrica-sistema.correcta::before { background: #4F46E5; }
        body.dashboard-indigo .metrica-sistema:hover,
        body.dashboard-indigo .panorama-panel:hover { border-color: #A9A3CD; background: #FFFFFF; }
        body.dashboard-indigo .panorama-panel__cabecera,
        body.dashboard-indigo .panel-cabecera-osc { background: #FBF8F3; border-color: #E7E2EC; }
        body.dashboard-indigo .panorama-panel__cabecera a { color: #4F46E5; }

        body.dashboard-indigo .donut-envoltura { filter: drop-shadow(0 7px 14px rgba(67,56,202,.15)); }
        body.dashboard-indigo .donut {
            background: conic-gradient(
                #4F46E5 0 calc(var(--pct-ok,85) * 1%),
                #C58A32 calc(var(--pct-ok,85) * 1%) calc((var(--pct-ok,85) + var(--pct-warn,10)) * 1%),
                #BE3F57 calc((var(--pct-ok,85) + var(--pct-warn,10)) * 1%) 100%
            );
        }
        body.dashboard-indigo .distribution-bar,
        body.dashboard-indigo .conexion-barra,
        body.dashboard-indigo .fila-grafico__fondo,
        body.dashboard-indigo .barra-mini .fondo { background: #E8E4EF; }
        body.dashboard-indigo .fila-grafico__fondo > span {
            background: linear-gradient(90deg,#3730A3,#6366F1 62%,#818CF8);
            box-shadow: 0 0 10px rgba(79,70,229,.22);
        }
        body.dashboard-indigo .fila-grafico { border-color: #EAE6F0; }
        body.dashboard-indigo .fila-grafico:hover { background: #F7F5FC; }
        body.dashboard-indigo .fila-grafico__rango { color: #625CB0; }
        body.dashboard-indigo .tabs-filtro { background: #ECE8F0; }
        body.dashboard-indigo .tabs-filtro button { color: #625E72; }
        body.dashboard-indigo .tabs-filtro button.activo { color: #FFFFFF; background: #4F46E5; }
        body.dashboard-indigo .campo-osc {
            color: #29263F;
            background: #FFFCF7;
            border-color: #CFCAD9;
        }
        body.dashboard-indigo.tema-oscuro th { color: #676278; background: #F7F3ED; }
        body.dashboard-indigo.tema-oscuro td { color: #464257; }
        body.dashboard-indigo.tema-oscuro .fila-clic:hover { background: #F1EFFA; }
        body.dashboard-indigo .lista-acciones .icono-accion { color: #4338CA; background: #ECEAFB; }
        body.dashboard-indigo .icono-accion { box-shadow: none; }
        body.dashboard-indigo.tema-oscuro .paginacion button { color: #373457; background: #FFFCF7; border-color: #CEC9DB; }

        /* Paleta zafiro 2026: indigo mas azul y modo oscuro con superficies propias. */
        body.dashboard-indigo:not(.tema-mdm-oscuro) {
            --bg: #F3F6FA !important;
            --bg-elevada: #FFFFFF !important;
            --bg-elevada-2: #F7F9FC !important;
            --bg-hover: #EDF3FA !important;
            --linea: #D5DEE9 !important;
            --linea-suave: #E7EDF4 !important;
            --texto: #172033 !important;
            --texto-suave: #526174 !important;
            --texto-tenue: #7B899B !important;
            --petroleo: #2457A6 !important;
            --petroleo-fuerte: #173E78 !important;
            --petroleo-wash: rgba(36,87,166,.11) !important;
            --ambar: #C43D4F !important;
            --ambar-wash: rgba(196,61,79,.12) !important;
            --critico: #8F2440 !important;
            --critico-wash: rgba(143,36,64,.11) !important;
            background: radial-gradient(920px 440px at 10% -10%,rgba(53,108,181,.10),transparent 62%),linear-gradient(180deg,#F8FAFD 0%,#F3F6FA 48%) !important;
            color: var(--texto) !important;
        }
        body.dashboard-indigo.tema-mdm-oscuro {
            --bg: #0B111A !important;
            --bg-elevada: #121B28 !important;
            --bg-elevada-2: #172334 !important;
            --bg-hover: #1B2A3C !important;
            --linea: #314055 !important;
            --linea-suave: #243247 !important;
            --texto: #EDF3F9 !important;
            --texto-suave: #B8C5D3 !important;
            --texto-tenue: #8493A7 !important;
            --petroleo: #78A9EA !important;
            --petroleo-fuerte: #A7CBF5 !important;
            --petroleo-wash: rgba(92,148,220,.17) !important;
            --ambar: #F07982 !important;
            --ambar-wash: rgba(240,121,130,.15) !important;
            --critico: #F5A0B0 !important;
            --critico-wash: rgba(245,160,176,.15) !important;
            background: radial-gradient(920px 460px at 8% -10%,rgba(47,101,174,.17),transparent 62%),linear-gradient(180deg,#0D1520 0%,#0B111A 52%) !important;
            color: var(--texto) !important;
        }
        body.dashboard-indigo .principal { background: transparent !important; }
        body.dashboard-indigo:not(.tema-mdm-oscuro) .sidebar {
            background: #FFFFFF !important;
            border-right-color: #D5DEE9 !important;
            box-shadow: 10px 0 30px rgba(23,48,83,.055) !important;
        }
        body.dashboard-indigo:not(.tema-mdm-oscuro) .nav-lateral a { color: #526174 !important; }
        body.dashboard-indigo:not(.tema-mdm-oscuro) .nav-lateral a:hover { color: #173E78 !important; background: #EDF3FA !important; }
        body.dashboard-indigo:not(.tema-mdm-oscuro) .nav-lateral a.activa { color: #1E4D91 !important; background: #E5EEF9 !important; }
        body.dashboard-indigo.tema-mdm-oscuro .sidebar { background: #0C1724 !important; border-right-color: #26384B !important; box-shadow: 12px 0 32px rgba(0,0,0,.2) !important; }
        body.dashboard-indigo.tema-mdm-oscuro .nav-lateral a { color: #B6C4D2 !important; }
        body.dashboard-indigo.tema-mdm-oscuro .nav-lateral a:hover { color: #F4F8FC !important; background: #17283A !important; }
        body.dashboard-indigo.tema-mdm-oscuro .nav-lateral a.activa { color: #A7CBF5 !important; background: #173452 !important; }
        body.dashboard-indigo h1,body.dashboard-indigo h2,body.dashboard-indigo h3 { color: var(--texto) !important; }
        body.dashboard-indigo .hero-pulso {
            border-color: var(--linea) !important;
            background: radial-gradient(circle at 90% 0%,rgba(63,123,196,.12),transparent 36%),linear-gradient(145deg,var(--bg-elevada),var(--bg-elevada-2)) !important;
            box-shadow: 0 14px 34px rgba(21,48,82,.08) !important;
        }
        body.dashboard-indigo.tema-mdm-oscuro .hero-pulso { box-shadow: 0 16px 38px rgba(0,0,0,.24) !important; }
        body.dashboard-indigo .hero-pulso::before { background: linear-gradient(90deg,#173E78,#2F6DB8 58%,#78A9EA); }
        body.dashboard-indigo .hero-pulso__etiqueta { color: var(--petroleo) !important; }
        body.dashboard-indigo .punto-vivo { background: #4F83C6; box-shadow: 0 0 0 0 rgba(79,131,198,.42); }
        body.dashboard-indigo .hero-pulso p { color: var(--texto-suave) !important; }
        body.dashboard-indigo .chip-estado { background: var(--bg-elevada) !important; border-color: var(--linea-suave) !important; box-shadow: 0 5px 14px rgba(21,48,82,.04); }
        body.dashboard-indigo .chip-estado__icono { background: var(--petroleo-wash) !important; border-color: var(--linea-suave) !important; }
        body.dashboard-indigo .chip-estado b { color: var(--texto) !important; }
        body.dashboard-indigo .chip-estado span { color: var(--texto-suave) !important; }
        body.dashboard-indigo .btn-actualizar { color: #FFF !important; background: #2457A6 !important; border-color: #2457A6 !important; box-shadow: 0 7px 18px rgba(36,87,166,.2) !important; }
        body.dashboard-indigo .btn-actualizar:hover { background: #173E78 !important; }
        body.dashboard-indigo .btn-reporte { color: var(--texto-suave) !important; background: var(--bg-elevada) !important; border-color: var(--linea) !important; }
        body.dashboard-indigo .btn-reporte:hover { color: var(--petroleo) !important; border-color: var(--petroleo) !important; }
        body.dashboard-indigo.tema-mdm-oscuro .btn-actualizar { color: #FFF !important; background: #346DB5 !important; border-color: #4C82C3 !important; box-shadow: 0 7px 18px rgba(20,74,137,.3) !important; }
        body.dashboard-indigo.tema-mdm-oscuro .btn-actualizar:hover { background: #437EC5 !important; }
        body.dashboard-indigo.tema-mdm-oscuro .btn-reporte { color: #C4D0DC !important; background: #131F2E !important; border-color: #3A4B60 !important; }
        body.dashboard-indigo.tema-mdm-oscuro .btn-reporte:hover { color: #A7CBF5 !important; border-color: #6597D7 !important; }
        body.dashboard-indigo .metrica-sistema,
        body.dashboard-indigo .panorama-panel,
        body.dashboard-indigo .bento-card,
        body.dashboard-indigo .panel-oscuro {
            color: var(--texto);
            background: var(--bg-elevada) !important;
            border-color: var(--linea) !important;
            box-shadow: 0 8px 22px rgba(21,48,82,.06) !important;
        }
        body.dashboard-indigo.tema-mdm-oscuro .metrica-sistema,
        body.dashboard-indigo.tema-mdm-oscuro .panorama-panel,
        body.dashboard-indigo.tema-mdm-oscuro .bento-card,
        body.dashboard-indigo.tema-mdm-oscuro .panel-oscuro { box-shadow: 0 10px 26px rgba(0,0,0,.22) !important; }
        body.dashboard-indigo .metrica-sistema::before { background: #3971B8; }
        body.dashboard-indigo .metrica-sistema.alerta::before { background: var(--critico); }
        body.dashboard-indigo .metrica-sistema.aviso::before { background: var(--ambar); }
        body.dashboard-indigo .metrica-sistema.correcta::before { background: var(--petroleo); }
        body.dashboard-indigo .metrica-sistema:hover,
        body.dashboard-indigo .panorama-panel:hover { border-color: #6F8FB5 !important; background: var(--bg-elevada-2) !important; }
        body.dashboard-indigo .panorama-panel__cabecera,
        body.dashboard-indigo .panel-cabecera-osc { background: var(--bg-elevada-2) !important; border-color: var(--linea-suave) !important; }
        body.dashboard-indigo .panorama-panel__cabecera a { color: var(--petroleo); }
        body.dashboard-indigo .donut-envoltura { filter: drop-shadow(0 7px 14px rgba(36,87,166,.16)); }
        body.dashboard-indigo .donut {
            background: conic-gradient(var(--petroleo) 0 calc(var(--pct-ok,85) * 1%),var(--ambar) calc(var(--pct-ok,85) * 1%) calc((var(--pct-ok,85) + var(--pct-warn,10)) * 1%),var(--critico) calc((var(--pct-ok,85) + var(--pct-warn,10)) * 1%) 100%);
        }
        body.dashboard-indigo .distribution-bar,
        body.dashboard-indigo .conexion-barra,
        body.dashboard-indigo .fila-grafico__fondo,
        body.dashboard-indigo .barra-mini .fondo { background: var(--linea-suave); }
        body.dashboard-indigo .fila-grafico__fondo > span { background: linear-gradient(90deg,#173E78,#3971B8 62%,#78A9EA); box-shadow: 0 0 10px rgba(36,87,166,.2); }
        body.dashboard-indigo .fila-grafico { border-color: var(--linea-suave); }
        body.dashboard-indigo .fila-grafico:hover,
        body.dashboard-indigo .fila-clic:hover { background: var(--bg-hover) !important; }
        body.dashboard-indigo .fila-grafico__rango { color: var(--petroleo); }
        body.dashboard-indigo .tabs-filtro { background: var(--bg); }
        body.dashboard-indigo .tabs-filtro button { color: var(--texto-suave); }
        body.dashboard-indigo .tabs-filtro button.activo { color: #FFF; background: #2457A6; }
        body.dashboard-indigo .campo-osc { color: var(--texto) !important; background: var(--bg-elevada) !important; border-color: var(--linea) !important; }
        body.dashboard-indigo th { color: var(--texto-tenue) !important; background: var(--bg-elevada-2) !important; }
        body.dashboard-indigo td { color: var(--texto-suave) !important; }
        body.dashboard-indigo.tema-mdm-oscuro th { color: #96A6B9 !important; background: #172334 !important; }
        body.dashboard-indigo.tema-mdm-oscuro td { color: #C0CBD7 !important; }
        body.dashboard-indigo.tema-mdm-oscuro .fila-clic:hover { background: #1B2A3C !important; }
        body.dashboard-indigo .lista-acciones .icono-accion { color: var(--petroleo); background: var(--petroleo-wash); }
        body.dashboard-indigo .paginacion button { color: var(--texto) !important; background: var(--bg-elevada) !important; border-color: var(--linea) !important; }
        body.dashboard-indigo.tema-mdm-oscuro .paginacion button { color: #EDF3F9 !important; background: #121B28 !important; border-color: #314055 !important; }

        @media (prefers-reduced-motion: reduce) {
            body.dashboard-indigo .punto-vivo,
            body.dashboard-indigo .ecg-grupo { animation: none !important; }
        }
    </style>
</head>
<body class="tema-oscuro dashboard-indigo">
<div class="app">
    <aside class="sidebar">
        <div class="marca">
            <img class="marca-logo" src="assets/img/logo.png" alt="MDM">
        </div>
        <?php require __DIR__ . '/partials/menu.php'; ?>
    </aside>

    <main class="principal">

        <!-- HERO: panel de pulso — firma visual del dashboard -->
        <div class="hero-pulso" id="hero-pulso">
            <div class="hero-pulso__encabezado">
                <div>
                    <div class="hero-pulso__etiqueta"><span class="punto-vivo"></span>Centro de operación</div>
                    <h1>Estado general del sistema</h1>
                    <p>Conectividad, medicamentos, recetas, distribución y alertas en una sola vista.</p>
                </div>
                <div>
                    <div class="acciones-dashboard">
                    <button class="btn-reporte" id="btn-reporte-estado" type="button">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none"><path d="M7 3h7l4 4v14H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/><path d="M14 3v5h5M8 15h8M8 18h5M8 12h8" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
                        Reporte de estado
                    </button>
                    <button class="btn-actualizar" id="btn-actualizar">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none"><path d="M20 12a8 8 0 1 1-2.34-5.66M20 4v5h-5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        Actualizar
                    </button>
                    </div>
                    <div class="hora-actualizacion" id="hora-actualizacion"></div>
                </div>
            </div>

            <div class="hero-pulso__linea">
                <svg class="ecg-svg" viewBox="0 0 2400 100" preserveAspectRatio="none">
                    <line class="ecg-base" x1="0" y1="50" x2="2400" y2="50" />
                    <g class="ecg-grupo">
                        <path id="ecg-trazo-sombra" class="ecg-linea-sombra" d="" />
                        <use href="#ecg-trazo-sombra" x="1200" y="0" />
                        <path id="ecg-trazo-brillo" class="ecg-linea" d="" />
                        <use href="#ecg-trazo-brillo" x="1200" y="0" />
                    </g>
                </svg>
            </div>

            <div class="hero-pulso__chips">
                <div class="chip-estado">
                    <div class="chip-estado__icono" style="color:var(--petroleo);">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M12 22s8-4.5 8-11.5A5.5 5.5 0 0 0 12 6a5.5 5.5 0 0 0-8 4.5C4 17.5 12 22 12 22Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg>
                    </div>
                    <div><b>Conectividad</b><span id="msg-operacion">Calculando máquinas en línea...</span></div>
                </div>
                <div class="chip-estado">
                    <div class="chip-estado__icono" style="color:var(--ambar);">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M6 4h12a2.5 2.5 0 0 1 2.5 2.5V19A2.5 2.5 0 0 1 18 21.5H6A2.5 2.5 0 0 1 3.5 19V6.5A2.5 2.5 0 0 1 6 4Z" stroke="currentColor" stroke-width="1.6"/><path d="M8 2v4M16 2v4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                    </div>
                    <div><b>Distribución</b><span id="msg-reposicion">Revisando viajes activos...</span></div>
                </div>
                <div class="chip-estado">
                    <div class="chip-estado__icono" style="color:var(--critico);">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M12 8v5M12 17h.01M10.2 4.8 2.8 18a1.5 1.5 0 0 0 1.3 2.2h15.8a1.5 1.5 0 0 0 1.3-2.2L13.8 4.8a2.1 2.1 0 0 0-3.6 0Z" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </div>
                    <div><b>Medicamentos</b><span id="msg-control">Buscando alertas críticas...</span></div>
                </div>
            </div>
        </div>

        <section class="resumen-sistema" aria-label="Indicadores principales">
            <a class="metrica-sistema" href="admin.php">
                <div class="metrica-sistema__cabecera"><span>Máquinas activas</span><i data-lucide="server"></i></div>
                <div class="metrica-sistema__valor" id="op-maquinas-total">—</div>
                <div class="metrica-sistema__detalle">Principal y esclavas</div>
            </a>
            <a class="metrica-sistema correcta" href="admin.php?conexion=online">
                <div class="metrica-sistema__cabecera"><span>En línea</span><i data-lucide="wifi"></i></div>
                <div class="metrica-sistema__valor" id="op-maquinas-online">—</div>
                <div class="metrica-sistema__detalle" id="op-maquinas-online-detalle">Latido menor a 10 min</div>
            </a>
            <a class="metrica-sistema alerta" href="admin.php?conexion=offline">
                <div class="metrica-sistema__cabecera"><span>Sin conexión</span><i data-lucide="wifi-off"></i></div>
                <div class="metrica-sistema__valor" id="op-maquinas-offline">—</div>
                <div class="metrica-sistema__detalle" id="op-maquinas-offline-detalle">Incluye máquinas que nunca se han conectado</div>
            </a>
            <a class="metrica-sistema correcta" href="estadisticas.php">
                <div class="metrica-sistema__cabecera"><span>Entregadas hoy</span><i data-lucide="pill"></i></div>
                <div class="metrica-sistema__valor" id="op-piezas-hoy">—</div>
                <div class="metrica-sistema__detalle">Piezas dispensadas</div>
            </a>
            <a class="metrica-sistema aviso" href="receta_nueva.php">
                <div class="metrica-sistema__cabecera"><span>Recetas pendientes</span><i data-lucide="clipboard-list"></i></div>
                <div class="metrica-sistema__valor" id="op-recetas-pendientes">—</div>
                <div class="metrica-sistema__detalle" id="op-recetas-detalle">Esperando atención</div>
            </a>
            <a class="metrica-sistema aviso" href="distribucion.php">
                <div class="metrica-sistema__cabecera"><span>Viajes activos</span><i data-lucide="route"></i></div>
                <div class="metrica-sistema__valor" id="op-viajes-activos">—</div>
                <div class="metrica-sistema__detalle" id="op-viajes-detalle">Programados y en ruta</div>
            </a>
        </section>

        <section class="panorama-operativo" aria-label="Panorama operativo">
            <div class="panorama-panel">
                <div class="panorama-panel__cabecera"><h2>Conectividad de máquinas</h2><a href="admin.php">Ver máquinas</a></div>
                <div class="panorama-panel__cuerpo">
                    <div class="conexion-barra" aria-hidden="true">
                        <span id="barra-online" style="background:var(--petroleo);width:0%"></span>
                        <span id="barra-offline" style="background:var(--critico);width:0%"></span>
                        <span id="barra-nunca" style="background:var(--texto-tenue);width:0%"></span>
                    </div>
                    <div class="resumen-lineas">
                        <div class="resumen-linea"><span><i class="indicador" style="background:var(--petroleo)"></i>En línea</span><strong id="conexion-online">—</strong></div>
                        <div class="resumen-linea"><span><i class="indicador" style="background:var(--critico)"></i>Sin conexión</span><strong id="conexion-offline">—</strong></div>
                        <div class="resumen-linea"><span><i class="indicador" style="background:var(--texto-tenue)"></i>Nunca conectadas</span><strong id="conexion-nunca">—</strong></div>
                    </div>
                    <div class="lista-compacta" id="lista-desconectadas"><div class="sin-datos-compacto" style="padding:12px 16px;">Calculando...</div></div>
                </div>
            </div>

            <div class="panorama-panel">
                <div class="panorama-panel__cabecera"><h2>Recetas y dispensación</h2><a href="receta_nueva.php">Nueva receta</a></div>
                <div class="panorama-panel__cuerpo">
                    <div class="resumen-lineas">
                        <div class="resumen-linea"><span>Creadas hoy</span><strong id="recetas-creadas">—</strong></div>
                        <div class="resumen-linea"><span>En proceso</span><strong id="recetas-proceso">—</strong></div>
                        <div class="resumen-linea"><span>Entregadas hoy</span><strong id="recetas-entregadas">—</strong></div>
                        <div class="resumen-linea"><span>Incidencias hoy</span><strong id="recetas-incidencias">—</strong></div>
                        <div class="resumen-linea"><span>Piezas últimos 7 días</span><strong id="piezas-7d">—</strong></div>
                        <div class="resumen-linea"><span>Mermas hoy</span><strong id="mermas-hoy">—</strong></div>
                    </div>
                </div>
            </div>

            <div class="panorama-panel">
                <div class="panorama-panel__cabecera"><h2>Distribución activa</h2><a href="distribucion.php">Abrir distribución</a></div>
                <div class="panorama-panel__cuerpo">
                    <div class="resumen-lineas">
                        <div class="resumen-linea"><span>En ruta</span><strong id="viajes-en-ruta">—</strong></div>
                        <div class="resumen-linea"><span>Vencidos</span><strong id="viajes-vencidos">—</strong></div>
                        <div class="resumen-linea"><span>Próximos 24 h</span><strong id="viajes-24h">—</strong></div>
                        <div class="resumen-linea"><span>Kilómetros pendientes</span><strong id="viajes-km">—</strong></div>
                    </div>
                    <div class="lista-compacta" id="lista-viajes"><div class="sin-datos-compacto" style="padding:12px 16px;">Calculando...</div></div>
                </div>
            </div>
        </section>

        <!-- Fila superior: donut de salud global + 2 KPIs -->
        <div class="bento-superior" id="bento-superior">
            <div class="bento-card card-salud">
                <div class="donut-envoltura">
                    <div class="donut" id="donut-salud"></div>
                    <div class="donut-centro">
                        <div class="num" id="donut-total">—</div>
                        <div class="lbl">Unidades</div>
                    </div>
                </div>
                <div class="leyenda-donut">
                    <div class="titulo-card" style="margin-bottom:2px;">Salud global de la red</div>
                    <div class="item"><span class="punto" style="background:var(--petroleo)"></span>Operativas<span class="valor" id="leg-ok">—</span></div>
                    <div class="item"><span class="punto" style="background:var(--ambar)"></span>Con carriles vacíos<span class="valor" id="leg-warn">—</span></div>
                    <div class="item"><span class="punto" style="background:var(--critico)"></span>Con lotes caducados<span class="valor" id="leg-crit">—</span></div>
                    <div class="distribution-bar" aria-hidden="true">
                        <span id="dist-ok" style="background:var(--petroleo);width:0%"></span>
                        <span id="dist-warn" style="background:var(--ambar);width:0%"></span>
                        <span id="dist-crit" style="background:var(--critico);width:0%"></span>
                    </div>
                </div>
            </div>

            <div class="bento-card kpi-card-doble">
                <div class="kpi-icono" style="color:var(--critico);">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M12 8v5M12 17h.01M10.2 4.8 2.8 18a1.5 1.5 0 0 0 1.3 2.2h15.8a1.5 1.5 0 0 0 1.3-2.2L13.8 4.8a2.1 2.1 0 0 0-3.6 0Z" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </div>
                <div class="titulo-card">Lotes caducados</div>
                <div class="kpi-num" id="kpi-caducados" style="color:var(--critico);">—</div>
                <div class="kpi-sub" id="kpi-caducados-sub">—</div>
            </div>

            <div class="bento-card kpi-card-doble">
                <div class="kpi-icono" style="color:var(--ambar);">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M8 2v4M16 2v4M3.5 9h17M6 4h12a2.5 2.5 0 0 1 2.5 2.5V19A2.5 2.5 0 0 1 18 21.5H6A2.5 2.5 0 0 1 3.5 19V6.5A2.5 2.5 0 0 1 6 4Z" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
                </div>
                <div class="titulo-card">Por caducar (30 días)</div>
                <div class="kpi-num" id="kpi-por-caducar" style="color:var(--ambar);">—</div>
                <div class="kpi-sub" id="kpi-por-caducar-sub">—</div>
            </div>
        </div>

        <!-- Fila media: alertas + acciones sugeridas -->
        <div class="bento-media">
            <div class="panel-oscuro">
                <div class="panel-cabecera-osc">
                    <h2>Alertas operativas</h2>
                    <div class="tabs-filtro" id="tabs-alertas">
                        <button type="button" data-tipo="todas" class="activo">Todo</button>
                        <button type="button" data-tipo="capacidad_30">Pedido 30%</button>
                        <button type="button" data-tipo="capacidad_60">Capacidad 60%</button>
                        <button type="button" data-tipo="stock_bajo">Stock &lt;= 5</button>
                        <button type="button" data-tipo="vacios">Vacíos</button>
                        <button type="button" data-tipo="caducados">Caducados</button>
                    </div>
                </div>
                <div style="padding: 12px 20px 0;">
                    <input type="text" id="alertas-q" class="campo-osc" style="width:100%;" placeholder="Buscar por producto, código o serie…">
                </div>
                <div id="tabla-alertas" class="cargando">Cargando…</div>
                <div class="paginacion" id="pag-alertas" style="display:none;">
                    <button id="alertas-anterior">&larr;</button>
                    <span id="alertas-texto"></span>
                    <button id="alertas-siguiente">&rarr;</button>
                </div>
            </div>

            <div class="panel-oscuro">
                <div class="panel-cabecera-osc">
                    <h2>Acciones sugeridas</h2>
                </div>
                <ul class="lista-acciones" id="lista-acciones">
                    <li class="vacio-acciones">Calculando…</li>
                </ul>
            </div>
        </div>

        <!-- Top productos -->
        <div class="panel-oscuro" style="margin-bottom:14px;">
            <div class="panel-cabecera-osc">
                <h2>Productos con más movimiento</h2>
                <div class="tabs-filtro" id="tabs-productos">
                    <button type="button" data-orden="7d">7 días</button>
                    <button type="button" data-orden="30d" class="activo">30 días</button>
                    <button type="button" data-orden="total">Histórico</button>
                </div>
            </div>
            <div id="tabla-productos" class="cargando">Cargando…</div>
        </div>

        <!-- Fila inferior: máquinas que requieren atención -->
        <div class="panel-oscuro">
            <div class="panel-cabecera-osc">
                <h2>Máquinas que requieren atención</h2>
            </div>
            <div class="barra-inferior">
                <input type="text" id="maquinas-q" class="campo-osc buscar" placeholder="Buscar por serie…">
                <div class="tabs-filtro" id="tabs-maquinas">
                    <button type="button" data-estado="">Todas</button>
                    <button type="button" data-estado="atencion" class="activo">Atención</button>
                    <button type="button" data-estado="ok">Al día</button>
                </div>
            </div>
            <div id="tabla-maquinas" class="cargando">Cargando…</div>
            <div class="paginacion" id="pag-maquinas" style="display:none;">
                <button id="maquinas-anterior">&larr;</button>
                <span id="maquinas-texto"></span>
                <button id="maquinas-siguiente">&rarr;</button>
            </div>
        </div>
    </main>
</div>

<script src="assets/js/comun.js?v=20260912-1"></script>
<script>
    const USUARIO_SOLO_MAQUINAS_DASHBOARD = <?= Auth::isUsuarioMaquinasDashboard() ? 'true' : 'false' ?>;
    const PAGE_SIZE = 15;
    let paginaAlertas = 1, paginaMaquinas = 1;
    let filtroTipoAlertas = 'todas';
    let filtroEstadoMaquinas = 'atencion';
    let ordenProductos = '30d';
    let kpisActuales = null;

    function badgeCaducidad(dias) {
        if (dias < 0) return '<span class="badge badge-danger">Caducado</span>';
        if (dias <= 15) return `<span class="badge badge-danger">${dias} días</span>`;
        if (dias <= 30) return `<span class="badge badge-warn">${dias} días</span>`;
        return `<span class="badge badge-ok">${dias} días</span>`;
    }

    function badgeAlertaOperativa(tipo) {
        if (tipo === 'vacio') return '<span class="badge badge-danger">Vacío</span>';
        if (tipo === 'caducado') return '<span class="badge badge-danger">Caducado</span>';
        if (tipo === 'capacidad_30') return '<span class="badge badge-danger">30% - generar pedido</span>';
        if (tipo === 'capacidad_60') return '<span class="badge badge-warn">60% capacidad</span>';
        if (tipo === 'stock_bajo') return '<span class="badge badge-warn">Stock bajo</span>';
        if (tipo === 'por_caducar') return '<span class="badge badge-warn">Por caducar</span>';
        return '<span class="badge badge-ok">OK</span>';
    }

    function fmt(n) { return (n ?? 0).toLocaleString('es-MX'); }

    function activarTab(contenedor, boton) {
        contenedor.querySelectorAll('button').forEach(b => b.classList.remove('activo'));
        boton.classList.add('activo');
    }

    // ---------------- Firma visual: línea de pulso (ECG) ----------------
    function trazoLatido(periodo, repeticiones, base = 50, amplitud = 40) {
        let d = `M0,${base}`;
        for (let i = 0; i < repeticiones; i++) {
            const x = i * periodo;
            d += ` L${x + periodo * 0.16},${base}`;
            d += ` L${x + periodo * 0.22},${base - amplitud * 0.18}`;
            d += ` L${x + periodo * 0.28},${base + amplitud * 0.6}`;
            d += ` L${x + periodo * 0.34},${base - amplitud}`;
            d += ` L${x + periodo * 0.40},${base + amplitud * 0.32}`;
            d += ` L${x + periodo * 0.46},${base}`;
            d += ` L${x + periodo},${base}`;
        }
        return d;
    }

    function iniciarPulso() {
        const d = trazoLatido(200, 6);
        document.getElementById('ecg-trazo-sombra').setAttribute('d', d);
        document.getElementById('ecg-trazo-brillo').setAttribute('d', d);
    }

    function badgeConexion(estado) {
        if (estado === 'online') return '<span class="badge badge-ok">En línea</span>';
        if (estado === 'offline') return '<span class="badge badge-danger">Sin conexión</span>';
        return '<span class="badge badge-neutro">Nunca conectada</span>';
    }

    function tiempoBreve(minutos) {
        if (minutos === null || minutos === undefined) return 'Sin latidos';
        const valor = Math.max(0, Number(minutos) || 0);
        if (valor < 60) return `Hace ${Math.max(1, Math.floor(valor))} min`;
        if (valor < 1440) return `Hace ${Math.floor(valor / 60)} h`;
        return `Hace ${Math.floor(valor / 1440)} d`;
    }

    function estadoLimiteViaje(minutos) {
        const valor = Number(minutos);
        if (valor < 0) return `Vencido hace ${Math.max(1, Math.abs(Math.floor(valor / 60)))} h`;
        if (valor < 60) return `${Math.max(1, Math.floor(valor))} min restantes`;
        return `${Math.floor(valor / 60)} h restantes`;
    }

    async function cargarOperacion() {
        try {
            const json = await obtenerJSON('api/dashboard_operacion.php');
            const d = json.data;
            const m = d.maquinas || {};
            const r = d.recetas || {};
            const s = d.salidas || {};
            const dist = d.distribucion || {};
            const total = Number(m.total) || 0;
            const online = Number(m.online) || 0;
            const offline = Number(m.offline) || 0;
            const nunca = Number(m.nunca_conectadas) || 0;
            const desconectadas = offline + nunca;

            document.getElementById('op-maquinas-total').textContent = fmt(total);
            document.getElementById('op-maquinas-online').textContent = fmt(online);
            document.getElementById('op-maquinas-offline').textContent = fmt(desconectadas);
            document.getElementById('op-maquinas-online-detalle').textContent = total ? `${Math.round((online / total) * 100)}% de disponibilidad` : 'Sin máquinas registradas';
            document.getElementById('op-maquinas-offline-detalle').textContent = `${fmt(offline)} sin conexión · ${fmt(nunca)} nunca conectadas`;
            document.getElementById('op-piezas-hoy').textContent = fmt(Number(s.piezas_hoy) || 0);
            document.getElementById('op-recetas-pendientes').textContent = fmt(Number(r.pendientes) || 0);
            document.getElementById('op-recetas-detalle').textContent = `${fmt(Number(r.en_proceso) || 0)} en proceso`;
            document.getElementById('op-viajes-activos').textContent = fmt(Number(dist.activos) || 0);
            document.getElementById('op-viajes-detalle').textContent = `${fmt(Number(dist.vencidos) || 0)} vencidos · ${fmt(Number(dist.en_ruta) || 0)} en ruta`;

            document.getElementById('conexion-online').textContent = fmt(online);
            document.getElementById('conexion-offline').textContent = fmt(offline);
            document.getElementById('conexion-nunca').textContent = fmt(nunca);
            document.getElementById('barra-online').style.width = `${total ? (online / total) * 100 : 0}%`;
            document.getElementById('barra-offline').style.width = `${total ? (offline / total) * 100 : 0}%`;
            document.getElementById('barra-nunca').style.width = `${total ? (nunca / total) * 100 : 0}%`;
            document.getElementById('msg-operacion').textContent = total ? `${online} de ${total} máquinas reportando.` : 'Sin máquinas activas.';

            const listaDesconectadas = document.getElementById('lista-desconectadas');
            listaDesconectadas.innerHTML = (d.maquinas_desconectadas || []).length
                ? d.maquinas_desconectadas.map(x => `<a class="item-compacto" href="maquina.php?id=${Number(x.id_maquina)}">
                    <span><b class="mono">${escapeHtml(x.numero_serie)}</b><small>${escapeHtml(x.nombre_ubicacion) || 'Sin ubicación'}</small></span>
                    <span class="dato">${escapeHtml(tiempoBreve(x.minutos_desde_conexion))}</span>
                </a>`).join('')
                : '<div class="sin-datos-compacto" style="padding:12px 16px;">Todas las máquinas activas están en línea.</div>';

            document.getElementById('recetas-creadas').textContent = fmt(Number(r.creadas_hoy) || 0);
            document.getElementById('recetas-proceso').textContent = fmt(Number(r.en_proceso) || 0);
            document.getElementById('recetas-entregadas').textContent = fmt(Number(r.entregadas_hoy) || 0);
            document.getElementById('recetas-incidencias').textContent = fmt(Number(r.incidencias_hoy) || 0);
            document.getElementById('piezas-7d').textContent = fmt(Number(s.piezas_7d) || 0);
            document.getElementById('mermas-hoy').textContent = fmt(Number(s.mermas_hoy) || 0);

            document.getElementById('viajes-en-ruta').textContent = fmt(Number(dist.en_ruta) || 0);
            document.getElementById('viajes-vencidos').textContent = fmt(Number(dist.vencidos) || 0);
            document.getElementById('viajes-24h').textContent = fmt(Number(dist.proximas_24h) || 0);
            document.getElementById('viajes-km').textContent = `${Number(dist.km_pendientes || 0).toLocaleString('es-MX', { maximumFractionDigits: 1 })} km`;
            document.getElementById('msg-reposicion').textContent = Number(dist.activos) > 0
                ? `${fmt(Number(dist.activos))} viaje(s) activo(s), ${fmt(Number(dist.vencidos))} vencido(s).`
                : 'Sin viajes activos.';

            const listaViajes = document.getElementById('lista-viajes');
            listaViajes.innerHTML = (dist.viajes || []).length
                ? dist.viajes.map(v => `<a class="item-compacto" href="distribucion.php">
                    <span><b>${escapeHtml(v.destino_nombre)}</b><small class="mono">${escapeHtml(v.numero_serie)} · ${escapeHtml(v.estatus)}</small></span>
                    <span class="dato">${escapeHtml(estadoLimiteViaje(v.minutos_restantes))}</span>
                </a>`).join('')
                : '<div class="sin-datos-compacto" style="padding:12px 16px;">No hay rutas pendientes.</div>';
        } catch (e) {
            document.getElementById('msg-operacion').textContent = 'No se pudo actualizar conectividad.';
            document.getElementById('msg-reposicion').textContent = 'No se pudo actualizar distribución.';
        }
    }

    // ---------------- KPIs + Donut ----------------
    async function cargarKpis() {
        try {
            const json = await obtenerJSON('api/resumen_maquinas.php?page_size=1');
            const k = json.kpis;
            kpisActuales = k;
            const total = Number(k.total_maquinas) || 0;
            const critico = Number(k.maquinas_con_caducados) || 0;
            const warn = Math.max(0, (Number(k.maquinas_con_vacios) || 0) - critico);
            const ok = Math.max(0, total - critico - warn);

            const pctOk = total ? Math.round((ok / total) * 100) : 0;
            const pctWarn = total ? Math.round((warn / total) * 100) : 0;
            const pctCrit = total ? Math.max(0, 100 - pctOk - pctWarn) : 0;

            const donut = document.getElementById('donut-salud');
            donut.style.setProperty('--pct-ok', pctOk);
            donut.style.setProperty('--pct-warn', pctWarn);

            document.getElementById('donut-total').textContent = fmt(total);
            document.getElementById('leg-ok').textContent = `${fmt(ok)} (${pctOk}%)`;
            document.getElementById('leg-warn').textContent = fmt(warn);
            document.getElementById('leg-crit').textContent = fmt(critico);
            document.getElementById('dist-ok').style.width = `${pctOk}%`;
            document.getElementById('dist-warn').style.width = `${pctWarn}%`;
            document.getElementById('dist-crit').style.width = `${pctCrit}%`;

            document.getElementById('kpi-caducados').textContent = fmt(k.maquinas_con_caducados);
            document.getElementById('kpi-caducados-sub').textContent = `${fmt(k.lotes_caducados_total)} lote(s) en total`;

            document.getElementById('kpi-por-caducar').textContent = fmt(k.lotes_por_caducar_30d_total);
            document.getElementById('kpi-por-caducar-sub').textContent = `de ${fmt(k.carriles_totales)} carriles en total`;
            document.getElementById('msg-control').textContent = Number(k.lotes_caducados_total) > 0
                ? `${fmt(k.lotes_caducados_total)} lote(s) caducados requieren retiro.`
                : 'Sin lotes caducados.';

            return k;
        } catch (e) {
            return null;
        }
    }

    // ---------------- Acciones sugeridas (derivadas del listado de máquinas en atención) ----------------
    async function cargarAcciones() {
        const cont = document.getElementById('lista-acciones');
        try {
            const json = await obtenerJSON('api/resumen_maquinas.php?estado=atencion&page_size=5');
            if (!json.data.length) {
                cont.innerHTML = '<li class="vacio-acciones">Sin acciones pendientes. Todo al día.</li>';
                return;
            }
            cont.innerHTML = json.data.map((f, i) => {
                let motivo;
                if (f.lotes_ya_caducados > 0) motivo = `${f.lotes_ya_caducados} lote(s) caducado(s)`;
                else motivo = `${f.carriles_vacios} carril(es) vacío(s)`;
                return `<li>
                    <span class="icono-accion">${String(i + 1).padStart(2, '0')}</span>
                    <span class="texto-accion"><b>${escapeHtml(f.numero_serie)}</b> — ${motivo}. Revisar y reabastecer.</span>
                </li>`;
            }).join('');
        } catch (e) {
            cont.innerHTML = '<li class="vacio-acciones">No se pudieron calcular las acciones.</li>';
        }
    }

    // ---------------- Alertas operativas ----------------
    async function cargarAlertas() {
        const cont = document.getElementById('tabla-alertas');
        const pag = document.getElementById('pag-alertas');
        cont.className = 'cargando';
        cont.textContent = 'Cargando…';

        const q = document.getElementById('alertas-q').value.trim();
        const params = new URLSearchParams({
            page: paginaAlertas,
            page_size: PAGE_SIZE,
            tipo: filtroTipoAlertas,
            umbral_stock: '5',
            capacidad_carril: '10',
            dias_caducidad: '30',
        });
        if (q) params.set('q', q);

        try {
            const json = await obtenerJSON(`api/alertas_operacion.php?${params}`);
            if (json.data.length === 0) {
                cont.className = '';
                cont.innerHTML = '<p class="vacio">Sin alertas para este filtro.</p>';
                pag.style.display = 'none';
                return;
            }
            cont.className = '';
            cont.innerHTML = `<table>
                <thead><tr><th>Producto</th><th>Máquina</th><th>Stock</th><th>Estado</th></tr></thead>
                <tbody>${json.data.map(f => `<tr class="${f.id_maquina ? 'fila-clic' : ''}" data-id="${f.id_maquina ?? ''}">
                    <td>${escapeHtml(f.producto) || '<span class="rol-tag">Sin producto</span>'}<br><span class="rol-tag mono">${escapeHtml(f.codigo_barras) || escapeHtml(f.numero_lote) || '-'}</span></td>
                    <td class="mono">${escapeHtml(f.numero_serie)} <span class="rol-tag">· carril ${f.numero_carril}</span></td>
                    <td>${fmt(f.cantidad_actual)}${f.porcentaje_capacidad !== undefined ? `<br><span class="rol-tag">${fmt(Number(f.porcentaje_capacidad))}% cap.</span>` : ''}</td>
                    <td>${badgeAlertaOperativa(f.tipo_alerta)}</td>
                </tr>`).join('')}</tbody>
            </table>`;
            cont.querySelectorAll('tr[data-id]').forEach(tr => {
                if (!tr.dataset.id) return;
                tr.addEventListener('click', () => { window.location.href = `maquina.php?id=${tr.dataset.id}`; });
            });

            const totalPaginas = Math.max(1, Math.ceil(json.meta.total / PAGE_SIZE));
            document.getElementById('alertas-texto').textContent = `${paginaAlertas} / ${totalPaginas}`;
            document.getElementById('alertas-anterior').disabled = paginaAlertas <= 1;
            document.getElementById('alertas-siguiente').disabled = paginaAlertas >= totalPaginas;
            pag.style.display = 'flex';
        } catch (e) {
            cont.className = '';
            cont.innerHTML = '<p class="vacio">No se pudo conectar con el servidor.</p>';
        }
    }

    // ---------------- Top productos: gráfico de barras horizontal ----------------
    async function cargarProductos() {
        const cont = document.getElementById('tabla-productos');
        cont.className = 'cargando';
        cont.textContent = 'Cargando…';
        try {
            const json = await obtenerJSON(`api/top_productos.php?orden=${ordenProductos}&limit=8`);
            if (json.data.length === 0) {
                cont.className = '';
                cont.innerHTML = '<p class="vacio">Sin datos todavía.</p>';
                return;
            }
            const obtenerValor = (f) => Number(
                ordenProductos === '7d' ? f.unidades_7d : ordenProductos === 'total' ? f.unidades_total : f.unidades_30d
            ) || 0;
            const maxUnidades = Math.max(...json.data.map(obtenerValor), 1);

            cont.className = '';
            cont.innerHTML = `<div class="grafico-productos">
                ${json.data.map((f, i) => {
                    const val = obtenerValor(f);
                    const pct = Math.max(2, Math.round((val / maxUnidades) * 100));
                    return `<div class="fila-grafico">
                        <div class="fila-grafico__rango">${String(i + 1).padStart(2, '0')}</div>
                        <div class="fila-grafico__info">
                            <div class="fila-grafico__nombre">${escapeHtml(f.nombre)}</div>
                            <div class="fila-grafico__meta">${escapeHtml(f.categoria) || 'Sin categoría'} · ${f.maquinas_distintas} máquina(s)</div>
                        </div>
                        <div class="fila-grafico__medida">
                            <div class="fila-grafico__fondo"><span style="width:${pct}%"></span></div>
                            <div class="fila-grafico__valor">${fmt(val)}</div>
                        </div>
                    </div>`;
                }).join('')}
            </div>`;
        } catch (e) {
            cont.className = '';
            cont.innerHTML = '<p class="vacio">No se pudo conectar con el servidor.</p>';
        }
    }

    // ---------------- Máquinas que requieren atención ----------------
    async function cargarMaquinas() {
        const cont = document.getElementById('tabla-maquinas');
        const pag = document.getElementById('pag-maquinas');
        cont.className = 'cargando';
        cont.textContent = 'Cargando…';

        const q = document.getElementById('maquinas-q').value.trim();
        const params = new URLSearchParams({ page: paginaMaquinas, page_size: PAGE_SIZE });
        if (q) params.set('q', q);
        if (filtroEstadoMaquinas) params.set('estado', filtroEstadoMaquinas);

        try {
            const json = await obtenerJSON(`api/resumen_maquinas.php?${params}`);
            if (json.data.length === 0) {
                cont.className = '';
                cont.innerHTML = '<p class="vacio">No hay máquinas que coincidan con el filtro.</p>';
                pag.style.display = 'none';
                return;
            }
            cont.className = '';
            cont.innerHTML = `<table>
                <thead><tr>
                    <th>Máquina</th><th>Conexión</th><th>Carriles</th><th>Caducados</th><th>Vacíos</th><th>Inventario</th>
                </tr></thead>
                <tbody>${json.data.map(f => {
                    const badge = f.lotes_ya_caducados > 0
                        ? '<span class="badge badge-danger">Atención requerida</span>'
                        : (f.carriles_vacios > 0 ? '<span class="badge badge-warn">Carriles vacíos</span>' : '<span class="badge badge-ok">OK</span>');
                    const pctVacios = f.total_carriles > 0 ? Math.round((f.carriles_vacios / f.total_carriles) * 100) : 0;
                    return `<tr class="${f.id_maquina ? 'fila-clic' : ''}" data-id="${f.id_maquina ?? ''}">
                        <td class="mono">${escapeHtml(f.numero_serie)} ${Number(f.rol_maquina) === 2 ? '<span class="rol-tag">(esclava)</span>' : ''}</td>
                        <td>${badgeConexion(f.estado_conexion)}</td>
                        <td>${f.total_carriles}</td>
                        <td>${f.lotes_ya_caducados > 0 ? `<span style="color:var(--critico); font-weight:600;">${f.lotes_ya_caducados}</span>` : '0'}</td>
                        <td>
                            <div class="barra-mini">
                                <div class="fondo"><span style="width:${pctVacios}%; background:${pctVacios > 30 ? 'var(--ambar)' : 'var(--petroleo)'};"></span></div>
                                <span class="num">${f.carriles_vacios}</span>
                            </div>
                        </td>
                        <td>${badge}</td>
                    </tr>`;
                }).join('')}</tbody>
            </table>`;
            cont.querySelectorAll('tr[data-id]').forEach(tr => {
                if (!tr.dataset.id) return;
                tr.addEventListener('click', () => { window.location.href = `maquina.php?id=${tr.dataset.id}`; });
            });

            const totalPaginas = Math.max(1, Math.ceil(json.meta.total / PAGE_SIZE));
            document.getElementById('maquinas-texto').textContent = `${paginaMaquinas} / ${totalPaginas}`;
            document.getElementById('maquinas-anterior').disabled = paginaMaquinas <= 1;
            document.getElementById('maquinas-siguiente').disabled = paginaMaquinas >= totalPaginas;
            pag.style.display = 'flex';
        } catch (e) {
            cont.className = '';
            cont.innerHTML = '<p class="vacio">No se pudo conectar con el servidor.</p>';
        }
    }

    function marcarHora() {
        const ahora = new Date();
        document.getElementById('hora-actualizacion').textContent = `Actualizado a las ${ahora.toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit' })}`;
    }

    async function actualizarTodo() {
        const boton = document.getElementById('btn-actualizar');
        boton.disabled = true;
        boton.classList.add('girando');
        await Promise.all([cargarOperacion(), cargarKpis(), cargarAlertas(), cargarProductos(), cargarMaquinas(), cargarAcciones()]);
        marcarHora();
        boton.disabled = false;
        setTimeout(() => boton.classList.remove('girando'), 400);
    }

    function csvValor(valor) {
        return `"${String(valor ?? '').replace(/"/g, '""')}"`;
    }

    function descargarCsv(nombre, filas) {
        const contenido = filas.map(f => f.map(csvValor).join(',')).join('\r\n');
        const blob = new Blob([contenido], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = nombre;
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(url);
    }

    async function generarReporteEstado() {
        const boton = document.getElementById('btn-reporte-estado');
        boton.disabled = true;
        try {
            const [resumen, maquinas, alertas, operacion] = await Promise.all([
                obtenerJSON('api/resumen_maquinas.php?page_size=1'),
                obtenerJSON('api/resumen_maquinas.php?estado=atencion&page_size=1000'),
                obtenerJSON('api/alertas_operacion.php?tipo=todas&umbral_stock=5&dias_caducidad=30&page_size=1000'),
                obtenerJSON('api/dashboard_operacion.php'),
            ]);
            const k = resumen.kpis || kpisActuales || {};
            const op = operacion.data || {};
            const fecha = new Date();
            const filas = [
                ['MDM', 'Reporte de estado'],
                ['Generado', fecha.toLocaleString('es-MX')],
                [],
                ['Resumen'],
                ['Total maquinas', k.total_maquinas ?? 0],
                ['Maquinas con caducados', k.maquinas_con_caducados ?? 0],
                ['Lotes caducados', k.lotes_caducados_total ?? 0],
                ['Lotes por caducar 30d', k.lotes_por_caducar_30d_total ?? 0],
                ['Carriles vacios', k.carriles_vacios_total ?? 0],
                [],
                ['Operacion del sistema'],
                ['Maquinas en linea', op.maquinas?.online ?? 0],
                ['Maquinas sin conexion', op.maquinas?.offline ?? 0],
                ['Maquinas nunca conectadas', op.maquinas?.nunca_conectadas ?? 0],
                ['Piezas entregadas hoy', op.salidas?.piezas_hoy ?? 0],
                ['Recetas pendientes', op.recetas?.pendientes ?? 0],
                ['Recetas en proceso', op.recetas?.en_proceso ?? 0],
                ['Viajes activos', op.distribucion?.activos ?? 0],
                ['Viajes vencidos', op.distribucion?.vencidos ?? 0],
                [],
                ['Maquinas que requieren atencion'],
                ['Serie', 'Carriles', 'Caducados', 'Vacios', 'Estado'],
                ...maquinas.data.map(m => [
                    m.numero_serie,
                    m.total_carriles,
                    m.lotes_ya_caducados,
                    m.carriles_vacios,
                    m.lotes_ya_caducados > 0 ? 'Retirar caducados' : 'Reabastecer carriles',
                ]),
                [],
                ['Alertas operativas'],
                ['Producto', 'Codigo', 'Maquina', 'Carril', 'Stock', 'Tipo alerta'],
                ...alertas.data.map(a => [
                    a.producto,
                    a.codigo_barras,
                    a.numero_serie,
                    a.numero_carril,
                    a.cantidad_actual,
                    a.tipo_alerta,
                ]),
            ];
            const nombre = `medical-life-reporte-estado-${fecha.toISOString().slice(0, 10)}.csv`;
            descargarCsv(nombre, filas);
        } catch (e) {
            await mlAlert('No se pudo generar el reporte de estado.');
        } finally {
            boton.disabled = false;
        }
    }

    if (USUARIO_SOLO_MAQUINAS_DASHBOARD) {
        document.addEventListener('click', async (ev) => {
            const link = ev.target.closest('a[href]');
            if (!link) return;
            const url = new URL(link.getAttribute('href'), window.location.href);
            const archivo = url.pathname.substring(url.pathname.lastIndexOf('/') + 1);
            const permitidos = ['dashboard.php', 'admin.php', 'maquina.php', 'logout.php'];
            if (url.origin === window.location.origin && !permitidos.includes(archivo)) {
                ev.preventDefault();
                await mlAlert('No tienes permisos de administrador', 'warning', 'Permiso requerido');
            }
        });
    }

    document.getElementById('btn-actualizar').addEventListener('click', actualizarTodo);
    document.getElementById('btn-reporte-estado').addEventListener('click', generarReporteEstado);

    let debounceAlertas = null;
    document.getElementById('alertas-q').addEventListener('input', () => {
        clearTimeout(debounceAlertas);
        debounceAlertas = setTimeout(() => { paginaAlertas = 1; cargarAlertas(); }, 350);
    });
    document.getElementById('tabs-alertas').addEventListener('click', (ev) => {
        const btn = ev.target.closest('button');
        if (!btn) return;
        activarTab(document.getElementById('tabs-alertas'), btn);
        filtroTipoAlertas = btn.dataset.tipo || 'todas';
        paginaAlertas = 1;
        cargarAlertas();
    });
    document.getElementById('alertas-anterior').addEventListener('click', () => { paginaAlertas--; cargarAlertas(); });
    document.getElementById('alertas-siguiente').addEventListener('click', () => { paginaAlertas++; cargarAlertas(); });

    document.getElementById('tabs-productos').addEventListener('click', (ev) => {
        const btn = ev.target.closest('button');
        if (!btn) return;
        activarTab(document.getElementById('tabs-productos'), btn);
        ordenProductos = btn.dataset.orden;
        cargarProductos();
    });

    let debounceMaquinas = null;
    document.getElementById('maquinas-q').addEventListener('input', () => {
        clearTimeout(debounceMaquinas);
        debounceMaquinas = setTimeout(() => { paginaMaquinas = 1; cargarMaquinas(); }, 350);
    });
    document.getElementById('tabs-maquinas').addEventListener('click', (ev) => {
        const btn = ev.target.closest('button');
        if (!btn) return;
        activarTab(document.getElementById('tabs-maquinas'), btn);
        filtroEstadoMaquinas = btn.dataset.estado;
        paginaMaquinas = 1;
        cargarMaquinas();
    });
    document.getElementById('maquinas-anterior').addEventListener('click', () => { paginaMaquinas--; cargarMaquinas(); });
    document.getElementById('maquinas-siguiente').addEventListener('click', () => { paginaMaquinas++; cargarMaquinas(); });

    iniciarPulso();
    actualizarTodo();
    setInterval(() => { if (!document.hidden) cargarOperacion(); }, 60000);
</script>
</body>
</html>

