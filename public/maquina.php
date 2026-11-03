<?php
// public/maquina.php — detalle de una unidad (maestra + esclavas)
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/bootstrap.php';

use App\Auth\Auth;

Auth::requireLogin();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>MDM - Detalle de unidad</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/estilo.css?v=20260914-2">
    <style>
        /* ================================================================
           Tema oscuro "sala de control" — mismos tokens que dashboard.php
           y admin.php, con animaciones de entrada y microinteracciones.
           ================================================================ */
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
            --petroleo-fuerte: #168A7F;
            --petroleo-wash: rgba(44, 183, 165, .14);

            --ambar: #D6A34A;
            --ambar-wash: rgba(214, 163, 74, .15);

            --critico: #E06C78;
            --critico-wash: rgba(224, 108, 120, .15);

            background: var(--bg);
            color: var(--texto);
        }
        body.tema-oscuro .sidebar { background: var(--bg-elevada); border-right: 1px solid var(--linea); }
        body.tema-oscuro .marca .icono-enlace { color: var(--petroleo); }
        body.tema-oscuro .marca .texto small { color: var(--texto-tenue); }
        body.tema-oscuro .nav-lateral a { color: var(--texto-suave); }
        body.tema-oscuro .nav-lateral a:hover { background: var(--bg-hover); color: var(--texto); }
        body.tema-oscuro .nav-lateral a.activa { background: var(--petroleo-wash); color: var(--petroleo); }
        body.tema-oscuro h1, body.tema-oscuro h2, body.tema-oscuro h3 { color: var(--texto); }

        body.tema-oscuro .volver { color: var(--texto-suave); }
        body.tema-oscuro .volver:hover { color: var(--petroleo); }

        body.tema-oscuro .card { background: var(--bg-elevada); border: 1px solid var(--linea); border-radius: var(--radius-lg); box-shadow: none; }
        body.tema-oscuro .card-encabezado { border-bottom: 1px solid var(--linea-suave); display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; }
        body.tema-oscuro .card-encabezado h2 { font-size: 14.5px; }
        body.tema-oscuro .vacio, body.tema-oscuro .cargando { color: var(--texto-tenue); font-style: italic; padding: 30px 14px; text-align: center; }

        /* -------- Cabecera de la unidad -------- */
        .cabecera-unidad { animation: aparecer .45s ease both; }
        .cabecera-unidad h1 { font-size: 21px; margin: 0 0 4px; }
        .cabecera-unidad .sub { color: var(--texto-suave); font-size: 13px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .cabecera-unidad .chips { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 14px; }

        .chip-unidad {
            display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px 4px 8px;
            border-radius: 999px; background: var(--bg); border: 1px solid var(--linea); font-size: 12px; color: var(--texto-suave);
        }
        .chip-unidad .rol-punto { width: 6px; height: 6px; border-radius: 50%; background: var(--texto-tenue); }

        body.tema-oscuro .badge { display: inline-flex; align-items: center; gap: 5px; padding: 3px 10px; border-radius: 999px; font-size: 11px; font-weight: 600; white-space: nowrap; }
        body.tema-oscuro .badge::before { content: ""; width: 6px; height: 6px; border-radius: 50%; background: currentColor; }
        body.tema-oscuro .badge-ok { background: var(--petroleo-wash); color: var(--petroleo); }
        body.tema-oscuro .badge-warn { background: var(--ambar-wash); color: var(--ambar); }
        body.tema-oscuro .badge-danger { background: var(--critico-wash); color: var(--critico); }
        body.tema-oscuro .badge-neutro { background: var(--bg); color: var(--texto-tenue); border: 1px solid var(--linea); }

        /* -------- Tabs -------- */
        .tabs { display: flex; gap: 4px; padding: 6px 16px; border-bottom: 1px solid var(--linea-suave); overflow-x: auto; }
        .tab {
            border: none; background: none; cursor: pointer; font-family: inherit; font-size: 13px; font-weight: 500;
            padding: 10px 14px; color: var(--texto-suave); border-radius: var(--radius-sm); white-space: nowrap;
            display: flex; align-items: center; gap: 7px; transition: background .15s ease, color .15s ease;
        }
        .tab:hover { color: var(--texto); background: var(--bg-hover); }
        .tab.activa { color: var(--petroleo); background: var(--petroleo-wash); font-weight: 600; }
        .tab .rol-punto { width: 6px; height: 6px; border-radius: 50%; background: var(--texto-tenue); }
        .tab.rol-maestra .rol-punto { background: var(--petroleo); }
        .tab .muted { color: var(--texto-tenue); }

        /* -------- Grid de info -------- */
        .equipo-resumen {
            display: grid; grid-template-columns: 230px minmax(0, 1fr); gap: 18px;
            align-items: stretch; margin-bottom: 22px;
        }
        .equipo-foto {
            position: relative; min-height: 286px; overflow: hidden; border: 1px solid var(--linea);
            border-radius: 7px; background: linear-gradient(145deg, #DCE7EE, #F6F9FB);
            box-shadow: 0 10px 24px rgba(8, 25, 39, .13);
        }
        .equipo-foto img {
            display: block; width: 100%; height: 100%; min-height: 286px; object-fit: contain;
            object-position: center 48%; transition: transform .35s ease, opacity .25s ease;
        }
        body.tema-mdm-oscuro .equipo-foto { background: #0C1720; }
        .equipo-foto:hover img { transform: scale(1.025); }
        .equipo-foto .equipo-tipo {
            position: absolute; top: 12px; left: 12px; display: inline-flex; align-items: center; gap: 7px;
            padding: 6px 9px; border: 1px solid rgba(255,255,255,.72); border-radius: 5px;
            background: rgba(8,25,39,.82); color: #FFFFFF; font-size: 10px; font-weight: 800;
            text-transform: uppercase; backdrop-filter: blur(5px);
        }
        .equipo-foto .equipo-tipo::before { content: ''; width: 7px; height: 7px; border-radius: 50%; background: #62C873; }
        .info-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 14px; }
        .info-grid > div {
            background: var(--bg); border: 1px solid var(--linea-suave); border-radius: var(--radius);
            padding: 12px 14px;
        }
        .info-grid .etiqueta { font-size: 10.5px; text-transform: uppercase; letter-spacing: .05em; color: var(--texto-tenue); margin-bottom: 5px; }
        .info-grid .valor { font-size: 14px; color: var(--texto); }

        @media (max-width: 760px) {
            .equipo-resumen { grid-template-columns: 1fr; }
            .equipo-foto { min-height: 250px; max-height: 360px; }
            .equipo-foto img { min-height: 250px; }
        }

        /* -------- Estado de los carriles: KPIs + donut + panel de alertas -------- */
        .resumen-carriles { display: flex; flex-direction: column; gap: 18px; }

        /* Fila de KPIs clicables (mismo patrón que la tira de stats de admin.php) */
        .stats-carriles { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; }
        .stat-carril {
            background: var(--bg); border: 1px solid var(--linea-suave); border-radius: var(--radius);
            padding: 12px 14px; cursor: pointer; transition: border-color .15s ease, background .15s ease; text-align: left;
        }
        .stat-carril:hover { background: var(--bg-hover); }
        .stat-carril.activo { border-color: var(--stat-color, var(--petroleo)); background: var(--bg-hover); }
        .stat-carril .lbl { display: flex; align-items: center; gap: 6px; font-size: 10.5px; text-transform: uppercase; letter-spacing: .05em; color: var(--texto-tenue); font-weight: 600; margin-bottom: 8px; }
        .stat-carril .lbl .punto { width: 7px; height: 7px; border-radius: 50%; background: var(--stat-color, var(--texto-tenue)); flex-shrink: 0; }
        .stat-carril .num { font-family: 'Space Grotesk', sans-serif; font-size: 24px; font-weight: 700; line-height: 1; color: var(--stat-color, var(--texto)); }

        /* Fila inferior: donut compacto a la izquierda + panel de alertas a la derecha (ocupa todo el ancho restante) */
        .fila-detalle-carriles { display: grid; grid-template-columns: 168px 1fr; gap: 22px; align-items: stretch; }
        .donut-envoltura { position: relative; width: 140px; height: 140px; margin: 0 auto; }
        .donut {
            width: 140px; height: 140px; border-radius: 50%; position: relative;
            transition: background 1.1s cubic-bezier(.16,1,.3,1);
        }
        .donut .centro {
            position: absolute; inset: 22px; background: var(--bg-elevada); border-radius: 50%;
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            border: 1px solid var(--linea-suave);
        }
        .donut .centro .n { font-family: 'Space Grotesk', sans-serif; font-size: 21px; font-weight: 700; line-height: 1; color: var(--texto); }
        .donut .centro .l { font-size: 9.5px; color: var(--texto-tenue); margin-top: 3px; }

        .panel-alertas {
            display: grid; grid-template-columns: 1fr 1fr; gap: 14px;
            background: var(--bg); border: 1px solid var(--linea-suave); border-radius: var(--radius);
            padding: 14px 16px;
        }
        .alerta-col { display: flex; flex-direction: column; min-width: 0; }
        .alerta-col:first-child { border-right: 1px solid var(--linea-suave); padding-right: 14px; }
        .alerta-col .titulo {
            font-size: 10.5px; text-transform: uppercase; letter-spacing: .05em; font-weight: 700;
            margin-bottom: 9px; display: flex; align-items: center; gap: 6px; flex-shrink: 0;
        }
        .alerta-col .titulo .punto { width: 7px; height: 7px; border-radius: 50%; }
        .alerta-col ul { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 5px; overflow-y: auto; max-height: 148px; }
        .alerta-col li {
            display: flex; justify-content: space-between; gap: 8px; font-size: 12px;
            background: var(--bg-elevada); border: 1px solid var(--linea-suave); border-radius: var(--radius-sm); padding: 6px 9px;
        }
        .alerta-col li .nombre { color: var(--texto); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .alerta-col li .dias { color: var(--texto-tenue); white-space: nowrap; flex-shrink: 0; font-size: 11px; }
        .alerta-col .vacio-mini {
            flex: 1; display: flex; align-items: center; justify-content: center; text-align: center;
            font-size: 12px; color: var(--texto-tenue); font-style: italic; min-height: 80px;
        }
        .alerta-col .ver-mas { font-size: 11px; color: var(--petroleo); margin-top: 6px; cursor: pointer; }
        .alerta-col .ver-mas:hover { text-decoration: underline; }

        @media (max-width: 760px) {
            .stats-carriles { grid-template-columns: repeat(2, 1fr); }
            .fila-detalle-carriles { grid-template-columns: 1fr; }
            .panel-alertas { grid-template-columns: 1fr; }
            .alerta-col:first-child { border-right: none; border-bottom: 1px solid var(--linea-suave); padding-right: 0; padding-bottom: 14px; }
        }

        /* -------- Buscador de carriles / medicamentos -------- */
        .buscador-carriles {
            display: flex; gap: 10px; padding: 14px 16px; border-bottom: 1px solid var(--linea-suave); flex-wrap: wrap; align-items: center;
        }
        body.tema-oscuro .buscador-carriles input[type="text"] {
            flex: 1; min-width: 220px; padding: 9px 13px; border: 1px solid var(--linea); border-radius: var(--radius-sm);
            background: var(--bg); color: var(--texto); font-family: inherit; font-size: 13px;
        }
        body.tema-oscuro .buscador-carriles input[type="text"]::placeholder { color: var(--texto-tenue); }
        body.tema-oscuro .buscador-carriles select {
            padding: 9px 12px; border: 1px solid var(--linea); border-radius: var(--radius-sm);
            background: var(--bg); color: var(--texto); font-family: inherit; font-size: 13px;
        }
        .buscador-carriles .contador-mini { font-size: 11.5px; color: var(--texto-tenue); white-space: nowrap; margin-left: auto; }

        /* -------- Formulario de carga -------- */
        body.tema-oscuro .form-row label { color: var(--texto-suave); display: block; font-size: 12px; margin-bottom: 5px; }
        body.tema-oscuro input[type="file"], body.tema-oscuro input[type="text"], body.tema-oscuro input[type="date"], body.tema-oscuro select, body.tema-oscuro textarea {
            background: var(--bg); border: 1px solid var(--linea); color: var(--texto);
        }
        body.tema-oscuro input[type="file"]::file-selector-button {
            background: var(--petroleo-wash); color: var(--petroleo); border: none; border-radius: 6px;
            padding: 6px 12px; font-family: inherit; font-weight: 600; font-size: 12px; margin-right: 10px; cursor: pointer;
        }
        body.tema-oscuro button.primario { background: var(--petroleo); color: #06201F; border: none; }
        body.tema-oscuro button.primario:hover { background: #00B8B8; }
        body.tema-oscuro button.primario:disabled { opacity: .5; }

        .msg-ok { background: var(--petroleo-wash); color: var(--petroleo); padding: 10px 13px; border-radius: var(--radius-sm); font-size: 13px; margin-top: 10px; animation: aparecer .3s ease both; }
        .msg-error { background: var(--critico-wash); color: var(--critico); padding: 10px 13px; border-radius: var(--radius-sm); font-size: 13px; margin-top: 10px; animation: aparecer .3s ease both; }

        /* -------- Tabla de carriles / mantenimiento -------- */
        body.tema-oscuro table { width: 100%; border-collapse: collapse; font-size: 13px; }
        body.tema-oscuro th, body.tema-oscuro td { text-align: left; padding: 11px 14px; border-bottom: 1px solid var(--linea-suave); }
        body.tema-oscuro th { color: var(--texto-tenue); font-weight: 600; text-transform: uppercase; font-size: 10px; letter-spacing: .06em; }
        body.tema-oscuro tbody tr:last-child td { border-bottom: none; }
        body.tema-oscuro tbody tr { transition: background .15s ease; }
        body.tema-oscuro tbody tr:hover { background: var(--bg-hover); }
        body.tema-oscuro .mono { font-family: 'JetBrains Mono', monospace; }
        body.tema-oscuro tr.fila-vencida td { background: var(--critico-wash); }
        body.tema-oscuro tr.fila-por-vencer td { background: var(--ambar-wash); }

        .btn-encabezado {
            border: none; background: var(--petroleo-wash); color: var(--petroleo); border-radius: 6px;
            padding: 7px 13px; font-family: inherit; font-weight: 600; font-size: 12px; cursor: pointer; white-space: nowrap;
        }
        .btn-encabezado:hover { background: var(--petroleo); color: #06201F; }

        /* -------- Modal genérico (mantenimiento) -------- */
        .overlay-modal {
            position: fixed; inset: 0; background: rgba(10, 12, 14, .65); display: none;
            align-items: flex-start; justify-content: center; padding: 40px 16px; overflow-y: auto; z-index: 50;
            backdrop-filter: blur(2px);
        }
        .overlay-modal.abierto { display: flex; }
        .modal {
            background: var(--bg-elevada); border: 1px solid var(--linea); border-radius: var(--radius-lg); width: 100%; max-width: 560px;
            box-shadow: 0 24px 60px rgba(0,0,0,.5);
        }
        .modal-encabezado {
            display: flex; align-items: center; justify-content: space-between;
            padding: 18px 22px; border-bottom: 1px solid var(--linea-suave);
        }
        .modal-encabezado h2 { margin: 0; font-size: 15.5px; }
        .modal-cerrar { border: none; background: none; cursor: pointer; font-size: 18px; color: var(--texto-tenue); line-height: 1; }
        .modal-cerrar:hover { color: var(--texto); }
        .modal-cuerpo { padding: 22px; display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        .modal-cuerpo .completo { grid-column: 1 / -1; }
        .modal-cuerpo .form-row input,
        .modal-cuerpo .form-row select,
        .modal-cuerpo .form-row textarea {
            width: 100%; box-sizing: border-box; padding: 9px 11px; border: 1px solid var(--linea);
            border-radius: var(--radius-sm); font-family: inherit; font-size: 13.5px; background: var(--bg); color: var(--texto);
        }
        .modal-cuerpo .form-row textarea { resize: vertical; min-height: 64px; }
        .modal-cuerpo .form-row input:focus, .modal-cuerpo .form-row select:focus, .modal-cuerpo .form-row textarea:focus { outline: none; border-color: var(--petroleo); }
        .modal-pie {
            padding: 16px 22px; border-top: 1px solid var(--linea-suave); display: flex;
            justify-content: flex-end; gap: 10px; align-items: center;
        }
        .modal-mensaje { font-size: 12.5px; margin-right: auto; }
        .modal-mensaje.error { color: var(--critico); }
        .modal-mensaje.ok { color: var(--petroleo); }
        .modal-pie button { font-family: inherit; font-size: 13px; font-weight: 600; padding: 9px 18px; border-radius: var(--radius-sm); cursor: pointer; }
        .modal-pie .btn-cancelar { border: 1px solid var(--linea); background: var(--bg); color: var(--texto-suave); }
        .modal-pie .btn-cancelar:hover { color: var(--texto); }
        .modal-pie .btn-guardar { border: none; background: var(--petroleo); color: #06201F; }
        .modal-pie .btn-guardar:hover { background: #00B8B8; }
        .modal-pie .btn-guardar:disabled { opacity: .5; cursor: default; }

        @media (max-width: 620px) {
            .modal-cuerpo { grid-template-columns: 1fr; }
        }

        /* -------- Animaciones -------- */
        @keyframes aparecer {
            from { opacity: 0; transform: translateY(6px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .panel-animado { animation: aparecer .4s ease both; }
        .panel-animado:nth-of-type(2) { animation-delay: .04s; }
        .panel-animado:nth-of-type(3) { animation-delay: .08s; }
        .panel-animado:nth-of-type(4) { animation-delay: .12s; }
        .panel-animado:nth-of-type(5) { animation-delay: .16s; }

        /* -------- Expediente operativo de la maquina -------- */
        .expediente-maquina {
            overflow: visible;
            border-top: 4px solid #214F8B !important;
            box-shadow: 0 18px 44px rgba(18, 39, 56, .11) !important;
            perspective: 1500px;
        }
        body.tema-mdm-oscuro .expediente-maquina {
            border-top-color: #62C873 !important;
            box-shadow: 0 24px 54px rgba(0, 0, 0, .28) !important;
        }
        #panel-unidad {
            position: relative;
            transform-style: preserve-3d;
            transform-origin: 50% 42%;
            backface-visibility: hidden;
            will-change: transform, opacity;
        }
        #panel-unidad.flip-salida { animation: voltearSalida .2s cubic-bezier(.4, 0, 1, 1) both; }
        #panel-unidad.flip-entrada { animation: voltearEntrada .3s cubic-bezier(0, 0, .2, 1) both; }
        #panel-unidad.cambiando-unidad { pointer-events: none; }

        .cabecera-unidad {
            position: relative;
            overflow: hidden;
            border-left: 5px solid #62C873 !important;
        }
        .cabecera-unidad::after {
            content: "EXPEDIENTE MDM";
            position: absolute;
            right: 18px;
            top: 15px;
            color: var(--texto-tenue);
            font: 700 10px/1 'JetBrains Mono', monospace;
        }
        .equipo-resumen {
            padding: 18px;
            border: 1px solid var(--linea-suave);
            border-left: 4px solid #214F8B;
            background: color-mix(in srgb, var(--bg-elevada) 93%, #214F8B 7%);
        }
        body.tema-mdm-oscuro .equipo-resumen { border-left-color: #62C873; }
        .info-grid > div {
            position: relative;
            min-height: 66px;
            border-radius: 4px;
            border-left: 3px solid #9FB0BC;
            box-shadow: inset 0 1px 0 rgba(255,255,255,.04);
        }
        .info-grid > div:nth-child(3n + 1) { border-left-color: #214F8B; }
        .info-grid > div:nth-child(3n + 2) { border-left-color: #62C873; }
        .info-grid > div:nth-child(3n) { border-left-color: #C79B4B; }
        .info-grid .valor { font-family: 'Space Grotesk', sans-serif; font-size: 16px; font-weight: 700; }
        #panel-unidad > .card {
            margin-top: 18px;
            border-radius: 4px !important;
            border-left: 4px solid #214F8B !important;
            box-shadow: none !important;
        }
        #panel-unidad > .card:nth-of-type(even) { border-left-color: #62C873 !important; }
        #panel-unidad > .card .card-encabezado {
            min-height: 54px;
            padding: 12px 16px;
            background: color-mix(in srgb, var(--bg) 88%, #214F8B 12%);
        }
        #panel-unidad > .card .card-encabezado h2 { font-size: 16px; }

        .analitica-inventario {
            display: grid;
            grid-template-columns: minmax(0, 1.2fr) minmax(280px, .8fr);
            gap: 14px;
        }
        .grafica-inventario {
            border: 1px solid var(--linea-suave);
            border-radius: 4px;
            background: var(--bg);
            padding: 15px;
            min-width: 0;
        }
        .grafica-inventario__head { display: flex; justify-content: space-between; gap: 12px; margin-bottom: 14px; }
        .grafica-inventario__head h3 { margin: 0; font-size: 13px; }
        .grafica-inventario__head span { color: var(--texto-tenue); font-size: 10px; text-transform: uppercase; }
        .barras-existencia { display: grid; grid-template-columns: repeat(10, minmax(18px, 1fr)); gap: 7px; height: 154px; align-items: end; }
        .barra-existencia { display: grid; grid-template-rows: 1fr auto; gap: 7px; height: 100%; min-width: 0; }
        .barra-existencia__pista { position: relative; align-self: stretch; background: var(--linea-suave); overflow: hidden; }
        .barra-existencia__valor {
            position: absolute; inset: auto 0 0; min-height: 4px;
            background: linear-gradient(180deg, #62C873, #214F8B);
            transform: scaleY(0); transform-origin: bottom;
            animation: crecerBarra .7s cubic-bezier(.16,1,.3,1) forwards;
        }
        .barra-existencia__label { overflow: hidden; text-overflow: ellipsis; text-align: center; color: var(--texto-tenue); font: 9px/1 'JetBrains Mono', monospace; }
        .ranking-stock { display: flex; flex-direction: column; gap: 10px; }
        .ranking-stock__fila { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 6px 12px; align-items: center; }
        .ranking-stock__nombre { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: var(--texto); font-size: 11px; font-weight: 700; }
        .ranking-stock__cantidad { color: var(--texto); font: 700 11px/1 'JetBrains Mono', monospace; }
        .ranking-stock__pista { grid-column: 1 / -1; height: 7px; background: var(--linea-suave); overflow: hidden; }
        .ranking-stock__barra {
            display: block; height: 100%; background: #214F8B;
            transform: scaleX(0); transform-origin: left;
            animation: crecerRanking .7s .12s cubic-bezier(.16,1,.3,1) forwards;
        }
        body.tema-mdm-oscuro .ranking-stock__barra { background: #62C873; }

        @keyframes voltearSalida {
            from { transform: rotateY(0deg) scale(1); opacity: 1; }
            to { transform: rotateY(34deg) translateX(14px) scale(.985); opacity: 0; }
        }
        @keyframes voltearEntrada {
            from { transform: rotateY(-34deg) translateX(-14px) scale(.985); opacity: 0; }
            to { transform: rotateY(0deg) scale(1); opacity: 1; }
        }
        @keyframes crecerBarra { to { transform: scaleY(1); } }
        @keyframes crecerRanking { to { transform: scaleX(1); } }

        /* Refinamiento Fluent: superficies neutras y un solo acento funcional. */
        .expediente-maquina {
            border-top: 1px solid var(--linea) !important;
            border-radius: 7px !important;
            box-shadow: 0 14px 34px rgba(18,39,56,.09) !important;
        }
        .cabecera-unidad { border-left: 1px solid var(--linea) !important; }
        .equipo-resumen {
            border: 1px solid var(--linea-suave);
            border-left: 1px solid var(--linea-suave);
            border-radius: 6px;
            background: var(--bg-elevada);
        }
        .info-grid > div,
        .info-grid > div:nth-child(3n + 1),
        .info-grid > div:nth-child(3n + 2),
        .info-grid > div:nth-child(3n) {
            padding-right: 42px;
            border-left: 1px solid var(--linea-suave);
            border-radius: 6px;
            box-shadow: 0 3px 10px rgba(18,39,56,.04);
        }
        .info-grid > div > svg {
            position: absolute;
            right: 13px;
            top: 13px;
            width: 17px;
            height: 17px;
            color: var(--azul);
        }
        #panel-unidad > .card,
        #panel-unidad > .card:nth-of-type(even) {
            border-left: 1px solid var(--linea) !important;
            border-radius: 6px !important;
            box-shadow: 0 7px 18px rgba(18,39,56,.05) !important;
        }
        #panel-unidad > .card .card-encabezado { background: var(--bg-elevada-2); }

        @media (max-width: 920px) {
            .analitica-inventario { grid-template-columns: 1fr; }
        }

        @media (prefers-reduced-motion: reduce) {
            .cabecera-unidad, .msg-ok, .msg-error, .panel-animado, #panel-unidad,
            .barra-existencia__valor, .ranking-stock__barra { animation: none !important; transform: none !important; }
            .donut { transition: none !important; }
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

    <main class="principal angosto">
        <a href="admin.php" class="volver">&larr; Volver al listado</a>

        <div class="card cabecera-unidad">
            <div class="card-cuerpo" id="cabecera">
                <p class="cargando">Cargando…</p>
            </div>
        </div>

        <div class="card expediente-maquina">
            <div class="tabs" id="tabs"></div>
            <div class="card-cuerpo" id="panel-unidad">
                <p class="cargando">Cargando…</p>
            </div>
        </div>
    </main>
</div>

<!-- Modal registrar/editar mantenimiento -->
<div class="overlay-modal" id="overlay-mant">
    <div class="modal">
        <div class="modal-encabezado">
            <h2 id="mant-titulo">Registrar mantenimiento</h2>
            <button class="modal-cerrar" id="mant-cerrar">&times;</button>
        </div>
        <form id="form-mant">
            <div class="modal-cuerpo">
                <input type="hidden" id="fm-id_mantenimiento" value="">
                <input type="hidden" id="fm-id_maquina" value="">

                <div class="form-row completo">
                    <label>Tipo de mantenimiento *</label>
                    <select id="fm-tipo_mantenimiento" required></select>
                </div>

                <div class="form-row completo">
                    <label>Descripción</label>
                    <textarea id="fm-descripcion" placeholder="Detalle de la incidencia, pieza reemplazada, etc."></textarea>
                </div>

                <div class="form-row">
                    <label>Técnico</label>
                    <input type="text" id="fm-tecnico">
                </div>
                <div class="form-row">
                    <label>Estatus *</label>
                    <select id="fm-id_estatus" required></select>
                </div>

                <div class="form-row">
                    <label>Fecha programada</label>
                    <input type="date" id="fm-fecha_programada">
                </div>
                <div class="form-row">
                    <label>Fecha realizado</label>
                    <input type="date" id="fm-fecha_realizado">
                </div>
            </div>
            <div class="modal-pie">
                <span class="modal-mensaje" id="mant-mensaje"></span>
                <button type="button" class="btn-cancelar" id="mant-cancelar">Cancelar</button>
                <button type="submit" class="btn-guardar" id="mant-guardar">Guardar</button>
            </div>
        </form>
    </div>
</div>

<script src="assets/js/comun.js?v=20260912-1"></script>
<script>
    const USUARIO_SOLO_MAQUINAS_DASHBOARD = <?= Auth::isUsuarioMaquinasDashboard() ? 'true' : 'false' ?>;
    const params = new URLSearchParams(window.location.search);
    const idSolicitado = parseInt(params.get('id'), 10);

    let unidades = [];      // [{ id_maquina, numero_serie, rol_maquina, ... }]
    let pestanaActiva = null;
    let filasCarrilesCache = {};   // { id_maquina: [filas de detalle_maquina] }
    let filtroCarriles = { texto: '', categoria: '' };
    let catalogoEstatusMant = null; // cache del catálogo de estatus
    let catalogoTiposMant = null;   // cache del catálogo de tipos de mantenimiento

    if (!idSolicitado) {
        document.getElementById('cabecera').innerHTML = '<p class="vacio">Falta el id de la máquina en la URL.</p>';
    } else {
        iniciar();
    }

    async function iniciar() {
        try {
            const json = await obtenerJSON(`api/maquina.php?id_maquina=${idSolicitado}`);
            const m = json.data;

            // Si el id apunta a una esclava, reubicamos la URL en su maestra
            // para que la unidad siempre se muestre completa desde la raíz.
            if (Number(m.rol_maquina) === 2 && m.id_maquina_padre) {
                window.location.replace(`maquina.php?id=${m.id_maquina_padre}`);
                return;
            }

            unidades = [m, ...(m.esclavas || [])].map(u => ({
                ...u,
                id_maquina: Number(u.id_maquina),
                rol_maquina: Number(u.id_maquina) === Number(m.id_maquina) ? 1 : 2,
            }));
            pestanaActiva = unidades[0].id_maquina;

            renderCabecera(m);
            renderTabs();
            renderPanel();
        } catch (e) {
            document.getElementById('cabecera').innerHTML = `<p class="vacio">Error: ${escapeHtml(e.message)}</p>`;
        }
    }

    function renderCabecera(maestra) {
        const dias = unidades.map(u => u.dias_sin_mantenimiento === null || u.dias_sin_mantenimiento === undefined ? null : Number(u.dias_sin_mantenimiento));
        const peor = peorDias(dias);
        const chips = unidades.slice(1).map(e => `<span class="chip-unidad es-esclava"><span class="rol-punto"></span><span class="mono">${escapeHtml(e.numero_serie)}</span></span>`).join('');

        document.getElementById('cabecera').innerHTML = `
            <h1 class="mono">${escapeHtml(maestra.numero_serie)}</h1>
            <p class="sub">${escapeHtml(maestra.nombre_ubicacion) || 'Sin ubicación registrada'} · Estado de la unidad: ${badgeMantenimiento(peor)}</p>
            ${unidades.length > 1 ? `<div class="chips">${chips}</div>` : '<p class="sub" style="margin-top:10px;">Esta unidad no tiene esclavas asociadas.</p>'}
        `;
    }

    function renderTabs() {
        document.getElementById('tabs').innerHTML = unidades.map(u => `
            <button class="tab ${u.rol_maquina === 1 ? 'rol-maestra' : ''} ${u.id_maquina === pestanaActiva ? 'activa' : ''}" data-id="${u.id_maquina}">
                <span class="rol-punto"></span>
                <span class="mono">${escapeHtml(u.numero_serie)}</span>
                <span class="muted">${u.rol_maquina === 1 ? '· Maestra' : '· Esclava'}</span>
            </button>
        `).join('');

        document.querySelectorAll('.tab').forEach(btn => {
            btn.addEventListener('click', () => {
                cambiarUnidad(parseInt(btn.dataset.id, 10));
            });
        });
    }

    function cambiarUnidad(idMaquina) {
        if (idMaquina === pestanaActiva) return;
        const panel = document.getElementById('panel-unidad');
        if (panel.classList.contains('cambiando-unidad')) return;

        const dibujarUnidad = () => {
            pestanaActiva = idMaquina;
            filtroCarriles = { texto: '', categoria: '' };
            renderTabs();
            renderPanel();
        };

        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            dibujarUnidad();
            return;
        }

        panel.classList.add('cambiando-unidad', 'flip-salida');
        window.setTimeout(() => {
            dibujarUnidad();
            panel.classList.remove('flip-salida');
            panel.classList.add('flip-entrada');
            window.setTimeout(() => panel.classList.remove('flip-entrada', 'cambiando-unidad'), 310);
        }, 195);
    }

    function estadoConexion(u) {
        if (u.estado_conexion === 'online') return '<span class="badge badge-ok">En línea</span>';
        if (u.estado_conexion === 'offline') return '<span class="badge badge-danger">Sin conexión</span>';
        return '<span class="badge badge-neutro">Nunca conectada</span>';
    }

    function ultimaConexion(u) {
        if (u.estado_conexion === 'nunca_conectada') return 'Sin latidos recibidos';
        const segundos = Math.max(0, Number(u.segundos_desde_conexion) || 0);
        if (segundos < 60) return 'Hace menos de 1 min';
        const minutos = Math.floor(segundos / 60);
        if (minutos < 60) return `Hace ${minutos} min`;
        const horas = Math.floor(minutos / 60);
        return horas < 24 ? `Hace ${horas} h` : `Hace ${Math.floor(horas / 24)} d`;
    }

    function renderPanel() {
        const u = unidades.find(x => x.id_maquina === pestanaActiva);
        const cont = document.getElementById('panel-unidad');
        const datoUltimaCargaStock = USUARIO_SOLO_MAQUINAS_DASHBOARD ? '' : `
                <div><i data-lucide="history" aria-hidden="true"></i><div class="etiqueta">Última carga de stock</div><div class="valor">${badgeMantenimiento(u.dias_sin_mantenimiento === null || u.dias_sin_mantenimiento === undefined ? null : Number(u.dias_sin_mantenimiento))}</div></div>
        `;
        const seccionCargaStock = USUARIO_SOLO_MAQUINAS_DASHBOARD ? '' : `
            <div class="card panel-animado">
                <div class="card-encabezado">
                    <h2>Cargar stock (Excel)</h2>
                    <a class="btn" href="api/plantilla_stock.php" download>Descargar plantilla vacía</a>
                </div>
                <div class="card-cuerpo">
                    <p class="muted">Llena una fila por producto, lote y carril. Usa cabina 1 para la maestra y cabina 2 para la esclava.</p>
                    <form class="form-stock" data-id="${u.id_maquina}">
                        <div class="form-row">
                            <label>Archivo Excel</label>
                            <input type="file" class="archivo-excel" accept=".xlsx,.xls" required>
                        </div>
                        <div class="form-row">
                            <label>Usuario que carga</label>
                            <input type="text" class="usuario-carga" value="admin">
                        </div>
                        <button type="submit" class="primario">Cargar stock</button>
                        <div class="resultado-carga"></div>
                    </form>
                </div>
            </div>
        `;
        const seccionMantenimiento = USUARIO_SOLO_MAQUINAS_DASHBOARD ? '' : `
            <div class="card panel-animado">
                <div class="card-encabezado">
                    <h2>Historial de mantenimiento</h2>
                    <button type="button" class="btn-encabezado" id="btn-registrar-mant">+ Registrar</button>
                </div>
                <div class="card-cuerpo" id="mant-${u.id_maquina}"><p class="cargando">Cargando…</p></div>
            </div>
        `;

        cont.innerHTML = `
            <div class="equipo-resumen panel-animado">
                <div class="equipo-foto">
                    <img src="${u.rol_maquina === 1 ? 'assets/img/maquina-principal-mdm.png' : 'assets/img/maquina-esclava-mdm.png'}" alt="Equipo MDM ${u.rol_maquina === 1 ? 'principal' : 'esclavo'}">
                    <span class="equipo-tipo">${u.rol_maquina === 1 ? 'Principal' : 'Esclava'}</span>
                </div>
                <div class="info-grid">
                <div><i data-lucide="cpu" aria-hidden="true"></i><div class="etiqueta">Rol</div><div class="valor">${u.rol_maquina === 1 ? 'Maestra' : 'Esclava'}</div></div>
                <div><i data-lucide="scan-line" aria-hidden="true"></i><div class="etiqueta">Código interno</div><div class="valor mono">${escapeHtml(u.codigo_interno) || '—'}</div></div>
                <div><i data-lucide="panels-top-left" aria-hidden="true"></i><div class="etiqueta">Carriles</div><div class="valor">${u.numero_carriles ?? '—'}</div></div>
                ${datoUltimaCargaStock}
                <div><i data-lucide="wifi" aria-hidden="true"></i><div class="etiqueta">Conexión</div><div class="valor">${estadoConexion(u)}</div></div>
                <div><i data-lucide="heart-pulse" aria-hidden="true"></i><div class="etiqueta">Último latido</div><div class="valor">${escapeHtml(ultimaConexion(u))}</div></div>
                <div><i data-lucide="radio-tower" aria-hidden="true"></i><div class="etiqueta">Red</div><div class="valor">${escapeHtml(u.tipo_conexion) || '—'}</div></div>
                <div><i data-lucide="smartphone" aria-hidden="true"></i><div class="etiqueta">Versión de app</div><div class="valor mono">${escapeHtml(u.version_app) || '—'}</div></div>
                </div>
            </div>

            <div class="card panel-animado">
                <div class="card-encabezado"><h2>Estado de los carriles</h2></div>
                <div class="card-cuerpo" id="grafica-${u.id_maquina}"><p class="cargando">Cargando…</p></div>
            </div>

            ${seccionCargaStock}

            <div class="card panel-animado">
                <div class="card-encabezado"><h2>Stock actual por carril</h2></div>
                <div class="buscador-carriles">
                    <input type="text" id="buscar-carril-${u.id_maquina}" placeholder="Buscar por medicamento, código de barras o lote…">
                    <select id="filtro-carril-${u.id_maquina}">
                        <option value="">Todas las categorías</option>
                        <option value="vencidos">Vencidos</option>
                        <option value="por_vencer">Por vencer (30d)</option>
                        <option value="vacios">Vacíos</option>
                        <option value="ok">OK</option>
                    </select>
                    <span class="contador-mini" id="contador-carril-${u.id_maquina}"></span>
                </div>
                <div class="card-cuerpo" id="carriles-${u.id_maquina}"><p class="cargando">Cargando…</p></div>
            </div>

            ${seccionMantenimiento}
        `;

        if (window.mlRenderIcons) window.mlRenderIcons();

        cont.querySelector('.form-stock')?.addEventListener('submit', manejarCargaStock);

        const inputBuscar = document.getElementById(`buscar-carril-${u.id_maquina}`);
        const selectFiltro = document.getElementById(`filtro-carril-${u.id_maquina}`);
        let debounceBusqueda = null;
        inputBuscar.addEventListener('input', () => {
            clearTimeout(debounceBusqueda);
            debounceBusqueda = setTimeout(() => {
                filtroCarriles.texto = inputBuscar.value.trim().toLowerCase();
                renderTablaCarriles(u.id_maquina);
            }, 250);
        });
        selectFiltro.addEventListener('change', () => {
            filtroCarriles.categoria = selectFiltro.value;
            renderTablaCarriles(u.id_maquina);
        });

        document.getElementById('btn-registrar-mant')?.addEventListener('click', () => abrirModalMantenimiento(u.id_maquina));

        cargarCarrilesYGrafica(u.id_maquina);
        if (!USUARIO_SOLO_MAQUINAS_DASHBOARD) {
            cargarMantenimientos(u.id_maquina);
        }
    }

    // ------------------------------------------------------------------
    // Carriles: carga, gráfica, listas rápidas y tabla filtrable
    // ------------------------------------------------------------------
    function categoriaDeFila(f) {
        if (!f.cantidad_actual || f.cantidad_actual <= 0) return 'vacios';
        const d = diasParaCaducar(f.fecha_caducidad);
        if (d === null) return 'ok';
        if (d < 0) return 'vencidos';
        if (d <= 30) return 'por_vencer';
        return 'ok';
    }

    async function cargarCarrilesYGrafica(idMaquina) {
        const contCarriles = document.getElementById(`carriles-${idMaquina}`);
        const contGrafica = document.getElementById(`grafica-${idMaquina}`);
        if (!contCarriles || !contGrafica) return;

        try {
            const json = await obtenerJSON(`api/detalle_maquina.php?id_maquina=${idMaquina}`);
            filasCarrilesCache[idMaquina] = json.data;

            if (json.data.length === 0) {
                contCarriles.innerHTML = '<p class="vacio">Esta máquina no tiene carriles configurados.</p>';
                contGrafica.innerHTML = '<p class="vacio">Sin carriles para graficar.</p>';
                return;
            }

            renderGrafica(contGrafica, json.data, idMaquina);
            renderTablaCarriles(idMaquina);
        } catch (e) {
            contCarriles.innerHTML = '<p class="vacio">No se pudo conectar con el servidor.</p>';
            contGrafica.innerHTML = '<p class="vacio">No se pudo conectar con el servidor.</p>';
        }
    }

    function renderTablaCarriles(idMaquina) {
        const contCarriles = document.getElementById(`carriles-${idMaquina}`);
        const contador = document.getElementById(`contador-carril-${idMaquina}`);
        if (!contCarriles) return;

        let filas = filasCarrilesCache[idMaquina] || [];

        if (filtroCarriles.categoria) {
            filas = filas.filter(f => categoriaDeFila(f) === filtroCarriles.categoria);
        }
        if (filtroCarriles.texto) {
            const t = filtroCarriles.texto;
            filas = filas.filter(f =>
                (f.nombre || '').toLowerCase().includes(t) ||
                (f.codigo_barras || '').toLowerCase().includes(t) ||
                (f.numero_lote || '').toLowerCase().includes(t)
            );
        }

        if (contador) contador.textContent = `${filas.length} carril${filas.length === 1 ? '' : 'es'}`;

        if (filas.length === 0) {
            contCarriles.innerHTML = '<p class="vacio">No hay carriles que coincidan con la búsqueda.</p>';
            return;
        }

        contCarriles.innerHTML = `<table>
            <thead><tr><th>Carril</th><th>Producto</th><th>Código</th><th>Lote</th><th>Caduca</th>${USUARIO_SOLO_MAQUINAS_DASHBOARD ? '' : '<th>Cantidad</th>'}</tr></thead>
            <tbody>${filas.map(f => {
                const cat = categoriaDeFila(f);
                const claseFila = cat === 'vencidos' ? 'fila-vencida' : (cat === 'por_vencer' ? 'fila-por-vencer' : '');
                return `<tr class="${claseFila}">
                    <td>${f.numero_carril}</td>
                    <td>${escapeHtml(f.nombre) || '—'}</td>
                    <td class="mono">${escapeHtml(f.codigo_barras) || '—'}</td>
                    <td class="mono">${escapeHtml(f.numero_lote) || '—'}</td>
                    <td class="mono">${f.fecha_caducidad ?? '—'}</td>
                    ${USUARIO_SOLO_MAQUINAS_DASHBOARD ? '' : `<td>${f.cantidad_actual ?? 0}</td>`}
                </tr>`;
            }).join('')}</tbody>
        </table>`;
    }

    function columnaAlerta(titulo, colorVar, filas, idMaquina, categoria) {
        const LIMITE = 6;
        const items = filas.slice(0, LIMITE).map(f => {
            const d = diasParaCaducar(f.fecha_caducidad);
            const etiquetaDias = d === null ? '—' : (d < 0 ? `venció hace ${Math.abs(d)}d` : `en ${d}d`);
            return `<li><span class="nombre">${escapeHtml(f.nombre) || 'Sin nombre'} <span class="mono" style="color:var(--texto-tenue);">· C${f.numero_carril}</span></span><span class="dias">${etiquetaDias}</span></li>`;
        }).join('');

        const cuerpo = filas.length
            ? `<ul>${items}</ul>${filas.length > LIMITE ? `<div class="ver-mas" data-cat="${categoria}">Ver los ${filas.length} →</div>` : ''}`
            : `<div class="vacio-mini">Nada en esta categoría. Todo en orden. ✓</div>`;

        return `
            <div class="alerta-col">
                <div class="titulo" style="color:${colorVar};"><span class="punto" style="background:${colorVar}"></span>${titulo} (${filas.length})</div>
                ${cuerpo}
            </div>
        `;
    }

    function renderGrafica(cont, filas, idMaquina) {
        let vacios = 0, vencidos = 0, porVencer = 0, ok = 0;
        const filasVencidas = [], filasPorVencer = [];
        for (const f of filas) {
            const cat = categoriaDeFila(f);
            if (cat === 'vacios') { vacios++; continue; }
            if (cat === 'vencidos') { vencidos++; filasVencidas.push(f); continue; }
            if (cat === 'por_vencer') { porVencer++; filasPorVencer.push(f); continue; }
            ok++;
        }
        // Ordenamos por urgencia: más vencido / más próximo primero.
        filasVencidas.sort((a, b) => diasParaCaducar(a.fecha_caducidad) - diasParaCaducar(b.fecha_caducidad));
        filasPorVencer.sort((a, b) => diasParaCaducar(a.fecha_caducidad) - diasParaCaducar(b.fecha_caducidad));

        const totalCarriles = filas.length;
        const segmentos = [
            { etiqueta: 'Vencidos', valor: vencidos, color: 'var(--critico)', clave: 'vencidos' },
            { etiqueta: 'Por vencer (30d)', valor: porVencer, color: 'var(--ambar)', clave: 'por_vencer' },
            { etiqueta: 'Vacíos', valor: vacios, color: 'var(--texto-tenue)', clave: 'vacios' },
            { etiqueta: 'OK', valor: ok, color: 'var(--petroleo)', clave: 'ok' },
        ];

        const carrilesConStock = [...filas]
            .map(f => ({ carril: f.numero_carril, cantidad: Math.max(0, Number(f.cantidad_actual) || 0) }))
            .sort((a, b) => b.cantidad - a.cantidad)
            .slice(0, 10);
        const maxCarril = Math.max(1, ...carrilesConStock.map(f => f.cantidad));

        const stockPorMedicamento = new Map();
        filas.forEach(f => {
            const nombre = String(f.nombre || 'Sin medicamento');
            stockPorMedicamento.set(nombre, (stockPorMedicamento.get(nombre) || 0) + Math.max(0, Number(f.cantidad_actual) || 0));
        });
        const medicamentosTop = [...stockPorMedicamento.entries()]
            .map(([nombre, cantidad]) => ({ nombre, cantidad }))
            .sort((a, b) => b.cantidad - a.cantidad)
            .slice(0, 5);
        const maxMedicamento = Math.max(1, ...medicamentosTop.map(m => m.cantidad));

        cont.innerHTML = `
            <div class="resumen-carriles">
                <div class="stats-carriles">
                    ${segmentos.map(s => `
                        <button type="button" class="stat-carril stat-clic" data-cat="${s.clave}" style="--stat-color:${s.color}">
                            <div class="lbl"><span class="punto"></span>${s.etiqueta}</div>
                            <div class="num">${s.valor}</div>
                        </button>
                    `).join('')}
                </div>

                <div class="fila-detalle-carriles">
                    <div class="donut-envoltura">
                        <div class="donut" id="donut-carriles">
                            <div class="centro"><div class="n">${totalCarriles}</div><div class="l">carriles</div></div>
                        </div>
                    </div>

                    <div class="panel-alertas">
                        ${columnaAlerta('Vencidos', 'var(--critico)', filasVencidas, idMaquina, 'vencidos')}
                        ${columnaAlerta('Por vencer (30d)', 'var(--ambar)', filasPorVencer, idMaquina, 'por_vencer')}
                    </div>
                </div>

                <div class="analitica-inventario">
                    <section class="grafica-inventario">
                        <div class="grafica-inventario__head"><h3>Existencias por carril</h3><span>10 con mayor carga</span></div>
                        <div class="barras-existencia">
                            ${carrilesConStock.map((f, indice) => `
                                <div class="barra-existencia" title="Carril ${escapeHtml(String(f.carril))}: ${f.cantidad} piezas">
                                    <div class="barra-existencia__pista"><span class="barra-existencia__valor" style="height:${Math.max(3, (f.cantidad / maxCarril) * 100)}%;animation-delay:${indice * 45}ms"></span></div>
                                    <span class="barra-existencia__label">C${escapeHtml(String(f.carril))}</span>
                                </div>
                            `).join('')}
                        </div>
                    </section>
                    <section class="grafica-inventario">
                        <div class="grafica-inventario__head"><h3>Concentracion de inventario</h3><span>Por medicamento</span></div>
                        <div class="ranking-stock">
                            ${medicamentosTop.map(m => `
                                <div class="ranking-stock__fila">
                                    <span class="ranking-stock__nombre" title="${escapeHtml(m.nombre)}">${escapeHtml(m.nombre)}</span>
                                    <b class="ranking-stock__cantidad">${m.cantidad} pzas.</b>
                                    <div class="ranking-stock__pista"><span class="ranking-stock__barra" style="width:${(m.cantidad / maxMedicamento) * 100}%"></span></div>
                                </div>
                            `).join('')}
                        </div>
                    </section>
                </div>
            </div>
        `;

        // Clic en un KPI filtra la tabla de abajo por esa categoría (toggle).
        function aplicarFiltroCategoria(cat) {
            filtroCarriles.categoria = filtroCarriles.categoria === cat ? '' : cat;
            const select = document.getElementById(`filtro-carril-${idMaquina}`);
            if (select) select.value = filtroCarriles.categoria;
            cont.querySelectorAll('.stat-clic').forEach(el => el.classList.toggle('activo', el.dataset.cat === filtroCarriles.categoria));
            renderTablaCarriles(idMaquina);
            document.getElementById(`carriles-${idMaquina}`).scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
        cont.querySelectorAll('.stat-clic').forEach(el => {
            el.addEventListener('click', () => aplicarFiltroCategoria(el.dataset.cat));
        });
        cont.querySelectorAll('.ver-mas').forEach(el => {
            el.addEventListener('click', () => aplicarFiltroCategoria(el.dataset.cat));
        });

        // Animación: arranca el donut en 0 y lo anima al valor real en el
        // siguiente frame, para que el conic-gradient se transicione.
        const donut = document.getElementById('donut-carriles');
        donut.style.background = `conic-gradient(var(--linea) 0deg 360deg)`;

        requestAnimationFrame(() => {
            requestAnimationFrame(() => {
                let acumulado = 0;
                const partesConic = segmentos.filter(s => s.valor > 0).map(s => {
                    const inicio = (acumulado / totalCarriles) * 360;
                    acumulado += s.valor;
                    const fin = (acumulado / totalCarriles) * 360;
                    return `${s.color} ${inicio}deg ${fin}deg`;
                }).join(', ');
                donut.style.background = `conic-gradient(${partesConic || 'var(--linea) 0deg 360deg'})`;
            });
        });
    }

    async function manejarCargaStock(ev) {
        ev.preventDefault();
        const form = ev.target;
        const idMaquina = form.dataset.id;
        const archivo = form.querySelector('.archivo-excel').files[0];
        const usuario = form.querySelector('.usuario-carga').value;
        const resultadoDiv = form.querySelector('.resultado-carga');
        const boton = form.querySelector('button');
        if (!archivo) return;

        const formData = new FormData();
        formData.append('excel', archivo);
        formData.append('id_maquina', idMaquina);
        formData.append('usuario', usuario);

        boton.disabled = true;
        boton.textContent = 'Cargando…';
        resultadoDiv.innerHTML = '';

        try {
            const res = await fetch('api/subir_stock.php', { method: 'POST', body: formData });
            const json = await res.json();
            if (!json.ok) {
                resultadoDiv.innerHTML = `<p class="msg-error">Error: ${escapeHtml(json.error)}</p>`;
            } else {
                let detalle = `<p class="msg-ok">Carga completada. ${json.data.filas_leidas} filas leídas.</p>`;
                if (json.data.sin_carril.length > 0) {
                    detalle += `<p class="msg-error">${json.data.sin_carril.length} lote(s) sin carril configurado en esta máquina.</p>`;
                }
                if (json.data.sin_fecha_caducidad.length > 0) {
                    detalle += `<p class="msg-error">${json.data.sin_fecha_caducidad.length} fila(s) sin fecha de caducidad.</p>`;
                }
                if ((json.data.sin_traspaso || []).length > 0) {
                    detalle += `<p class="msg-error">${json.data.sin_traspaso.length} fila(s) sin traspaso completo desde la sucursal.</p>`;
                }
                resultadoDiv.innerHTML = detalle;
                cargarCarrilesYGrafica(parseInt(idMaquina, 10));
            }
        } catch (e) {
            resultadoDiv.innerHTML = '<p class="msg-error">No se pudo conectar con el servidor.</p>';
        } finally {
            boton.disabled = false;
            boton.textContent = 'Cargar stock';
        }
    }

    // ------------------------------------------------------------------
    // Mantenimiento: listado + modal registrar/editar
    // ------------------------------------------------------------------
    function badgeEstatusMant(nombre) {
        const n = (nombre || '').toLowerCase();
        if (n.includes('complet') || n.includes('realiz')) return `<span class="badge badge-ok">${escapeHtml(nombre)}</span>`;
        if (n.includes('cancel')) return `<span class="badge badge-neutro">${escapeHtml(nombre)}</span>`;
        if (n.includes('proceso') || n.includes('program')) return `<span class="badge badge-warn">${escapeHtml(nombre)}</span>`;
        return `<span class="badge badge-neutro">${escapeHtml(nombre || '—')}</span>`;
    }

    async function cargarMantenimientos(idMaquina) {
        const cont = document.getElementById(`mant-${idMaquina}`);
        if (!cont) return;
        try {
            const json = await obtenerJSON(`api/mantenimientos.php?id_maquina=${idMaquina}`);
            const filas = json.data;
            if (filas.length === 0) {
                cont.innerHTML = '<p class="vacio">Sin mantenimientos registrados para esta unidad.</p>';
                return;
            }
            cont.innerHTML = `<table>
                <thead><tr><th>Programado</th><th>Realizado</th><th>Tipo</th><th>Técnico</th><th>Estatus</th><th>Descripción</th><th></th></tr></thead>
                <tbody>${filas.map(f => `<tr>
                    <td class="mono">${f.fecha_programada ?? '—'}</td>
                    <td class="mono">${f.fecha_realizado ?? '—'}</td>
                    <td>${escapeHtml(f.tipo_nombre) || `<span class="mono" style="color:var(--texto-tenue);">#${f.tipo_mantenimiento}</span>`}</td>
                    <td>${escapeHtml(f.tecnico) || '—'}</td>
                    <td>${badgeEstatusMant(f.estatus_nombre)}</td>
                    <td>${escapeHtml(f.descripcion) || '—'}</td>
                    <td><button type="button" class="btn-editar-mant" data-json='${JSON.stringify(f).replace(/'/g, "&#39;")}' style="border:1px solid var(--linea);background:var(--bg);color:var(--texto-suave);border-radius:6px;width:26px;height:26px;cursor:pointer;">✎</button></td>
                </tr>`).join('')}</tbody>
            </table>`;

            cont.querySelectorAll('.btn-editar-mant').forEach(btn => {
                btn.addEventListener('click', () => {
                    const datos = JSON.parse(btn.dataset.json);
                    abrirModalMantenimiento(idMaquina, datos);
                });
            });
        } catch (e) {
            cont.innerHTML = '<p class="vacio">No se pudo conectar con el servidor.</p>';
        }
    }

    const overlayMant = document.getElementById('overlay-mant');
    const formMant = document.getElementById('form-mant');
    const mantMensaje = document.getElementById('mant-mensaje');
    const selectEstatusMant = document.getElementById('fm-id_estatus');

    async function poblarCatalogoEstatusMant() {
        if (catalogoEstatusMant) return catalogoEstatusMant;
        try {
            const json = await obtenerJSON('api/estatus_maquina.php');
            catalogoEstatusMant = json.data;
        } catch (e) {
            catalogoEstatusMant = [];
        }
        return catalogoEstatusMant;
    }

    async function poblarCatalogoTiposMant() {
        if (catalogoTiposMant) return catalogoTiposMant;
        try {
            const json = await obtenerJSON('api/tipos_mantenimiento.php');
            catalogoTiposMant = json.data;
        } catch (e) {
            catalogoTiposMant = [];
        }
        return catalogoTiposMant;
    }

    async function abrirModalMantenimiento(idMaquina, datosExistentes) {
        formMant.reset();
        mantMensaje.textContent = '';
        mantMensaje.className = 'modal-mensaje';
        document.getElementById('fm-id_maquina').value = idMaquina;
        document.getElementById('fm-id_mantenimiento').value = '';

        const [catalogoEstatus, catalogoTipos] = await Promise.all([
            poblarCatalogoEstatusMant(),
            poblarCatalogoTiposMant(),
        ]);

        selectEstatusMant.innerHTML = catalogoEstatus.length
            ? catalogoEstatus.map(e => `<option value="${e.id_estatus}">${escapeHtml(e.nombre)}</option>`).join('')
            : '<option value="">Sin catálogo de estatus disponible</option>';

        const selectTipoMant = document.getElementById('fm-tipo_mantenimiento');
        selectTipoMant.innerHTML = catalogoTipos.length
            ? catalogoTipos.map(t => `<option value="${t.id_tipo_mantenimiento}">${escapeHtml(t.nombre)}</option>`).join('')
            : '<option value="">Sin catálogo de tipos disponible</option>';

        document.getElementById('mant-titulo').textContent = datosExistentes ? 'Editar mantenimiento' : 'Registrar mantenimiento';

        if (datosExistentes) {
            document.getElementById('fm-id_mantenimiento').value = datosExistentes.id_mantenimiento;
            document.getElementById('fm-descripcion').value = datosExistentes.descripcion || '';
            document.getElementById('fm-tecnico').value = datosExistentes.tecnico || '';
            document.getElementById('fm-fecha_programada').value = datosExistentes.fecha_programada ? String(datosExistentes.fecha_programada).substring(0, 10) : '';
            document.getElementById('fm-fecha_realizado').value = datosExistentes.fecha_realizado ? String(datosExistentes.fecha_realizado).substring(0, 10) : '';
            if (datosExistentes.id_estatus) selectEstatusMant.value = String(datosExistentes.id_estatus);
            if (datosExistentes.tipo_mantenimiento) selectTipoMant.value = String(datosExistentes.tipo_mantenimiento);
        }

        overlayMant.classList.add('abierto');
    }

    function cerrarModalMantenimiento() { overlayMant.classList.remove('abierto'); }

    document.getElementById('mant-cerrar').addEventListener('click', cerrarModalMantenimiento);
    document.getElementById('mant-cancelar').addEventListener('click', cerrarModalMantenimiento);
    overlayMant.addEventListener('click', (ev) => { if (ev.target === overlayMant) cerrarModalMantenimiento(); });

    formMant.addEventListener('submit', async (ev) => {
        ev.preventDefault();
        const btnGuardar = document.getElementById('mant-guardar');
        btnGuardar.disabled = true;
        mantMensaje.textContent = '';
        mantMensaje.className = 'modal-mensaje';

        const idMantenimiento = document.getElementById('fm-id_mantenimiento').value;
        const idMaquina = parseInt(document.getElementById('fm-id_maquina').value, 10);

        const payload = {
            id_mantenimiento: idMantenimiento ? parseInt(idMantenimiento, 10) : null,
            id_maquina: idMaquina,
            tipo_mantenimiento: parseInt(document.getElementById('fm-tipo_mantenimiento').value, 10) || null,
            descripcion: document.getElementById('fm-descripcion').value.trim(),
            tecnico: document.getElementById('fm-tecnico').value.trim(),
            id_estatus: parseInt(selectEstatusMant.value, 10) || null,
            fecha_programada: document.getElementById('fm-fecha_programada').value || null,
            fecha_realizado: document.getElementById('fm-fecha_realizado').value || null,
        };

        try {
            const res = await fetch('api/mantenimientos.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            });
            const json = await res.json();
            if (!json.ok) {
                mantMensaje.textContent = json.error || 'No se pudo guardar.';
                mantMensaje.className = 'modal-mensaje error';
                return;
            }
            mantMensaje.textContent = `Mantenimiento ${json.data.accion}.`;
            mantMensaje.className = 'modal-mensaje ok';
            await cargarMantenimientos(idMaquina);
            setTimeout(cerrarModalMantenimiento, 700);
        } catch (e) {
            mantMensaje.textContent = 'No se pudo conectar con el servidor.';
            mantMensaje.className = 'modal-mensaje error';
        } finally {
            btnGuardar.disabled = false;
        }
    });
</script>
</body>
</html>
