<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/bootstrap.php';

use App\Auth\Auth;
use App\Config\Features;

Auth::requireLogin();

$esAdmin = Auth::isAdmin();
$carritoHabilitado = Features::enabled('carrito_compras');

$modulos = [
    [
        'nombre' => 'Dashboard',
        'icono' => 'layout-dashboard',
        'grupo' => 'operacion',
        'href' => 'dashboard.php',
        'proposito' => 'Concentrar el estado general de la red y señalar qué necesita atención primero.',
        'muestra' => 'Alertas operativas, productos con movimiento, máquinas en riesgo y acciones sugeridas.',
        'pasos' => ['Revisa las alertas prioritarias.', 'Abre la máquina que requiere atención.', 'Actualiza después de atender la incidencia.'],
    ],
    [
        'nombre' => 'Máquinas',
        'icono' => 'server',
        'grupo' => 'operacion',
        'href' => 'admin.php',
        'proposito' => 'Consultar y administrar la red de máquinas maestras y esclavas.',
        'muestra' => 'Serie, ubicación operativa, conexión, estado, tipo de unidad y alertas.',
        'pasos' => ['Busca por serie o estado.', 'Selecciona una unidad para abrir su detalle.', 'Como administrador, registra máquinas nuevas o carga su stock.'],
    ],
    [
        'nombre' => 'Detalle de máquina',
        'icono' => 'monitor-cog',
        'grupo' => 'operacion',
        'href' => 'admin.php',
        'acceso' => 'Se abre desde Máquinas',
        'proposito' => 'Revisar una unidad específica y todo lo relacionado con su operación.',
        'muestra' => 'Telemetría, carriles, stock actual, unidad vinculada e historial de mantenimiento.',
        'pasos' => ['Entra desde el listado de Máquinas.', 'Revisa carriles y existencias.', 'Carga stock o registra mantenimiento cuando corresponda.'],
    ],
    [
        'nombre' => 'Stock por máquina',
        'icono' => 'package-search',
        'grupo' => 'abasto',
        'href' => 'stock_maquinas.php',
        'proposito' => 'Consultar las existencias de medicamentos instaladas en cada carril.',
        'muestra' => 'Producto, lote, caducidad, capacidad, cantidad disponible y porcentaje de ocupación.',
        'pasos' => ['Selecciona o busca una máquina.', 'Detecta carriles bajos, vacíos o próximos a caducar.', 'Usa Planeación para preparar el reabastecimiento.'],
    ],
    [
        'nombre' => 'Planeación',
        'icono' => 'clipboard-list',
        'grupo' => 'abasto',
        'href' => 'planeacion.php',
        'proposito' => 'Calcular qué productos conviene surtir y en qué cantidad.',
        'muestra' => 'Demanda, cobertura, prioridad, stock actual y reabastecimiento sugerido.',
        'pasos' => ['Genera o abre el plan vigente.', 'Filtra recomendaciones críticas y preventivas.', 'Prepara el surtido antes de programar la distribución.'],
    ],
    [
        'nombre' => 'Abastecimiento',
        'icono' => 'package-open',
        'grupo' => 'abasto',
        'href' => 'abastecimiento.php',
        'proposito' => 'Organizar el reparto de medicamentos por regiones, rutas, choferes y jornadas.',
        'muestra' => 'Top de maquinas con desabasto, porcentaje de llenado, piezas faltantes, asignaciones y avance de paradas.',
        'pasos' => ['Da de alta regiones y choferes.', 'Crea rutas y asigna sus maquinas.', 'Programa una jornada y atiende primero las paradas criticas.'],
    ],
    [
        'nombre' => 'Distribución',
        'icono' => 'route',
        'grupo' => 'abasto',
        'href' => 'distribucion.php',
        'proposito' => 'Programar viajes para entregar máquinas y dar seguimiento a sus destinos.',
        'muestra' => 'Viajes activos, mapa, ruta recomendada, kilómetros, tiempo, casetas y combustible estimado.',
        'pasos' => ['Crea un viaje y captura una dirección completa.', 'Calcula la ruta y revisa los datos aproximados.', 'Cambia el estado a En ruta y después a Completado.'],
    ],
    [
        'nombre' => 'Reportes',
        'icono' => 'file-chart-column',
        'grupo' => 'control',
        'href' => 'reportes.php',
        'proposito' => 'Extraer información operativa para revisión, conciliación o análisis externo.',
        'muestra' => 'Reportes de operación, máquinas, medicamentos, stock y caducidades.',
        'pasos' => ['Selecciona el tipo de reporte.', 'Define filtros y rango de fechas.', 'Revisa la vista previa y descarga el archivo CSV.'],
    ],
    [
        'nombre' => 'Mantenimiento',
        'icono' => 'wrench',
        'grupo' => 'control',
        'href' => 'mantenimiento.php',
        'proposito' => 'Organizar revisiones preventivas y correctivas de las máquinas.',
        'muestra' => 'Unidades vencidas, próximas a revisión, detenidas y trabajos registrados.',
        'pasos' => ['Atiende primero mantenimientos vencidos.', 'Registra técnico, tipo y observaciones.', 'Marca la fecha realizada para cerrar el trabajo.'],
    ],
    [
        'nombre' => 'Inventario de piezas',
        'icono' => 'boxes',
        'grupo' => 'control',
        'href' => 'inventario.php',
        'proposito' => 'Controlar las refacciones utilizadas para mantenimiento.',
        'muestra' => 'Existencias, mínimos, ubicación en almacén y movimientos de entrada o salida.',
        'pasos' => ['Consulta piezas bajo mínimo.', 'Registra entradas cuando recibas material.', 'Registra salidas indicando la máquina o mantenimiento relacionado.'],
    ],
    [
        'nombre' => 'Carrito',
        'icono' => 'shopping-cart',
        'grupo' => 'dispensacion',
        'href' => 'carrito.php',
        'feature' => $carritoHabilitado,
        'proposito' => 'Seleccionar medicamentos disponibles y convertir la selección en una receta.',
        'muestra' => 'Catálogo disponible, cantidades seleccionadas, máquina y resumen de compra.',
        'pasos' => ['Selecciona la máquina.', 'Agrega productos y cantidades.', 'Genera la receta para continuar con la dispensación.'],
    ],
    [
        'nombre' => 'Nueva receta',
        'icono' => 'clipboard-plus',
        'grupo' => 'dispensacion',
        'href' => 'receta_nueva.php',
        'proposito' => 'Preparar una orden de medicamentos y generar el código para retirarlos.',
        'muestra' => 'Máquina, datos de receta, medicamentos disponibles, cantidades y código de barras final.',
        'pasos' => ['Elige una máquina con stock.', 'Captura los datos y medicamentos.', 'Genera el código de barras que consultará el equipo Android.'],
    ],
    [
        'nombre' => 'Productos',
        'icono' => 'pill',
        'grupo' => 'administracion',
        'href' => 'productos.php',
        'admin' => true,
        'proposito' => 'Mantener el catálogo maestro de medicamentos y productos.',
        'muestra' => 'Código, nombre, descripción, imagen y estado del producto.',
        'pasos' => ['Busca un producto existente antes de crear otro.', 'Registra o corrige sus datos.', 'Desactívalo cuando ya no deba utilizarse.'],
    ],
    [
        'nombre' => 'Caducos',
        'icono' => 'calendar-clock',
        'grupo' => 'administracion',
        'href' => 'vencimientos.php',
        'admin' => true,
        'proposito' => 'Detectar lotes vencidos o próximos a vencer dentro de las máquinas.',
        'muestra' => 'Lote, producto, máquina, carril, fecha de caducidad, cantidad disponible y estado.',
        'pasos' => ['Filtra por días de anticipación.', 'Revisa primero los vencidos.', 'Retira los lotes caducos cuando ya no deben dispensarse.'],
    ],
    [
        'nombre' => 'Estadísticas',
        'icono' => 'chart-no-axes-column',
        'grupo' => 'administracion',
        'href' => 'estadisticas.php',
        'admin' => true,
        'proposito' => 'Analizar ventas, salidas y mermas por máquina o producto.',
        'muestra' => 'Resumen de movimientos, ranking de productos, kardex y comportamiento operativo.',
        'pasos' => ['Define el rango de fechas.', 'Filtra por máquina o producto cuando sea necesario.', 'Usa el kardex para revisar movimientos puntuales.'],
    ],
    [
        'nombre' => 'Resurtir',
        'icono' => 'package-plus',
        'grupo' => 'abasto',
        'href' => 'resurtir.php',
        'admin' => true,
        'proposito' => 'Registrar reabastecimientos y ajustes de carriles en Máquinas dispensadoras.',
        'muestra' => 'Máquinas, carriles, productos, lotes, cantidades y resultado del movimiento.',
        'pasos' => ['Selecciona la máquina o sucursal origen.', 'Elige carril, producto, lote y cantidad.', 'Confirma el movimiento y revisa cualquier advertencia de inventario.'],
    ],
    [
        'nombre' => 'Pedidos',
        'icono' => 'package-check',
        'grupo' => 'abasto',
        'href' => 'pedidos.php',
        'admin' => true,
        'proposito' => 'Gestionar solicitudes de reabastecimiento generadas por bajo stock.',
        'muestra' => 'Solicitudes pendientes, enviadas, entregadas o canceladas, con producto y carril relacionado.',
        'pasos' => ['Filtra por estatus.', 'Selecciona solicitudes pendientes.', 'Envía a CEDIS o marca el seguimiento correspondiente.'],
    ],
    [
        'nombre' => 'Usuarios',
        'icono' => 'users',
        'grupo' => 'administracion',
        'href' => 'usuarios.php',
        'admin' => true,
        'proposito' => 'Controlar quién puede entrar al sistema y qué máquinas puede consultar.',
        'muestra' => 'Usuarios, roles, estado de acceso y alcance de máquinas asignadas.',
        'pasos' => ['Crea o edita al usuario.', 'Asigna el rol correcto.', 'Limita las máquinas visibles cuando sea necesario.'],
    ],
];

