<?php

declare(strict_types=1);

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Compatibilidad: las versiones instaladas de la app móvil siguen enviando
// sus peticiones a /index.php. La API formal también queda disponible en /api/.
if ($requestMethod === 'POST' || $requestMethod === 'OPTIONS') {
    require __DIR__ . '/api/index.php';
    exit;
}

header('Content-Type: text/html; charset=UTF-8');
require __DIR__ . '/web/app.php';
