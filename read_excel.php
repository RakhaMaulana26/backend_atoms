<?php

require __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

$reader = new Xlsx();
$spreadsheet = $reader->load('C:/projekflutter/Jadwal Dinas Airnav.xlsx');

$sheet = $spreadsheet->getActiveSheet();
$highestRow = $sheet->getHighestRow();
$highestCol = $sheet->getHighestColumn();
$highestColIndex = Coordinate::columnIndexFromString($highestCol);

echo "Highest row: $highestRow, Highest column: $highestCol (index: $highestColIndex)" . PHP_EOL . PHP_EOL;

// Read all rows
for ($row = 1; $row <= $highestRow; $row++) {
    $rowData = [];
    // Only read columns A-D and AJ (name, class, position, notes)
    foreach (['A', 'B', 'C', 'D', 'AJ'] as $col) {
        $value = $sheet->getCell($col . $row)->getValue();
        if ($value !== null && $value !== '') {
            $rowData[] = "$col:$value";
        }
    }
    if (!empty($rowData)) {
        echo "Row $row: " . implode(' | ', $rowData) . PHP_EOL;
    }
}
