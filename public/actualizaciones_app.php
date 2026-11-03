<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/bootstrap.php';

use App\Actualizaciones\AppActualizaciones;
use App\Auth\Auth;

Auth::requireAdmin();
$canalServidor = AppActualizaciones::canalServidor($_GET['canal_app'] ?? null);
$canalEtiqueta = $canalServidor === AppActualizaciones::CANAL_MOSI ? 'MOSI' : 'Clientes';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>MDM <?= htmlspecialchars($canalEtiqueta, ENT_QUOTES, 'UTF-8') ?> - Actualizaciones app</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/estilo.css?v=20261002-8">
    <script src="assets/js/vendor/chart.min.js"></script>
    <style>
        body.tema-oscuro {
            --bg: #F3F6F8;
            --bg-elevada: #FFFFFF;
            --bg-soft: #F8FAFB;
            --bg-hover: #EEF5F6;
            --linea: #D6E0E6;
            --linea-suave: #E8EEF2;
            --texto: #14212B;
            --texto-suave: #38505F;
            --texto-tenue: #6A7A86;
            --petroleo: #087D76;
            --petroleo-dark: #075E59;
            --petroleo-wash: rgba(8,125,118,.11);
            --azul: #2563B8;
            --azul-wash: rgba(37,99,184,.10);
            --ambar: #9A620B;
            --ambar-wash: rgba(154,98,11,.13);
            --critico: #B42335;
            --critico-wash: rgba(180,35,53,.10);
            --shadow: 0 18px 46px rgba(20,33,43,.09);
            --shadow-soft: 0 10px 26px rgba(20,33,43,.07);
            background:
                linear-gradient(180deg, rgba(8,125,118,.08), rgba(8,125,118,0) 280px),
                radial-gradient(circle at 92% 0%, rgba(37,99,184,.10), rgba(37,99,184,0) 320px),
                var(--bg);
            color: var(--texto);
        }
        .principal.actualizaciones-principal { max-width: 1360px; width: 100%; }
        .encabezado-pagina {
            display: flex;
            justify-content: space-between;
            gap: 18px;
            align-items: flex-start;
            margin-bottom: 18px;
            padding: 4px 0 2px;
        }
        .encabezado-pagina__etiqueta {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            color: var(--petroleo);
            font-size: 11px;
            letter-spacing: .08em;
            text-transform: uppercase;
            font-weight: 800;
            margin-bottom: 9px;
        }
        .encabezado-pagina__etiqueta::before {
            content: "";
            width: 8px;
            height: 8px;
            border-radius: 99px;
            background: var(--petroleo);
            box-shadow: 0 0 0 5px var(--petroleo-wash);
        }
        h1 { margin: 0 0 6px; font-size: 30px; line-height: 1.04; color: var(--texto); letter-spacing: 0; }
        p { margin: 0; color: var(--texto-suave); font-size: 13px; line-height: 1.5; }
        .acciones-encabezado { display: flex; align-items: center; justify-content: flex-end; gap: 10px; flex-wrap: wrap; }
        .actualizaciones-hero {
            display: grid;
            grid-template-columns: minmax(0, 1.25fr) minmax(300px, .75fr);
            gap: 14px;
            margin-bottom: 14px;
            align-items: stretch;
        }
        .hero-copy {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 18px;
            align-items: end;
            margin-bottom: 14px;
        }
        .hero-copy h1 { max-width: 760px; }
        .hero-copy p { max-width: 720px; }
        .grid { display: grid; grid-template-columns: minmax(360px, .72fr) minmax(0, 1.28fr); gap: 14px; align-items: start; }
        .panel, .stat {
            background: rgba(255,255,255,.92);
            border: 1px solid rgba(214,224,230,.96);
            border-radius: 8px;
            box-shadow: var(--shadow-soft);
        }
        .panel { overflow: hidden; }
        .panel-head {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            align-items: center;
            padding: 17px 18px;
            border-bottom: 1px solid var(--linea-suave);
            background: linear-gradient(180deg, #FFFFFF, #FAFCFD);
        }
        .panel-head h2 { margin: 0; font-size: 15.5px; color: var(--texto); letter-spacing: 0; }
        .panel-head .sub { color: var(--texto-tenue); font-size: 12px; margin-top: 4px; }
        .formulario { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; padding: 18px; }
        .formulario .full { grid-column: 1 / -1; }
        label { display: block; font-size: 11px; font-weight: 800; color: var(--texto-suave); margin-bottom: 7px; text-transform: uppercase; letter-spacing: .045em; }
        input, textarea, select {
            width: 100%;
            box-sizing: border-box;
            border: 1px solid #C9D5DD;
            border-radius: 8px;
            background: #fff;
            color: var(--texto);
            font: inherit;
            font-size: 13px;
            padding: 10px 11px;
            transition: border-color .16s ease, box-shadow .16s ease, background .16s ease;
        }
        input:focus, textarea:focus, select:focus {
            outline: none;
            border-color: rgba(8,125,118,.72);
            box-shadow: 0 0 0 4px rgba(8,125,118,.10);
        }
        input[readonly] { background: #F5F8FA; color: var(--texto-tenue); }
        textarea { min-height: 98px; resize: vertical; }
        input[type="datetime-local"] { min-height: 40px; }
        input[type="checkbox"] { width: auto; accent-color: var(--petroleo); }
        input[type="file"] {
            padding: 13px;
            background: linear-gradient(180deg, #FFFFFF, #F8FBFC);
            border-style: dashed;
        }
        .hint { margin-top: 7px; color: var(--texto-tenue); font-size: 12px; }
        .checks { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        .check-row {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin: 0;
            padding: 8px 10px;
            border: 1px solid var(--linea-suave);
            border-radius: 8px;
            background: var(--bg-soft);
            font-size: 12px;
            color: var(--texto-suave);
            font-weight: 800;
            text-transform: none;
            letter-spacing: 0;
        }
        .btn {
            min-height: 38px;
            border: 1px solid #C8D3DC;
            background: #fff;
            color: var(--texto);
            border-radius: 8px;
            padding: 9px 13px;
            font: inherit;
            font-size: 13px;
            font-weight: 800;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: transform .16s ease, border-color .16s ease, box-shadow .16s ease, background .16s ease, color .16s ease;
            white-space: nowrap;
        }
        .btn svg { width: 16px; height: 16px; }
        .btn:hover { transform: translateY(-1px); border-color: var(--petroleo); color: var(--petroleo); background: #F2F8F7; box-shadow: 0 8px 18px rgba(20,33,43,.08); }
        .btn:disabled { opacity: .68; cursor: wait; transform: none; box-shadow: none; }
        .btn.primario { border-color: var(--petroleo); background: var(--petroleo); color: #fff; box-shadow: 0 10px 20px rgba(8,125,118,.18); }
        .btn.primario:hover { color: #fff; background: var(--petroleo-dark); }
        .btn.peligro:hover { border-color: var(--critico); color: var(--critico); background: #FFF6F7; }
        .btn.oscuro { background: #12212A; border-color: #12212A; color: #fff; }
        .btn.oscuro:hover { background: #1B303B; border-color: #1B303B; color: #fff; }
        .form-pie { grid-column: 1 / -1; display: flex; align-items: center; justify-content: space-between; gap: 10px; padding-top: 2px; }
        .mensaje { color: var(--texto-tenue); font-size: 12px; line-height: 1.4; }
        .mensaje.ok { color: var(--petroleo); font-weight: 700; }
        .mensaje.error { color: var(--critico); font-weight: 700; }
        .stats { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
        .stat {
            min-height: 108px;
            padding: 14px;
            position: relative;
            overflow: hidden;
        }
        .stat::after {
            content: "";
            position: absolute;
            inset: auto 12px 12px auto;
            width: 48px;
            height: 48px;
            border-radius: 8px;
            background: var(--petroleo-wash);
        }
        .stat .stat-icon {
            position: relative;
            z-index: 1;
            width: 30px;
            height: 30px;
            display: grid;
            place-items: center;
            border-radius: 8px;
            color: var(--petroleo);
            background: var(--petroleo-wash);
            margin-bottom: 12px;
        }
        .stat .stat-icon svg { width: 16px; height: 16px; }
        .stat.stat-info .stat-icon, .stat.stat-info::after { color: var(--azul); background: var(--azul-wash); }
        .stat.stat-ok .stat-icon, .stat.stat-ok::after { color: var(--petroleo); background: var(--petroleo-wash); }
        .stat.stat-danger .stat-icon, .stat.stat-danger::after { color: var(--critico); background: var(--critico-wash); }
        .stat .lbl { color: var(--texto-tenue); font-size: 10.5px; text-transform: uppercase; letter-spacing: .055em; font-weight: 800; margin-bottom: 4px; }
        .stat .num { font-family: 'Space Grotesk', sans-serif; font-size: 31px; line-height: 1; font-weight: 700; color: var(--texto); letter-spacing: 0; }
        .version-activa {
            min-height: 320px;
            padding: 24px;
            background:
                linear-gradient(135deg, rgba(18,33,42,.98), rgba(8,91,88,.94) 52%, rgba(37,99,184,.90)),
                #12212A;
            border: 1px solid rgba(255,255,255,.18);
            border-radius: 8px;
            box-shadow: 0 24px 58px rgba(18,33,42,.24);
            position: relative;
            overflow: hidden;
            color: #fff;
        }
        .version-activa::after {
            content: "";
            position: absolute;
            top: -70px;
            right: -40px;
            width: 220px;
            height: 220px;
            border-radius: 999px;
            background: rgba(255,255,255,.12);
        }
        .version-activa::before {
            content: "";
            position: absolute;
            left: 24px;
            right: 24px;
            bottom: 0;
            height: 4px;
            border-radius: 99px 99px 0 0;
            background: linear-gradient(90deg, #79E0D7, #9DBDFF, #F7C879);
        }
        .version-activa__head { position: relative; z-index: 1; display: flex; align-items: flex-start; justify-content: space-between; gap: 18px; margin-bottom: 22px; }
        .version-activa__titulo { margin: 0 0 9px; font-size: 12px; color: rgba(255,255,255,.72); text-transform: uppercase; letter-spacing: .08em; }
        .version-activa__head p { color: rgba(255,255,255,.72); }
        .version-activa__version { font-family: 'Space Grotesk', sans-serif; font-size: 56px; font-weight: 700; line-height: .96; color: #fff; letter-spacing: 0; }
        .version-activa__grid { position: relative; z-index: 1; display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; }
        .version-activa__dato {
            min-width: 0;
            padding: 11px 12px;
            border: 1px solid rgba(255,255,255,.14);
            border-radius: 8px;
            background: rgba(255,255,255,.10);
            backdrop-filter: blur(10px);
        }
        .version-activa__dato .lbl { color: rgba(255,255,255,.60); font-size: 9.5px; text-transform: uppercase; letter-spacing: .06em; font-weight: 800; margin-bottom: 5px; }
        .version-activa__dato .val { color: #fff; font-size: 12.5px; overflow-wrap: anywhere; }
        .version-activa__dato .muted { color: rgba(255,255,255,.64); }
        .version-activa__dato a { color: #D6FFFA; text-decoration-color: rgba(214,255,250,.35); }
        .version-activa__dato.full { grid-column: 1 / -1; }
        .version-activa__impacto {
            position: relative;
            z-index: 1;
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 10px;
            margin-top: 12px;
        }
        .impacto-dato {
            padding: 12px;
            border-radius: 8px;
            background: rgba(255,255,255,.13);
            border: 1px solid rgba(255,255,255,.14);
        }
        .impacto-dato .lbl { color: rgba(255,255,255,.62); font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: .06em; }
        .impacto-dato .num { color: #fff; font-family: 'Space Grotesk', sans-serif; font-size: 26px; font-weight: 700; line-height: 1.1; margin-top: 3px; }
        .graficas-grid { display: grid; grid-template-columns: minmax(300px, .85fr) minmax(0, 1.15fr); gap: 14px; margin-bottom: 14px; }
        .grafica-card { min-height: 340px; }
        .grafica-body { display: grid; min-height: 270px; padding: 20px; }
        .grafica-body canvas { width: 100% !important; }
        .adopcion-centro {
            align-self: center;
            justify-self: center;
            width: min(100%, 460px);
            display: grid;
            grid-template-columns: 190px minmax(0, 1fr);
            gap: 24px;
            align-items: center;
        }
        #grafica-adopcion {
            height: 190px !important;
            max-height: 190px;
        }
        #grafica-eventos {
            min-height: 230px;
        }
        .adopcion-copy .porcentaje { font-family: 'Space Grotesk', sans-serif; font-size: 46px; line-height: 1; font-weight: 700; color: var(--texto); }
        .adopcion-copy .texto { color: var(--texto-suave); font-size: 13px; line-height: 1.45; margin-top: 6px; }
        .adopcion-leyenda { display: grid; gap: 7px; margin-top: 15px; }
        .adopcion-leyenda__fila {
            display: grid; grid-template-columns: 9px minmax(0, 1fr) auto; gap: 8px; align-items: center;
            padding: 7px 0; border-bottom: 1px solid var(--linea-suave); color: var(--texto-suave); font-size: 11px;
        }
        .adopcion-leyenda__fila:last-child { border-bottom: 0; }
        .adopcion-leyenda__fila i { width: 8px; height: 8px; border-radius: 2px; background: var(--color); }
        .adopcion-leyenda__fila b { font-family: 'JetBrains Mono', monospace; font-size: 12px; }
        .chart-periodo {
            flex: 0 0 auto; padding: 5px 8px; border: 1px solid var(--linea); border-radius: 5px;
            color: var(--texto-suave); font-size: 10px; font-weight: 800; text-transform: uppercase;
        }
        .bitacora-cta {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 16px;
            align-items: center;
            padding: 18px;
            background: linear-gradient(135deg, #FFFFFF, #F4FAF9);
        }
        .bitacora-icono {
            width: 42px;
            height: 42px;
            display: grid;
            place-items: center;
            border-radius: 8px;
            color: var(--petroleo);
            background: var(--petroleo-wash);
            margin-bottom: 10px;
        }
        .bitacora-icono svg { width: 21px; height: 21px; }
        .bitacora-cta h2 { margin: 0 0 5px; font-size: 16px; letter-spacing: 0; }
        .bitacora-preview { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 12px; }
        .table-shell { overflow: auto; scrollbar-color: #AFC0C9 #F3F7F8; }
        table { width: 100%; min-width: 850px; border-collapse: separate; border-spacing: 0; font-size: 13px; }
        th, td { padding: 12px 14px; border-bottom: 1px solid var(--linea-suave); text-align: left; vertical-align: top; }
        th {
            position: sticky;
            top: 0;
            z-index: 1;
            background: #F7FAFB;
            color: var(--texto-tenue);
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: .06em;
            font-weight: 800;
        }
        tbody tr { background: #fff; }
        tbody tr:hover { background: var(--bg-hover); }
        tbody tr.row-error { box-shadow: inset 4px 0 0 var(--critico); }
        tbody tr.row-ok { box-shadow: inset 4px 0 0 var(--petroleo); }
        tbody tr.row-warn { box-shadow: inset 4px 0 0 var(--ambar); }
        td strong { color: var(--texto); }
        td a {
            color: var(--petroleo-dark);
            font-weight: 800;
            text-decoration-color: rgba(8,125,118,.28);
            text-underline-offset: 3px;
        }
        td a:hover { color: var(--azul); text-decoration-color: rgba(37,99,184,.42); }
        .mono { font-family: 'JetBrains Mono', monospace; font-size: 12px; }
        .muted { color: var(--texto-tenue); font-size: 12px; line-height: 1.45; }
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            border-radius: 999px;
            padding: 4px 9px;
            margin: 0 4px 4px 0;
            font-size: 10.5px;
            font-weight: 800;
            line-height: 1;
            white-space: nowrap;
            border: 1px solid transparent;
        }
        .badge-ok { background: var(--petroleo-wash); color: var(--petroleo-dark); border-color: rgba(8,125,118,.16); }
        .badge-info { background: var(--azul-wash); color: var(--azul); border-color: rgba(37,99,184,.16); }
        .badge-warn { background: var(--ambar-wash); color: var(--ambar); border-color: rgba(154,98,11,.16); }
        .badge-danger { background: var(--critico-wash); color: var(--critico); border-color: rgba(180,35,53,.16); }
        .acciones-tabla { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; min-width: 250px; }
        .acciones-tabla label { margin: 0; }
        .fecha-mini { width: 190px; padding: 7px 8px; font-size: 12px; }
        .seguimiento-toolbar { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .seguimiento-toolbar select { width: auto; min-width: 240px; }
        .seguimiento-resumen {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 10px;
            padding: 14px 18px;
            border-bottom: 1px solid var(--linea-suave);
            background: #FBFCFD;
        }
        .seguimiento-resumen .mini-stat { background: #fff; border: 1px solid var(--linea-suave); border-radius: 8px; padding: 11px 12px; }
        .seguimiento-resumen .mini-stat[data-estado="pendiente"] { border-color: rgba(37,99,184,.18); background: linear-gradient(180deg, rgba(37,99,184,.06), #fff); }
        .seguimiento-resumen .mini-stat[data-estado="descargando"] { border-color: rgba(154,98,11,.18); background: linear-gradient(180deg, rgba(154,98,11,.07), #fff); }
        .seguimiento-resumen .mini-stat[data-estado="instalada"] { border-color: rgba(8,125,118,.18); background: linear-gradient(180deg, rgba(8,125,118,.07), #fff); }
        .seguimiento-resumen .mini-stat[data-estado="error"] { border-color: rgba(180,35,53,.18); background: linear-gradient(180deg, rgba(180,35,53,.07), #fff); }
        .seguimiento-resumen .mini-stat .lbl { color: var(--texto-tenue); font-size: 10px; text-transform: uppercase; letter-spacing: .06em; font-weight: 800; }
        .seguimiento-resumen .mini-stat .num { font-family: 'Space Grotesk', sans-serif; font-size: 24px; line-height: 1.08; font-weight: 800; color: var(--texto); letter-spacing: 0; }
        details.detalle-json { margin-top: 6px; }
        details.detalle-json summary { cursor: pointer; color: var(--azul); font-size: 12px; font-weight: 800; }
        .json-box { max-height: 260px; overflow: auto; margin: 8px 0 0; padding: 10px; border: 1px solid var(--linea-suave); border-radius: 8px; background: #F8FAFC; color: var(--texto); white-space: pre-wrap; overflow-wrap: anywhere; }
        .vacio, .cargando { color: var(--texto-tenue); font-style: italic; padding: 32px 14px; text-align: center; }
        @media (max-width: 1180px) {
            .actualizaciones-hero, .grid, .graficas-grid { grid-template-columns: 1fr; }
            .stats { grid-template-columns: repeat(4, minmax(0, 1fr)); }
        }
        @media (max-width: 820px) {
            .encabezado-pagina, .hero-copy, .panel-head, .form-pie, .bitacora-cta { grid-template-columns: 1fr; flex-direction: column; align-items: stretch; }
            .acciones-encabezado, .seguimiento-toolbar { justify-content: stretch; }
            .acciones-encabezado .btn, .seguimiento-toolbar .btn, .seguimiento-toolbar select, .bitacora-cta .btn { width: 100%; }
            .stats, .seguimiento-resumen { grid-template-columns: 1fr 1fr; }
            .formulario { grid-template-columns: 1fr; }
            .version-activa__grid { grid-template-columns: 1fr 1fr; }
            .version-activa__impacto, .adopcion-centro { grid-template-columns: 1fr; }
            .version-activa__version { font-size: 40px; }
        }
        @media (max-width: 560px) {
            h1 { font-size: 25px; }
            .stats, .seguimiento-resumen, .version-activa__grid { grid-template-columns: 1fr; }
        }

        /* Modo terminal: oscuro, tecnico y legible. */
        body.tema-oscuro {
            --bg: #070C10;
            --bg-elevada: #0D151B;
            --bg-soft: #101B22;
            --bg-hover: #13252D;
            --linea: rgba(119, 239, 226, .18);
            --linea-suave: rgba(119, 239, 226, .10);
            --texto: #EAF7F6;
            --texto-suave: #B6C8C7;
            --texto-tenue: #7F9694;
            --petroleo: #37F0D2;
            --petroleo-dark: #89FFF1;
            --petroleo-wash: rgba(55, 240, 210, .13);
            --azul: #78A7FF;
            --azul-wash: rgba(120, 167, 255, .13);
            --ambar: #F5C15B;
            --ambar-wash: rgba(245, 193, 91, .14);
            --critico: #FF6B86;
            --critico-wash: rgba(255, 107, 134, .13);
            --shadow: 0 22px 58px rgba(0, 0, 0, .40);
            --shadow-soft: 0 16px 34px rgba(0, 0, 0, .30);
            background:
                linear-gradient(rgba(119,239,226,.035) 1px, transparent 1px),
                linear-gradient(90deg, rgba(119,239,226,.025) 1px, transparent 1px),
                radial-gradient(circle at 80% -10%, rgba(55,240,210,.18), transparent 360px),
                radial-gradient(circle at 12% 12%, rgba(120,167,255,.12), transparent 320px),
                #070C10;
            background-size: 36px 36px, 36px 36px, auto, auto, auto;
        }
        body.tema-oscuro .sidebar {
            background: rgba(8, 14, 18, .96);
            border-right-color: rgba(119,239,226,.14);
            box-shadow: 14px 0 42px rgba(0,0,0,.24);
        }
        body.tema-oscuro .nav-lateral a,
        body.tema-oscuro .nav-grupo summary { color: #8FA8A6; }
        body.tema-oscuro .nav-lateral a:hover,
        body.tema-oscuro .nav-grupo summary:hover {
            background: rgba(55,240,210,.08);
            color: #EAF7F6;
        }
        body.tema-oscuro .nav-lateral a.activa {
            background: rgba(55,240,210,.14);
            color: #89FFF1;
            box-shadow: inset 3px 0 0 #37F0D2;
        }
        .hero-copy {
            padding: 18px;
            border: 1px solid rgba(119,239,226,.15);
            border-radius: 8px;
            background: linear-gradient(135deg, rgba(13,21,27,.88), rgba(10,16,21,.72));
            box-shadow: var(--shadow-soft);
        }
        .encabezado-pagina__etiqueta { color: #37F0D2; font-family: 'JetBrains Mono', monospace; }
        .encabezado-pagina__etiqueta::before { background: #37F0D2; box-shadow: 0 0 16px rgba(55,240,210,.65), 0 0 0 5px rgba(55,240,210,.12); }
        h1 { color: #F4FFFE; text-shadow: 0 0 24px rgba(55,240,210,.18); }
        p { color: #AFC4C2; }
        .panel, .stat {
            background: linear-gradient(180deg, rgba(14,25,32,.96), rgba(9,16,21,.96));
            border-color: rgba(119,239,226,.15);
            box-shadow: var(--shadow-soft);
        }
        .panel-head {
            background: linear-gradient(180deg, rgba(18,33,41,.96), rgba(12,21,28,.96));
            border-bottom-color: rgba(119,239,226,.12);
        }
        .panel-head h2 { color: #F1FFFE; }
        .panel-head .sub, .muted, .hint { color: #83A09D; }
        input, textarea, select {
            background: #091116;
            border-color: rgba(119,239,226,.20);
            color: #EAF7F6;
        }
        input::placeholder, textarea::placeholder { color: #5E7674; }
        input:focus, textarea:focus, select:focus {
            border-color: #37F0D2;
            box-shadow: 0 0 0 1px rgba(55,240,210,.45), 0 0 24px rgba(55,240,210,.13);
        }
        input[readonly], input[type="file"] { background: #0B151B; color: #9BB2B0; }
        label { color: #9EB8B5; font-family: 'JetBrains Mono', monospace; }
        .check-row {
            background: rgba(55,240,210,.06);
            border-color: rgba(119,239,226,.16);
            color: #D6EAE8;
        }
        .btn {
            background: #0B151B;
            border-color: rgba(119,239,226,.22);
            color: #DDF8F5;
        }
        .btn:hover {
            background: rgba(55,240,210,.10);
            border-color: #37F0D2;
            color: #89FFF1;
            box-shadow: 0 0 0 1px rgba(55,240,210,.22), 0 12px 28px rgba(0,0,0,.28);
        }
        .btn.primario, .btn.oscuro {
            background: linear-gradient(135deg, #0B6E68, #1445A0);
            border-color: rgba(119,239,226,.40);
            color: #FFFFFF;
            box-shadow: 0 0 28px rgba(55,240,210,.18);
        }
        .btn.peligro:hover { background: rgba(255,107,134,.10); border-color: #FF6B86; color: #FFD7DE; }
        .stat::after { background: rgba(55,240,210,.08); box-shadow: 0 0 28px rgba(55,240,210,.12); }
        .stat .stat-icon { background: rgba(55,240,210,.10); color: #37F0D2; border: 1px solid rgba(55,240,210,.18); }
        .stat.stat-info .stat-icon, .stat.stat-info::after { color: #78A7FF; background: rgba(120,167,255,.12); }
        .stat.stat-ok .stat-icon, .stat.stat-ok::after { color: #37F0D2; background: rgba(55,240,210,.12); }
        .stat.stat-danger .stat-icon, .stat.stat-danger::after { color: #FF6B86; background: rgba(255,107,134,.12); }
        .stat .num, .adopcion-copy .porcentaje, .seguimiento-resumen .mini-stat .num { color: #F4FFFE; }
        .stat .lbl { color: #8BA5A2; font-family: 'JetBrains Mono', monospace; }
        .version-activa {
            background:
                linear-gradient(135deg, rgba(6,12,16,.98), rgba(8,38,38,.96) 50%, rgba(10,35,70,.94)),
                #070C10;
            border-color: rgba(55,240,210,.28);
            box-shadow: 0 0 0 1px rgba(55,240,210,.12), 0 30px 70px rgba(0,0,0,.50);
        }
        .version-activa::after {
            background: radial-gradient(circle, rgba(55,240,210,.20), transparent 68%);
            box-shadow: 0 0 80px rgba(55,240,210,.14);
        }
        .version-activa::before { background: linear-gradient(90deg, #37F0D2, #78A7FF, #FF6B86); }
        .version-activa__titulo, .impacto-dato .lbl { color: rgba(137,255,241,.72); font-family: 'JetBrains Mono', monospace; }
        .version-activa__head p { color: rgba(214,248,245,.72); }
        .version-activa__dato, .impacto-dato {
            background: rgba(3,9,12,.38);
            border-color: rgba(119,239,226,.16);
        }
        .graficas-grid .panel { background: linear-gradient(180deg, rgba(13,23,30,.97), rgba(8,14,19,.97)); }
        .grafica-body { background: rgba(4,10,14,.18); }
        .adopcion-copy .texto { color: #9FB8B5; }
        .bitacora-cta {
            background: linear-gradient(135deg, rgba(12,22,29,.98), rgba(5,16,18,.96));
        }
        .bitacora-icono { background: rgba(55,240,210,.10); color: #37F0D2; border: 1px solid rgba(55,240,210,.18); }
        .bitacora-cta h2 { color: #F4FFFE; }
        .table-shell { scrollbar-color: rgba(55,240,210,.45) rgba(8,14,18,.85); }
        table { color: #DDEBEA; }
        th {
            background: #0D171E;
            color: #81A19E;
            border-bottom-color: rgba(119,239,226,.16);
            font-family: 'JetBrains Mono', monospace;
        }
        td { border-bottom-color: rgba(119,239,226,.08); }
        tbody tr { background: rgba(10,18,24,.92); }
        tbody tr:nth-child(even) { background: rgba(12,22,29,.92); }
        tbody tr:hover { background: rgba(31,55,65,.90); }
        td strong { color: #F3FFFD; }
        td a { color: #89FFF1; text-decoration-color: rgba(137,255,241,.34); }
        td a:hover { color: #B8CAFF; text-decoration-color: rgba(184,202,255,.45); }
        .badge { font-family: 'JetBrains Mono', monospace; }
        .badge-ok { background: rgba(55,240,210,.12) !important; color: #89FFF1 !important; border-color: rgba(55,240,210,.26); }
        .badge-info { background: rgba(120,167,255,.12) !important; color: #B8CAFF !important; border-color: rgba(120,167,255,.24); }
        .badge-warn { background: rgba(245,193,91,.13) !important; color: #FFD78A !important; border-color: rgba(245,193,91,.26); }
        .badge-danger { background: rgba(255,107,134,.13) !important; color: #FFA7B8 !important; border-color: rgba(255,107,134,.28); }
        .seguimiento-resumen { background: #091116; border-bottom-color: rgba(119,239,226,.10); }
        .seguimiento-resumen .mini-stat {
            background: rgba(13,23,30,.92);
            border-color: rgba(119,239,226,.12);
        }
        .seguimiento-resumen .mini-stat .lbl { color: #83A09D; font-family: 'JetBrains Mono', monospace; }
        .json-box {
            background: #050A0E;
            border-color: rgba(119,239,226,.16);
            color: #CEF8F3;
        }
        .vacio, .cargando { color: #89A5A2 !important; }
    </style>
</head>
<body class="tema-oscuro tema-mdm-oscuro" data-mdm-theme-lock="oscuro">
<div class="app">
    <aside class="sidebar">
        <div class="marca">
            <img class="marca-logo" src="assets/img/logo.png" alt="MDM">
        </div>
        <?php require __DIR__ . '/partials/menu.php'; ?>
    </aside>

    <main class="principal actualizaciones-principal">
        <div class="hero-copy">
            <div>
                <div class="encabezado-pagina__etiqueta">Android · <?= htmlspecialchars($canalEtiqueta, ENT_QUOTES, 'UTF-8') ?></div>
                <h1>Actualizaciones de la app</h1>
                <p>Servidor exclusivo para <?= htmlspecialchars($canalEtiqueta, ENT_QUOTES, 'UTF-8') ?>: publica el APK y revisa el avance de sus maquinas.</p>
            </div>
            <div class="acciones-encabezado">
                <a class="btn <?= $canalServidor === AppActualizaciones::CANAL_CLIENTES ? 'primario' : '' ?>" href="actualizaciones_app.php?canal_app=cliente">Clientes</a>
                <a class="btn <?= $canalServidor === AppActualizaciones::CANAL_MOSI ? 'primario' : '' ?>" href="actualizaciones_app.php?canal_app=mosi">MOSI</a>
                <div class="android-brand">
                    <img src="assets/img/android-actualizaciones-mdm.png" alt="Android MDM">
                    <span><b>Android</b><small>Canal de despliegue</small></span>
                </div>
                <button class="btn" id="btn-recargar" type="button"><i data-lucide="refresh-cw"></i>Actualizar</button>
                <?php if ($canalServidor === AppActualizaciones::CANAL_CLIENTES): ?><button class="btn peligro" id="btn-limpiar-apks-maquinas" type="button"><i data-lucide="send"></i>Limpiar APKs en maquinas</button><?php endif; ?>
                <button class="btn peligro" id="btn-limpiar-apks" type="button"><i data-lucide="trash-2"></i>Limpiar APKs</button>
            </div>
        </div>

        <section class="update-showcase" aria-label="Gestion de actualizaciones Android">
            <div class="update-showcase__copy">
                <span class="update-showcase__eyebrow"><i data-lucide="cloud-download"></i>Despliegue Android MDM</span>
                <h2>Actualizaciones seguras para toda la red</h2>
                <p>Publica, distribuye y monitorea cada version desde un flujo centralizado.</p>
                <div class="update-showcase__features">
                    <span><i data-lucide="badge-check"></i>APK verificado</span>
                    <span><i data-lucide="shield-check"></i>Entrega controlada</span>
                    <span><i data-lucide="activity"></i>Telemetria en vivo</span>
                </div>
            </div>
            <div class="update-showcase__art">
                <img src="assets/img/android-actualizaciones-mdm.png" alt="Android actualizando una maquina expendedora MDM" loading="eager" decoding="async">
            </div>
        </section>

        <div class="actualizaciones-hero">
            <section class="version-activa" id="version-activa">
                <div class="version-activa__head">
                    <div>
                        <h2 class="version-activa__titulo">Version activa actual</h2>
                        <p>Estos son los datos que Android recibe antes de subir un nuevo APK.</p>
                    </div>
                    <span class="badge badge-info">Cargando</span>
                </div>
            </section>

            <section class="panel panel-subida">
                <div class="panel-head">
                    <div>
                        <h2>Subir APK firmado</h2>
                        <div class="sub">El SHA-256 se calcula en servidor al guardar.</div>
                    </div>
                </div>
                <form id="form-apk" class="formulario">
                    <div>
                        <label for="version_code">version_code</label>
                        <input id="version_code" name="version_code" type="number" min="1" step="1" placeholder="Automatico desde el APK" readonly>
                    </div>
                    <div>
                        <label for="version_name">version_name</label>
                        <input id="version_name" name="version_name" maxlength="50" placeholder="Automatico desde el APK" readonly>
                    </div>
                    <div class="full">
                        <label for="canal_app">App destino</label>
                        <select id="canal_app" name="canal_app">
                            <option value="<?= htmlspecialchars($canalServidor, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($canalEtiqueta, ENT_QUOTES, 'UTF-8') ?></option>
                        </select>
                        <p class="hint">El APK solo se ofrecerá a máquinas de este canal.</p>
                    </div>
                    <div class="full">
                        <label for="apk">APK firmado *</label>
                        <input id="apk" name="apk" type="file" accept=".apk,application/vnd.android.package-archive" required>
                        <p class="hint">El panel lee version_code, version_name y SHA-256 directamente del APK.</p>
                    </div>
                    <div class="full checks">
                        <label class="check-row"><input name="activa" type="checkbox" value="1" checked> Activa</label>
                        <label class="check-row"><input name="obligatoria" type="checkbox" value="1"> Obligatoria</label>
                    </div>
                    <div class="full">
                        <label for="fecha_publicacion">Programar publicacion</label>
                        <input id="fecha_publicacion" name="fecha_publicacion" type="datetime-local">
                        <p class="hint">Vacio publica de inmediato si esta activa. Para manual, desmarca Activa y activala despues.</p>
                    </div>
                    <div class="full">
                        <label for="notas">Notas</label>
                        <textarea id="notas" name="notas" placeholder="Cambios visibles para soporte o para la app Android."></textarea>
                    </div>
                    <div class="form-pie">
                        <span id="mensaje-subida" class="mensaje"></span>
                        <button class="btn primario" type="submit"><i data-lucide="upload"></i>Subir APK</button>
                    </div>
                </form>
            </section>
        </div>

        <div class="stats">
            <div class="stat stat-info">
                <div class="stat-icon"><i data-lucide="search-check"></i></div>
                <div class="lbl">Consultas</div>
                <div class="num" id="kpi-consulta">0</div>
            </div>
            <div class="stat stat-ok">
                <div class="stat-icon"><i data-lucide="download"></i></div>
                <div class="lbl">Descargas</div>
                <div class="num" id="kpi-descarga">0</div>
            </div>
            <div class="stat stat-ok">
                <div class="stat-icon"><i data-lucide="badge-check"></i></div>
                <div class="lbl">Instalaciones</div>
                <div class="num" id="kpi-instalacion">0</div>
            </div>
            <div class="stat stat-danger">
                <div class="stat-icon"><i data-lucide="triangle-alert"></i></div>
                <div class="lbl">Errores</div>
                <div class="num" id="kpi-error">0</div>
            </div>
        </div>

        <div class="graficas-grid">
            <section class="panel grafica-card">
                <div class="panel-head">
                    <div>
                        <h2>Ruta de adopcion</h2>
                        <div class="sub">Cobertura de la version seleccionada en la red MDM.</div>
                    </div>
                    <span class="telemetria-indice">01 / COBERTURA</span>
                </div>
                <div class="grafica-body adopcion-telemetria">
                    <div class="adopcion-cifra">
                        <span>Cobertura instalada</span>
                        <strong id="adopcion-porcentaje">0%</strong>
                        <p id="adopcion-texto">Esperando datos de seguimiento.</p>
                    </div>
                    <div class="ruta-despliegue" id="ruta-despliegue" aria-label="Distribucion del estado de actualizacion"></div>
                    <div class="matriz-head"><span>Unidades reportando</span><b id="matriz-conteo">0 nodos</b></div>
                    <div class="matriz-unidades" id="matriz-unidades"></div>
                    <div class="adopcion-leyenda" id="adopcion-leyenda"></div>
                </div>
            </section>

            <section class="panel grafica-card">
                <div class="panel-head">
                    <div>
                        <h2>Pulso de despliegue</h2>
                        <div class="sub">Cada señal usa su propia escala para conservar detalle.</div>
                    </div>
                    <span class="telemetria-indice">02 / ULTIMOS 14 DIAS</span>
                </div>
                <div class="grafica-body pulso-grid">
                    <article class="pulso-item" style="--pulso:#4EA1FF">
                        <header><span><i data-lucide="search-check"></i>Consultas</span><strong id="pulso-total-consulta">0</strong></header>
                        <div class="pulso-canvas"><canvas id="pulso-consulta"></canvas></div>
                    </article>
                    <article class="pulso-item" style="--pulso:#F4C95D">
                        <header><span><i data-lucide="download"></i>Descargas</span><strong id="pulso-total-descarga">0</strong></header>
                        <div class="pulso-canvas"><canvas id="pulso-descarga"></canvas></div>
                    </article>
                    <article class="pulso-item" style="--pulso:#53D769">
                        <header><span><i data-lucide="badge-check"></i>Instalaciones</span><strong id="pulso-total-instalacion">0</strong></header>
                        <div class="pulso-canvas"><canvas id="pulso-instalacion"></canvas></div>
                    </article>
                    <article class="pulso-item" style="--pulso:#FF667A">
                        <header><span><i data-lucide="triangle-alert"></i>Errores</span><strong id="pulso-total-error">0</strong></header>
                        <div class="pulso-canvas"><canvas id="pulso-error"></canvas></div>
                    </article>
                </div>
            </section>
        </div>

        <section class="panel">
            <div class="panel-head">
                <div>
                    <h2>Versiones publicadas</h2>
                    <div class="sub">Android recibe la activa con version_code mayor al instalado.</div>
                </div>
            </div>
            <div id="tabla-versiones" class="cargando">Cargando...</div>
        </section>

        <section class="panel" style="margin-top:14px;">
            <div class="panel-head">
                <div>
                    <h2>Seguimiento por actualizacion</h2>
                    <div class="sub">Ultimo estado reportado por cada maquina para la version seleccionada.</div>
                </div>
                <div class="seguimiento-toolbar">
                    <select id="seguimiento-version"></select>
                    <button class="btn" id="btn-seguimiento" type="button"><i data-lucide="refresh-cw"></i>Actualizar</button>
                </div>
            </div>
            <div class="seguimiento-resumen" id="seguimiento-resumen"></div>
            <div id="tabla-seguimiento" class="cargando">Cargando...</div>
        </section>

        <section class="panel" style="margin-top:14px;">
            <div class="bitacora-cta">
                <div>
                    <div class="bitacora-icono"><i data-lucide="logs"></i></div>
                    <h2>Bitacora reciente</h2>
                    <p>Los ultimos 200 eventos de consulta, descarga, instalacion y error viven en una pantalla aparte para revisarlos con calma.</p>
                    <div class="bitacora-preview" id="bitacora-preview"></div>
                </div>
                <a class="btn oscuro" href="actualizaciones_app_bitacora.php"><i data-lucide="external-link"></i>Consultar bitacora</a>
            </div>
        </section>
    </main>
</div>

<script src="assets/js/comun.js?v=20260912-1"></script>
<style id="actualizaciones-terminal-final">
    body.tema-oscuro {
        background:
            linear-gradient(rgba(119,239,226,.030) 1px, transparent 1px),
            linear-gradient(90deg, rgba(119,239,226,.022) 1px, transparent 1px),
            radial-gradient(circle at 82% -8%, rgba(55,240,210,.13), transparent 360px),
            #070C10 !important;
        background-size: 36px 36px, 36px 36px, auto, auto !important;
        color: #EAF7F6 !important;
    }
    body.tema-oscuro .sidebar {
        background: #080E12 !important;
        border-right-color: #1D3038 !important;
    }
    body.tema-oscuro .principal.actualizaciones-principal,
    body.tema-oscuro .principal.actualizaciones-principal * {
        border-color: rgba(119,239,226,.14);
    }
    body.tema-oscuro .principal.actualizaciones-principal .hero-copy,
    body.tema-oscuro .principal.actualizaciones-principal .panel,
    body.tema-oscuro .principal.actualizaciones-principal .stat {
        background: linear-gradient(180deg, rgba(14,25,32,.97), rgba(8,14,19,.97)) !important;
        border-color: rgba(119,239,226,.16) !important;
        color: #EAF7F6 !important;
        box-shadow: 0 18px 42px rgba(0,0,0,.34) !important;
    }
    body.tema-oscuro .principal.actualizaciones-principal .version-activa {
        background: linear-gradient(135deg, rgba(5,10,14,.98), rgba(7,43,42,.96) 48%, rgba(10,34,72,.94)) !important;
        border-color: rgba(55,240,210,.30) !important;
        box-shadow: 0 0 0 1px rgba(55,240,210,.13), 0 34px 76px rgba(0,0,0,.54) !important;
    }
    body.tema-oscuro .principal.actualizaciones-principal .panel-head,
    body.tema-oscuro .principal.actualizaciones-principal .seguimiento-resumen,
    body.tema-oscuro .principal.actualizaciones-principal .grafica-body,
    body.tema-oscuro .principal.actualizaciones-principal .bitacora-cta {
        background: linear-gradient(180deg, rgba(18,33,41,.98), rgba(9,16,21,.98)) !important;
        border-color: rgba(119,239,226,.12) !important;
    }
    body.tema-oscuro .principal.actualizaciones-principal table {
        background: #081015 !important;
        color: #DDEBEA !important;
    }
    body.tema-oscuro .principal.actualizaciones-principal th {
        background: #0D171E !important;
        color: #8EB0AD !important;
        border-color: rgba(119,239,226,.16) !important;
    }
    body.tema-oscuro .principal.actualizaciones-principal td {
        background: transparent !important;
        color: #DDEBEA !important;
        border-color: rgba(119,239,226,.08) !important;
    }
    body.tema-oscuro .principal.actualizaciones-principal tbody tr {
        background: rgba(10,18,24,.95) !important;
    }
    body.tema-oscuro .principal.actualizaciones-principal tbody tr:nth-child(even) {
        background: rgba(12,22,29,.95) !important;
    }
    body.tema-oscuro .principal.actualizaciones-principal tbody tr:hover {
        background: rgba(31,55,65,.94) !important;
    }
    body.tema-oscuro .principal.actualizaciones-principal input,
    body.tema-oscuro .principal.actualizaciones-principal textarea,
    body.tema-oscuro .principal.actualizaciones-principal select {
        background: #091116 !important;
        border-color: rgba(119,239,226,.22) !important;
        color: #EAF7F6 !important;
    }
    body.tema-oscuro .principal.actualizaciones-principal input[readonly],
    body.tema-oscuro .principal.actualizaciones-principal input[type="file"] {
        background: #0B151B !important;
        color: #9BB2B0 !important;
    }
    body.tema-oscuro .principal.actualizaciones-principal .btn {
        background: #0B151B !important;
        border-color: rgba(119,239,226,.24) !important;
        color: #DDF8F5 !important;
        box-shadow: none !important;
    }
    body.tema-oscuro .principal.actualizaciones-principal .btn:hover {
        background: rgba(55,240,210,.10) !important;
        border-color: #37F0D2 !important;
        color: #89FFF1 !important;
        box-shadow: 0 0 0 1px rgba(55,240,210,.20), 0 12px 28px rgba(0,0,0,.28) !important;
    }
    body.tema-oscuro .principal.actualizaciones-principal .btn.primario,
    body.tema-oscuro .principal.actualizaciones-principal .btn.oscuro {
        background: linear-gradient(135deg, #0B6E68, #1445A0) !important;
        border-color: rgba(119,239,226,.42) !important;
        color: #FFFFFF !important;
        box-shadow: 0 0 28px rgba(55,240,210,.18) !important;
    }
    body.tema-oscuro .principal.actualizaciones-principal .badge-ok { background: rgba(55,240,210,.12) !important; color: #89FFF1 !important; border-color: rgba(55,240,210,.26) !important; }
    body.tema-oscuro .principal.actualizaciones-principal .badge-info { background: rgba(120,167,255,.12) !important; color: #B8CAFF !important; border-color: rgba(120,167,255,.24) !important; }
    body.tema-oscuro .principal.actualizaciones-principal .badge-warn { background: rgba(245,193,91,.13) !important; color: #FFD78A !important; border-color: rgba(245,193,91,.26) !important; }
    body.tema-oscuro .principal.actualizaciones-principal .badge-danger { background: rgba(255,107,134,.13) !important; color: #FFA7B8 !important; border-color: rgba(255,107,134,.28) !important; }

    body.tema-oscuro .principal.actualizaciones-principal h1,
    body.tema-oscuro .principal.actualizaciones-principal h2,
    body.tema-oscuro .principal.actualizaciones-principal h3 {
        color: #FFFFFF !important;
        text-shadow: none !important;
    }
    body.tema-oscuro .principal.actualizaciones-principal p,
    body.tema-oscuro .principal.actualizaciones-principal .sub {
        color: #D4E3E1 !important;
    }
    body.tema-oscuro .principal.actualizaciones-principal .encabezado-pagina__etiqueta,
    body.tema-oscuro .principal.actualizaciones-principal .version-activa__titulo,
    body.tema-oscuro .principal.actualizaciones-principal label,
    body.tema-oscuro .principal.actualizaciones-principal .lbl {
        color: #BFFCF4 !important;
    }
    body.tema-oscuro .principal.actualizaciones-principal .version-activa__version,
    body.tema-oscuro .principal.actualizaciones-principal .num,
    body.tema-oscuro .principal.actualizaciones-principal .val,
    body.tema-oscuro .principal.actualizaciones-principal td,
    body.tema-oscuro .principal.actualizaciones-principal td strong {
        color: #FFFFFF !important;
    }
    body.tema-oscuro .principal.actualizaciones-principal .muted,
    body.tema-oscuro .principal.actualizaciones-principal .hint,
    body.tema-oscuro .principal.actualizaciones-principal .adopcion-copy .texto {
        color: #C5D9D7 !important;
    }
    body.tema-oscuro .principal.actualizaciones-principal th {
        color: #D7FFFA !important;
        font-weight: 900 !important;
    }
    body.tema-oscuro .principal.actualizaciones-principal input,
    body.tema-oscuro .principal.actualizaciones-principal textarea,
    body.tema-oscuro .principal.actualizaciones-principal select {
        color: #FFFFFF !important;
    }
    body.tema-oscuro .principal.actualizaciones-principal input::placeholder,
    body.tema-oscuro .principal.actualizaciones-principal textarea::placeholder {
        color: #A9BFBC !important;
    }

    body.tema-oscuro .principal.actualizaciones-principal {
        max-width: 1440px;
    }
    body.tema-oscuro .principal.actualizaciones-principal .actualizaciones-hero {
        grid-template-columns: minmax(0, 1.15fr) minmax(390px, .85fr);
        align-items: stretch;
    }
    body.tema-oscuro .principal.actualizaciones-principal > .stats {
        grid-template-columns: repeat(4, minmax(0, 1fr));
        margin: 0 0 14px;
    }
    body.tema-oscuro .principal.actualizaciones-principal .hero-copy,
    body.tema-oscuro .principal.actualizaciones-principal .panel,
    body.tema-oscuro .principal.actualizaciones-principal .stat {
        border-radius: 4px !important;
        background: #0E171D !important;
        border: 1px solid #22343D !important;
        box-shadow: none !important;
    }
    body.tema-oscuro .principal.actualizaciones-principal .panel {
        border-left: 3px solid rgba(55,240,210,.58) !important;
    }
    body.tema-oscuro .principal.actualizaciones-principal .panel-head {
        padding: 15px 18px;
        background: #111D24 !important;
        border-bottom: 1px solid #273A43 !important;
    }
    body.tema-oscuro .principal.actualizaciones-principal .version-activa {
        min-height: 300px;
        border-radius: 4px !important;
        background: #081116 !important;
        border: 1px solid #2B4A4B !important;
        border-left: 4px solid #37F0D2 !important;
        box-shadow: inset 0 1px 0 rgba(255,255,255,.04) !important;
    }
    body.tema-oscuro .principal.actualizaciones-principal .version-activa::after {
        opacity: .28;
    }
    body.tema-oscuro .principal.actualizaciones-principal .version-activa__dato,
    body.tema-oscuro .principal.actualizaciones-principal .impacto-dato,
    body.tema-oscuro .principal.actualizaciones-principal .seguimiento-resumen .mini-stat {
        border-radius: 4px !important;
        background: #111E25 !important;
        border-color: #2A3E47 !important;
    }
    body.tema-oscuro .principal.actualizaciones-principal .stat {
        min-height: 84px;
        display: grid;
        grid-template-columns: 36px minmax(0, 1fr);
        grid-template-rows: auto auto;
        column-gap: 12px;
        align-items: center;
        padding: 14px 16px;
        border-top: 2px solid rgba(55,240,210,.34) !important;
    }
    body.tema-oscuro .principal.actualizaciones-principal .stat::after {
        display: none;
    }
    body.tema-oscuro .principal.actualizaciones-principal .stat .stat-icon {
        grid-row: 1 / 3;
        margin: 0;
        border-radius: 4px;
    }
    body.tema-oscuro .principal.actualizaciones-principal .stat .lbl,
    body.tema-oscuro .principal.actualizaciones-principal .stat .num {
        position: relative;
        z-index: 1;
    }
    body.tema-oscuro .principal.actualizaciones-principal .formulario {
        gap: 12px;
        padding: 16px;
    }
    body.tema-oscuro .principal.actualizaciones-principal label {
        color: #C2D8D6;
    }
    body.tema-oscuro .principal.actualizaciones-principal .hint,
    body.tema-oscuro .principal.actualizaciones-principal .muted {
        color: #B1C6C4 !important;
    }
    body.tema-oscuro .principal.actualizaciones-principal table {
        border-collapse: separate;
        border-spacing: 0 6px;
        background: transparent !important;
        font-size: 13.5px;
    }
    body.tema-oscuro .principal.actualizaciones-principal th {
        background: transparent !important;
        color: #A7C4C1 !important;
        border-bottom: 1px solid #2A3E47 !important;
        padding: 10px 14px;
    }
    body.tema-oscuro .principal.actualizaciones-principal td {
        background: #101B22 !important;
        color: #F0FAF9 !important;
        border-top: 1px solid #263941 !important;
        border-bottom: 1px solid #263941 !important;
        line-height: 1.48;
    }
    body.tema-oscuro .principal.actualizaciones-principal td:first-child {
        border-left: 1px solid #263941 !important;
    }
    body.tema-oscuro .principal.actualizaciones-principal td:last-child {
        border-right: 1px solid #263941 !important;
    }
    body.tema-oscuro .principal.actualizaciones-principal tbody tr,
    body.tema-oscuro .principal.actualizaciones-principal tbody tr:nth-child(even) {
        background: transparent !important;
    }
    body.tema-oscuro .principal.actualizaciones-principal tbody tr:hover td {
        background: #172832 !important;
        color: #FFFFFF !important;
    }
    body.tema-oscuro .principal.actualizaciones-principal td strong {
        color: #FFFFFF !important;
        font-weight: 800;
    }
    body.tema-oscuro .principal.actualizaciones-principal .mono {
        color: #D8FFFA;
        font-size: 12.5px;
    }
    body.tema-oscuro .principal.actualizaciones-principal .version-activa .mono,
    body.tema-oscuro .principal.actualizaciones-principal .version-activa .val,
    body.tema-oscuro .principal.actualizaciones-principal .version-activa .muted {
        color: #FFFFFF !important;
    }
    body.tema-oscuro .principal.actualizaciones-principal .grafica-card {
        min-height: 340px;
    }
    body.tema-oscuro .principal.actualizaciones-principal .grafica-body {
        min-height: 270px;
        background: #0A1217 !important;
    }
    body.tema-oscuro .principal.actualizaciones-principal .adopcion-centro {
        width: min(100%, 500px);
        grid-template-columns: 200px minmax(0, 1fr);
        gap: 26px;
    }
    body.tema-oscuro .principal.actualizaciones-principal #grafica-adopcion {
        height: 200px !important;
        max-height: 200px !important;
    }
    body.tema-oscuro .principal.actualizaciones-principal #grafica-eventos {
        min-height: 230px !important;
    }
    body.tema-oscuro .principal.actualizaciones-principal .bitacora-cta {
        background: #0E171D !important;
    }
    @media (max-width: 1180px) {
        body.tema-oscuro .principal.actualizaciones-principal .actualizaciones-hero,
        body.tema-oscuro .principal.actualizaciones-principal .graficas-grid {
            grid-template-columns: 1fr;
        }
    }
    @media (max-width: 760px) {
        body.tema-oscuro .principal.actualizaciones-principal > .stats {
            grid-template-columns: 1fr 1fr;
        }
    }
    @media (max-width: 520px) {
        body.tema-oscuro .principal.actualizaciones-principal > .stats {
            grid-template-columns: 1fr;
        }
    }
</style>
<style id="actualizaciones-brutal-final">
    /* Brutalismo controlado: alta jerarquia, contraste y geometria funcional. */
    body.tema-oscuro {
        background: #DCE4E8 !important;
    }
    .principal.actualizaciones-principal {
        --brutal-ink: #0A1720;
        --brutal-paper: #F8FAFB;
        --brutal-soft: #E8EEF1;
        --brutal-blue: #214F8B;
        --brutal-green: #36A957;
        --brutal-gold: #C79B4B;
        max-width: 1440px !important;
        color: var(--brutal-ink) !important;
    }
    .principal.actualizaciones-principal .hero-copy {
        position: relative;
        align-items: center;
        margin: 0 6px 22px 0;
        padding: 25px 26px 21px;
        background: var(--brutal-paper) !important;
        border: 2px solid var(--brutal-ink) !important;
        border-radius: 0 !important;
        box-shadow: 7px 7px 0 var(--brutal-blue) !important;
        animation: brutalEntrada .45s cubic-bezier(.16,1,.3,1) both;
    }
    .principal.actualizaciones-principal .hero-copy::before {
        content: "MDM / CONTROL DE DESPLIEGUES";
        position: absolute;
        top: -11px;
        left: 20px;
        padding: 4px 9px;
        color: #FFFFFF;
        background: var(--brutal-ink);
        font: 800 10px/1 'JetBrains Mono', monospace;
    }
    .principal.actualizaciones-principal .hero-copy h1 {
        color: var(--brutal-ink) !important;
        font-size: clamp(28px, 3vw, 43px);
        line-height: 1;
        text-transform: uppercase;
        letter-spacing: 0;
    }
    .principal.actualizaciones-principal .hero-copy p { color: #405663 !important; font-size: 14px; }
    .principal.actualizaciones-principal .encabezado-pagina__etiqueta {
        color: var(--brutal-blue) !important;
        font-family: 'JetBrains Mono', monospace;
        letter-spacing: 0;
    }
    .principal.actualizaciones-principal .encabezado-pagina__etiqueta::before {
        width: 18px; height: 4px; border-radius: 0; box-shadow: none; background: var(--brutal-green);
    }
    .principal.actualizaciones-principal .actualizaciones-hero,
    .principal.actualizaciones-principal .graficas-grid { gap: 20px; margin-bottom: 20px; }
    .principal.actualizaciones-principal .panel,
    .principal.actualizaciones-principal .stat {
        position: relative;
        overflow: visible;
        color: var(--brutal-ink) !important;
        background: var(--brutal-paper) !important;
        border: 2px solid var(--brutal-ink) !important;
        border-radius: 0 !important;
        box-shadow: 5px 5px 0 #80939E !important;
    }
    .principal.actualizaciones-principal .version-activa {
        min-height: 320px;
        padding: 24px;
        color: #FFFFFF !important;
        background: #102A43 !important;
        border: 2px solid var(--brutal-ink) !important;
        border-radius: 0 !important;
        box-shadow: 8px 8px 0 var(--brutal-green) !important;
        animation: brutalEntrada .5s .04s cubic-bezier(.16,1,.3,1) both;
    }
    .principal.actualizaciones-principal .version-activa::before {
        content: "APK ACTIVO";
        position: absolute;
        right: 18px;
        bottom: 16px;
        color: rgba(255,255,255,.32);
        font: 800 11px/1 'JetBrains Mono', monospace;
    }
    .principal.actualizaciones-principal .version-activa::after { display: none !important; }
    .principal.actualizaciones-principal .version-activa__version { font-size: clamp(48px, 6vw, 78px); }
    .principal.actualizaciones-principal .version-activa__dato,
    .principal.actualizaciones-principal .impacto-dato {
        background: #173B5C !important;
        border: 1px solid #6D8DA5 !important;
        border-radius: 0 !important;
    }
    .principal.actualizaciones-principal .panel-subida { animation: brutalEntrada .5s .10s cubic-bezier(.16,1,.3,1) both; }
    .principal.actualizaciones-principal .panel-subida::before,
    .principal.actualizaciones-principal .grafica-card::before {
        position: absolute;
        z-index: 3;
        top: -12px;
        right: 14px;
        padding: 5px 9px;
        color: #FFFFFF;
        background: var(--brutal-blue);
        border: 2px solid var(--brutal-ink);
        font: 800 10px/1 'JetBrains Mono', monospace;
    }
    .principal.actualizaciones-principal .panel-subida::before { content: "UPLOAD / APK"; }
    .principal.actualizaciones-principal .grafica-card:first-child::before { content: "GRAFICA / 01"; background: var(--brutal-green); color: var(--brutal-ink); }
    .principal.actualizaciones-principal .grafica-card:nth-child(2)::before { content: "GRAFICA / 02"; }
    .principal.actualizaciones-principal .panel-head,
    .principal.actualizaciones-principal .grafica-body,
    .principal.actualizaciones-principal .seguimiento-resumen,
    .principal.actualizaciones-principal .bitacora-cta {
        background: var(--brutal-paper) !important;
        border-color: var(--brutal-ink) !important;
    }
    .principal.actualizaciones-principal .panel-head {
        min-height: 58px;
        padding: 15px 18px;
        border-bottom: 2px solid var(--brutal-ink) !important;
    }
    .principal.actualizaciones-principal .panel-head h2 {
        color: var(--brutal-ink) !important;
        font-size: 16px;
        text-transform: uppercase;
        letter-spacing: 0;
    }
    .principal.actualizaciones-principal .panel-head .sub,
    .principal.actualizaciones-principal .hint,
    .principal.actualizaciones-principal .muted { color: #536A78 !important; }
    .principal.actualizaciones-principal > .stats { gap: 13px; margin: 0 6px 20px 0; }
    .principal.actualizaciones-principal .stat {
        min-height: 100px;
        padding: 16px;
        grid-template-columns: 42px minmax(0, 1fr);
        border-top-width: 7px !important;
        animation: brutalEntrada .45s cubic-bezier(.16,1,.3,1) both;
    }
    .principal.actualizaciones-principal .stat:nth-child(2) { animation-delay: .05s; }
    .principal.actualizaciones-principal .stat:nth-child(3) { animation-delay: .10s; }
    .principal.actualizaciones-principal .stat:nth-child(4) { animation-delay: .15s; }
    .principal.actualizaciones-principal .stat-info { border-top-color: var(--brutal-blue) !important; }
    .principal.actualizaciones-principal .stat-ok { border-top-color: var(--brutal-green) !important; }
    .principal.actualizaciones-principal .stat-danger { border-top-color: #B42335 !important; }
    .principal.actualizaciones-principal .stat::after { display: none !important; }
    .principal.actualizaciones-principal .stat .stat-icon {
        width: 40px; height: 40px; border: 2px solid currentColor; border-radius: 0 !important; margin: 0;
    }
    .principal.actualizaciones-principal .stat .lbl { color: #405663 !important; letter-spacing: 0; }
    .principal.actualizaciones-principal .stat .num { color: var(--brutal-ink) !important; font-size: 32px; }
    .principal.actualizaciones-principal .btn {
        border: 2px solid var(--brutal-ink) !important;
        border-radius: 0 !important;
        background: #FFFFFF !important;
        color: var(--brutal-ink) !important;
        box-shadow: 3px 3px 0 var(--brutal-ink) !important;
        text-transform: uppercase;
    }
    .principal.actualizaciones-principal .btn:hover {
        transform: translate(2px, 2px);
        box-shadow: 1px 1px 0 var(--brutal-ink) !important;
    }
    .principal.actualizaciones-principal .btn.primario,
    .principal.actualizaciones-principal .btn.oscuro {
        color: #FFFFFF !important;
        background: var(--brutal-blue) !important;
    }
    .principal.actualizaciones-principal .btn.peligro { color: #FFFFFF !important; background: #A61E30 !important; }
    .principal.actualizaciones-principal input,
    .principal.actualizaciones-principal textarea,
    .principal.actualizaciones-principal select,
    .principal.actualizaciones-principal .check-row {
        color: var(--brutal-ink) !important;
        background: #FFFFFF !important;
        border: 2px solid #82949E !important;
        border-radius: 0 !important;
    }
    .principal.actualizaciones-principal input:focus,
    .principal.actualizaciones-principal textarea:focus,
    .principal.actualizaciones-principal select:focus {
        border-color: var(--brutal-blue) !important;
        box-shadow: 4px 4px 0 rgba(33,79,139,.22) !important;
    }
    .principal.actualizaciones-principal .grafica-card { min-height: 370px; animation: brutalEntrada .5s .12s cubic-bezier(.16,1,.3,1) both; }
    .principal.actualizaciones-principal .grafica-body { min-height: 285px; }
    .principal.actualizaciones-principal .chart-periodo {
        border: 1px solid var(--brutal-ink); border-radius: 0; color: var(--brutal-ink); background: #FFFFFF;
    }
    .principal.actualizaciones-principal table { border-collapse: collapse !important; border-spacing: 0 !important; }
    .principal.actualizaciones-principal th {
        color: #FFFFFF !important; background: var(--brutal-ink) !important; border: 1px solid var(--brutal-ink) !important;
    }
    .principal.actualizaciones-principal td {
        color: var(--brutal-ink) !important; background: #FFFFFF !important; border: 1px solid #AAB8C0 !important;
    }
    .principal.actualizaciones-principal tbody tr:hover td { color: var(--brutal-ink) !important; background: #E3F4E8 !important; }
    .principal.actualizaciones-principal .seguimiento-resumen .mini-stat {
        color: var(--brutal-ink) !important; background: #FFFFFF !important; border: 2px solid #82949E !important; border-radius: 0 !important;
    }
    .principal.actualizaciones-principal .bitacora-icono { border: 2px solid var(--brutal-ink); border-radius: 0; }

    body.tema-mdm-oscuro {
        background: #080D11 !important;
    }
    body.tema-mdm-oscuro .principal.actualizaciones-principal {
        --brutal-ink: #DCE8ED;
        --brutal-paper: #101920;
        --brutal-soft: #17242D;
        color: #F4FAFC !important;
    }
    body.tema-mdm-oscuro .principal.actualizaciones-principal .hero-copy {
        background: #101920 !important; border-color: #A9BCC6 !important; box-shadow: 7px 7px 0 #36A957 !important;
    }
    body.tema-mdm-oscuro .principal.actualizaciones-principal .hero-copy::before { color: #071018; background: #62C873; }
    body.tema-mdm-oscuro .principal.actualizaciones-principal .hero-copy h1,
    body.tema-mdm-oscuro .principal.actualizaciones-principal .panel-head h2,
    body.tema-mdm-oscuro .principal.actualizaciones-principal .stat .num { color: #F7FBFD !important; }
    body.tema-mdm-oscuro .principal.actualizaciones-principal .hero-copy p,
    body.tema-mdm-oscuro .principal.actualizaciones-principal .panel-head .sub,
    body.tema-mdm-oscuro .principal.actualizaciones-principal .hint,
    body.tema-mdm-oscuro .principal.actualizaciones-principal .muted,
    body.tema-mdm-oscuro .principal.actualizaciones-principal .stat .lbl { color: #B8CAD3 !important; }
    body.tema-mdm-oscuro .principal.actualizaciones-principal .panel,
    body.tema-mdm-oscuro .principal.actualizaciones-principal .stat {
        background: #101920 !important; border-color: #A9BCC6 !important; box-shadow: 5px 5px 0 #214F8B !important;
    }
    body.tema-mdm-oscuro .principal.actualizaciones-principal .version-activa {
        background: #0D2940 !important; border-color: #A9BCC6 !important; box-shadow: 8px 8px 0 #36A957 !important;
    }
    body.tema-mdm-oscuro .principal.actualizaciones-principal .panel-head,
    body.tema-mdm-oscuro .principal.actualizaciones-principal .grafica-body,
    body.tema-mdm-oscuro .principal.actualizaciones-principal .seguimiento-resumen,
    body.tema-mdm-oscuro .principal.actualizaciones-principal .bitacora-cta { background: #101920 !important; border-color: #A9BCC6 !important; }
    body.tema-mdm-oscuro .principal.actualizaciones-principal input,
    body.tema-mdm-oscuro .principal.actualizaciones-principal textarea,
    body.tema-mdm-oscuro .principal.actualizaciones-principal select,
    body.tema-mdm-oscuro .principal.actualizaciones-principal .check-row,
    body.tema-mdm-oscuro .principal.actualizaciones-principal .seguimiento-resumen .mini-stat {
        color: #F4FAFC !important; background: #081118 !important; border-color: #78909C !important;
    }
    body.tema-mdm-oscuro .principal.actualizaciones-principal th { color: #071018 !important; background: #AFC5CF !important; }
    body.tema-mdm-oscuro .principal.actualizaciones-principal td { color: #EDF6F8 !important; background: #0D171D !important; border-color: #40545F !important; }
    body.tema-mdm-oscuro .principal.actualizaciones-principal tbody tr:hover td { color: #FFFFFF !important; background: #17313C !important; }
    body.tema-mdm-oscuro .principal.actualizaciones-principal .btn { color: #F4FAFC !important; background: #101920 !important; border-color: #A9BCC6 !important; box-shadow: 3px 3px 0 #78909C !important; }
    body.tema-mdm-oscuro .principal.actualizaciones-principal .btn.primario,
    body.tema-mdm-oscuro .principal.actualizaciones-principal .btn.oscuro { background: #245C99 !important; }

    @keyframes brutalEntrada {
        from { opacity: 0; transform: translate(8px, 8px); }
        to { opacity: 1; transform: translate(0, 0); }
    }
    @media (prefers-reduced-motion: reduce) {
        .principal.actualizaciones-principal .hero-copy,
        .principal.actualizaciones-principal .version-activa,
        .principal.actualizaciones-principal .panel-subida,
        .principal.actualizaciones-principal .grafica-card,
        .principal.actualizaciones-principal .stat { animation: none !important; }
    }
    @media (max-width: 760px) {
        .principal.actualizaciones-principal .hero-copy { padding: 22px 18px 18px; }
        .principal.actualizaciones-principal .version-activa { min-height: auto; }
        .principal.actualizaciones-principal .grafica-card { min-height: 340px; }
    }
</style>
<style id="actualizaciones-fluent-refinement">
    body.tema-oscuro { background: #EEF2F5 !important; }
    .principal.actualizaciones-principal {
        --brutal-ink: #142A38;
        --brutal-paper: #FFFFFF;
        --brutal-soft: #F3F6F8;
        --brutal-blue: #24679A;
        --brutal-green: #2F8B4A;
        color: #142A38 !important;
    }
    .principal.actualizaciones-principal .hero-copy {
        margin-right: 0;
        padding: 22px 24px;
        background: #FFFFFF !important;
        border: 1px solid #D4DEE4 !important;
        border-radius: 8px !important;
        box-shadow: 0 12px 30px rgba(30,52,66,.08) !important;
    }
    .principal.actualizaciones-principal .hero-copy::before,
    .principal.actualizaciones-principal .panel-subida::before,
    .principal.actualizaciones-principal .grafica-card::before { display: none !important; }
    .principal.actualizaciones-principal .hero-copy h1 {
        color: #142A38 !important;
        font-size: clamp(28px, 3vw, 38px);
        text-transform: none;
    }
    .principal.actualizaciones-principal .actualizaciones-hero,
    .principal.actualizaciones-principal .graficas-grid { gap: 16px; margin-bottom: 16px; }
    .principal.actualizaciones-principal .panel,
    .principal.actualizaciones-principal .stat {
        overflow: hidden;
        color: #142A38 !important;
        background: #FFFFFF !important;
        border: 1px solid #D4DEE4 !important;
        border-radius: 8px !important;
        box-shadow: 0 7px 20px rgba(30,52,66,.065) !important;
    }
    .principal.actualizaciones-principal .version-activa {
        min-height: 310px;
        padding: 24px;
        background: #173B58 !important;
        border: 1px solid #315B78 !important;
        border-left: 4px solid #55B56C !important;
        border-radius: 8px !important;
        box-shadow: 0 14px 34px rgba(20,50,72,.16) !important;
    }
    .principal.actualizaciones-principal .version-activa::before { display: none; }
    .principal.actualizaciones-principal .version-activa__version { font-size: clamp(45px, 5vw, 64px); }
    .principal.actualizaciones-principal .version-activa__dato,
    .principal.actualizaciones-principal .impacto-dato {
        background: rgba(255,255,255,.075) !important;
        border: 1px solid rgba(255,255,255,.18) !important;
        border-radius: 5px !important;
    }
    .principal.actualizaciones-principal .panel-head,
    .principal.actualizaciones-principal .grafica-body,
    .principal.actualizaciones-principal .seguimiento-resumen,
    .principal.actualizaciones-principal .bitacora-cta {
        background: #FFFFFF !important;
        border-color: #DCE4E9 !important;
    }
    .principal.actualizaciones-principal .panel-head { min-height: 58px; border-bottom-width: 1px !important; }
    .principal.actualizaciones-principal .panel-head h2 { color: #183144 !important; text-transform: none; }
    .principal.actualizaciones-principal > .stats { gap: 12px; margin-right: 0; }
    .principal.actualizaciones-principal .stat {
        min-height: 94px;
        padding: 15px;
        border-top: 1px solid #D4DEE4 !important;
    }
    .principal.actualizaciones-principal .stat .stat-icon {
        width: 38px;
        height: 38px;
        border: 0;
        border-radius: 6px !important;
    }
    .principal.actualizaciones-principal .stat .num { color: #142A38 !important; font-size: 30px; }
    .principal.actualizaciones-principal .btn {
        border: 1px solid #AEBEC8 !important;
        border-radius: 5px !important;
        background: #FFFFFF !important;
        color: #183144 !important;
        box-shadow: none !important;
        text-transform: none;
    }
    .principal.actualizaciones-principal .btn:hover {
        transform: translateY(-1px);
        border-color: #6D91A8 !important;
        background: #F3F7F9 !important;
        box-shadow: 0 5px 12px rgba(30,52,66,.08) !important;
    }
    .principal.actualizaciones-principal .btn.primario,
    .principal.actualizaciones-principal .btn.oscuro { color: #FFFFFF !important; background: #24679A !important; border-color: #24679A !important; }
    .principal.actualizaciones-principal .btn.peligro { color: #A32636 !important; background: #FFF7F8 !important; border-color: #D7A7AF !important; }
    .principal.actualizaciones-principal input,
    .principal.actualizaciones-principal textarea,
    .principal.actualizaciones-principal select,
    .principal.actualizaciones-principal .check-row {
        color: #142A38 !important;
        background: #FFFFFF !important;
        border: 1px solid #B8C7D0 !important;
        border-radius: 5px !important;
    }
    .principal.actualizaciones-principal input:focus,
    .principal.actualizaciones-principal textarea:focus,
    .principal.actualizaciones-principal select:focus {
        border-color: #3979A8 !important;
        box-shadow: 0 0 0 3px rgba(36,103,154,.12) !important;
    }
    .principal.actualizaciones-principal .grafica-card { min-height: 360px; }
    .principal.actualizaciones-principal .grafica-body { min-height: 280px; }
    .principal.actualizaciones-principal .chart-periodo { border-color: #CAD6DD; border-radius: 4px; color: #536A78; background: #F5F8FA; }
    .principal.actualizaciones-principal th { color: #425968 !important; background: #F1F5F7 !important; border-color: #D7E0E5 !important; }
    .principal.actualizaciones-principal td { color: #203746 !important; background: #FFFFFF !important; border-color: #E0E7EB !important; }
    .principal.actualizaciones-principal tbody tr:hover td { color: #142A38 !important; background: #F2F7FA !important; }
    .principal.actualizaciones-principal .seguimiento-resumen .mini-stat {
        color: #183144 !important;
        background: #F7F9FB !important;
        border: 1px solid #D5DFE5 !important;
        border-radius: 6px !important;
    }
    .principal.actualizaciones-principal .bitacora-icono { border: 0; border-radius: 6px; }

    body.tema-mdm-oscuro { background: #0B1116 !important; }
    body.tema-mdm-oscuro .principal.actualizaciones-principal { color: #EDF4F7 !important; }
    body.tema-mdm-oscuro .principal.actualizaciones-principal .hero-copy,
    body.tema-mdm-oscuro .principal.actualizaciones-principal .panel,
    body.tema-mdm-oscuro .principal.actualizaciones-principal .stat,
    body.tema-mdm-oscuro .principal.actualizaciones-principal .panel-head,
    body.tema-mdm-oscuro .principal.actualizaciones-principal .grafica-body,
    body.tema-mdm-oscuro .principal.actualizaciones-principal .seguimiento-resumen,
    body.tema-mdm-oscuro .principal.actualizaciones-principal .bitacora-cta {
        background: #121C23 !important;
        border-color: #34444E !important;
        box-shadow: 0 8px 24px rgba(0,0,0,.20) !important;
    }
    body.tema-mdm-oscuro .principal.actualizaciones-principal .hero-copy h1,
    body.tema-mdm-oscuro .principal.actualizaciones-principal .panel-head h2,
    body.tema-mdm-oscuro .principal.actualizaciones-principal .stat .num { color: #F4F8FA !important; }
    body.tema-mdm-oscuro .principal.actualizaciones-principal .version-activa { background: #102E45 !important; border-color: #36596F !important; border-left-color: #62C873 !important; }
    body.tema-mdm-oscuro .principal.actualizaciones-principal input,
    body.tema-mdm-oscuro .principal.actualizaciones-principal textarea,
    body.tema-mdm-oscuro .principal.actualizaciones-principal select,
    body.tema-mdm-oscuro .principal.actualizaciones-principal .check-row,
    body.tema-mdm-oscuro .principal.actualizaciones-principal .seguimiento-resumen .mini-stat {
        color: #EDF4F7 !important;
        background: #0D161C !important;
        border-color: #41535E !important;
    }
    body.tema-mdm-oscuro .principal.actualizaciones-principal th { color: #B8CAD3 !important; background: #18242C !important; border-color: #394B55 !important; }
    body.tema-mdm-oscuro .principal.actualizaciones-principal td { color: #E7F0F3 !important; background: #101920 !important; border-color: #2D3D46 !important; }
    body.tema-mdm-oscuro .principal.actualizaciones-principal tbody tr:hover td { color: #FFFFFF !important; background: #192830 !important; }
    body.tema-mdm-oscuro .principal.actualizaciones-principal .btn { color: #EAF1F4 !important; background: #17232A !important; border-color: #526671 !important; }

    /* Esta consola conserva siempre su identidad oscura. */
    body[data-mdm-theme-lock="oscuro"] .principal.actualizaciones-principal {
        --chart-surface: #0D171D;
    }
    body[data-mdm-theme-lock="oscuro"] .mdm-sidebar-footer { padding-top: 6px; }
    body[data-mdm-theme-lock="oscuro"] .version-activa {
        position: relative;
        padding-right: 228px !important;
        overflow: hidden !important;
        isolation: isolate;
    }
    body[data-mdm-theme-lock="oscuro"] .version-activa > :not(.android-visual) { position: relative; z-index: 2; }
    .android-visual {
        position: absolute;
        z-index: 1;
        top: 22px;
        right: 20px;
        width: 184px;
        height: calc(100% - 44px);
        min-height: 250px;
        object-fit: cover;
        object-position: center;
        border: 1px solid rgba(121, 238, 139, .42);
        border-radius: 7px;
        box-shadow: 0 12px 30px rgba(0,0,0,.32), 0 0 28px rgba(83,215,105,.12);
    }
    .android-visual::after { display: none; }
    body[data-mdm-theme-lock="oscuro"] .grafica-card {
        border-color: #354853 !important;
        background: #101A21 !important;
        box-shadow: 0 12px 32px rgba(0,0,0,.24) !important;
    }
    body[data-mdm-theme-lock="oscuro"] .grafica-card .panel-head {
        padding: 18px 20px;
        background: #121F27 !important;
        border-bottom-color: #2D414C !important;
    }
    body[data-mdm-theme-lock="oscuro"] .grafica-card .panel-head h2 { font-size: 15px; font-weight: 700; }
    body[data-mdm-theme-lock="oscuro"] .grafica-card .grafica-body {
        min-height: 320px;
        padding: 22px;
        background: var(--chart-surface) !important;
    }
    body[data-mdm-theme-lock="oscuro"] .adopcion-centro {
        width: min(100%, 560px);
        grid-template-columns: 230px minmax(0, 1fr);
        gap: 34px;
    }
    body[data-mdm-theme-lock="oscuro"] #grafica-adopcion {
        width: 220px !important;
        height: 220px !important;
        max-height: 220px !important;
    }
    body[data-mdm-theme-lock="oscuro"] #grafica-eventos { min-height: 268px !important; }
    body[data-mdm-theme-lock="oscuro"] .adopcion-copy .porcentaje {
        color: #F7FBFD !important;
        font-size: 40px;
        line-height: 1;
    }
    body[data-mdm-theme-lock="oscuro"] .adopcion-copy .texto { color: #AFC0C9 !important; line-height: 1.55; }
    body[data-mdm-theme-lock="oscuro"] .adopcion-leyenda {
        margin-top: 18px;
        gap: 5px;
    }
    body[data-mdm-theme-lock="oscuro"] .adopcion-leyenda__fila {
        min-height: 31px;
        padding: 6px 8px;
        border: 1px solid #253943;
        border-radius: 4px;
        background: #121F27;
    }
    body[data-mdm-theme-lock="oscuro"] .adopcion-leyenda__fila i { width: 10px; height: 10px; border-radius: 3px; }
    body[data-mdm-theme-lock="oscuro"] .chart-periodo {
        color: #B8C9D1;
        background: #18262E;
        border-color: #3D515C;
    }
    @media (max-width: 900px) {
        body[data-mdm-theme-lock="oscuro"] .version-activa { padding-right: 24px !important; }
        .android-visual {
            position: relative;
            top: auto;
            right: auto;
            width: 100%;
            height: 190px;
            min-height: 0;
            margin-bottom: 20px;
            object-fit: cover;
            object-position: center 38%;
        }
    }
    @media (max-width: 620px) {
        body[data-mdm-theme-lock="oscuro"] .adopcion-centro { grid-template-columns: 1fr; gap: 18px; }
        body[data-mdm-theme-lock="oscuro"] #grafica-adopcion { margin: 0 auto; }
    }
</style>
<style id="actualizaciones-console-v2">
    body[data-mdm-theme-lock="oscuro"] {
        background: #080F14 !important;
    }
    body[data-mdm-theme-lock="oscuro"] .principal.actualizaciones-principal {
        max-width: 1480px !important;
        color: #EDF4F7 !important;
    }
    body[data-mdm-theme-lock="oscuro"] .hero-copy {
        min-height: 82px;
        margin: 0 0 20px;
        padding: 5px 2px 20px;
        background: transparent !important;
        border: 0 !important;
        border-bottom: 1px solid #2A3A43 !important;
        border-radius: 0 !important;
        box-shadow: none !important;
    }
    body[data-mdm-theme-lock="oscuro"] .hero-copy h1 {
        color: #F6FAFC !important;
        font-size: 34px;
        font-weight: 700;
    }
    body[data-mdm-theme-lock="oscuro"] .hero-copy p {
        max-width: 760px;
        color: #A9BBC4 !important;
        font-size: 13px;
    }
    body[data-mdm-theme-lock="oscuro"] .encabezado-pagina__etiqueta {
        color: #71DD83 !important;
        font-size: 10px;
    }
    .android-brand {
        display: grid;
        grid-template-columns: 48px auto;
        align-items: center;
        gap: 10px;
        min-width: 168px;
        height: 54px;
        padding: 3px 11px 3px 4px;
        color: #EAF2F5;
        background: #111C23;
        border: 1px solid #354852;
        border-radius: 7px;
    }
    .android-brand img {
        width: 46px;
        height: 46px;
        object-fit: cover;
        object-position: center 35%;
        border-radius: 5px;
    }
    .android-brand b,
    .android-brand small { display: block; }
    .android-brand b { color: #F2F8FA !important; font-size: 12px; }
    .android-brand small { margin-top: 3px; color: #8FA4AF; font-size: 9px; }

    body[data-mdm-theme-lock="oscuro"] .actualizaciones-hero {
        grid-template-columns: minmax(0, 1.12fr) minmax(390px, .88fr);
        gap: 18px;
        margin-bottom: 18px;
    }
    body[data-mdm-theme-lock="oscuro"] .version-activa {
        min-height: 0;
        padding: 24px !important;
        background: #11232D !important;
        border: 1px solid #38505D !important;
        border-top: 4px solid #53D769 !important;
        border-left: 1px solid #38505D !important;
        border-radius: 8px !important;
        box-shadow: 0 12px 30px rgba(0,0,0,.22) !important;
    }
    body[data-mdm-theme-lock="oscuro"] .version-activa__head { margin-bottom: 18px; }
    body[data-mdm-theme-lock="oscuro"] .version-activa__titulo {
        color: #8FE69C !important;
        font-size: 11px;
        letter-spacing: 0;
    }
    body[data-mdm-theme-lock="oscuro"] .version-activa__version {
        margin-top: 5px;
        color: #FFFFFF !important;
        font-size: 58px;
        line-height: 1;
    }
    body[data-mdm-theme-lock="oscuro"] .version-activa__grid { gap: 9px; }
    body[data-mdm-theme-lock="oscuro"] .version-activa__dato {
        min-height: 62px;
        padding: 10px 11px;
        background: #0C1920 !important;
        border: 1px solid #314650 !important;
        border-radius: 5px !important;
    }
    body[data-mdm-theme-lock="oscuro"] .version-activa__dato .lbl,
    body[data-mdm-theme-lock="oscuro"] .impacto-dato .lbl {
        color: #8EA4AE !important;
        font-size: 9px;
        letter-spacing: 0;
    }
    body[data-mdm-theme-lock="oscuro"] .version-activa__dato .val {
        color: #EDF5F7 !important;
        font-size: 12px;
        line-height: 1.45;
    }
    body[data-mdm-theme-lock="oscuro"] .version-activa__impacto {
        gap: 9px;
        margin-top: 12px;
    }
    body[data-mdm-theme-lock="oscuro"] .impacto-dato {
        min-height: 60px;
        padding: 10px 12px;
        background: #162C36 !important;
        border: 1px solid #38515C !important;
        border-radius: 5px !important;
    }
    body[data-mdm-theme-lock="oscuro"] .impacto-dato .num { font-size: 24px; }

    body[data-mdm-theme-lock="oscuro"] .panel-subida {
        background: #101A21 !important;
        border: 1px solid #344750 !important;
        border-radius: 8px !important;
        box-shadow: 0 10px 26px rgba(0,0,0,.18) !important;
    }
    body[data-mdm-theme-lock="oscuro"] .panel-subida .panel-head {
        min-height: 64px;
        padding: 16px 18px;
        background: #142129 !important;
    }
    body[data-mdm-theme-lock="oscuro"] .panel-subida .panel-head h2 { font-size: 16px; }
    body[data-mdm-theme-lock="oscuro"] .formulario { gap: 14px; padding: 18px; }
    body[data-mdm-theme-lock="oscuro"] .formulario label { font-size: 10px; color: #AFC0C8 !important; }
    body[data-mdm-theme-lock="oscuro"] .formulario input,
    body[data-mdm-theme-lock="oscuro"] .formulario textarea {
        min-height: 41px;
        color: #EAF2F5 !important;
        background: #0B141A !important;
        border-color: #425760 !important;
    }
    body[data-mdm-theme-lock="oscuro"] .formulario textarea { min-height: 92px; }

    body[data-mdm-theme-lock="oscuro"] .stats {
        gap: 12px;
        margin-bottom: 18px !important;
    }
    body[data-mdm-theme-lock="oscuro"] .stat {
        min-height: 92px;
        padding: 16px 17px;
        background: #111C23 !important;
        border: 1px solid #344750 !important;
        border-radius: 7px !important;
        box-shadow: none !important;
    }
    body[data-mdm-theme-lock="oscuro"] .stat .lbl { color: #9EB0B9 !important; font-size: 10px; }
    body[data-mdm-theme-lock="oscuro"] .stat .num { font-size: 31px; }

    body[data-mdm-theme-lock="oscuro"] .graficas-grid {
        grid-template-columns: minmax(390px, .82fr) minmax(0, 1.38fr);
        gap: 18px;
        margin-bottom: 18px;
    }
    body[data-mdm-theme-lock="oscuro"] .grafica-card {
        min-height: 430px;
        border-radius: 8px !important;
    }
    body[data-mdm-theme-lock="oscuro"] .grafica-card .panel-head {
        min-height: 72px;
        padding: 17px 20px;
    }
    body[data-mdm-theme-lock="oscuro"] .grafica-card .panel-head h2 {
        color: #F1F6F8 !important;
        font-size: 16px;
    }
    body[data-mdm-theme-lock="oscuro"] .grafica-card .panel-head .sub {
        margin-top: 5px;
        color: #99ADB6 !important;
        font-size: 11px;
    }
    body[data-mdm-theme-lock="oscuro"] .grafica-card .grafica-body {
        min-height: 354px;
        padding: 24px;
    }
    body[data-mdm-theme-lock="oscuro"] .adopcion-centro {
        width: 100%;
        grid-template-columns: 220px minmax(0, 1fr);
        gap: 30px;
    }
    body[data-mdm-theme-lock="oscuro"] #grafica-adopcion {
        width: 210px !important;
        height: 210px !important;
        max-height: 210px !important;
    }
    body[data-mdm-theme-lock="oscuro"] .adopcion-copy .porcentaje { display: none; }
    body[data-mdm-theme-lock="oscuro"] .adopcion-copy .texto {
        color: #C4D1D7 !important;
        font-size: 12px;
    }
    body[data-mdm-theme-lock="oscuro"] .adopcion-leyenda__fila {
        min-height: 36px;
        padding: 7px 10px;
        font-size: 11px;
    }
    body[data-mdm-theme-lock="oscuro"] #grafica-eventos { min-height: 300px !important; }

    @media (max-width: 1180px) {
        body[data-mdm-theme-lock="oscuro"] .actualizaciones-hero,
        body[data-mdm-theme-lock="oscuro"] .graficas-grid { grid-template-columns: 1fr; }
        body[data-mdm-theme-lock="oscuro"] .version-activa__grid { grid-template-columns: repeat(4, minmax(0,1fr)); }
    }
    @media (max-width: 760px) {
        body[data-mdm-theme-lock="oscuro"] .hero-copy { grid-template-columns: 1fr; }
        body[data-mdm-theme-lock="oscuro"] .acciones-encabezado { justify-content: flex-start; }
        .android-brand { width: 100%; }
        body[data-mdm-theme-lock="oscuro"] .version-activa__grid { grid-template-columns: 1fr 1fr; }
        body[data-mdm-theme-lock="oscuro"] .adopcion-centro { grid-template-columns: 1fr; }
        body[data-mdm-theme-lock="oscuro"] #grafica-adopcion { margin-inline: auto; }
    }
</style>
<style id="actualizaciones-telemetria-v3">
    body[data-mdm-theme-lock="oscuro"] .graficas-grid {
        grid-template-columns: minmax(380px, .88fr) minmax(560px, 1.12fr);
        align-items: stretch;
    }
    body[data-mdm-theme-lock="oscuro"] .grafica-card {
        min-height: 444px;
        background: #0D171D !important;
        border-color: #324750 !important;
        box-shadow: 0 18px 42px rgba(0,0,0,.22) !important;
    }
    body[data-mdm-theme-lock="oscuro"] .grafica-card .panel-head {
        min-height: 78px;
        background: #101D24 !important;
    }
    .telemetria-indice {
        flex: 0 0 auto;
        color: #7E98A5;
        font: 700 9px/1 'JetBrains Mono', monospace;
        white-space: nowrap;
    }
    body[data-mdm-theme-lock="oscuro"] .grafica-card .grafica-body {
        min-height: 364px;
        background: #0B151B !important;
    }

    .adopcion-telemetria {
        display: flex;
        flex-direction: column;
        gap: 20px;
        padding: 25px !important;
    }
    .adopcion-cifra {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        grid-template-rows: auto auto;
        column-gap: 20px;
        align-items: end;
    }
    .adopcion-cifra > span {
        color: #8CA2AD;
        font: 700 10px/1 'JetBrains Mono', monospace;
        text-transform: uppercase;
    }
    .adopcion-cifra strong {
        grid-column: 2;
        grid-row: 1 / 3;
        color: #F4F9FB !important;
        font: 700 48px/1 'Space Grotesk', sans-serif;
    }
    .adopcion-cifra p {
        margin-top: 8px;
        color: #C1D0D6 !important;
        font-size: 12px;
        line-height: 1.45;
    }
    .ruta-despliegue {
        display: flex;
        width: 100%;
        height: 16px;
        overflow: hidden;
        background: #17252C;
        border: 1px solid #38505A;
        border-radius: 3px;
    }
    .ruta-despliegue__tramo {
        width: var(--ancho);
        min-width: var(--minimo, 0);
        height: 100%;
        background: var(--estado-color);
        transform: scaleX(0);
        transform-origin: left;
        animation: desplegarTramo .7s cubic-bezier(.16,1,.3,1) forwards;
    }
    .ruta-despliegue__tramo + .ruta-despliegue__tramo { border-left: 2px solid #0B151B; }
    .matriz-head { display: flex; justify-content: space-between; gap: 12px; align-items: center; }
    .matriz-head span { color: #8CA2AD; font-size: 10px; text-transform: uppercase; font-weight: 700; }
    .matriz-head b { color: #DCE7EB !important; font: 600 10px/1 'JetBrains Mono', monospace; }
    .matriz-unidades {
        display: grid;
        grid-template-columns: repeat(auto-fill, 18px);
        align-content: start;
        gap: 7px;
        min-height: 45px;
        padding: 13px;
        background: #0E1A20;
        border: 1px solid #293E48;
        border-radius: 5px;
    }
    .matriz-nodo {
        width: 18px;
        height: 18px;
        border: 1px solid color-mix(in srgb, var(--nodo-color) 75%, #FFFFFF 25%);
        border-radius: 3px;
        background: color-mix(in srgb, var(--nodo-color) 72%, transparent);
        box-shadow: inset 0 0 0 3px #0E1A20;
        opacity: 0;
        animation: revelarNodo .28s ease forwards;
    }
    .matriz-vacia { color: #78909B; font-size: 11px; }
    .adopcion-telemetria .adopcion-leyenda {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 7px;
        margin: 0;
    }
    .adopcion-telemetria .adopcion-leyenda__fila {
        min-height: 38px !important;
        padding: 8px 10px !important;
        border: 1px solid #2A3F49 !important;
        background: #101D24 !important;
    }

    body[data-mdm-theme-lock="oscuro"] .pulso-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0;
        padding: 0 !important;
        overflow: hidden;
    }
    .pulso-item {
        min-width: 0;
        min-height: 182px;
        padding: 17px 19px 14px;
        background: #0B151B;
        border-right: 1px solid #293D46;
        border-bottom: 1px solid #293D46;
    }
    .pulso-item:nth-child(2n) { border-right: 0; }
    .pulso-item:nth-child(n+3) { border-bottom: 0; }
    .pulso-item header { display: flex; align-items: center; justify-content: space-between; gap: 14px; }
    .pulso-item header span {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        color: #AFC0C8;
        font-size: 11px;
        font-weight: 700;
    }
    .pulso-item header svg { width: 15px; height: 15px; color: var(--pulso); }
    .pulso-item header strong {
        color: var(--pulso) !important;
        font: 700 25px/1 'Space Grotesk', sans-serif;
    }
    .pulso-canvas { position: relative; height: 112px; margin-top: 10px; }
    .pulso-canvas canvas { width: 100% !important; height: 112px !important; }

    @keyframes desplegarTramo { to { transform: scaleX(1); } }
    @keyframes revelarNodo { to { opacity: 1; } }
    @media (max-width: 1180px) {
        body[data-mdm-theme-lock="oscuro"] .graficas-grid { grid-template-columns: 1fr; }
    }
    @media (max-width: 650px) {
        body[data-mdm-theme-lock="oscuro"] .pulso-grid { grid-template-columns: 1fr; }
        .pulso-item { border-right: 0; border-bottom: 1px solid #293D46 !important; }
        .pulso-item:last-child { border-bottom: 0 !important; }
        .adopcion-telemetria .adopcion-leyenda { grid-template-columns: 1fr; }
        .adopcion-cifra { grid-template-columns: 1fr; }
        .adopcion-cifra strong { grid-column: 1; grid-row: auto; margin-top: 12px; }
    }
    @media (prefers-reduced-motion: reduce) {
        .ruta-despliegue__tramo, .matriz-nodo { animation: none !important; transform: none !important; opacity: 1 !important; }
    }
</style>
<style id="actualizaciones-brand-v4">
    /* Identidad Android MDM: azul, verde y superficies claras de alta legibilidad. */
    body[data-mdm-theme-lock="oscuro"] {
        background: #EAF2F7 !important;
        color: #102A43 !important;
    }
    body[data-mdm-theme-lock="oscuro"] .principal.actualizaciones-principal {
        --brand-blue: #1457B8;
        --brand-blue-dark: #0B347D;
        --brand-green: #55C92C;
        --brand-green-dark: #2E9E2D;
        --brand-ink: #102A43;
        --brand-muted: #526B7A;
        --brand-line: #D5E3EC;
        max-width: 1480px !important;
        color: var(--brand-ink) !important;
    }
    body[data-mdm-theme-lock="oscuro"] .hero-copy {
        min-height: 88px;
        margin: 0 0 16px;
        padding: 18px 22px !important;
        border: 1px solid var(--brand-line) !important;
        border-radius: 14px !important;
        background: rgba(255,255,255,.94) !important;
        box-shadow: 0 8px 24px rgba(16,55,86,.07) !important;
    }
    body[data-mdm-theme-lock="oscuro"] .hero-copy h1 {
        color: var(--brand-ink) !important;
        font-size: clamp(28px,3vw,38px);
        letter-spacing: -.025em;
    }
    body[data-mdm-theme-lock="oscuro"] .hero-copy p { color: var(--brand-muted) !important; font-size: 13px; }
    body[data-mdm-theme-lock="oscuro"] .encabezado-pagina__etiqueta { color: var(--brand-blue) !important; }
    body[data-mdm-theme-lock="oscuro"] .encabezado-pagina__etiqueta::before { background: var(--brand-green) !important; }
    body[data-mdm-theme-lock="oscuro"] .android-brand {
        color: var(--brand-ink);
        background: #F4F9FC;
        border-color: #CFE0EA;
        border-radius: 10px;
    }
    body[data-mdm-theme-lock="oscuro"] .android-brand img { object-fit: contain; background: #EAF5FB; }
    body[data-mdm-theme-lock="oscuro"] .android-brand b { color: var(--brand-ink) !important; }
    body[data-mdm-theme-lock="oscuro"] .android-brand small { color: #647C8A; }

    .update-showcase {
        position: relative;
        isolation: isolate;
        display: grid;
        grid-template-columns: minmax(360px,.78fr) minmax(520px,1.22fr);
        height: 330px;
        margin-bottom: 18px;
        overflow: hidden;
        border: 1px solid #164D9B;
        border-radius: 18px;
        background: linear-gradient(120deg,#092D72 0%,#1457B8 48%,#E8F6FF 48%,#F7FCFF 100%);
        box-shadow: 0 18px 44px rgba(15,59,119,.16);
        animation: updateShowcaseIn .6s cubic-bezier(.2,.75,.25,1) both;
    }
    .update-showcase::before {
        content: "";
        position: absolute;
        z-index: -1;
        inset: 0 50% 0 0;
        background: radial-gradient(circle at 15% 20%,rgba(100,220,60,.22),transparent 34%);
    }
    .update-showcase__copy {
        position: relative;
        z-index: 2;
        display: flex;
        flex-direction: column;
        justify-content: center;
        padding: 36px 40px;
    }
    .update-showcase__eyebrow {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        width: max-content;
        margin-bottom: 12px;
        color: #BFE6FF;
        font-size: 10px;
        font-weight: 800;
        letter-spacing: .12em;
        text-transform: uppercase;
    }
    .update-showcase__eyebrow svg { width: 15px; height: 15px; color: #75E44E; }
    body[data-mdm-theme-lock="oscuro"] .update-showcase h2 {
        max-width: 470px;
        margin: 0;
        color: #FFFFFF !important;
        font: 700 clamp(27px,2.2vw,36px)/1.08 'Space Grotesk',sans-serif;
        letter-spacing: -.03em;
    }
    body[data-mdm-theme-lock="oscuro"] .update-showcase p {
        max-width: 470px;
        margin-top: 12px;
        color: #EAF6FF !important;
        font-size: 14px;
        line-height: 1.55;
    }
    .update-showcase__features { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 20px; }
    .update-showcase__features span {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 6px 9px;
        border: 1px solid rgba(255,255,255,.25);
        border-radius: 999px;
        background: rgba(2,22,63,.24);
        color: #FFFFFF;
        font-size: 10px;
        font-weight: 700;
    }
    .update-showcase__features svg { width: 13px; height: 13px; color: #75E44E; }
    .update-showcase__art { position: relative; min-width: 0; height: 100%; }
    .update-showcase__art::before {
        content: "";
        position: absolute;
        z-index: 2;
        inset: 0 auto 0 0;
        width: 20%;
        background: linear-gradient(90deg,#1457B8,transparent);
        pointer-events: none;
    }
    .update-showcase__art img {
        display: block;
        width: 100%;
        height: 100%;
        object-fit: contain;
        object-position: center;
        transform: translate3d(0,0,0) scale(.97);
        filter: drop-shadow(0 16px 20px rgba(12,48,105,.18));
        animation: updateVisualFloat 8s ease-in-out infinite alternate;
        will-change: transform;
    }

    body[data-mdm-theme-lock="oscuro"] .actualizaciones-hero { gap: 16px; }
    body[data-mdm-theme-lock="oscuro"] .version-activa {
        background: linear-gradient(135deg,#0A347E,#1457B8 68%,#1970D1) !important;
        border: 1px solid #174C9B !important;
        border-top: 4px solid var(--brand-green) !important;
        border-radius: 14px !important;
        box-shadow: 0 14px 32px rgba(14,55,119,.17) !important;
    }
    body[data-mdm-theme-lock="oscuro"] .version-activa__titulo { color: #C7F6B5 !important; }
    body[data-mdm-theme-lock="oscuro"] .version-activa__head p { color: #E6F2FF !important; }
    body[data-mdm-theme-lock="oscuro"] .version-activa__dato,
    body[data-mdm-theme-lock="oscuro"] .impacto-dato {
        background: rgba(4,30,83,.28) !important;
        border-color: rgba(255,255,255,.2) !important;
        border-radius: 8px !important;
    }
    body[data-mdm-theme-lock="oscuro"] .principal.actualizaciones-principal .panel,
    body[data-mdm-theme-lock="oscuro"] .principal.actualizaciones-principal .stat {
        color: var(--brand-ink) !important;
        background: #FFFFFF !important;
        border-color: var(--brand-line) !important;
        border-radius: 13px !important;
        box-shadow: 0 8px 24px rgba(16,55,86,.07) !important;
    }
    body[data-mdm-theme-lock="oscuro"] .principal.actualizaciones-principal .panel-head,
    body[data-mdm-theme-lock="oscuro"] .principal.actualizaciones-principal .grafica-body,
    body[data-mdm-theme-lock="oscuro"] .principal.actualizaciones-principal .seguimiento-resumen,
    body[data-mdm-theme-lock="oscuro"] .principal.actualizaciones-principal .bitacora-cta {
        color: var(--brand-ink) !important;
        background: #FFFFFF !important;
        border-color: #E0E9EF !important;
    }
    body[data-mdm-theme-lock="oscuro"] .principal.actualizaciones-principal .panel-head h2,
    body[data-mdm-theme-lock="oscuro"] .principal.actualizaciones-principal .stat .num { color: var(--brand-ink) !important; }
    body[data-mdm-theme-lock="oscuro"] .principal.actualizaciones-principal .panel-head .sub,
    body[data-mdm-theme-lock="oscuro"] .principal.actualizaciones-principal .stat .lbl,
    body[data-mdm-theme-lock="oscuro"] .principal.actualizaciones-principal .hint,
    body[data-mdm-theme-lock="oscuro"] .principal.actualizaciones-principal .muted { color: var(--brand-muted) !important; }
    body[data-mdm-theme-lock="oscuro"] .stat { border-top: 3px solid var(--brand-blue) !important; }
    body[data-mdm-theme-lock="oscuro"] .stat.stat-ok { border-top-color: var(--brand-green-dark) !important; }
    body[data-mdm-theme-lock="oscuro"] .stat .stat-icon { border-radius: 9px !important; }
    body[data-mdm-theme-lock="oscuro"] .principal.actualizaciones-principal input,
    body[data-mdm-theme-lock="oscuro"] .principal.actualizaciones-principal textarea,
    body[data-mdm-theme-lock="oscuro"] .principal.actualizaciones-principal select,
    body[data-mdm-theme-lock="oscuro"] .principal.actualizaciones-principal .check-row {
        color: var(--brand-ink) !important;
        background: #F9FCFE !important;
        border-color: #BFD2DE !important;
        border-radius: 8px !important;
    }
    body[data-mdm-theme-lock="oscuro"] .principal.actualizaciones-principal input:focus,
    body[data-mdm-theme-lock="oscuro"] .principal.actualizaciones-principal textarea:focus,
    body[data-mdm-theme-lock="oscuro"] .principal.actualizaciones-principal select:focus {
        border-color: var(--brand-blue) !important;
        box-shadow: 0 0 0 3px rgba(20,87,184,.13) !important;
    }
    body[data-mdm-theme-lock="oscuro"] .principal.actualizaciones-principal .btn {
        color: #17364B !important;
        background: #FFFFFF !important;
        border-color: #B9CDD9 !important;
        border-radius: 8px !important;
        box-shadow: none !important;
    }
    body[data-mdm-theme-lock="oscuro"] .principal.actualizaciones-principal .btn:hover {
        color: var(--brand-blue) !important;
        background: #F1F7FB !important;
        border-color: var(--brand-blue) !important;
    }
    body[data-mdm-theme-lock="oscuro"] .principal.actualizaciones-principal .btn.primario,
    body[data-mdm-theme-lock="oscuro"] .principal.actualizaciones-principal .btn.oscuro {
        color: #FFFFFF !important;
        background: var(--brand-blue) !important;
        border-color: var(--brand-blue) !important;
    }
    body[data-mdm-theme-lock="oscuro"] .principal.actualizaciones-principal .btn.peligro { color: #A32636 !important; background: #FFF8F9 !important; border-color: #E0B2B9 !important; }
    body[data-mdm-theme-lock="oscuro"] .grafica-card { border-top: 3px solid var(--brand-blue) !important; }
    body[data-mdm-theme-lock="oscuro"] .grafica-card:first-child { border-top-color: var(--brand-green-dark) !important; }
    body[data-mdm-theme-lock="oscuro"] .grafica-card .grafica-body { background: #F8FBFD !important; }
    body[data-mdm-theme-lock="oscuro"] .adopcion-cifra > span,
    body[data-mdm-theme-lock="oscuro"] .matriz-head span { color: #587180; }
    body[data-mdm-theme-lock="oscuro"] .adopcion-cifra strong,
    body[data-mdm-theme-lock="oscuro"] .matriz-head b { color: var(--brand-ink) !important; }
    body[data-mdm-theme-lock="oscuro"] .adopcion-cifra p { color: var(--brand-muted) !important; }
    body[data-mdm-theme-lock="oscuro"] .ruta-despliegue { background: #E2ECF2; border-color: #BFD2DE; }
    body[data-mdm-theme-lock="oscuro"] .matriz-unidades { background: #EDF4F8; border-color: #CFDEE7; }
    body[data-mdm-theme-lock="oscuro"] .matriz-nodo { box-shadow: inset 0 0 0 3px #EDF4F8; }
    body[data-mdm-theme-lock="oscuro"] .adopcion-telemetria .adopcion-leyenda__fila { color: #29485C !important; background: #FFFFFF !important; border-color: #D5E3EC !important; }
    body[data-mdm-theme-lock="oscuro"] .pulso-item { background: #F8FBFD; border-color: #DCE7ED; }
    body[data-mdm-theme-lock="oscuro"] .pulso-item header span { color: #4E6878; }
    body[data-mdm-theme-lock="oscuro"] .principal.actualizaciones-principal th { color: #FFFFFF !important; background: #123C70 !important; border-color: #315D8D !important; }
    body[data-mdm-theme-lock="oscuro"] .principal.actualizaciones-principal td { color: #19374A !important; background: #FFFFFF !important; border-color: #DDE7ED !important; }
    body[data-mdm-theme-lock="oscuro"] .principal.actualizaciones-principal tbody tr:hover td { color: #102A43 !important; background: #EFF8EE !important; }
    body[data-mdm-theme-lock="oscuro"] .principal.actualizaciones-principal .seguimiento-resumen .mini-stat { color: var(--brand-ink) !important; background: #F7FBFD !important; border-color: #D2E1E9 !important; }

    @keyframes updateShowcaseIn { from { opacity:0; transform:translate3d(0,12px,0); } to { opacity:1; transform:none; } }
    @keyframes updateVisualFloat { from { transform:translate3d(0,2px,0) scale(.97); } to { transform:translate3d(0,-3px,0) scale(.985); } }
    @media (max-width: 1000px) {
        .update-showcase { grid-template-columns: minmax(330px,.9fr) minmax(380px,1.1fr); height: 310px; }
        .update-showcase__copy { padding: 30px; }
    }
    @media (max-width: 760px) {
        .update-showcase { grid-template-columns: 1fr; height: auto; background: #0D4395; }
        .update-showcase__copy { min-height: 245px; padding: 26px 22px; }
        .update-showcase__art { height: 290px; background: linear-gradient(145deg,#E8F6FF,#FFFFFF); }
        .update-showcase__art::before { inset: 0 0 auto; width: 100%; height: 25%; background: linear-gradient(180deg,#0D4395,transparent); }
    }
    @media (prefers-reduced-motion: reduce) {
        .update-showcase,.update-showcase__art img { animation: none !important; }
    }

    /* Correccion de cascada: esta vista conserva una sola identidad clara. */
    body[data-mdm-theme-lock="oscuro"].tema-mdm-oscuro {
        background: #EAF2F7 !important;
    }
    body[data-mdm-theme-lock="oscuro"] .principal.actualizaciones-principal {
        padding: 28px 34px 54px !important;
        background: #EAF2F7 !important;
    }
    body[data-mdm-theme-lock="oscuro"] .principal.actualizaciones-principal .hero-copy {
        min-height: auto !important;
        margin: 0 0 16px !important;
        padding: 18px 22px !important;
        color: #102A43 !important;
        background: #FFFFFF !important;
        border: 1px solid #D5E3EC !important;
        border-radius: 14px !important;
        box-shadow: 0 8px 24px rgba(16,55,86,.07) !important;
    }
    body[data-mdm-theme-lock="oscuro"] .principal.actualizaciones-principal .hero-copy h1 { color: #102A43 !important; }
    body[data-mdm-theme-lock="oscuro"] .principal.actualizaciones-principal .hero-copy p { color: #526B7A !important; }

    body[data-mdm-theme-lock="oscuro"] .principal.actualizaciones-principal .update-showcase {
        display: block;
        height: 306px;
        min-height: 306px;
        background: #0C4296;
    }
    body[data-mdm-theme-lock="oscuro"] .principal.actualizaciones-principal .update-showcase__copy {
        position: absolute;
        z-index: 4;
        inset: 0 auto 0 0;
        box-sizing: border-box;
        width: 45%;
        min-height: 0;
        padding: 34px 38px;
        justify-content: center;
        background: linear-gradient(115deg,#092E73 0%,#1457B8 100%);
    }
    body[data-mdm-theme-lock="oscuro"] .principal.actualizaciones-principal .update-showcase__copy h2 {
        display: block;
        margin: 0 !important;
        color: #FFFFFF !important;
        font-size: clamp(27px,2.1vw,35px) !important;
        line-height: 1.08 !important;
    }
    body[data-mdm-theme-lock="oscuro"] .principal.actualizaciones-principal .update-showcase__copy p {
        display: block;
        margin: 11px 0 0 !important;
        color: #EAF6FF !important;
    }
    body[data-mdm-theme-lock="oscuro"] .principal.actualizaciones-principal .update-showcase__art {
        position: absolute;
        inset: 0 0 0 43%;
        height: auto;
        background: radial-gradient(circle at 55% 42%,#FFFFFF 0%,#EAF7FF 64%,#DCEFFA 100%);
    }
    body[data-mdm-theme-lock="oscuro"] .principal.actualizaciones-principal .update-showcase__art::before {
        inset: 0 auto 0 0;
        width: 16%;
        height: auto;
        background: linear-gradient(90deg,#1457B8,rgba(20,87,184,.35),transparent);
    }
    body[data-mdm-theme-lock="oscuro"] .principal.actualizaciones-principal .update-showcase__art img {
        box-sizing: border-box;
        width: 100%;
        height: 100%;
        padding: 10px 18px;
        object-fit: contain;
        object-position: center;
    }

    body[data-mdm-theme-lock="oscuro"] .principal.actualizaciones-principal .panel-subida,
    body[data-mdm-theme-lock="oscuro"] .principal.actualizaciones-principal .panel-subida .panel-head,
    body[data-mdm-theme-lock="oscuro"] .principal.actualizaciones-principal .panel-subida .formulario {
        color: #102A43 !important;
        background: #FFFFFF !important;
    }
    body[data-mdm-theme-lock="oscuro"] .principal.actualizaciones-principal .panel-subida .panel-head h2 { color: #102A43 !important; }
    body[data-mdm-theme-lock="oscuro"] .principal.actualizaciones-principal .panel-subida .panel-head .sub,
    body[data-mdm-theme-lock="oscuro"] .principal.actualizaciones-principal .panel-subida .hint { color: #5B7281 !important; }
    body[data-mdm-theme-lock="oscuro"] .principal.actualizaciones-principal .panel-subida label { color: #1457B8 !important; }
    body[data-mdm-theme-lock="oscuro"] .principal.actualizaciones-principal .panel-subida input,
    body[data-mdm-theme-lock="oscuro"] .principal.actualizaciones-principal .panel-subida textarea,
    body[data-mdm-theme-lock="oscuro"] .principal.actualizaciones-principal .panel-subida select,
    body[data-mdm-theme-lock="oscuro"] .principal.actualizaciones-principal .panel-subida .check-row {
        color: #17364B !important;
        background: #F8FBFD !important;
        border-color: #BFD2DE !important;
    }
    body[data-mdm-theme-lock="oscuro"] .principal.actualizaciones-principal .panel-subida input::placeholder,
    body[data-mdm-theme-lock="oscuro"] .principal.actualizaciones-principal .panel-subida textarea::placeholder { color: #718794 !important; opacity: 1; }

    @media (max-width: 900px) {
        body[data-mdm-theme-lock="oscuro"] .principal.actualizaciones-principal { padding: 20px 18px 44px !important; }
        body[data-mdm-theme-lock="oscuro"] .principal.actualizaciones-principal .update-showcase { height: auto; min-height: 0; }
        body[data-mdm-theme-lock="oscuro"] .principal.actualizaciones-principal .update-showcase__copy,
        body[data-mdm-theme-lock="oscuro"] .principal.actualizaciones-principal .update-showcase__art { position: relative; inset: auto; width: 100%; }
        body[data-mdm-theme-lock="oscuro"] .principal.actualizaciones-principal .update-showcase__copy { min-height: 240px; padding: 28px 24px; }
        body[data-mdm-theme-lock="oscuro"] .principal.actualizaciones-principal .update-showcase__art { height: 300px; }
        body[data-mdm-theme-lock="oscuro"] .principal.actualizaciones-principal .update-showcase__art::before { inset: 0 0 auto; width: 100%; height: 22%; background: linear-gradient(180deg,#1457B8,transparent); }
    }

    /* Contraste final: neutraliza los colores pastel heredados del tema oscuro. */
    body[data-mdm-theme-lock="oscuro"] .principal.actualizaciones-principal .seguimiento-resumen .mini-stat .lbl {
        color: #425F73 !important;
        opacity: 1 !important;
    }
    body[data-mdm-theme-lock="oscuro"] .principal.actualizaciones-principal .seguimiento-resumen .mini-stat .num {
        color: #102A43 !important;
        opacity: 1 !important;
    }
    body[data-mdm-theme-lock="oscuro"] .principal.actualizaciones-principal .seguimiento-resumen .mini-stat[data-estado="pendiente"] .lbl,
    body[data-mdm-theme-lock="oscuro"] .principal.actualizaciones-principal .seguimiento-resumen .mini-stat[data-estado="pendiente"] .num { color: #174F96 !important; }
    body[data-mdm-theme-lock="oscuro"] .principal.actualizaciones-principal .seguimiento-resumen .mini-stat[data-estado="descargando"] .lbl,
    body[data-mdm-theme-lock="oscuro"] .principal.actualizaciones-principal .seguimiento-resumen .mini-stat[data-estado="descargando"] .num { color: #82510B !important; }
    body[data-mdm-theme-lock="oscuro"] .principal.actualizaciones-principal .seguimiento-resumen .mini-stat[data-estado="instalada"] .lbl,
    body[data-mdm-theme-lock="oscuro"] .principal.actualizaciones-principal .seguimiento-resumen .mini-stat[data-estado="instalada"] .num { color: #236D37 !important; }
    body[data-mdm-theme-lock="oscuro"] .principal.actualizaciones-principal .seguimiento-resumen .mini-stat[data-estado="error"] .lbl,
    body[data-mdm-theme-lock="oscuro"] .principal.actualizaciones-principal .seguimiento-resumen .mini-stat[data-estado="error"] .num { color: #9B2940 !important; }

    body[data-mdm-theme-lock="oscuro"] .principal.actualizaciones-principal .badge-info {
        color: #174F96 !important;
        background: #E8F1FD !important;
        border-color: #BDD1EC !important;
    }
    body[data-mdm-theme-lock="oscuro"] .principal.actualizaciones-principal .badge-ok {
        color: #236D37 !important;
        background: #E8F6EB !important;
        border-color: #B9DFC2 !important;
    }
    body[data-mdm-theme-lock="oscuro"] .principal.actualizaciones-principal .badge-warn {
        color: #82510B !important;
        background: #FFF2DC !important;
        border-color: #EACD96 !important;
    }
    body[data-mdm-theme-lock="oscuro"] .principal.actualizaciones-principal .badge-danger {
        color: #9B2940 !important;
        background: #FBE9ED !important;
        border-color: #E8BBC5 !important;
    }
    body[data-mdm-theme-lock="oscuro"] .principal.actualizaciones-principal td a,
    body[data-mdm-theme-lock="oscuro"] .principal.actualizaciones-principal .version-activa a {
        color: #1457B8 !important;
        font-weight: 800 !important;
    }
    body[data-mdm-theme-lock="oscuro"] .principal.actualizaciones-principal .bitacora-cta h2 { color: #102A43 !important; }
    body[data-mdm-theme-lock="oscuro"] .principal.actualizaciones-principal .bitacora-cta p { color: #526B7A !important; opacity: 1 !important; }
    body[data-mdm-theme-lock="oscuro"] .principal.actualizaciones-principal .bitacora-icono {
        color: #1457B8 !important;
        background: #E8F1FD !important;
        border-color: #BDD1EC !important;
    }
</style>
<script>
const CANAL_APP = <?= json_encode($canalServidor, JSON_UNESCAPED_UNICODE) ?>;
const CANAL_QUERY = `canal_app=${encodeURIComponent(CANAL_APP)}`;
const estado = { actualizaciones: [], eventos: [], seguimiento: null, resumen30: {} };
const graficas = { pulsos: [] };
const $ = sel => document.querySelector(sel);

function badge(texto, clase) {
    return `<span class="badge ${clase}">${escapeHtml(texto)}</span>`;
}

function canalEtiqueta(canal) {
    return 'Clientes';
}

function estaPublicada(a) {
    if (!a.activa) return false;
    if (!a.fecha_publicacion) return true;
    const fecha = new Date(a.fecha_publicacion);
    return Number.isNaN(fecha.getTime()) || fecha <= new Date();
}

function estadoPublicacion(a) {
    if (!a.activa) return badge('Manual/pausada', 'badge-danger');
    if (!estaPublicada(a)) return badge('Programada', 'badge-warn');
    return badge('Publicada', 'badge-ok');
}

function fechaCorta(fecha) {
    if (!fecha) return '-';
    const d = new Date(fecha);
    return Number.isNaN(d.getTime()) ? escapeHtml(fecha) : d.toLocaleString('es-MX');
}

function fechaInput(fecha) {
    if (!fecha) return '';
    const d = new Date(fecha);
    if (Number.isNaN(d.getTime())) {
        return String(fecha).replace(' ', 'T').substring(0, 16);
    }
    const pad = n => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

function renderVersiones() {
    const cont = $('#tabla-versiones');
    if (!estado.actualizaciones.length) {
        cont.innerHTML = '<div class="vacio">Todavia no hay APKs publicados.</div>';
        return;
    }

    cont.innerHTML = `<div class="table-shell"><table>
        <thead><tr>
            <th>Version</th><th>Canal</th><th>Estado</th><th>APK</th><th>SHA-256</th><th>Fecha</th><th>Acciones</th>
        </tr></thead>
        <tbody>${estado.actualizaciones.map(a => `
            <tr>
                <td><strong>${escapeHtml(a.version_name)}</strong><div class="muted mono">${a.version_code}</div></td>
                <td>${badge(canalEtiqueta(a.canal_app), 'badge-info')}</td>
                <td>
                    ${estadoPublicacion(a)}
                    ${a.activa ? badge('Activa', 'badge-info') : ''}
                    ${a.obligatoria ? badge('Obligatoria', 'badge-warn') : badge('Opcional', 'badge-info')}
                </td>
                <td>
                    <a href="${escapeHtml(a.apk_url)}" target="_blank" rel="noopener">Descargar</a>
                    <div class="muted">${a.apk_archivo_existe ? `Archivo OK (${Math.round((a.apk_archivo_tamano || 0) / 1024 / 1024)} MB)` : 'Archivo no encontrado'}</div>
                    <div class="muted">${escapeHtml(a.notas || '')}</div>
                </td>
                <td class="mono">${escapeHtml(a.sha256)}</td>
                <td>${fechaCorta(a.fecha)}<div class="muted">Publica: ${a.fecha_publicacion ? fechaCorta(a.fecha_publicacion) : 'inmediata'}</div></td>
                <td>
                    <div class="acciones-tabla">
                        <label class="check-row"><input type="checkbox" data-id="${a.id_actualizacion}" data-campo="activa" ${a.activa ? 'checked' : ''}> Activa</label>
                        <label class="check-row"><input type="checkbox" data-id="${a.id_actualizacion}" data-campo="obligatoria" ${a.obligatoria ? 'checked' : ''}> Obligatoria</label>
                        <input class="fecha-mini" type="datetime-local" data-id="${a.id_actualizacion}" data-campo="fecha_publicacion" value="${escapeHtml(fechaInput(a.fecha_publicacion))}" title="Programar publicacion">
                    </div>
                </td>
            </tr>
        `).join('')}</tbody>
    </table></div>`;

    cont.querySelectorAll('input[type="checkbox"], input[type="datetime-local"]').forEach(chk => {
        chk.addEventListener('change', guardarEstadoVersion);
    });
}

function renderEventos() {
    const cont = $('#bitacora-preview');
    if (!cont) return;
    if (!estado.eventos.length) {
        cont.innerHTML = '<span class="badge badge-info">Sin eventos todavia</span>';
        return;
    }

    const claseEvento = {
        consulta: 'badge-info',
        descarga: 'badge-ok',
        instalacion: 'badge-ok',
        error: 'badge-danger',
    };

    cont.innerHTML = estado.eventos.slice(0, 6).map(ev => (
        badge(`${ev.tipo_evento || '-'} · ${ev.numero_serie || 'sin serie'}`, claseEvento[ev.tipo_evento] || 'badge-info')
    )).join('');
}

function renderSelectorSeguimiento() {
    const select = $('#seguimiento-version');
    if (!estado.actualizaciones.length) {
        select.innerHTML = '<option value="">Sin versiones</option>';
        return;
    }
    const valorActual = select.value;
    select.innerHTML = estado.actualizaciones.map(a => (
        `<option value="${a.id_actualizacion}">${escapeHtml(canalEtiqueta(a.canal_app))} - ${escapeHtml(a.version_name)} (${escapeHtml(a.version_code)})</option>`
    )).join('');
    const activa = estado.actualizaciones.filter(estaPublicada)
        .sort((a, b) => Number(b.version_code) - Number(a.version_code))[0];
    select.value = valorActual || (activa ? String(activa.id_actualizacion) : String(estado.actualizaciones[0].id_actualizacion));
}

function renderSeguimiento() {
    const data = estado.seguimiento;
    const resumen = data?.resumen || {};
    $('#seguimiento-resumen').innerHTML = [
        ['pendiente', 'Pendientes'],
        ['descargando', 'Descargando'],
        ['instalada', 'Instaladas'],
        ['error', 'Errores'],
    ].map(([key, label]) => `<div class="mini-stat" data-estado="${key}"><div class="lbl">${label}</div><div class="num">${resumen[key] || 0}</div></div>`).join('');

    const cont = $('#tabla-seguimiento');
    const rows = data?.seguimiento || [];
    if (!rows.length) {
        cont.innerHTML = '<div class="vacio">Sin eventos para esta actualizacion. La app todavia no ha consultado o reportado avance.</div>';
        return;
    }

    const claseEstado = {
        pendiente: 'badge-info',
        descargando: 'badge-warn',
        instalada: 'badge-ok',
        error: 'badge-danger',
    };

    cont.innerHTML = `<div class="table-shell"><table>
        <thead><tr>
            <th>Maquina</th><th>Estado</th><th>Version anterior</th><th>Version objetivo</th><th>Ultimo evento</th><th>Error</th>
        </tr></thead>
        <tbody>${rows.map(r => `
            <tr class="${r.estado === 'error' ? 'row-error' : (r.estado === 'instalada' ? 'row-ok' : (r.estado === 'descargando' ? 'row-warn' : ''))}">
                <td>
                    <strong>${escapeHtml(r.nombre_maquina || r.nombre_ubicacion || '-')}</strong>
                    <div class="muted mono">${escapeHtml(r.numero_serie || r.codigo_interno || '-')}</div>
                </td>
                <td>${badge(r.estado || '-', claseEstado[r.estado] || 'badge-info')}</td>
                <td class="mono">${r.version_code_actual || '-'} ${escapeHtml(r.version_name_actual || '')}</td>
                <td class="mono">${r.version_code_objetivo || data.actualizacion?.version_code || '-'}</td>
                <td>${fechaCorta(r.fecha_ultimo_evento)}<div class="muted">${escapeHtml(r.ultimo_evento || '')}</div></td>
                <td><strong>${escapeHtml(r.codigo_error || '')}</strong><div class="muted">${escapeHtml(r.mensaje || '')}</div></td>
            </tr>
        `).join('')}</tbody>
    </table></div>`;
}

async function cargarSeguimiento() {
    const id = $('#seguimiento-version').value;
    $('#tabla-seguimiento').innerHTML = '<div class="cargando">Cargando...</div>';
    const json = await obtenerJSON(`api/app_actualizaciones_seguimiento.php?${CANAL_QUERY}${id ? `&id_actualizacion=${encodeURIComponent(id)}` : ''}`);
    estado.seguimiento = json.data;
    renderSeguimiento();
    renderVersionActiva();
    renderGraficas();
}

function renderKpis(resumen) {
    $('#kpi-consulta').textContent = resumen.consulta || 0;
    $('#kpi-descarga').textContent = resumen.descarga || 0;
    $('#kpi-instalacion').textContent = resumen.instalacion || 0;
    $('#kpi-error').textContent = resumen.error || 0;
}

function renderVersionActiva() {
    const cont = $('#version-activa');
    const activa = estado.actualizaciones
        .filter(estaPublicada)
        .sort((a, b) => Number(b.version_code) - Number(a.version_code) || String(b.fecha || '').localeCompare(String(a.fecha || '')))[0];
    const resumen = estado.seguimiento?.resumen || {};
    const instaladas = Number(resumen.instalada || 0);
    const descargando = Number(resumen.descargando || 0);
    const pendientes = Number(resumen.pendiente || 0);
    const errores = Number(resumen.error || 0);
    const total = instaladas + descargando + pendientes + errores;
    const porcentaje = total ? Math.round((instaladas / total) * 100) : 0;

    if (!activa) {
        cont.innerHTML = `
            <div class="version-activa__head">
                <div>
                    <h2 class="version-activa__titulo">Version activa actual</h2>
                    <p>No hay una version activa publicada todavia.</p>
                </div>
                ${badge('Sin activa', 'badge-danger')}
            </div>`;
        return;
    }

    cont.innerHTML = `
        <div class="version-activa__head">
            <div>
                <h2 class="version-activa__titulo">Version activa actual</h2>
                <div class="version-activa__version">${escapeHtml(activa.version_name)}</div>
            </div>
            <div>
                ${badge('Publicada', 'badge-ok')}
                ${badge(canalEtiqueta(activa.canal_app), 'badge-info')}
                ${activa.obligatoria ? badge('Obligatoria', 'badge-warn') : badge('Opcional', 'badge-info')}
            </div>
        </div>
        <div class="version-activa__grid">
            <div class="version-activa__dato"><div class="lbl">version_code</div><div class="val mono">${escapeHtml(activa.version_code)}</div></div>
            <div class="version-activa__dato"><div class="lbl">Canal</div><div class="val">${escapeHtml(canalEtiqueta(activa.canal_app))}</div></div>
            <div class="version-activa__dato"><div class="lbl">Fecha</div><div class="val">${fechaCorta(activa.fecha)}</div></div>
            <div class="version-activa__dato"><div class="lbl">Descarga</div><div class="val"><a href="${escapeHtml(activa.apk_url)}" target="_blank" rel="noopener">Abrir APK</a><div class="muted">${activa.apk_archivo_existe ? 'Archivo fisico OK' : 'Archivo fisico no encontrado'}</div></div></div>
            <div class="version-activa__dato"><div class="lbl">ID</div><div class="val mono">${escapeHtml(activa.id_actualizacion)}</div></div>
            <div class="version-activa__dato full"><div class="lbl">SHA-256</div><div class="val mono">${escapeHtml(activa.sha256)}</div></div>
            <div class="version-activa__dato full"><div class="lbl">Notas</div><div class="val">${escapeHtml(activa.notas || 'Sin notas')}</div></div>
        </div>
        <div class="version-activa__impacto">
            <div class="impacto-dato"><div class="lbl">Actualizadas</div><div class="num">${instaladas}</div></div>
            <div class="impacto-dato"><div class="lbl">Avance</div><div class="num">${porcentaje}%</div></div>
            <div class="impacto-dato"><div class="lbl">Con error</div><div class="num">${errores}</div></div>
        </div>`;
}

function renderGraficasAnterior() {
    if (!window.Chart) return;
    const resumen = estado.seguimiento?.resumen || {};
    const instaladas = Number(resumen.instalada || 0);
    const descargando = Number(resumen.descargando || 0);
    const pendientes = Number(resumen.pendiente || 0);
    const errores = Number(resumen.error || 0);
    const total = instaladas + descargando + pendientes + errores;
    const porcentaje = total ? Math.round((instaladas / total) * 100) : 0;

    $('#adopcion-porcentaje').textContent = `${porcentaje}%`;
    $('#adopcion-texto').textContent = total
        ? `${instaladas} de ${total} maquinas ya reportaron la version seleccionada como instalada.`
        : 'Aun no hay maquinas reportando esta actualizacion.';

    const oscuro = document.body.classList.contains('tema-mdm-oscuro');
    const colores = {
        instalada: oscuro ? '#53D769' : '#247A3B',
        descargando: oscuro ? '#F4C95D' : '#A96D0B',
        pendiente: oscuro ? '#4EA1FF' : '#214F8B',
        error: oscuro ? '#FF667A' : '#B42335',
        texto: oscuro ? '#EAF2F5' : '#29404F',
        tenue: oscuro ? '#9FB1BB' : '#536A78',
        linea: oscuro ? 'rgba(178,202,214,.12)' : 'rgba(83,106,120,.16)',
        tooltip: oscuro ? '#17242C' : '#FFFFFF',
        tooltipBorde: oscuro ? '#536A76' : '#AEBCC6',
        superficie: oscuro ? '#0D171D' : '#FFFFFF',
    };

    $('#adopcion-leyenda').innerHTML = [
        ['Instaladas', instaladas, colores.instalada],
        ['Descargando', descargando, colores.descargando],
        ['Pendientes', pendientes, colores.pendiente],
        ['Errores', errores, colores.error],
    ].map(([etiqueta, valor, color]) => `<div class="adopcion-leyenda__fila" style="--color:${color}"><i></i><span>${etiqueta}</span><b>${valor}</b></div>`).join('');

    const opcionesBase = {
        responsive: true,
        maintainAspectRatio: false,
        animation: { duration: 900, easing: 'easeOutQuart' },
        plugins: {
            legend: { labels: { color: colores.texto, boxWidth: 9, boxHeight: 9, usePointStyle: true, padding: 18, font: { size: 11, weight: '700' } } },
            tooltip: {
                displayColors: true,
                backgroundColor: colores.tooltip,
                borderColor: colores.tooltipBorde,
                borderWidth: 1,
                titleColor: colores.texto,
                bodyColor: colores.texto,
                padding: 11,
                cornerRadius: 5,
            },
        },
    };

    if (graficas.adopcion) graficas.adopcion.destroy();
    const etiquetaCentral = {
        id: 'etiquetaCentralAdopcion',
        afterDraw(chart) {
            const arco = chart.getDatasetMeta(0)?.data?.[0];
            if (!arco) return;
            const ctx = chart.ctx;
            ctx.save();
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillStyle = colores.texto;
            ctx.font = "700 30px 'Space Grotesk', sans-serif";
            ctx.fillText(`${porcentaje}%`, arco.x, arco.y - 7);
            ctx.fillStyle = colores.tenue;
            ctx.font = "600 10px Inter, sans-serif";
            ctx.fillText('ADOPCION', arco.x, arco.y + 18);
            ctx.restore();
        },
    };
    graficas.adopcion = new Chart($('#grafica-adopcion'), {
        type: 'doughnut',
        data: {
            labels: total ? ['Instaladas', 'Descargando', 'Pendientes', 'Errores'] : ['Sin datos'],
            datasets: [{
                data: total ? [instaladas, descargando, pendientes, errores] : [1],
                backgroundColor: total ? [colores.instalada, colores.descargando, colores.pendiente, colores.error] : [colores.linea],
                borderColor: colores.superficie,
                borderWidth: 2,
                spacing: 3,
                hoverOffset: 4,
            }],
        },
        options: { ...opcionesBase, maintainAspectRatio: true, aspectRatio: 1, cutout: '78%', plugins: { ...opcionesBase.plugins, legend: { display: false } } },
        plugins: [etiquetaCentral],
    });

    const dias = [];
    const porDia = {};
    const hoy = new Date();
    for (let i = 13; i >= 0; i--) {
        const fecha = new Date(hoy.getFullYear(), hoy.getMonth(), hoy.getDate() - i);
        const clave = `${fecha.getFullYear()}-${String(fecha.getMonth() + 1).padStart(2, '0')}-${String(fecha.getDate()).padStart(2, '0')}`;
        dias.push({ clave, etiqueta: `${String(fecha.getDate()).padStart(2, '0')}/${String(fecha.getMonth() + 1).padStart(2, '0')}` });
        porDia[clave] = { consulta: 0, descarga: 0, instalacion: 0, error: 0 };
    }
    estado.eventos.forEach(ev => {
        const clave = String(ev.fecha || '').slice(0, 10);
        const tipo = String(ev.tipo_evento || '').toLowerCase();
        if (porDia[clave] && Object.prototype.hasOwnProperty.call(porDia[clave], tipo)) porDia[clave][tipo]++;
    });
    const maximoOperativo = Math.max(1, ...dias.flatMap(d => [
        porDia[d.clave].descarga,
        porDia[d.clave].instalacion,
        porDia[d.clave].error,
    ]));

    if (graficas.eventos) graficas.eventos.destroy();
    graficas.eventos = new Chart($('#grafica-eventos'), {
        type: 'line',
        data: {
            labels: dias.map(d => d.etiqueta),
            datasets: [
                { label: 'Consultas', data: dias.map(d => porDia[d.clave].consulta), borderColor: colores.pendiente, pointBackgroundColor: colores.pendiente, backgroundColor: 'rgba(78,161,255,.12)', yAxisID: 'y' },
                { label: 'Descargas', data: dias.map(d => porDia[d.clave].descarga), borderColor: colores.descargando, pointBackgroundColor: colores.descargando, backgroundColor: 'rgba(244,201,93,.10)', yAxisID: 'y1' },
                { label: 'Instalaciones', data: dias.map(d => porDia[d.clave].instalacion), borderColor: colores.instalada, pointBackgroundColor: colores.instalada, backgroundColor: 'rgba(83,215,105,.10)', yAxisID: 'y1' },
                { label: 'Errores', data: dias.map(d => porDia[d.clave].error), borderColor: colores.error, pointBackgroundColor: colores.error, backgroundColor: 'rgba(255,102,122,.10)', yAxisID: 'y1' },
            ],
        },
        options: {
            ...opcionesBase,
            interaction: { mode: 'index', intersect: false },
            elements: {
                line: { borderWidth: 2.25, tension: .34, cubicInterpolationMode: 'monotone', fill: false },
                point: { radius: 2.5, hoverRadius: 5, borderWidth: 0 },
            },
            scales: {
                x: { grid: { display: false }, border: { display: false }, ticks: { color: colores.tenue, maxRotation: 0, autoSkip: true, font: { size: 10, weight: '600' } } },
                y: {
                    beginAtZero: true,
                    position: 'left',
                    title: { display: true, text: 'Consultas', color: colores.pendiente, font: { size: 10, weight: '700' } },
                    border: { display: false },
                    grid: { color: colores.linea },
                    ticks: { color: colores.tenue, precision: 0, padding: 8, font: { size: 10, weight: '600' } },
                },
                y1: {
                    beginAtZero: true,
                    position: 'right',
                    suggestedMax: Math.ceil(maximoOperativo * 1.25),
                    title: { display: true, text: 'Descargas / instalaciones / errores', color: colores.instalada, font: { size: 10, weight: '700' } },
                    border: { display: false },
                    grid: { drawOnChartArea: false },
                    ticks: { color: colores.tenue, precision: 0, padding: 8, font: { size: 10, weight: '600' } },
                },
            },
        },
    });
}

function renderGraficas() {
    if (!window.Chart) return;

    const resumen = estado.seguimiento?.resumen || {};
    const estadosAdopcion = [
        { clave: 'instalada', etiqueta: 'Instaladas', valor: Number(resumen.instalada || 0), color: '#53D769' },
        { clave: 'descargando', etiqueta: 'Descargando', valor: Number(resumen.descargando || 0), color: '#F4C95D' },
        { clave: 'pendiente', etiqueta: 'Pendientes', valor: Number(resumen.pendiente || 0), color: '#4EA1FF' },
        { clave: 'error', etiqueta: 'Errores', valor: Number(resumen.error || 0), color: '#FF667A' },
    ];
    const total = estadosAdopcion.reduce((suma, item) => suma + item.valor, 0);
    const instaladas = estadosAdopcion[0].valor;
    const porcentaje = total ? Math.round((instaladas / total) * 100) : 0;

    $('#adopcion-porcentaje').textContent = `${porcentaje}%`;
    $('#adopcion-texto').textContent = total
        ? `${instaladas} de ${total} maquinas ya confirmaron la version seleccionada.`
        : 'Aun no hay maquinas reportando esta actualizacion.';
    $('#matriz-conteo').textContent = `${total} nodo${total === 1 ? '' : 's'}`;

    $('#ruta-despliegue').innerHTML = total
        ? estadosAdopcion.filter(item => item.valor > 0).map((item, indice) => (
            `<span class="ruta-despliegue__tramo" title="${item.etiqueta}: ${item.valor}" style="--ancho:${(item.valor / total) * 100}%;--estado-color:${item.color};animation-delay:${indice * 80}ms"></span>`
        )).join('')
        : '<span class="ruta-despliegue__tramo" style="--ancho:100%;--estado-color:#263942"></span>';

    const nodos = estadosAdopcion.flatMap(item => Array.from({ length: item.valor }, (_, indice) => ({
        ...item,
        indice,
    })));
    $('#matriz-unidades').innerHTML = nodos.length
        ? nodos.map((item, indice) => `<span class="matriz-nodo" title="${item.etiqueta} ${item.indice + 1}" style="--nodo-color:${item.color};animation-delay:${Math.min(indice * 18, 650)}ms"></span>`).join('')
        : '<span class="matriz-vacia">Sin nodos reportando esta version.</span>';

    $('#adopcion-leyenda').innerHTML = estadosAdopcion.map(item => `
        <div class="adopcion-leyenda__fila" style="--color:${item.color}">
            <i></i><span>${item.etiqueta}</span><b>${item.valor}</b>
        </div>
    `).join('');

    const dias = [];
    const porDia = {};
    const hoy = new Date();
    for (let i = 13; i >= 0; i--) {
        const fecha = new Date(hoy.getFullYear(), hoy.getMonth(), hoy.getDate() - i);
        const clave = `${fecha.getFullYear()}-${String(fecha.getMonth() + 1).padStart(2, '0')}-${String(fecha.getDate()).padStart(2, '0')}`;
        dias.push({ clave, etiqueta: `${String(fecha.getDate()).padStart(2, '0')}/${String(fecha.getMonth() + 1).padStart(2, '0')}` });
        porDia[clave] = { consulta: 0, descarga: 0, instalacion: 0, error: 0 };
    }
    estado.eventos.forEach(evento => {
        const clave = String(evento.fecha || '').slice(0, 10);
        const tipo = String(evento.tipo_evento || '').toLowerCase();
        if (porDia[clave] && Object.prototype.hasOwnProperty.call(porDia[clave], tipo)) porDia[clave][tipo]++;
    });

    graficas.pulsos.forEach(grafica => grafica.destroy());
    graficas.pulsos = [];
    const pulsos = [
        { clave: 'consulta', color: '#4EA1FF', relleno: 'rgba(78,161,255,.13)' },
        { clave: 'descarga', color: '#F4C95D', relleno: 'rgba(244,201,93,.12)' },
        { clave: 'instalacion', color: '#53D769', relleno: 'rgba(83,215,105,.12)' },
        { clave: 'error', color: '#FF667A', relleno: 'rgba(255,102,122,.12)' },
    ];

    pulsos.forEach(pulso => {
        const valores = dias.map(dia => porDia[dia.clave][pulso.clave]);
        const totalPulso = valores.reduce((suma, valor) => suma + valor, 0);
        const totalElemento = $(`#pulso-total-${pulso.clave}`);
        if (totalElemento) totalElemento.textContent = totalPulso.toLocaleString('es-MX');
        const canvas = $(`#pulso-${pulso.clave}`);
        if (!canvas) return;

        graficas.pulsos.push(new Chart(canvas, {
            type: 'line',
            data: {
                labels: dias.map(dia => dia.etiqueta),
                datasets: [{
                    data: valores,
                    borderColor: pulso.color,
                    backgroundColor: pulso.relleno,
                    borderWidth: 2,
                    tension: .34,
                    cubicInterpolationMode: 'monotone',
                    fill: true,
                    pointRadius: contexto => contexto.dataIndex === valores.length - 1 ? 3 : 0,
                    pointHoverRadius: 4,
                    pointBackgroundColor: pulso.color,
                    pointBorderWidth: 0,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: { duration: 700, easing: 'easeOutQuart' },
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        displayColors: false,
                        backgroundColor: '#17252D',
                        borderColor: '#49606B',
                        borderWidth: 1,
                        titleColor: '#AFC0C8',
                        bodyColor: '#FFFFFF',
                        padding: 9,
                        cornerRadius: 5,
                        callbacks: { label: contexto => `${contexto.parsed.y} evento${contexto.parsed.y === 1 ? '' : 's'}` },
                    },
                },
                scales: {
                    x: {
                        grid: { display: false },
                        border: { display: false },
                        ticks: { color: '#6F8792', maxTicksLimit: 3, maxRotation: 0, font: { size: 8, weight: '600' } },
                    },
                    y: {
                        beginAtZero: true,
                        suggestedMax: Math.max(1, ...valores),
                        grid: { color: 'rgba(159,181,191,.09)', drawTicks: false },
                        border: { display: false },
                        ticks: { display: false, precision: 0 },
                    },
                },
            },
        }));
    });

    if (window.mlRenderIcons) window.mlRenderIcons();
}

window.addEventListener('mdmthemechange', () => {
    if (estado.actualizaciones.length || estado.eventos.length) renderGraficas();
});

async function cargarDatos() {
    $('#tabla-versiones').innerHTML = '<div class="cargando">Cargando...</div>';
    const [versiones, eventos] = await Promise.all([
        obtenerJSON(`api/app_actualizaciones.php?${CANAL_QUERY}`),
        obtenerJSON(`api/app_actualizaciones_eventos.php?${CANAL_QUERY}`),
    ]);
    estado.actualizaciones = versiones.data.actualizaciones || [];
    estado.eventos = eventos.data || [];
    estado.resumen30 = versiones.data.resumen_30_dias || {};
    renderKpis(estado.resumen30);
    renderVersiones();
    renderSelectorSeguimiento();
    await cargarSeguimiento();
    renderVersionActiva();
    renderEventos();
    renderGraficas();
    if (window.mlRenderIcons) window.mlRenderIcons();
}

async function guardarEstadoVersion(ev) {
    const id = Number(ev.target.dataset.id);
    const item = estado.actualizaciones.find(a => Number(a.id_actualizacion) === id);
    if (!item) return;

    const campo = ev.target.dataset.campo;
    const anterior = item[campo];
    item[campo] = campo === 'fecha_publicacion' ? ev.target.value : ev.target.checked;
    try {
        const res = await fetch('api/app_actualizacion_estado.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                id_actualizacion: id,
                activa: item.activa ? 1 : 0,
                obligatoria: item.obligatoria ? 1 : 0,
                fecha_publicacion: item.fecha_publicacion || '',
                canal_app: CANAL_APP,
            }),
        });
        const json = await res.json();
        if (!json.ok) throw new Error(json.error || 'No se pudo guardar');
        await cargarDatos();
    } catch (e) {
        item[campo] = anterior;
        if (campo === 'fecha_publicacion') {
            ev.target.value = anterior || '';
        } else {
            ev.target.checked = Boolean(anterior);
        }
        mlAlert(e.message || 'No se pudo guardar el estado');
    }
}

$('#form-apk').addEventListener('submit', async ev => {
    ev.preventDefault();
    const form = ev.currentTarget;
    const msg = $('#mensaje-subida');
    const boton = form.querySelector('button[type="submit"]');
    msg.className = 'mensaje';
    msg.textContent = 'Subiendo y verificando APK...';
    const fd = new FormData(form);
    if (!fd.has('activa')) fd.append('activa', '0');
    if (!fd.has('obligatoria')) fd.append('obligatoria', '0');

    try {
        boton.disabled = true;
        const Swal = await cargarSweetAlert();
        Swal.fire({
            title: 'Subiendo APK',
            html: 'Espera un momento. Se esta guardando el archivo y calculando la firma SHA-256.',
            allowOutsideClick: false,
            allowEscapeKey: false,
            didOpen: () => Swal.showLoading(),
        });
        const res = await fetch('api/app_actualizacion_subir.php', { method: 'POST', body: fd });
        const json = await res.json();
        if (!json.ok) throw new Error(json.error || 'No se pudo subir el APK');
        form.reset();
        form.querySelector('input[name="activa"]').checked = true;
        msg.className = 'mensaje ok';
        msg.textContent = `APK publicado: ${json.data.version_name} (${json.data.version_code}). SHA-256: ${json.data.sha256}`;
        await cargarDatos();
        await Swal.fire({
            icon: 'success',
            title: 'APK publicado',
            html: `<strong>${escapeHtml(json.data.version_name)}</strong> (${escapeHtml(json.data.version_code)})<br><span style="font-size:12px;color:#647583;">SHA-256: ${escapeHtml(json.data.sha256)}</span>`,
            confirmButtonText: 'Listo',
            confirmButtonColor: '#087D76',
        });
    } catch (e) {
        msg.className = 'mensaje error';
        msg.textContent = e.message || 'No se pudo subir el APK';
        const Swal = await cargarSweetAlert();
        await Swal.fire({
            icon: 'error',
            title: 'No se pudo subir',
            text: e.message || 'No se pudo subir el APK',
            confirmButtonText: 'Revisar',
            confirmButtonColor: '#B42335',
        });
    } finally {
        boton.disabled = false;
    }
});

$('#btn-recargar').addEventListener('click', () => cargarDatos().catch(e => mlAlert(e.message)));
$('#btn-limpiar-apks-maquinas')?.addEventListener('click', async () => {
    const ok = await mlConfirm(
        'Se enviara una orden a todas las maquinas para borrar APKs descargados/cacheados por la app. Android debe soportar el comando limpiar_apks_dispositivo.',
        'Limpiar APKs en maquinas',
        'Enviar orden'
    );
    if (!ok) return;
    const confirmacion = prompt('Para confirmar escribe: LIMPIAR_APKS_MAQUINAS');
    if (confirmacion !== 'LIMPIAR_APKS_MAQUINAS') return;
    try {
        const res = await fetch('api/app_comandos_android_crear.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                confirmacion,
                tipo_comando: 'limpiar_apks_dispositivo',
            }),
        });
        const json = await res.json();
        if (!json.ok) throw new Error(json.error || 'No se pudo enviar la orden');
        await mlAlert(`Listo. Ordenes creadas: ${json.data.comandos_creados}.`, 'success');
    } catch (e) {
        mlAlert(e.message || 'No se pudo enviar la orden');
    }
});
$('#btn-limpiar-apks').addEventListener('click', async () => {
    const ok = await mlConfirm('Se borraran versiones, eventos de actualizacion y archivos APK subidos. Escribe LIMPIAR_APKS en la siguiente confirmacion.', 'Limpiar APKs', 'Continuar');
    if (!ok) return;
    const confirmacion = prompt('Para confirmar escribe: LIMPIAR_APKS');
    if (confirmacion !== 'LIMPIAR_APKS') return;
    try {
        const res = await fetch('api/app_actualizaciones_limpiar.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ confirmacion, canal_app: CANAL_APP }),
        });
        const json = await res.json();
        if (!json.ok) throw new Error(json.error || 'No se pudo limpiar');
        await mlAlert(
            `Listo. Versiones: ${json.data.actualizaciones_borradas}, eventos: ${json.data.eventos_borrados}, APKs: ${json.data.archivos_apk_borrados}.`,
            'success'
        );
        await cargarDatos();
    } catch (e) {
        mlAlert(e.message || 'No se pudo limpiar');
    }
});
$('#btn-seguimiento').addEventListener('click', () => cargarSeguimiento().catch(e => mlAlert(e.message)));
$('#seguimiento-version').addEventListener('change', () => cargarSeguimiento().catch(e => mlAlert(e.message)));
cargarDatos().catch(e => {
    $('#tabla-versiones').innerHTML = `<div class="vacio">${escapeHtml(e.message)}</div>`;
    $('#tabla-seguimiento').innerHTML = `<div class="vacio">${escapeHtml(e.message)}</div>`;
    $('#bitacora-preview').innerHTML = `<span class="badge badge-danger">${escapeHtml(e.message)}</span>`;
});
</script>
</body>
</html>
