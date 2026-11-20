<?php
namespace InduSoft {
    function rlog($entry) {
        if (!empty($GLOBALS['fail_log'])) throw new \RuntimeException('log unavailable');
        $GLOBALS['auth_logs'][] = $entry;
    }
}
namespace {
    class ControllerBase {}
    require __DIR__ . '/../controller/agronomo.controller.php';
    require __DIR__ . '/../api/router.php';
    class AuthTestModel {
        public $session;
        public $accepted;
        public $updates = 0;
        public function queryPrepared($sql, $params) {
            return strpos($sql, 'LEFT JOIN') !== false
                ? ($this->session ? [$this->session] : [])
                : ($this->accepted ? [['id'=>9,'user_id'=>'1005']] : []);
        }
        public function executePrepared($sql, $params) { $this->updates++; }
    }
    function check($value) { if (!$value) throw new \RuntimeException('Check failed'); }
    $ref = new \ReflectionClass(AgronomoController::class);
    $controller = $ref->newInstanceWithoutConstructor();
    $model = new AuthTestModel();
    $ref->getProperty('model')->setValue($controller, $model);
    $token = str_repeat('b', 64);
    foreach (['token_desconocido', 'token_revocado', 'token_vencido', 'usuario_inactivo_o_inexistente'] as $reason) {
        $model->session = $reason === 'token_desconocido' ? null : [
            'id'=>8, 'user_id'=>'1005',
            'revoked_at'=>$reason === 'token_revocado' ? '2026-01-01 00:00:00' : null,
            'expires_at'=>$reason === 'token_vencido' ? '2000-01-01 00:00:00' : '2099-01-01 00:00:00',
            'void'=>$reason === 'usuario_inactivo_o_inexistente' ? '0' : '1',
        ];
        check($controller->authenticateMobileToken($token) === null);
        check(end($GLOBALS['auth_logs'])['motivo'] === $reason);
    }
    check($controller->authenticateMobileToken('') === null);
    check(end($GLOBALS['auth_logs'])['motivo'] === 'token_ausente');
    $model->accepted = true;
    check($controller->authenticateMobileToken($token) === '1005');
    check($model->updates === 1);
    $router = new AgronomoApiRouter();
    $method = new \ReflectionMethod($router, 'bearerToken');
    $method->setAccessible(true);
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
    check($method->invoke($router, ['_api_token'=>'different']) === $token);
    check(end($GLOBALS['auth_logs'])['coincide_con_cuerpo'] === false);
    unset($_SERVER['HTTP_AUTHORIZATION']);
    check($method->invoke($router, ['_api_token'=>$token]) === $token);
    check(end($GLOBALS['auth_logs'])['origen'] === 'cuerpo');
    check(strpos(json_encode($GLOBALS['auth_logs']), $token) === false);
    $GLOBALS['fail_log'] = true;
    check($controller->authenticateMobileToken($token) === '1005');
    putenv('AGRONOMO_AUTH_TRACE=0');
    $count = count($GLOBALS['auth_logs']);
    MobileAuthTrace::write('test', $token);
    check(count($GLOBALS['auth_logs']) === $count);
    echo "OK: autenticación, motivos, origen, privacidad y fallo de log\n";
}
