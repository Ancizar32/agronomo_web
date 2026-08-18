<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS, HEAD');
header('Access-Control-Allow-Headers: X-Requested-With, Origin, Content-Type, Accept, Authorization');
header('Content-Type: application/json; charset=UTF-8');

date_default_timezone_set('America/Bogota');

// hot-properly-penguin.ngrok-free.app/AgroSoft_Agronomo

use function InduSoft\rlog;

require_once 'config/global.php';
require_once 'config/structure.php';
rlog("llega");
$method = $_SERVER['REQUEST_METHOD'] ?? '';
$userAgent = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');

// --- Health check global ---
if (in_array($method, ['GET', 'HEAD', 'OPTIONS'])) {
    $param = [];
    $success = isset($param['success']) ? $param['success'] : true;
    $title = isset($param['title']) ? $param['title'] : 'Genial!';
    $icon = isset($param['icon']) ? $param['icon'] : 'success';
    $message = isset($param['message']) ? $param['message'] : 'AgroSoft API disponible (Health check)';
    $data = isset($param['data']) ? $param['data'] : [];
    $ret = ['success' => $success, 'title' => $title, 'icon' => $icon, 'message' => $message, 'detail' => $data];
    $raw_bytes = json_encode($ret, JSON_PRETTY_PRINT);

    echo $raw_bytes;
    rlog($method);
    rlog($userAgent);
    exit(0);
}

// --- Ignorar bots comunes (Facebook, Google, LinkedIn, etc.) ---
$botPatterns = [
    'googlebot',
    'apis-google',
    'bingbot',
    'crawler',
    'spider',
    'bot',
    'meta-externalagent',
    'facebookexternalhit',
    'facebot',
    'linkedinbot',
    'whatsapp',
    'twitterbot'
];
foreach ($botPatterns as $bot) {
    if (strpos($userAgent, $bot) !== false) {
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'status'  => 'ok',
            'message' => 'Health check / crawler access',
            'agent'   => $userAgent
        ], JSON_UNESCAPED_UNICODE);
        rlog("llega a googlebot");
        exit(0);
    }
}

// Leer cuerpo JSON
$json = file_get_contents('php://input');
$data = json_decode($json, true);

// Si no hay cuerpo, devolver mensaje genérico
if (!is_array($data)) {
    echo json_encode(['success' => true, 'status' => 'ok', 'message' => 'API AgroSoft Agrónomo operativa']);
    exit();
}

// Permitir también POST clásico
if (empty($data) && !empty($_POST)) {
    $data = $_POST;
}

rlog($data);
// Log de peticiones reales
if (isset($data['controller'])) {
    rlog($data);
}

// Ruteo de controladores
$controller = $data['controller'] ?? null;
$method = $data['method'] ?? null;

if ($controller && $method) {
    require_once "controller/$controller.controller.php";
    $controllerClass = ucfirst($controller) . 'Controller';
    if (class_exists($controllerClass)) {
        $instance = new $controllerClass();
        if (method_exists($instance, $method)) {
            $instance->$method($data['data'] ?? []);
        } else {
            echo json_encode(['error' => 'Método no encontrado']);
        }
    } else {
        echo json_encode(['error' => 'Controlador no encontrado']);
    }
} else {
    echo json_encode(['error' => 'Parámetros inválidos']);
}
