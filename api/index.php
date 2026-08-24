<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Dependencias heredadas como PHPExcel todavía emiten avisos deprecados en
// PHP 8.4. Nunca deben mezclarse con el cuerpo JSON que consume la app.
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
ini_set('display_errors', '0');

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: X-Requested-With, Origin, Content-Type, Accept, Authorization');
header('Content-Type: application/json; charset=UTF-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once dirname(__DIR__) . '/config/global.php';
require_once dirname(__DIR__) . '/config/structure.php';
require_once __DIR__ . '/router.php';

// El MVC heredado resuelve model/, view/ y uploads/ desde el directorio de
// trabajo. La entrada /api vive un nivel más abajo, así que normalizamos aquí.
chdir(RUTA_ROOT);

(new AgronomoApiRouter())->dispatch();
