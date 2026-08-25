<?php
class Database
{
    private $db_name;
    private $username;
    private $password;

    public $conn;

    // Mismo interruptor que ya usa dbConnection()/dbConnection2() para el
    // host (getenv('APP_ENV') === 'docker'): en el contenedor local usa las
    // credenciales de Docker; en cualquier otro lado (producción) usa las
    // reales. Así ya no hay que comentar/descomentar credenciales a mano
    // antes de cada despliegue — y de paso se evita el riesgo de subir por
    // error las credenciales locales a producción.
    public function __construct()
    {
        if (getenv('APP_ENV') === 'docker') {
            $this->db_name = "u902320992_agronomo";
            $this->username = "root";
            $this->password = "root";
        } else {
            $this->db_name = "u902320992_agronomos";
            $this->username = "u902320992_agronomos_db";
            $this->password = "AgroSoft_db13579";
        }
    }

    //Se crea el método que permite realizar la conexión a la b.d 
    public function dbConnection()
    {

        $this->conn = null;
        try {
            $host = getenv('APP_ENV') === 'docker' ? 'db' : 'localhost';

            $this->conn = new PDO("mysql:host=" . $host . ";dbname=" . $this->db_name, $this->username, $this->password, array(PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8"));

            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->conn->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        } catch (PDOException $exception) {
            // echo $exception;
            echo "Error al conectar a la base de datos";
        }
        return $this->conn;
    }

    public function dbConnection2()
    {

        $this->conn = null;
        try {
            $host = getenv('APP_ENV') === 'docker' ? 'db' : 'localhost';
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES 'latin1'"
            ];

            $this->conn = new PDO("mysql:host=" . $host . ";dbname=" . $this->db_name . ';charset=latin1', $this->username, $this->password, $options);

            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->conn->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        } catch (PDOException $exception) {
            echo "Error al conectar a la base de datos";
        }
        return $this->conn;
    }

    function begintransaction()
    {
        $this->conn->begintransaction();
    }

    function commit()
    {
        $this->conn->commit();
    }

    function rollback()
    {
        $this->conn->rollback();
    }
}
