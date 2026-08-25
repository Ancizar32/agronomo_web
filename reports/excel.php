<?php

declare(strict_types=1);

// Endpoint público para Power Query ("Desde la Web" en Excel). No pasa por
// el enrutador JSON-RPC de /api — Excel lo llama directamente con HTTP
// Basic Auth (usuario y contraseña de la app) y espera una tabla HTML.

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
ini_set('display_errors', '0');

require_once dirname(__DIR__) . '/config/global.php';
require_once dirname(__DIR__) . '/config/structure.php';
require_once dirname(__DIR__) . '/controller/agronomo.controller.php';

chdir(RUTA_ROOT);

(new AgronomoController())->renderExcelReport();
