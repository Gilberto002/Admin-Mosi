<?php
// public/admin.php — listado de la red de máquinas, agrupado por unidad (maestra + esclavas)
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/bootstrap.php';

use App\Auth\Auth;

Auth::requireLogin();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>MDM - Máquinas</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/estilo.css?v=20260914-2">
    <style>
        /* ================================================================
           Tema oscuro "sala de control" — mismos tokens que dashboard.php
           y usuarios.php, aplicado vía body.tema-oscuro.
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
                radial-gradient(1100px 550px at 12% -12%, rgba(44, 217, 190, .06), transparent 60%),
                radial-gradient(900px 500px at 100% 0%, rgba(228, 172, 79, .03), transparent 55%),
                var(--bg);
            color: var(--texto);
        }
        body.tema-oscuro .sidebar { background: var(--bg-elevada); border-right: 1px solid var(--linea); }
        body.tema-oscuro .marca .icono-enlace { color: var(--petroleo); }
        body.tema-oscuro .marca .texto small { color: var(--texto-tenue); }
        body.tema-oscuro .nav-lateral a { color: var(--texto-suave); }
        body.tema-oscuro .nav-lateral a:hover { background: var(--bg-hover); color: var(--texto); }
        body.tema-oscuro .nav-lateral a.activa { background: var(--petroleo-wash); color: var(--petroleo); }
        body.tema-oscuro h1, body.tema-oscuro h2, body.tema-oscuro h3 { color: var(--texto); }

        /* -------- Encabezado -------- */
        .encabezado-pagina { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; flex-wrap: wrap; margin-bottom: 22px; }
        .encabezado-pagina__etiqueta {
            display: inline-flex; align-items: center; gap: 7px; font-size: 11px; letter-spacing: .08em;
            text-transform: uppercase; color: var(--petroleo); font-weight: 700; margin-bottom: 9px;
        }
        .punto-vivo { width: 7px; height: 7px; border-radius: 50%; background: var(--petroleo); }
        .encabezado-pagina h1 { font-size: 22px; margin: 0 0 4px; letter-spacing: -.01em; }
        .encabezado-pagina p { margin: 0; color: var(--texto-suave); font-size: 13.5px; max-width: 560px; }
        .acciones-encabezado { display: flex; gap: 10px; flex-shrink: 0; }

        .btn-nueva-maquina, .btn-cargar-stock, .btn-importar-maquinas {
            border: none; font-family: inherit; font-weight: 600;
            font-size: 13px; padding: 10px 18px; border-radius: var(--radius-sm, 8px); cursor: pointer; white-space: nowrap;
            display: inline-flex; align-items: center; gap: 8px;
            transition: background .15s ease, border-color .15s ease, transform .1s ease;
        }
        .btn-nueva-maquina:active, .btn-cargar-stock:active, .btn-importar-maquinas:active { transform: scale(.97); }
        .btn-nueva-maquina { background: var(--petroleo); color: #06201F; }
        .btn-nueva-maquina:hover { background: #45E6CD; }
        .btn-cargar-stock, .btn-importar-maquinas { background: var(--bg-elevada); border: 1px solid var(--linea); color: var(--texto); }
        .btn-cargar-stock:hover, .btn-importar-maquinas:hover { border-color: var(--petroleo); color: var(--petroleo); }

        /* -------- Tira de KPIs (bento) -------- */
        .tira-stats { display: grid; grid-template-columns: repeat(5, 1fr); gap: 14px; margin-bottom: 18px; }
        .stat {
            display: flex; align-items: center; gap: 14px;
            background: var(--bg-elevada); border: 1px solid var(--linea); border-radius: var(--radius-lg, 14px);
            padding: 16px 18px;
            box-shadow: 0 1px 0 rgba(255,255,255,.02) inset, 0 14px 26px -20px rgba(0,0,0,.65);
        }
        .stat .icono-stat {
            width: 36px; height: 36px; border-radius: 10px; flex-shrink: 0;
            display: flex; align-items: center; justify-content: center;
            background: rgba(255,255,255,.04); border: 1px solid var(--linea-suave); color: var(--texto-suave);
        }
        .stat.acento-rose .icono-stat { color: var(--critico); }
        .stat.acento-amber .icono-stat { color: var(--ambar); }
        .stat.acento-moss .icono-stat { color: var(--petroleo); }
        .stat .lbl { font-size: 11px; text-transform: uppercase; letter-spacing: .05em; color: var(--texto-tenue); font-weight: 600; margin-bottom: 6px; }
        .stat .num { font-family: 'Space Grotesk', sans-serif; font-size: 27px; font-weight: 700; line-height: 1; color: var(--texto); }
        .stat .subtle-note { font-size: 12px; color: var(--texto-tenue); margin-top: 3px; }
        .stat.acento-rose .num { color: var(--critico); }
        .stat.acento-amber .num { color: var(--ambar); }
        .stat.acento-moss .num { color: var(--petroleo); }

        /* -------- Barra de filtros -------- */
        .toolbar-card {
            background: var(--bg-elevada); border: 1px solid var(--linea); border-radius: var(--radius-lg, 14px);
            padding: 14px 16px; margin-bottom: 14px;
        }
        .barra { display: flex; gap: 10px; margin-bottom: 16px; flex-wrap: wrap; align-items: center; }
        .toolbar-card .barra { margin-bottom: 0; }
        body.tema-oscuro .barra input[type="text"], body.tema-oscuro .barra select {
            padding: 10px 14px; border: 1px solid var(--linea); border-radius: var(--radius-sm, 8px);
            font-size: 13px; background: var(--bg); color: var(--texto); font-family: inherit;
            transition: border-color .15s ease, box-shadow .15s ease;
        }
        body.tema-oscuro .barra input[type="text"]:focus, body.tema-oscuro .barra select:focus {
            outline: none; border-color: var(--petroleo); box-shadow: 0 0 0 3px var(--petroleo-wash);
        }
        body.tema-oscuro .barra input[type="text"]::placeholder { color: var(--texto-tenue); }
        body.tema-oscuro .barra input[type="text"] { flex: 1; min-width: 260px; }
        body.tema-oscuro .contador { font-size: 12px; color: var(--texto-tenue); margin-left: auto; white-space: nowrap; }

        body.tema-oscuro .card {
            background: var(--bg-elevada); border: 1px solid var(--linea); border-radius: var(--radius-lg, 14px);
            box-shadow: 0 1px 0 rgba(255,255,255,.02) inset, 0 14px 26px -20px rgba(0,0,0,.65);
        }
        body.tema-oscuro .vacio, body.tema-oscuro .cargando { color: var(--texto-tenue); font-style: italic; padding: 40px 14px; text-align: center; }

        /* -------- Lista de unidades -------- */
        .lista-unidades { display: flex; flex-direction: column; }
        .unidad {
            display: grid;
            grid-template-columns: 1.4fr 1.6fr auto auto auto auto;
            align-items: center;
            gap: 18px;
            padding: 16px 22px;
            border-bottom: 1px solid var(--linea-suave);
            transition: background .12s ease;
        }
        .unidad:first-child { border-top-left-radius: 10px; border-top-right-radius: 10px; }
        .unidad:last-child { border-bottom: none; }
        .unidad:hover { background: var(--bg-hover); }

        .unidad .maestra-col { display: flex; align-items: center; gap: 12px; min-width: 0; cursor: pointer; }
        .unidad .maestra-col .icono-maquina {
            width: 34px; height: 34px; border-radius: 9px; background: var(--petroleo-wash); color: var(--petroleo);
            display: flex; align-items: center; justify-content: center; flex-shrink: 0;
        }
        .unidad .maestra-col .foto-maquina {
            width: 54px; height: 64px; flex: 0 0 54px; display: block; object-fit: cover;
            object-position: center 44%; border: 1px solid var(--linea); border-radius: 6px;
            background: #DCE6ED; box-shadow: 0 5px 14px rgba(8, 25, 39, .13);
        }
        .unidad .maestra-col .info { min-width: 0; }
        .unidad .serie-line { display: flex; align-items: center; flex-wrap: wrap; gap: 7px; }
        .unidad .maestra-col .serie { font-weight: 600; font-size: 13.5px; color: var(--texto); }
        .unidad .maestra-col .ubic { color: var(--texto-suave); font-size: 12px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .unidad .meta-mini { color: var(--texto-tenue); font-size: 11px; margin-top: 3px; }

        .unidad .esclavas-col { display: flex; flex-wrap: wrap; gap: 6px; min-width: 0; }
        .unidad .esclavas-col .sin-esclava { color: var(--texto-tenue); font-size: 12px; font-style: italic; }

        .chip-unidad {
            display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px 4px 8px;
            border-radius: 999px; background: var(--bg); border: 1px solid var(--linea); font-size: 12px; color: var(--texto-suave);
        }
        .chip-unidad .rol-punto { width: 6px; height: 6px; border-radius: 50%; background: var(--texto-tenue); flex-shrink: 0; }
        .chip-unidad.online .rol-punto { background: var(--petroleo); box-shadow: 0 0 0 3px var(--petroleo-wash); }
        .chip-unidad.offline .rol-punto { background: var(--critico); }
        .chip-unidad .btn-editar-esclava {
            width: 20px; height: 20px; border: 1px solid var(--linea); border-radius: 999px;
            background: transparent; color: var(--texto-tenue); display: inline-flex; align-items: center; justify-content: center;
            cursor: pointer; font-size: 11px; line-height: 1; padding: 0;
        }
        .chip-unidad .btn-editar-esclava:hover { color: var(--petroleo); border-color: var(--petroleo); }

        .unidad .fecha-col { font-size: 12.5px; color: var(--texto-suave); white-space: nowrap; cursor: pointer; font-family: 'JetBrains Mono', monospace; }
        .unidad .estado-col { justify-self: end; cursor: pointer; }

        .unidad .acciones-col { display: flex; gap: 6px; justify-self: end; }
        .unidad .acciones-col button {
            border: 1px solid var(--linea); background: var(--bg); border-radius: 7px;
            width: 30px; height: 30px; display: flex; align-items: center; justify-content: center;
            cursor: pointer; color: var(--texto-suave); transition: color .12s ease, border-color .12s ease;
        }
        .unidad .acciones-col button:hover { color: var(--texto); border-color: var(--texto-tenue); }
        .unidad .acciones-col button.btn-baja:hover { color: var(--critico); border-color: var(--critico); }

        body.tema-oscuro .badge { display: inline-flex; align-items: center; gap: 5px; padding: 3px 10px; border-radius: 999px; font-size: 11px; font-weight: 600; white-space: nowrap; }
        body.tema-oscuro .badge::before { content: ""; width: 6px; height: 6px; border-radius: 50%; background: currentColor; }
        body.tema-oscuro .badge-ok { background: var(--petroleo-wash); color: var(--petroleo); }
        body.tema-oscuro .badge-warn { background: var(--ambar-wash); color: var(--ambar); }
        body.tema-oscuro .badge-danger { background: var(--critico-wash); color: var(--critico); }
        body.tema-oscuro .badge-neutro { background: var(--bg); color: var(--texto-tenue); border: 1px solid var(--linea); }

        body.tema-oscuro .paginacion { color: var(--texto-suave); }
        body.tema-oscuro .paginacion button { background: var(--bg); border: 1px solid var(--linea); color: var(--texto); border-radius: var(--radius-sm, 8px); padding: 8px 14px; cursor: pointer; font: inherit; font-size: 12.5px; }
        body.tema-oscuro .paginacion button:not(:disabled):hover { border-color: var(--petroleo); color: var(--petroleo); }
        body.tema-oscuro .paginacion button:disabled { opacity: .35; }

        /* --- Modal crear/editar --- */
        .overlay-modal {
            position: fixed; inset: 0; background: rgba(6, 9, 12, .72); display: none;
            align-items: flex-start; justify-content: center; padding: 40px 16px; overflow-y: auto; z-index: 50;
            backdrop-filter: blur(2px);
        }
        .overlay-modal.abierto { display: flex; animation: aparecer-overlay .15s ease; }
        @keyframes aparecer-overlay { from { opacity: 0; } to { opacity: 1; } }
        .modal {
            background: var(--bg-elevada); border: 1px solid var(--linea); border-radius: var(--radius-lg, 14px); width: 100%; max-width: 560px;
            box-shadow: 0 30px 60px -25px rgba(0,0,0,.7);
            animation: aparecer-modal .18s ease;
        }
        @keyframes aparecer-modal { from { opacity: 0; transform: translateY(6px) scale(.99); } to { opacity: 1; transform: none; } }
        .modal-encabezado {
            display: flex; align-items: center; justify-content: space-between;
            padding: 18px 22px; border-bottom: 1px solid var(--linea-suave);
        }
        .modal-encabezado h2 { margin: 0; font-size: 15.5px; }
        .modal-cerrar { border: none; background: none; cursor: pointer; font-size: 18px; color: var(--texto-tenue); line-height: 1; }
        .modal-cerrar:hover { color: var(--texto); }
        .modal-cuerpo { padding: 22px; display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        .modal-cuerpo .completo { grid-column: 1 / -1; }
        .modal-cuerpo .form-row label { display: block; font-size: 12px; font-weight: 600; color: var(--texto-suave); margin-bottom: 6px; }
        .modal-cuerpo .form-row input,
        .modal-cuerpo .form-row select {
            width: 100%; box-sizing: border-box; padding: 9px 11px; border: 1px solid var(--linea);
            border-radius: var(--radius-sm, 8px); font-family: inherit; font-size: 13.5px; background: var(--bg); color: var(--texto);
            transition: border-color .15s ease, box-shadow .15s ease;
        }
        .modal-cuerpo .form-row input[type="file"] { padding: 8px; font-size: 12px; }
        .modal-cuerpo .form-row input[type="color"] { height: 40px; padding: 4px; cursor: pointer; }
        .modal-cuerpo .form-row input[type="checkbox"] { width: auto; }
        .check-row { display: flex; align-items: center; gap: 8px; color: var(--texto-suave); font-size: 13px; font-weight: 600; }
        .nota-campo { margin-top: 6px; font-size: 11.5px; color: var(--texto-tenue); line-height: 1.35; }
        .branding-preview { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 8px; }
        .branding-preview a { color: var(--petroleo); font-size: 12px; text-decoration: none; }
        .branding-preview a:hover { text-decoration: underline; }
        .modal-cuerpo .form-row input:focus, .modal-cuerpo .form-row select:focus {
            outline: none; border-color: var(--petroleo); box-shadow: 0 0 0 3px var(--petroleo-wash);
        }
        .solo-maestra.oculto { display: none; }
        .form-row:has(#f-nombre_ubicacion),
        .form-row:has(#f-direccion),
        .form-row:has(#f-latitud),
        .form-row:has(#f-longitud),
        .form-row:has(#f-sucursal-buscar),
        .form-row:has(#f-id_sucursal_clinica) {
            display: none !important;
        }
        .modal-pie {
            padding: 16px 22px; border-top: 1px solid var(--linea-suave); display: flex;
            justify-content: flex-end; gap: 10px; align-items: center;
        }
        .modal-mensaje { font-size: 12.5px; margin-right: auto; }
        .modal-mensaje.error { color: var(--critico); font-weight: 600; }
        .modal-mensaje.ok { color: var(--petroleo); font-weight: 600; }
        .modal-pie button { font-family: inherit; font-size: 13px; font-weight: 600; padding: 9px 18px; border-radius: var(--radius-sm, 8px); cursor: pointer; transition: border-color .15s ease, background .15s ease; }
        .modal-pie .btn-cancelar { border: 1px solid var(--linea); background: var(--bg); color: var(--texto-suave); }
        .modal-pie .btn-cancelar:hover { color: var(--texto); border-color: var(--texto-tenue); }
        .modal-pie .btn-guardar { border: none; background: var(--petroleo); color: #06201F; }
        .modal-pie .btn-guardar:hover { background: #45E6CD; }
        .modal-pie .btn-guardar:disabled { opacity: .5; cursor: default; }

        /* --- Modal generico de aviso (Excel invalido / capacidad pendiente) --- */
        .aviso-caja { border-radius: 10px; padding: 16px; }
        .aviso-caja.critico { background: var(--critico-wash); border: 1px solid var(--critico); }
        .aviso-caja.advertencia { background: var(--ambar-wash); border: 1px solid var(--ambar); }
        .aviso-caja.exito { background: var(--petroleo-wash); border: 1px solid var(--petroleo); }
        .aviso-caja.exito .aviso-texto { color: var(--petroleo); font-weight: 600; }
        .aviso-caja .aviso-texto { margin: 0; font-size: 13px; line-height: 1.5; color: var(--texto); }
        .aviso-lista-carriles { list-style: none; margin: 14px 0 0; padding: 0; display: flex; flex-direction: column; gap: 8px; max-height: 320px; overflow-y: auto; }
        .aviso-lista-carriles li { background: var(--bg); border: 1px solid var(--linea); border-radius: 8px; padding: 10px 12px; font-size: 12.5px; display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; }
        .aviso-lista-carriles .acciones { display: flex; gap: 6px; flex-shrink: 0; }
        .aviso-lista-carriles button { font-family: inherit; border: none; border-radius: 6px; padding: 6px 12px; font-size: 12px; font-weight: 600; cursor: pointer; }
        .aviso-lista-carriles button[data-accion="autorizar"] { background: var(--critico); color: #3A0A10; }
        .aviso-lista-carriles button[data-accion="descartar"] { background: var(--petroleo); color: #06201F; }
        .aviso-lista-carriles button:disabled { opacity: .5; cursor: default; }
        .btn-ver-pendientes { font-family: inherit; border: 1px solid var(--ambar); color: var(--ambar); background: var(--ambar-wash); border-radius: 6px; padding: 6px 12px; font-size: 12px; font-weight: 600; cursor: pointer; }

        /* --- Modal cargar stock (Excel) --- */
        .modal-cuerpo.una-col { grid-template-columns: 1fr; }
        .dropzone {
            border: 1.5px dashed var(--linea); border-radius: var(--radius-lg, 12px); padding: 30px 16px;
            text-align: center; color: var(--texto-suave); font-size: 13px; cursor: pointer; background: var(--bg);
            transition: border-color .15s ease, background .15s ease;
        }
        .dropzone:hover { border-color: var(--texto-tenue); }
        .dropzone .icono-dropzone { color: var(--texto-tenue); margin-bottom: 8px; }
        .dropzone.arrastrando { border-color: var(--petroleo); color: var(--texto); background: var(--petroleo-wash); }
        .dropzone.arrastrando .icono-dropzone { color: var(--petroleo); }
        .dropzone .nombre-archivo { font-weight: 600; color: var(--petroleo); margin-top: 6px; }
        .stock-reset-box {
            display: flex; align-items: center; justify-content: space-between; gap: 16px;
            padding: 13px 14px; border: 1px solid rgba(241,112,126,.38); border-radius: 10px;
            background: var(--critico-wash);
        }
        .stock-reset-box strong { display: block; color: var(--critico); font-size: 12.5px; margin-bottom: 3px; }
        .stock-reset-box span { display: block; color: var(--texto-suave); font-size: 11.5px; line-height: 1.4; }
        .btn-vaciar-stock {
            flex-shrink: 0; border: 1px solid var(--critico); background: transparent; color: var(--critico);
            border-radius: 8px; padding: 8px 12px; font: 600 12px inherit; cursor: pointer;
        }
        .btn-vaciar-stock:hover { background: var(--critico); color: #26090D; }
        .btn-vaciar-stock:disabled { opacity: .45; cursor: default; }
        .stock-guia {
            padding: 11px 13px; border: 1px solid var(--linea); border-radius: 8px;
            background: var(--bg); color: var(--texto-suave); font-size: 12px; line-height: 1.5;
        }
        .stock-guia strong { color: var(--texto); }
        .btn-plantilla-stock {
            display: inline-flex; align-items: center; justify-content: center; gap: 7px;
            width: fit-content; margin-top: 8px; padding: 7px 11px; border: 1px solid var(--petroleo);
            border-radius: 7px; color: var(--petroleo); background: var(--petroleo-wash);
            font-size: 12px; font-weight: 600; text-decoration: none;
        }
        .btn-plantilla-stock:hover { background: var(--petroleo); color: #06201F; }
        .resultado-importacion { font-size: 12.5px; line-height: 1.6; color: var(--texto-suave); }
        .resultado-importacion h4 { margin: 14px 0 6px; font-size: 11.5px; text-transform: uppercase; letter-spacing: .04em; color: var(--texto-tenue); }
        .resultado-importacion .resumen-ok { color: var(--petroleo); font-weight: 600; }
        .resultado-importacion ul { margin: 0; padding-left: 18px; max-height: 140px; overflow-y: auto; }
        .resultado-importacion .mono { font-family: 'JetBrains Mono', monospace; font-size: 11.5px; color: var(--texto); }

        @media (max-width: 1100px) { .tira-stats { grid-template-columns: repeat(3, 1fr); } }
        @media (max-width: 760px) {
            .unidad { grid-template-columns: 1fr; row-gap: 8px; }
            .unidad .estado-col, .unidad .acciones-col { justify-self: start; }
            .modal-cuerpo { grid-template-columns: 1fr; }
            .encabezado-pagina { flex-direction: column; }
            .tira-stats { grid-template-columns: repeat(2, 1fr); }
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
                <div class="encabezado-pagina__etiqueta"><span class="punto-vivo"></span>Red de máquinas</div>
                <h1>Red de máquinas</h1>
                <p>Consulta unidades, filtra por atención y entra al detalle para mantenimiento, stock y caducidades.</p>
            </div>
            <?php if (Auth::isAdmin() && !Auth::isUsuarioMaquinasDashboard()): ?>
            <div class="acciones-encabezado">
                <button class="btn-importar-maquinas" id="btn-importar-maquinas">
                    <i data-lucide="file-up" aria-hidden="true"></i>
                    Importar CSV
                </button>
                <button class="btn-cargar-stock" id="btn-cargar-stock">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none"><path d="M12 16V4M12 4l-5 5M12 4l5 5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M4 16v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                    Cargar stock
                </button>
                <button class="btn-nueva-maquina" id="btn-nueva-maquina">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                    Nueva máquina
                </button>
            </div>
            <?php endif; ?>
        </div>

        <div class="tira-stats" id="tira-stats">
            <div class="stat">
                <div class="icono-stat">
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none"><rect x="3" y="3" width="18" height="18" rx="3" stroke="currentColor" stroke-width="1.6"/><path d="M8 8h8M8 12h8M8 16h5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                </div>
                <div><div class="lbl">Unidades totales</div><div class="num">—</div><div class="subtle-note">Maestras visibles</div></div>
            </div>
            <div class="stat acento-rose">
                <div class="icono-stat">
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none"><path d="M12 8v5M12 17h.01M10.2 4.8 2.8 18a1.5 1.5 0 0 0 1.3 2.2h15.8a1.5 1.5 0 0 0 1.3-2.2L13.8 4.8a2.1 2.1 0 0 0-3.6 0Z" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </div>
                <div><div class="lbl">Requieren atención</div><div class="num">—</div><div class="subtle-note">Revisar primero</div></div>
            </div>
            <div class="stat acento-amber">
                <div class="icono-stat">
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="8.5" stroke="currentColor" stroke-width="1.6"/><path d="M12 7.5V12l3 2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </div>
                <div><div class="lbl">Nunca cargadas</div><div class="num">—</div><div class="subtle-note">Sin historial</div></div>
            </div>
            <div class="stat acento-moss">
                <div class="icono-stat">
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none"><path d="M5 12.5l4.2 4.2L19 6.8" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </div>
                <div><div class="lbl">Al día</div><div class="num">—</div><div class="subtle-note">Operación normal</div></div>
            </div>
            <div class="stat acento-moss">
                <div class="icono-stat"><i data-lucide="wifi" aria-hidden="true"></i></div>
                <div><div class="lbl">En línea</div><div class="num">—</div><div class="subtle-note">Latido menor a 10 min</div></div>
            </div>
        </div>

        <div class="toolbar-card">
            <div class="barra">
                <input type="text" id="buscar" placeholder="Buscar por serie, código o ubicación">
                <select id="filtro-estado">
                    <option value="">Cualquier estado</option>
                    <option value="atencion">Requiere atención</option>
                    <option value="nunca">Nunca cargada</option>
                    <option value="ok">Al día</option>
                    <option value="estatus_1">Activas</option>
                    <option value="estatus_2">En mantenimiento</option>
                    <option value="estatus_3">Fuera de servicio</option>
                </select>
                <select id="filtro-conexion" aria-label="Estado de conexión">
                    <option value="">Cualquier conexión</option>
                    <option value="online">En línea</option>
                    <option value="offline">Sin conexión</option>
                    <option value="nunca">Nunca conectada</option>
                </select>
                <input type="text" id="filtro-estado-geo" placeholder="Estado">
                <input type="text" id="filtro-municipio" placeholder="Municipio">
                <span class="contador" id="contador"></span>
            </div>
        </div>

        <div class="card">
            <div id="lista-unidades" class="cargando">Cargando…</div>
            <div class="paginacion" id="paginacion" style="display:none;">
                <button id="btn-anterior">&larr; Anterior</button>
                <span id="texto-pagina"></span>
                <button id="btn-siguiente">Siguiente &rarr;</button>
            </div>
        </div>
    </main>
</div>

<!-- Modal crear/editar máquina -->
<div class="overlay-modal" id="overlay-modal">
    <div class="modal">
        <div class="modal-encabezado">
            <h2 id="modal-titulo">Nueva máquina</h2>
            <button class="modal-cerrar" id="modal-cerrar">&times;</button>
        </div>
        <form id="form-maquina">
            <div class="modal-cuerpo">
                <input type="hidden" id="f-id_maquina" value="">

                <div class="form-row">
                    <label>Rol</label>
                    <select id="f-rol_maquina">
                        <option value="1">Maestra</option>
                        <option value="2">Esclava</option>
                    </select>
                </div>
                <div class="form-row" id="fila-padre" style="display:none;">
                    <label>Máquina maestra (padre)</label>
                    <select id="f-id_maquina_padre"></select>
                </div>

                <div class="form-row">
                    <label>Número de serie *</label>
                    <input type="text" id="f-numero_serie" required>
                </div>
                <div class="form-row">
                    <label>Nombre de la máquina</label>
                    <input type="text" id="f-nombre_maquina" placeholder="Ej. Farmacia acceso principal">
                </div>
                <div class="form-row">
                    <label>Código interno *</label>
                    <input type="text" id="f-codigo_interno" required>
                </div>
                <div class="form-row">
                    <label>Estado</label>
                    <input type="text" id="f-estado_geo" placeholder="Ej. Baja California">
                    <div class="nota-campo">Usado por "Resurtir" para buscar farmacias/CEDIS cercanos con existencia.</div>
                </div>
                <div class="form-row">
                    <label>Municipio</label>
                    <input type="text" id="f-municipio" placeholder="Ej. Tijuana">
                </div>

                <div class="form-row">
                    <label>Último número de carril</label>
                    <input type="number" id="f-numero_carriles" min="0" placeholder="69 maestra / 58 esclava">
                    <div class="nota-campo">Los múltiplos de 10 no son posiciones físicas y se omiten automáticamente.</div>
                </div>
                <div class="form-row">
                    <label>Tipo de máquina</label>
                    <input type="text" id="f-tipo_maquina">
                </div>

                <div class="form-row">
                    <label>Fecha de instalación</label>
                    <input type="date" id="f-fecha_instalacion">
                </div>
                <div class="form-row">
                    <label>Estatus</label>
                    <select id="f-id_estatus">
                        <option value="1">Activa</option>
                        <option value="2">Mantenimiento</option>
                        <option value="3">Fuera de servicio</option>
                    </select>
                </div>

                <div class="form-row completo solo-maestra">
                    <label>Ubicación</label>
                    <input type="text" id="f-nombre_ubicacion">
                </div>
                <div class="form-row completo solo-maestra">
                    <label>Dirección</label>
                    <input type="text" id="f-direccion">
                </div>

                <div class="form-row solo-maestra">
                    <label>Latitud</label>
                    <input type="text" id="f-latitud" inputmode="decimal">
                </div>
                <div class="form-row solo-maestra">
                    <label>Longitud</label>
                    <input type="text" id="f-longitud" inputmode="decimal">
                </div>

                <div class="form-row completo solo-maestra">
                    <label>Sucursal de farmacia vinculada (opcional)</label>
                    <input type="text" id="f-sucursal-buscar" placeholder="Buscar por nombre de sucursal…">
                    <select id="f-id_sucursal_clinica">
                        <option value="">Sin vincular</option>
                    </select>
                </div>

                <div class="form-row completo solo-maestra">
                    <label class="check-row">
                        <input type="checkbox" id="f-branding_activo">
                        Activar personalizacion en app
                    </label>
                    <div class="nota-campo">Si queda apagado, Android debe usar logo, video y colores por defecto.</div>
                </div>
                <div class="form-row completo solo-maestra">
                    <label>Logo para app</label>
                    <input type="file" id="f-logo" accept="image/png,image/jpeg,image/webp">
                    <div class="nota-campo">Opcional. Si no se carga, la app conserva su logo por defecto.</div>
                    <div class="branding-preview" id="preview-logo"></div>
                </div>
                <div class="form-row completo solo-maestra">
                    <label>Video para app</label>
                    <input type="file" id="f-video" accept="video/mp4,video/webm,video/quicktime">
                    <div class="nota-campo">Opcional. Si no se carga, la app conserva su video por defecto.</div>
                    <div class="branding-preview" id="preview-video"></div>
                </div>

                <div class="form-row solo-maestra">
                    <label>Color primario</label>
                    <input type="color" id="f-color_primario" value="#2CD9BE">
                </div>
                <div class="form-row solo-maestra">
                    <label>Color secundario</label>
                    <input type="color" id="f-color_secundario" value="#121A21">
                </div>
                <div class="form-row solo-maestra">
                    <label>Color acento</label>
                    <input type="color" id="f-color_acento" value="#E4AC4F">
                </div>
            </div>
            <div class="modal-pie">
                <span class="modal-mensaje" id="modal-mensaje"></span>
                <button type="button" class="btn-cancelar" id="btn-cancelar">Cancelar</button>
                <button type="submit" class="btn-guardar" id="btn-guardar">Guardar</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal cargar stock (Excel) -->
<div class="overlay-modal" id="overlay-stock">
    <div class="modal">
        <div class="modal-encabezado">
            <h2>Cargar stock desde Excel</h2>
            <button class="modal-cerrar" id="stock-cerrar">&times;</button>
        </div>
        <form id="form-stock">
            <div class="modal-cuerpo una-col">
                <div class="form-row">
                    <label>Máquina principal (maestra)</label>
                    <select id="stock-id_maquina" required></select>
                </div>
                <div class="stock-guia">
                    <strong>Orden de carga:</strong> 1. Descarga la plantilla, 2. captura una fila por producto/lote/carril, 3. elige la máquina maestra y 4. importa el archivo.
                    <br>Usa cabina 1 para la maestra y cabina 2 para la esclava.
                    <br><a class="btn-plantilla-stock" href="api/plantilla_stock.php" download>Descargar plantilla vacía</a>
                </div>
                <div class="stock-reset-box">
                    <div>
                        <strong>Preparar una planimetría nueva</strong>
                        <span>Vacía la maestra y sus esclavas, conserva carriles y capacidad, y registra el ajuste.</span>
                    </div>
                    <button type="button" class="btn-vaciar-stock" id="stock-vaciar">Vaciar stock</button>
                </div>
                <div class="form-row">
                    <label>Archivo de planimetría (.xlsx / .xls)</label>
                    <div class="dropzone" id="stock-dropzone">
                        <div class="icono-dropzone">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none"><path d="M12 16V4M12 4l-5 5M12 4l5 5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/><path d="M4 16v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
                        </div>
                        <div>Arrastra el archivo aquí o haz clic para elegirlo</div>
                        <div class="nombre-archivo" id="stock-nombre-archivo"></div>
                    </div>
                    <input type="file" id="stock-archivo" accept=".xlsx,.xls" style="display:none;" required>
                </div>
                <div class="resultado-importacion" id="stock-resultado"></div>
            </div>
            <div class="modal-pie">
                <span class="modal-mensaje" id="stock-mensaje"></span>
                <button type="button" class="btn-cancelar" id="stock-cancelar">Cerrar</button>
                <button type="submit" class="btn-guardar" id="stock-guardar">Importar</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal generico de aviso: Excel invalido (rojo) / capacidad pendiente (amarillo) -->
<div class="overlay-modal" id="overlay-aviso">
    <div class="modal" style="max-width: 560px;">
        <div class="modal-encabezado">
            <h2 id="aviso-titulo">Aviso</h2>
            <button class="modal-cerrar" id="aviso-cerrar">&times;</button>
        </div>
        <div class="modal-cuerpo una-col" id="aviso-cuerpo"></div>
        <div class="modal-pie">
            <button type="button" class="btn-cancelar" id="aviso-cerrar-pie">Cerrar</button>
        </div>
    </div>
</div>

<!-- Modal importar preregistro de maquinas -->
<div class="overlay-modal" id="overlay-importar-maquinas">
    <div class="modal">
        <div class="modal-encabezado">
            <h2>Importar maquinas desde CSV</h2>
            <button class="modal-cerrar" id="importar-cerrar">&times;</button>
        </div>
        <form id="form-importar-maquinas">
            <div class="modal-cuerpo una-col">
                <div class="form-row">
                    <label>Archivo CSV</label>
                    <input type="file" id="importar-archivo" accept=".csv,text/csv" required>
                    <div class="nota-campo">Columnas: numero_serie,nombre_maquina,codigo_interno,numero_carriles</div>
                </div>
                <div class="nota-campo">
                    Mínimo requerido: numero_serie. Las máquinas quedan pendientes de activación.
                </div>
                <div id="importar-resultado" class="resultado-stock"></div>
            </div>
            <div class="modal-pie">
                <span class="modal-mensaje" id="importar-mensaje"></span>
                <button type="button" class="btn-cancelar" id="importar-cancelar">Cancelar</button>
                <button type="submit" class="btn-guardar" id="importar-guardar">Importar</button>
            </div>
        </form>
    </div>
</div>

<script src="assets/js/comun.js?v=20260912-1"></script>
<script>
    const ES_ADMIN = <?= (Auth::isAdmin() && !Auth::isUsuarioMaquinasDashboard()) ? 'true' : 'false' ?>;
    const PAGE_SIZE = 50;
    let pagina = 1;
    let total = 0;
    let debounceTimer = null;
    let maestrasCache = []; // para poblar el <select> de "máquina padre" y el modal de stock

    const svgMaquina = `<svg width="16" height="16" viewBox="0 0 24 24" fill="none"><rect x="3" y="3" width="18" height="18" rx="3" stroke="currentColor" stroke-width="1.6"/><path d="M8 8h8M8 12h8M8 16h5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>`;

    function textoUltimaConexion(u) {
        if (u.estado_conexion === 'nunca_conectada') return 'Nunca conectada';
        const segundos = Math.max(0, Number(u.segundos_desde_conexion) || 0);
        if (segundos < 60) return 'Hace menos de 1 min';
        const minutos = Math.floor(segundos / 60);
        if (minutos < 60) return `Hace ${minutos} min`;
        const horas = Math.floor(minutos / 60);
        if (horas < 24) return `Hace ${horas} h`;
        return `Hace ${Math.floor(horas / 24)} d`;
    }

    function badgeConexion(u) {
        if (u.estado_conexion === 'online') return '<span class="badge badge-ok">En línea</span>';
        if (u.estado_conexion === 'offline') return '<span class="badge badge-danger">Sin conexión</span>';
        return '<span class="badge badge-neutro">Nunca conectada</span>';
    }

    function detalleConexion(u) {
        const datos = [textoUltimaConexion(u)];
        if (u.tipo_conexion) datos.push(u.tipo_conexion);
        if (u.version_app) datos.push(`App ${u.version_app}`);
        return datos.join(' · ');
    }

    function chipEsclava(e) {
        const claseConexion = e.estado_conexion === 'online' ? 'online' : (e.estado_conexion === 'offline' ? 'offline' : 'nunca');
        return `<span class="chip-unidad es-esclava ${claseConexion}" title="${escapeHtml(detalleConexion(e))}">
            <span class="rol-punto"></span>
            <span class="mono">${escapeHtml(e.numero_serie)}</span>
            ${ES_ADMIN ? `<button type="button" class="btn-editar-esclava" data-id="${e.id_maquina}" title="Editar esclava" aria-label="Editar esclava">✎</button>` : ''}
        </span>`;
    }

    function badgeEstatusMaquina(nombre) {
        const n = (nombre || '').toLowerCase();
        if (n.includes('mant')) return '<span class="badge badge-warn">Mantenimiento</span>';
        if (n.includes('fuera') || n.includes('baja') || n.includes('servicio')) return '<span class="badge badge-danger">Fuera de servicio</span>';
        if (n.includes('act')) return '<span class="badge badge-ok">Activa</span>';
        return `<span class="badge badge-neutro">${escapeHtml(nombre || 'Sin estatus')}</span>`;
    }

    function badgeActivacion(u) {
        if (u.permitir_reactivacion) return '<span class="badge badge-warn">Reactivacion</span>';
        return Number(u.activada) === 1 || u.activada === true
            ? '<span class="badge badge-ok">Activada</span>'
            : '<span class="badge badge-neutro">Pendiente</span>';
    }

    function filaUnidad(u) {
        const dias = u.esclavas.map(e => e.dias_sin_mantenimiento === null || e.dias_sin_mantenimiento === undefined ? null : Number(e.dias_sin_mantenimiento));
        dias.push(u.dias_sin_mantenimiento === null || u.dias_sin_mantenimiento === undefined ? null : Number(u.dias_sin_mantenimiento));
        const peor = peorDias(dias);

        const colEsclavas = u.esclavas.length
            ? u.esclavas.map(chipEsclava).join('')
            : '<span class="sin-esclava">Sin esclavas asociadas</span>';

        return `<div class="unidad" data-id="${u.id_maquina}">
            <div class="maestra-col ir-detalle" data-id="${u.id_maquina}">
                <img class="foto-maquina" src="assets/img/maquina-principal-mdm.png" alt="" loading="lazy">
                <div class="info">
                    <div class="serie-line"><div class="serie mono">${escapeHtml(u.numero_serie)}</div>${badgeConexion(u)}${badgeActivacion(u)}<span class="badge badge-neutro">Cliente</span></div>
                    <div class="meta-mini">${escapeHtml(u.nombre_maquina) || 'Sin nombre asignado'}</div>
                    <div class="meta-mini">${escapeHtml(detalleConexion(u))}</div>
                    <div class="ubic">${escapeHtml(u.nombre_ubicacion) || 'Sin ubicación'}</div>
                    <div class="meta-mini">${escapeHtml(u.codigo_interno) || 'Sin código interno'}</div>
                </div>
            </div>
            <div class="esclavas-col">${colEsclavas}</div>
            <div class="fecha-col ir-detalle" data-id="${u.id_maquina}">${peor === null ? 'Nunca cargada' : `Hace ${peor} día${peor === 1 ? '' : 's'}`}</div>
            <div class="estado-col ir-detalle" data-id="${u.id_maquina}">${badgeMantenimiento(peor)}</div>
            <div class="estado-col ir-detalle" data-id="${u.id_maquina}">${badgeEstatusMaquina(u.estatus_nombre)}</div>
            <div class="acciones-col">
                <button type="button" class="btn-editar" data-id="${u.id_maquina}" title="Editar">✎</button>
                <button type="button" class="btn-reactivar" data-id="${u.id_maquina}" title="Permitir reactivacion">↻</button>
                <button type="button" class="btn-baja" data-id="${u.id_maquina}" title="Dar de baja">🗑</button>
            </div>
        </div>`;
    }

    async function cargarStats() {
        try {
            const [rAten, rOk, rTodas, rNunca, rOnline] = await Promise.all([
                obtenerJSON('api/maquinas.php?agrupado=1&estado=atencion&page_size=1'),
                obtenerJSON('api/maquinas.php?agrupado=1&estado=ok&page_size=1'),
                obtenerJSON('api/maquinas.php?agrupado=1&page_size=1'),
                obtenerJSON('api/maquinas.php?agrupado=1&estado=nunca&page_size=1'),
                obtenerJSON('api/maquinas.php?agrupado=1&conexion=online&page_size=1'),
            ]);
            const stats = document.querySelectorAll('#tira-stats .stat .num');
            stats[0].textContent = rTodas.meta.total;
            stats[1].textContent = rAten.meta.total;
            stats[3].textContent = rOk.meta.total;
            stats[2].textContent = rNunca.meta.total;
            stats[4].textContent = rOnline.meta.total;
        } catch (e) { /* silencioso: la tira de stats no bloquea el listado */ }
    }

    async function cargar(silencioso = false) {
        const cont = document.getElementById('lista-unidades');
        const paginacion = document.getElementById('paginacion');
        if (!silencioso) {
            cont.className = 'cargando';
            cont.textContent = 'Cargando…';
            paginacion.style.display = 'none';
        }

        const q = document.getElementById('buscar').value.trim();
        const estado = document.getElementById('filtro-estado').value;
        const conexion = document.getElementById('filtro-conexion').value;
        const estadoGeo = document.getElementById('filtro-estado-geo').value.trim();
        const municipio = document.getElementById('filtro-municipio').value.trim();

        const params = new URLSearchParams({ agrupado: '1', page: pagina, page_size: PAGE_SIZE });
        if (q) params.set('q', q);
        if (estado) params.set('estado', estado);
        if (conexion) params.set('conexion', conexion);
        if (estadoGeo) params.set('estado_geo', estadoGeo);
        if (municipio) params.set('municipio', municipio);

        try {
            const json = await obtenerJSON(`api/maquinas.php?${params}`);
            total = json.meta.total;
            document.getElementById('contador').textContent = `${total} unidad${total === 1 ? '' : 'es'}`;

            // Guardamos las maestras de esta página para el <select> del modal "esclava"
            maestrasCache = json.data.map(u => ({ id_maquina: u.id_maquina, numero_serie: u.numero_serie }));

            if (json.data.length === 0) {
                cont.className = '';
                cont.innerHTML = '<p class="vacio">No hay unidades que coincidan con la búsqueda.</p>';
                return;
            }

            cont.className = 'lista-unidades';
            cont.innerHTML = json.data.map(filaUnidad).join('');

            cont.querySelectorAll('.ir-detalle').forEach(el => {
                el.addEventListener('click', () => { window.location.href = `maquina.php?id=${el.dataset.id}`; });
            });
            if (ES_ADMIN) {
                cont.querySelectorAll('.btn-editar').forEach(btn => {
                    btn.addEventListener('click', (ev) => { ev.stopPropagation(); abrirModalEditar(parseInt(btn.dataset.id, 10)); });
                });
                cont.querySelectorAll('.btn-editar-esclava').forEach(btn => {
                    btn.addEventListener('click', (ev) => { ev.stopPropagation(); abrirModalEditar(parseInt(btn.dataset.id, 10)); });
                });
                cont.querySelectorAll('.btn-reactivar').forEach(btn => {
                    btn.addEventListener('click', (ev) => { ev.stopPropagation(); permitirReactivacion(parseInt(btn.dataset.id, 10)); });
                });
                cont.querySelectorAll('.btn-baja').forEach(btn => {
                    btn.addEventListener('click', (ev) => { ev.stopPropagation(); darDeBaja(parseInt(btn.dataset.id, 10)); });
                });
            } else {
                cont.querySelectorAll('.acciones-col').forEach(el => el.remove());
            }

            const totalPaginas = Math.max(1, Math.ceil(total / PAGE_SIZE));
            document.getElementById('texto-pagina').textContent = `Página ${pagina} de ${totalPaginas}`;
            document.getElementById('btn-anterior').disabled = pagina <= 1;
            document.getElementById('btn-siguiente').disabled = pagina >= totalPaginas;
            paginacion.style.display = 'flex';
        } catch (e) {
            cont.className = '';
            cont.innerHTML = '<p class="vacio">No se pudo conectar con el servidor.</p>';
        }
    }

    function reiniciarYBuscar() { pagina = 1; cargar(); }

    document.getElementById('buscar').addEventListener('input', () => {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(reiniciarYBuscar, 350);
    });
    document.getElementById('filtro-estado').addEventListener('change', reiniciarYBuscar);
    document.getElementById('filtro-conexion').addEventListener('change', reiniciarYBuscar);
    document.getElementById('filtro-estado-geo').addEventListener('input', () => {
        clearTimeout(debounce);
        debounce = setTimeout(reiniciarYBuscar, 350);
    });
    document.getElementById('filtro-municipio').addEventListener('input', () => {
        clearTimeout(debounce);
        debounce = setTimeout(reiniciarYBuscar, 350);
    });
    document.getElementById('btn-anterior').addEventListener('click', () => { pagina--; cargar(); });
    document.getElementById('btn-siguiente').addEventListener('click', () => { pagina++; cargar(); });

    // ------------------------------------------------------------------
    // Modal crear / editar
    // ------------------------------------------------------------------
    const overlay = document.getElementById('overlay-modal');
    const form = document.getElementById('form-maquina');
    const mensaje = document.getElementById('modal-mensaje');
    const selectRol = document.getElementById('f-rol_maquina');
    const filaPadre = document.getElementById('fila-padre');
    const selectPadre = document.getElementById('f-id_maquina_padre');
    const brandingActivo = document.getElementById('f-branding_activo');

    function poblarSelectPadre(idExcluir) {
        selectPadre.innerHTML = maestrasCache
            .filter(m => m.id_maquina !== idExcluir)
            .map(m => `<option value="${m.id_maquina}">${escapeHtml(m.numero_serie)}</option>`)
            .join('');
    }

    function actualizarVisibilidadPadre() {
        const esEsclava = selectRol.value === '2';
        filaPadre.style.display = esEsclava ? '' : 'none';
        document.querySelectorAll('#form-maquina .solo-maestra').forEach(el => {
            el.classList.toggle('oculto', esEsclava);
        });
        if (esEsclava) {
            buscarSucursal.value = '';
            selectSucursal.innerHTML = '<option value="">Se hereda de la maestra</option>';
            selectSucursal.value = '';
            brandingActivo.checked = false;
            document.getElementById('preview-logo').innerHTML = '';
            document.getElementById('preview-video').innerHTML = '';
        } else if (selectSucursal.options.length === 1 && selectSucursal.options[0].textContent.includes('hereda')) {
            buscarYPoblarSucursales('', null);
        }
    }
    selectRol.addEventListener('change', actualizarVisibilidadPadre);

    // Vínculo opcional con una sucursal de farmacia (cat_SucursalClinica),
    // usado por Pedidos para enviar solicitudes reales a CEDIS.
    const selectSucursal = document.getElementById('f-id_sucursal_clinica');
    const buscarSucursal = document.getElementById('f-sucursal-buscar');
    let debounceSucursal = null;

    function poblarSelectSucursal(sucursales, seleccionActual) {
        const opciones = ['<option value="">Sin vincular</option>'];
        let yaIncluida = false;
        sucursales.forEach(s => {
            if (seleccionActual && String(s.id_SucursalClinica) === String(seleccionActual.id)) {
                yaIncluida = true;
            }
            opciones.push(`<option value="${s.id_SucursalClinica}">${escapeHtml(s.st_Nombre)}</option>`);
        });
        if (seleccionActual && seleccionActual.id && !yaIncluida) {
            opciones.push(`<option value="${seleccionActual.id}">${escapeHtml(seleccionActual.nombre || ('#' + seleccionActual.id))}</option>`);
        }
        selectSucursal.innerHTML = opciones.join('');
        selectSucursal.value = seleccionActual ? String(seleccionActual.id ?? '') : '';
    }

    async function buscarYPoblarSucursales(q, seleccionActual) {
        try {
            const json = await obtenerJSON(`api/sucursales_clinica.php?q=${encodeURIComponent(q)}`);
            poblarSelectSucursal(json.data || [], seleccionActual);
        } catch (e) {
            selectSucursal.innerHTML = '<option value="">Sin vincular</option>';
        }
    }

    buscarSucursal.addEventListener('input', () => {
        clearTimeout(debounceSucursal);
        debounceSucursal = setTimeout(() => buscarYPoblarSucursales(buscarSucursal.value.trim(), null), 300);
    });

    function limpiarFormulario() {
        form.reset();
        document.getElementById('f-id_maquina').value = '';
        document.getElementById('f-id_estatus').value = '1';
        buscarSucursal.value = '';
        selectSucursal.innerHTML = '<option value="">Sin vincular</option>';
        brandingActivo.checked = false;
        document.getElementById('f-color_primario').value = '#2CD9BE';
        document.getElementById('f-color_secundario').value = '#121A21';
        document.getElementById('f-color_acento').value = '#E4AC4F';
        document.getElementById('preview-logo').innerHTML = '';
        document.getElementById('preview-video').innerHTML = '';
        mensaje.textContent = '';
        mensaje.className = 'modal-mensaje';
        actualizarVisibilidadPadre();
    }

    function abrirModalCrear() {
        limpiarFormulario();
        document.getElementById('modal-titulo').textContent = 'Nueva máquina';
        poblarSelectPadre(null);
        buscarYPoblarSucursales('', null);
        overlay.classList.add('abierto');
    }

    async function abrirModalEditar(idMaquina) {
        limpiarFormulario();
        document.getElementById('modal-titulo').textContent = 'Editar máquina';
        overlay.classList.add('abierto');
        mensaje.textContent = 'Cargando datos…';

        try {
            const json = await obtenerJSON(`api/maquina.php?id_maquina=${idMaquina}`);
            const m = json.data;
            document.getElementById('modal-titulo').textContent = Number(m.rol_maquina) === 2 ? 'Editar esclava' : 'Editar máquina';

            poblarSelectPadre(idMaquina);

            document.getElementById('f-id_maquina').value = m.id_maquina;
            document.getElementById('f-numero_serie').value = m.numero_serie || '';
            document.getElementById('f-nombre_maquina').value = m.nombre_maquina || '';
            document.getElementById('f-estado_geo').value = m.estado_geo || '';
            document.getElementById('f-municipio').value = m.municipio || '';
            document.getElementById('f-codigo_interno').value = m.codigo_interno || '';
            document.getElementById('f-nombre_ubicacion').value = m.nombre_ubicacion || '';
            document.getElementById('f-direccion').value = m.direccion || '';
            document.getElementById('f-latitud').value = m.latitud ?? '';
            document.getElementById('f-longitud').value = m.longitud ?? '';
            document.getElementById('f-numero_carriles').value = m.numero_carriles ?? '';
            document.getElementById('f-tipo_maquina').value = m.tipo_maquina || '';
            document.getElementById('f-fecha_instalacion').value = m.fecha_instalacion ? m.fecha_instalacion.substring(0, 10) : '';
            document.getElementById('f-id_estatus').value = m.id_estatus ?? '1';
            selectRol.value = String(m.rol_maquina ?? '1');
            if (m.id_maquina_padre) {
                selectPadre.value = String(m.id_maquina_padre);
            }
            buscarSucursal.value = '';
            await buscarYPoblarSucursales('', m.id_sucursal_clinica ? { id: m.id_sucursal_clinica, nombre: m.sucursal_nombre } : null);

            const branding = m.branding || {};
            brandingActivo.checked = !!branding.activo;
            document.getElementById('f-color_primario').value = branding.color_primario || '#2CD9BE';
            document.getElementById('f-color_secundario').value = branding.color_secundario || '#121A21';
            document.getElementById('f-color_acento').value = branding.color_acento || '#E4AC4F';
            document.getElementById('preview-logo').innerHTML = branding.logo_url
                ? `<a href="${escapeHtml(branding.logo_url)}" target="_blank" rel="noopener">Ver logo actual</a>`
                : '';
            document.getElementById('preview-video').innerHTML = branding.video_url
                ? `<a href="${escapeHtml(branding.video_url)}" target="_blank" rel="noopener">Ver video actual</a>`
                : '';
            actualizarVisibilidadPadre();
            mensaje.textContent = '';
        } catch (e) {
            mensaje.textContent = 'No se pudo cargar la máquina: ' + e.message;
            mensaje.className = 'modal-mensaje error';
        }
    }

    function cerrarModal() { overlay.classList.remove('abierto'); }

    document.getElementById('btn-nueva-maquina')?.addEventListener('click', abrirModalCrear);
    document.getElementById('modal-cerrar').addEventListener('click', cerrarModal);
    document.getElementById('btn-cancelar').addEventListener('click', cerrarModal);
    overlay.addEventListener('click', (ev) => { if (ev.target === overlay) cerrarModal(); });

    form.addEventListener('submit', async (ev) => {
        ev.preventDefault();
        const btnGuardar = document.getElementById('btn-guardar');
        btnGuardar.disabled = true;
        mensaje.textContent = '';
        mensaje.className = 'modal-mensaje';

        const idMaquina = document.getElementById('f-id_maquina').value;
        const payload = new FormData();
        payload.append('id_maquina', idMaquina || '');
        payload.append('numero_serie', document.getElementById('f-numero_serie').value.trim());
        payload.append('nombre_maquina', document.getElementById('f-nombre_maquina').value.trim());
        payload.append('estado_geo', document.getElementById('f-estado_geo').value.trim());
        payload.append('municipio', document.getElementById('f-municipio').value.trim());
        payload.append('codigo_interno', document.getElementById('f-codigo_interno').value.trim());
        payload.append('segmento_operacion', 'cliente');
        payload.append('nombre_ubicacion', document.getElementById('f-nombre_ubicacion').value.trim());
        payload.append('direccion', document.getElementById('f-direccion').value.trim());
        payload.append('latitud', document.getElementById('f-latitud').value.trim());
        payload.append('longitud', document.getElementById('f-longitud').value.trim());
        payload.append('rol_maquina', String(parseInt(selectRol.value, 10)));
        payload.append('id_maquina_padre', selectRol.value === '2' ? String(parseInt(selectPadre.value, 10) || '') : '');
        payload.append('numero_carriles', document.getElementById('f-numero_carriles').value || '');
        payload.append('tipo_maquina', document.getElementById('f-tipo_maquina').value.trim());
        payload.append('fecha_instalacion', document.getElementById('f-fecha_instalacion').value || '');
        payload.append('id_estatus', String(parseInt(document.getElementById('f-id_estatus').value, 10) || 1));
        payload.append('id_sucursal_clinica', selectSucursal.value || '');
        const logo = document.getElementById('f-logo').files[0];
        const video = document.getElementById('f-video').files[0];
        const cambioArchivoBranding = !!(logo || video);
        payload.append('branding_activo', (brandingActivo.checked || cambioArchivoBranding) ? '1' : '0');
        if (logo) payload.append('logo', logo);
        if (video) payload.append('video', video);
        if (brandingActivo.checked && !cambioArchivoBranding) {
            payload.append('color_primario', document.getElementById('f-color_primario').value);
            payload.append('color_secundario', document.getElementById('f-color_secundario').value);
            payload.append('color_acento', document.getElementById('f-color_acento').value);
        }

        try {
            const res = await fetch('api/maquina_guardar.php', {
                method: 'POST',
                body: payload,
            });
            const textoRespuesta = await res.text();
            let json;
            try {
                json = JSON.parse(textoRespuesta);
            } catch (parseError) {
                throw new Error(`HTTP ${res.status}: ${textoRespuesta.slice(0, 220) || 'Respuesta vacia del servidor'}`);
            }
            if (!json.ok) {
                mensaje.textContent = json.error || 'No se pudo guardar.';
                mensaje.className = 'modal-mensaje error';
                return;
            }
            const avisos = [json.aviso, json.aviso_carriles].filter(Boolean);
            mensaje.textContent = avisos.length ? avisos.join(' ') : `Máquina ${json.data.accion}.`;
            mensaje.className = 'modal-mensaje ok';
            await cargar();
            setTimeout(cerrarModal, avisos.length ? 3200 : 700);
        } catch (e) {
            mensaje.textContent = e.message || 'No se pudo conectar con el servidor.';
            mensaje.className = 'modal-mensaje error';
        } finally {
            btnGuardar.disabled = false;
        }
    });

    async function permitirReactivacion(idMaquina) {
        const confirmado = await mlConfirm(
            'Se revocará el token actual y la máquina podrá activarse otra vez en sitio usando su número de serie.',
            'Permitir reactivacion',
            'Permitir'
        );
        if (!confirmado) return;

        try {
            const res = await fetch('api/maquina_reactivacion.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id_maquina: idMaquina, permitir_reactivacion: 1 }),
            });
            const json = await res.json();
            if (!json.ok) throw new Error(json.error || 'No se pudo permitir la reactivacion');
            await mlAlert(`Maquina ${json.data.numero_serie} lista para reactivarse en sitio.`, 'success', 'Listo');
            await cargar();
        } catch (e) {
            await mlAlert(e.message || 'No se pudo permitir la reactivacion');
        }
    }

    // ------------------------------------------------------------------
    // Dar de baja (borrado lógico)
    // ------------------------------------------------------------------
    async function darDeBaja(idMaquina) {
        const confirmado = await mlConfirm(
            'Quedara marcada como fuera de servicio y se ocultara del listado normal. Si es maestra, tambien se daran de baja sus esclavas.',
            'Dar de baja maquina',
            'Dar de baja'
        );
        if (!confirmado) return;

        try {
            const res = await fetch('api/maquina_eliminar.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id_maquina: idMaquina }),
            });
            const json = await res.json();
            if (!json.ok) {
                await mlAlert(json.error || 'No se pudo dar de baja la maquina.');
                return;
            }
            await mlAlert(`Maquina dada de baja. Registros afectados: ${json.data?.maquinas_afectadas || 1}.`, 'success', 'Listo');
            await cargar();
            await cargarStats();
        } catch (e) {
            await mlAlert('No se pudo conectar con el servidor.');
        }
    }

    // ------------------------------------------------------------------
    // Modal "Cargar stock" (Excel)
    // ------------------------------------------------------------------
    const overlayStock = document.getElementById('overlay-stock');
    const formStock = document.getElementById('form-stock');
    const stockMensaje = document.getElementById('stock-mensaje');
    const stockResultado = document.getElementById('stock-resultado');
    const stockSelectMaquina = document.getElementById('stock-id_maquina');
    const stockDropzone = document.getElementById('stock-dropzone');
    const stockInputArchivo = document.getElementById('stock-archivo');

    // ------------------------------------------------------------------
    // Modal generico de aviso: rojo (Excel invalido) / amarillo (capacidad
    // pendiente de autorizacion) -- una sola pieza reutilizada para las dos
    // situaciones de "Cargar stock" que necesitan la atencion inmediata del
    // administrador, cada una con su color. Decision del usuario 2026-09-10.
    // ------------------------------------------------------------------
    const overlayAviso = document.getElementById('overlay-aviso');
    const avisoTitulo = document.getElementById('aviso-titulo');
    const avisoCuerpo = document.getElementById('aviso-cuerpo');

    function cerrarAviso() { overlayAviso.classList.remove('abierto'); }
    document.getElementById('aviso-cerrar').addEventListener('click', cerrarAviso);
    document.getElementById('aviso-cerrar-pie').addEventListener('click', cerrarAviso);
    overlayAviso.addEventListener('click', (ev) => { if (ev.target === overlayAviso) cerrarAviso(); });

    // Recuadro rojo: Excel invalido (ver ExcelColumnasFaltantesException) u
    // otro error de validacion devuelto por maquina_importar_stock.php.
    function mostrarAvisoError(mensaje) {
        avisoTitulo.textContent = 'Archivo inválido';
        avisoCuerpo.innerHTML = `<div class="aviso-caja critico"><p class="aviso-texto">${escapeHtml(mensaje)}</p></div>`;
        overlayAviso.classList.add('abierto');
    }

    // Recuadro amarillo: carriles cuya carga se limito porque excedio la
    // capacidad_maxima ya registrada (ver LoteRegistrador::registrar() /
    // obtenerCapacidadPendiente()). Cada fila lleva en su dataset todo lo
    // que autorizar_capacidad_carril.php necesita para completar la carga.
    let ultimoCapacidadPendiente = [];
    function mostrarAvisoCapacidadPendiente(items) {
        ultimoCapacidadPendiente = items;
        avisoTitulo.textContent = `Capacidad máxima pendiente de autorización (${items.length})`;
        const filas = items.map(p => {
            const datos = {
                id_carril: p.id_carril,
                id_producto: p.id_producto,
                numero_lote: p.numero_lote || '',
                fecha_caducidad: p.fecha_caducidad || '',
                cantidad_pendiente: p.cantidad_pendiente,
                capacidad_propuesta: p.capacidad_propuesta,
            };
            const atributos = Object.entries(datos).map(([k, v]) => `data-${k.replace(/_/g, '-')}="${escapeHtml(String(v))}"`).join(' ');
            return `<li ${atributos}>
                <span>carril=${escapeHtml(String(p.numero_carril || ''))} producto_id=${escapeHtml(String(p.id_producto || ''))} lote=${escapeHtml(String(p.numero_lote || ''))}:
                actual <span class="mono">${escapeHtml(String(p.capacidad_actual))}</span> → propuesta <span class="mono">${escapeHtml(String(p.capacidad_propuesta))}</span>
                (+${escapeHtml(String(p.cantidad_pendiente))} sin cargar)</span>
                <span class="acciones">
                    <button type="button" class="btn-autorizar-capacidad" data-accion="descartar">Descartar</button>
                    <button type="button" class="btn-autorizar-capacidad" data-accion="autorizar">Autorizar</button>
                </span>
            </li>`;
        }).join('');
        avisoCuerpo.innerHTML = `
            <div class="aviso-caja advertencia">
                <p class="aviso-texto">La carga se limitó al máximo ya registrado para estos carriles. Autoriza para subir la capacidad máxima y completar la carga pendiente, o descarta para dejar el límite como está.</p>
            </div>
            <ul class="aviso-lista-carriles">${filas}</ul>
        `;
        overlayAviso.classList.add('abierto');
    }

    // Autorizar/descartar ampliaciones de capacidad_maxima -- delegado en
    // avisoCuerpo porque su contenido se reemplaza en cada carga.
    avisoCuerpo.addEventListener('click', async (ev) => {
        const btn = ev.target.closest('.btn-autorizar-capacidad');
        if (!btn) return;
        const accion = btn.dataset.accion;
        const li = btn.closest('li');
        const d = li ? li.dataset : {};
        btn.disabled = true;
        try {
            const res = await fetch('api/autorizar_capacidad_carril.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    id_carril: d.idCarril,
                    accion,
                    id_producto: d.idProducto,
                    numero_lote: d.numeroLote,
                    fecha_caducidad: d.fechaCaducidad,
                    cantidad_pendiente: d.cantidadPendiente,
                    capacidad_propuesta: d.capacidadPropuesta,
                }),
            });
            const json = await res.json();
            if (!json.ok) throw new Error(json.error || 'No se pudo procesar.');
            if (li) {
                li.querySelectorAll('button').forEach(b => b.disabled = true);
                const etiqueta = accion === 'autorizar' ? 'Autorizado ✓' : 'Descartado';
                li.insertAdjacentHTML('beforeend', ` <strong>${etiqueta}</strong>`);
            }
        } catch (e) {
            btn.disabled = false;
            alert(e.message || 'No se pudo procesar la autorización.');
        }
    });
    const stockNombreArchivo = document.getElementById('stock-nombre-archivo');
    const stockBtnGuardar = document.getElementById('stock-guardar');
    const stockBtnVaciar = document.getElementById('stock-vaciar');

    // Trae TODAS las máquinas maestras (no solo la página actual) para el <select>.
    async function poblarSelectMaquinasStock() {
        stockSelectMaquina.innerHTML = '<option value="">Cargando máquinas…</option>';
        try {
            const json = await obtenerJSON('api/maquinas.php?agrupado=1&page_size=1000');
            const maestras = json.data.map(u => ({ id_maquina: u.id_maquina, numero_serie: u.numero_serie, ubicacion: u.nombre_ubicacion }));
            stockSelectMaquina.innerHTML = maestras.length
                ? maestras.map(m => `<option value="${escapeHtml(m.numero_serie)}">${escapeHtml(m.numero_serie)}${m.ubicacion ? ' — ' + escapeHtml(m.ubicacion) : ''}</option>`).join('')
                : '<option value="">No hay máquinas registradas</option>';
        } catch (e) {
            stockSelectMaquina.innerHTML = '<option value="">No se pudo cargar el listado</option>';
        }
    }

    function limpiarFormularioStock() {
        formStock.reset();
        stockNombreArchivo.textContent = '';
        stockResultado.innerHTML = '';
        stockMensaje.textContent = '';
        stockMensaje.className = 'modal-mensaje';
        stockInputArchivo.value = '';
    }

    function abrirModalStock() {
        limpiarFormularioStock();
        poblarSelectMaquinasStock();
        overlayStock.classList.add('abierto');
    }
    function cerrarModalStock() { overlayStock.classList.remove('abierto'); }

    document.getElementById('btn-cargar-stock')?.addEventListener('click', abrirModalStock);
    document.getElementById('stock-cerrar').addEventListener('click', cerrarModalStock);
    document.getElementById('stock-cancelar').addEventListener('click', cerrarModalStock);
    overlayStock.addEventListener('click', (ev) => { if (ev.target === overlayStock) cerrarModalStock(); });

    const overlayImportar = document.getElementById('overlay-importar-maquinas');
    const formImportar = document.getElementById('form-importar-maquinas');
    const importarArchivo = document.getElementById('importar-archivo');
    const importarMensaje = document.getElementById('importar-mensaje');
    const importarResultado = document.getElementById('importar-resultado');

    function abrirModalImportar() {
        formImportar.reset();
        importarMensaje.textContent = '';
        importarMensaje.className = 'modal-mensaje';
        importarResultado.innerHTML = '';
        overlayImportar.classList.add('abierto');
    }
    function cerrarModalImportar() { overlayImportar.classList.remove('abierto'); }

    document.getElementById('btn-importar-maquinas')?.addEventListener('click', abrirModalImportar);
    document.getElementById('importar-cerrar').addEventListener('click', cerrarModalImportar);
    document.getElementById('importar-cancelar').addEventListener('click', cerrarModalImportar);
    overlayImportar.addEventListener('click', (ev) => { if (ev.target === overlayImportar) cerrarModalImportar(); });

    formImportar.addEventListener('submit', async (ev) => {
        ev.preventDefault();
        if (!importarArchivo.files[0]) {
            importarMensaje.textContent = 'Selecciona un CSV.';
            importarMensaje.className = 'modal-mensaje error';
            return;
        }

        const fd = new FormData();
        fd.append('archivo', importarArchivo.files[0]);
        importarMensaje.textContent = 'Importando...';
        importarMensaje.className = 'modal-mensaje';
        try {
            const res = await fetch('api/maquinas_preregistro_csv.php', { method: 'POST', body: fd });
            const json = await res.json();
            if (!json.ok) throw new Error(json.error || 'No se pudo importar');
            const omitidas = json.data.omitidas || [];
            importarMensaje.textContent = 'Importacion completada.';
            importarMensaje.className = 'modal-mensaje ok';
            importarResultado.innerHTML = `
                <div class="resumen-ok">Creadas: ${json.data.creadas} · Actualizadas: ${json.data.actualizadas} · Omitidas: ${omitidas.length}</div>
                ${omitidas.length ? `<ul>${omitidas.slice(0, 20).map(o => `<li>Fila ${escapeHtml(o.fila)} ${escapeHtml(o.numero_serie || '')}: ${escapeHtml(o.motivo)}</li>`).join('')}</ul>` : ''}
            `;
            await cargar();
            await cargarStats();
        } catch (e) {
            importarMensaje.textContent = e.message || 'No se pudo importar';
            importarMensaje.className = 'modal-mensaje error';
        }
    });

    stockDropzone.addEventListener('click', () => stockInputArchivo.click());
    stockDropzone.addEventListener('dragover', (ev) => { ev.preventDefault(); stockDropzone.classList.add('arrastrando'); });
    stockDropzone.addEventListener('dragleave', () => stockDropzone.classList.remove('arrastrando'));
    stockDropzone.addEventListener('drop', (ev) => {
        ev.preventDefault();
        stockDropzone.classList.remove('arrastrando');
        if (ev.dataTransfer.files.length) {
            stockInputArchivo.files = ev.dataTransfer.files;
            stockNombreArchivo.textContent = ev.dataTransfer.files[0].name;
        }
    });
    stockInputArchivo.addEventListener('change', () => {
        stockNombreArchivo.textContent = stockInputArchivo.files[0]?.name || '';
    });

    stockBtnVaciar.addEventListener('click', async () => {
        const numeroSerie = stockSelectMaquina.value;
        if (!numeroSerie) {
            stockMensaje.textContent = 'Elige primero la máquina principal.';
            stockMensaje.className = 'modal-mensaje error';
            return;
        }

        const mensaje = `Se dejarán en cero todos los carriles de ${numeroSerie} y sus esclavas. Se quitará la planimetría actual y esta acción no se puede deshacer.`;
        let confirmado = false;
        try {
            confirmado = window.mlConfirm
                ? await window.mlConfirm(mensaje, 'Vaciar stock completo', 'Sí, vaciar stock')
                : window.confirm(mensaje);
        } catch (_) {
            confirmado = window.confirm(mensaje);
        }
        if (!confirmado) return;

        stockBtnVaciar.disabled = true;
        stockBtnGuardar.disabled = true;
        stockMensaje.textContent = 'Vaciando stock y registrando movimientos…';
        stockMensaje.className = 'modal-mensaje';
        stockResultado.innerHTML = '';

        try {
            const res = await fetch('api/maquina_vaciar_stock.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ numero_serie: numeroSerie, confirmar: true }),
            });
            const json = await res.json();
            if (!json.ok) throw new Error(json.error || 'No se pudo vaciar el stock.');

            const data = json.data;
            stockMensaje.textContent = 'Stock vaciado. Ya puedes cargar la nueva planimetría.';
            stockMensaje.className = 'modal-mensaje ok';
            stockResultado.innerHTML = `
                <div class="resumen-ok">Unidad lista para una nueva carga.</div>
                <div>${escapeHtml(String(data.maquinas_afectadas))} máquina(s) · ${escapeHtml(String(data.carriles_limpiados))} carriles en cero · ${escapeHtml(String(data.piezas_retiradas))} piezas retiradas.</div>
                <div>Series: <span class="mono">${(data.series_afectadas || []).map(escapeHtml).join(', ')}</span></div>
                <div>${escapeHtml(String(data.movimientos_registrados))} movimiento(s) de auditoría · ${escapeHtml(String(data.solicitudes_canceladas))} solicitud(es) pendiente(s) cancelada(s).</div>
            `;
            await cargar();
            await cargarStats();
        } catch (e) {
            stockMensaje.textContent = e.message || 'No se pudo vaciar el stock.';
            stockMensaje.className = 'modal-mensaje error';
        } finally {
            stockBtnVaciar.disabled = false;
            stockBtnGuardar.disabled = false;
        }
    });

    function renderResultadoStock(data) {
        const partes = [];
        partes.push(`<div class="resumen-ok">Importación completa: ${data.filas_leidas} fila(s) leídas del Excel.</div>`);
        partes.push(`<div>id_reabastecimiento: <span class="mono">${data.id_reabastecimiento}</span></div>`);
        partes.push(`<div>Máquina principal: <span class="mono">id_maquina=${data.id_maquina_principal}</span>${data.id_maquina_esclava ? ` · Esclava: <span class="mono">id_maquina=${data.id_maquina_esclava}</span>` : ' · Sin esclava configurada'}</div>`);

        const bloque = (titulo, items, render) => {
            if (!items || !items.length) return;
            partes.push(`<h4>${titulo} (${items.length})</h4><ul>${items.map(render).join('')}</ul>`);
        };
        bloque('Capacidad máxima de carril establecida (primera carga)', data.capacidad_ajustada, a => `<li>carril=${escapeHtml(String(a.numero_carril || ''))}: ${escapeHtml(String(a.capacidad_anterior))} → ${escapeHtml(String(a.capacidad_nueva))}</li>`);

        if (data.capacidad_pendiente && data.capacidad_pendiente.length) {
            partes.push(`<div class="resumen-ok" style="border-color:var(--ambar);color:var(--ambar);">
                ⚠ ${data.capacidad_pendiente.length} carril(es) con capacidad pendiente de autorización
                <button type="button" class="btn-ver-pendientes" id="btn-ver-pendientes-capacidad" style="margin-left:8px;">Ver</button>
            </div>`);
        }

        // Carriles ya en su capacidad máxima (cero espacio disponible): es
        // un estado esperado/correcto, no una discrepancia -- solo se
        // informa (verde), sin pedir autorización. Decisión del usuario
        // 2026-09-10.
        if (data.carril_lleno && data.carril_lleno.length) {
            partes.push(`<div class="aviso-caja exito">
                <p class="aviso-texto">✓ ${data.carril_lleno.length} carril(es) ya estaban en su capacidad máxima -- no se cargó nada ahí, todo en orden.</p>
                <ul class="aviso-lista-carriles" style="margin-top:10px;">${data.carril_lleno.map(c => `
                    <li><span>carril=${escapeHtml(String(c.numero_carril || ''))} producto_id=${escapeHtml(String(c.id_producto || ''))}: capacidad máxima ${escapeHtml(String(c.capacidad_maxima))} (${escapeHtml(String(c.cantidad_no_cargada))} unidad(es) no cargadas)</span></li>`).join('')}</ul>
            </div>`);
        }

        bloque('Carriles sin match', data.no_asignados, n => `<li>código=${escapeHtml(n.codigo_barras || '')} ubicación=${escapeHtml(n.ubicacion_carril || '')}</li>`);
        bloque('Filas sin fecha de caducidad válida', data.sin_fecha, f => `<li>código=${escapeHtml(f.codigo_barras || '')}</li>`);
        bloque('Lotes sin carril match', data.sin_carril, c => `<li>producto_id=${c.id_producto} carril=${c.numero_carril}</li>`);
        bloque('Filas sin traspaso completo', data.sin_traspaso, t => `<li>carril=${escapeHtml(String(t.numero_carril || ''))} producto_id=${escapeHtml(String(t.id_producto || ''))} — ${escapeHtml(t.motivo || '')}</li>`);
        bloque('Filas con error', data.errores, e => `<li>código=${escapeHtml(e.codigo_barras || '')} — ${escapeHtml(e.error_procesamiento || '')}</li>`);

        stockResultado.innerHTML = partes.join('');

        document.getElementById('btn-ver-pendientes-capacidad')?.addEventListener('click', () => {
            mostrarAvisoCapacidadPendiente(ultimoCapacidadPendiente);
        });
    }

    formStock.addEventListener('submit', async (ev) => {
        ev.preventDefault();

        if (!stockInputArchivo.files.length) {
            stockMensaje.textContent = 'Elige un archivo Excel.';
            stockMensaje.className = 'modal-mensaje error';
            return;
        }
        if (!stockSelectMaquina.value) {
            stockMensaje.textContent = 'Elige la máquina principal.';
            stockMensaje.className = 'modal-mensaje error';
            return;
        }

        stockBtnGuardar.disabled = true;
        stockMensaje.textContent = 'Importando… esto puede tardar unos segundos.';
        stockMensaje.className = 'modal-mensaje';
        stockResultado.innerHTML = '';

        const fd = new FormData();
        fd.append('archivo', stockInputArchivo.files[0]);
        fd.append('numero_serie', stockSelectMaquina.value);

        try {
            const res = await fetch('api/maquina_importar_stock.php', { method: 'POST', body: fd });
            const json = await res.json();

            if (!json.ok) {
                stockMensaje.textContent = '';
                mostrarAvisoError(json.error || 'No se pudo importar el archivo.');
                return;
            }

            stockMensaje.textContent = '';
            renderResultadoStock(json.data);
            if (json.data.capacidad_pendiente && json.data.capacidad_pendiente.length) {
                mostrarAvisoCapacidadPendiente(json.data.capacidad_pendiente);
            }
            cargar();
            cargarStats();
        } catch (e) {
            stockMensaje.textContent = 'No se pudo conectar con el servidor.';
            stockMensaje.className = 'modal-mensaje error';
        } finally {
            stockBtnGuardar.disabled = false;
        }
    });

    const conexionInicial = new URLSearchParams(window.location.search).get('conexion');
    if (['online', 'offline', 'nunca'].includes(conexionInicial)) {
        document.getElementById('filtro-conexion').value = conexionInicial;
    }
    cargarStats();
    cargar();
    setInterval(() => {
        if (document.hidden || document.querySelector('.overlay-modal.abierto')) return;
        cargarStats();
        cargar(true);
    }, 60000);
</script>
</body>
</html>

