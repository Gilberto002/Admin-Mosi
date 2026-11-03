<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

use App\Auth\Auth;
use App\Import\PlantillaStockExcel;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

Auth::requireLogin();

$libro = PlantillaStockExcel::crear();
$nombre = 'plantilla_carga_stock.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $nombre . '"');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

(new Xlsx($libro))->save('php://output');
$libro->disconnectWorksheets();
exit;
