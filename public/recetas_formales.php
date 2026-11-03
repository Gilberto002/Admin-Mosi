<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/bootstrap.php';

use App\Auth\Auth;
use App\Catalogo\ProductoCatalogo;
use App\Database\Connection;
use App\Vending\RecetaFolio;

Auth::requireLogin();

$loteId = trim((string) ($_GET['lote'] ?? ''));
$foliosDesdeLote = [];
if ($loteId !== '' && preg_match('/^[a-f0-9]{32}$/', $loteId) === 1) {
    $lote = $_SESSION['recetas_prueba_lotes'][$loteId] ?? null;
    if (is_array($lote) && is_array($lote['folios'] ?? null)) {
        $foliosDesdeLote = $lote['folios'];
    }
}

$foliosRaw = $foliosDesdeLote ? implode(',', $foliosDesdeLote) : trim((string) ($_GET['folios'] ?? ''));
$folios = array_values(array_unique(array_filter(array_map(static function (string $folio): string {
    return strtoupper(trim($folio));
}, explode(',', $foliosRaw)), static function (string $folio): bool {
    return preg_match('/^[0-9A-F-]{36}$/', $folio) === 1 || preg_match('/^[0-9]{10}$/', $folio) === 1;
})));
$autoPrint = ($_GET['auto_print'] ?? '') === '1';

$recetas = [];
if ($folios) {
    $pdo = Connection::get();
    ProductoCatalogo::asegurarColumnas($pdo);
    RecetaFolio::asegurarColumnas($pdo);
    $marcadores = implode(',', array_fill(0, count($folios), '?'));
    $stmt = $pdo->prepare(
        "SELECT r.id_receta, COALESCE(r.codigo_receta, CONVERT(varchar(36), r.folio)) AS folio,
                r.id_maquina, r.nombre_paciente, r.medico_solicita, r.fecha_creacion,
                m.numero_serie, m.nombre_ubicacion, m.codigo_interno, m.estado_geo, m.municipio,
                m.localidad, m.clues, m.id_sucursal_clinica,
                COALESCE(sc.st_Nombre, m.nombre_ubicacion) AS sucursal_nombre
         FROM vendingm.recetas r
         INNER JOIN vendingm.maquinas m ON m.id_maquina = r.id_maquina
         LEFT JOIN cat_SucursalClinica sc ON sc.id_SucursalClinica = m.id_sucursal_clinica
         WHERE UPPER(COALESCE(r.codigo_receta, CONVERT(varchar(36), r.folio))) IN ({$marcadores})
         ORDER BY r.fecha_creacion, r.id_receta"
    );
    $stmt->execute($folios);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $receta) {
        if (!Auth::canAccessMachine($pdo, (int) $receta['id_maquina'])) {
            continue;
        }
        $receta['items'] = [];
        $recetas[(int) $receta['id_receta']] = $receta;
    }

    if ($recetas) {
        $ids = array_keys($recetas);
        $marcadoresIds = implode(',', array_fill(0, count($ids), '?'));
        $stmtItems = $pdo->prepare(
            "SELECT dr.id_receta, COALESCE(dr.id_maquina, r.id_maquina) AS id_maquina,
                    m.numero_serie AS numero_serie_maquina, dr.numero_carril,
                    p.nombre AS producto_nombre, p.codigo_barras, p.clave,
                    p.sustancia_activa, p.presentacion,
                    dr.cantidad_solicitada
             FROM vendingm.detalle_receta dr
             INNER JOIN vendingm.recetas r ON r.id_receta = dr.id_receta
             LEFT JOIN vendingm.maquinas m ON m.id_maquina = COALESCE(dr.id_maquina, r.id_maquina)
             INNER JOIN vendingm.productos p ON p.id_producto = dr.id_producto
             WHERE dr.id_receta IN ({$marcadoresIds})
             ORDER BY dr.id_receta, m.numero_serie, dr.numero_carril"
        );
        $stmtItems->execute($ids);
        foreach ($stmtItems->fetchAll(PDO::FETCH_ASSOC) as $item) {
            $recetas[(int) $item['id_receta']]['items'][] = $item;
        }
    }
}

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function fechaReceta(mixed $fecha): string
{
    if (!$fecha) {
        return date('d/m/Y');
    }
    try {
        return (new DateTime((string) $fecha))->format('d/m/Y');
    } catch (Throwable) {
        return date('d/m/Y');
    }
}

