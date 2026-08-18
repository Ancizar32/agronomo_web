<?php
class Database
{
    private $db_name = "u902320992_agronomo";
    private $username = "root";
    private $password = "root";


    // private $db_name = "u902320992_collect_db";
    // private $username = "u902320992_collector";
    // private $password = "AgroSoft_db13579";

    public $conn;

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
