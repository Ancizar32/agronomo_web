<?php

declare(strict_types=1);

final class AgronomoApiRouter
{
    private const CONTROLLER = 'agronomo';

    private const ALLOWED_METHODS = [
        'validSystem' => true, 'validLogin' => true, 'logoutWeb' => true, 'cambiarPassword' => true,
        'getPermisosUsuario' => true,
        'changeProvisionalPasswordWeb' => true,
        'getTecnicos' => true, 'crearTecnico' => true, 'editarTecnico' => true,
        'asignarFincaTecnico' => true, 'getVisitasPorTecnico' => true,
        'getEquipoTecnicoWeb' => true,
        'createConfiguracion' => true, 'descargarConfiguracion' => true,
        'createVisitaDetalleRecomendacion' => true, 'descargarVisitaDetallesRecomendaciones' => true,
        'createVisitaDetalleFormula' => true, 'descargarVisitaDetallesFormulas' => true,
        'createActividad' => true, 'descargarActividades' => true,
        'createHallazgo' => true, 'descargarHallazgos' => true,
        'createVisitaDetalleLote' => true, 'getVisitaDetalleLote' => true,
        'createVisita' => true, 'getVisitas' => true,
        'saveAgendaVisita' => true, 'getAgendaVisitas' => true,
        'getAgendaVisitasWeb' => true, 'saveAgendaVisitaWeb' => true, 'cambiarEstadoAgendaVisitaWeb' => true,
        'createRecomendacion' => true, 'getRecomendaciones' => true,
        'createLabor' => true, 'getLabores' => true,
        'createDetalleFormula' => true, 'getDetalleFormula' => true,
        'createFormula' => true, 'getFormulas' => true,
        'createLote' => true, 'getLotes' => true,
        'createFinca' => true, 'getFincas' => true,
        'createCategoriaLabor' => true, 'getCategoriasLabor' => true,
        'getFincasWeb' => true, 'getFincaDetalleWeb' => true, 'getUsuariosFincaWeb' => true,
        'saveFincaWeb' => true, 'saveLoteWeb' => true,
        'savePredioCompletoWeb' => true,
        'getCultivosWeb' => true, 'saveCultivoWeb' => true,
        'getLaboresWeb' => true, 'saveLaborWeb' => true,
        'getCategoriasLaborWeb' => true, 'saveCategoriaLaborWeb' => true,
        'getRecomendacionesWeb' => true, 'saveRecomendacionWeb' => true, 'toggleRecomendacionWeb' => true,
        'getVisitasWeb' => true, 'getVisitaDetalleWeb' => true, 'getResumenWeb' => true,
        'getConfiguracionUsuarioWeb' => true, 'saveConfiguracionUsuarioWeb' => true,
        'getFirmaTecnicoWeb' => true,
        'getDivisionTerritorialWeb' => true,
        'getAdministracionWeb' => true, 'saveUsuarioWeb' => true,
        'toggleUsuarioWeb' => true, 'resetUsuarioPasswordWeb' => true,
        'saveRolWeb' => true, 'deleteRolWeb' => true,
        'getInsumosFormulasWeb' => true, 'saveInsumoWeb' => true,
        'toggleInsumoWeb' => true, 'saveFormulaWeb' => true, 'toggleFormulaWeb' => true,
        'createCultivo' => true, 'getCultivos' => true,
        'createInsumo' => true, 'getInsumos' => true,
    ];

    public function dispatch(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->respond(false, 'Método HTTP no permitido.', [], 405);
        }

        $request = $this->readRequest();
        $controller = strtolower(trim((string)($request['controller'] ?? '')));
        $method = trim((string)($request['method'] ?? ''));

        if ($controller !== self::CONTROLLER) {
            $this->respond(false, 'Controlador no permitido.', [], 404);
        }
        if (!isset(self::ALLOWED_METHODS[$method])) {
            $this->respond(false, 'Método no permitido.', [], 404);
        }

        $data = $request['data'] ?? [];
        if (!is_array($data)) {
            $this->respond(false, 'El campo data debe ser un objeto JSON.', [], 422);
        }

