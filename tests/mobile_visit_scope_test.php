<?php
// Ejecutar: php tests/mobile_visit_scope_test.php (requiere pdo_sqlite).
namespace InduSoft {
    function service_return($result) { throw new \Result($result); }
}
namespace {
    class ControllerBase {}
    class Result extends \Exception {
        public function __construct(public array $result) { parent::__construct(); }
    }
    class TestModel {
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
    $db->exec("CREATE TABLE roles(id TEXT, codigo TEXT);
        INSERT INTO roles VALUES('a','admin'),('t','tecnico');
        CREATE TABLE user(id TEXT, rol_id TEXT, void TEXT);
        INSERT INTO user VALUES('u','t','1');
        CREATE TABLE fincas(id TEXT, voided TEXT);
        INSERT INTO fincas VALUES('actual','1'),('anterior','1');
        CREATE TABLE usuario_fincas(usuario_id TEXT, finca_id TEXT);
        INSERT INTO usuario_fincas VALUES('u','actual');
        CREATE TABLE lotes(id TEXT, finca_id TEXT);
        INSERT INTO lotes VALUES('la','actual'),('lb','anterior');
        CREATE TABLE visitas_tecnicas(id TEXT, finca_id TEXT, created_by TEXT, fecha TEXT, voided TEXT);
        INSERT INTO visitas_tecnicas VALUES
          ('actual','actual','otro','2026-10-01','1'),
          ('anterior','anterior','u','2026-10-01','1'),
          ('anulada','actual','u','2026-10-01','0'),
          ('antigua','actual','u','2020-01-01','1');
        CREATE TABLE visita_detalles_lote(id TEXT, visita_id TEXT, lote_id TEXT);
        INSERT INTO visita_detalles_lote VALUES
          ('actual','actual','la'),('anterior','anterior','lb'),
          ('anulada','anulada','la'),('antigua','antigua','la');
        CREATE TABLE visita_firmas(id TEXT, visita_id TEXT);
        INSERT INTO visita_firmas SELECT id,id FROM visitas_tecnicas;");
    $methods = ['getVisitas','getVisitaDetalleLote','getVisitaFirmas'];
    foreach ([
        'visita_detalles_hallazgos'=>'descargarHallazgos',
        'visita_detalles_actividades'=>'descargarActividades',
        'visita_detalles_formulas'=>'descargarVisitaDetallesFormulas',
        'visita_detalles_recomendaciones'=>'descargarVisitaDetallesRecomendaciones',
    ] as $table=>$method) {
        $db->exec("CREATE TABLE $table(id TEXT, visita_id TEXT, visita_detalle_id TEXT, voided TEXT);
            INSERT INTO $table SELECT id,id,id,'1' FROM visitas_tecnicas;");
        $methods[] = $method;
    }
    $reflection = new ReflectionClass(AgronomoController::class);
    $controller = $reflection->newInstanceWithoutConstructor();
    $reflection->getProperty('model')->setValue($controller, new TestModel($db));
    $checks = 0;
    foreach (['t'=>['actual'], 'a'=>['actual','anterior'], 't_again'=>['actual']] as $role=>$expected) {
        $db->exec("UPDATE user SET rol_id='" . ($role === 'a' ? 'a' : 't') . "'");
        foreach ($methods as $method) {
            try {
                // Un roll antiguo enviado por la app no debe ampliar el acceso.
                $controller->$method(['usuario_id'=>'u','roll'=>'S','fecha_desde'=>'2026-04-01']);
                throw new RuntimeException('No se recibió respuesta');
            } catch (Result $response) {
                $ids = array_column($response->result['data'], 'id');
                sort($ids);
                if ($ids !== $expected) throw new RuntimeException("$role/$method: " . json_encode($ids));
                $checks++;
            }
        }
    }
    $db->exec('DELETE FROM usuario_fincas');
    foreach ($methods as $method) {
        try { $controller->$method(['usuario_id'=>'u','fecha_desde'=>'2026-04-01']); }
        catch (Result $response) {
            if ($response->result['data'] !== []) throw new RuntimeException("Sin asignaciones: $method");
            $checks++;
        }
    }
    echo "OK: $checks comprobaciones de alcance móvil\n";
}