$modulos = array_values(array_filter($modulos, static function (array $modulo) use ($esAdmin): bool {
    if (($modulo['admin'] ?? false) && !$esAdmin) {
        return false;
    }
    return $modulo['feature'] ?? true;
}));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MDM - Ayuda</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/estilo.css?v=20260914-2">
    <style>
        body { background: #F3F6F7; }
        .sidebar { position: sticky; top: 0; height: 100vh; overflow-y: auto; }
        .marca { justify-content: center; padding-bottom: 18px; border-bottom: 1px solid var(--line); }
        .principal { width: 100%; max-width: 1440px; }
        .encabezado-ayuda {
            display: flex; align-items: flex-end; justify-content: space-between;
            gap: 24px; margin-bottom: 22px; padding-bottom: 18px; border-bottom: 1px solid var(--line);
        }
        .encabezado-ayuda h1 { margin: 0 0 5px; font-size: 24px; letter-spacing: 0; }
        .encabezado-ayuda p { margin: 0; color: var(--ink-soft); max-width: 720px; line-height: 1.55; }
        .rol-actual { color: var(--teal-dark); font-size: 12px; font-weight: 700; white-space: nowrap; }
        .flujo-rapido {
            display: grid; grid-template-columns: 190px repeat(4, minmax(0, 1fr));
            border-top: 1px solid var(--line); border-bottom: 1px solid var(--line);
            background: #FFFFFF; margin-bottom: 22px;
        }
        .flujo-titulo, .flujo-paso { padding: 17px 18px; }
        .flujo-titulo { display: flex; align-items: center; gap: 9px; font-weight: 700; color: var(--ink); }
        .flujo-titulo svg { color: var(--teal); }
        .flujo-paso { border-left: 1px solid var(--line-soft); }
        .flujo-paso b { display: block; color: var(--teal-dark); font-size: 11px; text-transform: uppercase; margin-bottom: 4px; }
        .flujo-paso span { color: var(--ink-soft); font-size: 12.5px; line-height: 1.4; }
        .herramientas-ayuda { display: grid; grid-template-columns: minmax(260px, 1fr) auto; gap: 12px; align-items: start; margin-bottom: 20px; }
        .busqueda-ayuda { position: relative; min-width: 0; }
        .busqueda-ayuda svg { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); width: 17px; color: var(--ink-faint); }
        .busqueda-ayuda input { width: 100%; min-height: 42px; padding-left: 40px; background: #FFFFFF; }
        .filtros-ayuda { display: flex; flex-wrap: wrap; gap: 7px; justify-content: flex-end; max-width: 620px; }
        .filtro-ayuda { min-height: 38px; border: 1px solid var(--line); border-radius: 7px; background: #FFFFFF; padding: 0 13px; color: var(--ink-soft); cursor: pointer; font: 600 12px Inter, sans-serif; white-space: nowrap; }
        .filtro-ayuda.activo { color: #FFFFFF; background: var(--teal); }
        .modulos-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; }
        .modulo {
            display: flex; flex-direction: column; min-width: 0; background: #FFFFFF;
            border: 1px solid var(--line); border-radius: 7px; overflow: hidden;
            box-shadow: 0 6px 20px -18px rgba(16, 22, 28, .35);
        }
        .modulo-cabecera { display: flex; align-items: center; gap: 11px; padding: 16px 17px 13px; border-bottom: 1px solid var(--line-soft); }
        .modulo-icono { width: 34px; height: 34px; display: grid; place-items: center; background: var(--teal-wash); color: var(--teal-dark); border-radius: 6px; flex: 0 0 34px; }
        .modulo-icono svg { width: 18px; height: 18px; }
        .modulo-cabecera h2 { margin: 0; font-size: 15px; letter-spacing: 0; }
        .modulo-grupo { margin-top: 2px; color: var(--ink-faint); font-size: 10.5px; text-transform: uppercase; font-weight: 700; }
        .modulo-cuerpo { display: flex; flex-direction: column; flex: 1; padding: 15px 17px 17px; }
        .modulo-cuerpo p { margin: 0 0 12px; color: var(--ink-soft); font-size: 12.5px; line-height: 1.5; }
        .modulo-cuerpo p strong { color: var(--ink); }
        .modulo-pasos { margin: 0 0 16px; padding: 0; list-style: none; counter-reset: paso; }
        .modulo-pasos li { position: relative; padding: 5px 0 5px 24px; color: var(--ink-soft); font-size: 12px; line-height: 1.4; counter-increment: paso; }
        .modulo-pasos li::before { content: counter(paso); position: absolute; left: 0; top: 5px; width: 17px; height: 17px; display: grid; place-items: center; border: 1px solid var(--line); border-radius: 50%; color: var(--teal-dark); font-size: 9px; font-weight: 700; }
        .modulo-pie { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-top: auto; padding-top: 13px; border-top: 1px solid var(--line-soft); }
        .modulo-acceso { color: var(--ink-faint); font-size: 10.5px; }
        .abrir-modulo { display: inline-flex; align-items: center; gap: 6px; color: var(--teal-dark); text-decoration: none; font-size: 12px; font-weight: 700; }
        .abrir-modulo svg { width: 15px; height: 15px; }
        .sin-resultados { display: none; padding: 48px 16px; text-align: center; color: var(--ink-soft); }
        .sin-resultados.visible { display: block; }
        @media (max-width: 1120px) {
            .modulos-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .flujo-rapido { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .flujo-titulo { grid-column: 1 / -1; border-bottom: 1px solid var(--line-soft); }
            .flujo-paso:nth-child(2n) { border-left: 0; }
        }
        @media (max-width: 720px) {
            .sidebar { height: auto; overflow-x: auto; overflow-y: hidden; }
            .marca { flex: 0 0 auto; padding: 0 14px 0 0; border: 0; border-right: 1px solid var(--line); }
            .encabezado-ayuda { align-items: flex-start; flex-direction: column; }
            .modulos-grid, .flujo-rapido { grid-template-columns: 1fr; }
            .flujo-paso, .flujo-paso:nth-child(2n) { border-left: 0; border-top: 1px solid var(--line-soft); }
            .herramientas-ayuda { grid-template-columns: 1fr; }
            .filtros-ayuda { width: 100%; justify-content: flex-start; overflow-x: auto; flex-wrap: nowrap; padding-bottom: 4px; }
            .filtro-ayuda { flex: 1 0 auto; }
        }
    </style>
</head>
<body class="tema-oscuro">
<div class="app">
    <aside class="sidebar">
        <div class="marca"><img class="marca-logo" src="assets/img/logo.png" alt="MDM"></div>
        <?php require __DIR__ . '/partials/menu.php'; ?>
    </aside>

    <main class="principal">
        <header class="encabezado-ayuda">
            <div>
                <h1>Guía del sistema</h1>
                <p>Consulta para qué sirve cada vista, qué información contiene y el orden recomendado para realizar las tareas habituales.</p>
            </div>
            <div class="rol-actual">Contenido para: <?= $esAdmin ? 'Administrador' : 'Usuario operativo' ?></div>
        </header>

        <section class="flujo-rapido" aria-label="Flujo operativo recomendado">
            <div class="flujo-titulo"><i data-lucide="workflow"></i>Flujo recomendado</div>
            <div class="flujo-paso"><b>1. Detectar</b><span>Revisa Dashboard, Máquinas, Stock y Caducos para localizar riesgos.</span></div>
            <div class="flujo-paso"><b>2. Preparar</b><span>Genera Planeación, revisa Pedidos y define qué se debe surtir.</span></div>
            <div class="flujo-paso"><b>3. Ejecutar</b><span>Usa Resurtir, programa Abastecimiento o registra Mantenimiento.</span></div>
            <div class="flujo-paso"><b>4. Comprobar</b><span>Valida stock, recetas y salidas con Reportes y Estadísticas.</span></div>
        </section>

        <div class="herramientas-ayuda">
            <label class="busqueda-ayuda">
                <i data-lucide="search"></i>
                <input id="buscar-ayuda" type="search" placeholder="Buscar una vista o tarea" autocomplete="off">
            </label>
            <div class="filtros-ayuda" role="group" aria-label="Filtrar módulos">
                <button class="filtro-ayuda activo" type="button" data-grupo="todos">Todos</button>
                <button class="filtro-ayuda" type="button" data-grupo="operacion">Operación</button>
                <button class="filtro-ayuda" type="button" data-grupo="abasto">Abasto</button>
                <button class="filtro-ayuda" type="button" data-grupo="control">Control</button>
                <button class="filtro-ayuda" type="button" data-grupo="dispensacion">Dispensación</button>
                <?php if ($esAdmin): ?><button class="filtro-ayuda" type="button" data-grupo="administracion">Administración</button><?php endif; ?>
            </div>
        </div>

        <section class="modulos-grid" id="modulos-grid">
            <?php foreach ($modulos as $modulo): ?>
                <article class="modulo" data-grupo="<?= htmlspecialchars($modulo['grupo']) ?>" data-busqueda="<?= htmlspecialchars(strtolower($modulo['nombre'] . ' ' . $modulo['proposito'] . ' ' . $modulo['muestra'])) ?>">
                    <div class="modulo-cabecera">
                        <span class="modulo-icono"><i data-lucide="<?= htmlspecialchars($modulo['icono']) ?>"></i></span>
                        <div>
                            <h2><?= htmlspecialchars($modulo['nombre']) ?></h2>
                            <div class="modulo-grupo"><?= htmlspecialchars($modulo['grupo']) ?></div>
                        </div>
                    </div>
                    <div class="modulo-cuerpo">
                        <p><strong>Para qué sirve:</strong> <?= htmlspecialchars($modulo['proposito']) ?></p>
                        <p><strong>Qué encontrarás:</strong> <?= htmlspecialchars($modulo['muestra']) ?></p>
                        <ol class="modulo-pasos">
                            <?php foreach ($modulo['pasos'] as $paso): ?><li><?= htmlspecialchars($paso) ?></li><?php endforeach; ?>
                        </ol>
                        <div class="modulo-pie">
                            <span class="modulo-acceso"><?= htmlspecialchars($modulo['acceso'] ?? (($modulo['admin'] ?? false) ? 'Solo administrador' : 'Disponible en el menú')) ?></span>
                            <a class="abrir-modulo" href="<?= htmlspecialchars($modulo['href']) ?>">Abrir <i data-lucide="arrow-right"></i></a>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </section>
        <div class="sin-resultados" id="sin-resultados">No se encontraron vistas relacionadas con esa búsqueda.</div>
    </main>
</div>

<script>
    if (window.mlRenderIcons) window.mlRenderIcons();
    const buscador = document.getElementById('buscar-ayuda');
    const filtros = [...document.querySelectorAll('.filtro-ayuda')];
    const modulos = [...document.querySelectorAll('.modulo')];
    let grupoActivo = 'todos';

    function normalizar(texto) {
        return String(texto || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();
    }

    function filtrarModulos() {
        const termino = normalizar(buscador.value);
        let visibles = 0;
        modulos.forEach(modulo => {
            const coincideGrupo = grupoActivo === 'todos' || modulo.dataset.grupo === grupoActivo;
            const coincideTexto = !termino || normalizar(modulo.dataset.busqueda).includes(termino);
            modulo.hidden = !(coincideGrupo && coincideTexto);
            if (!modulo.hidden) visibles++;
        });
        document.getElementById('sin-resultados').classList.toggle('visible', visibles === 0);
    }

    buscador.addEventListener('input', filtrarModulos);
    filtros.forEach(filtro => filtro.addEventListener('click', () => {
        grupoActivo = filtro.dataset.grupo;
        filtros.forEach(item => item.classList.toggle('activo', item === filtro));
        filtrarModulos();
    }));
</script>
</body>
</html>
