<?php
date_default_timezone_set("America/Bogota");

use function InduSoft\rlog;
use function InduSoft\service_return;
use function InduSoft\crearSentenciaInsert;
use function InduSoft\crearSentenciaSelect;
use function InduSoft\crearSentenciaUpdate;
use function InduSoft\uniqueId;

class AgronomoController extends ControllerBase
{

    private $model;
    private $liveSession = null;

    // Tablas con datos sensibles (credenciales, hashes, tokens) que el
    // constructor de queries nunca deja referenciar, ni para escribir SQL
    // ni para navegar el esquema — mismo criterio que la lista negra de
    // AgroSoft_dev2/build.query, adaptada a las tablas de esta base.
    private const BUILD_QUERY_BLOCKED_TABLES = [
        'user', 'auth_tokens', 'api_clientes', 'api_cliente_reportes',
        'api_request_log', 'report_queries', 'log_accesos',
        'notificaciones_mobile', 'notificacion_destinatarios', 'mobile_data_version',
    ];

    public function __construct()
    {
        parent::__construct();
        $this->model = loadModel('model');
    }

    // Los errores de MySQL para tablas/columnas inexistentes traen el
    // esquema calificado (ej. 'u902320992_agronomo.cultivo') — se quita ese
    // prefijo antes de devolver el mensaje al navegador, para no revelar el
    // nombre real de la base de datos a quien esté probando una consulta.
    private function sanitizeBuildQueryError(string $message): string
    {
        return preg_replace('/\b[a-zA-Z0-9_]+\.([a-zA-Z0-9_]+)\b/', '$1', $message);
    }

    /** Ventana de histórico enviada a mobile. Nunca entrega todo el histórico
     * por omisión, incluso cuando una versión antigua no envía fecha_desde. */
    private function fechaDesdeMobile(array $data): string
    {
        $fecha = trim((string)($data['fecha_desde'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            return $fecha;
        }
        return date('Y-m-d', strtotime('-6 months'));
    }

    public function validLogin($data)
    {
        $username = strtolower(trim((string)($data['usuario'] ?? '')));
        $password = (string)($data['psw'] ?? '');
        if ($username === '' || $password === '') {
            service_return(['success'=>false,'title'=>'Revisa tus datos','icon'=>'warning','message'=>'Ingresa el usuario y la contraseña.','data'=>[]]);
        }
        $rows = $this->model->queryPrepared(
            "SELECT u.*,r.codigo AS rol_codigo,r.nombre AS rol_nombre
             FROM `user` u LEFT JOIN roles r ON r.id=u.rol_id
             WHERE LOWER(u.`user`)=:usuario LIMIT 1",
            [':usuario'=>$username]
        );
        $userRow = $rows[0] ?? null;
        if (!$userRow) {
            service_return(['success'=>false,'title'=>'Acceso no válido','icon'=>'warning','message'=>'Usuario o contraseña incorrectos.','data'=>[]]);
        }
        if ((string)($userRow['void'] ?? '0') !== '1') {
            service_return(['success'=>false,'title'=>'Usuario inactivo','icon'=>'warning','message'=>'Este usuario está inactivo. Comunícate con el administrador.','data'=>[]]);
        }
        $validPassword = $userRow && (
            (!empty($userRow['password_hash']) && password_verify($password, $userRow['password_hash']))
            || hash_equals((string)($userRow['code'] ?? ''), $password)
        );
        if (!$validPassword) {
            service_return(['success'=>false,'title'=>'Acceso no válido','icon'=>'warning','message'=>'Usuario o contraseña incorrectos.','data'=>[]]);
        }
        $permissions = $this->permissionsForRole((int)($userRow['rol_id'] ?? 0));
        $_SESSION['agronomo_user_id'] = $userRow['id'];
        $_SESSION['agronomo_role_id'] = (int)($userRow['rol_id'] ?? 0);
        $_SESSION['agronomo_role_code'] = $userRow['rol_codigo'] ?? '';
        $_SESSION['agronomo_permissions'] = $permissions;
        $_SESSION['agronomo_pass_provi'] = (string)($userRow['pass_provi'] ?? '0');
        $this->model->executePrepared(
            "UPDATE `user` SET ultimo_acceso=:ahora WHERE id=:id",
            [':id'=>$userRow['id'], ':ahora'=>date('Y-m-d H:i:s')]
        );
        $apiToken = null;
        if (strtolower(trim((string)($data['client_type'] ?? ''))) === 'mobile') {
            $apiToken = bin2hex(random_bytes(32));
            $tokenCreatedAt = date('Y-m-d H:i:s');
            $tokenExpiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));
            $tokenDeviceName = substr(trim((string)($data['device_name'] ?? 'App móvil')), 0, 120);
            $tokenPlatform = substr(trim((string)($data['platform'] ?? '')), 0, 20);
            // Solo se reemplaza la sesión anterior del mismo dispositivo. Un
            // usuario puede trabajar simultáneamente desde Android e iOS.
            $this->model->executePrepared(
                "UPDATE auth_tokens SET revoked_at=:ahora
                 WHERE user_id=:usuario AND device_name=:dispositivo
                   AND platform=:platform AND revoked_at IS NULL",
                [
                    ':ahora'=>$tokenCreatedAt,
                    ':usuario'=>(string)$userRow['id'],
                    ':dispositivo'=>$tokenDeviceName,
                    ':platform'=>$tokenPlatform,
                ]
            );
            $this->model->executePrepared(
                "INSERT INTO auth_tokens(user_id,token_hash,device_name,app_version,build_number,platform,os_version,ip_address,expires_at,created_at)
                 VALUES(:usuario,:hash,:dispositivo,:app_version,:build_number,:platform,:os_version,:ip,:expires_at,:created_at)",
                [
                    ':usuario'=>(string)$userRow['id'],
                    ':hash'=>hash('sha256', $apiToken),
                    ':dispositivo'=>$tokenDeviceName,
                    ':app_version'=>substr(trim((string)($data['app_version'] ?? '')), 0, 30),
                    ':build_number'=>substr(trim((string)($data['build_number'] ?? '')), 0, 20),
                    ':platform'=>$tokenPlatform,
                    ':os_version'=>substr(trim((string)($data['os_version'] ?? '')), 0, 80),
                    ':ip'=>substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
                    ':expires_at'=>$tokenExpiresAt,
                    ':created_at'=>$tokenCreatedAt,
                ]
            );
        }
        // La respuesta de autenticación nunca debe exponer la contraseña ni
        // columnas internas de la tabla user al navegador o a la app móvil.
        $data_cliente = [
            'id' => $userRow['id'] ?? '',
            'name' => $userRow['name'] ?? '',
            'user' => $userRow['user'] ?? '',
            'roll' => $userRow['roll'] ?? '',
            'pass_provi' => $userRow['pass_provi'] ?? '0',
            'mail' => $userRow['mail'] ?? '',
            'rol_id' => (int)($userRow['rol_id'] ?? 0),
            'rol_codigo' => $userRow['rol_codigo'] ?? '',
            'rol_nombre' => $userRow['rol_nombre'] ?? '',
            'permissions' => $permissions,
        ];
        if ($apiToken !== null) $data_cliente['api_token'] = $apiToken;

        service_return(['data' => $data_cliente, 'message' => 'El login se realiza con éxito!']);
    }

    private function permissionsForRole(int $roleId): array
    {
        if ($roleId <= 0) return [];
        $rows = $this->model->queryPrepared(
            "SELECT CONCAT(p.modulo,'.',p.accion) AS permiso
             FROM rol_permisos rp JOIN permisos p ON p.id=rp.permiso_id
             WHERE rp.rol_id=:rol ORDER BY p.modulo,p.accion",
            [':rol'=>$roleId]
        );
        return array_values(array_column($rows, 'permiso'));
    }

    // Permite que la app móvil refresque el rol y los permisos del usuario
    // (por ejemplo, al presionar "Actualizar datos") sin volver a pedir
    // contraseña, para reflejar cambios que un administrador haya hecho en
    // el rol desde el panel web.
    public function getPermisosUsuario($data)
    {
        $usuarioId = trim((string)($data['usuario_id'] ?? ''));
        if ($usuarioId === '') {
            service_return(['success' => false, 'message' => 'El usuario es requerido.', 'data' => []]);
        }
        $rows = $this->model->queryPrepared(
            "SELECT u.rol_id, r.codigo AS rol_codigo, r.nombre AS rol_nombre
             FROM `user` u LEFT JOIN roles r ON r.id = u.rol_id
             WHERE u.id = :id AND u.void = '1' LIMIT 1",
            [':id' => $usuarioId]
        );
        if (!$rows) {
            service_return(['success' => false, 'message' => 'Usuario no encontrado o inactivo.', 'data' => []]);
        }
        $permissions = $this->permissionsForRole((int)($rows[0]['rol_id'] ?? 0));
        service_return(['data' => [
            'rol_id' => (int)($rows[0]['rol_id'] ?? 0),
            'rol_codigo' => $rows[0]['rol_codigo'] ?? '',
            'rol_nombre' => $rows[0]['rol_nombre'] ?? '',
            'permissions' => $permissions,
        ], 'message' => 'Permisos consultados con éxito.']);
    }

    // Consulta el rol, los permisos y el estado (activo, contraseña provisional)
    // directamente en la base de datos en cada petición, en vez de confiar en
    // el snapshot que $_SESSION guardó al hacer login. Sin esto, a un usuario
    // con la sesión abierta se le podía revocar un permiso, desactivar la
    // cuenta, o cambiar de rol, y seguiría pudiendo crear/editar/anular en el
    // backend hasta que cerrara sesión manualmente. Se cachea en memoria por
    // el resto de la petición porque canWeb() puede llamarse varias veces.
    private function loadLiveSession(): array
    {
        if ($this->liveSession !== null) return $this->liveSession;
        $userId = (string)($_SESSION['agronomo_user_id'] ?? '');
        $empty = ['active' => false, 'role_code' => '', 'permissions' => [], 'pass_provi' => '0'];
        if ($userId === '') {
            $this->liveSession = $empty;
            return $this->liveSession;
        }
        $rows = $this->model->queryPrepared(
            "SELECT u.rol_id, u.pass_provi, u.void, r.codigo AS rol_codigo
             FROM `user` u LEFT JOIN roles r ON r.id = u.rol_id
             WHERE u.id = :id LIMIT 1",
            [':id' => $userId]
        );
        $row = $rows[0] ?? null;
        if (!$row || (string)($row['void'] ?? '0') !== '1') {
            $this->liveSession = $empty;
            return $this->liveSession;
        }
        $this->liveSession = [
            'active' => true,
            'role_code' => $row['rol_codigo'] ?? '',
            'permissions' => $this->permissionsForRole((int)($row['rol_id'] ?? 0)),
            'pass_provi' => (string)($row['pass_provi'] ?? '0'),
        ];
        return $this->liveSession;
    }

    public function canWeb(string $module, string $action): bool
    {
        if (empty($_SESSION['agronomo_user_id'])) return false;
        $session = $this->loadLiveSession();
        if (!$session['active']) return false;
        if ($session['pass_provi'] === '1') return false;
        if ($session['role_code'] === 'admin') return true;
        return in_array($module . '.' . $action, $session['permissions'], true);
    }

    // Fincas asignadas al usuario actual (tabla usuario_fincas). Un
    // Administrador ve todas las fincas (null = sin restricción); cualquier
    // otro rol solo debe ver/tocar las fincas que tiene asignadas — sin esto,
    // un técnico o supervisor con el permiso "fincas.ver" veía el listado
    // completo de fincas y visitas de toda la operación, no solo las suyas.
    private function assignedFincaIds(): ?array
    {
        $session = $this->loadLiveSession();
        if ($session['role_code'] === 'admin') return null;
        $userId = (string)($_SESSION['agronomo_user_id'] ?? '');
        if ($userId === '') return [];
        $rows = $this->model->queryPrepared(
            "SELECT finca_id FROM usuario_fincas WHERE usuario_id = :id",
            [':id' => $userId]
        );
        return array_map(function ($row) { return (string)$row['finca_id']; }, $rows);
    }

    // Fragmento SQL + parámetros para limitar una consulta a las fincas
    // asignadas. $fincaIds=null (admin) no agrega restricción alguna.
    private function fincaScopeClause(string $column, ?array $fincaIds): array
    {
        if ($fincaIds === null) return ['sql' => '', 'params' => []];
        if (empty($fincaIds)) return ['sql' => '1 = 0', 'params' => []];
        $placeholders = [];
        $params = [];
        foreach (array_values($fincaIds) as $index => $fincaId) {
            $key = ":scope_finca_$index";
            $placeholders[] = $key;
            $params[$key] = $fincaId;
        }
        return ['sql' => "$column IN (" . implode(',', $placeholders) . ')', 'params' => $params];
    }

    // Corta la ejecución con un 403 si la finca solicitada no está entre las
    // asignadas al usuario actual (los Administradores nunca son bloqueados).
    private function assertFincaAccesible(string $fincaId): void
    {
        $fincaIds = $this->assignedFincaIds();
        if ($fincaIds !== null && !in_array($fincaId, $fincaIds, true)) {
            service_return(['success' => false, 'message' => 'No tienes acceso a esta finca.', 'data' => []]);
        }
    }

    private function mobileUserIsAdmin(string $usuarioId): bool
    {
        if ($usuarioId === '') return false;
        $rows = $this->model->queryPrepared(
            "SELECT r.codigo FROM `user` u LEFT JOIN roles r ON r.id=u.rol_id WHERE u.id=:id AND u.void='1' LIMIT 1",
            [':id'=>$usuarioId]
        );
        return strtolower((string)($rows[0]['codigo'] ?? '')) === 'admin';
    }

    private function mobileUserCan(string $usuarioId, string $module, string $action): bool
    {
        if ($this->mobileUserIsAdmin($usuarioId)) return true;
        if ($usuarioId === '') return false;
        $rows = $this->model->queryPrepared(
            "SELECT 1 FROM `user` u
             JOIN rol_permisos rp ON rp.rol_id=u.rol_id
             JOIN permisos p ON p.id=rp.permiso_id
             WHERE u.id=:usuario AND u.void='1' AND p.modulo=:modulo AND p.accion=:accion
             LIMIT 1",
            [':usuario'=>$usuarioId, ':modulo'=>$module, ':accion'=>$action]
        );
        return !empty($rows);
    }

    private function rejectInactiveMobileReference(string $entity, string $id, bool $missing = false): void
    {
        $state = $missing ? 'ya no existe' : 'fue inactivado';
        service_return([
            'success'=>false,
            'title'=>'Catálogo desactualizado',
            'icon'=>'warning',
            'message'=>"El registro de {$entity} seleccionado {$state} en el servidor. Corrige o elimina este envío pendiente y después actualiza los datos.",
            'data'=>[
                'reason'=>$missing ? 'missing_reference' : 'inactive_reference',
                'entity'=>$entity,
                'id'=>$id,
                'requires_data_refresh'=>true,
                'can_discard'=>true,
            ],
        ]);
    }

    private function assertActiveMobileReference(string $table, string $id, string $entity): array
    {
        $allowed = ['fincas','lotes','cultivos','labores','categorias_labor','formulas','insumos'];
        if (!in_array($table, $allowed, true) || trim($id) === '') {
            $this->rejectInactiveMobileReference($entity, $id, true);
        }
        $rows = $this->model->queryPrepared("SELECT * FROM `{$table}` WHERE id=:id LIMIT 1", [':id'=>$id]);
        if (!$rows) $this->rejectInactiveMobileReference($entity, $id, true);
        if ((string)($rows[0]['voided'] ?? '0') !== '1') $this->rejectInactiveMobileReference($entity, $id, false);
        return $rows[0];
    }

    private function assertMobileCatalogNotReactivated(string $table, string $id, string $entity, array $data): void
    {
        if ($id === '' || (string)($data['voided'] ?? '1') === '0') return;
        $rows = $this->model->queryPrepared("SELECT voided FROM `{$table}` WHERE id=:id LIMIT 1", [':id'=>$id]);
        if ($rows && (string)$rows[0]['voided'] !== '1') $this->rejectInactiveMobileReference($entity, $id, false);
    }

    private function assertMobileLotUsable(string $lotId, string $cultivoId = ''): array
    {
        $lot = $this->assertActiveMobileReference('lotes', $lotId, 'lote');
        $this->assertActiveMobileReference('fincas', (string)($lot['finca_id'] ?? ''), 'finca');
        $serverCrop = trim((string)($lot['cultivo_id'] ?? ''));
        if ($serverCrop !== '') $this->assertActiveMobileReference('cultivos', $serverCrop, 'cultivo');
        if ($cultivoId !== '') $this->assertActiveMobileReference('cultivos', $cultivoId, 'cultivo');
        return $lot;
    }

    private function assertMobileLaborUsable(string $laborId): array
    {
        $labor = $this->assertActiveMobileReference('labores', $laborId, 'labor');
        $this->assertActiveMobileReference('cultivos', (string)($labor['cultivo_id'] ?? ''), 'cultivo');
        $categoryId = trim((string)($labor['categoria_labor_id'] ?? ''));
        if ($categoryId !== '') $this->assertActiveMobileReference('categorias_labor', $categoryId, 'categoría de labor');
        return $labor;
    }

    private function assertMobileFormulaUsable(string $formulaId): array
    {
        $formula = $this->assertActiveMobileReference('formulas', $formulaId, 'fórmula');
        $inactiveInputs = $this->model->queryPrepared(
            "SELECT i.id FROM formulas_detalle fd
             JOIN insumos i ON i.id=fd.insumo_id
             WHERE fd.formula_id=:formula AND fd.voided='1' AND i.voided<>'1' LIMIT 1",
            [':formula'=>$formulaId]
        );
        if ($inactiveInputs) $this->rejectInactiveMobileReference('insumo', (string)$inactiveInputs[0]['id'], false);
        return $formula;
    }

    public function authenticateMobileToken(string $token): ?string
    {
        if ($token === '') return null;
        $rows = $this->model->queryPrepared(
            "SELECT t.id,t.user_id
             FROM auth_tokens t JOIN `user` u ON u.id=t.user_id
             WHERE t.token_hash=:hash AND t.revoked_at IS NULL
               AND t.expires_at>:ahora AND u.void='1' LIMIT 1",
            [':hash'=>hash('sha256', $token), ':ahora'=>date('Y-m-d H:i:s')]
        );
        if (!$rows) return null;
        $this->model->executePrepared(
            "UPDATE auth_tokens SET last_used_at=:ahora WHERE id=:id",
            [':id'=>$rows[0]['id'], ':ahora'=>date('Y-m-d H:i:s')]
        );
        return (string)$rows[0]['user_id'];
    }

    public function logoutMobile(array $data): void
    {
        $token = trim((string)($data['api_token'] ?? ''));
        $usuarioId = trim((string)($data['authenticated_user_id'] ?? ''));
        if ($token === '' || $usuarioId === '') {
            service_return(['success'=>false,'message'=>'La sesión móvil no es válida.','data'=>[]]);
        }
        $this->model->executePrepared(
            "UPDATE auth_tokens
             SET revoked_at=:ahora
             WHERE token_hash=:hash AND user_id=:usuario AND revoked_at IS NULL",
            [
                ':ahora'=>date('Y-m-d H:i:s'),
                ':hash'=>hash('sha256', $token),
                ':usuario'=>$usuarioId,
            ]
        );
        service_return(['data'=>[],'message'=>'Sesión móvil cerrada correctamente.']);
    }

    public function canMobileMutation(string $usuarioId, string $method, array $data): bool
    {
        if (in_array($method, ['asignarFincaTecnico','saveAsignacionesFincasMobile'], true)) {
            return $this->mobileUserCan($usuarioId, 'tecnicos', 'asignar_fincas');
        }
        $catalogs = [
            'createFormula'=>['formulas','formulas'],
            'createDetalleFormula'=>['formulas','formulas_detalle'],
            'createInsumo'=>['insumos','insumos'],
            'createLabor'=>['labores','labores'],
            'createCategoriaLabor'=>['categorias_labor','categorias_labor'],
            'createCultivo'=>['cultivos','cultivos'],
            'createLote'=>['lotes','lotes'],
            'createFinca'=>['fincas','fincas'],
            'createRecomendacion'=>['recomendaciones','recomendaciones'],
        ];
        if (isset($catalogs[$method])) {
            [$module,$table] = $catalogs[$method];
            $id = trim((string)($data['id'] ?? ''));
            $exists = $id !== '' && !empty($this->model->queryPrepared(
                "SELECT id FROM `{$table}` WHERE id=:id LIMIT 1",
                [':id'=>$id]
            ));
            if (!$this->mobileUserCan($usuarioId, $module, $exists ? 'editar' : 'crear')) return false;
            if ($method === 'createFinca') {
                $tecnicoId = trim((string)($data['tecnico_id'] ?? ''));
                if ($tecnicoId !== '' && $tecnicoId !== $usuarioId) {
                    return $this->mobileUserCan($usuarioId, 'tecnicos', 'asignar_fincas');
                }
            }
            return true;
        }

        $visitMethods = [
            'createVisitaDetalleRecomendacion','createVisitaDetalleFormula',
            'createActividad','createHallazgo','createVisitaDetalleLote',
        ];
        if (in_array($method, $visitMethods, true)) {
            return $this->mobileUserCan($usuarioId, 'visitas', 'editar');
        }
        if ($method === 'createVisita') {
            $id = trim((string)($data['id'] ?? ''));
            $exists = $id !== '' && !empty($this->model->queryPrepared(
                "SELECT id FROM visitas_tecnicas WHERE id=:id LIMIT 1",
                [':id'=>$id]
            ));
            return $this->mobileUserCan($usuarioId, 'visitas', $exists ? 'editar' : 'crear');
        }
        if ($method === 'saveAgendaVisita') {
            $id = trim((string)($data['id'] ?? ''));
            $exists = $id !== '' && !empty($this->model->queryPrepared(
                "SELECT id FROM agenda_visitas WHERE id=:id LIMIT 1",
                [':id'=>$id]
            ));
            return $this->mobileUserCan($usuarioId, 'agenda', $exists ? 'editar' : 'crear');
        }
        return false;
    }

    public function validSystem($data)
    {
        $data_cliente = [
            'token' => uniqid(),
        ];

        service_return(['data' => $data_cliente, 'message' => 'El sistema se valida con éxito!']);
    }

