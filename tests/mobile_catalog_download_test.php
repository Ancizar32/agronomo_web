<?php
// Ejecutar: php tests/mobile_catalog_download_test.php (requiere pdo_sqlite).
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
        public function queryPrepared($sql, $params = []): array {
            // Emula los metadatos de MySQL para reproducir también el fallo previo.
            if (str_contains($sql, 'information_schema.COLUMNS')) {
                return [
                    ['col'=>'id', 'tipo'=>'varchar', 'nulo'=>'NO'],
                    ['col'=>'voided', 'tipo'=>'char', 'nulo'=>'NO'],
                ];
            }
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
    require __DIR__ . '/../controller/agronomo.controller.php';
    $db = new PDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $reflection = new ReflectionClass(AgronomoController::class);
    $controller = $reflection->newInstanceWithoutConstructor();
    $reflection->getProperty('model')->setValue($controller, new TestModel($db));
    $checks = 0;
    foreach ([
        'recomendaciones'=>'getRecomendaciones',
        'labores'=>'getLabores',
        'categorias_labor'=>'getCategoriasLabor',
        'cultivos'=>'getCultivos',
        'insumos'=>'getInsumos',
    ] as $table=>$method) {
        $db->exec("CREATE TABLE $table(id TEXT, voided TEXT)");
        foreach ([[], [['id'=>'activo','voided'=>'1'], ['id'=>'inactivo','voided'=>'0']]] as $expected) {
            foreach ($expected as $row) {
                $stmt = $db->prepare("INSERT INTO $table VALUES (?, ?)");
                $stmt->execute([$row['id'], $row['voided']]);
            }
            try {
                $controller->$method([]);
                throw new RuntimeException("Sin respuesta: $method");
            } catch (Result $response) {
                $rows = $response->result['data'];
                usort($rows, fn($a, $b) => strcmp($a['id'], $b['id']));
                if ($rows !== $expected) throw new RuntimeException("Datos incorrectos: $method");
                $checks++;
            }
        }
    }
    echo "OK: $checks comprobaciones de descarga de catálogos\n";
}
