<?php

$handler = fopen(__DIR__ . '/compta.csv', 'r+');

$cotisations = [];

while(false !==$data = fgetcsv($handler)) {
  if($data[3] === 'Cotisations' && $data[7] !== 'HelloAsso') {
    var_dump($data);
    $cotisations[] = $data;
  }
}

fclose($handler);

$newFile = fopen(__DIR__ . '/new_compta.csv', 'w+');

foreach($cotisations as $cotisation) {
  $fullDesc = explode(' - ', $cotisation[4]);
  $cotisation[4] = strtolower($fullDesc[1]);

  fputcsv($newFile, $cotisation);
}

fclose($newFile);

