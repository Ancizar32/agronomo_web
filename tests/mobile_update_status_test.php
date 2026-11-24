<?php
// Ejecutar: php tests/mobile_update_status_test.php (requiere pdo_sqlite).
namespace InduSoft {
    function service_return($result) { throw new \UpdateStatusResult($result); }
}
namespace {
    class ControllerBase {}
    class UpdateStatusResult extends \Exception {
        public function __construct(public array $result) { parent::__construct(); }
    }
    class UpdateStatusModel {
        public function __construct(public PDO $db) {}
        public function queryPrepared($sql, $params = []) {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
    require __DIR__ . '/../controller/agronomo.controller.php';
    $db = new PDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("CREATE TABLE mobile_data_version(id INTEGER, version INTEGER, motivo TEXT, obligatoria INTEGER, updated_at TEXT);
        INSERT INTO mobile_data_version VALUES(1,5,'Actualizar',1,'2026-11-23');
        CREATE TABLE notificaciones_mobile(id TEXT, titulo TEXT, mensaje TEXT, requiere_actualizacion INTEGER,
            actualizacion_obligatoria INTEGER, data_version INTEGER, created_at TEXT);
        INSERT INTO notificaciones_mobile VALUES('update','Actualizar','Datos',1,1,5,'2026-11-23');
        CREATE TABLE notificacion_destinatarios(notificacion_id TEXT, usuario_id TEXT, leida_at TEXT, confirmada_at TEXT);
        INSERT INTO notificacion_destinatarios VALUES('update','cliente',NULL,NULL);");
    $reflection = new ReflectionClass(AgronomoController::class);
    $controller = $reflection->newInstanceWithoutConstructor();
    $reflection->getProperty('model')->setValue($controller, new UpdateStatusModel($db));
    function response($controller, $method, $user = 'cliente') {
        try { $controller->$method(['authenticated_user_id'=>$user]); }
        catch (UpdateStatusResult $result) { return $result->result['data']; }
        throw new \RuntimeException('Falta respuesta');
    }
    function check($condition, $message) {
        if (!$condition) throw new \RuntimeException($message);
    }
    $status = response($controller, 'getMobileUpdateStatus');
    check(count($status['notificaciones']) === 1, 'Debe ofrecer la actualización');
    check((int)$status['notificaciones'][0]['actualizacion_obligatoria'] === 0, 'Debe permitir Continuar');
    check((int)$status['notificaciones'][0]['requiere_actualizacion'] === 1, 'Debe conservar la solicitud');
    check(response($controller, 'getMobileUpdateStatus', 'otro')['notificaciones'] === [], 'Debe aislar destinatarios');
    // Simula la marca de lectura que escribe confirmar al pulsar Continuar.
    $db->exec("UPDATE notificacion_destinatarios SET leida_at='2026-11-23'");
    check(response($controller, 'getMobileUpdateStatus')['notificaciones'] === [], 'El sondeo no debe reabrir el aviso leído');
    $inbox = response($controller, 'getMobileNotifications')['notificaciones'];
    check(count($inbox) === 1 && $inbox[0]['confirmada_at'] === null, 'Debe seguir pendiente en la bandeja');
    check((int)$inbox[0]['actualizacion_obligatoria'] === 1, 'No debe alterar la notificación guardada');
    echo "OK: actualización omitible, sin repetición y pendiente conservado.\n";
}