function codigoBarrasReceta(string $folio): string
{
    $compacto = strtoupper((string) preg_replace('/[^0-9A-Z]/', '', $folio));
    if (preg_match('/^[0-9]{10}$/', $compacto) === 1) {
        return $compacto;
    }
    return $compacto !== '' ? substr($compacto, -12) : substr(strtoupper($folio), -12);
}

function partesNombre(string $nombreCompleto): array
{
    $partes = preg_split('/\s+/', trim($nombreCompleto)) ?: [];
    if (!$partes) return ['', '', ''];
    if (count($partes) === 1) return [$partes[0], '', ''];
    if (count($partes) === 2) return [$partes[0], $partes[1], ''];
    $segundoApellido = array_pop($partes);
    $primerApellido = array_pop($partes);
    return [implode(' ', $partes), $primerApellido, $segundoApellido];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Receta de medicamentos</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
    <style>
        :root { --vino:#9F1747; --dorado:#B2955C; --texto:#202020; --linea:#2B2B2B; --gris:#F4F4F4; }
        * { box-sizing: border-box; }
        body { margin:0; background:#fff; color:var(--texto); font-family:Arial, Helvetica, sans-serif; }
        .barra { position:sticky; top:0; z-index:10; display:flex; justify-content:center; gap:10px; padding:14px; background:#202020; color:#fff; box-shadow:0 8px 24px rgba(0,0,0,.22); }
        .barra button,.barra a { border:1px solid rgba(255,255,255,.2); background:#fff; color:#202020; border-radius:7px; padding:10px 18px; text-decoration:none; font:inherit; font-weight:700; font-size:13px; cursor:pointer; }
        .wrap { padding:20px 0 40px; }
        .hoja { position:relative; width:216mm; min-height:279mm; margin:0 auto 26px; padding:6mm 7mm; background:#fff; box-shadow:0 20px 50px rgba(16,22,28,.22); overflow:hidden; page-break-after:always; }
        .logos { display:grid; grid-template-columns:34mm 30mm 24mm 34mm 24mm 35mm; align-items:center; gap:3mm; min-height:13mm; margin-bottom:1.4mm; }
        .logos img { display:block; max-width:100%; max-height:11mm; object-fit:contain; }
        .logo-texto { border:1px solid var(--linea); border-radius:2px; padding:1.2mm 1mm; text-align:center; font-size:6px; font-weight:800; line-height:1.1; color:var(--vino); }
        .linea-dorada { height:.8mm; background:var(--dorado); margin-bottom:1.6mm; }
        .titulo-receta { text-align:center; color:var(--vino); font-weight:800; font-size:15px; letter-spacing:0; text-transform:uppercase; margin:1.1mm 0 1.5mm; }
        .grid { display:grid; gap:1mm; margin-bottom:1.2mm; }
        .cols-4 { grid-template-columns:25mm 28mm 42mm 1fr; }
        .cols-3 { grid-template-columns:40mm 1fr 44mm; }
        .cols-4b { grid-template-columns:42mm 42mm 42mm 1fr; }
        .cols-3b { grid-template-columns:44mm 45mm 1fr; }
        .caja { border:1px solid var(--linea); border-radius:2px; min-height:8.6mm; padding:1mm 1.4mm; background:#fff; overflow:hidden; }
        .label { display:block; font-size:5.8px; font-weight:800; line-height:1.05; text-transform:uppercase; color:#111; margin-bottom:.6mm; }
        .valor { min-height:3.6mm; font-size:7.8px; font-weight:700; line-height:1.12; overflow-wrap:anywhere; }
        .fecha-box { display:grid; grid-template-columns:repeat(3,1fr); gap:1mm; text-align:center; }
        .fecha-box span { border-bottom:1px solid var(--linea); min-height:3.8mm; font-size:8px; font-weight:800; }
        .riesgo { display:flex; gap:1.2mm; align-items:center; font-size:7px; font-weight:700; }
        .circulo { display:inline-grid; place-items:center; width:3.2mm; height:3.2mm; border:1px solid var(--linea); border-radius:50%; font-size:6.8px; font-weight:800; }
        .barcode svg { display:block; width:100%; max-width:100%; height:12mm; }
        .opciones { display:flex; justify-content:space-between; gap:2mm; border:1px solid var(--linea); border-radius:2px; padding:1.2mm; margin-bottom:1.2mm; font-size:6.7px; font-weight:700; text-transform:uppercase; }
        .diagnostico { min-height:9mm; margin-bottom:1.2mm; }
        .medicamento { display:grid; grid-template-columns:63mm 29mm 39mm 35mm 1fr; border:1px solid var(--linea); border-radius:2px; min-height:15.2mm; margin-bottom:1mm; overflow:hidden; }
        .medicamento > div { padding:1mm 1.4mm; border-right:1px solid var(--linea); }
        .medicamento > div:last-child { border-right:0; }
        .medicamento .valor { font-size:7.2px; }
        .entregado { display:flex; justify-content:flex-end; gap:1mm; align-items:center; margin-top:1mm; font-size:6px; font-weight:800; text-transform:uppercase; }
        .firmas { display:grid; grid-template-columns:1fr 42mm; gap:1mm; margin-top:1mm; }
        .firma-box { min-height:11mm; }
        .cedula-grid { display:grid; grid-template-columns:42mm 1fr; gap:1mm; margin-top:1mm; }
        .final-grid { display:grid; grid-template-columns:1fr 55mm; gap:1mm; margin-top:1mm; }
        .firma-linea { height:6.5mm; border-bottom:1px solid var(--linea); margin:1mm 3mm .6mm; }
        .pie { position:absolute; left:7mm; right:7mm; bottom:3mm; text-align:center; color:#111; font-size:7px; font-weight:800; }
        .anexo-titulo { margin:8mm 0 5mm; text-align:center; color:var(--vino); font-size:22px; text-transform:uppercase; }
        .anexo-subtitulo { margin:-3mm 0 8mm; text-align:center; color:#666; font-size:11px; }
        .anexo-meta { display:grid; grid-template-columns:1fr 55mm; gap:3mm; margin-bottom:8mm; }
        .lista-medicamentos { margin:0; padding:0; list-style:none; counter-reset:medicamento; border-top:1px solid var(--linea); }
        .lista-medicamentos li { counter-increment:medicamento; display:grid; grid-template-columns:12mm 1fr; align-items:center; min-height:13mm; padding:2.5mm 3mm; border:1px solid var(--linea); border-top:0; font-size:12px; font-weight:700; }
        .lista-medicamentos li::before { content:counter(medicamento); display:grid; place-items:center; width:7mm; height:7mm; border-radius:50%; background:var(--vino); color:#fff; font-size:10px; }
        .anexo-nota { margin-top:8mm; padding:4mm; border:1px solid var(--linea); min-height:22mm; font-size:9px; }
        .mono { font-family:"Courier New", monospace; }
        .vacio { max-width:720px; margin:70px auto; padding:26px 28px; background:#fff; border:1px solid var(--linea); border-left:4px solid var(--vino); border-radius:4px; box-shadow:0 12px 30px rgba(0,0,0,.08); color:#555; }
        @page { size:letter; margin:0; }
        @media print { body { background:#fff; } .barra { display:none; } .wrap { padding:0; } .hoja { width:216mm; min-height:279mm; margin:0; box-shadow:none; page-break-after:always; } }
    </style>
</head>
<body>
<div class="barra">
    <button type="button" onclick="window.print()">Guardar como PDF</button>
    <a href="receta_nueva.php">Volver a recetas</a>
</div>
<?php if (!$recetas): ?>
    <div class="vacio">No se encontraron recetas para imprimir.</div>
<?php else: ?>
    <div class="wrap">
        <?php foreach ($recetas as $idx => $receta): ?>
            <?php
            $folio = strtoupper((string) $receta['folio']);
            $codigoBarras = codigoBarrasReceta($folio);
            $nombrePaciente = trim((string) ($receta['nombre_paciente'] ?? ''));
            $medicamentos = [];
            foreach ($receta['items'] as $itemReceta) {
                $nombreMedicamento = trim((string) ($itemReceta['producto_nombre'] ?? ''));
                if ($nombreMedicamento !== '') {
                    $medicamentos[mb_strtolower($nombreMedicamento, 'UTF-8')] = $nombreMedicamento;
                }
            }
            $medicamentos = array_values($medicamentos);
            $barcodeId = 'barcode-' . $idx;
            ?>
            <article class="hoja">
                    <div class="contenido">
                        <header class="logos">
                            <img src="assets/img/logo.png" alt="Medical Life">
                        </header>
                        <div class="linea-dorada"></div>

                        <section class="grid cols-4">
                            <div class="caja"><span class="label">Fecha</span><div class="fecha-box"><span></span><span></span><span></span></div></div>
                            <div class="caja"><span class="label">Grupo riesgo</span><div class="riesgo"><span class="circulo"></span>1 <span class="circulo"></span>2 <span class="circulo"></span>3 <span class="circulo"></span>4</div></div>
                            <div class="caja"><span class="label">Folio</span><div class="valor mono"><?= h($folio) ?></div></div>
                            <div class="caja"><span class="label">Codigo de barras</span><div class="barcode"><svg id="<?= h($barcodeId) ?>"></svg></div></div>
                        </section>

                        <h1 class="titulo-receta">Receta de medicamentos</h1>

                        <section class="grid cols-3">
                            <div class="caja"><span class="label">ID derechohabiente</span><div class="valor"></div></div>
                            <div class="caja"><span class="label">Nombre del derechohabiente</span><div class="valor"><?= h($nombrePaciente) ?></div></div>
                            <div class="caja"><span class="label">CURP</span><div class="valor"></div></div>
                        </section>

                        <section class="grid cols-4b">
                            <div class="caja"><span class="label">Entidad</span><div class="valor"></div></div>
                            <div class="caja"><span class="label">Region</span><div class="valor"></div></div>
                            <div class="caja"><span class="label">Municipio</span><div class="valor"></div></div>
                            <div class="caja"><span class="label">Area</span><div class="valor"></div></div>
                        </section>

                        <section class="grid cols-3b">
                            <div class="caja"><span class="label">Zona de trabajo</span><div class="valor"></div></div>
                            <div class="caja"><span class="label">Clave de farmacia</span><div class="valor"></div></div>
                            <div class="caja"><span class="label">Nombre de farmacia</span><div class="valor"></div></div>
                        </section>

                        <section class="opciones">
                            <span>Es derechohabiente de alguna de estas instituciones?</span>
                            <span><span class="circulo"></span> IMSS</span>
                            <span><span class="circulo"></span> ISSSTE</span>
                            <span><span class="circulo"></span> ISSFAM</span>
                            <span><span class="circulo"></span> PEMEX</span>
                            <span><span class="circulo"></span> Sin derechohabiencia</span>
                        </section>

                        <section class="caja diagnostico"><span class="label">Diagnostico</span><div class="valor"></div></section>

                        <?php for ($i = 0; $i < 5; $i++): ?>
                            <section class="medicamento">
                                <div><span class="label"><?= $i + 1 ?> Nombre del medicamento</span><div class="valor"></div><div class="entregado">Entregado <span class="circulo"></span> Si <span class="circulo"></span> No</div></div>
                                <div><span class="label">Clave / unidades</span><div class="valor"></div></div>
                                <div><span class="label">Dosis / frecuencia</span><div class="valor"></div></div>
                                <div><span class="label">Via de administracion</span><div class="valor"></div></div>
                                <div><span class="label">Duracion</span><div class="valor"></div></div>
                            </section>
                        <?php endfor; ?>

                        <section class="firmas">
                            <div>
                                <div class="caja firma-box"><span class="label">ID del personal / Nombre de servidora de la salud</span><div class="valor"></div></div>
                                <div class="cedula-grid"><div class="caja"><span class="label">Cedula profesional</span><div class="valor"></div></div><div class="caja"><span class="label">Universidad que expide</span><div class="valor"></div></div></div>
                            </div>
                            <div class="caja firma-box"><span class="label">Firma</span><div class="firma-linea"></div></div>
                        </section>

                        <section class="firmas">
                            <div>
                                <div class="caja firma-box"><span class="label">Nombre del medico supervisor</span><div class="valor"></div></div>
                                <div class="cedula-grid"><div class="caja"><span class="label">Cedula profesional</span><div class="valor"></div></div><div class="caja"><span class="label">Folio autorizacion</span><div class="valor"></div></div></div>
                            </div>
                            <div class="caja firma-box"><span class="label">Firma</span><div class="firma-linea"></div></div>
                        </section>

                        <section class="final-grid">
                            <div class="caja"><span class="label">Observaciones</span><div class="valor"></div></div>
                            <div class="caja"><span class="label">Recibi receta medica</span><div class="firma-linea"></div><div class="label" style="text-align:center;">Firma del derechohabiente</div></div>
                        </section>
                    </div>
                    <footer class="pie">ORIGINAL-BENEFICIARIO(A)</footer>
                </article>
                <script>
                    JsBarcode('#<?= h($barcodeId) ?>', <?= json_encode($codigoBarras) ?>, {
                        format: 'CODE128',
                width: 2.2,
                height: 46,
                        displayValue: true,
                        font: 'monospace',
                fontSize: 11,
                        textMargin: 1,
                        margin: 0,
                        lineColor: '#111111',
                        background: '#ffffff'
                    });
                </script>

            <article class="hoja">
                <header class="logos">
                    <img src="assets/img/logo.png" alt="Medical Life">
                </header>
                <div class="linea-dorada"></div>
                <h1 class="anexo-titulo">Medicamentos de la receta</h1>
                <p class="anexo-subtitulo">Anexo informativo de la receta</p>
                <section class="anexo-meta">
                    <div class="caja"><span class="label">Nombre del paciente</span><div class="valor"><?= h($nombrePaciente) ?></div></div>
                    <div class="caja"><span class="label">Folio</span><div class="valor mono"><?= h($folio) ?></div></div>
                </section>
                <?php if ($medicamentos): ?>
                    <ol class="lista-medicamentos">
                        <?php foreach ($medicamentos as $nombreMedicamento): ?>
                            <li><?= h($nombreMedicamento) ?></li>
                        <?php endforeach; ?>
                    </ol>
                <?php else: ?>
                    <div class="caja"><span class="label">Medicamentos</span><div class="valor">Sin medicamentos registrados.</div></div>
                <?php endif; ?>
                <div class="anexo-nota"><strong>Observaciones:</strong></div>
                <footer class="pie">ANEXO DE MEDICAMENTOS</footer>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php if ($autoPrint && $recetas): ?>
    <script>window.addEventListener('load', () => setTimeout(() => window.print(), 500));</script>
<?php endif; ?>
</body>
</html>
