<?php
declare(strict_types=1);

namespace App\Import;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

final class PlantillaStockExcel
{
    /** @var list<string> */
    public const ENCABEZADOS = [
        'Código de barras',
        'Clave',
        'Descripción',
        'Nombre comercial',
        'Sustancia activa',
        'Ubicación',
        'Cabina',
        'Exhibición',
        'Grupo',
        'Uso terapéutico',
        'Lote',
        'Fecha de caducidad',
        'Stock',
    ];

    public static function crear(): Spreadsheet
    {
        $libro = new Spreadsheet();
        $hoja = $libro->getActiveSheet();
        $hoja->setTitle('Stock');
        $hoja->fromArray(self::ENCABEZADOS, null, 'A1');
        $hoja->freezePane('A2');
        $hoja->setAutoFilter('A1:M1');
        $hoja->setShowGridlines(false);

        $hoja->getStyle('A1:M1')->applyFromArray([
            'font' => [
                'name' => 'Arial',
                'size' => 10,
                'bold' => true,
                'color' => ['rgb' => 'FFFFFF'],
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '176B66'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
            'borders' => [
                'bottom' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => 'D7E4E2'],
                ],
            ],
        ]);
        $hoja->getRowDimension(1)->setRowHeight(24);

        $anchos = [
            'A' => 20, 'B' => 20, 'C' => 36, 'D' => 24, 'E' => 24,
            'F' => 14, 'G' => 10, 'H' => 16, 'I' => 16, 'J' => 22,
            'K' => 18, 'L' => 18, 'M' => 12,
        ];
        foreach ($anchos as $columna => $ancho) {
            $hoja->getColumnDimension($columna)->setWidth($ancho);
        }

        // Identificadores y lotes deben conservar ceros a la izquierda.
        $hoja->getStyle('A2:B1000')->getNumberFormat()->setFormatCode('@');
        $hoja->getStyle('F2:F1000')->getNumberFormat()->setFormatCode('@');
        $hoja->getStyle('K2:K1000')->getNumberFormat()->setFormatCode('@');
        $hoja->getStyle('L2:L1000')->getNumberFormat()->setFormatCode('yyyy-mm-dd');
        $hoja->getStyle('G2:G1000')->getNumberFormat()->setFormatCode('0');
        $hoja->getStyle('M2:M1000')->getNumberFormat()->setFormatCode('0');

        $libro->getProperties()
            ->setTitle('Plantilla vacia para carga de stock')
            ->setSubject('Planimetria y stock de maquinas vending');

        return $libro;
    }
}