        require_once dirname(__DIR__) . '/controller/agronomo.controller.php';
        $instance = new AgronomoController();
        $webMethods = ['getFincasWeb','getFincaDetalleWeb','saveFincaWeb','saveLoteWeb','savePredioCompletoWeb','getDivisionTerritorialWeb','getAdministracionWeb','saveUsuarioWeb','toggleUsuarioWeb','resetUsuarioPasswordWeb','saveRolWeb','deleteRolWeb','getCultivosWeb','saveCultivoWeb','getLaboresWeb','saveLaborWeb','getCategoriasLaborWeb','saveCategoriaLaborWeb','getInsumosFormulasWeb','saveInsumoWeb','toggleInsumoWeb','saveFormulaWeb','toggleFormulaWeb','getRecomendacionesWeb','saveRecomendacionWeb','toggleRecomendacionWeb','getVisitasWeb','getVisitaDetalleWeb','getFirmaTecnicoWeb','getEquipoTecnicoWeb','getResumenWeb','getConfiguracionUsuarioWeb','saveConfiguracionUsuarioWeb','getAgendaVisitasWeb','saveAgendaVisitaWeb','cambiarEstadoAgendaVisitaWeb'];
        // Un 401 (sesión inexistente) es un caso distinto de un 403 (sesión
        // válida pero sin el permiso). Antes ambos devolvían el mismo 403
        // "No tienes permiso para realizar esta acción" — dejaba al usuario
        // viendo ese mensaje confuso cuando en realidad su sesión de PHP ya
        // había expirado, en vez de mandarlo de vuelta al login.
        $sessionRequiredMethods = array_merge($webMethods, ['changeProvisionalPasswordWeb']);
        if (in_array($method, $sessionRequiredMethods, true) && empty($_SESSION['agronomo_user_id'])) {
            $this->respond(false, 'Tu sesión expiró. Inicia sesión nuevamente.', [], 401);
        }
        if (in_array($method, $webMethods, true) && ($_SESSION['agronomo_pass_provi'] ?? '0') === '1') {
            $this->respond(false, 'Debes cambiar tu contraseña provisional antes de continuar.', [], 403);
        }
        $requiredPermissions = [
            'saveUsuarioWeb' => ['usuarios', empty($data['id']) ? 'crear' : 'editar'],
            'toggleUsuarioWeb' => ['usuarios', 'editar'],
            'resetUsuarioPasswordWeb' => ['usuarios', 'cambiar_password'],
            'saveRolWeb' => ['roles', empty($data['id']) ? 'crear' : 'editar'],
            'deleteRolWeb' => ['roles', 'eliminar'],
            'getFincasWeb' => ['fincas', 'ver'], 'getFincaDetalleWeb' => ['fincas', 'ver'],
            'getUsuariosFincaWeb' => ['fincas', 'editar'],
            'saveFincaWeb' => ['fincas', empty($data['id']) ? 'crear' : 'editar'],
            'saveLoteWeb' => ['lotes', empty($data['id']) ? 'crear' : 'editar'],
            'savePredioCompletoWeb' => ['fincas', empty($data['finca_id']) ? 'crear' : 'editar'],
            'getDivisionTerritorialWeb' => ['catalogos', 'ver'],
            'saveInsumoWeb' => ['insumos', empty($data['id_original']) ? 'crear' : 'editar'],
            'toggleInsumoWeb' => ['insumos', 'editar'],
            'saveFormulaWeb' => ['formulas', empty($data['id']) ? 'crear' : 'editar'],
            'toggleFormulaWeb' => ['formulas', 'editar'],
            'getCultivosWeb' => ['cultivos', 'ver'],
            'saveCultivoWeb' => ['cultivos', empty($data['id']) ? 'crear' : 'editar'],
            'getLaboresWeb' => ['labores', 'ver'],
            'saveLaborWeb' => ['labores', empty($data['id']) ? 'crear' : 'editar'],
            'getCategoriasLaborWeb' => ['categorias_labor', 'ver'],
            'saveCategoriaLaborWeb' => ['categorias_labor', empty($data['id']) ? 'crear' : 'editar'],
            'getRecomendacionesWeb' => ['recomendaciones', 'ver'],
            'saveRecomendacionWeb' => ['recomendaciones', empty($data['id']) ? 'crear' : 'editar'],
            'toggleRecomendacionWeb' => ['recomendaciones', 'editar'],
            'getVisitasWeb' => ['visitas', 'ver'],
            'getVisitaDetalleWeb' => ['visitas', 'ver'],
            'getFirmaTecnicoWeb' => ['visitas', 'ver'],
            'getResumenWeb' => ['dashboard', 'ver'],
            'getEquipoTecnicoWeb' => ['tecnicos', 'ver'],
            'getAgendaVisitasWeb' => ['agenda', 'ver'],
            'saveAgendaVisitaWeb' => ['agenda', empty($data['id']) ? 'crear' : 'editar'],
            'cambiarEstadoAgendaVisitaWeb' => ['agenda', 'editar'],
        ];
        if ($method === 'getAdministracionWeb' && !$instance->canWeb('usuarios', 'ver') && !$instance->canWeb('roles', 'ver')) {
            $this->respond(false, 'No tienes permiso para consultar la administración.', [], 403);
        }
        if ($method === 'getInsumosFormulasWeb' && !$instance->canWeb('insumos', 'ver') && !$instance->canWeb('formulas', 'ver')) {
            $this->respond(false, 'No tienes permiso para consultar insumos o fórmulas.', [], 403);
        }
        if (isset($requiredPermissions[$method])) {
            [$module, $action] = $requiredPermissions[$method];
            if (!$instance->canWeb($module, $action)) {
                $this->respond(false, 'No tienes permiso para realizar esta acción.', [], 403);
            }
        }
        try {
            $instance->{$method}($data);
        } catch (Throwable $e) {
            // Red de seguridad: cualquier excepción no atrapada (violación
            // de integridad, error de conexión, etc.) debe seguir
            // respondiendo JSON válido, nunca el volcado crudo que
            // imprimía antes el manejador de PDO en config/global.php.
            $this->respond(false, 'Error del servidor: ' . $e->getMessage(), [], 500);
        }
    }

    private function readRequest(): array
    {
        $raw = file_get_contents('php://input');
        $decoded = json_decode($raw ?: '', true);
        if (is_array($decoded)) {
            return $decoded;
        }
        if (!empty($_POST)) {
            return $_POST;
        }
        $this->respond(false, 'Se esperaba un cuerpo JSON válido.', [], 400);
    }

    private function respond(bool $success, string $message, array $detail, int $status): void
    {
        http_response_code($status);
        echo json_encode([
            'success' => $success,
            'title' => $success ? 'Genial!' : 'Error',
            'icon' => $success ? 'success' : 'error',
            'message' => $message,
            'detail' => $detail,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
}
