<?php
// Ejecutar: php tests/mobile_mutation_permissions_test.php (requiere pdo_sqlite).
class ControllerBase {}
require __DIR__ . '/../controller/agronomo.controller.php';
class MutationPermissionModel {
    public function __construct(public PDO $db) {}
    public function queryPrepared($sql, $params = []) {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec("CREATE TABLE roles(id INTEGER, codigo TEXT);
    INSERT INTO roles VALUES(1,'tecnico'),(2,'admin');
    CREATE TABLE user(id TEXT, rol_id INTEGER, void TEXT);
    INSERT INTO user VALUES('tecnico',1,'1'),('admin',2,'1');
    CREATE TABLE permisos(id INTEGER, modulo TEXT, accion TEXT);
    INSERT INTO permisos VALUES(1,'formulas','ver'),(2,'formulas','crear'),(3,'formulas','editar');
    CREATE TABLE rol_permisos(rol_id INTEGER, permiso_id INTEGER);
    INSERT INTO rol_permisos VALUES(1,1);
    CREATE TABLE formulas(id TEXT);
    INSERT INTO formulas VALUES('existente');
    CREATE TABLE formulas_detalle(id TEXT);
    INSERT INTO formulas_detalle VALUES('detalle');");
$reflection = new ReflectionClass(AgronomoController::class);
$controller = $reflection->newInstanceWithoutConstructor();
$reflection->getProperty('model')->setValue($controller, new MutationPermissionModel($db));
$checks = 0;
function expectPermission($expected, $user, $method, $id) {
    global $controller, $checks;
    $actual = $controller->canMobileMutation($user, $method, ['id'=>$id]);
    if ($actual !== $expected) throw new RuntimeException("Permiso incorrecto: $user $method $id");
    $checks++;
}
expectPermission(false, 'tecnico', 'createFormula', 'nuevo');
expectPermission(false, 'tecnico', 'createFormula', 'existente');
expectPermission(true, 'admin', 'createFormula', 'nuevo');
$db->exec('INSERT INTO rol_permisos VALUES(1,2)');
expectPermission(true, 'tecnico', 'createFormula', 'nuevo');
expectPermission(false, 'tecnico', 'createFormula', 'existente');
expectPermission(true, 'tecnico', 'createDetalleFormula', 'nuevo');
$db->exec('INSERT INTO rol_permisos VALUES(1,3)');
expectPermission(true, 'tecnico', 'createFormula', 'existente');
expectPermission(true, 'tecnico', 'createDetalleFormula', 'detalle');
// Revocar un permiso debe tener efecto sin renovar la sesión.
$db->exec('DELETE FROM rol_permisos WHERE permiso_id=3');
expectPermission(false, 'tecnico', 'createFormula', 'existente');
$db->exec("UPDATE user SET void='0'");
expectPermission(false, 'tecnico', 'createFormula', 'nuevo');
expectPermission(false, 'admin', 'createFormula', 'nuevo');
expectPermission(false, 'desconocido', 'createFormula', 'nuevo');
echo "OK: $checks comprobaciones de permisos móviles\n";
