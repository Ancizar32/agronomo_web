<?php

declare(strict_types=1);

const SOURCE_URL = 'https://geoportal.dane.gov.co/mparcgis/rest/services/NIVEL_DE_REFERENCIA_DE_VEREDAS/Serv_CapasNivelReferenciaVeredas_2024/MapServer/1/query';
const PAGE_SIZE = 2000;
const INSERT_SIZE = 500;

$output = dirname(__DIR__) . '/migrations/009_veredas_dane_2024.sql';
$rows = [];

for ($offset = 0; ; $offset += PAGE_SIZE) {
    $query = http_build_query([
        'where' => "NOMBRE_VER IS NOT NULL AND DPTOMPIO IS NOT NULL",
        'outFields' => 'DPTOMPIO,CODIGO_VER,NOMBRE_VER',
        'returnGeometry' => 'false',
        'orderByFields' => 'OBJECTID_1',
        'resultOffset' => $offset,
        'resultRecordCount' => PAGE_SIZE,
        'f' => 'json',
    ]);
    $curl = curl_init(SOURCE_URL . '?' . $query);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0 AgroSoft-Agronomo/1.0',
        CURLOPT_TIMEOUT => 60,
    ]);
    $json = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    if (!is_string($json) || $status !== 200) {
        throw new RuntimeException('No fue posible consultar el Geoportal DANE.');
    }
    $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    $features = $payload['features'] ?? [];
    foreach ($features as $feature) {
        $attributes = $feature['attributes'] ?? [];
        $municipio = sqlValue((string)($attributes['DPTOMPIO'] ?? ''));
        $codigo = sqlValue((string)($attributes['CODIGO_VER'] ?? ''));
        $nombre = sqlValue(trim((string)($attributes['NOMBRE_VER'] ?? '')));
        if ($municipio === "''" || $nombre === "''") {
            continue;
        }
        $rows[] = "((SELECT id FROM geo_municipios WHERE codigo_dane={$municipio}),{$nombre},'VEREDA',NULLIF({$codigo},''),1)";
    }
    if (count($features) < PAGE_SIZE) {
        break;
    }
}

$sql = "-- 009 · Nivel de referencia de veredas DANE 2024\n";
$sql .= "-- Fuente oficial indicada al final del archivo. Requiere migraciones 007 y 008.\n\n";
foreach (array_chunk($rows, INSERT_SIZE) as $chunk) {
    $sql .= "INSERT INTO geo_localidades_rurales (municipio_id,nombre,tipo,codigo,activo) VALUES\n";
    $sql .= implode(",\n", $chunk);
    $sql .= "\nON DUPLICATE KEY UPDATE codigo=VALUES(codigo),activo=1;\n\n";
}
$sql .= "-- Fuente: " . SOURCE_URL . "\n";

file_put_contents($output, $sql);
echo count($rows) . " veredas escritas en {$output}\n";

function sqlValue(string $value): string
{
    return "'" . str_replace(["\\", "'"], ["\\\\", "''"], $value) . "'";
}
