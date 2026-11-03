<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/bootstrap.php';

use App\Auth\Auth;

Auth::requireLogin();

$googleMapsApiKey = trim((string) (
    $_ENV['GOOGLE_MAPS_BROWSER_API_KEY']
    ?? getenv('GOOGLE_MAPS_BROWSER_API_KEY')
    ?: ($_ENV['GOOGLE_MAPS_API_KEY'] ?? getenv('GOOGLE_MAPS_API_KEY') ?: '')
));
$mapaOrigen = [
    'nombre' => (string) ($_ENV['DISTRIBUCION_ORIGEN_NOMBRE'] ?? getenv('DISTRIBUCION_ORIGEN_NOMBRE') ?: ''),
    'direccion' => (string) ($_ENV['DISTRIBUCION_ORIGEN_DIRECCION'] ?? getenv('DISTRIBUCION_ORIGEN_DIRECCION') ?: ''),
    'lat' => ($_ENV['DISTRIBUCION_ORIGEN_LAT'] ?? getenv('DISTRIBUCION_ORIGEN_LAT') ?: null),
    'lng' => ($_ENV['DISTRIBUCION_ORIGEN_LNG'] ?? getenv('DISTRIBUCION_ORIGEN_LNG') ?: null),
];
$rendimientoGasolinaDefault = (float) ($_ENV['DISTRIBUCION_RENDIMIENTO_KM_L'] ?? getenv('DISTRIBUCION_RENDIMIENTO_KM_L') ?: 10);
$precioGasolinaDefault = (float) ($_ENV['DISTRIBUCION_GASOLINA_PRECIO_LITRO_MXN'] ?? getenv('DISTRIBUCION_GASOLINA_PRECIO_LITRO_MXN') ?: 0);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>MDM - Distribucion</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/estilo.css?v=20261002-7">
    <style>
        body.tema-oscuro {
            --bg: #0B1117;
            --bg-elevada: #131C25;
            --bg-hover: #1D2A35;
            --linea: #263541;
            --linea-suave: #1F2B35;
            --texto: #EEF4F7;
            --texto-suave: #A8B7C1;
            --texto-tenue: #71818D;
            --petroleo: #2CB7A5;
            --petroleo-wash: rgba(44, 183, 165, .14);
            --azul: #5B8DEF;
            --azul-wash: rgba(91, 141, 239, .14);
            --ambar: #D6A34A;
            --ambar-wash: rgba(214, 163, 74, .15);
            --critico: #E06C78;
            --critico-wash: rgba(224, 108, 120, .15);
            background: var(--bg);
            color: var(--texto);
        }
        .encabezado-pagina {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 16px;
            flex-wrap: wrap;
        }
        .etiqueta-modulo {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--petroleo);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
            margin-bottom: 9px;
        }
        .etiqueta-modulo::before {
            content: "";
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: var(--petroleo);
        }
        .acciones-encabezado { display: flex; gap: 10px; flex-wrap: wrap; }
        .btn {
            border: 1px solid var(--linea);
            background: var(--bg-elevada);
            color: var(--texto);
            border-radius: 8px;
            padding: 9px 14px;
            font-family: inherit;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
        }
        .btn:hover { border-color: var(--petroleo); color: var(--petroleo); }
        .btn.primario { background: var(--petroleo); color: #05201D; border-color: var(--petroleo); }
        .btn.primario:hover { color: #05201D; background: #41D8C4; }
        .btn:disabled { opacity: .5; cursor: default; }
        .grid-kpis {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 14px;
            margin-bottom: 18px;
        }
        .kpi-dist {
            background: var(--bg-elevada);
            border: 1px solid var(--linea);
            border-radius: 10px;
            padding: 16px;
            position: relative;
            overflow: hidden;
        }
        .kpi-dist::before {
            content: "";
            position: absolute;
            inset: 0 0 auto;
            height: 2px;
            background: linear-gradient(90deg, var(--kpi-color, var(--petroleo)), transparent);
        }
        .kpi-dist .lbl {
            color: var(--texto-tenue);
            font-size: 11px;
            text-transform: uppercase;
            font-weight: 700;
            margin-bottom: 8px;
        }
        .kpi-dist .num {
            font-family: 'Space Grotesk', sans-serif;
            color: var(--kpi-color, var(--texto));
            font-size: 30px;
            font-weight: 700;
            line-height: 1;
        }
        .layout-distribucion {
            display: grid;
            grid-template-columns: 1fr;
            gap: 18px;
            align-items: start;
        }
        .panel {
            background: var(--bg-elevada);
            border: 1px solid var(--linea);
            border-radius: 10px;
            overflow: hidden;
        }
        .panel-cabecera {
            padding: 16px 18px;
            border-bottom: 1px solid var(--linea-suave);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }
        .panel-cabecera h2 { margin: 0; font-size: 15px; }
        .panel-cuerpo { padding: 18px; }
        .overlay-modal {
            position: fixed;
            inset: 0;
            display: none;
            align-items: flex-start;
            justify-content: center;
            overflow-y: auto;
            padding: 34px 16px;
            background: rgba(20, 33, 43, .32);
            backdrop-filter: blur(2px);
            z-index: 60;
        }
        .overlay-modal.abierto { display: flex; }
        .modal {
            width: min(820px, 100%);
            background: var(--bg-elevada);
            border: 1px solid var(--linea);
            border-radius: 10px;
            box-shadow: 0 24px 70px rgba(20, 33, 43, .22);
        }
        .modal-cabecera {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            padding: 16px 18px;
            border-bottom: 1px solid var(--linea-suave);
        }
        .modal-cabecera h2 { margin: 0; font-size: 15px; }
        .modal-cuerpo { padding: 18px; }
        .modal-cerrar {
            border: 1px solid var(--linea);
            background: var(--bg);
            color: var(--texto-suave);
            border-radius: 8px;
            width: 32px;
            height: 32px;
            cursor: pointer;
            font-size: 18px;
            line-height: 1;
        }
        .modal-cerrar:hover { color: var(--texto); border-color: var(--petroleo); }
        .form-grid { display: grid; gap: 12px; }
        .form-row label {
            display: block;
            color: var(--texto-suave);
            font-size: 12px;
            font-weight: 700;
            margin-bottom: 6px;
        }
        .form-row input,
        .form-row select,
        .form-row textarea {
            width: 100%;
            border: 1px solid var(--linea);
            border-radius: 8px;
            background: var(--bg);
            color: var(--texto);
            padding: 9px 11px;
            font: inherit;
            font-size: 13px;
        }
        .form-row textarea { min-height: 66px; resize: vertical; }
        .form-row input:focus,
        .form-row select:focus,
        .form-row textarea:focus {
            outline: none;
            border-color: var(--petroleo);
            box-shadow: 0 0 0 3px var(--petroleo-wash);
        }
        .dos-cols { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        .ruta-preview-form {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 280px;
            gap: 14px;
            border-top: 1px solid var(--linea-suave);
            border-bottom: 1px solid var(--linea-suave);
            padding: 14px 0;
        }
        .ruta-preview-form[hidden] { display: none; }
        .ruta-preview-titulo { margin: 0 0 10px; font-size: 13px; }
        .ruta-preview-metricas { display: grid; grid-template-columns: 1fr 1fr; gap: 10px 14px; }
        .ruta-preview-metrica span { display: block; color: var(--texto-suave); font-size: 11px; }
        .ruta-preview-metrica strong { display: block; margin-top: 2px; font-size: 15px; }
        .ruta-preview-aviso { margin: 11px 0 0; color: var(--texto-suave); font-size: 11px; line-height: 1.45; }
        #mapa-ruta-form { width: 100%; min-height: 190px; border: 1px solid var(--linea); border-radius: 8px; }
        .combustible-form { padding: 2px 0 10px; }
        .combustible-form h3 { margin: 0 0 4px; font-size: 13px; }
        .combustible-form > p { margin: 0 0 10px; color: var(--texto-suave); font-size: 11px; }
        .combustible-grid { display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 10px; align-items: end; }
        .combustible-resultado { min-height: 39px; padding: 8px 10px; border-left: 3px solid var(--petroleo); background: var(--bg); }
        .combustible-resultado span { display: block; color: var(--texto-suave); font-size: 10px; }
        .combustible-resultado strong { font-size: 14px; }
        .toolbar {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
            padding: 14px 18px;
            border-bottom: 1px solid var(--linea-suave);
        }
        .toolbar input,
        .toolbar select {
            border: 1px solid var(--linea);
            border-radius: 8px;
            background: var(--bg);
            color: var(--texto);
            padding: 9px 11px;
            font: inherit;
            font-size: 13px;
        }
        .toolbar input { flex: 1; min-width: 220px; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th, td { border-bottom: 1px solid var(--linea-suave); padding: 11px 14px; text-align: left; }
        th { color: var(--texto-tenue); text-transform: uppercase; font-size: 10px; letter-spacing: .06em; }
        td { color: var(--texto-suave); vertical-align: top; }
        tbody tr:hover { background: rgba(255,255,255,.025); }
        .mono { font-family: 'JetBrains Mono', monospace; }
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            border-radius: 999px;
            padding: 3px 9px;
            font-size: 11px;
            font-weight: 700;
            white-space: nowrap;
        }
        .badge::before { content: ""; width: 6px; height: 6px; border-radius: 50%; background: currentColor; }
        .badge-ok { background: var(--petroleo-wash); color: var(--petroleo); }
        .badge-warn { background: var(--ambar-wash); color: var(--ambar); }
        .badge-danger { background: var(--critico-wash); color: var(--critico); }
        .badge-info { background: var(--azul-wash); color: var(--azul); }
        .badge-neutro { background: var(--bg); color: var(--texto-tenue); border: 1px solid var(--linea); }
        .mini { color: var(--texto-tenue); font-size: 12px; margin-top: 3px; }
        .acciones-tabla { display: flex; flex-direction: column; gap: 6px; }
        .acciones-tabla button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            border: 1px solid var(--linea);
            background: var(--bg);
            color: var(--texto-suave);
            border-radius: 7px;
            padding: 6px 8px;
            font: inherit;
            font-size: 12px;
            cursor: pointer;
            white-space: nowrap;
        }
        .acciones-tabla button:hover { color: var(--texto); border-color: var(--petroleo); }
        .vacio, .cargando { color: var(--texto-tenue); font-style: italic; padding: 32px 16px; text-align: center; }
        .msg { font-size: 12.5px; min-height: 18px; color: var(--texto-tenue); }
        .msg.ok { color: var(--petroleo); }
        .msg.error { color: var(--critico); }
        .ruta-lista { display: flex; flex-direction: column; gap: 8px; }
        .ruta-resumen {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
            margin-bottom: 14px;
        }
        .acciones-tabla button svg { width: 14px; height: 14px; }
        .acciones-tabla button.accion-principal { background: var(--petroleo); border-color: var(--petroleo); color: #FFFFFF; }
        .acciones-tabla button.accion-principal:hover { background: var(--petroleo-fuerte, #075F5A); color: #FFFFFF; }
        .ruta-metrica {
            border: 1px solid var(--linea);
            border-radius: 8px;
            padding: 12px;
            background: #FFFFFF;
        }
        .ruta-metrica .lbl {
            color: var(--texto-tenue);
            font-size: 10.5px;
            font-weight: 800;
            text-transform: uppercase;
            margin-bottom: 6px;
        }
        .ruta-metrica .num {
            color: var(--texto);
            font-family: 'Space Grotesk', sans-serif;
            font-size: 19px;
            font-weight: 800;
        }
        .mapa-distribucion {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 390px;
            gap: 0;
            min-height: 520px;
        }
        #mapa-distribucion {
            min-height: 520px;
            background: #E8EEF3;
        }
        .mapa-lateral {
            border-left: 1px solid var(--linea-suave);
            padding: 16px;
            display: flex;
            flex-direction: column;
            gap: 12px;
            background: #FBFCFD;
        }
        .ruta-lateral-cabecera { display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; }
        .ruta-lateral-cabecera .mini { text-align: right; }
        .ruta-panel-lateral { flex: 1; min-height: 0; overflow-y: auto; padding-right: 3px; }
        .ruta-panel-lateral .cargando, .ruta-panel-lateral .vacio { padding: 22px 8px; }
        .mapa-contexto { margin-top: auto; padding-top: 12px; border-top: 1px solid var(--linea-suave); }
        .mapa-contexto .mapa-estado { margin: 8px 0 10px; }
        .mapa-estado {
            border: 1px solid var(--linea);
            border-radius: 8px;
            padding: 12px;
            color: var(--texto-suave);
            font-size: 12px;
            line-height: 1.45;
            background: #FFFFFF;
        }
        .mapa-leyenda {
            display: grid;
            gap: 8px;
            color: var(--texto-suave);
            font-size: 12px;
        }
        .mapa-leyenda span {
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .mapa-leyenda i {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            display: inline-block;
            background: var(--petroleo);
        }
        .mapa-leyenda .rojo { background: var(--critico); }
        .mapa-leyenda .ambar { background: var(--ambar); }
        .mapa-leyenda .azul { background: var(--azul); }
        .ruta-item {
            display: grid;
            grid-template-columns: 28px 1fr auto;
            gap: 10px;
            align-items: center;
            background: var(--bg);
            border: 1px solid var(--linea-suave);
            border-radius: 8px;
            padding: 10px;
        }
        .ruta-num {
            width: 28px;
            height: 28px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--petroleo-wash);
            color: var(--petroleo);
            font-weight: 800;
        }
        @media (max-width: 1100px) {
            .layout-distribucion { grid-template-columns: 1fr; }
            .grid-kpis, .ruta-resumen { grid-template-columns: repeat(2, 1fr); }
            .mapa-distribucion { grid-template-columns: 1fr; }
            .mapa-lateral { border-left: 0; border-top: 1px solid var(--linea-suave); }
        }
        .ruta-panel-lateral .ruta-item { grid-template-columns: 26px minmax(0, 1fr); padding: 9px; }
        .ruta-panel-lateral .ruta-item > .mono { grid-column: 2; }
        @media (max-width: 680px) {
            .grid-kpis, .dos-cols { grid-template-columns: 1fr; }
            .ruta-preview-form { grid-template-columns: 1fr; }
            #mapa-ruta-form { min-height: 180px; }
            .combustible-grid { grid-template-columns: 1fr 1fr; }
            .ruta-resumen { grid-template-columns: 1fr; }
            .principal { padding: 22px 16px 48px; }
        }

        /* Legibilidad: tema claro real para esta vista */
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
            --azul: #2563B8;
            --azul-wash: rgba(37, 99, 184, .10);
            --ambar: #9A620B;
            --ambar-wash: rgba(154, 98, 11, .12);
            --critico: #B42335;
            --critico-wash: rgba(180, 35, 53, .10);
            background: var(--bg);
            color: var(--texto);
        }
        body.tema-oscuro .sidebar {
            background: #FFFFFF;
            border-right-color: var(--linea);
            box-shadow: 12px 0 30px rgba(20,33,43,.06);
        }
        h1, h2, h3, .panel-cabecera h2, .modal-cabecera h2 { color: var(--texto); }
        p, td, .mini { color: var(--texto-suave); }
        .etiqueta-modulo, .kpi-dist .num { color: var(--petroleo); }
        .panel, .kpi-dist, .modal, .ruta-item {
            background: #FFFFFF;
            border-color: var(--linea);
            box-shadow: 0 12px 28px rgba(20,33,43,.08);
        }
        .panel-cabecera, .modal-cabecera, .toolbar, th, td {
            border-color: var(--linea-suave);
        }
        th {
            background: #F8FAFC;
            color: var(--texto-tenue);
        }
        tbody tr:hover { background: var(--bg-hover); }
        .toolbar input, .toolbar select,
        .form-row input, .form-row select, .form-row textarea {
            background: #FFFFFF;
            border-color: #C8D3DC;
            color: var(--texto);
        }
        .btn {
            background: #FFFFFF;
            color: var(--texto);
            border-color: #C8D3DC;
            box-shadow: 0 2px 6px rgba(20,33,43,.06);
        }
        .btn:hover {
            background: #F2F7F6;
            color: var(--petroleo);
            border-color: var(--petroleo);
        }
        .btn.primario {
            background: #0B8F86;
            border-color: #0B8F86;
            color: #FFFFFF;
            box-shadow: 0 8px 18px rgba(8,125,118,.22);
        }
        .btn.primario:hover {
            background: #087D76;
            color: #FFFFFF;
        }
        .acciones-tabla button, .modal-cerrar {
            background: #FFFFFF;
            color: var(--texto);
            border-color: #C8D3DC;
        }
        .overlay-modal { background: rgba(20,33,43,.24); }

        /* Acabado operativo de Distribucion */
        body.tema-oscuro .principal {
            background: #F4F6F8;
            padding: 30px 34px 52px;
        }
        .encabezado-pagina {
            align-items: center;
            padding-bottom: 22px;
            margin-bottom: 18px;
            border-bottom: 1px solid var(--linea);
        }
        .encabezado-pagina h1 {
            margin: 0;
            font-family: 'Space Grotesk', sans-serif;
            font-size: 28px;
            line-height: 1.15;
            letter-spacing: 0;
        }
        .encabezado-pagina p {
            max-width: 680px;
            margin: 7px 0 0;
            font-size: 13px;
            line-height: 1.55;
        }
        .etiqueta-modulo { margin-bottom: 6px; letter-spacing: 0; }
        .acciones-encabezado { align-items: center; gap: 8px; }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            min-height: 38px;
            padding: 8px 12px;
            border-radius: 7px;
            transition: background .15s ease, border-color .15s ease, color .15s ease, box-shadow .15s ease;
        }
        .btn svg, .titulo-con-icono svg, .kpi-dist .lbl svg, .form-seccion-titulo svg {
            width: 16px;
            height: 16px;
            stroke-width: 1.9;
            flex: 0 0 auto;
        }
        .btn:focus-visible, .acciones-tabla button:focus-visible, .modal-cerrar:focus-visible {
            outline: 3px solid var(--petroleo-wash);
            outline-offset: 2px;
        }
        .grid-kpis { gap: 12px; margin-bottom: 16px; }
        .kpi-dist {
            min-height: 108px;
            padding: 15px 16px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(20,33,43,.045) !important;
        }
        .kpi-dist::before {
            inset: 0 auto 0 0;
            width: 3px;
            height: auto;
            background: var(--kpi-color, var(--petroleo));
        }
        .kpi-dist .lbl {
            display: flex;
            align-items: center;
            gap: 7px;
            margin-bottom: 14px;
            color: var(--texto-suave);
            font-size: 11px;
            letter-spacing: 0;
        }
        .kpi-dist .lbl svg { color: var(--kpi-color, var(--petroleo)); }
        .kpi-dist .num { font-size: 28px; }
        .layout-distribucion { gap: 16px; }
        .panel {
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(20,33,43,.05) !important;
        }
        .panel-cabecera { min-height: 56px; padding: 13px 16px; }
        .panel-cabecera h2 { font-size: 14px; letter-spacing: 0; }
        .titulo-con-icono { display: inline-flex; align-items: center; gap: 8px; }
        .titulo-con-icono svg { color: var(--petroleo); }
        .mapa-distribucion { min-height: 520px; }
        #mapa-distribucion { min-height: 520px; }
        .mapa-lateral { padding: 18px; background: #F8FAFB; }
        .mapa-lateral-titulo {
            margin: 0;
            color: var(--texto);
            font-size: 12px;
            font-weight: 700;
        }
        .mapa-estado { border-radius: 6px; box-shadow: none; }
        .mapa-leyenda { padding-top: 4px; gap: 10px; }
        .toolbar { padding: 12px 16px; background: #FAFBFC; }
        .toolbar input, .toolbar select { min-height: 38px; border-radius: 6px; }
        .busqueda-toolbar { position: relative; flex: 1; min-width: 240px; }
        .busqueda-toolbar svg {
            position: absolute;
            left: 11px;
            top: 50%;
            width: 16px;
            height: 16px;
            color: var(--texto-tenue);
            transform: translateY(-50%);
            pointer-events: none;
        }
        .toolbar .busqueda-toolbar input { width: 100%; min-width: 0; padding-left: 35px; }
        .toolbar input:focus, .toolbar select:focus {
            outline: none;
            border-color: var(--petroleo);
            box-shadow: 0 0 0 3px var(--petroleo-wash);
        }
        .table-wrap { overflow-x: auto; }
        th { padding: 10px 14px; letter-spacing: 0; white-space: nowrap; }
        td { padding: 13px 14px; line-height: 1.4; }
        tbody tr { transition: background .12s ease; }
        .acciones-tabla { gap: 5px; }
        .acciones-tabla button { border-radius: 6px; padding: 6px 9px; }
        .badge { border-radius: 6px; padding: 4px 8px; }
        .ruta-metrica { border-radius: 6px; box-shadow: none; }
        .ruta-metrica .lbl { letter-spacing: 0; }
        .ruta-item { border-radius: 6px; background: #FFFFFF; }
        .modal {
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 24px 64px rgba(20,33,43,.20) !important;
        }
        .modal-cabecera { padding: 14px 18px; background: #FAFBFC; }
        .modal-cabecera h2 { font-size: 16px; }
        .modal-cuerpo { max-height: calc(100vh - 110px); overflow-y: auto; padding: 18px 20px 20px; }
        .modal-cerrar { display: inline-flex; align-items: center; justify-content: center; border-radius: 6px; font-size: 0; }
        .modal-cerrar svg { width: 17px; height: 17px; }
        .form-grid { gap: 13px; }
        .form-seccion-titulo {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 4px;
            padding-top: 14px;
            border-top: 1px solid var(--linea-suave);
            color: var(--texto);
            font-size: 12px;
            font-weight: 700;
        }
        .form-seccion-titulo.primera { margin-top: 0; padding-top: 0; border-top: 0; }
        .form-seccion-titulo svg { color: var(--petroleo); }
        .accion-calculo { display: flex; justify-content: flex-end; gap: 8px; }
        .form-row label { margin-bottom: 5px; font-weight: 600; }
        .form-row input, .form-row select, .form-row textarea { min-height: 39px; border-radius: 6px; }
        .ruta-preview-form {
            border: 1px solid var(--linea);
            border-radius: 7px;
            padding: 14px;
            background: #FAFBFC;
        }
        .ruta-preview-metrica strong { font-family: 'Space Grotesk', sans-serif; }
        .combustible-form { padding: 14px; border: 1px solid var(--linea); border-radius: 7px; background: #FFFFFF; }
        .combustible-resultado { border-radius: 4px; }
        .msg.ok, .msg.error { padding: 9px 11px; border-radius: 6px; }
        .msg.ok { background: var(--petroleo-wash); }
        .msg.error { background: var(--critico-wash); }
        .acciones-formulario {
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            position: sticky;
            bottom: -20px;
            margin: 2px -20px -20px;
            padding: 13px 20px;
            border-top: 1px solid var(--linea-suave);
            background: rgba(255,255,255,.96);
            backdrop-filter: blur(8px);
            z-index: 2;
        }
        @media (max-width: 680px) {
            body.tema-oscuro .principal { padding: 20px 14px 42px; }
            .encabezado-pagina { align-items: flex-start; }
            .acciones-encabezado { width: 100%; }
            .encabezado-pagina > .acciones-encabezado .btn { flex: 1 1 auto; }
            .grid-kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .kpi-dist { min-height: 96px; }
            .mapa-distribucion, #mapa-distribucion { min-height: 360px; }
            .combustible-grid { grid-template-columns: 1fr; }
            .acciones-formulario .btn { flex: 1; }
        }

        /* MDM oscuro: elimina superficies claras heredadas de esta vista. */
        body.tema-mdm-oscuro .principal { background: #0A1118 !important; }
        body.tema-mdm-oscuro .panel,
        body.tema-mdm-oscuro .kpi-dist,
        body.tema-mdm-oscuro .modal,
        body.tema-mdm-oscuro .ruta-item,
        body.tema-mdm-oscuro .ruta-metrica,
        body.tema-mdm-oscuro .mapa-estado,
        body.tema-mdm-oscuro .combustible-form {
            background: #121C25 !important;
            border-color: #3B4B57 !important;
            box-shadow: none !important;
        }
        body.tema-mdm-oscuro .panel-cabecera,
        body.tema-mdm-oscuro .modal-cabecera,
        body.tema-mdm-oscuro .toolbar,
        body.tema-mdm-oscuro .mapa-lateral,
        body.tema-mdm-oscuro .ruta-preview-form,
        body.tema-mdm-oscuro .acciones-formulario {
            background: #17242E !important;
            border-color: #3B4B57 !important;
        }
        body.tema-mdm-oscuro #mapa-distribucion { background: #17242E !important; }
        body.tema-mdm-oscuro .toolbar input,
        body.tema-mdm-oscuro .toolbar select,
        body.tema-mdm-oscuro .form-row input,
        body.tema-mdm-oscuro .form-row select,
        body.tema-mdm-oscuro .form-row textarea,
        body.tema-mdm-oscuro .combustible-resultado {
            background: #0D171F !important;
            color: #F1F6F8 !important;
            border-color: #4A5C68 !important;
        }
        body.tema-mdm-oscuro th { background: #1B2A35 !important; color: #D2DEE4 !important; }
        body.tema-mdm-oscuro td { color: #C0CDD4 !important; border-color: #293843 !important; }
        body.tema-mdm-oscuro tbody tr:nth-child(even) { background: #15212B !important; }
        body.tema-mdm-oscuro tbody tr:hover { background: #20313D !important; }
        body.tema-mdm-oscuro .btn:not(.primario),
        body.tema-mdm-oscuro .acciones-tabla button:not(.accion-principal),
        body.tema-mdm-oscuro .modal-cerrar {
            background: #17242E !important;
            color: #E6EEF2 !important;
            border-color: #536773 !important;
        }
        body.tema-mdm-oscuro .gm-style .gm-style-iw-c,
        body.tema-mdm-oscuro .gm-style .gm-style-iw-d { background: #17242E !important; color: #F1F6F8 !important; }
        body.tema-mdm-oscuro .gm-style .gm-style-iw-tc::after { background: #17242E !important; }

        /* Superficies operativas con jerarquia visual consistente. */
        .grid-kpis{gap:12px}.kpi-dist{min-height:92px;padding:17px 18px;border-color:#dbe5eb;border-radius:14px;background:linear-gradient(150deg,#fff,#fbfdfe);box-shadow:0 7px 20px rgba(17,52,76,.055);transition:transform .2s ease,box-shadow .2s ease}.kpi-dist:hover{transform:translate3d(0,-2px,0);box-shadow:0 11px 26px rgba(17,52,76,.09)}.kpi-dist::before{height:3px}.kpi-dist .lbl{font-family:Inter,sans-serif;font-size:10px;letter-spacing:.06em}.kpi-dist .num{font-family:'Space Grotesk',sans-serif;letter-spacing:-.025em}
        .panel{overflow:hidden;border-color:#dbe5eb;border-radius:15px;box-shadow:0 9px 26px rgba(17,52,76,.065)}.panel-cabecera{min-height:62px;padding:15px 19px;background:linear-gradient(180deg,#fff,#f9fbfc);border-color:#e2e9ed}.panel-cabecera h2{color:#17364d;font:700 16px/1.2 'Space Grotesk',sans-serif;letter-spacing:-.015em}.panel-cabecera p,.panel-cabecera small{color:#607584;font-size:11px}.tabs{padding:8px 12px;background:#f5f8fa}.tab{min-height:38px;border-radius:8px;font-family:Inter,sans-serif;font-weight:700;transition:background .18s ease,color .18s ease}.tab:hover{background:#eaf4f9}.tab.activo{background:#fff;box-shadow:0 3px 10px rgba(17,52,76,.07)}
        body.tema-mdm-oscuro .kpi-dist{background:#121c25;border-color:#3b4b57;box-shadow:none}body.tema-mdm-oscuro .panel{box-shadow:none}body.tema-mdm-oscuro .panel-cabecera{background:#15232d}body.tema-mdm-oscuro .panel-cabecera h2{color:#f0f6f8}body.tema-mdm-oscuro .tabs{background:#17242e}
        @media(prefers-reduced-motion:reduce){.kpi-dist,.tab{transition:none!important}}
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
        <div class="encabezado-pagina mdm-page-heading">
            <div class="mdm-page-heading__intro">
                <span class="mdm-page-heading__icon"><i data-lucide="container"></i></span>
                <div>
                    <div class="etiqueta-modulo">Logistica de maquinas</div>
                    <h1>Distribucion</h1>
                    <p>Programa viajes, monitorea tiempos limite y prioriza entregas con estimaciones por distancia.</p>
                </div>
            </div>
            <div class="acciones-encabezado">
                <?php if (Auth::isAdmin()): ?><button class="btn primario" id="btn-nuevo-viaje"><i data-lucide="plus"></i>Nuevo viaje</button><?php endif; ?>
                <button class="btn" id="btn-actualizar"><i data-lucide="refresh-cw"></i>Actualizar</button>
            </div>
        </div>

        <section class="mdm-fleet-visual mdm-fleet-visual--maquinas" aria-label="Flota para distribucion de maquinas">
            <div class="mdm-fleet-visual__copy">
                <span class="mdm-fleet-visual__eyebrow">Logistica de instalaciones</span>
                <h2>Movemos tecnologia hacia nuevos puntos de servicio</h2>
                <p>Programa el traslado de maquinas, valida destinos y da seguimiento a cada viaje.</p>
                <div class="mdm-fleet-visual__tags">
                    <span><i data-lucide="map-pinned"></i>Destino validado</span>
                    <span><i data-lucide="navigation"></i>Ruta calculada</span>
                    <span><i data-lucide="clock-3"></i>Seguimiento operativo</span>
                </div>
            </div>
            <div class="mdm-fleet-visual__art">
                <img src="assets/img/camion-vending.png" alt="Camion de distribucion Vending Medical Life" loading="lazy" decoding="async">
            </div>
        </section>

        <section class="grid-kpis">
            <div class="kpi-dist"><div class="lbl"><i data-lucide="truck"></i>Viajes activos</div><div class="num" id="kpi-activos">-</div></div>
            <div class="kpi-dist" style="--kpi-color:var(--critico)"><div class="lbl"><i data-lucide="circle-alert"></i>Rojo / vencido</div><div class="num" id="kpi-rojos">-</div></div>
            <div class="kpi-dist" style="--kpi-color:var(--ambar)"><div class="lbl"><i data-lucide="clock-3"></i>Proximos 3 dias</div><div class="num" id="kpi-amarillos">-</div></div>
            <div class="kpi-dist" style="--kpi-color:var(--azul)"><div class="lbl"><i data-lucide="map-pin-off"></i>Sin distancia</div><div class="num" id="kpi-sin-distancia">-</div></div>
        </section>

        <section class="layout-distribucion">
            <div class="panel">
                <div class="panel-cabecera">
                    <h2 class="titulo-con-icono"><i data-lucide="map"></i>Mapa de distribucion</h2>
                    <div class="acciones-encabezado">
                        <button class="btn" id="btn-centrar-mapa" type="button"><i data-lucide="locate-fixed"></i>Centrar</button>
                        <button class="btn primario" id="btn-trazar-mapa" type="button"><i data-lucide="waypoints"></i>Recalcular ruta</button>
                    </div>
                </div>
                <div class="mapa-distribucion">
                    <div id="mapa-distribucion" role="img" aria-label="Mapa de distribucion de maquinas"></div>
                    <aside class="mapa-lateral">
                        <div class="ruta-lateral-cabecera">
                            <h3 class="mapa-lateral-titulo">Ruta activa</h3>
                            <span class="mini" id="ruta-meta">Calculando paradas...</span>
                        </div>
                        <div class="ruta-panel-lateral" id="ruta-contenido">
                            <p class="cargando">Calculando ruta...</p>
                        </div>
                        <div class="mapa-contexto">
                            <h3 class="mapa-lateral-titulo">Estado del mapa</h3>
                            <div class="mapa-estado" id="mapa-estado">Cargando mapa...</div>
                            <div class="mapa-leyenda" aria-label="Leyenda del mapa">
                                <span><i style="background:#111827"></i>Almacén de salida</span>
                                <span><i class="rojo"></i>Vencido o urgente</span>
                                <span><i class="ambar"></i>Próximo a vencer</span>
                                <span><i></i>En tiempo</span>
                                <span><i class="azul"></i>En ruta</span>
                            </div>
                        </div>
                    </aside>
                </div>
            </div>
            <div class="panel">
                <div class="panel-cabecera">
                    <h2 class="titulo-con-icono"><i data-lucide="list-checks"></i>Seguimiento</h2>
                </div>
                <div class="toolbar">
                    <div class="busqueda-toolbar"><i data-lucide="search"></i><input id="buscar" type="text" aria-label="Buscar viajes" placeholder="Buscar por serie, destino o responsable"></div>
                    <select id="filtro-estado" aria-label="Filtrar por estado">
                        <option value="activos">Activos</option>
                        <option value="programado">Programados</option>
                        <option value="en_ruta">En ruta</option>
                        <option value="completado">Completados</option>
                        <option value="cancelado">Cancelados</option>
                        <option value="todos">Todos</option>
                    </select>
                </div>
                <div id="tabla-viajes" class="cargando">Cargando...</div>
            </div>
        </section>

    </main>
</div>

<div class="overlay-modal" id="overlay-viaje">
    <div class="modal">
        <div class="modal-cabecera">
            <h2 id="form-titulo">Nuevo viaje</h2>
            <button class="modal-cerrar" id="modal-cerrar" type="button" aria-label="Cerrar"><i data-lucide="x"></i></button>
        </div>
        <div class="modal-cuerpo">
            <form id="form-viaje" class="form-grid">
                <input type="hidden" id="f-id_viaje">
                <div class="form-seccion-titulo primera"><i data-lucide="map-pinned"></i>Trayecto</div>
                <div class="form-row">
                    <label>Maquina</label>
                    <select id="f-id_maquina" required></select>
                </div>
                <div class="form-row">
                    <label>Origen de salida (configurado)</label>
                    <input id="f-origen_nombre" type="text" readonly aria-readonly="true">
                </div>
                <input id="f-origen_latitud" type="hidden">
                <input id="f-origen_longitud" type="hidden">
                <div class="form-row">
                    <label>Ubicacion de destino</label>
                    <input id="f-destino_nombre" type="text" required placeholder="Ej. Hospital Angeles Pedregal">
                </div>
                <div class="form-row">
                    <label>Direccion completa de destino</label>
                    <input id="f-destino_direccion" type="text" required placeholder="Calle, numero, colonia, alcaldia o municipio, estado y codigo postal">
                </div>
                <div class="accion-calculo">
                    <button class="btn" id="btn-calcular-form" type="button"><i data-lucide="calculator"></i>Calcular ruta y costos</button>
                </div>
                <input id="f-destino_latitud" type="hidden">
                <input id="f-destino_longitud" type="hidden">
                <input id="f-horas_google" type="hidden">
                <input id="f-casetas_monto" type="hidden">
                <input id="f-casetas_moneda" type="hidden">
                <input id="f-gasolina_litros" type="hidden">
                <input id="f-gasolina_costo" type="hidden">
                <input id="f-ruta_polyline" type="hidden">
                <section class="ruta-preview-form" id="ruta-preview-form" hidden aria-live="polite">
                    <div>
                        <h3 class="ruta-preview-titulo">Ruta sugerida por Google</h3>
                        <div class="ruta-preview-metricas">
                            <div class="ruta-preview-metrica"><span>Distancia aproximada</span><strong id="ruta-form-km">-</strong></div>
                            <div class="ruta-preview-metrica"><span>Tiempo aproximado</span><strong id="ruta-form-horas">-</strong></div>
                            <div class="ruta-preview-metrica"><span>Casetas aproximadas</span><strong id="ruta-form-casetas">-</strong></div>
                            <div class="ruta-preview-metrica"><span>Combustible aproximado</span><strong id="ruta-form-gasolina">-</strong></div>
                        </div>
                        <p class="ruta-preview-aviso">Datos aproximados. La ruta, el tiempo, las casetas y el consumo pueden cambiar por trafico, cierres, tarifas y condiciones del viaje.</p>
                    </div>
                    <div id="mapa-ruta-form" role="img" aria-label="Minimapa de la ruta sugerida"></div>
                </section>
                <section class="combustible-form" id="combustible-form" hidden>
                    <h3>Estimacion de combustible</h3>
                    <p>Captura los datos del vehiculo y el precio pagado. El resultado es aproximado.</p>
                    <div class="combustible-grid">
                        <div class="form-row">
                            <label>Rendimiento (km/L)</label>
                            <input id="f-rendimiento_km_l" type="number" min="0.1" step="0.1" inputmode="decimal">
                        </div>
                        <div class="form-row">
                            <label>Precio gasolina ($/L)</label>
                            <input id="f-gasolina_precio_litro" type="number" min="0" step="0.01" inputmode="decimal" placeholder="Ej. 24.50">
                        </div>
                        <div class="combustible-resultado"><span>Litros aproximados</span><strong id="combustible-litros">-</strong></div>
                        <div class="combustible-resultado"><span>Costo aproximado</span><strong id="combustible-costo">-</strong></div>
                    </div>
                </section>
                <div class="form-seccion-titulo"><i data-lucide="calendar-clock"></i>Programacion</div>
                <div class="dos-cols">
                    <div class="form-row">
                        <label>Salida programada</label>
                        <input id="f-fecha_salida" type="datetime-local">
                    </div>
                    <div class="form-row">
                        <label>Fecha limite</label>
                        <input id="f-fecha_limite" type="datetime-local" required>
                    </div>
                </div>
                <div class="dos-cols">
                    <div class="form-row">
                        <label>Km estimados</label>
                        <input id="f-distancia_km" type="number" min="0" step="0.1">
                    </div>
                    <div class="form-row">
                        <label>Velocidad km/h</label>
                        <input id="f-velocidad" type="number" min="1" step="1" value="65">
                    </div>
                </div>
                <div class="form-seccion-titulo"><i data-lucide="user-check"></i>Seguimiento del viaje</div>
                <div class="dos-cols">
                    <div class="form-row">
                        <label>Responsable</label>
                        <input id="f-responsable" type="text">
                    </div>
                    <div class="form-row">
                        <label>Estatus</label>
                        <select id="f-estatus">
                            <option value="programado">Programado</option>
                            <option value="en_ruta">En ruta</option>
                            <option value="completado">Completado</option>
                            <option value="cancelado">Cancelado</option>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <label>Notas</label>
                    <textarea id="f-notas"></textarea>
                </div>
                <div class="msg" id="form-msg"></div>
                <div class="acciones-formulario">
                    <button class="btn" id="btn-limpiar" type="button"><i data-lucide="eraser"></i>Limpiar</button>
                    <button class="btn primario" id="btn-guardar" type="submit"><i data-lucide="save"></i>Guardar viaje</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="assets/js/comun.js?v=20260912-1"></script>
<script>
    const ES_ADMIN = <?= Auth::isAdmin() ? 'true' : 'false' ?>;
    const GOOGLE_MAPS_API_KEY = <?= json_encode($googleMapsApiKey, JSON_UNESCAPED_SLASHES) ?>;
    const MAPA_ORIGEN = <?= json_encode($mapaOrigen, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const RENDIMIENTO_GASOLINA_DEFAULT = <?= json_encode($rendimientoGasolinaDefault) ?>;
    const PRECIO_GASOLINA_DEFAULT = <?= json_encode($precioGasolinaDefault) ?>;
    let maquinas = [];
    let viajes = [];
    let debounce = null;
    const overlayViaje = document.getElementById('overlay-viaje');
    let mapa = null;
    let googleMapsListo = false;
    let marcadoresMapa = [];
    let directionsRenderer = null;
    let polylineRuta = null;
    let infoMapa = null;
    let origenMapaResuelto = null;
    let rutaRecomendadaActual = [];
    let metaRutaRecomendadaActual = {};
    let mapaRutaFormulario = null;
    let polylineRutaFormulario = null;
    let marcadoresRutaFormulario = [];
    window.gm_authFailure = function () {
        actualizarEstadoMapa('Google Maps rechazo la API key. Revisa que Maps JavaScript API este habilitada, que la facturacion este activa y que el dominio/IP local este permitido en las restricciones de la key.');
    };

    async function obtenerJSON(url) {
        const res = await fetch(url);
        const json = await res.json();
        if (!json.ok) throw new Error(json.error || 'Error de servidor');
        return json;
    }

    function escapeHtml(v) {
        return String(v ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
    }
    function fmt(n, dec = 0) {
        if (n === null || n === undefined || n === '') return '-';
        return Number(n).toLocaleString('es-MX', { maximumFractionDigits: dec, minimumFractionDigits: dec });
    }
    function fmtDuracion(horas) {
        if (horas === null || horas === undefined || horas === '' || Number(horas) <= 0) return '-';
        const minutosTotales = Math.round(Number(horas) * 60);
        const horasEnteras = Math.floor(minutosTotales / 60);
        const minutos = minutosTotales % 60;
        if (!horasEnteras) return `${minutos} min`;
        return `${horasEnteras} h${minutos ? ` ${minutos} min` : ''}`;
    }
    function fechaLocal(valor) {
        if (!valor) return '-';
        return new Date(String(valor).replace(' ', 'T')).toLocaleString('es-MX', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });
    }
    function paraInputFecha(valor) {
        if (!valor) return '';
        const d = new Date(String(valor).replace(' ', 'T'));
        const pad = n => String(n).padStart(2, '0');
        return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
    }
    function val(id) { return document.getElementById(id).value; }
    function numVal(id) { const v = val(id); return v === '' ? null : Number(v); }
    function coordenada(v) { return v === null || v === undefined || v === '' ? null : Number(v); }
    function distanciaKmLocal(lat1, lng1, lat2, lng2) {
        const r = 6371;
        const toRad = n => n * Math.PI / 180;
        const dLat = toRad(lat2 - lat1);
        const dLng = toRad(lng2 - lng1);
        const a = Math.sin(dLat / 2) ** 2 + Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) * Math.sin(dLng / 2) ** 2;
        return r * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a)) * 1.18;
    }
    function tieneCoordenadas(v) {
        return coordenada(v.destino_latitud) !== null && coordenada(v.destino_longitud) !== null;
    }
    function colorMarcador(v) {
        if (v.estatus === 'en_ruta') return '#2563B8';
        if (['vencido', 'rojo'].includes(v.estado_calculado)) return '#B42335';
        if (v.estado_calculado === 'amarillo') return '#9A620B';
        return '#087D76';
    }
    function origenMapaDesdeViajes(lista) {
        const latEnv = coordenada(MAPA_ORIGEN.lat);
        const lngEnv = coordenada(MAPA_ORIGEN.lng);
        if (latEnv !== null && lngEnv !== null) return { lat: latEnv, lng: lngEnv, nombre: MAPA_ORIGEN.nombre || 'Origen' };
        const conOrigen = lista.find(v => coordenada(v.origen_latitud) !== null && coordenada(v.origen_longitud) !== null);
        if (!conOrigen) return null;
        return {
            lat: coordenada(conOrigen.origen_latitud),
            lng: coordenada(conOrigen.origen_longitud),
            nombre: conOrigen.origen_nombre || 'Origen'
        };
    }
    function origenDireccionMapa() {
        return MAPA_ORIGEN.direccion || MAPA_ORIGEN.nombre || '';
    }
    function usaDireccionOrigenPorDefecto() {
        return origenDireccionMapa() && coordenada(MAPA_ORIGEN.lat) === null && coordenada(MAPA_ORIGEN.lng) === null;
    }

    function badgeEstado(v) {
        const e = v.estado_calculado;
        if (e === 'vencido') return '<span class="badge badge-danger">Vencido</span>';
        if (e === 'rojo') return '<span class="badge badge-danger">Rojo</span>';
        if (e === 'amarillo') return '<span class="badge badge-warn">Poco tiempo</span>';
        if (e === 'completado') return '<span class="badge badge-ok">Completado</span>';
        if (e === 'cancelado') return '<span class="badge badge-neutro">Cancelado</span>';
        if (v.estatus === 'en_ruta') return '<span class="badge badge-info">En ruta</span>';
        return '<span class="badge badge-ok">OK</span>';
    }

    function textoEstatusViaje(estatus) {
        return ({ programado: 'Programado', en_ruta: 'En ruta', completado: 'Completado', cancelado: 'Cancelado' })[estatus] || estatus || '-';
    }

    function textoMargen(horas) {
        if (horas === null || horas === undefined) return '-';
        if (Number(horas) === 0) return 'Sin margen';
        return `${fmtDuracion(Math.abs(Number(horas)))} ${Number(horas) < 0 ? 'de retraso' : 'disponible'}`;
    }

    async function cargarMaquinas() {
        const json = await obtenerJSON('api/distribucion.php?accion=maquinas');
        maquinas = json.data || [];
        document.getElementById('f-id_maquina').innerHTML = maquinas.length
            ? '<option value="">Selecciona una maquina</option>' + maquinas.map(m => `<option value="${m.id_maquina}">${escapeHtml(m.numero_serie)}</option>`).join('')
            : '<option value="">No hay maquinas disponibles</option>';
    }

    function completarDestinoDesdeMaquina() {
        const m = maquinas.find(x => Number(x.id_maquina) === numVal('f-id_maquina'));
        if (!m) return;
        const destinoNombre = document.getElementById('f-destino_nombre');
        const destinoDireccion = document.getElementById('f-destino_direccion');
        const destinoLat = document.getElementById('f-destino_latitud');
        const destinoLng = document.getElementById('f-destino_longitud');
        destinoNombre.value = '';
        destinoDireccion.value = '';
        destinoLat.value = '';
        destinoLng.value = '';
        destinoNombre.placeholder = `Ubicacion donde se instalara ${m.numero_serie || 'la maquina'}`;
    }

    async function cargarResumen() {
        const json = await obtenerJSON('api/distribucion.php?accion=resumen');
        const d = json.data || {};
        document.getElementById('kpi-activos').textContent = fmt(d.total_activos);
        document.getElementById('kpi-rojos').textContent = fmt((Number(d.vencidos) || 0) + (Number(d.rojos) || 0));
        document.getElementById('kpi-amarillos').textContent = fmt(d.amarillos);
        document.getElementById('kpi-sin-distancia').textContent = fmt(d.sin_distancia);
    }

    async function cargarViajes() {
        const cont = document.getElementById('tabla-viajes');
        cont.className = 'cargando';
        cont.textContent = 'Cargando...';
        const params = new URLSearchParams({ accion: 'listar', estado: val('filtro-estado') });
        const q = val('buscar').trim();
        if (q) params.set('q', q);

        try {
            const json = await obtenerJSON(`api/distribucion.php?${params}`);
            viajes = json.data || [];
            if (!viajes.length) {
                cont.className = '';
                cont.innerHTML = '<p class="vacio">Sin viajes para este filtro.</p>';
                refrescarMapaOperativo();
                return;
            }
            cont.className = '';
            cont.innerHTML = `<div class="table-wrap"><table>
                <thead><tr><th>Máquina / responsable</th><th>Destino</th><th>Programación</th><th>Ruta y costos</th><th>Prioridad</th>${ES_ADMIN ? '<th>Acciones</th>' : ''}</tr></thead>
                <tbody>${viajes.map(v => `
                    <tr>
                        <td><span class="mono">${escapeHtml(v.numero_serie)}</span><div class="mini">${escapeHtml(v.responsable) || 'Sin responsable asignado'}</div></td>
                        <td>${escapeHtml(v.destino_nombre)}<div class="mini">${escapeHtml(v.destino_direccion) || escapeHtml(v.responsable) || ''}</div></td>
                        <td><div class="mini">Salida</div><span class="mono">${fechaLocal(v.fecha_salida_programada)}</span><div class="mini">Límite: ${fechaLocal(v.fecha_limite)}</div></td>
                        <td><strong>${v.distancia_km ? `${fmt(v.distancia_km, 1)} km` : 'Sin calcular'}</strong><div class="mini">${v.horas_estimadas ? `${fmtDuracion(v.horas_estimadas)} aprox.` : 'Tiempo no disponible'}</div><div class="mini">${v.casetas_monto !== null && v.casetas_monto !== undefined ? `Casetas $${fmt(v.casetas_monto, 2)} ${escapeHtml(v.casetas_moneda || 'MXN')}` : 'Casetas no disponibles'}</div><div class="mini">${v.gasolina_costo !== null && v.gasolina_costo !== undefined ? `Combustible $${fmt(v.gasolina_costo, 2)} MXN` : 'Combustible por estimar'}</div></td>
                        <td>${badgeEstado(v)}<div class="mini">${textoEstatusViaje(v.estatus)}</div><div class="mini">Margen: ${textoMargen(v.margen_horas)}</div></td>
                        ${ES_ADMIN ? `<td><div class="acciones-tabla">
                            <button type="button" data-editar="${v.id_viaje}"><i data-lucide="pencil"></i>Editar</button>
                            ${v.estatus === 'programado' ? `<button type="button" class="accion-principal" data-estatus="${v.id_viaje}" data-valor="en_ruta"><i data-lucide="truck"></i>Iniciar viaje</button>` : ''}
                            ${v.estatus === 'en_ruta' ? `<button type="button" class="accion-principal" data-estatus="${v.id_viaje}" data-valor="completado"><i data-lucide="circle-check"></i>Completar</button>` : ''}
                        </div></td>` : ''}
                    </tr>`).join('')}</tbody>
            </table></div>`;
            cont.querySelectorAll('[data-editar]').forEach(btn => btn.addEventListener('click', () => editarViaje(Number(btn.dataset.editar))));
            cont.querySelectorAll('[data-estatus]').forEach(btn => btn.addEventListener('click', () => cambiarEstatus(Number(btn.dataset.estatus), btn.dataset.valor, btn)));
            if (!ES_ADMIN) cont.querySelectorAll('.acciones-tabla').forEach(el => el.remove());
            cargarLucide().then(lib => lib.createIcons()).catch(() => {});
            refrescarMapaOperativo();
        } catch (e) {
            cont.className = '';
            cont.innerHTML = '<p class="vacio">No se pudo cargar distribucion.</p>';
            actualizarEstadoMapa('No se pudo cargar la informacion del mapa.');
        }
    }

    function cargarGoogleMaps() {
        if (!GOOGLE_MAPS_API_KEY) {
            actualizarEstadoMapa('Configura GOOGLE_MAPS_BROWSER_API_KEY en .env para activar Google Maps.');
            return;
        }
        window.initDistribucionGoogleMaps = inicializarMapa;
        const script = document.createElement('script');
        script.src = `https://maps.googleapis.com/maps/api/js?key=${encodeURIComponent(GOOGLE_MAPS_API_KEY)}&callback=initDistribucionGoogleMaps&language=es&region=MX&libraries=geometry&v=weekly&loading=async`;
        script.async = true;
        script.defer = true;
        script.onerror = () => actualizarEstadoMapa('No se pudo cargar Google Maps. Revisa la API key y los dominios autorizados.');
        document.head.appendChild(script);
    }

    function inicializarMapa() {
        googleMapsListo = true;
        mapa = new google.maps.Map(document.getElementById('mapa-distribucion'), {
            center: { lat: 23.6345, lng: -102.5528 },
            zoom: 5,
            mapTypeControl: false,
            streetViewControl: false,
            fullscreenControl: true,
            styles: [
                { featureType: 'poi.business', stylers: [{ visibility: 'off' }] },
                { featureType: 'transit', stylers: [{ visibility: 'off' }] }
            ],
        });
        directionsRenderer = new google.maps.DirectionsRenderer({
            map: mapa,
            suppressMarkers: true,
            polylineOptions: { strokeColor: '#087D76', strokeWeight: 5, strokeOpacity: .82 },
        });
        infoMapa = new google.maps.InfoWindow();
        if (rutaRecomendadaActual.length) {
            trazarRutaEnMapa(rutaRecomendadaActual, metaRutaRecomendadaActual);
        } else {
            pintarMapaViajes(viajes);
        }
    }

    function resolverOrigenMapa() {
        if (origenMapaResuelto) return Promise.resolve(origenMapaResuelto);
        const lat = coordenada(MAPA_ORIGEN.lat);
        const lng = coordenada(MAPA_ORIGEN.lng);
        if (lat !== null && lng !== null) {
            origenMapaResuelto = { lat, lng, nombre: MAPA_ORIGEN.nombre || 'Origen de distribucion' };
            return Promise.resolve(origenMapaResuelto);
        }
        const direccion = origenDireccionMapa();
        if (!direccion || !google.maps.Geocoder) return Promise.resolve(null);
        return new Promise(resolve => {
            const geocoder = new google.maps.Geocoder();
            geocoder.geocode({ address: direccion, region: 'MX' }, (results, status) => {
                if (status === 'OK' && results[0]) {
                    origenMapaResuelto = {
                        lat: results[0].geometry.location.lat(),
                        lng: results[0].geometry.location.lng(),
                        nombre: MAPA_ORIGEN.nombre || 'Origen de distribucion',
                    };
                }
                resolve(origenMapaResuelto);
            });
        });
    }

    async function centrarMapaEnOrigen() {
        const origen = await resolverOrigenMapa();
        if (!origen || !mapa) return;
        mapa.setCenter({ lat: origen.lat, lng: origen.lng });
        mapa.setZoom(13);
    }

    function actualizarEstadoMapa(texto) {
        document.getElementById('mapa-estado').textContent = texto;
    }

    function limpiarCapaMapa() {
        marcadoresMapa.forEach(m => m.setMap(null));
        marcadoresMapa = [];
        if (directionsRenderer) directionsRenderer.set('directions', null);
        if (polylineRuta) {
            polylineRuta.setMap(null);
            polylineRuta = null;
        }
    }

    function crearIcono(color) {
        return {
            path: google.maps.SymbolPath.CIRCLE,
            fillColor: color,
            fillOpacity: 1,
            strokeColor: '#FFFFFF',
            strokeWeight: 2,
            scale: 8,
        };
    }

    async function pintarMapaViajes(lista) {
        if (!googleMapsListo || !mapa) return;
        limpiarCapaMapa();
        const conCoords = lista.filter(tieneCoordenadas);
        const bounds = new google.maps.LatLngBounds();
        const origen = await resolverOrigenMapa() || origenMapaDesdeViajes(lista);
        if (origen) {
            const markerOrigen = new google.maps.Marker({
                map: mapa,
                position: { lat: origen.lat, lng: origen.lng },
                title: origen.nombre || 'Origen de distribucion',
                icon: crearIcono('#111827'),
            });
            marcadoresMapa.push(markerOrigen);
            bounds.extend(markerOrigen.getPosition());
        }
        conCoords.forEach(v => {
            const marker = new google.maps.Marker({
                map: mapa,
                position: { lat: coordenada(v.destino_latitud), lng: coordenada(v.destino_longitud) },
                title: v.destino_nombre,
                icon: crearIcono(colorMarcador(v)),
            });
            marker.addListener('click', () => {
                infoMapa.setContent(`
                    <div style="font-family:Inter,Arial,sans-serif;min-width:180px">
                        <b>${escapeHtml(v.destino_nombre)}</b>
                        <div>${escapeHtml(v.numero_serie)}</div>
                        <div>${fechaLocal(v.fecha_limite)}</div>
                    </div>`);
                infoMapa.open({ anchor: marker, map: mapa });
            });
            marcadoresMapa.push(marker);
            bounds.extend(marker.getPosition());
        });
        if (!conCoords.length) {
            if (origen) {
                mapa.setCenter({ lat: origen.lat, lng: origen.lng });
                mapa.setZoom(13);
                actualizarEstadoMapa('Origen de distribucion visible. Crea un viaje para agregar su destino al mapa.');
            } else {
                actualizarEstadoMapa('No se pudo ubicar el origen de distribucion.');
            }
            return;
        }
        mapa.fitBounds(bounds, 48);
        actualizarEstadoMapa(`${conCoords.length} destino${conCoords.length === 1 ? '' : 's'} visible${conCoords.length === 1 ? '' : 's'}. La ruta se calcula automáticamente.`);
    }

    async function refrescarMapaOperativo() {
        if (rutaRecomendadaActual.length) {
            await pintarMapaViajes(rutaRecomendadaActual);
            await trazarRutaEnMapa(rutaRecomendadaActual, metaRutaRecomendadaActual);
            return;
        }
        await pintarMapaViajes(viajes);
    }

    async function trazarRutaEnMapa(rutaCalculada = null, meta = {}) {
        if (!googleMapsListo || !mapa) {
            actualizarEstadoMapa('Google Maps todavia no esta listo.');
            return;
        }
        const dataRuta = rutaCalculada || (await obtenerJSON('api/distribucion.php?accion=ruta')).data || [];
        const orden = dataRuta.filter(tieneCoordenadas);
        const origen = await resolverOrigenMapa() || origenMapaDesdeViajes(dataRuta || viajes);
        if (!origen || !orden.length) {
            actualizarEstadoMapa('Falta origen o coordenadas de destino para trazar la ruta.');
            return;
        }
        if (meta.ruta_polyline) {
            trazarPolylineCodificada(meta.ruta_polyline);
            actualizarEstadoMapa(`Ruta recomendada visible con ${orden.length} parada${orden.length === 1 ? '' : 's'}.`);
            return;
        }
        if (orden.length <= 25 && google.maps.DirectionsService) {
            const service = new google.maps.DirectionsService();
            service.route({
                origin: { lat: origen.lat, lng: origen.lng },
                destination: {
                    lat: coordenada(orden[orden.length - 1].destino_latitud),
                    lng: coordenada(orden[orden.length - 1].destino_longitud),
                },
                waypoints: orden.slice(0, -1).map(v => ({
                    location: { lat: coordenada(v.destino_latitud), lng: coordenada(v.destino_longitud) },
                    stopover: true,
                })),
                travelMode: google.maps.TravelMode.DRIVING,
                optimizeWaypoints: false,
            }, (result, status) => {
                if (status === 'OK') {
                    directionsRenderer.setDirections(result);
                    actualizarEstadoMapa('Ruta trazada con Google Maps.');
                } else {
                    trazarPolylineLocal(origen, orden);
                    actualizarEstadoMapa('Recorrido aproximado visible; Google no devolvio la geometria vial.');
                }
            });
            return;
        }
        trazarPolylineLocal(origen, orden);
        actualizarEstadoMapa('Recorrido aproximado visible en el mapa.');
    }

    function trazarPolylineCodificada(encodedPolyline) {
        if (!encodedPolyline || !google.maps.geometry?.encoding) return;
        if (polylineRuta) polylineRuta.setMap(null);
        const path = google.maps.geometry.encoding.decodePath(encodedPolyline);
        if (!path.length) return;
        polylineRuta = new google.maps.Polyline({
            path,
            strokeColor: '#087D76',
            strokeOpacity: .9,
            strokeWeight: 5,
            map: mapa,
        });
        const bounds = new google.maps.LatLngBounds();
        path.forEach(p => bounds.extend(p));
        mapa.fitBounds(bounds, 48);
    }

    function trazarPolylineLocal(origen, orden) {
        if (polylineRuta) polylineRuta.setMap(null);
        const path = [
            { lat: origen.lat, lng: origen.lng },
            ...orden.map(v => ({ lat: coordenada(v.destino_latitud), lng: coordenada(v.destino_longitud) }))
        ];
        polylineRuta = new google.maps.Polyline({
            path,
            geodesic: true,
            strokeColor: '#087D76',
            strokeOpacity: .78,
            strokeWeight: 4,
            map: mapa,
        });
        const bounds = new google.maps.LatLngBounds();
        path.forEach(p => bounds.extend(p));
        mapa.fitBounds(bounds, 48);
    }

    function limpiarResultadoRuta(limpiarDistancia = false) {
        ['f-horas_google', 'f-casetas_monto', 'f-casetas_moneda', 'f-gasolina_litros', 'f-gasolina_costo', 'f-ruta_polyline']
            .forEach(id => { document.getElementById(id).value = ''; });
        if (limpiarDistancia) document.getElementById('f-distancia_km').value = '';
        document.getElementById('ruta-preview-form').hidden = true;
        document.getElementById('combustible-form').hidden = true;
        if (polylineRutaFormulario) {
            polylineRutaFormulario.setMap(null);
            polylineRutaFormulario = null;
        }
        marcadoresRutaFormulario.forEach(m => m.setMap(null));
        marcadoresRutaFormulario = [];
    }

    function pintarMiniMapaRuta(encodedPolyline) {
        if (!googleMapsListo || !encodedPolyline || !google.maps.geometry?.encoding) return;
        const path = google.maps.geometry.encoding.decodePath(encodedPolyline);
        if (!path.length) return;
        if (!mapaRutaFormulario) {
            mapaRutaFormulario = new google.maps.Map(document.getElementById('mapa-ruta-form'), {
                center: path[0],
                zoom: 11,
                mapTypeControl: false,
                streetViewControl: false,
                fullscreenControl: false,
                clickableIcons: false,
                gestureHandling: 'cooperative',
            });
        }
        if (polylineRutaFormulario) polylineRutaFormulario.setMap(null);
        marcadoresRutaFormulario.forEach(m => m.setMap(null));
        polylineRutaFormulario = new google.maps.Polyline({
            path,
            map: mapaRutaFormulario,
            strokeColor: '#087D76',
            strokeOpacity: .9,
            strokeWeight: 5,
        });
        marcadoresRutaFormulario = [
            new google.maps.Marker({ position: path[0], map: mapaRutaFormulario, title: 'Origen', icon: crearIcono('#2563B8') }),
            new google.maps.Marker({ position: path[path.length - 1], map: mapaRutaFormulario, title: 'Destino', icon: crearIcono('#B42335') }),
        ];
        const bounds = new google.maps.LatLngBounds();
        path.forEach(p => bounds.extend(p));
        mapaRutaFormulario.fitBounds(bounds, 28);
    }

    function mostrarResumenRuta(d) {
        const km = d.distancia_km ?? null;
        const horas = d.horas ?? d.horas_google ?? null;
        const casetas = d.casetas_monto ?? null;
        const moneda = d.casetas_moneda || 'MXN';
        const gasolina = d.gasolina_litros ?? null;
        const encodedPolyline = d.ruta_polyline || '';
        document.getElementById('f-horas_google').value = horas ?? '';
        document.getElementById('f-casetas_monto').value = casetas ?? '';
        document.getElementById('f-casetas_moneda').value = moneda;
        document.getElementById('f-gasolina_litros').value = gasolina ?? '';
        document.getElementById('f-ruta_polyline').value = encodedPolyline;
        document.getElementById('ruta-form-km').textContent = km !== null ? `${fmt(km, 1)} km` : '-';
        document.getElementById('ruta-form-horas').textContent = fmtDuracion(horas);
        document.getElementById('ruta-form-casetas').textContent = casetas !== null ? `$${fmt(casetas, 2)} ${moneda}` : 'No disponible';
        document.getElementById('ruta-form-gasolina').textContent = gasolina !== null ? `${fmt(gasolina, 1)} L` : 'No disponible';
        document.getElementById('ruta-preview-form').hidden = false;
        document.getElementById('combustible-form').hidden = false;
        recalcularEstimacionCombustible();
        setTimeout(() => pintarMiniMapaRuta(encodedPolyline), 0);
    }

    function recalcularEstimacionCombustible() {
        const km = numVal('f-distancia_km');
        const rendimiento = numVal('f-rendimiento_km_l');
        const precio = numVal('f-gasolina_precio_litro');
        const litros = km !== null && rendimiento !== null && rendimiento > 0 ? km / rendimiento : null;
        const costo = litros !== null && precio !== null && precio > 0 ? litros * precio : null;
        document.getElementById('f-gasolina_litros').value = litros !== null ? litros.toFixed(2) : '';
        document.getElementById('f-gasolina_costo').value = costo !== null ? costo.toFixed(2) : '';
        document.getElementById('combustible-litros').textContent = litros !== null ? `${fmt(litros, 2)} L` : '-';
        document.getElementById('combustible-costo').textContent = costo !== null ? `$${fmt(costo, 2)} MXN` : 'Captura precio';
        document.getElementById('ruta-form-gasolina').textContent = litros !== null
            ? `${fmt(litros, 1)} L${costo !== null ? ` / $${fmt(costo, 2)}` : ''}`
            : 'No disponible';
    }

    function limpiarForm() {
        document.getElementById('form-viaje').reset();
        limpiarResultadoRuta();
        document.getElementById('f-id_viaje').value = '';
        document.getElementById('f-destino_nombre').placeholder = 'Ej. Hospital Angeles Pedregal';
        document.getElementById('f-origen_nombre').value = MAPA_ORIGEN.nombre || MAPA_ORIGEN.direccion || 'Origen configurado';
        document.getElementById('f-origen_latitud').value = MAPA_ORIGEN.lat || '';
        document.getElementById('f-origen_longitud').value = MAPA_ORIGEN.lng || '';
        document.getElementById('f-velocidad').value = '65';
        document.getElementById('f-rendimiento_km_l').value = RENDIMIENTO_GASOLINA_DEFAULT || 10;
        document.getElementById('f-gasolina_precio_litro').value = PRECIO_GASOLINA_DEFAULT || '';
        document.getElementById('form-titulo').textContent = 'Nuevo viaje';
        document.getElementById('form-msg').textContent = '';
        document.getElementById('form-msg').className = 'msg';
    }

    function abrirModalViaje(nuevo = false) {
        if (nuevo) {
            limpiarForm();
            asegurarOrigenFormulario().catch(() => {});
        }
        overlayViaje.classList.add('abierto');
        setTimeout(() => document.getElementById('f-id_maquina')?.focus(), 30);
    }

    function destinoFormularioTexto() {
        return val('f-destino_direccion').trim() || val('f-destino_nombre').trim();
    }

    function geocodificarDireccion(direccion) {
        return new Promise((resolve, reject) => {
            if (!googleMapsListo || !google.maps.Geocoder) {
                reject(new Error('Google Maps todavia no esta listo.'));
                return;
            }
            const geocoder = new google.maps.Geocoder();
            geocoder.geocode({ address: direccion, region: 'MX' }, (results, status) => {
                if (status === 'OK' && results[0]) {
                    resolve(results[0]);
                    return;
                }
                reject(new Error('No se pudo ubicar esa direccion en Google Maps.'));
            });
        });
    }

    async function asegurarOrigenFormulario() {
        if (numVal('f-origen_latitud') !== null && numVal('f-origen_longitud') !== null) return;
        const direccion = origenDireccionMapa();
        if (!direccion) return;
        const resultado = await geocodificarDireccion(direccion);
        document.getElementById('f-origen_latitud').value = resultado.geometry.location.lat().toFixed(7);
        document.getElementById('f-origen_longitud').value = resultado.geometry.location.lng().toFixed(7);
        if (!val('f-origen_nombre').trim()) {
            document.getElementById('f-origen_nombre').value = MAPA_ORIGEN.nombre || resultado.formatted_address || direccion;
        }
    }

    async function asegurarDestinoFormulario() {
        let lat = numVal('f-destino_latitud');
        let lng = numVal('f-destino_longitud');
        if (lat !== null && lng !== null) return { lat, lng };

        const direccion = destinoFormularioTexto();
        if (!direccion) throw new Error('Captura una direccion o destino para calcular.');
        const resultado = await geocodificarDireccion(direccion);
        lat = resultado.geometry.location.lat();
        lng = resultado.geometry.location.lng();
        document.getElementById('f-destino_latitud').value = lat.toFixed(7);
        document.getElementById('f-destino_longitud').value = lng.toFixed(7);
        if (!val('f-destino_direccion').trim()) {
            document.getElementById('f-destino_direccion').value = resultado.formatted_address || direccion;
        }
        return { lat, lng };
    }

    async function calcularRutaFormulario(detenerEnError = false) {
        const msg = document.getElementById('form-msg');
        const btn = document.getElementById('btn-calcular-form');
        btn.disabled = true;
        msg.textContent = 'Calculando ruta con Google...';
        msg.className = 'msg';
        try {
            await asegurarOrigenFormulario().catch(() => null);
            if (numVal('f-destino_latitud') === null || numVal('f-destino_longitud') === null) {
                await asegurarDestinoFormulario().catch(() => null);
            }
            const res = await fetch('api/distribucion.php?accion=calcular_ruta', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    origen_nombre: MAPA_ORIGEN.direccion || val('f-origen_nombre').trim(),
                    origen_direccion: MAPA_ORIGEN.direccion || val('f-origen_nombre').trim(),
                    origen_latitud: numVal('f-origen_latitud'),
                    origen_longitud: numVal('f-origen_longitud'),
                    destino_nombre: val('f-destino_nombre').trim(),
                    destino_direccion: destinoFormularioTexto(),
                    destino_latitud: numVal('f-destino_latitud'),
                    destino_longitud: numVal('f-destino_longitud'),
                }),
            });
            const json = await res.json();
            if (!json.ok) throw new Error(json.error || 'Google Routes no pudo calcular la ruta.');
            const d = json.data || {};
            document.getElementById('f-distancia_km').value = Number(d.distancia_km || 0).toFixed(1);
            mostrarResumenRuta(d);
            const extras = [];
            if (d.horas) extras.push(fmtDuracion(d.horas));
            if (d.casetas_monto) extras.push(`casetas ${fmt(d.casetas_monto, 2)} ${d.casetas_moneda || 'MXN'}`);
            if (d.gasolina_litros) extras.push(`gasolina ${fmt(d.gasolina_litros, 1)} L`);
            msg.textContent = `Ruta aproximada calculada: ${fmt(d.distancia_km, 1)} km${extras.length ? ' / ' + extras.join(' / ') : ''}.`;
            msg.className = 'msg ok';
        } catch (e) {
            const origenLat = numVal('f-origen_latitud');
            const origenLng = numVal('f-origen_longitud');
            const destinoLat = numVal('f-destino_latitud');
            const destinoLng = numVal('f-destino_longitud');
            if (origenLat !== null && origenLng !== null && destinoLat !== null && destinoLng !== null) {
                const km = distanciaKmLocal(origenLat, origenLng, destinoLat, destinoLng);
                document.getElementById('f-distancia_km').value = km.toFixed(1);
                mostrarResumenRuta({ distancia_km: km });
                msg.textContent = `Google Routes no devolvio ruta; use estimacion por coordenadas: ${fmt(km, 1)} km.`;
                msg.className = 'msg ok';
                return;
            }
            msg.textContent = `${e.message}. Captura una ubicacion y una direccion completa de destino.`;
            msg.className = 'msg error';
            if (detenerEnError && !val('f-distancia_km')) throw e;
        } finally {
            btn.disabled = false;
        }
    }

    function cerrarModalViaje() {
        overlayViaje.classList.remove('abierto');
    }

    function editarViaje(id) {
        const v = viajes.find(x => Number(x.id_viaje) === id);
        if (!v) return;
        document.getElementById('form-titulo').textContent = 'Editar viaje';
        document.getElementById('f-id_viaje').value = v.id_viaje;
        document.getElementById('f-id_maquina').value = v.id_maquina;
        document.getElementById('f-origen_nombre').value = v.origen_nombre || '';
        document.getElementById('f-origen_latitud').value = v.origen_latitud ?? '';
        document.getElementById('f-origen_longitud').value = v.origen_longitud ?? '';
        document.getElementById('f-destino_nombre').value = v.destino_nombre || '';
        document.getElementById('f-destino_direccion').value = v.destino_direccion || '';
        document.getElementById('f-destino_latitud').value = v.destino_latitud ?? '';
        document.getElementById('f-destino_longitud').value = v.destino_longitud ?? '';
        document.getElementById('f-fecha_salida').value = paraInputFecha(v.fecha_salida_programada);
        document.getElementById('f-fecha_limite').value = paraInputFecha(v.fecha_limite);
        document.getElementById('f-distancia_km').value = v.distancia_km ?? '';
        document.getElementById('f-velocidad').value = v.velocidad_promedio_kmh ?? '65';
        document.getElementById('f-responsable').value = v.responsable || '';
        document.getElementById('f-estatus').value = v.estatus || 'programado';
        document.getElementById('f-notas').value = v.notas || '';
        document.getElementById('f-rendimiento_km_l').value = v.rendimiento_km_l ?? RENDIMIENTO_GASOLINA_DEFAULT ?? 10;
        document.getElementById('f-gasolina_precio_litro').value = v.gasolina_precio_litro ?? PRECIO_GASOLINA_DEFAULT ?? '';
        mostrarResumenRuta({
            distancia_km: v.distancia_km,
            horas_google: v.horas_google,
            casetas_monto: v.casetas_monto,
            casetas_moneda: v.casetas_moneda,
            gasolina_litros: v.gasolina_litros,
            ruta_polyline: v.ruta_polyline,
        });
        abrirModalViaje(false);
    }

    async function guardarViaje(ev) {
        ev.preventDefault();
        const msg = document.getElementById('form-msg');
        const btn = document.getElementById('btn-guardar');
        btn.disabled = true;
        msg.textContent = 'Guardando...';
        msg.className = 'msg';
        if ((!val('f-distancia_km') || !val('f-destino_latitud') || !val('f-destino_longitud')) && destinoFormularioTexto()) {
            try {
                await calcularRutaFormulario(true);
                msg.textContent = 'Guardando...';
                msg.className = 'msg';
            } catch (e) {
                if (!val('f-distancia_km')) {
                    msg.textContent = `${e.message}. Revisa la direccion de destino y vuelve a calcular la ruta.`;
                    msg.className = 'msg error';
                    btn.disabled = false;
                    return;
                }
            }
        }
        const payload = {
            id_viaje: numVal('f-id_viaje'),
            id_maquina: numVal('f-id_maquina'),
            origen_nombre: val('f-origen_nombre').trim(),
            origen_latitud: numVal('f-origen_latitud'),
            origen_longitud: numVal('f-origen_longitud'),
            destino_nombre: val('f-destino_nombre').trim(),
            destino_direccion: val('f-destino_direccion').trim(),
            destino_latitud: numVal('f-destino_latitud'),
            destino_longitud: numVal('f-destino_longitud'),
            fecha_salida_programada: val('f-fecha_salida') || null,
            fecha_limite: val('f-fecha_limite'),
            distancia_km: numVal('f-distancia_km'),
            horas_google: numVal('f-horas_google'),
            casetas_monto: numVal('f-casetas_monto'),
            casetas_moneda: val('f-casetas_moneda'),
            gasolina_litros: numVal('f-gasolina_litros'),
            rendimiento_km_l: numVal('f-rendimiento_km_l'),
            gasolina_precio_litro: numVal('f-gasolina_precio_litro'),
            gasolina_costo: numVal('f-gasolina_costo'),
            ruta_polyline: val('f-ruta_polyline'),
            velocidad_promedio_kmh: numVal('f-velocidad') || 65,
            responsable: val('f-responsable').trim(),
            estatus: val('f-estatus'),
            notas: val('f-notas').trim(),
        };
        try {
            const res = await fetch('api/distribucion.php?accion=guardar', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            });
            const json = await res.json();
            if (!json.ok) throw new Error(json.error || 'No se pudo guardar');
            msg.textContent = `Viaje ${json.data.accion}.`;
            msg.className = 'msg ok';
            limpiarForm();
            await actualizar();
            cerrarModalViaje();
        } catch (e) {
            msg.textContent = e.message;
            msg.className = 'msg error';
        } finally {
            btn.disabled = false;
        }
    }

    async function cambiarEstatus(id, estatus, boton = null) {
        const viaje = viajes.find(item => Number(item.id_viaje) === Number(id));
        if (estatus === 'completado') {
            const destino = viaje?.destino_nombre || 'el destino registrado';
            const confirmado = await mlConfirm(
                `Se marcará el viaje como completado y la ubicación de la máquina cambiará a ${destino}.`,
                'Completar viaje',
                'Sí, completar'
            );
            if (!confirmado) return;
        }
        if (boton) boton.disabled = true;
        try {
            const res = await fetch('api/distribucion.php?accion=estatus', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id_viaje: id, estatus }),
            });
            const json = await res.json();
            if (!json.ok) throw new Error(json.error || 'No se pudo actualizar');
            await actualizar();
        } catch (e) {
            await mlAlert(e.message || 'No se pudo actualizar');
        } finally {
            if (boton?.isConnected) boton.disabled = false;
        }
    }

    async function cargarRuta() {
        const cont = document.getElementById('ruta-contenido');
        const btn = document.getElementById('btn-trazar-mapa');
        btn.disabled = true;
        cont.className = 'ruta-panel-lateral';
        cont.innerHTML = '<p class="cargando">Calculando...</p>';
        try {
            const json = await obtenerJSON('api/distribucion.php?accion=ruta');
            const data = json.data || [];
            const meta = json.meta || {};
            rutaRecomendadaActual = data;
            metaRutaRecomendadaActual = meta;
            if (!data.length) {
                document.getElementById('ruta-meta').textContent = 'No hay paradas pendientes.';
                cont.innerHTML = '<p class="vacio">No hay viajes activos con destino calculado.</p>';
                await pintarMapaViajes(viajes);
                return;
            }
            const totalKm = meta.total_km ?? data[data.length - 1].ruta_total_km;
            const totalHoras = meta.total_horas ?? data[data.length - 1].ruta_total_horas;
            const proveedor = meta.proveedor === 'google_routes' ? 'Google Routes' : 'calculo local';
            const casetas = meta.casetas_monto !== null && meta.casetas_monto !== undefined ? `${fmt(meta.casetas_monto, 2)} ${escapeHtml(meta.casetas_moneda || 'MXN')}` : (meta.casetas_detectadas ? 'Sin precio' : '-');
            const gasolina = meta.gasolina_litros !== null && meta.gasolina_litros !== undefined ? `${fmt(meta.gasolina_litros, 1)} L` : '-';
            const gasolinaCosto = meta.gasolina_costo_mxn !== null && meta.gasolina_costo_mxn !== undefined ? `$${fmt(meta.gasolina_costo_mxn, 2)}` : '-';
            document.getElementById('ruta-meta').textContent = `${proveedor}: ${fmt(totalKm, 1)} km${totalHoras ? ' / ' + fmtDuracion(totalHoras) : ''} / aproximado`;
            cont.innerHTML = `<div class="ruta-resumen">
                <div class="ruta-metrica"><div class="lbl">Kilometros</div><div class="num">${fmt(totalKm, 1)} km</div></div>
                <div class="ruta-metrica"><div class="lbl">Tiempo</div><div class="num">${fmtDuracion(totalHoras)}</div></div>
                <div class="ruta-metrica"><div class="lbl">Casetas Google</div><div class="num">${casetas}</div></div>
                <div class="ruta-metrica"><div class="lbl">Gasolina estimada</div><div class="num">${gasolina}${gasolinaCosto !== '-' ? ' / ' + gasolinaCosto : ''}</div></div>
            </div><div class="ruta-lista">${data.map((v, i) => `
                <div class="ruta-item">
                    <div class="ruta-num">${i + 1}</div>
                    <div>
                        <b>${escapeHtml(v.destino_nombre)}</b> <span class="mono mini">${escapeHtml(v.numero_serie)}</span>
                        <div class="mini">${badgeEstado(v)} / tramo ${v.tramo_km ? fmt(v.tramo_km, 1) + ' km' : 'sin tramo'}${v.tramo_horas ? ' / ' + fmtDuracion(v.tramo_horas) : ''} / limite ${fechaLocal(v.fecha_limite)}</div>
                    </div>
                    <div class="mono">${v.ruta_total_km ? fmt(v.ruta_total_km, 1) + ' km' : '-'}</div>
                </div>`).join('')}</div>`;
            await pintarMapaViajes(data);
            await trazarRutaEnMapa(data, meta);
        } catch (e) {
            rutaRecomendadaActual = [];
            metaRutaRecomendadaActual = {};
            cont.innerHTML = '<p class="vacio">No se pudo calcular la ruta.</p>';
            actualizarEstadoMapa(e.message || 'No se pudo calcular la ruta recomendada.');
        } finally {
            btn.disabled = false;
        }
    }
    async function actualizar() {
        await Promise.all([cargarResumen(), cargarViajes()]);
        await cargarRuta();
    }

    document.getElementById('form-viaje').addEventListener('submit', guardarViaje);
    document.getElementById('btn-limpiar').addEventListener('click', limpiarForm);
    document.getElementById('btn-calcular-form').addEventListener('click', () => calcularRutaFormulario(false));
    document.getElementById('btn-nuevo-viaje')?.addEventListener('click', () => abrirModalViaje(true));
    document.getElementById('modal-cerrar').addEventListener('click', cerrarModalViaje);
    document.getElementById('btn-actualizar').addEventListener('click', actualizar);
    document.getElementById('btn-centrar-mapa').addEventListener('click', centrarMapaEnOrigen);
    document.getElementById('btn-trazar-mapa').addEventListener('click', cargarRuta);
    document.getElementById('f-id_maquina').addEventListener('change', () => {
        limpiarResultadoRuta(true);
        completarDestinoDesdeMaquina();
        asegurarOrigenFormulario().catch(() => {});
        if (googleMapsListo && destinoFormularioTexto()) {
            calcularRutaFormulario().catch(() => {});
        }
    });
    ['f-destino_nombre', 'f-destino_direccion'].forEach(id => {
        document.getElementById(id).addEventListener('input', () => {
            document.getElementById('f-destino_latitud').value = '';
            document.getElementById('f-destino_longitud').value = '';
            limpiarResultadoRuta(true);
        });
    });
    ['f-rendimiento_km_l', 'f-gasolina_precio_litro', 'f-distancia_km'].forEach(id => {
        document.getElementById(id).addEventListener('input', recalcularEstimacionCombustible);
    });
    document.getElementById('filtro-estado').addEventListener('change', cargarViajes);
    document.getElementById('buscar').addEventListener('input', () => {
        clearTimeout(debounce);
        debounce = setTimeout(cargarViajes, 300);
    });

    if (!ES_ADMIN) {
        document.getElementById('form-viaje').querySelectorAll('input, select, textarea, button').forEach(el => el.disabled = true);
        document.getElementById('form-msg').textContent = 'Solo administradores pueden programar viajes.';
    }

    cargarGoogleMaps();
    cargarMaquinas().then(() => {
        limpiarForm();
        actualizar();
    });
</script>
</body>
</html>

