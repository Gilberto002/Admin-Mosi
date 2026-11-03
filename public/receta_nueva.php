<?php
// public/receta_nueva.php - armar receta de medicamentos y generar codigo de barras para el lector de la maquina
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/bootstrap.php';

use App\Auth\Auth;

Auth::requireLogin();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>MDM - Nueva receta</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/estilo.css?v=20260914-2">
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
    <style>
        body.tema-oscuro {
            --bg: #1A1D21; --bg-elevada: #202429; --bg-hover: #262B31;
            --linea: #2E3339; --linea-suave: #282C32;
            --texto: #EAF3F1; --texto-suave: #9FADAA; --texto-tenue: #6E7A78;
            --petroleo: #00A3A3; --petroleo-fuerte: #006B6B; --petroleo-wash: rgba(0, 163, 163, .14);
            --ambar: #E8A94B; --ambar-wash: rgba(232, 169, 75, .14);
            --critico: #E8677A; --critico-wash: rgba(232, 103, 122, .14);
            background: var(--bg); color: var(--texto);
        }
        body.tema-oscuro .sidebar { background: var(--bg-elevada); border-right: 1px solid var(--linea); }
        body.tema-oscuro .marca .icono-enlace { color: var(--petroleo); }
        body.tema-oscuro .marca .texto small { color: var(--texto-tenue); }
        body.tema-oscuro .nav-lateral a { color: var(--texto-suave); }
        body.tema-oscuro .nav-lateral a:hover { background: var(--bg-hover); color: var(--texto); }
        body.tema-oscuro .nav-lateral a.activa { background: var(--petroleo-wash); color: var(--petroleo); }
        body.tema-oscuro h1, body.tema-oscuro h2, body.tema-oscuro h3 { color: var(--texto); }
        body.tema-oscuro .card { background: var(--bg-elevada); border: 1px solid var(--linea); border-radius: var(--radius-lg); box-shadow: none; }
        body.tema-oscuro .card-encabezado { border-bottom: 1px solid var(--linea-suave); }
        body.tema-oscuro .vacio, body.tema-oscuro .cargando { color: var(--texto-tenue); font-style: italic; padding: 30px 14px; text-align: center; }
        body.tema-oscuro .form-row label { color: var(--texto-suave); display: block; font-size: 12px; margin-bottom: 5px; }
        body.tema-oscuro input[type="text"], body.tema-oscuro input[type="number"], body.tema-oscuro select {
            background: var(--bg); border: 1px solid var(--linea); color: var(--texto);
            padding: 9px 11px; border-radius: var(--radius-sm); font-family: inherit; font-size: 13.5px; width: 100%; box-sizing: border-box;
        }
        body.tema-oscuro table { width: 100%; border-collapse: collapse; font-size: 13px; }
        body.tema-oscuro th, body.tema-oscuro td { text-align: left; padding: 10px 12px; border-bottom: 1px solid var(--linea-suave); }
        body.tema-oscuro th { color: var(--texto-tenue); font-weight: 600; text-transform: uppercase; font-size: 10px; letter-spacing: .06em; }
        body.tema-oscuro .mono { font-family: 'JetBrains Mono', monospace; }
        body.tema-oscuro button.primario { background: var(--petroleo); color: #06201F; border: none; }
        body.tema-oscuro button.primario:hover { background: #00B8B8; }
        body.tema-oscuro button.primario:disabled { opacity: .5; }

        .grid-receta { display: grid; grid-template-columns: minmax(0, 1fr) 310px; gap: 18px; align-items: start; }
        @media (max-width: 900px) { .grid-receta { grid-template-columns: 1fr; } }

        .buscador-carriles-receta { display: flex; gap: 10px; padding: 4px 0 14px; flex-wrap: wrap; }
        .buscador-carriles-receta input, .buscador-carriles-receta select { flex: 1; min-width: 180px; }
        .selector-modo-receta { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 12px; }
        .selector-modo-receta button {
            border: 1px solid var(--linea); background: var(--bg); color: var(--texto-suave);
            border-radius: 8px; padding: 8px 11px; font: inherit; font-size: 12px; font-weight: 700; cursor: pointer;
        }
        .selector-modo-receta button.activo { border-color: var(--petroleo); color: var(--petroleo); background: var(--petroleo-wash); }

        .cabinas-receta { display: grid; gap: 18px; }
        .cabina-receta { min-width: 0; }
        .cabina-receta__cabecera { display: flex; align-items: end; justify-content: space-between; gap: 12px; margin-bottom: 9px; }
        .cabina-receta__cabecera h3 { margin: 0; font-size: 13px; }
        .cabina-receta__cabecera span { color: var(--texto-tenue); font-size: 11px; }
        .cabina-receta__scroll { overflow-x: auto; padding-bottom: 5px; }
        .cuadricula-carriles {
            display: grid; grid-template-columns: repeat(9, minmax(72px, 1fr));
            gap: 7px; min-width: 710px;
        }
        .posicion-carril {
            position: relative; min-height: 96px; border: 1px solid var(--linea); border-radius: 8px;
            padding: 9px 8px 8px; background: var(--bg); color: var(--texto); text-align: left;
            font: inherit; cursor: pointer; overflow: hidden; transition: border-color .12s ease, background .12s ease;
        }
        .posicion-carril:hover:not(:disabled) { border-color: var(--petroleo); background: var(--petroleo-wash); }
        .posicion-carril:focus-visible { outline: 2px solid var(--petroleo); outline-offset: 2px; }
        .posicion-carril:disabled { cursor: default; }
        .posicion-carril.vacia { color: var(--texto-tenue); border-style: dashed; background: transparent; }
        .posicion-carril.sin-stock { opacity: .58; }
        .posicion-carril.seleccionada { border-color: var(--petroleo); box-shadow: inset 0 0 0 1px var(--petroleo); }
        .posicion-carril.fuera-filtro { opacity: .16; pointer-events: none; }
        .posicion-carril__numero { display: block; font-family: 'JetBrains Mono', monospace; font-size: 10px; color: var(--texto-tenue); margin-bottom: 7px; }
        .posicion-carril__nombre { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; overflow-wrap: anywhere; font-size: 11.5px; font-weight: 700; line-height: 1.25; }
        .posicion-carril__stock { position: absolute; left: 8px; bottom: 7px; color: var(--texto-tenue); font-size: 10px; }
        .posicion-carril__cantidad {
            position: absolute; right: 6px; top: 6px; min-width: 20px; height: 20px; padding: 0 5px;
            display: inline-flex; align-items: center; justify-content: center; border-radius: 10px;
            background: var(--petroleo); color: #06201F; font-size: 10px; font-weight: 800;
        }
        .leyenda-cuadricula { display: flex; flex-wrap: wrap; gap: 12px; margin: 0 0 12px; color: var(--texto-tenue); font-size: 11px; }
        .leyenda-cuadricula span { display: inline-flex; align-items: center; gap: 5px; }
        .leyenda-cuadricula .indicador { width: 7px; height: 7px; border-radius: 50%; display: inline-block; }
        .panel-receta-actual { position: sticky; top: 18px; }

        .carrito { display: flex; flex-direction: column; gap: 8px; }
        .carrito-item {
            display: grid; grid-template-columns: minmax(0, 1fr) auto; align-items: center; gap: 10px; background: var(--bg); border: 1px solid var(--linea-suave);
            border-radius: var(--radius-sm); padding: 8px 12px; font-size: 13px;
        }
        .carrito-item .info { flex: 1; min-width: 0; }
        .carrito-item .info .nombre { color: var(--texto); }
        .carrito-item .info .detalle { color: var(--texto-tenue); font-size: 11.5px; }
        .carrito-item .controles { display: flex; align-items: center; gap: 5px; }
        .carrito-item .cantidad { min-width: 24px; text-align: center; font-weight: 700; font-family: 'Space Grotesk', sans-serif; color: var(--petroleo); }
        .carrito-item .ajustar, .carrito-item .quitar { width: 28px; height: 28px; border: 1px solid var(--linea); border-radius: 6px; background: var(--bg-elevada); color: var(--texto-suave); cursor: pointer; }
        .carrito-item .ajustar:hover { border-color: var(--petroleo); color: var(--petroleo); }
        .carrito-item .quitar { border: none; background: transparent; font-size: 16px; }
        .carrito-item .quitar:hover { color: var(--critico); }
        .carrito-vacio { color: var(--texto-tenue); font-style: italic; font-size: 13px; text-align: center; padding: 20px 10px; }

        .msg-ok { background: var(--petroleo-wash); color: var(--petroleo); padding: 10px 13px; border-radius: var(--radius-sm); font-size: 13px; margin-top: 10px; }
        .msg-error { background: var(--critico-wash); color: var(--critico); padding: 10px 13px; border-radius: var(--radius-sm); font-size: 13px; margin-top: 10px; }

        .panel-qr { text-align: center; }
        .panel-qr .barcode-box { display: inline-block; padding: 10px 14px; background: #fff; border-radius: var(--radius-sm); margin: 8px 0; max-width: 100%; overflow-x: auto; }
        .panel-qr .barcode-box svg { display: block; width: auto; max-width: 100%; }
        .panel-qr .folio { font-size: 12px; color: var(--texto-tenue); word-break: break-all; }
        .panel-qr .codigo-titulo { margin: 14px 0 4px; font-size: 12px; color: var(--texto-suave); text-transform: uppercase; letter-spacing: .06em; }
        .panel-qr h3 { margin: 4px 0 2px; }
        .btn-nueva-receta {
            margin-top: 16px; border: 1px solid var(--linea); background: var(--bg); color: var(--texto-suave);
            border-radius: var(--radius-sm); padding: 9px 16px; cursor: pointer; font-family: inherit; font-size: 13px;
        }
        .btn-nueva-receta:hover { color: var(--texto); border-color: var(--petroleo); }
        .btn-pruebas-recetas {
            margin-top: 10px; width: 100%; border: 1px solid var(--linea); background: var(--bg); color: var(--texto);
            border-radius: var(--radius-sm); padding: 10px 12px; cursor: pointer; font-family: inherit; font-size: 12.5px; font-weight: 700;
        }
        .btn-pruebas-recetas:hover { border-color: var(--ambar); color: var(--ambar); }
        .btn-pruebas-recetas:disabled { opacity: .5; cursor: default; }
        @media (max-width: 900px) { .panel-receta-actual { position: static; } }
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
                <h1>Nueva receta</h1>
                <p>Elige una unidad y toca las posiciones para agregar medicamentos a la receta.</p>
            </div>
        </div>

        <div id="vista-formulario">
            <div class="card">
                <div class="card-encabezado"><h2>1. Máquina y datos de la receta</h2></div>
                <div class="card-cuerpo">
                    <div class="grid-receta" style="grid-template-columns: 1fr 1fr 1fr; align-items: end;">
                        <div class="form-row">
                            <label>Máquina *</label>
                            <select id="r-maquina"><option value="">Cargando…</option></select>
                        </div>
                        <div class="form-row">
                            <label>Paciente (opcional)</label>
                            <input type="text" id="r-paciente" placeholder="Nombre del paciente">
                        </div>
                        <div class="form-row">
                            <label>Médico que solicita (opcional)</label>
                            <input type="text" id="r-medico" placeholder="Nombre del médico">
                        </div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-encabezado"><h2>2. Selecciona medicamentos</h2></div>
                <div class="card-cuerpo">
                    <div class="grid-receta">
                        <div>
                            <div class="buscador-carriles-receta">
                                <input type="text" id="r-buscar" placeholder="Buscar medicamento por nombre o código…" disabled>
                            </div>
                            <div class="selector-modo-receta" id="r-modo-selector">
                                <button type="button" class="activo" data-modo="carril">Por carril</button>
                                <button type="button" data-modo="medicamento">Por medicamento</button>
                            </div>
                            <div class="leyenda-cuadricula">
                                <span><i class="indicador" style="background:var(--petroleo)"></i>Seleccionado</span>
                                <span><i class="indicador" style="background:var(--texto-tenue)"></i>Posición vacía</span>
                                <span>Un toque agrega una unidad</span>
                            </div>
                            <div id="r-carriles"><p class="vacio">Elige una máquina para ver sus carriles.</p></div>
                        </div>
                        <div class="panel-receta-actual">
                            <h3 style="font-size:13.5px;margin:0 0 10px;">Receta actual <span id="r-total" style="color:var(--texto-tenue);font-weight:500;"></span></h3>
                            <div class="carrito" id="r-carrito">
                                <div class="carrito-vacio">Sin medicamentos agregados todavía.</div>
                            </div>
                            <div id="r-mensaje"></div>
                            <button type="button" class="primario" id="r-generar" style="margin-top:16px;width:100%;padding:11px;border-radius:var(--radius-sm);cursor:pointer;font-family:inherit;font-weight:600;" disabled>
                                Generar receta
                            </button>
                            <button type="button" class="btn-pruebas-recetas" id="r-generar-pruebas" disabled>
                                Generar 100 recetas para prueba
                            </button>
                            <button type="button" class="btn-pruebas-recetas" id="r-descargar-folios-blanco">
                                Descargar folios 0000000007 al 0000000016
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div id="vista-qr" style="display:none;">
            <div class="card">
                <div class="card-encabezado"><h2>Receta generada</h2></div>
                <div class="card-cuerpo panel-qr">
                    <div class="codigo-titulo">Codigo de barras</div>
                    <div class="barcode-box"><svg id="barcode-code"></svg></div>
                    <h3 id="qr-maquina"></h3>
                    <p class="muted" id="qr-paciente"></p>
                    <p class="folio mono" id="qr-folio"></p>
                    <div id="qr-items" style="text-align:left;max-width:420px;margin:18px auto 0;"></div>
                    <button type="button" class="btn-nueva-receta" id="btn-imprimir-receta">Abrir receta</button>
                    <button type="button" class="btn-nueva-receta" id="btn-otra-receta">+ Crear otra receta</button>
                </div>
            </div>
        </div>
    </main>
</div>

<script src="assets/js/comun.js?v=20260912-1"></script>
<script>
    let maquinasPlanas = [];       // [{id_maquina, id_unidad, numero_serie, nombre_ubicacion, rol_maquina}]
    let maquinasPorUnidad = {};    // id_maquina principal => [principal, esclavas]
    let carrilesMaquinaActual = []; // carriles de la unidad seleccionada (principal + esclavas)
    let carrito = [];              // modo carril: ubicaciones; modo medicamento: productos agregados
    let filtroBuscar = '';
    let modoAsignacion = 'carril';
    const fmt = n => new Intl.NumberFormat('es-MX').format(Number(n) || 0);

    const selectMaquina = document.getElementById('r-maquina');
    const inputBuscar = document.getElementById('r-buscar');
    const contCarriles = document.getElementById('r-carriles');
    const contCarrito = document.getElementById('r-carrito');
    const btnGenerar = document.getElementById('r-generar');
    const btnGenerarPruebas = document.getElementById('r-generar-pruebas');
    const mensajeDiv = document.getElementById('r-mensaje');
    const modoSelector = document.getElementById('r-modo-selector');

    async function iniciar() {
        try {
            // Traemos unidades completas: principal + esclavas.
            const json = await obtenerJSON('api/maquinas.php?agrupado=1&page_size=1000');
            maquinasPlanas = [];
            maquinasPorUnidad = {};
            json.data.forEach(u => {
                const principal = {
                    id_maquina: parseInt(u.id_maquina, 10),
                    id_unidad: parseInt(u.id_maquina, 10),
                    numero_serie: u.numero_serie,
                    nombre_ubicacion: u.nombre_ubicacion,
                    rol_maquina: 1,
                    numero_carriles: parseInt(u.numero_carriles || 69, 10),
                    tipo: 'Principal'
                };
                const grupo = [principal];
                (u.esclavas || []).forEach(e => {
                    grupo.push({
                        id_maquina: parseInt(e.id_maquina, 10),
                        id_unidad: parseInt(u.id_maquina, 10),
                        numero_serie: e.numero_serie,
                        nombre_ubicacion: e.nombre_ubicacion || u.nombre_ubicacion,
                        rol_maquina: 2,
                        numero_carriles: parseInt(e.numero_carriles || 58, 10),
                        tipo: 'Esclava'
                    });
                });
                maquinasPorUnidad[principal.id_unidad] = grupo;
                maquinasPlanas.push(...grupo);
            });

            selectMaquina.innerHTML = '<option value="">Selecciona una unidad…</option>' +
                Object.values(maquinasPorUnidad).map(grupo => {
                    const m = grupo[0];
                    const extras = grupo.length > 1 ? ` + ${grupo.length - 1} esclava${grupo.length === 2 ? '' : 's'}` : '';
                    return `<option value="${m.id_unidad}">${escapeHtml(m.numero_serie)}${extras}${m.nombre_ubicacion ? ' — ' + escapeHtml(m.nombre_ubicacion) : ''}</option>`;
                }).join('');
        } catch (e) {
            selectMaquina.innerHTML = '<option value="">No se pudo cargar el listado</option>';
        }
    }

    selectMaquina.addEventListener('change', async () => {
        carrito = [];
        renderCarrito();
        actualizarBotonGenerar();
        const idUnidad = parseInt(selectMaquina.value, 10);
        if (!idUnidad) {
            contCarriles.innerHTML = '<p class="vacio">Elige una máquina para ver sus carriles.</p>';
            inputBuscar.disabled = true;
            return;
        }
        inputBuscar.disabled = false;
        inputBuscar.value = '';
        filtroBuscar = '';
        contCarriles.innerHTML = '<p class="cargando">Cargando…</p>';
        try {
            const grupo = maquinasPorUnidad[idUnidad] || [];
            const respuestas = await Promise.all(grupo.map(async m => {
                const json = await obtenerJSON(`api/detalle_maquina.php?id_maquina=${m.id_maquina}`);
                return json.data.map(f => ({
                    ...f,
                    id_maquina: m.id_maquina,
                    numero_serie_maquina: m.numero_serie,
                    tipo_maquina: m.tipo
                }));
            }));
            carrilesMaquinaActual = respuestas.flat();
            renderCarrilesDisponibles();
        } catch (e) {
            contCarriles.innerHTML = '<p class="vacio">No se pudo conectar con el servidor.</p>';
        }
    });

    let debounceBuscar = null;
    inputBuscar.addEventListener('input', () => {
        clearTimeout(debounceBuscar);
        debounceBuscar = setTimeout(() => {
            filtroBuscar = inputBuscar.value.trim().toLowerCase();
            renderCarrilesDisponibles();
        }, 200);
    });

    modoSelector.querySelectorAll('button[data-modo]').forEach(btn => {
        btn.addEventListener('click', () => {
            const nuevoModo = btn.dataset.modo;
            if (nuevoModo === modoAsignacion) return;
            modoAsignacion = nuevoModo;
            carrito = [];
            modoSelector.querySelectorAll('button[data-modo]').forEach(b => b.classList.toggle('activo', b.dataset.modo === modoAsignacion));
            renderCarrito();
            renderCarrilesDisponibles();
            actualizarBotonGenerar();
        });
    });

    function stockProducto(idProducto) {
        return carrilesMaquinaActual
            .filter(f => Number(f.id_producto) === Number(idProducto))
            .reduce((total, f) => total + Number(f.cantidad_actual || 0), 0);
    }

    function seleccionadoProducto(idProducto) {
        return carrito
            .filter(c => Number(c.id_producto) === Number(idProducto))
            .reduce((total, c) => total + Number(c.cantidad || 0), 0);
    }

    function renderCarrilesDisponibles() {
        const idUnidad = parseInt(selectMaquina.value, 10);
        const grupo = maquinasPorUnidad[idUnidad] || [];
        if (!grupo.length) {
            contCarriles.innerHTML = '<p class="vacio">Elige una máquina para ver sus posiciones.</p>';
            return;
        }

        const htmlCabinas = grupo.map(maquina => {
            const ultimoNumeroCarril = Number(maquina.numero_carriles || (maquina.rol_maquina === 1 ? 69 : 58));
            const filasFisicas = Math.ceil(ultimoNumeroCarril / 10);
            const columnasFisicas = 9;
            const carrilesMaquina = carrilesMaquinaActual.filter(f => Number(f.id_maquina) === Number(maquina.id_maquina));
            const porNumero = new Map(carrilesMaquina.map(f => [Number(f.numero_carril), f]));
            const posiciones = [];
            for (let fila = 1; fila <= filasFisicas; fila++) {
                for (let columna = 1; columna <= columnasFisicas; columna++) {
                    const numeroCarril = (fila - 1) * 10 + columna;
                    if (numeroCarril > ultimoNumeroCarril) continue;
                    const carril = porNumero.get(numeroCarril) || null;
                    const stock = Number(carril?.cantidad_actual || 0);
                    const tieneMedicamento = Boolean(carril?.nombre);
                    const cantidad = modoAsignacion === 'medicamento' && tieneMedicamento
                        ? seleccionadoProducto(carril.id_producto)
                        : Number((carrito.find(c => c.id_maquina === maquina.id_maquina && c.numero_carril === numeroCarril) || {}).cantidad || 0);
                    const disponible = modoAsignacion === 'medicamento' && tieneMedicamento
                        ? Math.max(0, stockProducto(carril.id_producto) - cantidad)
                        : Math.max(0, stock - cantidad);
                    const coincide = !filtroBuscar || (tieneMedicamento && (
                        String(carril.nombre || '').toLowerCase().includes(filtroBuscar) ||
                        String(carril.codigo_barras || '').toLowerCase().includes(filtroBuscar)
                    ));
                    const clases = [
                        'posicion-carril',
                        !tieneMedicamento ? 'vacia' : '',
                        tieneMedicamento && disponible <= 0 ? 'sin-stock' : '',
                        cantidad > 0 ? 'seleccionada' : '',
                        !coincide ? 'fuera-filtro' : '',
                    ].filter(Boolean).join(' ');
                    posiciones.push(`<button type="button" class="${clases}"
                        data-maquina="${maquina.id_maquina}" data-carril="${numeroCarril}"
                        ${!tieneMedicamento || disponible <= 0 ? 'disabled' : ''}
                        aria-label="${tieneMedicamento ? 'Agregar ' + escapeHtml(carril.nombre) : 'Posición vacía'}">
                        <span class="posicion-carril__numero">${numeroCarril}</span>
                        <span class="posicion-carril__nombre">${tieneMedicamento ? escapeHtml(carril.nombre) : 'Sin medicamento'}</span>
                        ${tieneMedicamento ? `<span class="posicion-carril__stock">Stock ${disponible}</span>` : ''}
                        ${cantidad > 0 ? `<span class="posicion-carril__cantidad">${cantidad}</span>` : ''}
                    </button>`);
                }
            }
            return `<section class="cabina-receta">
                <div class="cabina-receta__cabecera">
                    <h3>${escapeHtml(maquina.tipo)} <span class="mono">${escapeHtml(maquina.numero_serie)}</span></h3>
                    <span>${filasFisicas} filas x ${columnasFisicas} columnas - ultimo ${ultimoNumeroCarril}</span>
                </div>
                <div class="cabina-receta__scroll"><div class="cuadricula-carriles">${posiciones.join('')}</div></div>

            </section>`;
        }).join('');

        contCarriles.innerHTML = `<div class="cabinas-receta">${htmlCabinas}</div>`;
        contCarriles.querySelectorAll('.posicion-carril:not(:disabled)').forEach(btn => {
            btn.addEventListener('click', () => agregarAlCarrito(Number(btn.dataset.maquina), Number(btn.dataset.carril)));
        });
    }

    function agregarAlCarrito(idMaquina, numeroCarril) {
        const fila = carrilesMaquinaActual.find(f => f.id_maquina == idMaquina && f.numero_carril == numeroCarril);
        if (!fila || !fila.nombre || Number(fila.cantidad_actual) <= 0) return;

        const existente = modoAsignacion === 'medicamento'
            ? carrito.find(c => Number(c.id_producto) === Number(fila.id_producto))
            : carrito.find(c => c.id_maquina == idMaquina && c.numero_carril == numeroCarril);
        const stockMaximo = modoAsignacion === 'medicamento'
            ? stockProducto(fila.id_producto)
            : Number(fila.cantidad_actual);
        if (existente) {
            if (existente.cantidad >= stockMaximo) return;
            existente.cantidad += 1;
        } else {
            carrito.push({
                modo: modoAsignacion,
                id_maquina: modoAsignacion === 'medicamento' ? null : idMaquina,
                numero_carril: modoAsignacion === 'medicamento' ? null : numeroCarril,
                id_producto: fila.id_producto,
                numero_serie_maquina: modoAsignacion === 'medicamento' ? 'Asignacion automatica' : fila.numero_serie_maquina,
                tipo_maquina: modoAsignacion === 'medicamento' ? 'Por medicamento' : fila.tipo_maquina,
                nombre: fila.nombre,
                codigo_barras: fila.codigo_barras,
                cantidad: 1,
            });
        }
        renderCarrito();
        renderCarrilesDisponibles();
        actualizarBotonGenerar();
    }

    function quitarDelCarrito(idMaquina, numeroCarril, idProducto) {
        if (modoAsignacion === 'medicamento') {
            carrito = carrito.filter(c => Number(c.id_producto) !== Number(idProducto));
        } else {
            carrito = carrito.filter(c => !(c.id_maquina === idMaquina && c.numero_carril === numeroCarril));
        }
        renderCarrito();
        renderCarrilesDisponibles();
        actualizarBotonGenerar();
    }

    function ajustarCantidad(idMaquina, numeroCarril, idProducto, cambio) {
        const item = modoAsignacion === 'medicamento'
            ? carrito.find(c => Number(c.id_producto) === Number(idProducto))
            : carrito.find(c => c.id_maquina === idMaquina && c.numero_carril === numeroCarril);
        const fila = modoAsignacion === 'medicamento'
            ? { cantidad_actual: stockProducto(idProducto) }
            : carrilesMaquinaActual.find(f => Number(f.id_maquina) === idMaquina && Number(f.numero_carril) === numeroCarril);
        if (!item || !fila) return;
        const nuevaCantidad = item.cantidad + cambio;
        if (nuevaCantidad <= 0) {
            quitarDelCarrito(idMaquina, numeroCarril, idProducto);
            return;
        }
        if (nuevaCantidad > Number(fila.cantidad_actual)) return;
        item.cantidad = nuevaCantidad;
        renderCarrito();
        renderCarrilesDisponibles();
        actualizarBotonGenerar();
    }

    function renderCarrito() {
        if (carrito.length === 0) {
            contCarrito.innerHTML = '<div class="carrito-vacio">Sin medicamentos agregados todavía.</div>';
            document.getElementById('r-total').textContent = '';
            return;
        }
        const totalUnidades = carrito.reduce((total, item) => total + Number(item.cantidad || 0), 0);
        document.getElementById('r-total').textContent = `· ${totalUnidades} unidad${totalUnidades === 1 ? '' : 'es'}`;
        contCarrito.innerHTML = carrito.map(c => `
            <div class="carrito-item">
                <div class="info">
                    <div class="nombre">${escapeHtml(c.nombre)}</div>
                    <div class="detalle">${escapeHtml(c.numero_serie_maquina)} / ${escapeHtml(c.tipo_maquina)} · Carril ${c.numero_carril}${c.codigo_barras ? ' · ' + escapeHtml(c.codigo_barras) : ''}</div>
                </div>
                <div class="controles">
                    <button type="button" class="ajustar" data-cambio="-1" data-maquina="${c.id_maquina}" data-carril="${c.numero_carril}" title="Restar una unidad" aria-label="Restar una unidad">−</button>
                    <div class="cantidad">${c.cantidad}</div>
                    <button type="button" class="ajustar" data-cambio="1" data-maquina="${c.id_maquina}" data-carril="${c.numero_carril}" title="Agregar una unidad" aria-label="Agregar una unidad">+</button>
                    <button type="button" class="quitar" data-maquina="${c.id_maquina}" data-carril="${c.numero_carril}" title="Quitar medicamento" aria-label="Quitar medicamento">&times;</button>
                </div>
            </div>
        `).join('');

        contCarrito.querySelectorAll('.ajustar').forEach(btn => {
            btn.addEventListener('click', () => ajustarCantidad(Number(btn.dataset.maquina), Number(btn.dataset.carril), Number(btn.dataset.cambio)));
        });
        contCarrito.querySelectorAll('.quitar').forEach(btn => {
            btn.addEventListener('click', () => quitarDelCarrito(parseInt(btn.dataset.maquina, 10), parseInt(btn.dataset.carril, 10)));
        });
    }

    renderCarrito = function () {
        if (carrito.length === 0) {
            contCarrito.innerHTML = '<div class="carrito-vacio">Sin medicamentos agregados todavia.</div>';
            document.getElementById('r-total').textContent = '';
            return;
        }
        const totalUnidades = carrito.reduce((total, item) => total + Number(item.cantidad || 0), 0);
        document.getElementById('r-total').textContent = `- ${totalUnidades} unidad${totalUnidades === 1 ? '' : 'es'}`;
        contCarrito.innerHTML = carrito.map(c => {
            const detalle = modoAsignacion === 'medicamento'
                ? `Stock total ${stockProducto(c.id_producto)} - asigna carriles al generar`
                : `${escapeHtml(c.numero_serie_maquina)} / ${escapeHtml(c.tipo_maquina)} - Carril ${c.numero_carril}`;
            return `
                <div class="carrito-item">
                    <div class="info">
                        <div class="nombre">${escapeHtml(c.nombre)}</div>
                        <div class="detalle">${detalle}${c.codigo_barras ? ' - ' + escapeHtml(c.codigo_barras) : ''}</div>
                    </div>
                    <div class="controles">
                        <button type="button" class="ajustar" data-cambio="-1" data-maquina="${c.id_maquina || ''}" data-carril="${c.numero_carril || ''}" data-producto="${c.id_producto}" title="Restar una unidad" aria-label="Restar una unidad">-</button>
                        <div class="cantidad">${c.cantidad}</div>
                        <button type="button" class="ajustar" data-cambio="1" data-maquina="${c.id_maquina || ''}" data-carril="${c.numero_carril || ''}" data-producto="${c.id_producto}" title="Agregar una unidad" aria-label="Agregar una unidad">+</button>
                        <button type="button" class="quitar" data-maquina="${c.id_maquina || ''}" data-carril="${c.numero_carril || ''}" data-producto="${c.id_producto}" title="Quitar medicamento" aria-label="Quitar medicamento">&times;</button>
                    </div>
                </div>
            `;
        }).join('');

        contCarrito.querySelectorAll('.ajustar').forEach(btn => {
            btn.addEventListener('click', () => ajustarCantidad(Number(btn.dataset.maquina), Number(btn.dataset.carril), Number(btn.dataset.producto), Number(btn.dataset.cambio)));
        });
        contCarrito.querySelectorAll('.quitar').forEach(btn => {
            btn.addEventListener('click', () => quitarDelCarrito(parseInt(btn.dataset.maquina, 10), parseInt(btn.dataset.carril, 10), parseInt(btn.dataset.producto, 10)));
        });
    };

    function actualizarBotonGenerar() {
        btnGenerar.disabled = carrito.length === 0 || !selectMaquina.value;
        btnGenerarPruebas.disabled = !selectMaquina.value;
    }

    btnGenerar.addEventListener('click', async () => {
        mensajeDiv.innerHTML = '';
        btnGenerar.disabled = true;
        btnGenerar.textContent = 'Generando…';

        // Se abre durante el clic para que el navegador no bloquee la receta
        // formal cuando termine la petición asíncrona.
        const ventanaFormal = abrirVentanaEnEspera('Generando receta…');

        const payload = {
            id_maquina: parseInt(selectMaquina.value, 10),
            modo_asignacion: modoAsignacion,
            nombre_paciente: document.getElementById('r-paciente').value.trim(),
            medico_solicita: document.getElementById('r-medico').value.trim(),
            items: carrito.map(c => modoAsignacion === 'medicamento'
                ? ({
                    id_producto: c.id_producto,
                    cantidad: c.cantidad
                })
                : ({
                    id_maquina: c.id_maquina,
                    id_producto: c.id_producto,
                    numero_carril: c.numero_carril,
                    cantidad: c.cantidad
                })
            ),
        };

        try {
            const res = await fetch('api/recetas.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            });
            const json = await res.json();
            if (!json.ok) {
                ventanaFormal?.close();
                mensajeDiv.innerHTML = `<p class="msg-error">Error: ${escapeHtml(json.error)}</p>`;
                btnGenerar.disabled = false;
                btnGenerar.textContent = 'Generar receta';
                return;
            }
            mostrarCodigoBarras(json.data.folio, payload, ventanaFormal);
        } catch (e) {
            ventanaFormal?.close();
            mensajeDiv.innerHTML = '<p class="msg-error">No se pudo conectar con el servidor.</p>';
            btnGenerar.disabled = false;
            btnGenerar.textContent = 'Generar receta';
        }
    });

    btnGenerarPruebas.addEventListener('click', async () => {
        const idUnidad = parseInt(selectMaquina.value, 10);
        if (!idUnidad) return;

        const ok = window.confirm('Se crearan hasta 100 recetas pendientes de prueba para esta unidad, con recetas variables de 5 a 10 medicamentos segun stock disponible. Continuar?');
        if (!ok) return;

        const ventanaFormal = abrirVentanaEnEspera('Generando recetas para prueba…');

        mensajeDiv.innerHTML = '';
        btnGenerarPruebas.disabled = true;
        btnGenerarPruebas.textContent = 'Generando lote...';

        try {
            const res = await fetch('api/recetas_prueba.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id_maquina: idUnidad, total_recetas: 100, min_items_por_receta: 5, max_items_por_receta: 10 }),
            });
            const json = await res.json();
            if (!json.ok) {
                ventanaFormal?.close();
                const detalle = json.data
                    ? ` Principal: ${json.data.principal_disponibles || 0}, esclavas: ${json.data.esclavas_disponibles || 0}.`
                    : '';
                mensajeDiv.innerHTML = `<p class="msg-error">Error: ${escapeHtml(json.error || 'No se pudo generar el lote.')}${detalle}</p>`;
                return;
            }

            const data = json.data;
            const rangoItems = data.items_por_receta
                ? `${data.items_por_receta.min}-${data.items_por_receta.max} medicamentos por receta (promedio ${data.items_por_receta.promedio})`
                : '5-10 medicamentos por receta';
            const resumenProductos = Array.isArray(data.productos_generados) && data.productos_generados.length
                ? `<br><strong>Medicamentos generados:</strong> ${data.productos_generados.slice(0, 10).map(p => `${escapeHtml(p.producto || 'Sin nombre')}: ${fmt(p.cantidad)}`).join(', ')}${data.productos_generados.length > 10 ? '...' : ''}`
                : '';
            mensajeDiv.innerHTML = `<p class="msg-ok">Lote generado: ${data.total_recetas} recetas, ${rangoItems}, ${data.total_medicamentos || 0} medicamentos totales y ${data.carriles_distintos_usados} carriles distintos usados.${resumenProductos}<br>${ventanaFormal ? 'Se abrió la vista formal para guardar o imprimir el PDF.' : `<a href="${escapeHtml(data.print_url)}" target="_blank" rel="noopener">Abrir recetas</a>`}</p>`;
            navegarVentanaFormal(ventanaFormal, data.print_url);
        } catch (e) {
            ventanaFormal?.close();
            mensajeDiv.innerHTML = '<p class="msg-error">No se pudo conectar con el servidor.</p>';
        } finally {
            btnGenerarPruebas.textContent = 'Generar 100 recetas para prueba';
            actualizarBotonGenerar();
        }
    });

    document.getElementById('r-descargar-folios-blanco')?.addEventListener('click', () => {
        window.open('recetas_folios_blanco.php?desde=7&hasta=16&auto_print=1', '_blank', 'noopener');
    });

   function abrirVentanaEnEspera(mensaje) {
    const ventana = window.open('', '_blank');
    if (!ventana) return null;
    ventana.opener = null;
    ventana.document.title = 'Receta';
    ventana.document.body.innerHTML = `<p style="font-family:Arial,sans-serif;padding:32px;color:#39464d">${escapeHtml(mensaje)}</p>`;
    return ventana;
}

   function navegarVentanaFormal(ventana, url) {
    if (ventana && !ventana.closed) ventana.location.href = url;
}

   function codigoBarrasFuncional(folio) {
    const limpio = String(folio || '').toUpperCase().replace(/[^0-9A-Z]/g, '');
    if (/^[0-9]{10}$/.test(limpio)) return limpio;
    return limpio.slice(-12);
}

   function mostrarCodigoBarras(folio, payload, ventanaFormal = null) {
    document.getElementById('vista-formulario').style.display = 'none';
    document.getElementById('vista-qr').style.display = '';

    const grupo = maquinasPorUnidad[payload.id_maquina] || [];
    const maquina = grupo[0] || maquinasPlanas.find(m => m.id_maquina == payload.id_maquina);
    const extras = grupo.length > 1 ? ` + ${grupo.length - 1} esclava${grupo.length === 2 ? '' : 's'}` : '';
    document.getElementById('qr-maquina').textContent = maquina ? `${maquina.numero_serie}${extras}${maquina.nombre_ubicacion ? ' — ' + maquina.nombre_ubicacion : ''}` : '';
    document.getElementById('qr-paciente').textContent = payload.nombre_paciente ? `Paciente: ${payload.nombre_paciente}` : '';
    document.getElementById('qr-folio').textContent = `Folio: ${folio}`;
    const codigoCorto = codigoBarrasFuncional(folio);
    JsBarcode('#barcode-code', codigoCorto, {
        format: 'CODE128',
        width: 1.6,
        height: 48,
        displayValue: true,
        font: 'monospace',
        fontSize: 12,
        textMargin: 4,
        margin: 6,
        lineColor: '#10161C',
        background: '#ffffff'
    });

    document.getElementById('qr-items').innerHTML = `<table>
        <thead><tr><th>Máquina</th><th>Carril</th><th>Medicamento</th><th>Cantidad</th></tr></thead>
        <tbody>${carrito.map(c => `<tr><td>${escapeHtml(c.numero_serie_maquina)}</td><td>${c.numero_carril || 'Auto'}</td><td>${escapeHtml(c.nombre)}</td><td>${c.cantidad}</td></tr>`).join('')}</tbody>
    </table>`;

    document.getElementById('btn-imprimir-receta').onclick = () => {
        window.open(`recetas_formales.php?folios=${encodeURIComponent(folio)}&auto_print=1`, '_blank', 'noopener');
    };

    const urlFormal = `recetas_formales.php?folios=${encodeURIComponent(folio)}&auto_print=1`;
    navegarVentanaFormal(ventanaFormal, urlFormal);
    if (!ventanaFormal) {
        mensajeDiv.innerHTML = `<p class="msg-ok"><a href="${urlFormal}" target="_blank" rel="noopener">Abrir receta para imprimir</a></p>`;
    }

}

    document.getElementById('btn-otra-receta').addEventListener('click', () => {
        document.getElementById('vista-qr').style.display = 'none';
        document.getElementById('vista-formulario').style.display = '';
        carrito = [];
        carrilesMaquinaActual = [];
        filtroBuscar = '';
        renderCarrito();
        document.getElementById('r-paciente').value = '';
        document.getElementById('r-medico').value = '';
        selectMaquina.value = '';
        contCarriles.innerHTML = '<p class="vacio">Elige una máquina para ver sus carriles.</p>';
        inputBuscar.disabled = true;
        mensajeDiv.innerHTML = '';
        btnGenerar.textContent = 'Generar receta';
        actualizarBotonGenerar();
    });

    iniciar();
</script>
</body>
</html>

