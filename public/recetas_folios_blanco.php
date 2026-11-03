<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/bootstrap.php';

use App\Auth\Auth;

Auth::requireLogin();

$desde = filter_input(INPUT_GET, 'desde', FILTER_VALIDATE_INT) ?: 7;
$hasta = filter_input(INPUT_GET, 'hasta', FILTER_VALIDATE_INT) ?: 16;
$desde = max(1, min(9999999999, $desde));
$hasta = max($desde, min(9999999999, $hasta));
$hasta = min($hasta, $desde + 99);
$autoPrint = ($_GET['auto_print'] ?? '') === '1';

$folios = [];
for ($i = $desde; $i <= $hasta; $i++) {
    $folios[] = str_pad((string) $i, 10, '0', STR_PAD_LEFT);
}

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Recetas en blanco <?= h($folios[0] ?? '') ?> - <?= h($folios[count($folios) - 1] ?? '') ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
    <style>
        :root { --vino:#7b123f; --dorado:#B2955C; --texto:#202020; --linea:#2B2B2B; }
        * { box-sizing:border-box; }
        body { margin:0; background:#fff; color:var(--texto); font-family:Arial, Helvetica, sans-serif; }
        .barra { position:sticky; top:0; z-index:10; display:flex; justify-content:center; gap:10px; padding:14px; background:#202020; color:#fff; box-shadow:0 8px 24px rgba(0,0,0,.22); }
        .barra button,.barra a { border:1px solid rgba(255,255,255,.2); background:#fff; color:#202020; border-radius:7px; padding:10px 18px; text-decoration:none; font:inherit; font-weight:700; font-size:13px; cursor:pointer; }
        .wrap { padding:20px 0 40px; }
        .hoja { position:relative; width:216mm; min-height:279mm; margin:0 auto 26px; padding:6mm 7mm; background:#fff; box-shadow:0 20px 50px rgba(16,22,28,.22); overflow:hidden; page-break-after:always; }
        .logos { display:grid; grid-template-columns:34mm 31mm 25mm 34mm 24mm 35mm; align-items:center; gap:3mm; min-height:13mm; margin-bottom:1.4mm; }
        .logos img { display:block; max-width:100%; max-height:11mm; object-fit:contain; }
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
        .fecha-box span { border-bottom:1px solid var(--linea); min-height:3.8mm; }
        .riesgo { display:flex; gap:1.2mm; align-items:center; font-size:7px; font-weight:700; }
        .circulo { display:inline-grid; place-items:center; width:3.2mm; height:3.2mm; border:1px solid var(--linea); border-radius:50%; font-size:6.8px; font-weight:800; }
        .barcode svg { display:block; width:100%; max-width:100%; height:12mm; }
        .opciones { display:flex; justify-content:space-between; gap:2mm; border:1px solid var(--linea); border-radius:2px; padding:1.2mm; margin-bottom:1.2mm; font-size:6.7px; font-weight:700; text-transform:uppercase; }
        .diagnostico { min-height:9mm; margin-bottom:1.2mm; }
        .medicamento { display:grid; grid-template-columns:63mm 29mm 39mm 35mm 1fr; border:1px solid var(--linea); border-radius:2px; min-height:15.2mm; margin-bottom:1mm; overflow:hidden; }
        .medicamento > div { padding:1mm 1.4mm; border-right:1px solid var(--linea); }
        .medicamento > div:last-child { border-right:0; }
        .entregado { display:flex; justify-content:flex-end; gap:1mm; align-items:center; margin-top:1mm; font-size:6px; font-weight:800; text-transform:uppercase; }
        .firmas { display:grid; grid-template-columns:1fr 42mm; gap:1mm; margin-top:1mm; }
        .firma-box { min-height:11mm; }
        .cedula-grid { display:grid; grid-template-columns:42mm 1fr; gap:1mm; margin-top:1mm; }
        .final-grid { display:grid; grid-template-columns:1fr 55mm; gap:1mm; margin-top:1mm; }
        .firma-linea { height:6.5mm; border-bottom:1px solid var(--linea); margin:1mm 3mm .6mm; }
        .pie { position:absolute; left:7mm; right:7mm; bottom:3mm; text-align:center; color:#111; font-size:7px; font-weight:800; }
        .mono { font-family:"Courier New", monospace; }
        @page { size:letter; margin:0; }
        @media print { body { background:#fff; } .barra { display:none; } .wrap { padding:0; } .hoja { width:216mm; min-height:279mm; margin:0; box-shadow:none; page-break-after:always; } }
    </style>
</head>
<body>
<div class="barra">
    <button type="button" onclick="window.print()">Guardar como PDF</button>
    <a href="receta_nueva.php">Volver a recetas</a>
</div>
<div class="wrap">
    <?php foreach ($folios as $idx => $folio): ?>
        <?php $barcodeId = 'barcode-blanco-' . $idx; ?>
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
                    <div class="caja"><span class="label">Nombre del derechohabiente</span><div class="valor"></div></div>
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

                <?php for ($i = 1; $i <= 5; $i++): ?>
                    <section class="medicamento">
                        <div><span class="label"><?= $i ?> Nombre del medicamento</span><div class="valor"></div><div class="entregado">Entregado <span class="circulo"></span> Si <span class="circulo"></span> No</div></div>
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
            JsBarcode('#<?= h($barcodeId) ?>', <?= json_encode($folio) ?>, {
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
    <?php endforeach; ?>
</div>
<?php if ($autoPrint): ?>
    <script>window.addEventListener('load', () => setTimeout(() => window.print(), 500));</script>
<?php endif; ?>
</body>
</html>
