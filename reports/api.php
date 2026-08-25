<?php

declare(strict_types=1);

// API JSON pública para integraciones externas. Se autentica con
// client_key/client_secret (tabla api_clientes), no con la sesión de la
// app — no pasa por el enrutador JSON-RPC de /api.

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
ini_set('display_errors', '0');

require_once dirname(__DIR__) . '/config/global.php';
require_once dirname(__DIR__) . '/config/structure.php';
require_once dirname(__DIR__) . '/controller/agronomo.controller.php';

chdir(RUTA_ROOT);

(new AgronomoController())->renderApiReport();
