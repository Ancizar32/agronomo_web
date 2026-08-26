<?php

use InduSoft\myException;

use function InduSoft\errorUserId;
use function InduSoft\mfex;
use function InduSoft\rlog;

date_default_timezone_set("America/Bogota");
define('SERVER_NAME', $_SERVER['SERVER_NAME'] ?? 'localhost');

// El proyecto puede publicarse como raíz de un dominio o dentro de
// /myApp/AgroSoft_Agronomo. Resolver desde este archivo evita depender del
// DocumentRoot configurado por Apache, Docker, ngrok o Hostinger.
define('RUTA_ROOT', realpath(__DIR__ . '/..'));
require_once(RUTA_ROOT . '/config/structure.php');
require_once(RUTA_ROOT . '/config/dbconfig.php');
define('RUTA_MVC', dirname(RUTA_ROOT, 1) . '/application/');
define('RUTA_APP', RUTA_ROOT . '/app/');
define('RUTA_LIB', dirname(RUTA_ROOT, 1) . '/resources/');
require_once RUTA_MVC . '/Classes/PHPExcel/Reader/Excel2007.php';
require_once RUTA_MVC . '/Classes/PHPExcel.php';
require_once RUTA_MVC . '/core2/ControladorBase.php';
require_once RUTA_MVC . '/core2/VistaBase.php';
require_once RUTA_LIB . '/lib/vendor/autoload.php';
$controlador = 'controller';

define('CONTROLADOR_DEFECTO', $controlador);
define('ACCION_DEFECTO', 'start');
define('PREFIX', 'PV');
define('DATE_REGEX', '/^(19|20)\d\d[\-\/.](0[1-9]|1[012])[\-\/.](0[1-9]|[12][0-9]|3[01])$/');

global $user_id;
global $user_text;
global $host;
global $token_session;

$user_id = !isset($_COOKIE['user_id']) ? errorUserId("1") : $_COOKIE['user_id'];
$user_text = !isset($_COOKIE['user_text']) ? errorUserId("2") : $_COOKIE['user_text'];
$token_session = !isset($_COOKIE['token_session']) ? '' : $_COOKIE['token_session'];
$host = $_SERVER['HTTP_HOST'];


class Global_class
{
    private static $instancia;
    private $dbh;
    private $dbh2;

    private $beginTransaction;
    private $commit;
    private $rollback;


    private $database;

    public function __construct()
    {
        $this->database = new Database();
        $this->dbh = $this->database->dbConnection();
        // dbh2 (latin1) ya no se abre aquí de una vez: la app moderna
        // (AgronomoController, vía model::queryPrepared/executePrepared)
        // solo usa $dbh (utf8) — valid_user()/myQueryUtf()/
        // prepare_statement_utf() son las únicas que tocan dbh2, y son
        // legacy sin uso desde este controlador. Abrir esa segunda conexión
        // en TODAS las peticiones costaba un round-trip de red completo de
        // más por cada llamada a la API sin ningún beneficio — ahora se
        // conecta perezosamente, solo si algo realmente la necesita.
    }

    private function getDbh2()
    {
        if ($this->dbh2 === null) {
            $this->dbh2 = $this->database->dbConnection2();
        }
        return $this->dbh2;
    }

    public function beginTransaction()
    {
        $this->dbh->beginTransaction();
    }

    public function commit()
    {
        $this->dbh->commit();
    }

    public function rollBack()
    {
        $this->dbh->rollBack();
    }

    public static function singleton()
    {
        if (!isset(self::$instancia)) {
            $miclase = __CLASS__;
            self::$instancia = new $miclase;
        }
        return self::$instancia;
    }

    public function valid_user(array $parametro)
    {
        try {
            $query = $this->getDbh2()->prepare("select * from user where user= :user and code= :psw and void=1");
            $query->execute([
                'user' => $parametro['user'],
                'psw' => $parametro['psw'],
            ]);
            return $query->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            // Antes: var_dump($e->getMessage()) imprimía texto crudo en la
            // respuesta HTTP, rompiendo el JSON que espera cada cliente
            // (web y móvil). Se relanza para que el router la atrape y
            // responda con un JSON de error legible.
            throw $e;
        }
    }

    function prepare_statement($query, $parameters = [])
    {
        try {
            $this->statement = $this->dbh->prepare($query);
            $this->statement->execute($parameters);
            return $this->statement->rowcount();
        } catch (PDOException $e) {
            throw $e;
        }
    }

    function myQuery($query, $parameters = [])
    {
        try {
            $this->prepare_statement($query, $parameters);
            return $this->statement->fetchall(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw $e;
        }
    }

    function prepare_statement_utf($query, $parameters = [])
    {
        try {
            $dbh2 = $this->getDbh2();
            $dbh2->beginTransaction();
            $this->statement = $dbh2->prepare($query);
            $this->statement->execute($parameters);
            $dbh2->commit();
            return $this->statement->rowcount();
        } catch (PDOException $e) {
            $this->statement->rollBack();
            $this->getDbh2()->rollBack();
            throw $e;
        }
    }

    function myQueryUtf($query, $parameters = [])
    {
        try {
            $this->prepare_statement_utf($query, $parameters);
            return $this->statement->fetchall(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw $e;
        }
    }

    function columnCount()
    {
        return $this->statement->columnCount();
    }

    function getColumnMeta($col)
    {
        return $this->statement->getColumnMeta($col);
    }

    public function createReport(array $parametro)
    {
        try {

            $query = $this->myQueryUtf($parametro['query'], $parametro['parametro']);
            $columns_total = $this->columnCount();


            for ($i = 0; $i < $columns_total; $i++) {
                $heading = $this->getColumnMeta($i);
                $booleanColumns[] = $heading['name'];
            }
            $table = '<table><thead>';
            foreach ($booleanColumns as $col) {
                $table .= "<th>$col</th>";
            }
            $table .= "</thead>";

            $table .= "<tbody>";
            foreach ($query as $row) {
                $table .= '<tr>';
                foreach ($row as $column) {
                    $table .= '<td>';
                    $table .= $column;
                    $table .= '</td>';
                }
                $table .= '</tr>';
            }

            $table .= "</tbody></table>";

            return $table;
        } catch (PDOException $e) {
            var_dump($e->getMessage());
        }
    }

    public function get_query_stored(array $parametro)
    {
        try {

            $query = $this->getDbh2()->prepare("SELECT a.query, a.parametes as param FROM report_query as a where a.report_id = :report_id");
            $query->execute([
                'report_id' => $parametro['report_id']
            ]);
            return $query->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            var_dump($e->getMessage());
        }
    }

    function getSecurityObject()
    {
        global $user_id;
        $query = $this->myQuery("select 
                                    a.profile, 
                                    a.item,
                                    b.`user`
                                    from security_profile_item as a
                                    LEFT OUTER JOIN security_profile_user as b on a.`profile`=b.`profile`
                                    WHERE b.user = :user", [':user' => $user_id]);
        return $query;
    }

    function validSecurityObject($item_sec_ev)
    {
        global $user_id;
        $query = $this->myQuery("SELECT count(b.item) as cuenta FROM security_profile_user as a
        INNER JOIN security_profile_item as b ON a.`profile` = b.`profile`
        WHERE a.`user` = :user_id
        AND b.item = :item_sec_ev", [
            'user_id' => $user_id,
            'item_sec_ev' => $item_sec_ev
        ]);
        $resul = false;
        if (!empty($query)) {
            $count_obj = $query[0]['cuenta'];
            if ($count_obj > 0) {
                $resul = true;
            }
        }
        return $resul;
    }
}