    public function logoutWeb($data)
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            session_destroy();
        }
        service_return(['data'=>[],'message'=>'Sesión cerrada.']);
    }

    public function cambiarPassword($data)
    {

        $a = $this->model;
        $where['id'] = $data['id'];
        $sql_data = crearSentenciaSelect(['tabla' => 'user', 'where' => $where]);
        $data_select = $a->executeScript(['sql' => $sql_data]);
        $success = false;
        if (empty($data_select)) {
            $msg = "Usuario no existe o esta incorrecto";
        } else {

            $success = true;
            $data_query['code'] = $data['nueva_pass'];
            $data_query['password_hash'] = password_hash((string)$data['nueva_pass'], PASSWORD_DEFAULT);
            $data_query['pass_provi'] = '0';

            $data_query['user_modify'] = $data['id'];
            $data_query['date_modify'] = date("Y-m-d h:i:s");

            $data_where['id'] = $data['id'];

            $sql_mast = crearSentenciaUpdate(['tabla' => 'user', 'sets' => $data_query, 'where' => $data_where]);
            $a->executeScript([
                'sql' => $sql_mast
            ]);
            $msg = 'Password actualizado con éxito!';
        }


        service_return(['data' => $data['id'], 'message' => $msg, 'success' => $success]);
    }

    // =====================================================================
    // GESTIÓN DE TÉCNICOS (Solo Superusuario roll='S')
    // =====================================================================

    public function getTecnicos($data)
    {
        $a = $this->model;
        $usuario = trim((string)($data['usuario_id'] ?? ''));
        if (!$this->mobileUserCan($usuario, 'tecnicos', 'ver') &&
            !$this->mobileUserCan($usuario, 'agenda', 'ver_equipo')) {
            service_return(['success'=>false,'message'=>'No tienes permiso para consultar el equipo técnico.','data'=>[]]);
        }
        // Incluye tanto los técnicos creados desde la app móvil (roll='T')
        // como los usuarios creados desde el panel web con el sistema de
        // roles nuevo (roll='1' con un rol distinto de admin). Excluye
        // siempre a los superusuarios (roll='S' o rol admin), igual que
        // getEquipoTecnicoWeb en el panel web.
        $sql = "SELECT u.id, u.name, u.`user`, u.roll, u.void, u.mail
                FROM `user` u LEFT JOIN roles r ON r.id = u.rol_id
                WHERE u.void = '1' AND COALESCE(u.roll,'') <> 'S' AND COALESCE(r.codigo,'') <> 'admin'
                ORDER BY u.name ASC";
        $data_load = $a->executeScript(['sql' => $sql]);
        service_return(['data' => $data_load, 'message' => 'Listado de técnicos enviado con éxito!']);
    }

    public function crearTecnico($data)
    {
        $a = $this->model;

        if (empty($data['user']) || empty($data['name'])) {
            service_return(['success' => false, 'data' => [], 'message' => 'Usuario y nombre son requeridos']);
        }

        $where_check['user'] = $data['user'];
        $sql_check = crearSentenciaSelect(['tabla' => 'user', 'where' => $where_check]);
        $existing = $a->executeScript(['sql' => $sql_check]);

        if (!empty($existing)) {
            service_return(['success' => false, 'data' => [], 'message' => 'El nombre de usuario ya existe en el sistema']);
        }

        $temp_pass = (string)($data['code'] ?? random_int(1000, 9999));

        $new_user = [
            'id'          => $this->nextUserId(),
            'name'        => $data['name'],
            'user'        => $data['user'],
            'code'        => $temp_pass,
            'password_hash' => password_hash($temp_pass, PASSWORD_DEFAULT),
            'mail'        => $data['mail'] ?? '',
            'void'        => '1',
            'roll'        => 'T',
            'pass_provi'  => '1',
            'user_crea'   => $data['created_by'] ?? '',
            'date_crea'   => date('Y-m-d H:i:s'),
        ];

        $sql = crearSentenciaInsert(['tabla' => 'user', 'conten' => $new_user]);
        $a->executeScript(['sql' => $sql]);

        $new_user['temp_pass'] = $temp_pass;
        service_return(['data' => $new_user, 'message' => 'Técnico creado con éxito!']);
    }

    public function editarTecnico($data)
    {
        $a = $this->model;

        if (empty($data['id'])) {
            service_return(['success' => false, 'data' => [], 'message' => 'ID del técnico es requerido']);
        }

        $sets = [];
        if (!empty($data['name']))  $sets['name'] = $data['name'];
        if (!empty($data['mail']))  $sets['mail'] = $data['mail'];
        if (isset($data['void']))   $sets['void'] = $data['void'];

        $sets['user_modify'] = $data['modified_by'] ?? '';
        $sets['date_modify']  = date('Y-m-d H:i:s');

        $where['id'] = $data['id'];
        $sql = crearSentenciaUpdate(['tabla' => 'user', 'sets' => $sets, 'where' => $where]);
        $a->executeScript(['sql' => $sql]);

        service_return(['data' => $data['id'], 'message' => 'Técnico actualizado con éxito!']);
    }

    public function asignarFincaTecnico($data)
    {
        $a = $this->model;

        $finca_id   = $data['finca_id'] ?? '';
        $tecnico_id = $data['tecnico_id'] ?? '';

        if (empty($finca_id) || empty($tecnico_id)) {
            service_return(['success' => false, 'data' => [], 'message' => 'Finca y técnico son requeridos']);
        }

        $a->executePrepared(
            "INSERT IGNORE INTO usuario_fincas(usuario_id,finca_id,created_by) VALUES(:usuario,:finca,:actor)",
            [':usuario'=>$tecnico_id,':finca'=>$finca_id,':actor'=>$data['created_by'] ?? $tecnico_id]
        );

        service_return(['data' => $finca_id, 'message' => 'Finca asignada al técnico con éxito!']);
    }

    public function getAsignacionesFincasMobile($data)
    {
        $tecnicoId = trim((string)($data['tecnico_id'] ?? ''));
        if ($tecnicoId === '') {
            service_return(['success'=>false,'message'=>'Debes seleccionar un técnico.','data'=>[]]);
        }
        $rows = $this->model->queryPrepared(
            "SELECT f.id,f.descripcion,COALESCE(f.ubicacion,'') AS ubicacion,
                    CASE WHEN uf.finca_id IS NULL THEN 0 ELSE 1 END AS asignada
             FROM fincas f
             LEFT JOIN usuario_fincas uf
               ON uf.finca_id=f.id AND uf.usuario_id=:usuario
             WHERE f.voided='1'
             ORDER BY f.descripcion",
            [':usuario'=>$tecnicoId]
        );
        service_return([
            'data'=>$rows,
            'message'=>'Fincas y asignaciones consultadas con éxito.'
        ]);
    }

    public function saveAsignacionesFincasMobile($data)
    {
        $tecnicoId = trim((string)($data['tecnico_id'] ?? ''));
        $fincaIds = array_values(array_unique(array_filter(array_map(
            'strval',
            is_array($data['finca_ids'] ?? null) ? $data['finca_ids'] : []
        ))));
        if ($tecnicoId === '') {
            service_return(['success'=>false,'message'=>'Debes seleccionar un técnico.','data'=>[]]);
        }
        $tecnico = $this->model->queryPrepared(
            "SELECT id FROM `user` WHERE id=:id AND void='1' LIMIT 1",
            [':id'=>$tecnicoId]
        );
        if (!$tecnico) {
            service_return(['success'=>false,'message'=>'El técnico no existe o está inactivo.','data'=>[]]);
        }
        if ($fincaIds) {
            $placeholders = [];
            $params = [];
            foreach ($fincaIds as $index=>$fincaId) {
                $key = ':finca_'.$index;
                $placeholders[] = $key;
                $params[$key] = $fincaId;
            }
            $validas = $this->model->queryPrepared(
                "SELECT id FROM fincas WHERE voided='1' AND id IN (".implode(',', $placeholders).")",
                $params
            );
            if (count($validas) !== count($fincaIds)) {
                service_return(['success'=>false,'message'=>'Una o más fincas ya no están disponibles. Actualiza los datos.','data'=>[]]);
            }
        }

        $this->model->beginTransaction();
        try {
            $this->model->executePrepared(
                "DELETE FROM usuario_fincas WHERE usuario_id=:usuario",
                [':usuario'=>$tecnicoId]
            );
            foreach ($fincaIds as $fincaId) {
                $this->model->executePrepared(
                    "INSERT INTO usuario_fincas(usuario_id,finca_id,created_by)
                     VALUES(:usuario,:finca,:actor)",
                    [
                        ':usuario'=>$tecnicoId,
                        ':finca'=>$fincaId,
                        ':actor'=>(string)($data['created_by'] ?? ''),
                    ]
                );
            }
            $this->model->commit();
        } catch (Throwable $e) {
            $this->model->rollBack();
            throw $e;
        }
        service_return([
            'data'=>['tecnico_id'=>$tecnicoId,'total'=>count($fincaIds)],
            'message'=>'Asignaciones guardadas correctamente.'
        ]);
    }

    public function getVisitasPorTecnico($data)
    {
        $a = $this->model;
        $roll = $data['roll'] ?? '';

        if ($roll !== 'S') {
            service_return(['success' => false, 'data' => [], 'message' => 'Acceso no autorizado']);
        }

        // Resumen agregado por técnico: fincas cubiertas, visitas del mes,
        // visitas totales y última visita. Misma lógica de getEquipoTecnicoWeb,
        // adaptada al patrón de autenticación por roll explícito (sin sesión web).
        $tecnicos = $a->queryPrepared(
            "SELECT u.id, u.name, u.`user`,
                    COUNT(DISTINCT uf.finca_id) AS total_fincas,
                    COUNT(DISTINCT CASE WHEN vt.voided='1' THEN vt.id END) AS total_visitas,
                    COUNT(DISTINCT CASE WHEN vt.voided='1' AND vt.fecha>=DATE_FORMAT(CURDATE(),'%Y-%m-01') THEN vt.id END) AS visitas_mes,
                    MAX(CASE WHEN vt.voided='1' THEN vt.fecha END) AS ultima_visita
             FROM `user` u
             LEFT JOIN roles r ON r.id=u.rol_id
             LEFT JOIN usuario_fincas uf ON uf.usuario_id=u.id
             LEFT JOIN visitas_tecnicas vt ON vt.created_by=u.id
             WHERE COALESCE(r.codigo,'')<>'admin' AND COALESCE(u.roll,'')<>'S' AND u.void='1'
             GROUP BY u.id, u.name, u.`user`
             ORDER BY total_visitas DESC, u.name ASC"
        );

        // Fincas asignadas por técnico, para poder mostrarlas en el detalle.
        $fincas_por_tecnico = $a->queryPrepared(
            "SELECT uf.usuario_id AS tecnico_id, f.id AS finca_id, f.descripcion AS finca_nombre
             FROM usuario_fincas uf
             JOIN fincas f ON f.id = uf.finca_id
             JOIN `user` u ON u.id = uf.usuario_id
             LEFT JOIN roles r ON r.id = u.rol_id
             WHERE COALESCE(r.codigo,'')<>'admin' AND COALESCE(u.roll,'')<>'S'
             ORDER BY f.descripcion ASC"
        );

        // Listado de visitas con total_lotes por visita (igual que getResumenWeb/getEquipoTecnicoWeb).
        $visitas = $a->queryPrepared(
            "SELECT vt.id, vt.finca_id, vt.fecha, vt.descripcion, vt.observacion,
                    vt.sync, vt.voided, vt.created_at, vt.updated_at, vt.created_by,
                    vt.created_by AS tecnico_id,
                    COALESCE(u.name, vt.created_by) AS tecnico_nombre,
                    u.user AS tecnico_usuario,
                    f.descripcion AS finca_nombre,
                    COUNT(DISTINCT vdl.id) AS total_lotes
             FROM visitas_tecnicas vt
             LEFT JOIN `user` u ON vt.created_by = u.id
             LEFT JOIN fincas f ON vt.finca_id = f.id
             LEFT JOIN visita_detalles_lote vdl ON vdl.visita_id = vt.id AND vdl.voided = '1'
             WHERE vt.voided = '1'
             GROUP BY vt.id, vt.finca_id, vt.fecha, vt.descripcion, vt.observacion,
                      vt.sync, vt.voided, vt.created_at, vt.updated_at, vt.created_by,
                      u.name, u.user, f.descripcion
             ORDER BY u.name ASC, vt.fecha DESC"
        );

        service_return(['data' => [
            'tecnicos' => $tecnicos,
            'visitas' => $visitas,
            'fincas_por_tecnico' => $fincas_por_tecnico,
        ], 'message' => 'Visitas por técnico enviadas con éxito!']);
    }

    public function getEquipoTecnicoWeb($data)
    {
        $tecnicos = $this->model->queryPrepared(
            "SELECT u.id,u.name,u.`user`,u.mail,u.void,u.ultimo_acceso,
                    r.nombre AS rol_nombre,r.codigo AS rol_codigo,
                    COUNT(DISTINCT uf.finca_id) AS total_fincas,
                    COUNT(DISTINCT CASE WHEN vt.voided='1' THEN vt.id END) AS total_visitas,
                    COUNT(DISTINCT CASE WHEN vt.voided='1' AND vt.fecha>=DATE_FORMAT(CURDATE(),'%Y-%m-01') THEN vt.id END) AS visitas_mes,
                    MAX(CASE WHEN vt.voided='1' THEN vt.fecha END) AS ultima_visita
             FROM `user` u
             LEFT JOIN roles r ON r.id=u.rol_id
             LEFT JOIN usuario_fincas uf ON uf.usuario_id=u.id
             LEFT JOIN visitas_tecnicas vt ON vt.created_by=u.id
             WHERE COALESCE(r.codigo,'')<>'admin' AND COALESCE(u.roll,'')<>'S'
             GROUP BY u.id,u.name,u.`user`,u.mail,u.void,u.ultimo_acceso,r.nombre,r.codigo
             ORDER BY u.void DESC,u.name"
        );
        $fincas = $this->model->queryPrepared(
            "SELECT uf.usuario_id,f.id,f.descripcion AS nombre,f.ubicacion,f.voided
             FROM usuario_fincas uf
             JOIN fincas f ON f.id=uf.finca_id
             JOIN `user` u ON u.id=uf.usuario_id
             LEFT JOIN roles r ON r.id=u.rol_id
             WHERE COALESCE(r.codigo,'')<>'admin' AND COALESCE(u.roll,'')<>'S'
             ORDER BY f.descripcion"
        );
        $visitas = $this->model->queryPrepared(
            "SELECT vt.id,vt.created_by AS tecnico_id,vt.fecha,vt.descripcion,vt.observacion,vt.voided,
                    vt.updated_at,f.descripcion AS finca_nombre
             FROM visitas_tecnicas vt
             LEFT JOIN fincas f ON f.id=vt.finca_id
             JOIN `user` u ON u.id=vt.created_by
             LEFT JOIN roles r ON r.id=u.rol_id
             WHERE COALESCE(r.codigo,'')<>'admin' AND COALESCE(u.roll,'')<>'S'
             ORDER BY vt.fecha DESC,vt.updated_at DESC"
        );
        $totalFincas = $this->model->queryPrepared("SELECT COUNT(*) AS total FROM fincas WHERE voided='1'");
        $fincasCubiertas = $this->model->queryPrepared(
            "SELECT COUNT(DISTINCT uf.finca_id) AS total
             FROM usuario_fincas uf
             JOIN fincas f ON f.id=uf.finca_id AND f.voided='1'
             JOIN `user` u ON u.id=uf.usuario_id
             LEFT JOIN roles r ON r.id=u.rol_id
             WHERE COALESCE(r.codigo,'')<>'admin' AND COALESCE(u.roll,'')<>'S'"
        );
        service_return(['data'=>[
            'tecnicos'=>$tecnicos,
            'fincas'=>$fincas,
            'visitas'=>$visitas,
            'metricas'=>[
                'total_fincas'=>(int)($totalFincas[0]['total'] ?? 0),
                'fincas_cubiertas'=>(int)($fincasCubiertas[0]['total'] ?? 0),
            ],
        ],'message'=>'Equipo técnico consultado con éxito.']);
    }

    // =====================================================================
    // CONFIGURACIÓN
    // =====================================================================

    public function createConfiguracion($data)
    {

        $a = $this->model;
        // El móvil manda siempre el mismo id literal ('configuracion_actual')
        // para su única fila local, sin importar qué técnico esté logueado
        // — no sirve para identificar la fila en el servidor, donde debe
        // haber una por técnico. La identidad confiable es created_by (fijado
        // arriba en router.php desde el token autenticado, nunca desde el
        // JSON del dispositivo), así que se busca y guarda por usuario_id
        // usando ese valor en vez del id que mandó el cliente.
        $where['usuario_id'] = $data['created_by'];
        $sql_data = crearSentenciaSelect(['tabla' => 'configuracion_usuario', 'where' => $where]);
        $data_select = $a->executeScript(['sql' => $sql_data]);

        if (empty($data_select)) {
            $data['id'] = $data['created_by'];
            $data['usuario_id'] = $data['created_by'];
            $data['sync'] = '1';
            $sql = crearSentenciaInsert(['tabla' => 'configuracion_usuario', 'conten' => $data]);
            $a->executeScript(['sql' => $sql]);
            $msg = 'Las configuraciones creó con éxito!';
        } else {

            $data_query['nombre'] = $data['nombre'];
            $data_query['titulo'] = $data['titulo'];
            $data_query['tarjeta_profesional'] = $data['tarjeta_profesional'];
            $data_query['celular'] = $data['celular'];
            $data_query['firma_base64'] = $data['firma_base64'];

            $data_query['sync'] = '1';
            $data_query['voided'] = $data['voided'];
            $data_query['created_at'] = $data['created_at'];
            $data_query['updated_at'] = $data['updated_at'];
            $data_query['created_by'] = $data['created_by'];
            $data_query['usuario_id'] = $data['created_by'];

            $data_where['usuario_id'] = $data['created_by'];

            $sql_mast = crearSentenciaUpdate(['tabla' => 'configuracion_usuario', 'sets' => $data_query, 'where' => $data_where]);
            $a->executeScript([
                'sql' => $sql_mast
            ]);
            $msg = 'Las configuraciones editó con éxito!';
        }


        service_return(['data' => $data['id'], 'message' => $msg]);
    }

    public function descargarConfiguracion()
    {

        $a = $this->model;
        $where['voided'] = '1';
        $sql_ = crearSentenciaSelect(['tabla' => 'configuracion_usuario', 'where' => $where]);
        $data_load = $a->executeScript(['sql' => $sql_]);

        service_return(['data' => $data_load, 'message' => 'El listado de configuraciones se envia con éxito!']);
    }

    // =====================================================================
    // VISITAS TÉCNICAS
    // =====================================================================

    public function createVisitaDetalleRecomendacion($data)
    {

        $a = $this->model;
        $where['id'] = $data['id'];
        $sql_data = crearSentenciaSelect(['tabla' => 'visita_detalles_recomendaciones', 'where' => $where]);
        $data_select = $a->executeScript(['sql' => $sql_data]);

        if (empty($data_select)) {
            $data['sync'] = '1';
            $sql = crearSentenciaInsert(['tabla' => 'visita_detalles_recomendaciones', 'conten' => $data]);
            $a->executeScript(['sql' => $sql]);
            $msg = 'La recomendacion de visita por lote se creó con éxito!';
        } else {

            $data_query['visita_id'] = $data['visita_id'];
            $data_query['visita_detalle_id'] = $data['visita_detalle_id'];
            $data_query['recomendacion_id'] = $data['recomendacion_id'];
            $data_query['texto'] = $data['texto'];

            $data_query['sync'] = '1';
            $data_query['voided'] = $data['voided'];
            $data_query['created_at'] = $data['created_at'];
            $data_query['updated_at'] = $data['updated_at'];
            $data_query['created_by'] = $data['created_by'];

            $data_where['id'] = $data['id'];

            $sql_mast = crearSentenciaUpdate(['tabla' => 'visita_detalles_recomendaciones', 'sets' => $data_query, 'where' => $data_where]);
            $a->executeScript([
                'sql' => $sql_mast
            ]);
            $msg = 'La recomendacion de visita por lote se editó con éxito!';
        }


        service_return(['data' => $data['id'], 'message' => $msg]);
    }

    public function descargarVisitaDetallesRecomendaciones($data = [])
    {
        $a = $this->model;
        $roll = $data['roll'] ?? '';
        $usuarioId = $data['usuario_id'] ?? '';
        $fechaDesde = $this->fechaDesdeMobile($data);

        if ($roll === 'S') {
            $data_load = $a->queryPrepared(
                "SELECT vdr.* FROM visita_detalles_recomendaciones vdr
                 JOIN visitas_tecnicas vt ON vt.id=vdr.visita_id
                 WHERE vdr.voided='1' AND vt.fecha>=:fecha_desde",
                [':fecha_desde'=>$fechaDesde]
            );
        } else {
            $data_load = $a->queryPrepared(
                "SELECT vdr.* FROM visita_detalles_recomendaciones vdr
                 JOIN visitas_tecnicas vt ON vt.id = vdr.visita_id
                 WHERE vdr.voided='1' AND vt.created_by=:usuario AND vt.fecha>=:fecha_desde",
                [':usuario'=>$usuarioId, ':fecha_desde'=>$fechaDesde]
            );
        }

        service_return(['data' => $data_load, 'message' => 'El listado de recomendaciones de visita por lote se envia con éxito!']);
    }

    public function createVisitaDetalleFormula($data)
    {

        $a = $this->model;
        $formulaId = trim((string)($data['formula_id'] ?? ''));
        $insumoId = trim((string)($data['insumo_id'] ?? ''));
        if ((string)($data['voided'] ?? '1') !== '0') {
            if ($formulaId !== '') $this->assertMobileFormulaUsable($formulaId);
            if ($insumoId !== '') $this->assertActiveMobileReference('insumos', $insumoId, 'insumo');
        }
        $where['id'] = $data['id'];
        $sql_data = crearSentenciaSelect(['tabla' => 'visita_detalles_formulas', 'where' => $where]);
        $data_select = $a->executeScript(['sql' => $sql_data]);

        if (empty($data_select)) {
            $data['sync'] = '1';
            $sql = crearSentenciaInsert(['tabla' => 'visita_detalles_formulas', 'conten' => $data]);
            $a->executeScript(['sql' => $sql]);
            $msg = 'La formula de visita por lote se creó con éxito!';
        } else {

            $data_query['visita_id'] = $data['visita_id'];
            $data_query['visita_detalle_id'] = $data['visita_detalle_id'];
            $data_query['formula_id'] = $data['formula_id'];
            $data_query['group_id'] = $data['group_id'];
            $data_query['insumo_id'] = $data['insumo_id'];
            $data_query['dosis'] = $data['dosis'];
            $data_query['unidad'] = $data['unidad'];
            $data_query['obs_insumo'] = $data['obs_insumo'];
            $data_query['obs_global'] = $data['obs_global'];
            $data_query['es_header'] = $data['es_header'];

            $data_query['sync'] = '1';
            $data_query['voided'] = $data['voided'];
            $data_query['created_at'] = $data['created_at'];
            $data_query['updated_at'] = $data['updated_at'];
            $data_query['created_by'] = $data['created_by'];

            $data_where['id'] = $data['id'];

            $sql_mast = crearSentenciaUpdate(['tabla' => 'visita_detalles_formulas', 'sets' => $data_query, 'where' => $data_where]);

            $a->executeScript([
                'sql' => $sql_mast
            ]);
            $msg = 'La formula de visita por lote se editó con éxito!';
        }


        service_return(['data' => $data['id'], 'message' => $msg]);
    }

    function decodeNewlines($row): array
    {
        foreach ($row as $k => $v) {
            if (is_string($v)) {
                $v = str_replace(["\r\n", "\r"], "\n", $v);
                $v = str_replace('\\n', "\n", $v);
                $row[$k] = $v;
            }
        }
        return $row;
    }

    public function descargarVisitaDetallesFormulas($data = [])
    {
        $a = $this->model;
        $roll = $data['roll'] ?? '';
        $usuarioId = $data['usuario_id'] ?? '';
        $fechaDesde = $this->fechaDesdeMobile($data);

        if ($roll === 'S') {
            $rows = $a->queryPrepared(
                "SELECT vdf.* FROM visita_detalles_formulas vdf
                 JOIN visitas_tecnicas vt ON vt.id=vdf.visita_id
                 WHERE vdf.voided='1' AND vt.fecha>=:fecha_desde",
                [':fecha_desde'=>$fechaDesde]
            );
        } else {
            $rows = $a->queryPrepared(
                "SELECT vdf.* FROM visita_detalles_formulas vdf
                 JOIN visitas_tecnicas vt ON vt.id = vdf.visita_id
                 WHERE vdf.voided='1' AND vt.created_by=:usuario AND vt.fecha>=:fecha_desde",
                [':usuario'=>$usuarioId, ':fecha_desde'=>$fechaDesde]
            );
        }
        $data_select = array_map([$this, 'decodeNewlines'], $rows);
        service_return(['data' => $data_select, 'message' => 'El listado de formulas de visita por lote se envia con éxito!']);
    }

    public function createActividad($data)
    {

        $a = $this->model;
        if ((string)($data['voided'] ?? '1') !== '0') $this->assertMobileLaborUsable(trim((string)($data['labor_id'] ?? '')));
        $where['id'] = $data['id'];
        $sql_data = crearSentenciaSelect(['tabla' => 'visita_detalles_actividades', 'where' => $where]);

        $data_select = $a->executeScript(['sql' => $sql_data]);

        if (empty($data_select)) {
            $data['sync'] = '1';
            $sql = crearSentenciaInsert(['tabla' => 'visita_detalles_actividades', 'conten' => $data]);
            $a->executeScript(['sql' => $sql]);
            $msg = 'La actividad de visita por lote se creó con éxito!';
        } else {

            $data_query['visita_id'] = $data['visita_id'];
            $data_query['visita_detalle_id'] = $data['visita_detalle_id'];
            $data_query['labor_id'] = $data['labor_id'];
            $data_query['estado'] = $data['estado'];
            $data_query['sync'] = '1';
            $data_query['voided'] = $data['voided'];
            $data_query['created_at'] = $data['created_at'];
            $data_query['updated_at'] = $data['updated_at'];
            $data_query['created_by'] = $data['created_by'];

            $data_where['id'] = $data['id'];

            $sql_mast = crearSentenciaUpdate(['tabla' => 'visita_detalles_actividades', 'sets' => $data_query, 'where' => $data_where]);
            $a->executeScript([
                'sql' => $sql_mast
            ]);
            $msg = 'La actividad de visita por lote se editó con éxito!';
        }


        service_return(['data' => $data['id'], 'message' => $msg]);
    }

    public function descargarActividades($data = [])
    {
        $a = $this->model;
        $roll = $data['roll'] ?? '';
        $usuarioId = $data['usuario_id'] ?? '';
        $fechaDesde = $this->fechaDesdeMobile($data);

        if ($roll === 'S') {
            $data_load = $a->queryPrepared(
                "SELECT vda.* FROM visita_detalles_actividades vda
                 JOIN visitas_tecnicas vt ON vt.id=vda.visita_id
                 WHERE vda.voided='1' AND vt.fecha>=:fecha_desde",
                [':fecha_desde'=>$fechaDesde]
            );
        } else {
            $data_load = $a->queryPrepared(
                "SELECT vda.* FROM visita_detalles_actividades vda
                 JOIN visitas_tecnicas vt ON vt.id = vda.visita_id
                 WHERE vda.voided='1' AND vt.created_by=:usuario AND vt.fecha>=:fecha_desde",
                [':usuario'=>$usuarioId, ':fecha_desde'=>$fechaDesde]
            );
        }

        service_return(['data' => $data_load, 'message' => 'El listado de actividades de visita por lote se envia con éxito!']);
    }

    public function createHallazgo($data)
    {

        $a = $this->model;
        $where['id'] = $data['id'];
        $sql_data = crearSentenciaSelect(['tabla' => 'visita_detalles_hallazgos', 'where' => $where]);

        $data_select = $a->executeScript(['sql' => $sql_data]);

        if (empty($data_select)) {

            if (!empty($data['imagen_base64']) && !empty($data['imagen_nombre'])) {
                $base64 = $data['imagen_base64'];
                $nombre = $data['imagen_nombre'];
                $rutaDestino = "uploads/hallazgos/" . $nombre;
                file_put_contents($rutaDestino, base64_decode($base64));

                $data['imagen_path_srv'] = $rutaDestino;
                unset($data['imagen_base64']);
                unset($data['imagen_nombre']);
            }

            $data['sync'] = '1';
            $sql = crearSentenciaInsert(['tabla' => 'visita_detalles_hallazgos', 'conten' => $data]);
            $a->executeScript(['sql' => $sql]);
            $msg = 'Hallazgos se creó con éxito!';
        } else {


            if (!empty($data['imagen_base64']) && !empty($data['imagen_nombre'])) {
                $base64 = $data['imagen_base64'];
                $nombre = $data['imagen_nombre'];
                $rutaDestino = "uploads/hallazgos/" . $nombre;
                file_put_contents($rutaDestino, base64_decode($base64));

                $data_query['imagen_path_srv'] = $rutaDestino;
                unset($data['imagen_base64']);
                unset($data['imagen_nombre']);
            }

            $data_query['visita_id'] = $data['visita_id'];
            $data_query['visita_detalle_id'] = $data['visita_detalle_id'];
            $data_query['descripcion'] = $data['descripcion'];
            $data_query['imagen_path'] = $data['imagen_path'];

            $data_query['sync'] = '1';
            $data_query['voided'] = $data['voided'];
            $data_query['created_at'] = $data['created_at'];
            $data_query['updated_at'] = $data['updated_at'];
            $data_query['created_by'] = $data['created_by'];

            $data_where['id'] = $data['id'];

            $sql_mast = crearSentenciaUpdate(['tabla' => 'visita_detalles_hallazgos', 'sets' => $data_query, 'where' => $data_where]);
            $a->executeScript([
                'sql' => $sql_mast
            ]);
            $msg = 'Hallazgos se editó con éxito!';
        }


        service_return(['data' => $data['id'], 'message' => $msg]);
    }

    public function descargarHallazgos($data = [])
    {
        $a = $this->model;
        $roll = $data['roll'] ?? '';
        $usuarioId = $data['usuario_id'] ?? '';
        $fechaDesde = $this->fechaDesdeMobile($data);

        if ($roll === 'S') {
            $data_load = $a->queryPrepared(
                "SELECT vdh.* FROM visita_detalles_hallazgos vdh
                 JOIN visitas_tecnicas vt ON vt.id=vdh.visita_id
                 WHERE vt.fecha>=:fecha_desde",
                [':fecha_desde'=>$fechaDesde]
            );
        } else {
            $data_load = $a->queryPrepared(
                "SELECT vdh.* FROM visita_detalles_hallazgos vdh
                 JOIN visitas_tecnicas vt ON vt.id = vdh.visita_id
                 WHERE vt.created_by=:usuario AND vt.fecha>=:fecha_desde",
                [':usuario'=>$usuarioId, ':fecha_desde'=>$fechaDesde]
            );
        }
        foreach ($data_load as $key => $value) {
            if (!empty($value['imagen_path_srv']) && file_exists($value['imagen_path_srv'])) {
                $contenido = file_get_contents($value['imagen_path_srv']);
                $data_load[$key]['imagen_base64'] = base64_encode($contenido);
            } else {
                $data_load[$key]['imagen_base64'] = null;
            }
        }
        service_return(['data' => $data_load, 'message' => 'El listado de hallazgos se envia con éxito!']);
    }

    public function createVisitaDetalleLote($data)
    {

        $a = $this->model;
        if ((string)($data['voided'] ?? '1') !== '0') {
            $this->assertMobileLotUsable(
                trim((string)($data['lote_id'] ?? '')),
                trim((string)($data['cultivo_id'] ?? ''))
            );
        }
        $where['id'] = $data['id'];
        $sql_data = crearSentenciaSelect(['tabla' => 'visita_detalles_lote', 'where' => $where]);

        $data_select = $a->executeScript(['sql' => $sql_data]);

        if (empty($data_select)) {
            $data['sync'] = '1';
            $sql = crearSentenciaInsert(['tabla' => 'visita_detalles_lote', 'conten' => $data]);
            $a->executeScript(['sql' => $sql]);
            $msg = 'El detalle de visita por lote se creó con éxito!';
        } else {

            $data_query['visita_id'] = $data['visita_id'];
            $data_query['lote_id'] = $data['lote_id'];
            $data_query['cultivo_id'] = $data['cultivo_id'];
            $data_query['observacion'] = $data['observacion'];
            $data_query['sync'] = '1';
            $data_query['voided'] = $data['voided'];
            $data_query['created_at'] = $data['created_at'];
            $data_query['updated_at'] = $data['updated_at'];
            $data_query['created_by'] = $data['created_by'];

            $data_where['id'] = $data['id'];

            $sql_mast = crearSentenciaUpdate(['tabla' => 'visita_detalles_lote', 'sets' => $data_query, 'where' => $data_where]);
            $a->executeScript([
                'sql' => $sql_mast
            ]);
            $msg = 'El detalle de visita por lote se editó con éxito!';
        }


        service_return(['data' => $data['id'], 'message' => $msg]);
    }

    public function getVisitaDetalleLote($data = [])
    {
        $a = $this->model;
        $roll = $data['roll'] ?? '';
        $usuarioId = $data['usuario_id'] ?? '';
        $fechaDesde = $this->fechaDesdeMobile($data);

        if ($roll === 'S') {
            $data_load = $a->queryPrepared(
                "SELECT vdl.* FROM visita_detalles_lote vdl
                 JOIN visitas_tecnicas vt ON vt.id=vdl.visita_id
                 WHERE vt.fecha>=:fecha_desde",
                [':fecha_desde'=>$fechaDesde]
            );
        } else {
            $data_load = $a->queryPrepared(
                "SELECT vdl.* FROM visita_detalles_lote vdl
                 JOIN visitas_tecnicas vt ON vt.id = vdl.visita_id
                 WHERE vt.created_by=:usuario AND vt.fecha>=:fecha_desde",
                [':usuario'=>$usuarioId, ':fecha_desde'=>$fechaDesde]
            );
        }

        service_return(['data' => $data_load, 'message' => 'El listado de detalle de visita por lote se envia con éxito!']);
    }

    public function createVisita($data)
    {

        $a = $this->model;
        if ((string)($data['voided'] ?? '1') !== '0') $this->assertActiveMobileReference('fincas', trim((string)($data['finca_id'] ?? '')), 'finca');
        $where['id'] = $data['id'];
        $sql_data = crearSentenciaSelect(['tabla' => 'visitas_tecnicas', 'where' => $where]);
        $data_select = $a->executeScript(['sql' => $sql_data]);

        if (empty($data_select)) {
            $data['sync'] = '1';
            $sql = crearSentenciaInsert(['tabla' => 'visitas_tecnicas', 'conten' => $data]);
            $a->executeScript(['sql' => $sql]);
            $msg = 'La visita se creó con éxito!';
        } else {

            $data_query['finca_id'] = $data['finca_id'];
            $data_query['fecha'] = $data['fecha'];
            $data_query['descripcion'] = $data['descripcion'];
            $data_query['observacion'] = $data['observacion'];
            $data_query['sync'] = '1';
            $data_query['voided'] = $data['voided'];
            $data_query['created_at'] = $data['created_at'];
            $data_query['updated_at'] = $data['updated_at'];
            $data_query['created_by'] = $data['created_by'];

            $data_where['id'] = $data['id'];

            $sql_mast = crearSentenciaUpdate(['tabla' => 'visitas_tecnicas', 'sets' => $data_query, 'where' => $data_where]);
            $a->executeScript([
                'sql' => $sql_mast
            ]);
            $msg = 'La visita se editó con éxito!';
        }


        service_return(['data' => $data['id'], 'message' => $msg]);
    }

    public function getVisitas($data)
    {
        $a = $this->model;
        $usuario_id = trim((string)($data['usuario_id'] ?? ''));
        $fechaDesde = $this->fechaDesdeMobile($data);

        if ($this->mobileUserIsAdmin($usuario_id)) {
            $data_load = $a->queryPrepared(
                "SELECT vt.* FROM visitas_tecnicas vt
                 WHERE vt.voided='1' AND vt.fecha>=:fecha_desde ORDER BY vt.fecha DESC",
                [':fecha_desde'=>$fechaDesde]
            );
        } else {
            $data_load = $a->queryPrepared(
                "SELECT DISTINCT vt.* FROM visitas_tecnicas vt
                 JOIN usuario_fincas uf ON uf.finca_id=vt.finca_id
                 WHERE uf.usuario_id=:usuario AND vt.voided='1' AND vt.fecha>=:fecha_desde
                 ORDER BY vt.fecha DESC",
                [':usuario'=>$usuario_id, ':fecha_desde'=>$fechaDesde]
            );
        }

        service_return(['data' => $data_load, 'message' => 'El listado de visitas tecnicas se envia con éxito!']);
    }

    public function saveAgendaVisita($data)
    {
        $required = ['id','finca_id','usuario_id','fecha_inicio','objetivo','estado','created_at','updated_at','created_by'];
        foreach ($required as $field) {
            if (!isset($data[$field]) || trim((string)$data[$field]) === '') {
                service_return(['success'=>false,'message'=>'Falta el campo '.$field,'data'=>[]]);
            }
        }
        $allowed = ['PROGRAMADA','EN_CURSO','COMPLETADA','CANCELADA'];
        if (!in_array($data['estado'], $allowed, true)) {
            service_return(['success'=>false,'message'=>'Estado de agenda no válido.','data'=>[]]);
        }
        $exists = $this->model->queryPrepared(
            "SELECT id FROM agenda_visitas WHERE id=:id LIMIT 1",
            [':id'=>$data['id']]
        );
        $params = [
            ':id'=>$data['id'], ':finca'=>$data['finca_id'], ':usuario'=>$data['usuario_id'],
            ':visita'=>($data['visita_id'] ?? null) ?: null, ':fecha'=>$data['fecha_inicio'],
            ':duracion'=>(int)($data['duracion_minutos'] ?? 60), ':objetivo'=>$data['objetivo'],
            ':observacion'=>$data['observacion'] ?? null, ':estado'=>$data['estado'],
            ':voided'=>$data['voided'] ?? '1', ':created'=>$data['created_at'],
            ':updated'=>$data['updated_at'], ':actor'=>$data['created_by']
        ];
        if (empty($exists)) {
            $this->model->executePrepared(
                "INSERT INTO agenda_visitas(id,finca_id,usuario_id,visita_id,fecha_inicio,duracion_minutos,objetivo,observacion,estado,sync,voided,created_at,updated_at,created_by)
                 VALUES(:id,:finca,:usuario,:visita,:fecha,:duracion,:objetivo,:observacion,:estado,'1',:voided,:created,:updated,:actor)", $params
            );
        } else {
            $this->model->executePrepared(
                "UPDATE agenda_visitas SET finca_id=:finca,usuario_id=:usuario,visita_id=:visita,fecha_inicio=:fecha,duracion_minutos=:duracion,objetivo=:objetivo,observacion=:observacion,estado=:estado,sync='1',voided=:voided,created_at=:created,updated_at=:updated,created_by=:actor WHERE id=:id",
                $params
            );
        }
        service_return(['data'=>$data['id'],'message'=>'Programación guardada correctamente.']);
    }

    public function getAgendaVisitas($data)
    {
        $usuario = trim((string)($data['usuario_id'] ?? ''));
        $tecnico = trim((string)($data['tecnico_id'] ?? ''));
        $fechaDesde = $this->fechaDesdeMobile($data);
        if ($tecnico !== '' && $tecnico !== $usuario && !$this->mobileUserCan($usuario, 'agenda', 'ver_equipo')) {
            service_return(['success'=>false,'message'=>'No tienes permiso para consultar la agenda de otros técnicos.','data'=>[]]);
        }
        if ($tecnico !== '') {
            $rows = $this->model->queryPrepared(
                "SELECT a.*,f.descripcion AS finca_nombre FROM agenda_visitas a
                 LEFT JOIN fincas f ON f.id=a.finca_id
                 WHERE a.voided='1' AND a.usuario_id=:tecnico AND a.fecha_inicio>=:fecha_desde
                 ORDER BY a.fecha_inicio",
                [':tecnico'=>$tecnico, ':fecha_desde'=>$fechaDesde]
            );
        } else {
            $rows = $this->mobileUserIsAdmin($usuario)
            ? $this->model->queryPrepared(
                "SELECT a.*,f.descripcion AS finca_nombre FROM agenda_visitas a
                 LEFT JOIN fincas f ON f.id=a.finca_id
                 WHERE a.voided='1' AND a.fecha_inicio>=:fecha_desde ORDER BY a.fecha_inicio",
                [':fecha_desde'=>$fechaDesde]
              )
            : $this->model->queryPrepared(
                "SELECT DISTINCT a.*,f.descripcion AS finca_nombre FROM agenda_visitas a
                 JOIN usuario_fincas uf ON uf.finca_id=a.finca_id LEFT JOIN fincas f ON f.id=a.finca_id
                 WHERE a.voided='1' AND a.fecha_inicio>=:fecha_desde
                   AND (a.usuario_id=:agenda_usuario OR uf.usuario_id=:finca_usuario)
                 ORDER BY a.fecha_inicio",
                [':agenda_usuario'=>$usuario, ':finca_usuario'=>$usuario, ':fecha_desde'=>$fechaDesde]
              );
        }
        service_return(['data'=>$rows,'message'=>'Agenda enviada correctamente.']);
    }

    // =====================================================================
    // AGENDA DE VISITAS (WEB) — mismo control que el módulo móvil, con
    // selección de técnico para roles admin y alcance por finca asignada
    // para el resto (mismo criterio que getVisitasWeb/assertFincaAccesible).
    // =====================================================================

    public function getAgendaVisitasWeb($data)
    {
        $fincaIds = $this->assignedFincaIds();
        $isAdmin = $fincaIds === null;
        $scope = $this->fincaScopeClause('a.finca_id', $fincaIds);
        $conditions = ["a.voided = '1'"];
        if ($scope['sql'] !== '') $conditions[] = $scope['sql'];
        $where = 'WHERE ' . implode(' AND ', $conditions);
        $items = $this->model->queryPrepared(
            "SELECT a.id, a.finca_id, a.usuario_id, a.visita_id, a.fecha_inicio, a.duracion_minutos,
                    a.objetivo, a.observacion, a.estado, a.created_at, a.updated_at, a.created_by,
                    f.descripcion AS finca_nombre, u.name AS tecnico_nombre
             FROM agenda_visitas a
             LEFT JOIN fincas f ON f.id = a.finca_id
             LEFT JOIN `user` u ON u.id = a.usuario_id
             $where
             ORDER BY a.fecha_inicio",
            $scope['params']
        );

        $farmScope = $this->fincaScopeClause('id', $fincaIds);
        $farmWhere = "WHERE voided='1'" . ($farmScope['sql'] !== '' ? " AND {$farmScope['sql']}" : '');
        $fincas = $this->model->queryPrepared(
            "SELECT id, descripcion AS nombre FROM fincas $farmWhere ORDER BY descripcion",
            $farmScope['params']
        );

        $tecnicos = $isAdmin
            ? $this->model->queryPrepared("SELECT id, name FROM `user` WHERE void='1' ORDER BY name")
            : $this->model->queryPrepared(
                "SELECT id, name FROM `user` WHERE id = :id",
                [':id' => (string)($_SESSION['agronomo_user_id'] ?? '')]
              );

        service_return([
            'data' => ['items' => $items, 'fincas' => $fincas, 'tecnicos' => $tecnicos, 'is_admin' => $isAdmin],
            'message' => 'Agenda de visitas consultada con éxito.',
        ]);
    }

    public function saveAgendaVisitaWeb($data)
    {
        $id = trim((string)($data['id'] ?? ''));
        $fincaId = trim((string)($data['finca_id'] ?? ''));
        $objetivo = trim((string)($data['objetivo'] ?? ''));
        $fechaInicio = trim((string)($data['fecha_inicio'] ?? ''));
        $duracion = (int)($data['duracion_minutos'] ?? 60);
        $observacion = trim((string)($data['observacion'] ?? ''));
        $estado = trim((string)($data['estado'] ?? 'PROGRAMADA'));
        $allowedEstados = ['PROGRAMADA', 'EN_CURSO', 'COMPLETADA', 'CANCELADA'];

        if ($fincaId === '' || $objetivo === '' || $fechaInicio === '') {
            service_return(['success' => false, 'message' => 'Finca, objetivo y fecha son obligatorios.', 'data' => []]);
        }
        if (!in_array($estado, $allowedEstados, true)) {
            service_return(['success' => false, 'message' => 'Estado de agenda no válido.', 'data' => []]);
        }
        if ($duracion <= 0) $duracion = 60;

        $this->assertFincaAccesible($fincaId);

        $sessionUserId = (string)($_SESSION['agronomo_user_id'] ?? '');
        $isAdmin = $this->loadLiveSession()['role_code'] === 'admin';
        $usuarioId = trim((string)($data['usuario_id'] ?? ''));
        if (!$isAdmin || $usuarioId === '') $usuarioId = $sessionUserId;

        $tecnico = $this->model->queryPrepared("SELECT id FROM `user` WHERE id = :id AND void = '1' LIMIT 1", [':id' => $usuarioId]);
        if (!$tecnico) {
            service_return(['success' => false, 'message' => 'El técnico seleccionado no está disponible.', 'data' => []]);
        }

        if ($id !== '') {
            $existing = $this->model->queryPrepared("SELECT id FROM agenda_visitas WHERE id = :id LIMIT 1", [':id' => $id]);
            if (!$existing) {
                service_return(['success' => false, 'message' => 'La programación que intentas editar ya no existe.', 'data' => []]);
            }
        }

        $now = date('Y-m-d H:i:s');
        $params = [
            ':id' => $id !== '' ? $id : bin2hex(random_bytes(16)),
            ':finca' => $fincaId, ':usuario' => $usuarioId,
            ':fecha' => $fechaInicio, ':duracion' => $duracion,
            ':objetivo' => $objetivo, ':observacion' => $observacion !== '' ? $observacion : null,
            ':estado' => $estado, ':actor' => $sessionUserId, ':now' => $now,
        ];

        if ($id === '') {
            // PDO en este entorno no permite reutilizar el mismo parámetro
            // nombrado dos veces en la misma consulta ("Invalid parameter
            // number"), así que created_at y updated_at necesitan cada uno
            // su propio placeholder aunque compartan el mismo valor.
            $insertParams = $params;
            $insertParams[':created_at'] = $now;
            $this->model->executePrepared(
                "INSERT INTO agenda_visitas(id,finca_id,usuario_id,fecha_inicio,duracion_minutos,objetivo,observacion,estado,sync,voided,created_at,updated_at,created_by)
                 VALUES(:id,:finca,:usuario,:fecha,:duracion,:objetivo,:observacion,:estado,'1','1',:created_at,:now,:actor)",
                $insertParams
            );
            $message = 'Visita programada con éxito.';
        } else {
            $updateParams = $params;
            unset($updateParams[':actor']);
            $this->model->executePrepared(
                "UPDATE agenda_visitas SET finca_id=:finca,usuario_id=:usuario,fecha_inicio=:fecha,duracion_minutos=:duracion,objetivo=:objetivo,observacion=:observacion,estado=:estado,sync='1',updated_at=:now WHERE id=:id",
                $updateParams
            );
            $message = 'Programación actualizada con éxito.';
        }
        $this->auditWeb('AGENDA_GUARDADA', $params[':id']);
        service_return(['data' => ['id' => $params[':id']], 'message' => $message]);
    }

    public function cambiarEstadoAgendaVisitaWeb($data)
    {
        $id = trim((string)($data['id'] ?? ''));
        $estado = trim((string)($data['estado'] ?? ''));
        $allowedEstados = ['PROGRAMADA', 'EN_CURSO', 'COMPLETADA', 'CANCELADA'];
        if ($id === '' || !in_array($estado, $allowedEstados, true)) {
            service_return(['success' => false, 'message' => 'Solicitud inválida.', 'data' => []]);
        }
        $existing = $this->model->queryPrepared("SELECT finca_id FROM agenda_visitas WHERE id = :id LIMIT 1", [':id' => $id]);
        if (!$existing) {
            service_return(['success' => false, 'message' => 'La programación ya no existe.', 'data' => []]);
        }
        $this->assertFincaAccesible((string)$existing[0]['finca_id']);
        $this->model->executePrepared(
            "UPDATE agenda_visitas SET estado = :estado, sync = '1', updated_at = :now WHERE id = :id",
            [':id' => $id, ':estado' => $estado, ':now' => date('Y-m-d H:i:s')]
        );
        $this->auditWeb('AGENDA_ESTADO', $id . ' · ' . $estado);
        service_return(['data' => ['id' => $id, 'estado' => $estado], 'message' => 'Estado actualizado.']);
    }

    // =====================================================================
    // CONSULTA WEB DE VISITAS TÉCNICAS (solo lectura)
    // =====================================================================

    // =====================================================================
    // MI PERFIL (configuración por usuario)
    // =====================================================================

    public function getConfiguracionUsuarioWeb($data)
    {
        $usuarioId = (string)($_SESSION['agronomo_user_id'] ?? '');
        if ($usuarioId === '') {
            service_return(['success' => false, 'message' => 'Debes iniciar sesión.', 'data' => []]);
        }
        $rows = $this->model->queryPrepared(
            "SELECT id, nombre, titulo, tarjeta_profesional, celular, firma_base64
             FROM configuracion_usuario WHERE usuario_id = :usuario_id AND voided = '1' LIMIT 1",
            [':usuario_id' => $usuarioId]
        );
        service_return(['data' => $rows[0] ?? null, 'message' => 'Configuración consultada con éxito.']);
    }

    // Igual que getConfiguracionUsuarioWeb, pero recibe el usuario_id como
    // parámetro en vez de tomarlo de la sesión: se usa para el reporte PDF de
    // visitas, donde quien exporta (un admin/supervisor) no es necesariamente
    // el técnico que registró la visita y hay que traer la firma de ese otro
    // usuario.
    public function getFirmaTecnicoWeb($data)
    {
        $usuarioId = trim((string)($data['usuario_id'] ?? ''));
        if ($usuarioId === '') {
            service_return(['success' => false, 'message' => 'El usuario es requerido.', 'data' => []]);
        }
        $rows = $this->model->queryPrepared(
            "SELECT id, nombre, titulo, tarjeta_profesional, celular, firma_base64
             FROM configuracion_usuario WHERE usuario_id = :usuario_id AND voided = '1' LIMIT 1",
            [':usuario_id' => $usuarioId]
        );
        service_return(['data' => $rows[0] ?? null, 'message' => 'Firma consultada con éxito.']);
    }

    public function saveConfiguracionUsuarioWeb($data)
    {
        $usuarioId = (string)($_SESSION['agronomo_user_id'] ?? '');
        if ($usuarioId === '') {
            service_return(['success' => false, 'message' => 'Debes iniciar sesión.', 'data' => []]);
        }
        $nombre = trim((string)($data['nombre'] ?? ''));
        $titulo = trim((string)($data['titulo'] ?? ''));
        $tarjeta = trim((string)($data['tarjeta_profesional'] ?? ''));
        $celular = trim((string)($data['celular'] ?? ''));
        $firma = trim((string)($data['firma_base64'] ?? ''));

        $existing = $this->model->queryPrepared(
            "SELECT id FROM configuracion_usuario WHERE usuario_id = :usuario_id LIMIT 1",
            [':usuario_id' => $usuarioId]
        );

        $now = date('Y-m-d H:i:s');
        if ($existing) {
            $id = $existing[0]['id'];
            $this->model->executePrepared(
                "UPDATE configuracion_usuario SET nombre = :nombre, titulo = :titulo,
                 tarjeta_profesional = :tarjeta, celular = :celular, firma_base64 = NULLIF(:firma, ''),
                 sync = '1', voided = '1', updated_at = :now WHERE id = :id",
                [':nombre' => $nombre, ':titulo' => $titulo, ':tarjeta' => $tarjeta, ':celular' => $celular, ':firma' => $firma, ':id' => $id, ':now' => $now]
            );
            $message = 'Perfil actualizado con éxito.';
        } else {
            $id = bin2hex(random_bytes(16));
            $this->model->executePrepared(
                "INSERT INTO configuracion_usuario (id, usuario_id, nombre, titulo, tarjeta_profesional, celular, firma_base64, sync, voided, created_at, updated_at, created_by)
                 VALUES (:id, :usuario_id, :nombre, :titulo, :tarjeta, :celular, NULLIF(:firma, ''), '1', '1', :created_at, :now, :usuario_id2)",
                [':id' => $id, ':usuario_id' => $usuarioId, ':nombre' => $nombre, ':titulo' => $titulo, ':tarjeta' => $tarjeta, ':celular' => $celular, ':firma' => $firma, ':usuario_id2' => $usuarioId, ':now' => $now, ':created_at' => $now]
            );
            $message = 'Perfil creado con éxito.';
        }
        service_return(['data' => ['id' => $id], 'message' => $message]);
    }

    public function getResumenWeb($data)
    {
        $contadores = $this->model->queryPrepared(
            "SELECT
                (SELECT COUNT(*) FROM fincas WHERE voided = '1') AS total_fincas,
                (SELECT COUNT(*) FROM fincas WHERE voided = '1' AND productor_id IS NOT NULL) AS total_predios,
                (SELECT COUNT(DISTINCT finca_id) FROM usuario_fincas) AS fincas_asignadas,
                (SELECT COALESCE(SUM(hectareas_totales),0) FROM fincas WHERE voided = '1') AS total_hectareas,
                (SELECT COUNT(*) FROM lotes WHERE voided = '1') AS total_lotes,
                (SELECT COUNT(*) FROM cultivos WHERE voided = '1') AS total_cultivos,
                (SELECT COUNT(*) FROM insumos WHERE voided = '1') AS total_insumos,
                (SELECT COUNT(*) FROM formulas WHERE voided = '1') AS total_formulas,
                (SELECT COUNT(*) FROM `user` u LEFT JOIN roles r ON r.id = u.rol_id
                 WHERE u.void = '1' AND COALESCE(r.codigo,'') <> 'admin' AND COALESCE(u.roll,'') <> 'S') AS total_tecnicos,
                (SELECT COUNT(*) FROM visitas_tecnicas
                 WHERE voided = '1' AND fecha >= DATE_FORMAT(CURDATE(), '%Y-%m-01')) AS visitas_mes,
                (SELECT COUNT(*) FROM visitas_tecnicas
                 WHERE voided = '1' AND fecha >= DATE_FORMAT(CURDATE() - INTERVAL 1 MONTH, '%Y-%m-01')
                   AND fecha < DATE_FORMAT(CURDATE(), '%Y-%m-01')) AS visitas_mes_anterior,
                (SELECT COUNT(*) FROM visitas_tecnicas WHERE voided = '1') AS total_visitas"
        );

        $actividadReciente = $this->model->queryPrepared(
            "SELECT vt.id, vt.fecha, vt.descripcion, vt.created_at,
                    f.descripcion AS finca_nombre,
                    COALESCE(u.name, vt.created_by) AS tecnico_nombre,
                    COUNT(DISTINCT vdl.id) AS total_lotes
             FROM visitas_tecnicas vt
             LEFT JOIN fincas f ON f.id = vt.finca_id
             LEFT JOIN `user` u ON u.id = vt.created_by
             LEFT JOIN visita_detalles_lote vdl ON vdl.visita_id=vt.id AND vdl.voided='1'
             WHERE vt.voided = '1'
             GROUP BY vt.id,vt.fecha,vt.descripcion,vt.created_at,f.descripcion,u.name,vt.created_by
             ORDER BY vt.created_at DESC
             LIMIT 8"
        );

        $alertasPredio = $this->model->queryPrepared(
            "SELECT alerta.*, CASE
                    WHEN fecha_vencimiento IS NULL THEN 'pendiente'
                    WHEN dias_restantes < 0 THEN 'vencida'
                    WHEN dias_restantes <= 30 THEN 'critica'
                    ELSE 'preventiva' END AS nivel
             FROM (
                SELECT f.id AS finca_id,f.descripcion AS finca_nombre,
                       COALESCE(tc.nombre,pc.tipo) AS documento,
                       pc.valido_hasta AS fecha_vencimiento,DATEDIFF(pc.valido_hasta,CURDATE()) AS dias_restantes
                FROM predio_certificaciones pc JOIN fincas f ON f.id=pc.finca_id
                LEFT JOIN tipos_certificacion tc ON tc.codigo=pc.tipo
                WHERE pc.vigente=1 AND f.voided='1'
                UNION ALL
                SELECT f.id,f.descripcion,'Registro ICA',f.vencimiento_ica,DATEDIFF(f.vencimiento_ica,CURDATE())
                FROM fincas f WHERE f.registro_ica=1 AND f.voided='1'
                UNION ALL
                SELECT f.id,f.descripcion,'Contrato de proveeduría',f.fecha_vencimiento_contrato,DATEDIFF(f.fecha_vencimiento_contrato,CURDATE())
                FROM fincas f WHERE f.contrato_proveeduria=1 AND f.voided='1'
             ) alerta
             WHERE fecha_vencimiento IS NULL OR dias_restantes <= 90
             ORDER BY CASE WHEN fecha_vencimiento IS NULL THEN 0 WHEN dias_restantes < 0 THEN 1 WHEN dias_restantes <= 30 THEN 2 ELSE 3 END,
                      dias_restantes, finca_nombre
             LIMIT 20"
        );

        service_return(['data' => [
            'contadores' => $contadores[0] ?? [],
            'actividad_reciente' => $actividadReciente,
            'alertas_predio' => $alertasPredio,
        ], 'message' => 'Resumen consultado con éxito.']);
    }

    public function getVisitasWeb($data)
    {
        $scope = $this->fincaScopeClause('vt.finca_id', $this->assignedFincaIds());
        $where = $scope['sql'] !== '' ? "WHERE {$scope['sql']}" : '';
        $visitas = $this->model->queryPrepared(
            "SELECT vt.id, vt.finca_id, vt.fecha, vt.descripcion, vt.observacion, vt.voided,
                    vt.created_at, vt.updated_at, vt.created_by,
                    f.descripcion AS finca_nombre,
                    COALESCE(u.name, vt.created_by) AS tecnico_nombre,
                    COUNT(DISTINCT vdl.id) AS total_lotes,
                    COALESCE((SELECT GROUP_CONCAT(DISTINCT lb.nombre ORDER BY lb.nombre SEPARATOR '|||')
                              FROM visita_detalles_actividades vda
                              JOIN labores lb ON lb.id=vda.labor_id
                              WHERE vda.visita_id=vt.id AND vda.voided='1'),'') AS labores_filtro,
                    COALESCE((SELECT GROUP_CONCAT(DISTINCT i.nombre ORDER BY i.nombre SEPARATOR '|||')
                              FROM visita_detalles_formulas vdf
                              JOIN insumos i ON i.id=vdf.insumo_id
                              WHERE vdf.visita_id=vt.id AND vdf.voided='1' AND COALESCE(vdf.es_header,'0')<>'1'),'') AS insumos_filtro,
                    COALESCE((SELECT GROUP_CONCAT(DISTINCT fo.descripcion ORDER BY fo.descripcion SEPARATOR '|||')
                              FROM visita_detalles_formulas vdf
                              JOIN formulas fo ON fo.id=vdf.formula_id
                              WHERE vdf.visita_id=vt.id AND vdf.voided='1'),'') AS formulas_filtro
             FROM visitas_tecnicas vt
             LEFT JOIN fincas f ON f.id = vt.finca_id
             LEFT JOIN `user` u ON u.id = vt.created_by
             LEFT JOIN visita_detalles_lote vdl ON vdl.visita_id = vt.id AND vdl.voided = '1'
             $where
             GROUP BY vt.id, vt.finca_id, vt.fecha, vt.descripcion, vt.observacion, vt.voided,
                      vt.created_at, vt.updated_at, f.descripcion, u.name, vt.created_by
             ORDER BY vt.fecha DESC",
            $scope['params']
        );
        service_return(['data' => $visitas, 'message' => 'Visitas técnicas consultadas con éxito.']);
    }

    public function getVisitaDetalleWeb($data)
    {
        $visitaId = trim((string)($data['visita_id'] ?? ''));
        if ($visitaId === '') {
            service_return(['success' => false, 'message' => 'La visita es requerida.', 'data' => []]);
        }
        $visitaRows = $this->model->queryPrepared(
            "SELECT vt.id, vt.finca_id, vt.fecha, vt.descripcion, vt.observacion, vt.voided,
                    vt.created_at, vt.updated_at, vt.created_by,
                    f.descripcion AS finca_nombre,
                    COALESCE(u.name, vt.created_by) AS tecnico_nombre
             FROM visitas_tecnicas vt
             LEFT JOIN fincas f ON f.id = vt.finca_id
             LEFT JOIN `user` u ON u.id = vt.created_by
             WHERE vt.id = :visita_id",
            [':visita_id' => $visitaId]
        );
        if (!$visitaRows) {
            service_return(['success' => false, 'message' => 'La visita no existe.', 'data' => []]);
        }
        $this->assertFincaAccesible((string)($visitaRows[0]['finca_id'] ?? ''));
        $lotes = $this->model->queryPrepared(
            "SELECT vdl.id, vdl.lote_id, vdl.cultivo_id, vdl.observacion, vdl.voided,
                    l.nombre AS lote_nombre, c.descripcion AS cultivo_nombre
             FROM visita_detalles_lote vdl
             LEFT JOIN lotes l ON l.id = vdl.lote_id
             LEFT JOIN cultivos c ON c.id = vdl.cultivo_id
             WHERE vdl.visita_id = :visita_id AND vdl.voided = '1'
             ORDER BY l.nombre",
            [':visita_id' => $visitaId]
        );
        $hallazgos = $this->model->queryPrepared(
            "SELECT id, visita_detalle_id, descripcion, imagen_path_srv, created_at
             FROM visita_detalles_hallazgos WHERE visita_id = :visita_id AND voided = '1' ORDER BY created_at",
            [':visita_id' => $visitaId]
        );
        $actividades = $this->model->queryPrepared(
            "SELECT vda.id, vda.visita_detalle_id, vda.labor_id, vda.estado,
                    lb.nombre AS labor_nombre
             FROM visita_detalles_actividades vda
             LEFT JOIN labores lb ON lb.id = vda.labor_id
             WHERE vda.visita_id = :visita_id AND vda.voided = '1' ORDER BY lb.nombre",
            [':visita_id' => $visitaId]
        );
        $formulas = $this->model->queryPrepared(
            "SELECT vdf.id, vdf.visita_detalle_id, vdf.formula_id, vdf.group_id, vdf.insumo_id,
                    vdf.dosis, vdf.unidad, vdf.obs_insumo, vdf.obs_global, vdf.es_header,
                    fo.descripcion AS formula_nombre, i.nombre AS insumo_nombre
             FROM visita_detalles_formulas vdf
             LEFT JOIN formulas fo ON fo.id = vdf.formula_id
             LEFT JOIN insumos i ON i.id = vdf.insumo_id
             WHERE vdf.visita_id = :visita_id AND vdf.voided = '1'
             ORDER BY vdf.es_header DESC, fo.descripcion",
            [':visita_id' => $visitaId]
        );
        $recomendaciones = $this->model->queryPrepared(
            "SELECT vdr.id, vdr.visita_detalle_id, vdr.recomendacion_id, vdr.texto,
                    r.descripcion AS recomendacion_base
             FROM visita_detalles_recomendaciones vdr
             LEFT JOIN recomendaciones r ON r.id = vdr.recomendacion_id
             WHERE vdr.visita_id = :visita_id AND vdr.voided = '1'",
            [':visita_id' => $visitaId]
        );
        service_return(['data' => [
            'visita' => $visitaRows[0],
            'lotes' => $lotes,
            'hallazgos' => $hallazgos,
            'actividades' => $actividades,
            'formulas' => $formulas,
            'recomendaciones' => $recomendaciones,
        ], 'message' => 'Detalle de la visita consultado con éxito.']);
    }

    // =====================================================================
    // CATÁLOGOS
    // =====================================================================

    public function createRecomendacion($data)
    {

        $a = $this->model;


        $where['id'] = $data['id'];
        $sql_data = crearSentenciaSelect(['tabla' => 'recomendaciones', 'where' => $where]);

        $data_select = $a->executeScript(['sql' => $sql_data]);

        if (empty($data_select)) {
            $data['sync'] = '1';
            $sql = crearSentenciaInsert(['tabla' => 'recomendaciones', 'conten' => $data]);
            $a->executeScript(['sql' => $sql]);
            $msg = 'La recomendación se creó con éxito!';
        } else {

            $data_query['descripcion'] = $data['descripcion'];
            $data_query['sync'] = '1';
            $data_query['voided'] = $data['voided'];
            $data_query['created_at'] = $data['created_at'];
            $data_query['updated_at'] = $data['updated_at'];
            $data_query['created_by'] = $data['created_by'];

            $data_where['id'] = $data['id'];

            $sql_mast = crearSentenciaUpdate(['tabla' => 'recomendaciones', 'sets' => $data_query, 'where' => $data_where]);
            $a->executeScript([
                'sql' => $sql_mast
            ]);
            $msg = 'La recomendación se editó con éxito!';
        }


        service_return(['data' => $data['id'], 'message' => $msg]);
    }

    public function getRecomendaciones()
    {

        $a = $this->model;
        $where['1'] = '1';
        $sql_ = crearSentenciaSelect(['tabla' => 'recomendaciones', 'where' => $where]);
        $data_load = $a->executeScript(['sql' => $sql_]);

        service_return(['data' => $data_load, 'message' => 'El listado de recomendaciones se envia con éxito!']);
    }

    public function createLabor($data)
    {

        $a = $this->model;
        $this->assertMobileCatalogNotReactivated('labores', trim((string)($data['id'] ?? '')), 'labor', $data);
        $activeLaborMutation = (string)($data['voided'] ?? '1') !== '0';
        if ($activeLaborMutation) $this->assertActiveMobileReference('cultivos', trim((string)($data['cultivo_id'] ?? '')), 'cultivo');

        // categoria_labor_id es una FK opcional. Los helpers crearSentenciaInsert
        // /Update siempre escriben el valor como cadena, así que un valor vacío
        // rompería la restricción FK; se guarda aparte con NULLIF para permitir
        // "sin categoría" de forma segura, tanto al crear como al editar.
        $categoriaLaborId = trim((string)($data['categoria_labor_id'] ?? ''));
        if ($activeLaborMutation && $categoriaLaborId !== '') $this->assertActiveMobileReference('categorias_labor', $categoriaLaborId, 'categoría de labor');
        unset($data['categoria_labor_id']);

        $where['id'] = $data['id'];
        $sql_data = crearSentenciaSelect(['tabla' => 'labores', 'where' => $where]);

        $data_select = $a->executeScript(['sql' => $sql_data]);

        if (empty($data_select)) {
            $data['sync'] = '1';
            $sql = crearSentenciaInsert(['tabla' => 'labores', 'conten' => $data]);
            $a->executeScript(['sql' => $sql]);
            $msg = 'La labor se creó con éxito!';
        } else {

            $data_query['nombre'] = $data['nombre'];
            $data_query['codigo'] = $data['codigo'] ?? '';
            $data_query['cultivo_id'] = $data['cultivo_id'];
            $data_query['sync'] = '1';
            $data_query['voided'] = $data['voided'];
            $data_query['created_at'] = $data['created_at'];
            $data_query['updated_at'] = $data['updated_at'];
            $data_query['created_by'] = $data['created_by'];

            $data_where['id'] = $data['id'];

            $sql_mast = crearSentenciaUpdate(['tabla' => 'labores', 'sets' => $data_query, 'where' => $data_where]);
            $a->executeScript([
                'sql' => $sql_mast
            ]);
            $msg = 'La labor se editó con éxito!';
        }

        $a->executePrepared(
            "UPDATE labores SET categoria_labor_id = NULLIF(:categoria_id, '') WHERE id = :id",
            [':categoria_id' => $categoriaLaborId, ':id' => $data['id']]
        );

        service_return(['data' => $data['id'], 'message' => $msg]);
    }

    public function getLabores()
    {

        $a = $this->model;
        $where['1'] = '1';
        $sql_ = crearSentenciaSelect(['tabla' => 'labores', 'where' => $where]);
        $data_load = $a->executeScript(['sql' => $sql_]);

        service_return(['data' => $data_load, 'message' => 'El listado de labores se envia con éxito!']);
    }

    public function createDetalleFormula($data)
    {

        $a = $this->model;

        $formulaId = trim((string)($data['formula_id'] ?? ''));
        $insumoId = trim((string)($data['insumo_id'] ?? ''));
        if ($formulaId === '' || $insumoId === '') {
            service_return(['success' => false, 'message' => 'La fórmula y el insumo son obligatorios.', 'data' => []]);
        }
        if ((string)($data['voided'] ?? '1') !== '0') {
            $this->assertActiveMobileReference('formulas', $formulaId, 'fórmula');
            $this->assertActiveMobileReference('insumos', $insumoId, 'insumo');
        }

        $where['id'] = $data['id'];
        $sql_data = crearSentenciaSelect(['tabla' => 'formulas_detalle', 'where' => $where]);
        $data_select = $a->executeScript(['sql' => $sql_data]);

        if (empty($data_select)) {
            $data['sync'] = '1';
            $sql = crearSentenciaInsert(['tabla' => 'formulas_detalle', 'conten' => $data]);
            $a->executeScript(['sql' => $sql]);
            $msg = 'El detalle formula se creó con éxito!';
        } else {

            $data_query['formula_id'] = $data['formula_id'];
            $data_query['insumo_id'] = $data['insumo_id'];
            $data_query['grupo_id'] = $data['grupo_id'];
            $data_query['dosis'] = $data['dosis'];
            $data_query['observacion'] = $data['observacion'];
            $data_query['sync'] = '1';
            $data_query['voided'] = $data['voided'];
            $data_query['created_at'] = $data['created_at'];
            $data_query['updated_at'] = $data['updated_at'];
            $data_query['created_by'] = $data['created_by'];

            $data_where['id'] = $data['id'];

            $sql_mast = crearSentenciaUpdate(['tabla' => 'formulas_detalle', 'sets' => $data_query, 'where' => $data_where]);

            $a->executeScript([
                'sql' => $sql_mast
            ]);
            $msg = 'El detalle formula se editó con éxito!';
        }


        service_return(['data' => $data['id'], 'message' => $msg]);
    }

    public function getDetalleFormula()
    {

        $a = $this->model;
        $where['voided'] = '1';
        $sql_ = crearSentenciaSelect(['tabla' => 'formulas_detalle', 'where' => $where]);
        $data_load = $a->executeScript(['sql' => $sql_]);

        service_return(['data' => $data_load, 'message' => 'El listado de detalle de formula se envia con éxito!']);
    }

    public function createFormula($data)
    {

        $a = $this->model;
        $this->assertMobileCatalogNotReactivated('formulas', trim((string)($data['id'] ?? '')), 'fórmula', $data);


        $where['id'] = $data['id'];
        $sql_data = crearSentenciaSelect(['tabla' => 'formulas', 'where' => $where]);

        $data_select = $a->executeScript(['sql' => $sql_data]);

        if (empty($data_select)) {
            $data['sync'] = '1';
            $sql = crearSentenciaInsert(['tabla' => 'formulas', 'conten' => $data]);
            $a->executeScript(['sql' => $sql]);
            $msg = 'La formula se creó con éxito!';
        } else {

            $data_query['descripcion'] = $data['descripcion'];
            $data_query['unidad'] = $data['unidad'];
            $data_query['observacion'] = $data['observacion'];
            $data_query['sync'] = '1';
            $data_query['voided'] = $data['voided'];
            $data_query['created_at'] = $data['created_at'];
            $data_query['updated_at'] = $data['updated_at'];
            $data_query['created_by'] = $data['created_by'];

            $data_where['id'] = $data['id'];

            $sql_mast = crearSentenciaUpdate(['tabla' => 'formulas', 'sets' => $data_query, 'where' => $data_where]);

            $a->executeScript([
                'sql' => $sql_mast
            ]);
            $msg = 'La formula se editó con éxito!';
        }


        service_return(['data' => $data['id'], 'message' => $msg]);
    }

    public function getFormulas($data)
    {

        $a = $this->model;
        $where['voided'] = '1';
        $sql_ = crearSentenciaSelect(['tabla' => 'formulas', 'where' => $where]);
        $data_load = $a->executeScript(['sql' => $sql_]);

        service_return(['data' => $data_load, 'message' => 'El listado de formulas se envia con éxito!']);
    }

    // =====================================================================
    // ADMINISTRACIÓN WEB DE USUARIOS, ROLES Y PERMISOS
    // =====================================================================

    public function getAdministracionWeb($data)
    {
        $users = $this->model->queryPrepared(
            "SELECT u.id,u.name,u.`user`,u.mail,u.void,u.pass_provi,
                    CASE WHEN u.pass_provi='1' THEN u.code ELSE NULL END AS clave_provisional,
                    u.ultimo_acceso,u.date_crea,
                    u.rol_id,r.nombre AS rol_nombre,r.codigo AS rol_codigo
             FROM `user` u LEFT JOIN roles r ON r.id=u.rol_id
             ORDER BY u.void DESC,u.name,u.`user`"
        );
        $roles = $this->model->queryPrepared(
            "SELECT r.id,r.nombre,r.codigo,r.descripcion,r.es_sistema,r.activo,
                    COUNT(DISTINCT u.id) AS total_usuarios,
                    COUNT(DISTINCT rp.permiso_id) AS total_permisos
             FROM roles r LEFT JOIN `user` u ON u.rol_id=r.id
             LEFT JOIN rol_permisos rp ON rp.rol_id=r.id
             GROUP BY r.id,r.nombre,r.codigo,r.descripcion,r.es_sistema,r.activo
             ORDER BY r.es_sistema DESC,r.nombre"
        );
        $permissions = $this->model->queryPrepared(
            "SELECT p.id,p.modulo,p.accion,p.descripcion,
                    GROUP_CONCAT(rp.rol_id ORDER BY rp.rol_id) AS roles
             FROM permisos p LEFT JOIN rol_permisos rp ON rp.permiso_id=p.id
             GROUP BY p.id,p.modulo,p.accion,p.descripcion ORDER BY p.modulo,p.accion"
        );
        $farms = $this->model->queryPrepared(
            "SELECT id,descripcion,
                    COALESCE(NULLIF(ubicacion,''),NULLIF(CONCAT_WS(', ',NULLIF(vereda,''),NULLIF(ciudad,''),NULLIF(departamento,'')),'')) AS ubicacion,
                    COALESCE(estado_predio,'ACTIVO') AS estado
             FROM fincas WHERE voided='1' ORDER BY descripcion"
        );
        $assignments = $this->model->queryPrepared(
            "SELECT uf.usuario_id,uf.finca_id,f.descripcion AS finca_nombre
             FROM usuario_fincas uf JOIN fincas f ON f.id=uf.finca_id
             ORDER BY f.descripcion"
        );
        $assignmentMap = [];
        foreach ($assignments as $assignment) {
            $assignmentMap[$assignment['usuario_id']][] = [
                'id'=>$assignment['finca_id'],
                'nombre'=>$assignment['finca_nombre'],
            ];
        }
        foreach ($users as &$user) {
            $user['fincas'] = $assignmentMap[$user['id']] ?? [];
        }
        unset($user);
        service_return(['data'=>['usuarios'=>$users,'roles'=>$roles,'permisos'=>$permissions,'fincas'=>$farms],'message'=>'Administración consultada.']);
    }

    public function saveUsuarioWeb($data)
    {
        $id = trim((string)($data['id'] ?? ''));
        $name = trim((string)($data['name'] ?? ''));
        $username = strtolower(trim((string)($data['user'] ?? '')));
        $mail = strtolower(trim((string)($data['mail'] ?? '')));
        $roleId = (int)($data['rol_id'] ?? 0);
        $farmIds = array_values(array_unique(array_filter(array_map(
            static function ($value) {
                return trim((string)$value);
            },
            is_array($data['finca_ids'] ?? null) ? $data['finca_ids'] : []
        ))));
        if ($name === '' || !preg_match('/^[a-z][a-z0-9.]{2,19}$/', $username) || $roleId <= 0) {
            service_return(['success'=>false,'message'=>'Nombre, usuario válido y rol son obligatorios.','data'=>[]]);
        }
        if ($farmIds) {
            $placeholders = [];
            $farmParams = [];
            foreach ($farmIds as $index => $farmId) {
                $placeholder = ':finca_' . $index;
                $placeholders[] = $placeholder;
                $farmParams[$placeholder] = $farmId;
            }
            $validFarms = $this->model->queryPrepared(
                "SELECT id FROM fincas WHERE voided='1' AND id IN (" . implode(',', $placeholders) . ")",
                $farmParams
            );
            if (count($validFarms) !== count($farmIds)) {
                service_return(['success'=>false,'message'=>'Una o más fincas seleccionadas no están disponibles.','data'=>[]]);
            }
        }
        if ($mail !== '' && !filter_var($mail, FILTER_VALIDATE_EMAIL)) {
            service_return(['success'=>false,'message'=>'El correo electrónico no es válido.','data'=>[]]);
        }
        $duplicate = $this->model->queryPrepared(
            "SELECT id FROM `user` WHERE (`user`=:usuario OR (:correo<>'' AND mail=:correo2)) AND id<>:id LIMIT 1",
            [':usuario'=>$username,':correo'=>$mail,':correo2'=>$mail,':id'=>$id]
        );
        if ($duplicate) service_return(['success'=>false,'message'=>'El usuario o correo ya está registrado.','data'=>[]]);
        $role = $this->model->queryPrepared("SELECT id,codigo FROM roles WHERE id=:id AND activo=1", [':id'=>$roleId]);
        if (!$role) service_return(['success'=>false,'message'=>'El rol seleccionado no está disponible.','data'=>[]]);
        if ($id !== '') {
            $existingUser = $this->model->queryPrepared(
                "SELECT id FROM `user` WHERE id=:id LIMIT 1",
                [':id'=>$id]
            );
            if (!$existingUser) {
                service_return(['success'=>false,'message'=>'El usuario que intentas editar ya no existe. Actualiza el listado e inténtalo nuevamente.','data'=>[]]);
            }
        }
        $legacyRoll = $role[0]['codigo'] === 'admin' ? 'S' : '1';
        $temporaryPassword = null;
        $this->model->beginTransaction();
        try {
            if ($id === '') {
                $temporaryPassword = (string)random_int(1000, 9999);
                $id = $this->nextUserId();
                $this->model->executePrepared(
                    "INSERT INTO `user` (id,name,`user`,code,password_hash,mail,void,roll,rol_id,pass_provi,user_crea,date_crea)
                     VALUES (:id,:name,:usuario,:code,:hash,:mail,'1',:roll,:rol,'1',:actor,:now)",
                    [':id'=>$id,':name'=>$name,':usuario'=>$username,':code'=>$temporaryPassword,':hash'=>password_hash($temporaryPassword,PASSWORD_DEFAULT),':mail'=>$mail,':roll'=>$legacyRoll,':rol'=>$roleId,':actor'=>$_SESSION['agronomo_user_id'] ?? '',':now'=>date('Y-m-d H:i:s')]
                );
                $message = 'Usuario creado con éxito.';
            } else {
                $this->model->executePrepared(
                    "UPDATE `user` SET name=:name,`user`=:usuario,mail=:mail,roll=:roll,rol_id=:rol,user_modify=:actor,date_modify=:now WHERE id=:id",
                    [':id'=>$id,':name'=>$name,':usuario'=>$username,':mail'=>$mail,':roll'=>$legacyRoll,':rol'=>$roleId,':actor'=>$_SESSION['agronomo_user_id'] ?? '',':now'=>date('Y-m-d H:i:s')]
                );
                $message = 'Usuario actualizado con éxito.';
            }
            $persistedUser = $this->model->queryPrepared(
                "SELECT id FROM `user` WHERE id=:id LIMIT 1",
                [':id'=>$id]
            );
            if (!$persistedUser) {
                throw new RuntimeException('No fue posible confirmar el usuario antes de asignar las fincas.');
            }
            $this->model->executePrepared("DELETE FROM usuario_fincas WHERE usuario_id=:usuario", [':usuario'=>$id]);
            foreach ($farmIds as $farmId) {
                $this->model->executePrepared(
                    "INSERT INTO usuario_fincas(usuario_id,finca_id,created_by) VALUES(:usuario,:finca,:actor)",
                    [':usuario'=>$id,':finca'=>$farmId,':actor'=>$_SESSION['agronomo_user_id'] ?? '']
                );
            }
            $this->auditWeb('USUARIO_GUARDADO', $id . ' · ' . $username);
            $this->model->commit();
        } catch (Throwable $e) {
            $this->model->rollBack();
            throw $e;
        }
        service_return(['data'=>['id'=>$id,'provisional_password'=>$temporaryPassword],'message'=>$message]);
    }

    public function toggleUsuarioWeb($data)
    {
        $id = trim((string)($data['id'] ?? ''));
        if ($id === '' || $id === (string)($_SESSION['agronomo_user_id'] ?? '')) {
            service_return(['success'=>false,'message'=>'No puedes desactivar tu propia cuenta.','data'=>[]]);
        }
        $this->model->executePrepared("UPDATE `user` SET void=IF(void='1','0','1'),user_modify=:actor,date_modify=:now WHERE id=:id", [':id'=>$id,':actor'=>$_SESSION['agronomo_user_id'] ?? '',':now'=>date('Y-m-d H:i:s')]);
        $this->auditWeb('USUARIO_ESTADO', $id);
        service_return(['data'=>['id'=>$id],'message'=>'Estado del usuario actualizado.']);
    }

    public function resetUsuarioPasswordWeb($data)
    {
        $id = trim((string)($data['id'] ?? ''));
        if ($id === '') service_return(['success'=>false,'message'=>'Debes seleccionar un usuario.','data'=>[]]);
        $exists = $this->model->queryPrepared("SELECT id FROM `user` WHERE id=:id AND void='1' LIMIT 1", [':id'=>$id]);
        if (!$exists) service_return(['success'=>false,'message'=>'El usuario no existe o está inactivo.','data'=>[]]);
        $password = (string)random_int(1000, 9999);
        $this->model->executePrepared(
            "UPDATE `user` SET code=:code,password_hash=:hash,pass_provi='1',user_modify=:actor,date_modify=:now WHERE id=:id",
            [':id'=>$id,':code'=>$password,':hash'=>password_hash($password,PASSWORD_DEFAULT),':actor'=>$_SESSION['agronomo_user_id'] ?? '',':now'=>date('Y-m-d H:i:s')]
        );
        $this->auditWeb('PASSWORD_RESTABLECIDO', $id);
        service_return(['data'=>['id'=>$id,'provisional_password'=>$password],'message'=>'Clave provisional generada. El usuario deberá cambiarla al ingresar.']);
    }

    public function changeProvisionalPasswordWeb($data)
    {
        $id = (string)($_SESSION['agronomo_user_id'] ?? '');
        $password = (string)($data['password'] ?? '');
        if ($id === '') service_return(['success'=>false,'message'=>'La sesión no es válida.','data'=>[]]);
        if (strlen($password) < 6) service_return(['success'=>false,'message'=>'La nueva contraseña debe tener al menos 6 caracteres.','data'=>[]]);
        if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            service_return(['success'=>false,'message'=>'Usa al menos una letra y un número.','data'=>[]]);
        }
        $this->model->executePrepared(
            "UPDATE `user` SET code=:code,password_hash=:hash,pass_provi='0',user_modify=:actor,date_modify=:now WHERE id=:id AND pass_provi='1'",
            [':id'=>$id,':code'=>$password,':hash'=>password_hash($password,PASSWORD_DEFAULT),':actor'=>$id,':now'=>date('Y-m-d H:i:s')]
        );
        $_SESSION['agronomo_pass_provi'] = '0';
        $this->auditWeb('PASSWORD_PROVISIONAL_CAMBIADO', $id);
        service_return(['data'=>['id'=>$id,'pass_provi'=>'0'],'message'=>'Tu contraseña fue actualizada.']);
    }

    public function saveRolWeb($data)
    {
        $id = (int)($data['id'] ?? 0);
        $name = trim((string)($data['nombre'] ?? ''));
        $description = trim((string)($data['descripcion'] ?? ''));
        $permissionIds = array_values(array_unique(array_filter(array_map('intval', is_array($data['permisos'] ?? null) ? $data['permisos'] : []))));
        if ($name === '') service_return(['success'=>false,'message'=>'El nombre del rol es obligatorio.','data'=>[]]);
        if ($id > 0) {
            $current = $this->model->queryPrepared("SELECT codigo FROM roles WHERE id=:id", [':id'=>$id]);
            if (!$current || $current[0]['codigo'] === 'admin') service_return(['success'=>false,'message'=>'El rol administrador está protegido y no puede modificarse.','data'=>[]]);
            $this->model->executePrepared("UPDATE roles SET nombre=:nombre,descripcion=:descripcion WHERE id=:id", [':id'=>$id,':nombre'=>$name,':descripcion'=>$description]);
        } else {
            $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', strtolower($name));
            $code = trim((string)preg_replace('/[^a-z0-9]+/', '_', $ascii ?: strtolower($name)), '_');
            if ($code === '') $code = 'rol_' . substr(bin2hex(random_bytes(3)), 0, 6);
            $this->model->executePrepared("INSERT INTO roles(nombre,codigo,descripcion,es_sistema,activo) VALUES(:nombre,:codigo,:descripcion,0,1)", [':nombre'=>$name,':codigo'=>$code,':descripcion'=>$description]);
            $created = $this->model->queryPrepared("SELECT id FROM roles WHERE codigo=:codigo", [':codigo'=>$code]);
            $id = (int)($created[0]['id'] ?? 0);
        }
        $this->model->executePrepared("DELETE FROM rol_permisos WHERE rol_id=:rol", [':rol'=>$id]);
        foreach ($permissionIds as $permissionId) {
            $this->model->executePrepared("INSERT IGNORE INTO rol_permisos(rol_id,permiso_id) VALUES(:rol,:permiso)", [':rol'=>$id,':permiso'=>$permissionId]);
        }
        $this->auditWeb('ROL_GUARDADO', (string)$id . ' · ' . $name);
        service_return(['data'=>['id'=>$id],'message'=>'Rol y permisos guardados con éxito.']);
    }

    public function deleteRolWeb($data)
    {
        $id = (int)($data['id'] ?? 0);
        $roles = $this->model->queryPrepared("SELECT id,nombre,codigo,es_sistema FROM roles WHERE id=:id LIMIT 1", [':id'=>$id]);
        if (!$roles) service_return(['success'=>false,'message'=>'El rol no existe.','data'=>[]]);
        $role = $roles[0];
        if ($role['codigo'] === 'admin' || (int)$role['es_sistema'] === 1) {
            service_return(['success'=>false,'message'=>'Los roles base del sistema no pueden eliminarse.','data'=>[]]);
        }
        $assigned = $this->model->queryPrepared("SELECT COUNT(*) AS total FROM `user` WHERE rol_id=:rol", [':rol'=>$id]);
        $assignedTotal = (int)($assigned[0]['total'] ?? 0);
        if ($assignedTotal > 0) {
            service_return(['success'=>false,'message'=>'No se puede eliminar “'.$role['nombre'].'” porque está asignado a '.$assignedTotal.($assignedTotal === 1 ? ' usuario. Reasígnalo' : ' usuarios. Reasígnalos').' primero desde el módulo de usuarios.','data'=>[]]);
        }
        $this->auditWeb('ROL_ELIMINADO', (string)$id . ' · ' . $role['nombre']);
        $this->model->executePrepared("DELETE FROM roles WHERE id=:id", [':id'=>$id]);
        service_return(['data'=>['id'=>$id],'message'=>'Rol eliminado con éxito.']);
    }

    private function auditWeb(string $action, string $detail): void
    {
        $this->model->executePrepared(
            "INSERT INTO log_accesos(user_id,accion,ip_address,user_agent,detalle) VALUES(NULLIF(:usuario,''),:accion,:ip,:agent,:detalle)",
            [':usuario'=>$_SESSION['agronomo_user_id'] ?? '',':accion'=>$action,':ip'=>$_SERVER['REMOTE_ADDR'] ?? '',':agent'=>substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''),0,255),':detalle'=>substr($detail,0,255)]
        );
    }

    // Consecutivo numérico para nuevos usuarios (1000 en adelante), en vez de
    // los ids con timestamp/hex aleatorio usados antes — sigue el esquema
    // legado de otras apps de la suite (kamaje/Syscampo: COUNT(*)+1000).
    private function nextUserId(): string
    {
        $row = $this->model->queryPrepared(
            "SELECT MAX(CAST(id AS UNSIGNED)) AS max_id FROM `user` WHERE id REGEXP '^[0-9]+$'"
        );
        $max = (int)($row[0]['max_id'] ?? 0);
        return (string)max($max + 1, 1000);
    }

    public function getInsumosFormulasWeb($data)
    {
        $insumos = $this->model->queryPrepared(
            "SELECT i.*,COUNT(DISTINCT CASE WHEN fd.voided='1' THEN fd.formula_id END) AS total_formulas
             FROM insumos i LEFT JOIN formulas_detalle fd ON fd.insumo_id=i.id
             GROUP BY i.id ORDER BY i.categoria,i.nombre"
        );
        $formulas = $this->model->queryPrepared(
            "SELECT f.*,COUNT(DISTINCT CASE WHEN fd.voided='1' THEN fd.grupo_id END) AS total_grupos,
                    COUNT(CASE WHEN fd.voided='1' THEN 1 END) AS total_insumos
             FROM formulas f LEFT JOIN formulas_detalle fd ON fd.formula_id=f.id
             GROUP BY f.id ORDER BY f.voided DESC,f.descripcion"
        );
        $detalles = $this->model->queryPrepared(
            "SELECT fd.*,i.nombre AS insumo_nombre,i.unidad_medida,i.categoria
             FROM formulas_detalle fd JOIN insumos i ON i.id=fd.insumo_id
             WHERE fd.voided='1' ORDER BY fd.formula_id,fd.grupo_id,i.nombre"
        );
        service_return(['data'=>['insumos'=>$insumos,'formulas'=>$formulas,'detalles'=>$detalles],'message'=>'Catálogos de insumos y fórmulas consultados.']);
    }

    public function saveInsumoWeb($data)
    {
        $originalId = strtoupper(trim((string)($data['id_original'] ?? '')));
        $id = strtoupper(trim((string)($data['id'] ?? '')));
        $nombre = trim((string)($data['nombre'] ?? ''));
        $unidad = trim((string)($data['unidad_medida'] ?? ''));
        $categoria = trim((string)($data['categoria'] ?? ''));
        if (!preg_match('/^[A-Z0-9]{1,6}$/', $id) || $nombre === '' || strlen($nombre) > 30 || !in_array($unidad, ['Kg','Gr','Lt','Cc','Und'], true) || $categoria === '') {
            service_return(['success'=>false,'message'=>'Código, nombre, unidad y categoría son obligatorios. El código admite hasta 6 caracteres alfanuméricos.','data'=>[]]);
        }
        if ($originalId === '') {
            $exists = $this->model->queryPrepared("SELECT id FROM insumos WHERE id=:id", [':id'=>$id]);
            if ($exists) service_return(['success'=>false,'message'=>'El código del insumo ya está registrado.','data'=>[]]);
            $this->model->executePrepared(
                "INSERT INTO insumos(id,nombre,unidad_medida,categoria,sync,voided,created_at,updated_at,created_by) VALUES(:id,:nombre,:unidad,:categoria,'1','1',:created_at,:now,:actor)",
                [':id'=>$id,':nombre'=>$nombre,':unidad'=>$unidad,':categoria'=>$categoria,':actor'=>$_SESSION['agronomo_user_id'] ?? '',':now'=>date('Y-m-d H:i:s'),':created_at'=>date('Y-m-d H:i:s')]
            );
        } else {
            $this->model->executePrepared(
                "UPDATE insumos SET nombre=:nombre,unidad_medida=:unidad,categoria=:categoria,sync='1',updated_at=:now WHERE id=:id",
                [':id'=>$originalId,':nombre'=>$nombre,':unidad'=>$unidad,':categoria'=>$categoria,':now'=>date('Y-m-d H:i:s')]
            );
            $id = $originalId;
        }
        $this->auditWeb('INSUMO_GUARDADO', $id . ' · ' . $nombre);
        service_return(['data'=>['id'=>$id],'message'=>'Insumo guardado con éxito.']);
    }

    public function toggleInsumoWeb($data)
    {
        $id = trim((string)($data['id'] ?? ''));
        $this->model->executePrepared("UPDATE insumos SET voided=IF(voided='1','0','1'),sync='1',updated_at=:now WHERE id=:id", [':id'=>$id,':now'=>date('Y-m-d H:i:s')]);
        $this->auditWeb('INSUMO_ESTADO', $id);
        service_return(['data'=>['id'=>$id],'message'=>'Estado del insumo actualizado.']);
    }

    public function saveFormulaWeb($data)
    {
        $id = trim((string)($data['id'] ?? ''));
        $descripcion = trim((string)($data['descripcion'] ?? ''));
        $unidad = trim((string)($data['unidad'] ?? ''));
        $observacion = trim((string)($data['observacion'] ?? ''));
        $grupos = is_array($data['grupos'] ?? null) ? $data['grupos'] : [];
        if ($descripcion === '' || $unidad === '') service_return(['success'=>false,'message'=>'Descripción y unidad de la fórmula son obligatorias.','data'=>[]]);
        if (!$grupos) service_return(['success'=>false,'message'=>'Agrega al menos un grupo de insumos a la fórmula.','data'=>[]]);
        foreach ($grupos as $grupo) {
            $candidateIds = array_values(array_unique(array_filter(array_map('strval', is_array($grupo['insumo_ids'] ?? null) ? $grupo['insumo_ids'] : []))));
            if (!$candidateIds || trim((string)($grupo['dosis'] ?? '')) === '') {
                service_return(['success'=>false,'message'=>'Cada grupo requiere al menos un insumo y una dosis.','data'=>[]]);
            }
        }
        $now = date('Y-m-d H:i:s');
        if ($id === '') {
            $id = substr(bin2hex(random_bytes(10)), 0, 20);
            $this->model->executePrepared(
                "INSERT INTO formulas(id,descripcion,unidad,observacion,sync,voided,created_at,updated_at,created_by) VALUES(:id,:descripcion,:unidad,:observacion,'1','1',:created_at,:now,:actor)",
                [':id'=>$id,':descripcion'=>$descripcion,':unidad'=>$unidad,':observacion'=>$observacion,':actor'=>$_SESSION['agronomo_user_id'] ?? '',':now'=>$now,':created_at'=>$now]
            );
        } else {
            $this->model->executePrepared("UPDATE formulas SET descripcion=:descripcion,unidad=:unidad,observacion=:observacion,sync='1',updated_at=:now WHERE id=:id", [':id'=>$id,':descripcion'=>$descripcion,':unidad'=>$unidad,':observacion'=>$observacion,':now'=>$now]);
            $this->model->executePrepared("UPDATE formulas_detalle SET voided='0',sync='1',updated_at=:now WHERE formula_id=:formula AND voided='1'", [':formula'=>$id,':now'=>$now]);
        }
        foreach ($grupos as $grupo) {
            $insumoIds = array_values(array_unique(array_filter(array_map('strval', is_array($grupo['insumo_ids'] ?? null) ? $grupo['insumo_ids'] : []))));
            $dosis = trim((string)($grupo['dosis'] ?? ''));
            $groupId = substr(bin2hex(random_bytes(10)), 0, 20);
            foreach ($insumoIds as $insumoId) {
                $detailId = substr(bin2hex(random_bytes(10)), 0, 20);
                $this->model->executePrepared(
                    "INSERT INTO formulas_detalle(id,formula_id,grupo_id,insumo_id,dosis,observacion,sync,voided,created_at,updated_at,created_by) VALUES(:id,:formula,:grupo,:insumo,:dosis,:observacion,'1','1',:created_at,:now,:actor)",
                    [':id'=>$detailId,':formula'=>$id,':grupo'=>$groupId,':insumo'=>$insumoId,':dosis'=>$dosis,':observacion'=>trim((string)($grupo['observacion'] ?? '')),':actor'=>$_SESSION['agronomo_user_id'] ?? '',':now'=>$now,':created_at'=>$now]
                );
            }
        }
        $this->auditWeb('FORMULA_GUARDADA', $id . ' · ' . $descripcion);
        service_return(['data'=>['id'=>$id],'message'=>'Fórmula e insumos guardados con éxito.']);
    }

    public function toggleFormulaWeb($data)
    {
        $id = trim((string)($data['id'] ?? ''));
        $rows = $this->model->queryPrepared("SELECT voided FROM formulas WHERE id=:id", [':id'=>$id]);
        if (!$rows) service_return(['success'=>false,'message'=>'La fórmula no existe.','data'=>[]]);
        $next = $rows[0]['voided'] === '1' ? '0' : '1';
        $now = date('Y-m-d H:i:s');
        $this->model->executePrepared("UPDATE formulas SET voided=:estado,sync='1',updated_at=:now WHERE id=:id", [':id'=>$id,':estado'=>$next,':now'=>$now]);
        $this->model->executePrepared("UPDATE formulas_detalle SET voided=:estado,sync='1',updated_at=:now WHERE formula_id=:id", [':id'=>$id,':estado'=>$next,':now'=>$now]);
        $this->auditWeb('FORMULA_ESTADO', $id);
        service_return(['data'=>['id'=>$id,'voided'=>$next],'message'=>'Estado de la fórmula actualizado.']);
    }

    // =====================================================================
    // ADMINISTRACIÓN WEB DE FINCAS Y LOTES
    // =====================================================================

    public function getFincasWeb($data)
    {
        $scope = $this->fincaScopeClause('f.id', $this->assignedFincaIds());
        $where = $scope['sql'] !== '' ? "WHERE {$scope['sql']}" : '';
        $sql = "SELECT f.id, f.productor_id, f.descripcion, f.ubicacion, f.tecnico_id, f.voided,
                       f.created_at, f.updated_at, COUNT(l.id) AS total_lotes,
                       COALESCE(SUM(CASE WHEN l.voided = '1' THEN l.hectareas ELSE 0 END), 0) AS total_hectareas,
                       (SELECT COUNT(*) FROM usuario_fincas uf WHERE uf.finca_id=f.id) AS total_usuarios,
                       COALESCE((SELECT GROUP_CONCAT(u.name ORDER BY u.name SEPARATOR ', ')
                                 FROM usuario_fincas uf JOIN `user` u ON u.id=uf.usuario_id
                                 WHERE uf.finca_id=f.id),'') AS responsables,
                       ((SELECT COUNT(*) FROM predio_certificaciones pc
                         WHERE pc.finca_id=f.id AND pc.vigente=1 AND (pc.valido_hasta IS NULL OR DATEDIFF(pc.valido_hasta,CURDATE())<=90))
                        + IF(f.registro_ica=1 AND (f.vencimiento_ica IS NULL OR DATEDIFF(f.vencimiento_ica,CURDATE())<=90),1,0)
                        + IF(f.contrato_proveeduria=1 AND (f.fecha_vencimiento_contrato IS NULL OR DATEDIFF(f.fecha_vencimiento_contrato,CURDATE())<=90),1,0)) AS total_alertas,
                       ((SELECT COUNT(*) FROM predio_certificaciones pc
                         WHERE pc.finca_id=f.id AND pc.vigente=1 AND (pc.valido_hasta IS NULL OR DATEDIFF(pc.valido_hasta,CURDATE())<=30))
                        + IF(f.registro_ica=1 AND (f.vencimiento_ica IS NULL OR DATEDIFF(f.vencimiento_ica,CURDATE())<=30),1,0)
                        + IF(f.contrato_proveeduria=1 AND (f.fecha_vencimiento_contrato IS NULL OR DATEDIFF(f.fecha_vencimiento_contrato,CURDATE())<=30),1,0)) AS alertas_criticas
                FROM fincas f
                LEFT JOIN lotes l ON l.finca_id = f.id AND l.voided = '1'
                $where
                GROUP BY f.id, f.productor_id, f.descripcion, f.ubicacion, f.tecnico_id, f.voided,
                         f.created_at, f.updated_at
                ORDER BY f.voided DESC, f.descripcion";
        $fincas = $this->model->queryPrepared($sql, $scope['params']);
        service_return(['data' => $fincas, 'message' => 'Fincas consultadas con éxito.']);
    }

    public function getFincaDetalleWeb($data)
    {
        $fincaId = trim((string)($data['finca_id'] ?? ''));
        if ($fincaId === '') {
            service_return(['success' => false, 'message' => 'La finca es requerida.', 'data' => []]);
        }
        $this->assertFincaAccesible($fincaId);

        $lotes = $this->model->queryPrepared(
            "SELECT l.id, l.finca_id, l.cultivo_id, l.nombre, l.hectareas, l.voided,
                    l.created_at, l.updated_at, c.descripcion AS cultivo,
                    COUNT(DISTINCT vt.id) AS total_visitas
             FROM lotes l
             LEFT JOIN cultivos c ON c.id = l.cultivo_id
             LEFT JOIN visita_detalles_lote vdl ON vdl.lote_id = l.id AND vdl.voided = '1'
             LEFT JOIN visitas_tecnicas vt ON vt.id = vdl.visita_id AND vt.voided = '1'
             WHERE l.finca_id = :finca_id
             GROUP BY l.id, l.finca_id, l.cultivo_id, l.nombre, l.hectareas, l.voided,
                      l.created_at, l.updated_at, c.descripcion
             ORDER BY l.voided DESC, l.nombre",
            [':finca_id' => $fincaId]
        );
        $cultivos = $this->model->queryPrepared(
            "SELECT id, descripcion FROM cultivos WHERE voided = '1' ORDER BY descripcion"
        );
        $usuarios = $this->model->queryPrepared(
            "SELECT u.id,u.name,u.`user`,CASE WHEN uf.usuario_id IS NULL THEN 0 ELSE 1 END AS asignado
             FROM `user` u
             LEFT JOIN usuario_fincas uf ON uf.usuario_id=u.id AND uf.finca_id=:finca
             WHERE u.void='1' ORDER BY u.name,u.`user`",
            [':finca'=>$fincaId]
        );
        $predios = $this->model->queryPrepared(
            "SELECT f.*, p.tipo AS productor_tipo, p.nombre AS productor_nombre,
                    p.cedula AS productor_cedula, p.nit AS productor_nit, p.dv AS productor_dv,
                    p.telefono AS productor_telefono, p.correo AS productor_correo
             FROM fincas f
             LEFT JOIN productores p ON p.id=f.productor_id
             WHERE f.id=:finca LIMIT 1",
            [':finca'=>$fincaId]
        );
        if (!$predios) {
            service_return(['success'=>false,'message'=>'La finca no existe.','data'=>[]]);
        }
        $certificaciones = $this->model->queryPrepared(
            "SELECT pc.tipo,pc.vigente,pc.valido_hasta,COALESCE(tc.nombre,pc.tipo) AS nombre,
                    COALESCE(tc.requiere_vencimiento,1) AS requiere_vencimiento
             FROM predio_certificaciones pc
             LEFT JOIN tipos_certificacion tc ON tc.codigo=pc.tipo
             WHERE pc.finca_id=:finca ORDER BY nombre",
            [':finca'=>$fincaId]
        );
        service_return(['data' => ['lotes' => $lotes, 'cultivos' => $cultivos, 'usuarios'=>$usuarios, 'predio'=>$predios[0], 'certificaciones'=>$certificaciones], 'message' => 'Finca consultada con éxito.']);
    }

    public function getUsuariosFincaWeb($data)
    {
        $fincaId = trim((string)($data['finca_id'] ?? ''));
        $usuarios = $this->model->queryPrepared(
            "SELECT u.id,u.name,u.`user`,CASE WHEN uf.usuario_id IS NULL THEN 0 ELSE 1 END AS asignado
             FROM `user` u
             LEFT JOIN usuario_fincas uf ON uf.usuario_id=u.id AND uf.finca_id=NULLIF(:finca,'')
             WHERE u.void='1' ORDER BY u.name,u.`user`",
            [':finca'=>$fincaId]
        );
        service_return(['data'=>$usuarios,'message'=>'Usuarios disponibles consultados.']);
    }

    public function saveFincaWeb($data)
    {
        $id = trim((string)($data['id'] ?? ''));
        $descripcion = trim((string)($data['descripcion'] ?? ''));
        if ($descripcion === '') {
            service_return(['success' => false, 'message' => 'El nombre de la finca es obligatorio.', 'data' => []]);
        }
        $userIds = array_values(array_unique(array_filter(array_map('strval', is_array($data['usuario_ids'] ?? null) ? $data['usuario_ids'] : []))));
        if ($userIds) {
            $userPlaceholders = [];
            $userParams = [];
            foreach ($userIds as $index => $userId) {
                $placeholder = ':usuario_' . $index;
                $userPlaceholders[] = $placeholder;
                $userParams[$placeholder] = $userId;
            }
            $validUsers = $this->model->queryPrepared(
                "SELECT id FROM `user` WHERE void='1' AND id IN (" . implode(',', $userPlaceholders) . ")",
                $userParams
            );
            if (count($validUsers) !== count($userIds)) {
                service_return(['success'=>false,'message'=>'Uno o más usuarios seleccionados no están disponibles.','data'=>[]]);
            }
        }
        if ($id === '') {
            $id = bin2hex(random_bytes(16));
            $this->model->executePrepared(
                "INSERT INTO fincas (id, descripcion, ubicacion, tecnico_id, sync, voided, created_at, updated_at, created_by)
                 VALUES (:id, :descripcion, :ubicacion, NULLIF(:tecnico_id, ''), '1', '1', :created_at, :now, :created_by)",
                [':id'=>$id, ':descripcion'=>$descripcion, ':ubicacion'=>trim((string)($data['ubicacion'] ?? '')),
                 ':tecnico_id'=>trim((string)($data['tecnico_id'] ?? '')), ':created_by'=>(string)($data['created_by'] ?? ''), ':now'=>date('Y-m-d H:i:s'), ':created_at'=>date('Y-m-d H:i:s')]
            );
            $message = 'Finca creada con éxito.';
        } else {
            $this->assertFincaAccesible($id);
            $this->model->executePrepared(
                "UPDATE fincas SET descripcion=:descripcion, ubicacion=:ubicacion,
                 tecnico_id=NULLIF(:tecnico_id, ''), updated_at=:now, sync='1' WHERE id=:id",
                [':id'=>$id, ':descripcion'=>$descripcion, ':ubicacion'=>trim((string)($data['ubicacion'] ?? '')),
                 ':tecnico_id'=>trim((string)($data['tecnico_id'] ?? '')), ':now'=>date('Y-m-d H:i:s')]
            );
            $message = 'Finca actualizada con éxito.';
        }
        $this->model->executePrepared("DELETE FROM usuario_fincas WHERE finca_id=:finca", [':finca'=>$id]);
        foreach ($userIds as $userId) {
            $this->model->executePrepared(
                "INSERT INTO usuario_fincas(usuario_id,finca_id,created_by) VALUES(:usuario,:finca,:actor)",
                [':usuario'=>$userId,':finca'=>$id,':actor'=>$_SESSION['agronomo_user_id'] ?? '']
            );
        }
        service_return(['data' => ['id' => $id], 'message' => $message]);
    }

    public function toggleFincaWeb($data)
    {
        $id = trim((string)($data['id'] ?? ''));
        if ($id === '') {
            service_return(['success' => false, 'message' => 'Debes seleccionar una finca.', 'data' => []]);
        }
        $this->assertFincaAccesible($id);
        $this->model->executePrepared(
            "UPDATE fincas SET voided = IF(voided = '1', '0', '1'), updated_at = :now, sync = '1' WHERE id = :id",
            [':id' => $id, ':now' => date('Y-m-d H:i:s')]
        );
        service_return(['data' => ['id' => $id], 'message' => 'Estado de la finca actualizado.']);
    }

    public function toggleLoteWeb($data)
    {
        $id = trim((string)($data['id'] ?? ''));
        if ($id === '') {
            service_return(['success' => false, 'message' => 'Debes seleccionar un lote.', 'data' => []]);
        }
        $lote = $this->model->queryPrepared("SELECT finca_id FROM lotes WHERE id = :id LIMIT 1", [':id' => $id]);
        if (!$lote) {
            service_return(['success' => false, 'message' => 'El lote no existe.', 'data' => []]);
        }
        $this->assertFincaAccesible($lote[0]['finca_id']);
        $this->model->executePrepared(
            "UPDATE lotes SET voided = IF(voided = '1', '0', '1'), updated_at = :now, sync = '1' WHERE id = :id",
            [':id' => $id, ':now' => date('Y-m-d H:i:s')]
        );
        service_return(['data' => ['id' => $id], 'message' => 'Estado del lote actualizado.']);
    }

    public function savePredioCompletoWeb($data)
    {
        $fincaId = trim((string)($data['finca_id'] ?? ''));
        if ($fincaId !== '') $this->assertFincaAccesible($fincaId);
        $productor = is_array($data['productor'] ?? null) ? $data['productor'] : [];
        $predio = is_array($data['predio'] ?? null) ? $data['predio'] : [];
        if (trim((string)($productor['nombre'] ?? '')) === '' || trim((string)($predio['descripcion'] ?? '')) === '') {
            service_return(['success'=>false,'message'=>'Productor y nombre del predio son obligatorios.','data'=>[]]);
        }
        if (!empty($predio['contrato_proveeduria']) && empty($predio['fecha_vencimiento_contrato'])) {
            service_return(['success'=>false,'message'=>'Indica la fecha de vencimiento del contrato de proveeduría.','data'=>[]]);
        }
        if (!empty($predio['registro_ica']) && empty($predio['vencimiento_ica'])) {
            service_return(['success'=>false,'message'=>'Indica la fecha de vencimiento del registro ICA.','data'=>[]]);
        }
        foreach (($data['certificaciones'] ?? []) as $tipo => $certificacion) {
            if (!is_array($certificacion) || empty($certificacion['vigente'])) continue;
            $catalogo = $this->model->queryPrepared(
                "SELECT codigo,nombre,requiere_vencimiento FROM tipos_certificacion WHERE codigo=:codigo LIMIT 1",
                [':codigo'=>(string)$tipo]
            );
            if (!$catalogo) service_return(['success'=>false,'message'=>'La certificación seleccionada ya no existe en el catálogo.','data'=>[]]);
            if (!empty($catalogo[0]['requiere_vencimiento']) && empty($certificacion['valido_hasta'])) {
                service_return(['success'=>false,'message'=>'Indica la fecha de vigencia de ' . $catalogo[0]['nombre'] . '.','data'=>[]]);
            }
        }
        $departamentoId = (int)($predio['departamento_id'] ?? 0);
        $municipioId = (int)($predio['municipio_id'] ?? 0);
        $localidadId = (int)($predio['localidad_rural_id'] ?? 0);
        if ($departamentoId <= 0 || $municipioId <= 0) {
            service_return(['success'=>false,'message'=>'Departamento y municipio son obligatorios.','data'=>[]]);
        }
        $territorio = $this->model->queryPrepared(
            "SELECT d.id AS departamento_id, d.nombre AS departamento_nombre,
                    m.id AS municipio_id, m.nombre AS municipio_nombre,
                    l.nombre AS localidad_nombre
             FROM geo_departamentos d
             JOIN geo_municipios m ON m.departamento_id=d.id AND m.id=:municipio
             LEFT JOIN geo_localidades_rurales l ON l.id=NULLIF(:localidad_join,0) AND l.municipio_id=m.id AND l.activo=1
             WHERE d.id=:departamento AND d.activo=1 AND m.activo=1
               AND (:localidad=0 OR EXISTS (
                   SELECT 1 FROM geo_localidades_rurales l
                   WHERE l.id=:localidad_rel AND l.municipio_id=m.id AND l.activo=1
               ))",
            [':departamento'=>$departamentoId, ':municipio'=>$municipioId, ':localidad'=>$localidadId, ':localidad_rel'=>$localidadId, ':localidad_join'=>$localidadId]
        );
        if (!$territorio) {
            service_return(['success'=>false,'message'=>'La ubicación seleccionada no corresponde a la división territorial registrada.','data'=>[]]);
        }
        $existing = [];
        if ($fincaId !== '') {
            $existing = $this->model->queryPrepared("SELECT id,productor_id FROM fincas WHERE id=:id LIMIT 1", [':id'=>$fincaId]);
            if (!$existing) service_return(['success'=>false,'message'=>'La finca que deseas completar no existe.','data'=>[]]);
        } else {
            $fincaId = bin2hex(random_bytes(16));
        }
        $productorId = $existing[0]['productor_id'] ?? '';
        $productorParams = [':tipo'=>$productor['tipo'] ?? 'TERCERO',':nombre'=>trim((string)$productor['nombre']),':cedula'=>$productor['cedula'] ?? '',':nit'=>$productor['nit'] ?? '',':dv'=>$productor['dv'] ?? '',':telefono'=>$productor['telefono'] ?? '',':correo'=>$productor['correo'] ?? ''];
        if ($productorId === '' || $productorId === null) {
            $productorId = bin2hex(random_bytes(16));
            $this->model->executePrepared("INSERT INTO productores (id,tipo,nombre,cedula,nit,dv,telefono,correo) VALUES (:id,:tipo,:nombre,:cedula,:nit,:dv,:telefono,:correo)", array_merge([':id'=>$productorId], $productorParams));
        } else {
            $this->model->executePrepared("UPDATE productores SET tipo=:tipo,nombre=:nombre,cedula=:cedula,nit=:nit,dv=:dv,telefono=:telefono,correo=:correo,updated_at=:now WHERE id=:id", array_merge([':id'=>$productorId, ':now'=>date('Y-m-d H:i:s')], $productorParams));
        }
        $locationParts = array_filter([$territorio[0]['localidad_nombre'] ?? '', $territorio[0]['municipio_nombre'] ?? '', $territorio[0]['departamento_nombre'] ?? '']);
        $fincaParams = [':id'=>$fincaId,':productor'=>$productorId,':nombre'=>trim((string)$predio['descripcion']),':ubicacion'=>implode(', ', $locationParts),':departamento'=>$territorio[0]['departamento_nombre'],':municipio'=>$territorio[0]['municipio_nombre'],':localidad'=>$territorio[0]['localidad_nombre'] ?? '',':departamento_id'=>$departamentoId,':municipio_id'=>$municipioId,':localidad_id'=>$localidadId ?: null,':estado'=>$predio['estado_predio'] ?? 'ACTIVO',':hectareas'=>($predio['hectareas_totales'] ?? '') !== '' ? $predio['hectareas_totales'] : null,':latitud'=>($predio['latitud'] ?? '') !== '' ? $predio['latitud'] : null,':longitud'=>($predio['longitud'] ?? '') !== '' ? $predio['longitud'] : null,':url'=>$predio['url_localizacion'] ?? '',':contrato'=>!empty($predio['contrato_proveeduria'])?1:0,':fecha_contrato'=>$predio['fecha_contrato'] ?? '',':vence_contrato'=>$predio['fecha_vencimiento_contrato'] ?? '',':version'=>$predio['version_contrato'] ?? '',':ica'=>!empty($predio['registro_ica'])?1:0,':numero_ica'=>$predio['numero_ica'] ?? '',':vence_ica'=>$predio['vencimiento_ica'] ?? '',':anticipo'=>!empty($predio['anticipo'])?1:0,':valor'=>($predio['valor_anticipo'] ?? '') !== '' ? $predio['valor_anticipo'] : null,':usuario'=>$data['created_by'] ?? '',':now'=>date('Y-m-d H:i:s'),':created_at'=>date('Y-m-d H:i:s')];
        if ($existing) {
            $updateParams = $fincaParams;
            unset($updateParams[':usuario'], $updateParams[':created_at']);
            $this->model->executePrepared("UPDATE fincas SET productor_id=:productor,descripcion=:nombre,ubicacion=:ubicacion,departamento=:departamento,ciudad=:municipio,vereda=:localidad,departamento_id=:departamento_id,municipio_id=:municipio_id,localidad_rural_id=:localidad_id,estado_predio=:estado,hectareas_totales=:hectareas,latitud=:latitud,longitud=:longitud,url_localizacion=:url,contrato_proveeduria=:contrato,fecha_contrato=NULLIF(:fecha_contrato,''),fecha_vencimiento_contrato=NULLIF(:vence_contrato,''),version_contrato=:version,registro_ica=:ica,numero_ica=:numero_ica,vencimiento_ica=NULLIF(:vence_ica,''),anticipo=:anticipo,valor_anticipo=:valor,sync='1',updated_at=:now WHERE id=:id", $updateParams);
        } else {
            $this->model->executePrepared("INSERT INTO fincas (id,productor_id,descripcion,ubicacion,departamento,ciudad,vereda,departamento_id,municipio_id,localidad_rural_id,estado_predio,hectareas_totales,latitud,longitud,url_localizacion,contrato_proveeduria,fecha_contrato,fecha_vencimiento_contrato,version_contrato,registro_ica,numero_ica,vencimiento_ica,anticipo,valor_anticipo,sync,voided,created_at,updated_at,created_by) VALUES (:id,:productor,:nombre,:ubicacion,:departamento,:municipio,:localidad,:departamento_id,:municipio_id,:localidad_id,:estado,:hectareas,:latitud,:longitud,:url,:contrato,NULLIF(:fecha_contrato,''),NULLIF(:vence_contrato,''),:version,:ica,:numero_ica,NULLIF(:vence_ica,''),:anticipo,:valor,'1','1',:created_at,:now,:usuario)", $fincaParams);
        }
        $this->model->executePrepared("DELETE FROM predio_certificaciones WHERE finca_id=:finca", [':finca'=>$fincaId]);
        foreach (($data['certificaciones'] ?? []) as $tipo => $cert) {
            if (!is_array($cert) || empty($cert['vigente'])) continue;
            $this->model->executePrepared("INSERT INTO predio_certificaciones (id,finca_id,tipo,vigente,valido_hasta) VALUES (:id,:finca,:tipo,1,NULLIF(:fecha,''))", [':id'=>bin2hex(random_bytes(16)),':finca'=>$fincaId,':tipo'=>$tipo,':fecha'=>$cert['valido_hasta'] ?? '']);
        }
        service_return(['data'=>['id'=>$fincaId],'message'=>$existing ? 'Predio actualizado completamente con éxito.' : 'Predio registrado completamente con éxito.']);
    }

    public function getTiposCertificacionWeb($data)
    {
        $rows = $this->model->queryPrepared(
            "SELECT tc.codigo,tc.nombre,tc.descripcion,tc.requiere_vencimiento,tc.activo,
                    tc.created_at,tc.updated_at,COUNT(pc.id) AS total_predios
             FROM tipos_certificacion tc
             LEFT JOIN predio_certificaciones pc ON pc.tipo=tc.codigo AND pc.vigente=1
             GROUP BY tc.codigo,tc.nombre,tc.descripcion,tc.requiere_vencimiento,tc.activo,tc.created_at,tc.updated_at
             ORDER BY tc.activo DESC,tc.nombre"
        );
        service_return(['data'=>$rows,'message'=>'Catálogo de certificaciones consultado con éxito.']);
    }

    public function saveTipoCertificacionWeb($data)
    {
        $original = strtoupper(trim((string)($data['codigo_original'] ?? '')));
        $nombre = trim((string)($data['nombre'] ?? ''));
        $codigo = strtoupper(trim((string)($data['codigo'] ?? '')));
        $descripcion = trim((string)($data['descripcion'] ?? ''));
        if ($nombre === '') service_return(['success'=>false,'message'=>'El nombre de la certificación es obligatorio.','data'=>[]]);
        if ($codigo === '') {
            $base = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $nombre);
            $codigo = strtoupper(preg_replace('/[^A-Z0-9]+/', '_', (string)$base));
            $codigo = trim($codigo, '_');
        }
        if (!preg_match('/^[A-Z][A-Z0-9_]{1,49}$/', $codigo)) {
            service_return(['success'=>false,'message'=>'El código debe iniciar con una letra y usar únicamente letras, números o guion bajo.','data'=>[]]);
        }
        $duplicate = $this->model->queryPrepared(
            "SELECT codigo FROM tipos_certificacion WHERE (codigo=:codigo OR nombre=:nombre) AND codigo<>:original LIMIT 1",
            [':codigo'=>$codigo,':nombre'=>$nombre,':original'=>$original]
        );
        if ($duplicate) service_return(['success'=>false,'message'=>'Ya existe una certificación con ese nombre o código.','data'=>[]]);
        $now = date('Y-m-d H:i:s');
        if ($original === '') {
            $this->model->executePrepared(
                "INSERT INTO tipos_certificacion(codigo,nombre,descripcion,requiere_vencimiento,activo,created_at,updated_at,created_by)
                 VALUES(:codigo,:nombre,NULLIF(:descripcion,''),:vence,1,:created_at,:updated_at,:usuario)",
                [':codigo'=>$codigo,':nombre'=>$nombre,':descripcion'=>$descripcion,':vence'=>!empty($data['requiere_vencimiento'])?1:0,':created_at'=>$now,':updated_at'=>$now,':usuario'=>$_SESSION['agronomo_user_id'] ?? '']
            );
            $message = 'Certificación creada con éxito.';
        } else {
            if ($codigo !== $original) service_return(['success'=>false,'message'=>'El código no puede cambiarse después de crear la certificación.','data'=>[]]);
            $this->model->executePrepared(
                "UPDATE tipos_certificacion SET nombre=:nombre,descripcion=NULLIF(:descripcion,''),requiere_vencimiento=:vence,updated_at=:now WHERE codigo=:codigo",
                [':codigo'=>$original,':nombre'=>$nombre,':descripcion'=>$descripcion,':vence'=>!empty($data['requiere_vencimiento'])?1:0,':now'=>$now]
            );
            $message = 'Certificación actualizada con éxito.';
        }
        service_return(['data'=>['codigo'=>$codigo],'message'=>$message]);
    }

    public function toggleTipoCertificacionWeb($data)
    {
        $codigo = strtoupper(trim((string)($data['codigo'] ?? '')));
        $rows = $this->model->queryPrepared("SELECT activo FROM tipos_certificacion WHERE codigo=:codigo LIMIT 1", [':codigo'=>$codigo]);
        if (!$rows) service_return(['success'=>false,'message'=>'La certificación no existe.','data'=>[]]);
        $activo = (int)$rows[0]['activo'] === 1 ? 0 : 1;
        $this->model->executePrepared("UPDATE tipos_certificacion SET activo=:activo,updated_at=:now WHERE codigo=:codigo", [':activo'=>$activo,':now'=>date('Y-m-d H:i:s'),':codigo'=>$codigo]);
        service_return(['data'=>['codigo'=>$codigo,'activo'=>$activo],'message'=>$activo ? 'Certificación activada.' : 'Certificación inactivada. El historial se conserva.']);
    }

    public function getDivisionTerritorialWeb($data)
    {
        $nivel = $data['nivel'] ?? 'departamentos';
        if ($nivel === 'municipios') {
            $rows = $this->model->queryPrepared("SELECT id,codigo_dane,nombre FROM geo_municipios WHERE departamento_id=:id AND activo=1 ORDER BY nombre", [':id'=>(int)($data['parent_id'] ?? 0)]);
        } elseif ($nivel === 'localidades') {
            $rows = $this->model->queryPrepared("SELECT id,nombre,tipo FROM geo_localidades_rurales WHERE municipio_id=:id AND activo=1 ORDER BY nombre", [':id'=>(int)($data['parent_id'] ?? 0)]);
        } else {
            $rows = $this->model->queryPrepared("SELECT id,codigo_dane,nombre FROM geo_departamentos WHERE activo=1 ORDER BY nombre");
        }
        service_return(['data'=>$rows,'message'=>'División territorial consultada.']);
    }

    public function saveLoteWeb($data)
    {
        $id = trim((string)($data['id'] ?? ''));
        $fincaId = trim((string)($data['finca_id'] ?? ''));
        $cultivoId = trim((string)($data['cultivo_id'] ?? ''));
        $nombre = trim((string)($data['nombre'] ?? ''));
        if ($fincaId === '' || $cultivoId === '' || $nombre === '') {
            service_return(['success' => false, 'message' => 'Finca, cultivo y nombre del lote son obligatorios.', 'data' => []]);
        }
        $this->assertFincaAccesible($fincaId);
        $hectareas = ($data['hectareas'] ?? '') === '' ? null : (float)$data['hectareas'];
        if ($id === '') {
            $id = bin2hex(random_bytes(16));
            $this->model->executePrepared(
                "INSERT INTO lotes (id, finca_id, cultivo_id, nombre, hectareas, sync, voided, created_at, updated_at, created_by)
                 VALUES (:id, :finca_id, :cultivo_id, :nombre, :hectareas, '1', '1', :created_at, :now, :created_by)",
                [':id'=>$id, ':finca_id'=>$fincaId, ':cultivo_id'=>$cultivoId, ':nombre'=>$nombre,
                 ':hectareas'=>$hectareas, ':created_by'=>(string)($data['created_by'] ?? ''), ':now'=>date('Y-m-d H:i:s'), ':created_at'=>date('Y-m-d H:i:s')]
            );
            $message = 'Lote creado y asignado con éxito.';
        } else {
            $this->model->executePrepared(
                "UPDATE lotes SET finca_id=:finca_id, cultivo_id=:cultivo_id, nombre=:nombre,
                 hectareas=:hectareas, updated_at=:now, sync='1' WHERE id=:id",
                [':id'=>$id, ':finca_id'=>$fincaId, ':cultivo_id'=>$cultivoId, ':nombre'=>$nombre, ':hectareas'=>$hectareas, ':now'=>date('Y-m-d H:i:s')]
            );
            $message = 'Lote actualizado con éxito.';
        }
        service_return(['data' => ['id' => $id], 'message' => $message]);
    }

    // =====================================================================
    // ADMINISTRACIÓN WEB DE CULTIVOS Y LABORES
    // =====================================================================

    public function getCultivosWeb($data)
    {
        $sql = "SELECT c.id, c.descripcion, c.codigo, c.voided, c.created_at, c.updated_at,
                       COUNT(l.id) AS total_labores
                FROM cultivos c
                LEFT JOIN labores l ON l.cultivo_id = c.id AND l.voided = '1'
                GROUP BY c.id, c.descripcion, c.codigo, c.voided, c.created_at, c.updated_at
                ORDER BY c.voided DESC, c.descripcion";
        $cultivos = $this->model->queryPrepared($sql);
        service_return(['data' => $cultivos, 'message' => 'Cultivos consultados con éxito.']);
    }

    public function saveCultivoWeb($data)
    {
        $id = trim((string)($data['id'] ?? ''));
        $descripcion = trim((string)($data['descripcion'] ?? ''));
        $codigo = trim((string)($data['codigo'] ?? ''));
        if ($descripcion === '') {
            service_return(['success' => false, 'message' => 'El nombre del cultivo es obligatorio.', 'data' => []]);
        }
        $duplicate = $this->model->queryPrepared(
            "SELECT id FROM cultivos WHERE descripcion = :descripcion AND id <> :id LIMIT 1",
            [':descripcion' => $descripcion, ':id' => $id]
        );
        if ($duplicate) {
            service_return(['success' => false, 'message' => 'Ya existe un cultivo con ese nombre.', 'data' => []]);
        }
        if ($id === '') {
            $id = bin2hex(random_bytes(16));
            $this->model->executePrepared(
                "INSERT INTO cultivos (id, descripcion, codigo, sync, voided, created_at, updated_at, created_by)
                 VALUES (:id, :descripcion, NULLIF(:codigo, ''), '1', '1', :created_at, :now, :created_by)",
                [':id' => $id, ':descripcion' => $descripcion, ':codigo' => $codigo, ':created_by' => (string)($data['created_by'] ?? ''), ':now' => date('Y-m-d H:i:s'), ':created_at' => date('Y-m-d H:i:s')]
            );
            $message = 'Cultivo creado con éxito.';
        } else {
            $this->model->executePrepared(
                "UPDATE cultivos SET descripcion = :descripcion, codigo = NULLIF(:codigo, ''), updated_at = :now, sync = '1' WHERE id = :id",
                [':id' => $id, ':descripcion' => $descripcion, ':codigo' => $codigo, ':now' => date('Y-m-d H:i:s')]
            );
            $message = 'Cultivo actualizado con éxito.';
        }
        service_return(['data' => ['id' => $id], 'message' => $message]);
    }

    public function toggleCultivoWeb($data)
    {
        $id = trim((string)($data['id'] ?? ''));
        if ($id === '') {
            service_return(['success' => false, 'message' => 'Debes seleccionar un cultivo.', 'data' => []]);
        }
        $this->model->executePrepared(
            "UPDATE cultivos SET voided = IF(voided = '1', '0', '1'), updated_at = :now, sync = '1' WHERE id = :id",
            [':id' => $id, ':now' => date('Y-m-d H:i:s')]
        );
        service_return(['data' => ['id' => $id], 'message' => 'Estado del cultivo actualizado.']);
    }

    public function getLaboresWeb($data)
    {
        $cultivoId = trim((string)($data['cultivo_id'] ?? ''));
        $where = $cultivoId === '' ? '' : 'WHERE l.cultivo_id = :cultivo_id';
        $params = $cultivoId === '' ? [] : [':cultivo_id' => $cultivoId];
        $labores = $this->model->queryPrepared(
            "SELECT l.id, l.nombre, l.codigo, l.cultivo_id, l.categoria_labor_id, l.voided, l.created_at, l.updated_at,
                    cl.descripcion AS categoria_nombre, c.descripcion AS cultivo_nombre, c.codigo AS cultivo_codigo
             FROM labores l
             LEFT JOIN categorias_labor cl ON cl.id = l.categoria_labor_id
             JOIN cultivos c ON c.id = l.cultivo_id
             {$where}
             ORDER BY c.descripcion, cl.descripcion, l.voided DESC, l.nombre",
            $params
        );
        service_return(['data' => $labores, 'message' => 'Labores consultadas con éxito.']);
    }

    public function saveLaborWeb($data)
    {
        $id = trim((string)($data['id'] ?? ''));
        $cultivoId = trim((string)($data['cultivo_id'] ?? ''));
        $nombre = trim((string)($data['nombre'] ?? ''));
        $codigo = trim((string)($data['codigo'] ?? ''));
        $categoriaId = trim((string)($data['categoria_labor_id'] ?? ''));
        if ($cultivoId === '' || $nombre === '') {
            service_return(['success' => false, 'message' => 'Cultivo y nombre de la labor son obligatorios.', 'data' => []]);
        }
        $crop = $this->model->queryPrepared("SELECT id FROM cultivos WHERE id = :id AND voided = '1'", [':id' => $cultivoId]);
        if (!$crop) {
            service_return(['success' => false, 'message' => 'El cultivo seleccionado no está disponible.', 'data' => []]);
        }
        if ($categoriaId !== '') {
            $categoria = $this->model->queryPrepared("SELECT id FROM categorias_labor WHERE id = :id AND voided = '1'", [':id' => $categoriaId]);
            if (!$categoria) {
                service_return(['success' => false, 'message' => 'La categoría seleccionada no está disponible.', 'data' => []]);
            }
        }
        if ($id === '') {
            $id = bin2hex(random_bytes(16));
            $this->model->executePrepared(
                "INSERT INTO labores (id, nombre, codigo, cultivo_id, categoria_labor_id, sync, voided, created_at, updated_at, created_by)
                 VALUES (:id, :nombre, NULLIF(:codigo, ''), :cultivo_id, NULLIF(:categoria_id, ''), '1', '1', :created_at, :now, :created_by)",
                [':id' => $id, ':nombre' => $nombre, ':codigo' => $codigo, ':cultivo_id' => $cultivoId, ':categoria_id' => $categoriaId, ':created_by' => (string)($data['created_by'] ?? ''), ':now' => date('Y-m-d H:i:s'), ':created_at' => date('Y-m-d H:i:s')]
            );
            $message = 'Labor creada con éxito.';
        } else {
            $this->model->executePrepared(
                "UPDATE labores SET nombre = :nombre, codigo = NULLIF(:codigo, ''), cultivo_id = :cultivo_id,
                 categoria_labor_id = NULLIF(:categoria_id, ''), updated_at = :now, sync = '1' WHERE id = :id",
                [':id' => $id, ':nombre' => $nombre, ':codigo' => $codigo, ':cultivo_id' => $cultivoId, ':categoria_id' => $categoriaId, ':now' => date('Y-m-d H:i:s')]
            );
            $message = 'Labor actualizada con éxito.';
        }
        service_return(['data' => ['id' => $id], 'message' => $message]);
    }

    public function toggleLaborWeb($data)
    {
        $id = trim((string)($data['id'] ?? ''));
        if ($id === '') {
            service_return(['success' => false, 'message' => 'Debes seleccionar una labor.', 'data' => []]);
        }
        $this->model->executePrepared(
            "UPDATE labores SET voided = IF(voided = '1', '0', '1'), updated_at = :now, sync = '1' WHERE id = :id",
            [':id' => $id, ':now' => date('Y-m-d H:i:s')]
        );
        service_return(['data' => ['id' => $id], 'message' => 'Estado de la labor actualizado.']);
    }

    // =====================================================================
    // ADMINISTRACIÓN WEB DE CATEGORÍAS DE LABOR
    // =====================================================================

    public function getCategoriasLaborWeb($data)
    {
        $sql = "SELECT cl.id, cl.descripcion, cl.codigo, cl.voided, cl.created_at, cl.updated_at,
                       COUNT(l.id) AS total_labores
                FROM categorias_labor cl
                LEFT JOIN labores l ON l.categoria_labor_id = cl.id AND l.voided = '1'
                GROUP BY cl.id, cl.descripcion, cl.codigo, cl.voided, cl.created_at, cl.updated_at
                ORDER BY cl.voided DESC, cl.descripcion";
        $categorias = $this->model->queryPrepared($sql);
        service_return(['data' => $categorias, 'message' => 'Categorías de labor consultadas con éxito.']);
    }

    public function saveCategoriaLaborWeb($data)
    {
        $id = trim((string)($data['id'] ?? ''));
        $descripcion = trim((string)($data['descripcion'] ?? ''));
        $codigo = trim((string)($data['codigo'] ?? ''));
        if ($descripcion === '') {
            service_return(['success' => false, 'message' => 'El nombre de la categoría es obligatorio.', 'data' => []]);
        }
        $duplicate = $this->model->queryPrepared(
            "SELECT id FROM categorias_labor WHERE descripcion = :descripcion AND id <> :id LIMIT 1",
            [':descripcion' => $descripcion, ':id' => $id]
        );
        if ($duplicate) {
            service_return(['success' => false, 'message' => 'Ya existe una categoría con ese nombre.', 'data' => []]);
        }
        if ($id === '') {
            $id = bin2hex(random_bytes(16));
            $this->model->executePrepared(
                "INSERT INTO categorias_labor (id, descripcion, codigo, sync, voided, created_at, updated_at, created_by)
                 VALUES (:id, :descripcion, NULLIF(:codigo, ''), '1', '1', :created_at, :now, :created_by)",
                [':id' => $id, ':descripcion' => $descripcion, ':codigo' => $codigo, ':created_by' => (string)($data['created_by'] ?? ''), ':now' => date('Y-m-d H:i:s'), ':created_at' => date('Y-m-d H:i:s')]
            );
            $message = 'Categoría de labor creada con éxito.';
        } else {
            $this->model->executePrepared(
                "UPDATE categorias_labor SET descripcion = :descripcion, codigo = NULLIF(:codigo, ''), updated_at = :now, sync = '1' WHERE id = :id",
                [':id' => $id, ':descripcion' => $descripcion, ':codigo' => $codigo, ':now' => date('Y-m-d H:i:s')]
            );
            $message = 'Categoría de labor actualizada con éxito.';
        }
        service_return(['data' => ['id' => $id], 'message' => $message]);
    }

    public function toggleCategoriaLaborWeb($data)
    {
        $id = trim((string)($data['id'] ?? ''));
        if ($id === '') {
            service_return(['success' => false, 'message' => 'Debes seleccionar una categoría.', 'data' => []]);
        }
        $this->model->executePrepared(
            "UPDATE categorias_labor SET voided = IF(voided = '1', '0', '1'), updated_at = :now, sync = '1' WHERE id = :id",
            [':id' => $id, ':now' => date('Y-m-d H:i:s')]
        );
        service_return(['data' => ['id' => $id], 'message' => 'Estado de la categoría actualizado.']);
    }

    // =====================================================================
    // SINCRONIZACIÓN MÓVIL DE CATEGORÍAS DE LABOR
    // =====================================================================

    public function createCategoriaLabor($data)
    {
        $a = $this->model;
        $this->assertMobileCatalogNotReactivated('categorias_labor', trim((string)($data['id'] ?? '')), 'categoría de labor', $data);

        $where['id'] = $data['id'];
        $sql_data = crearSentenciaSelect(['tabla' => 'categorias_labor', 'where' => $where]);
        $data_select = $a->executeScript(['sql' => $sql_data]);

        if (empty($data_select)) {
            $data['sync'] = '1';
            $sql = crearSentenciaInsert(['tabla' => 'categorias_labor', 'conten' => $data]);
            $a->executeScript(['sql' => $sql]);
            $msg = 'La categoría de labor se creó con éxito!';
        } else {

            $data_query['descripcion'] = $data['descripcion'];
            $data_query['codigo'] = $data['codigo'] ?? null;
            $data_query['sync'] = '1';
            $data_query['voided'] = $data['voided'];
            $data_query['created_at'] = $data['created_at'];
            $data_query['updated_at'] = $data['updated_at'];
            $data_query['created_by'] = $data['created_by'];

            $data_where['id'] = $data['id'];

            $sql_mast = crearSentenciaUpdate(['tabla' => 'categorias_labor', 'sets' => $data_query, 'where' => $data_where]);
            $a->executeScript([
                'sql' => $sql_mast
            ]);
            $msg = 'La categoría de labor se editó con éxito!';
        }


        service_return(['data' => $data['id'], 'message' => $msg]);
    }

    public function getCategoriasLabor()
    {

        $a = $this->model;
        $where['1'] = '1';
        $sql_ = crearSentenciaSelect(['tabla' => 'categorias_labor', 'where' => $where]);
        $data_load = $a->executeScript(['sql' => $sql_]);

        service_return(['data' => $data_load, 'message' => 'El listado de categorías de labor se envia con éxito!']);
    }

    // =====================================================================
    // ADMINISTRACIÓN WEB DE RECOMENDACIONES
    // =====================================================================

    public function getRecomendacionesWeb($data)
    {
        $recomendaciones = $this->model->queryPrepared(
            "SELECT id, descripcion, voided, created_at, updated_at
             FROM recomendaciones ORDER BY voided DESC, updated_at DESC"
        );
        service_return(['data' => $recomendaciones, 'message' => 'Recomendaciones consultadas con éxito.']);
    }

    public function saveRecomendacionWeb($data)
    {
        $id = trim((string)($data['id'] ?? ''));
        $descripcion = trim((string)($data['descripcion'] ?? ''));
        if ($descripcion === '') {
            service_return(['success' => false, 'message' => 'El texto de la recomendación es obligatorio.', 'data' => []]);
        }
        if ($id === '') {
            $id = bin2hex(random_bytes(16));
            $this->model->executePrepared(
                "INSERT INTO recomendaciones (id, descripcion, sync, voided, created_at, updated_at, created_by)
                 VALUES (:id, :descripcion, '1', '1', :created_at, :now, :created_by)",
                [':id' => $id, ':descripcion' => $descripcion, ':created_by' => (string)($data['created_by'] ?? ''), ':now' => date('Y-m-d H:i:s'), ':created_at' => date('Y-m-d H:i:s')]
            );
            $message = 'Recomendación creada con éxito.';
        } else {
            $this->model->executePrepared(
                "UPDATE recomendaciones SET descripcion = :descripcion, updated_at = :now, sync = '1' WHERE id = :id",
                [':id' => $id, ':descripcion' => $descripcion, ':now' => date('Y-m-d H:i:s')]
            );
            $message = 'Recomendación actualizada con éxito.';
        }
        service_return(['data' => ['id' => $id], 'message' => $message]);
    }

    public function toggleRecomendacionWeb($data)
    {
        $id = trim((string)($data['id'] ?? ''));
        if ($id === '') {
            service_return(['success' => false, 'message' => 'Debes seleccionar una recomendación.', 'data' => []]);
        }
        $this->model->executePrepared(
            "UPDATE recomendaciones SET voided = IF(voided = '1', '0', '1'), updated_at = :now, sync = '1' WHERE id = :id",
            [':id' => $id, ':now' => date('Y-m-d H:i:s')]
        );
        service_return(['data' => ['id' => $id], 'message' => 'Estado de la recomendación actualizado.']);
    }

    // =====================================================================
    // REPORTES EN EXCEL (WEB) — biblioteca de archivos .xlsx/.xls/.xlsm que
    // un administrador sube ya armados (muchos con Power Query conectado a
    // la base de datos); no genera reportes dinámicamente. Mismo concepto
    // que el módulo "Reportes en Excel" de AgroSoft_hostinger.
    // =====================================================================

    public function getReportesExcelWeb($data)
    {
        $rows = $this->model->queryPrepared(
            "SELECT r.id, r.nombre, r.descripcion, r.archivo, r.extension, r.tamano_bytes,
                    r.voided, r.created_at, r.updated_at, u.name AS creado_por
             FROM reportes_excel r LEFT JOIN `user` u ON u.id = r.created_by
             ORDER BY r.voided DESC, r.nombre"
        );
        service_return(['data' => $rows, 'message' => 'Reportes consultados con éxito.']);
    }

    public function saveReporteExcelWeb($data)
    {
        $id = trim((string)($data['id'] ?? ''));
        $nombre = trim((string)($data['nombre'] ?? ''));
        $descripcion = trim((string)($data['descripcion'] ?? ''));
        $archivoBase64 = (string)($data['archivo_base64'] ?? '');
        $archivoNombre = trim((string)($data['archivo_nombre'] ?? ''));

        if ($nombre === '') {
            service_return(['success' => false, 'message' => 'El nombre del reporte es obligatorio.', 'data' => []]);
        }
        if ($id === '' && ($archivoBase64 === '' || $archivoNombre === '')) {
            service_return(['success' => false, 'message' => 'Debes adjuntar un archivo de Excel.', 'data' => []]);
        }

        $now = date('Y-m-d H:i:s');
        $actor = (string)($_SESSION['agronomo_user_id'] ?? '');
        $descripcionValor = $descripcion !== '' ? $descripcion : null;

        $archivoParams = [];
        if ($archivoBase64 !== '' && $archivoNombre !== '') {
            $extension = strtolower((string)pathinfo($archivoNombre, PATHINFO_EXTENSION));
            if (!in_array($extension, ['xlsx', 'xls', 'xlsm'], true)) {
                service_return(['success' => false, 'message' => 'El archivo debe ser .xlsx, .xls o .xlsm.', 'data' => []]);
            }
            $binario = base64_decode($archivoBase64, true);
            if ($binario === false || $binario === '') {
                service_return(['success' => false, 'message' => 'El archivo adjunto no es válido.', 'data' => []]);
            }
            if (strlen($binario) > 5 * 1024 * 1024) {
                service_return(['success' => false, 'message' => 'El archivo no puede superar 5 MB.', 'data' => []]);
            }
            $rutaDestino = 'uploads/reportes_excel/' . bin2hex(random_bytes(12)) . '.' . $extension;
            file_put_contents($rutaDestino, $binario);
            $archivoParams = [':archivo' => $rutaDestino, ':extension' => $extension, ':tamano' => strlen($binario)];
        }

        if ($id === '') {
            $id = bin2hex(random_bytes(16));
            $this->model->executePrepared(
                "INSERT INTO reportes_excel (id, nombre, descripcion, archivo, extension, tamano_bytes, sync, voided, created_at, updated_at, created_by)
                 VALUES (:id, :nombre, :descripcion, :archivo, :extension, :tamano, '1', '1', :created_at, :now, :actor)",
                array_merge([':id' => $id, ':nombre' => $nombre, ':descripcion' => $descripcionValor, ':now' => $now, ':created_at' => $now, ':actor' => $actor], $archivoParams)
            );
            $message = 'Reporte subido con éxito.';
        } else {
            $existing = $this->model->queryPrepared("SELECT archivo FROM reportes_excel WHERE id = :id LIMIT 1", [':id' => $id]);
            if (!$existing) {
                service_return(['success' => false, 'message' => 'El reporte que intentas editar ya no existe.', 'data' => []]);
            }
            if ($archivoParams) {
                $rutaAnterior = (string)$existing[0]['archivo'];
                if ($rutaAnterior !== '' && file_exists($rutaAnterior)) {
                    @unlink($rutaAnterior);
                }
                $this->model->executePrepared(
                    "UPDATE reportes_excel SET nombre=:nombre, descripcion=:descripcion, archivo=:archivo, extension=:extension, tamano_bytes=:tamano, sync='1', updated_at=:now WHERE id=:id",
                    array_merge([':id' => $id, ':nombre' => $nombre, ':descripcion' => $descripcionValor, ':now' => $now], $archivoParams)
                );
            } else {
                $this->model->executePrepared(
                    "UPDATE reportes_excel SET nombre=:nombre, descripcion=:descripcion, sync='1', updated_at=:now WHERE id=:id",
                    [':id' => $id, ':nombre' => $nombre, ':descripcion' => $descripcionValor, ':now' => $now]
                );
            }
            $message = 'Reporte actualizado con éxito.';
        }
        $this->auditWeb('REPORTE_EXCEL_GUARDADO', $id . ' · ' . $nombre);
        service_return(['data' => ['id' => $id], 'message' => $message]);
    }

    public function toggleReporteExcelWeb($data)
    {
        $id = trim((string)($data['id'] ?? ''));
        if ($id === '') {
            service_return(['success' => false, 'message' => 'Debes seleccionar un reporte.', 'data' => []]);
        }
        $this->model->executePrepared(
            "UPDATE reportes_excel SET voided = IF(voided = '1', '0', '1'), sync = '1', updated_at = :now WHERE id = :id",
            [':id' => $id, ':now' => date('Y-m-d H:i:s')]
        );
        $this->auditWeb('REPORTE_EXCEL_ESTADO', $id);
        service_return(['data' => ['id' => $id], 'message' => 'Estado del reporte actualizado.']);
    }

    // =====================================================================
    // CONSTRUCTOR DE QUERIES (build.query) — núcleo (guardar consulta y
    // generar el link), navegador de esquema y clientes de la API JSON.
    // Equivalente reducido al módulo build.query de AgroSoft_dev2: sin el
    // asistente de IA ni los widgets de dashboard móvil de ese original.
    // =====================================================================

    private function validateBuildQuerySql(string $sql): void
    {
        $sql = trim($sql);
        if ($sql === '') {
            service_return(['success' => false, 'message' => 'La consulta SQL es obligatoria.', 'data' => []]);
        }
        if (!preg_match('/^\s*(SELECT|WITH)\b/i', $sql)) {
            service_return(['success' => false, 'message' => 'Solo se permiten consultas SELECT o WITH.', 'data' => []]);
        }
        if (preg_match('/;\s*\S/', $sql)) {
            service_return(['success' => false, 'message' => 'No se permite más de una sentencia por consulta.', 'data' => []]);
        }
        if (preg_match('/\b(INSERT|UPDATE|DELETE|DROP|ALTER|TRUNCATE|CREATE|GRANT|REVOKE|REPLACE|EXEC|LOAD_FILE|OUTFILE|DUMPFILE)\b/i', $sql)) {
            service_return(['success' => false, 'message' => 'La consulta contiene una palabra clave no permitida.', 'data' => []]);
        }
        foreach (self::BUILD_QUERY_BLOCKED_TABLES as $tabla) {
            if (preg_match('/\b' . preg_quote($tabla, '/') . '\b/i', $sql)) {
                service_return(['success' => false, 'message' => "La tabla \"$tabla\" no está disponible para reportes.", 'data' => []]);
            }
        }
    }

    private function buildQueryParamNames(string $parametros): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $parametros))));
    }

    public function getReportQueriesWeb($data)
    {
        $rows = $this->model->queryPrepared(
            "SELECT rq.id, rq.descripcion, rq.consulta, rq.parametros, rq.api_habilitada, rq.api_descripcion,
                    rq.api_max_filas, rq.voided, rq.created_at, rq.updated_at, u.name AS creado_por,
                    (SELECT COUNT(*) FROM api_cliente_reportes acr WHERE acr.reporte_id = rq.id) AS clientes_autorizados
             FROM report_queries rq LEFT JOIN `user` u ON u.id = rq.created_by
             ORDER BY rq.voided DESC, rq.descripcion"
        );
        service_return(['data' => $rows, 'message' => 'Consultas guardadas con éxito.']);
    }

    public function saveReportQueryWeb($data)
    {
        $id = trim((string)($data['id'] ?? ''));
        $descripcion = trim((string)($data['descripcion'] ?? ''));
        $consulta = trim((string)($data['consulta'] ?? ''));
        $parametrosRaw = trim((string)($data['parametros'] ?? ''));
        $apiHabilitada = !empty($data['api_habilitada']) ? '1' : '0';
        $apiDescripcion = trim((string)($data['api_descripcion'] ?? ''));
        $apiMaxFilas = max(1, min(10000, (int)($data['api_max_filas'] ?? 1000)));

        if ($descripcion === '') {
            service_return(['success' => false, 'message' => 'La descripción es obligatoria.', 'data' => []]);
        }
        $this->validateBuildQuerySql($consulta);

        $parametros = $this->buildQueryParamNames($parametrosRaw);
        foreach ($parametros as $parametro) {
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $parametro)) {
                service_return(['success' => false, 'message' => "El parámetro \"$parametro\" no es un nombre válido.", 'data' => []]);
            }
        }

        $now = date('Y-m-d H:i:s');
        $actor = (string)($_SESSION['agronomo_user_id'] ?? '');
        $campos = [
            ':descripcion' => $descripcion, ':consulta' => $consulta,
            ':parametros' => $parametros ? implode(',', $parametros) : null,
            ':api_habilitada' => $apiHabilitada,
            ':api_descripcion' => $apiDescripcion !== '' ? $apiDescripcion : null,
            ':api_max_filas' => $apiMaxFilas, ':now' => $now,
        ];

        if ($id === '') {
            $id = bin2hex(random_bytes(20));
            $this->model->executePrepared(
                "INSERT INTO report_queries (id, descripcion, consulta, parametros, api_habilitada, api_descripcion, api_max_filas, sync, voided, created_at, updated_at, created_by)
                 VALUES (:id, :descripcion, :consulta, :parametros, :api_habilitada, :api_descripcion, :api_max_filas, '1', '1', :created_at, :now, :actor)",
                array_merge($campos, [':id' => $id, ':created_at' => $now, ':actor' => $actor])
            );
            $message = 'Consulta creada con éxito.';
        } else {
            $existing = $this->model->queryPrepared("SELECT id FROM report_queries WHERE id = :id LIMIT 1", [':id' => $id]);
            if (!$existing) {
                service_return(['success' => false, 'message' => 'La consulta que intentas editar ya no existe.', 'data' => []]);
            }
            $this->model->executePrepared(
                "UPDATE report_queries SET descripcion=:descripcion, consulta=:consulta, parametros=:parametros,
                 api_habilitada=:api_habilitada, api_descripcion=:api_descripcion, api_max_filas=:api_max_filas,
                 sync='1', updated_at=:now WHERE id=:id",
                array_merge($campos, [':id' => $id])
            );
            $message = 'Consulta actualizada con éxito.';
        }
        $this->auditWeb('BUILD_QUERY_GUARDADA', $id . ' · ' . $descripcion);
        service_return(['data' => ['id' => $id, 'parametros' => $parametros], 'message' => $message]);
    }

    public function toggleReportQueryWeb($data)
    {
        $id = trim((string)($data['id'] ?? ''));
        if ($id === '') {
            service_return(['success' => false, 'message' => 'Debes seleccionar una consulta.', 'data' => []]);
        }
        $this->model->executePrepared(
            "UPDATE report_queries SET voided = IF(voided = '1', '0', '1'), sync = '1', updated_at = :now WHERE id = :id",
            [':id' => $id, ':now' => date('Y-m-d H:i:s')]
        );
        $this->auditWeb('BUILD_QUERY_ESTADO', $id);
        service_return(['data' => ['id' => $id], 'message' => 'Estado de la consulta actualizado.']);
    }

    public function previewReportQueryWeb($data)
    {
        $consulta = rtrim(trim((string)($data['consulta'] ?? '')), '; ');
        $valores = is_array($data['valores'] ?? null) ? $data['valores'] : [];
        $this->validateBuildQuerySql($consulta);

        $params = [];
        foreach ($valores as $nombre => $valor) {
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', (string)$nombre)) continue;
            $params[':' . $nombre] = $valor;
        }
        try {
            $rows = $this->model->queryPrepared("SELECT * FROM ($consulta) AS build_query_preview LIMIT 200", $params);
        } catch (Throwable $e) {
            service_return(['success' => false, 'message' => 'No fue posible ejecutar la consulta: ' . $this->sanitizeBuildQueryError($e->getMessage()), 'data' => []]);
        }
        service_return(['data' => $rows, 'message' => 'Vista previa generada con éxito.']);
    }

    public function getSchemaTablesWeb($data)
    {
        $busqueda = trim((string)($data['busqueda'] ?? ''));
        $sql = "SELECT TABLE_NAME AS tabla, TABLE_COMMENT AS comentario, TABLE_ROWS AS filas_aprox
                FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'";
        $params = [];
        if ($busqueda !== '') {
            $sql .= ' AND TABLE_NAME LIKE :busqueda';
            $params[':busqueda'] = '%' . $busqueda . '%';
        }
        $sql .= ' ORDER BY TABLE_NAME';
        $rows = $this->model->queryPrepared($sql, $params);
        $bloqueadas = array_map('strtolower', self::BUILD_QUERY_BLOCKED_TABLES);
        $rows = array_values(array_filter($rows, function ($row) use ($bloqueadas) {
            return !in_array(strtolower((string)$row['tabla']), $bloqueadas, true);
        }));
        service_return(['data' => $rows, 'message' => 'Tablas consultadas con éxito.']);
    }

    public function getSchemaColumnsWeb($data)
    {
        $tabla = trim((string)($data['tabla'] ?? ''));
        if ($tabla === '' || in_array(strtolower($tabla), array_map('strtolower', self::BUILD_QUERY_BLOCKED_TABLES), true)) {
            service_return(['success' => false, 'message' => 'Esa tabla no está disponible.', 'data' => []]);
        }
        $rows = $this->model->queryPrepared(
            "SELECT COLUMN_NAME AS columna, DATA_TYPE AS tipo, IS_NULLABLE AS permite_nulo, COLUMN_COMMENT AS comentario
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :tabla
             ORDER BY ORDINAL_POSITION",
            [':tabla' => $tabla]
        );
        service_return(['data' => $rows, 'message' => 'Columnas consultadas con éxito.']);
    }

    public function getApiClientesWeb($data)
    {
        $rows = $this->model->queryPrepared(
            "SELECT c.id, c.nombre, c.client_key, c.notas, c.voided, c.created_at, c.updated_at,
                    (SELECT COUNT(*) FROM api_cliente_reportes acr WHERE acr.cliente_id = c.id) AS reportes_autorizados
             FROM api_clientes c ORDER BY c.voided DESC, c.nombre"
        );
        service_return(['data' => $rows, 'message' => 'Clientes de API consultados con éxito.']);
    }

    public function saveApiClienteWeb($data)
    {
        $id = trim((string)($data['id'] ?? ''));
        $nombre = trim((string)($data['nombre'] ?? ''));
        $notas = trim((string)($data['notas'] ?? ''));
        if ($nombre === '') {
            service_return(['success' => false, 'message' => 'El nombre del cliente es obligatorio.', 'data' => []]);
        }
        $now = date('Y-m-d H:i:s');
        $actor = (string)($_SESSION['agronomo_user_id'] ?? '');
        $clientKey = null;
        $secretoPlano = null;

        if ($id === '') {
            $id = bin2hex(random_bytes(16));
            $clientKey = bin2hex(random_bytes(10));
            $secretoPlano = bin2hex(random_bytes(24));
            $this->model->executePrepared(
                "INSERT INTO api_clientes (id, nombre, client_key, client_secret_hash, notas, voided, created_at, updated_at, created_by)
                 VALUES (:id, :nombre, :client_key, :hash, :notas, '1', :created_at, :now, :actor)",
                [':id' => $id, ':nombre' => $nombre, ':client_key' => $clientKey, ':hash' => password_hash($secretoPlano, PASSWORD_DEFAULT), ':notas' => $notas !== '' ? $notas : null, ':now' => $now, ':created_at' => $now, ':actor' => $actor]
            );
            $message = 'Cliente creado con éxito.';
        } else {
            $existing = $this->model->queryPrepared("SELECT id FROM api_clientes WHERE id = :id LIMIT 1", [':id' => $id]);
            if (!$existing) {
                service_return(['success' => false, 'message' => 'El cliente que intentas editar ya no existe.', 'data' => []]);
            }
            $this->model->executePrepared(
                "UPDATE api_clientes SET nombre=:nombre, notas=:notas, updated_at=:now WHERE id=:id",
                [':id' => $id, ':nombre' => $nombre, ':notas' => $notas !== '' ? $notas : null, ':now' => $now]
            );
            $message = 'Cliente actualizado con éxito.';
        }
        $this->auditWeb('API_CLIENTE_GUARDADO', $id . ' · ' . $nombre);
        $respuesta = ['id' => $id];
        if ($secretoPlano !== null) {
            $respuesta['client_key'] = $clientKey;
            $respuesta['client_secret'] = $secretoPlano;
        }
        service_return(['data' => $respuesta, 'message' => $message]);
    }

    public function toggleApiClienteWeb($data)
    {
        $id = trim((string)($data['id'] ?? ''));
        if ($id === '') {
            service_return(['success' => false, 'message' => 'Debes seleccionar un cliente.', 'data' => []]);
        }
        $this->model->executePrepared(
            "UPDATE api_clientes SET voided = IF(voided = '1', '0', '1'), updated_at = :now WHERE id = :id",
            [':id' => $id, ':now' => date('Y-m-d H:i:s')]
        );
        $this->auditWeb('API_CLIENTE_ESTADO', $id);
        service_return(['data' => ['id' => $id], 'message' => 'Estado del cliente actualizado.']);
    }

    public function getApiClienteReportesWeb($data)
    {
        $clienteId = trim((string)($data['cliente_id'] ?? ''));
        if ($clienteId === '') {
            service_return(['success' => false, 'message' => 'Debes seleccionar un cliente.', 'data' => []]);
        }
        $rows = $this->model->queryPrepared(
            "SELECT reporte_id FROM api_cliente_reportes WHERE cliente_id = :cliente",
            [':cliente' => $clienteId]
        );
        service_return(['data' => array_map(function ($row) { return $row['reporte_id']; }, $rows), 'message' => 'Permisos consultados con éxito.']);
    }

    public function saveApiClienteReportesWeb($data)
    {
        $clienteId = trim((string)($data['cliente_id'] ?? ''));
        $reporteIds = is_array($data['reporte_ids'] ?? null) ? array_values(array_unique(array_map('strval', $data['reporte_ids']))) : [];
        if ($clienteId === '') {
            service_return(['success' => false, 'message' => 'Debes seleccionar un cliente.', 'data' => []]);
        }
        $cliente = $this->model->queryPrepared("SELECT id FROM api_clientes WHERE id = :id LIMIT 1", [':id' => $clienteId]);
        if (!$cliente) {
            service_return(['success' => false, 'message' => 'El cliente no existe.', 'data' => []]);
        }
        $this->model->executePrepared("DELETE FROM api_cliente_reportes WHERE cliente_id = :cliente", [':cliente' => $clienteId]);
        foreach ($reporteIds as $reporteId) {
            $this->model->executePrepared(
                "INSERT INTO api_cliente_reportes (id, cliente_id, reporte_id, created_at) VALUES (:id, :cliente, :reporte, :now)",
                [':id' => bin2hex(random_bytes(16)), ':cliente' => $clienteId, ':reporte' => $reporteId, ':now' => date('Y-m-d H:i:s')]
            );
        }
        $this->auditWeb('API_CLIENTE_PERMISOS', $clienteId);
        service_return(['data' => ['cliente_id' => $clienteId, 'reporte_ids' => $reporteIds], 'message' => 'Permisos de reportes actualizados.']);
    }

    // ---- Endpoints públicos (Basic Auth propia, no pasan por el router
    // JSON-RPC): los llaman reports/excel.php y reports/api.php directamente.

    private function requireBuildQueryBasicAuth(): void
    {
        $user = $_SERVER['PHP_AUTH_USER'] ?? '';
        $pass = $_SERVER['PHP_AUTH_PW'] ?? '';
        $valido = false;
        if ($user !== '' && $pass !== '') {
            $rows = $this->model->queryPrepared(
                "SELECT password_hash FROM `user` WHERE `user` = :user AND void = '1' LIMIT 1",
                [':user' => strtolower($user)]
            );
            if ($rows && password_verify($pass, (string)$rows[0]['password_hash'])) {
                $valido = true;
            }
        }
        if (!$valido) {
            header('WWW-Authenticate: Basic realm="Reportes AgroSoft Agronomo"');
            http_response_code(401);
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'Autenticacion requerida.';
            exit;
        }
    }

    private function requireApiClientAuth(): string
    {
        $key = $_SERVER['PHP_AUTH_USER'] ?? '';
        $secret = $_SERVER['PHP_AUTH_PW'] ?? '';
        if ($key !== '' && $secret !== '') {
            $rows = $this->model->queryPrepared(
                "SELECT id, client_secret_hash FROM api_clientes WHERE client_key = :key AND voided = '1' LIMIT 1",
                [':key' => $key]
            );
            if ($rows && password_verify($secret, (string)$rows[0]['client_secret_hash'])) {
                return (string)$rows[0]['id'];
            }
        }
        header('WWW-Authenticate: Basic realm="API Reportes AgroSoft Agronomo"');
        http_response_code(401);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => false, 'message' => 'Credenciales de API invalidas.']);
        exit;
    }

    private function logApiRequest(?string $clienteId, string $reporteId, int $status, $filas): void
    {
        $this->model->executePrepared(
            "INSERT INTO api_request_log (cliente_id, reporte_id, ip_address, status_code, filas, created_at) VALUES (:cliente, :reporte, :ip, :status, :filas, :now)",
            [':cliente' => $clienteId, ':reporte' => $reporteId, ':ip' => $_SERVER['REMOTE_ADDR'] ?? null, ':status' => $status, ':filas' => $filas, ':now' => date('Y-m-d H:i:s')]
        );
    }

    public function renderExcelReport(): void
    {
        $this->requireBuildQueryBasicAuth();
        $id = trim((string)($_GET['id'] ?? ''));
        $reportes = $this->model->queryPrepared(
            "SELECT id, descripcion, consulta, parametros FROM report_queries WHERE id = :id AND voided = '1' LIMIT 1",
            [':id' => $id]
        );
        if (!$reportes) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'Reporte no encontrado o inactivo.';
            exit;
        }
        $reporte = $reportes[0];
        $params = [];
        foreach ($this->buildQueryParamNames((string)$reporte['parametros']) as $nombre) {
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $nombre)) continue;
            $params[':' . $nombre] = $_GET[$nombre] ?? '';
        }
        $consulta = rtrim(trim((string)$reporte['consulta']), '; ');
        try {
            $rows = $this->model->queryPrepared("SELECT * FROM ($consulta) AS build_query_export LIMIT 5000", $params);
        } catch (Throwable $e) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'No fue posible ejecutar el reporte.';
            exit;
        }
        $nombreArchivo = preg_replace('/[^a-zA-Z0-9_-]+/', '_', (string)$reporte['descripcion']);
        header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
        header('Content-Disposition: inline; filename="' . $nombreArchivo . '.xls"');
        echo "\xEF\xBB\xBF";
        echo '<html><head><meta charset="UTF-8"></head><body><table border="1">';
        if ($rows) {
            echo '<tr>';
            foreach (array_keys($rows[0]) as $columna) {
                echo '<th>' . htmlspecialchars((string)$columna, ENT_QUOTES, 'UTF-8') . '</th>';
            }
            echo '</tr>';
            foreach ($rows as $fila) {
                echo '<tr>';
                foreach ($fila as $valor) {
                    echo '<td>' . htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8') . '</td>';
                }
                echo '</tr>';
            }
        }
        echo '</table></body></html>';
        exit;
    }

    public function renderApiReport(): void
    {
        header('Content-Type: application/json; charset=UTF-8');
        $clienteId = $this->requireApiClientAuth();
        $token = trim((string)($_GET['token'] ?? ''));
        $reportes = $this->model->queryPrepared(
            "SELECT id, descripcion, consulta, parametros, api_habilitada, api_max_filas FROM report_queries WHERE id = :id AND voided = '1' LIMIT 1",
            [':id' => $token]
        );
        if (!$reportes || $reportes[0]['api_habilitada'] !== '1') {
            $this->logApiRequest($clienteId, $token, 404, null);
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Reporte no encontrado o no habilitado para API.']);
            exit;
        }
        $reporte = $reportes[0];
        $acl = $this->model->queryPrepared(
            "SELECT id FROM api_cliente_reportes WHERE cliente_id = :cliente AND reporte_id = :reporte LIMIT 1",
            [':cliente' => $clienteId, ':reporte' => $token]
        );
        if (!$acl) {
            $this->logApiRequest($clienteId, $token, 403, null);
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Este cliente no tiene acceso a este reporte.']);
            exit;
        }
        $paramNames = $this->buildQueryParamNames((string)$reporte['parametros']);
        $params = [];
        foreach ($paramNames as $nombre) {
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $nombre)) continue;
            $params[':' . $nombre] = $_GET[$nombre] ?? '';
        }
        $maxFilas = max(1, (int)$reporte['api_max_filas']);
        $consulta = rtrim(trim((string)$reporte['consulta']), '; ');
        try {
            $rows = $this->model->queryPrepared("SELECT * FROM ($consulta) AS build_query_api LIMIT $maxFilas", $params);
        } catch (Throwable $e) {
            $this->logApiRequest($clienteId, $token, 500, null);
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'No fue posible ejecutar el reporte.']);
            exit;
        }
        $this->logApiRequest($clienteId, $token, 200, count($rows));
        echo json_encode([
            'success' => true,
            'meta' => ['token' => $token, 'descripcion' => $reporte['descripcion'], 'parametros' => $paramNames, 'filas' => count($rows), 'max_filas' => $maxFilas],
            'data' => $rows,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    public function createLote($data)
    {

        $a = $this->model;
        $this->assertMobileCatalogNotReactivated('lotes', trim((string)($data['id'] ?? '')), 'lote', $data);
        if ((string)($data['voided'] ?? '1') !== '0') {
            $this->assertActiveMobileReference('fincas', trim((string)($data['finca_id'] ?? '')), 'finca');
            $this->assertActiveMobileReference('cultivos', trim((string)($data['cultivo_id'] ?? '')), 'cultivo');
        }


        $where['id'] = $data['id'];
        $sql_data = crearSentenciaSelect(['tabla' => 'lotes', 'where' => $where]);
        $data_select = $a->executeScript(['sql' => $sql_data]);
        if (empty($data_select)) {
            $data['sync'] = '1';
            $sql = crearSentenciaInsert(['tabla' => 'lotes', 'conten' => $data]);
            $a->executeScript(['sql' => $sql]);
            $msg = 'El lote se creó con éxito!';
        } else {

            $data_query['finca_id'] = $data['finca_id'];
            $data_query['nombre'] = $data['nombre'];
            $data_query['cultivo_id'] = $data['cultivo_id'];
            $data_query['sync'] = '1';
            $data_query['voided'] = $data['voided'];
            $data_query['created_at'] = $data['created_at'];
            $data_query['updated_at'] = $data['updated_at'];
            $data_query['created_by'] = $data['created_by'];

            $data_where['id'] = $data['id'];

            $sql_mast = crearSentenciaUpdate(['tabla' => 'lotes', 'sets' => $data_query, 'where' => $data_where]);

            $a->executeScript([
                'sql' => $sql_mast
            ]);
            $msg = 'El lote se editó con éxito!';
        }


        service_return(['data' => $data['id'], 'message' => $msg]);
    }

    public function getLotes($data)
    {
        $a = $this->model;
        $usuarioId = trim((string)($data['usuario_id'] ?? ''));

        if ($this->mobileUserIsAdmin($usuarioId)) {
            $data_load = $a->queryPrepared("SELECT * FROM lotes");
        } else {
            $data_load = $a->queryPrepared(
                "SELECT DISTINCT l.* FROM lotes l
                 JOIN usuario_fincas uf ON uf.finca_id = l.finca_id
                 WHERE uf.usuario_id = :usuario",
                [':usuario' => $usuarioId]
            );
        }

        service_return(['data' => $data_load, 'message' => 'El listado de lotes se envia con éxito!']);
    }

    public function createFinca($data)
    {

        $a = $this->model;
        $this->assertMobileCatalogNotReactivated('fincas', trim((string)($data['id'] ?? '')), 'finca', $data);


        $where['id'] = $data['id'];
        $sql_data = crearSentenciaSelect(['tabla' => 'fincas', 'where' => $where]);

        $data_select = $a->executeScript(['sql' => $sql_data]);

        $tecnicoId = trim((string)($data['tecnico_id'] ?? ''));
        if ($tecnicoId !== '') {
            $usuarioActivo = $a->queryPrepared(
                "SELECT id FROM `user` WHERE id=:id AND void='1' LIMIT 1",
                [':id'=>$tecnicoId]
            );
            if (!$usuarioActivo) {
                service_return(['success'=>false,'message'=>'El técnico seleccionado no existe o está inactivo.','data'=>[]]);
            }
        }
        $tecnicoAnterior = trim((string)($data_select[0]['tecnico_id'] ?? ''));
        if (empty($data_select)) {
            $data['sync'] = '1';
            $sql = crearSentenciaInsert(['tabla' => 'fincas', 'conten' => $data]);
            $a->executeScript(['sql' => $sql]);
            $msg = 'La finca se creó con éxito!';
        } else {

            $data_query['descripcion'] = $data['descripcion'];
            $data_query['tecnico_id']  = $data['tecnico_id'] ?? '';
            $data_query['sync'] = '1';
            $data_query['voided'] = $data['voided'];
            $data_query['created_at'] = $data['created_at'];
            $data_query['updated_at'] = $data['updated_at'];
            $data_query['created_by'] = $data['created_by'];

            $data_where['id'] = $data['id'];

            $sql_mast = crearSentenciaUpdate(['tabla' => 'fincas', 'sets' => $data_query, 'where' => $data_where]);
            $a->executeScript([
                'sql' => $sql_mast
            ]);
            $msg = 'La finca se editó con éxito!';
        }

        // La web consulta responsables desde usuario_fincas, mientras la app
        // móvil conserva además tecnico_id por compatibilidad. Mantenemos
        // ambas representaciones sincronizadas sin borrar otros responsables
        // que hayan sido asignados desde la interfaz web.
        if ($tecnicoAnterior !== '' && $tecnicoAnterior !== $tecnicoId) {
            $a->executePrepared(
                "DELETE FROM usuario_fincas WHERE finca_id=:finca AND usuario_id=:usuario",
                [':finca'=>(string)$data['id'], ':usuario'=>$tecnicoAnterior]
            );
        }
        if ($tecnicoId !== '') {
            $a->executePrepared(
                "INSERT INTO usuario_fincas(usuario_id,finca_id,created_by)
                 VALUES(:usuario,:finca,:actor)
                 ON DUPLICATE KEY UPDATE created_by=VALUES(created_by)",
                [
                    ':usuario'=>$tecnicoId,
                    ':finca'=>(string)$data['id'],
                    ':actor'=>(string)($data['created_by'] ?? ''),
                ]
            );
        }

        service_return(['data' => $data['id'], 'message' => $msg]);
    }

    public function getFincas($data)
    {
        $a = $this->model;
        $usuario_id = trim((string)($data['usuario_id'] ?? ''));

        if ($this->mobileUserIsAdmin($usuario_id)) {
            $data_load = $a->queryPrepared("SELECT f.* FROM fincas f ORDER BY f.descripcion");
        } else {
            $data_load = $a->queryPrepared(
                "SELECT DISTINCT f.* FROM fincas f
                 JOIN usuario_fincas uf ON uf.finca_id=f.id
                 WHERE uf.usuario_id=:usuario AND f.voided='1'
                 ORDER BY f.descripcion",
                [':usuario'=>$usuario_id]
            );
        }

        service_return(['data' => $data_load, 'message' => 'El listado de fincas se envia con éxito!']);
    }

    public function createCultivo($data)
    {

        $a = $this->model;
        $this->assertMobileCatalogNotReactivated('cultivos', trim((string)($data['id'] ?? '')), 'cultivo', $data);


        $where['id'] = $data['id'];
        $sql_data = crearSentenciaSelect(['tabla' => 'cultivos', 'where' => $where]);

        $data_select = $a->executeScript(['sql' => $sql_data]);

        if (empty($data_select)) {
            $data['sync'] = '1';
            $sql = crearSentenciaInsert(['tabla' => 'cultivos', 'conten' => $data]);
            $a->executeScript(['sql' => $sql]);
            $msg = 'El cultivo se creó con éxito!';
        } else {

            $data_query['descripcion'] = $data['descripcion'];
            $data_query['codigo'] = $data['codigo'] ?? '';
            $data_query['sync'] = '1';
            $data_query['voided'] = $data['voided'];
            $data_query['created_at'] = $data['created_at'];
            $data_query['updated_at'] = $data['updated_at'];
            $data_query['created_by'] = $data['created_by'];

            $data_where['id'] = $data['id'];

            $sql_mast = crearSentenciaUpdate(['tabla' => 'cultivos', 'sets' => $data_query, 'where' => $data_where]);
            $a->executeScript([
                'sql' => $sql_mast
            ]);
            $msg = 'El cultivo se editó con éxito!';
        }


        service_return(['data' => $data['id'], 'message' => $msg]);
    }

    public function getCultivos($data)
    {

        $a = $this->model;
        $where['1'] = '1';
        $sql_ = crearSentenciaSelect(['tabla' => 'cultivos', 'where' => $where]);
        $data_load = $a->executeScript(['sql' => $sql_]);

        service_return(['data' => $data_load, 'message' => 'El listado de cultivos se envia con éxito!']);
    }

    public function createInsumo($data)
    {

        $a = $this->model;
        $this->assertMobileCatalogNotReactivated('insumos', trim((string)($data['id'] ?? '')), 'insumo', $data);

        $where['id'] = $data['id'];
        $sql_cliente = crearSentenciaSelect(['tabla' => 'insumos', 'where' => $where]);
        $data_cliente = $a->executeScript(['sql' => $sql_cliente]);

        if (empty($data_cliente)) {

            $data['sync'] = '1';
            $sql = crearSentenciaInsert(['tabla' => 'insumos', 'conten' => $data]);
            $a->executeScript(['sql' => $sql]);
            $msg = 'El insumo se creó con éxito!';
        } else {

            $data_query['nombre'] = $data['nombre'];
            $data_query['unidad_medida'] = $data['unidad_medida'];
            $data_query['categoria'] = $data['categoria'];
            $data_query['sync'] = '1';
            $data_query['voided'] = $data['voided'];
            $data_query['created_at'] = $data['created_at'];
            $data_query['updated_at'] = $data['updated_at'];
            $data_query['created_by'] = $data['created_by'];

            $data_where['id'] = $data['id'];

            $sql_mast = crearSentenciaUpdate(['tabla' => 'insumos', 'sets' => $data_query, 'where' => $data_where]);
            $a->executeScript([
                'sql' => $sql_mast
            ]);
            $msg = 'El insumo se editó con éxito!';
        }


        service_return(['data' => $data['id'], 'message' => $msg]);
    }

    public function getInsumos($data)
    {

        $a = $this->model;
        $where['1'] = '1';
        $sql_cliente = crearSentenciaSelect(['tabla' => 'insumos', 'where' => $where]);
        $data_cliente = $a->executeScript(['sql' => $sql_cliente]);

        service_return(['data' => $data_cliente, 'message' => 'El listado de insumos se envia con éxito!']);
    }

    public function getNotificacionesWeb($data)
    {
        $items = $this->model->queryPrepared(
            "SELECT n.*,u.name AS creado_por,
                    COUNT(d.usuario_id) AS destinatarios,
                    SUM(d.confirmada_at IS NOT NULL) AS confirmadas,
                    GROUP_CONCAT(CASE WHEN d.confirmada_at IS NOT NULL THEN ud.name END ORDER BY ud.name SEPARATOR ', ') AS confirmadas_nombres,
                    GROUP_CONCAT(CASE WHEN d.confirmada_at IS NULL THEN ud.name END ORDER BY ud.name SEPARATOR ', ') AS pendientes_nombres,
                    SUM(d.push_estado='ENVIADA') AS push_enviadas,
                    SUM(d.push_estado='ERROR') AS push_errores,
                    SUM(d.push_estado='SIN_TOKEN') AS push_sin_token,
                    SUM(d.push_estado='PENDIENTE') AS push_pendientes,
                    GROUP_CONCAT(DISTINCT CASE WHEN d.push_error IS NOT NULL THEN d.push_error END SEPARATOR ' | ') AS push_error_detalle
             FROM notificaciones_mobile n
             LEFT JOIN `user` u ON u.id=n.created_by
             LEFT JOIN notificacion_destinatarios d ON d.notificacion_id=n.id
             LEFT JOIN `user` ud ON ud.id=d.usuario_id
             GROUP BY n.id ORDER BY n.created_at DESC LIMIT 100",
            []
        );
        $usuarios = $this->model->queryPrepared(
            "SELECT id,name,`user`,rol_id FROM `user` WHERE void='1' ORDER BY name", []
        );
        $roles = $this->model->queryPrepared(
            "SELECT id,nombre,codigo FROM roles WHERE activo=1 ORDER BY nombre", []
        );
        $version = $this->model->queryPrepared(
            "SELECT version,motivo,obligatoria,updated_at FROM mobile_data_version WHERE id=1", []
        );
        service_return(['data'=>[
            'notificaciones'=>$items,
            'usuarios'=>$usuarios,
            'roles'=>$roles,
            'version'=>$version[0] ?? ['version'=>1,'motivo'=>'','obligatoria'=>0],
        ],'message'=>'Notificaciones consultadas.']);
    }

    private function firebaseBase64Url($value)
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function firebaseCredentials()
    {
        $configuredPath = trim((string)getenv('FIREBASE_SERVICE_ACCOUNT'));
        $path = $configuredPath !== ''
            ? $configuredPath
            : dirname(__DIR__, 2).'/.agronomo-secrets/firebase-service-account.json';
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Firebase Admin no está configurado en el servidor.');
        }
        $credentials = json_decode((string)file_get_contents($path), true);
        if (!is_array($credentials)
            || empty($credentials['client_email'])
            || empty($credentials['private_key'])
            || empty($credentials['project_id'])) {
            throw new RuntimeException('La credencial privada de Firebase no es válida.');
        }
        return $credentials;
    }

    private function firebaseAccessToken($credentials)
    {
        $now = time();
        $header = $this->firebaseBase64Url(json_encode(['alg'=>'RS256','typ'=>'JWT']));
        $claims = $this->firebaseBase64Url(json_encode([
            'iss'=>$credentials['client_email'],
            'scope'=>'https://www.googleapis.com/auth/firebase.messaging',
            'aud'=>'https://oauth2.googleapis.com/token',
            'iat'=>$now,
            'exp'=>$now + 3600,
        ]));
        $unsigned = $header.'.'.$claims;
        $signature = '';
        if (!openssl_sign($unsigned, $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('No fue posible firmar la solicitud de Firebase.');
        }
        $assertion = $unsigned.'.'.$this->firebaseBase64Url($signature);
        $curl = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($curl, [
            CURLOPT_POST=>true,
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_CONNECTTIMEOUT=>10,
            CURLOPT_TIMEOUT=>25,
            CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_POSTFIELDS=>http_build_query([
                'grant_type'=>'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'=>$assertion,
            ]),
        ]);
        $body = curl_exec($curl);
        $curlError = curl_error($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        $response = json_decode((string)$body, true);
        if ($status < 200 || $status >= 300 || empty($response['access_token'])) {
            $detail = $response['error_description'] ?? $response['error'] ?? $curlError;
            throw new RuntimeException('Firebase no autorizó al servidor: '.substr((string)$detail, 0, 180));
        }
        return $response['access_token'];
    }

    private function sendFirebaseMessage($projectId, $accessToken, $token, $notification)
    {
        $url = 'https://fcm.googleapis.com/v1/projects/'.rawurlencode($projectId).'/messages:send';
        $payload = [
            'message'=>[
                'token'=>$token,
                'notification'=>[
                    'title'=>$notification['titulo'],
                    'body'=>$notification['mensaje'],
                ],
                'data'=>[
                    'notification_id'=>(string)$notification['id'],
                    'requires_update'=>(string)((int)$notification['requiere_actualizacion']),
                    'mandatory_update'=>(string)((int)$notification['actualizacion_obligatoria']),
                    'data_version'=>(string)($notification['data_version'] ?? ''),
                ],
                'android'=>[
                    'priority'=>'high',
                    'notification'=>[
                        'channel_id'=>'agronomo_notifications',
                        'sound'=>'default',
                        'notification_count'=>1,
                    ],
                ],
                'apns'=>[
                    'headers'=>['apns-priority'=>'10'],
                    'payload'=>['aps'=>['sound'=>'default','badge'=>1]],
                ],
            ],
        ];
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_POST=>true,
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_CONNECTTIMEOUT=>10,
            CURLOPT_TIMEOUT=>25,
            CURLOPT_HTTPHEADER=>[
                'Authorization: Bearer '.$accessToken,
                'Content-Type: application/json; charset=utf-8',
            ],
            CURLOPT_POSTFIELDS=>json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);
        $body = curl_exec($curl);
        $curlError = curl_error($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        $response = json_decode((string)$body, true);
        if ($status >= 200 && $status < 300 && !empty($response['name'])) {
            return ['success'=>true,'status'=>$status,'error'=>'','invalid_token'=>false];
        }
        $error = $response['error']['message'] ?? $curlError ?: 'Respuesta HTTP '.$status;
        $fcmStatus = $response['error']['details'][0]['errorCode'] ?? '';
        return [
            'success'=>false,
            'status'=>$status,
            'error'=>substr((string)$error, 0, 240),
            'invalid_token'=>in_array($fcmStatus, ['UNREGISTERED','INVALID_ARGUMENT'], true),
        ];
    }

    private function dispatchNotificationPush($notificationId)
    {
        $rows = $this->model->queryPrepared(
            "SELECT n.id,n.titulo,n.mensaje,n.requiere_actualizacion,n.actualizacion_obligatoria,n.data_version,
                    d.usuario_id,a.fcm_token
             FROM notificaciones_mobile n
             JOIN notificacion_destinatarios d ON d.notificacion_id=n.id
             LEFT JOIN auth_tokens a ON a.user_id=d.usuario_id
                  AND a.revoked_at IS NULL AND a.expires_at>NOW()
                  AND a.fcm_token IS NOT NULL AND a.fcm_token<>''
             WHERE n.id=:id
             ORDER BY d.usuario_id,a.id DESC",
            [':id'=>$notificationId]
        );
        if (!$rows) {
            return ['sent'=>0,'errors'=>0,'without_token'=>0,'devices'=>0];
        }
        $byUser = [];
        foreach ($rows as $row) {
            $userId = (string)$row['usuario_id'];
            if (!isset($byUser[$userId])) $byUser[$userId] = ['notification'=>$row,'tokens'=>[]];
            $token = trim((string)($row['fcm_token'] ?? ''));
            if ($token !== '') $byUser[$userId]['tokens'][$token] = true;
        }
        $summary = ['sent'=>0,'errors'=>0,'without_token'=>0,'devices'=>0];
        try {
            $credentials = $this->firebaseCredentials();
            $accessToken = $this->firebaseAccessToken($credentials);
            foreach ($byUser as $userId=>$entry) {
                $tokens = array_keys($entry['tokens']);
                if (!$tokens) {
                    $summary['without_token']++;
                    $this->model->executePrepared(
                        "UPDATE notificacion_destinatarios SET push_estado='SIN_TOKEN',push_error='El usuario no tiene un dispositivo registrado.' WHERE notificacion_id=:id AND usuario_id=:usuario",
                        [':id'=>$notificationId,':usuario'=>$userId]
                    );
                    continue;
                }
                $delivered = false;
                $errors = [];
                foreach ($tokens as $token) {
                    $summary['devices']++;
                    $result = $this->sendFirebaseMessage(
                        $credentials['project_id'], $accessToken, $token, $entry['notification']
                    );
                    if ($result['success']) {
                        $delivered = true;
                    } else {
                        $errors[] = $result['error'];
                        if ($result['invalid_token']) {
                            $this->model->executePrepared(
                                "UPDATE auth_tokens SET fcm_token=NULL WHERE fcm_token=:token",
                                [':token'=>$token]
                            );
                        }
                    }
                }
                if ($delivered) {
                    $summary['sent']++;
                    $this->model->executePrepared(
                        "UPDATE notificacion_destinatarios SET push_estado='ENVIADA',push_error=NULL WHERE notificacion_id=:id AND usuario_id=:usuario",
                        [':id'=>$notificationId,':usuario'=>$userId]
                    );
                } else {
                    $summary['errors']++;
                    $error = implode(' | ', array_unique($errors));
                    $this->model->executePrepared(
                        "UPDATE notificacion_destinatarios SET push_estado='ERROR',push_error=:error WHERE notificacion_id=:id AND usuario_id=:usuario",
                        [':error'=>substr($error,0,255),':id'=>$notificationId,':usuario'=>$userId]
                    );
                }
            }
        } catch (Throwable $e) {
            $summary['errors'] = count($byUser);
            $this->model->executePrepared(
                "UPDATE notificacion_destinatarios SET push_estado='ERROR',push_error=:error WHERE notificacion_id=:id",
                [':error'=>substr($e->getMessage(),0,255),':id'=>$notificationId]
            );
        }
        $state = $summary['sent'] > 0
            ? (($summary['errors'] + $summary['without_token']) > 0 ? 'PARCIAL' : 'ENVIADA')
            : 'ERROR';
        $resultText = $summary['sent'].' usuarios notificados; '.$summary['errors'].' errores; '
            .$summary['without_token'].' sin token; '.$summary['devices'].' dispositivos procesados.';
        $this->model->executePrepared(
            "UPDATE notificaciones_mobile SET estado=:estado,resultado_push=:resultado WHERE id=:id",
            [':estado'=>$state,':resultado'=>substr($resultText,0,500),':id'=>$notificationId]
        );
        $summary['state'] = $state;
        return $summary;
    }

    public function sendNotificacionWeb($data)
    {
        $titulo = trim((string)($data['titulo'] ?? ''));
        $mensaje = trim((string)($data['mensaje'] ?? ''));
        $audiencia = strtoupper(trim((string)($data['audiencia'] ?? 'TODOS')));
        $valores = array_values(array_unique(array_filter(array_map(
            'strval', is_array($data['audiencia_valores'] ?? null) ? $data['audiencia_valores'] : []
        ))));
        $requiere = !empty($data['requiere_actualizacion']) ? 1 : 0;
        $obligatoria = $requiere && !empty($data['actualizacion_obligatoria']) ? 1 : 0;
        if ($titulo === '' || $mensaje === '') {
            service_return(['success'=>false,'message'=>'El título y el mensaje son obligatorios.','data'=>[]]);
        }
        if (!in_array($audiencia, ['TODOS','ROL','USUARIOS'], true)) $audiencia = 'TODOS';
        if ($audiencia !== 'TODOS' && !$valores) {
            service_return(['success'=>false,'message'=>'Selecciona al menos un destinatario.','data'=>[]]);
        }
        $actor = (string)($_SESSION['agronomo_user_id'] ?? '');
        $id = bin2hex(random_bytes(16));
        $ahora = date('Y-m-d H:i:s');
        $this->model->beginTransaction();
        try {
            $dataVersion = null;
            if ($requiere) {
                $this->model->executePrepared(
                    "UPDATE mobile_data_version
                     SET version=version+1,motivo=:motivo,obligatoria=:obligatoria,updated_at=:ahora,updated_by=:actor
                     WHERE id=1",
                    [':motivo'=>$mensaje,':obligatoria'=>$obligatoria,':ahora'=>$ahora,':actor'=>$actor]
                );
                $row = $this->model->queryPrepared("SELECT version FROM mobile_data_version WHERE id=1", []);
                $dataVersion = (int)($row[0]['version'] ?? 1);
            }
            $this->model->executePrepared(
                "INSERT INTO notificaciones_mobile
                 (id,titulo,mensaje,audiencia,audiencia_valores,requiere_actualizacion,actualizacion_obligatoria,data_version,estado,created_at,created_by)
                 VALUES(:id,:titulo,:mensaje,:audiencia,:valores,:requiere,:obligatoria,:version,'PENDIENTE',:ahora,:actor)",
                [':id'=>$id,':titulo'=>substr($titulo,0,100),':mensaje'=>substr($mensaje,0,500),
                 ':audiencia'=>$audiencia,':valores'=>$valores ? json_encode($valores) : null,
                 ':requiere'=>$requiere,':obligatoria'=>$obligatoria,':version'=>$dataVersion,
                 ':ahora'=>$ahora,':actor'=>$actor]
            );
            $params = [':actor'=>$actor];
            $where = "u.void='1' AND u.id<>:actor";
            if ($audiencia !== 'TODOS') {
                $placeholders = [];
                foreach ($valores as $index=>$value) {
                    $key = ':target_'.$index;
                    $placeholders[] = $key;
                    $params[$key] = $value;
                }
                $column = $audiencia === 'ROL' ? 'u.rol_id' : 'u.id';
                $where .= " AND $column IN (".implode(',', $placeholders).")";
            }
            $destinatarios = $this->model->queryPrepared("SELECT u.id FROM `user` u WHERE $where", $params);
            foreach ($destinatarios as $destinatario) {
                $this->model->executePrepared(
                    "INSERT INTO notificacion_destinatarios(notificacion_id,usuario_id,push_estado)
                     VALUES(:notificacion,:usuario,'PENDIENTE')",
                    [':notificacion'=>$id,':usuario'=>$destinatario['id']]
                );
            }
            $this->model->commit();
            $push = $this->dispatchNotificationPush($id);
            $message = $push['sent'] > 0
                ? 'Notificación enviada a '.$push['sent'].' usuarios ('.$push['devices'].' dispositivos).'
                : 'La notificación fue creada, pero Firebase no pudo entregarla.';
            if ($push['errors'] || $push['without_token']) {
                $message .= ' '.$push['errors'].' con error y '.$push['without_token'].' sin dispositivo registrado.';
            }
            service_return(['data'=>[
                'id'=>$id,'destinatarios'=>count($destinatarios),'data_version'=>$dataVersion,'push'=>$push,
            ],'message'=>$message]);
        } catch (Throwable $e) {
            $this->model->rollBack();
            throw $e;
        }
    }

    public function retryNotificacionPushWeb($data)
    {
        $id = trim((string)($data['id'] ?? ''));
        if ($id === '') {
            service_return(['success'=>false,'message'=>'La notificación es requerida.','data'=>[]]);
        }
        $exists = $this->model->queryPrepared(
            "SELECT id FROM notificaciones_mobile WHERE id=:id LIMIT 1", [':id'=>$id]
        );
        if (!$exists) {
            service_return(['success'=>false,'message'=>'La notificación no existe.','data'=>[]]);
        }
        $push = $this->dispatchNotificationPush($id);
        service_return(['data'=>['id'=>$id,'push'=>$push],
            'message'=>'Reintento terminado: '.$push['sent'].' usuarios notificados, '.$push['errors'].' errores y '.$push['without_token'].' sin token.']);
    }

    public function getMobileUpdateStatus($data)
    {
        $usuario = trim((string)($data['authenticated_user_id'] ?? ''));
        $version = $this->model->queryPrepared(
            "SELECT version,motivo,obligatoria,updated_at FROM mobile_data_version WHERE id=1", []
        );
        $notificaciones = $this->model->queryPrepared(
            "SELECT n.id,n.titulo,n.mensaje,n.requiere_actualizacion,n.actualizacion_obligatoria,
                    n.data_version,n.created_at,d.leida_at,d.confirmada_at
             FROM notificacion_destinatarios d
             JOIN notificaciones_mobile n ON n.id=d.notificacion_id
             WHERE d.usuario_id=:usuario AND d.confirmada_at IS NULL
             ORDER BY n.created_at DESC LIMIT 20",
            [':usuario'=>$usuario]
        );
        service_return(['data'=>[
            'version'=>$version[0] ?? ['version'=>1,'motivo'=>'','obligatoria'=>0],
            'notificaciones'=>$notificaciones,
        ],'message'=>'Estado móvil consultado.']);
    }

    public function getMobileNotifications($data)
    {
        $usuario = trim((string)($data['authenticated_user_id'] ?? ''));
        $notificaciones = $this->model->queryPrepared(
            "SELECT n.id,n.titulo,n.mensaje,n.requiere_actualizacion,
                    n.actualizacion_obligatoria,n.data_version,n.created_at,
                    d.leida_at,d.confirmada_at
             FROM notificacion_destinatarios d
             JOIN notificaciones_mobile n ON n.id=d.notificacion_id
             WHERE d.usuario_id=:usuario
             ORDER BY n.created_at DESC LIMIT 100",
            [':usuario'=>$usuario]
        );
        $pendientes = 0;
        foreach ($notificaciones as $notificacion) {
            if (empty($notificacion['leida_at'])) $pendientes++;
        }
        service_return(['data'=>[
            'notificaciones'=>$notificaciones,
            'no_leidas'=>$pendientes,
        ],'message'=>'Bandeja de notificaciones consultada.']);
    }

    public function markMobileNotificationsRead($data)
    {
        $usuario = trim((string)($data['authenticated_user_id'] ?? ''));
        $id = trim((string)($data['notificacion_id'] ?? ''));
        $params = [':ahora'=>date('Y-m-d H:i:s'),':usuario'=>$usuario];
        $where = '';
        if ($id !== '') {
            $where = ' AND notificacion_id=:id';
            $params[':id'] = $id;
        }
        $this->model->executePrepared(
            "UPDATE notificacion_destinatarios
             SET leida_at=COALESCE(leida_at,:ahora)
             WHERE usuario_id=:usuario".$where,
            $params
        );
        service_return(['data'=>['id'=>$id],'message'=>'Notificaciones marcadas como leídas.']);
    }

    public function confirmMobileNotification($data)
    {
        $usuario = trim((string)($data['authenticated_user_id'] ?? ''));
        $id = trim((string)($data['notificacion_id'] ?? ''));
        // Un aviso informativo queda cumplido al aceptarlo. Si exige
        // actualización, aquí solo se marca leído; completeMobileDataUpdate
        // lo confirma después de terminar y validar toda la descarga.
        $this->model->executePrepared(
            "UPDATE notificacion_destinatarios d
             JOIN notificaciones_mobile n ON n.id=d.notificacion_id
             SET d.leida_at=COALESCE(d.leida_at,:ahora_leida),
                 d.confirmada_at=CASE WHEN n.requiere_actualizacion=0 THEN :ahora_confirmada ELSE d.confirmada_at END
             WHERE d.notificacion_id=:id AND d.usuario_id=:usuario",
            [
                ':ahora_leida'=>date('Y-m-d H:i:s'),
                ':ahora_confirmada'=>date('Y-m-d H:i:s'),
                ':id'=>$id,
                ':usuario'=>$usuario,
            ]
        );
        service_return(['data'=>['id'=>$id],'message'=>'Notificación confirmada.']);
    }

    public function completeMobileDataUpdate($data)
    {
        $usuario = trim((string)($data['authenticated_user_id'] ?? ''));
        $version = $this->model->queryPrepared(
            "SELECT version FROM mobile_data_version WHERE id=1", []
        );
        $numero = (int)($version[0]['version'] ?? 1);
        $ahora = date('Y-m-d H:i:s');
        $this->model->executePrepared(
            "UPDATE notificacion_destinatarios d
             JOIN notificaciones_mobile n ON n.id=d.notificacion_id
             SET d.leida_at=COALESCE(d.leida_at,:ahora_leida),d.confirmada_at=:ahora_confirmada
             WHERE d.usuario_id=:usuario AND d.confirmada_at IS NULL
               AND n.requiere_actualizacion=1
               AND (n.data_version IS NULL OR n.data_version<=:version)",
            [
                ':ahora_leida'=>$ahora,
                ':ahora_confirmada'=>$ahora,
                ':usuario'=>$usuario,
                ':version'=>$numero,
            ]
        );
        service_return(['data'=>['version'=>$numero,'completed_at'=>$ahora],
            'message'=>'Actualización confirmada para el usuario.']);
    }

    public function registerMobilePushToken($data)
    {
        $usuario = trim((string)($data['authenticated_user_id'] ?? ''));
        $apiToken = trim((string)($data['api_token'] ?? ''));
        $fcmToken = trim((string)($data['fcm_token'] ?? ''));
        if ($fcmToken === '') {
            service_return(['success'=>false,'message'=>'El token de notificaciones es requerido.','data'=>[]]);
        }
        $this->model->executePrepared(
            "UPDATE auth_tokens SET fcm_token=:fcm
             WHERE user_id=:usuario AND token_hash=:hash AND revoked_at IS NULL",
            [':fcm'=>substr($fcmToken,0,512),':usuario'=>$usuario,':hash'=>hash('sha256',$apiToken)]
        );
        service_return(['data'=>[],'message'=>'Dispositivo registrado para notificaciones.']);
    }
}
