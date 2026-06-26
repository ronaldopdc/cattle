<?php
require 'vendor/autoload.php';
$reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile('Simulação engorda.xls');
$reader->setReadDataOnly(true);
$spreadsheet = $reader->load('Simulação engorda.xls');
$worksheet = $spreadsheet->getActiveSheet();
$data = $worksheet->toArray();
print_r($data);
