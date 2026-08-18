<?php

declare(strict_types=1);

namespace InduSoft {

    class myException extends \Exception
    {
        private $details = [];

        public function __construct($message, $args, $code = 0, Exception $previous = null)
        {

            $this->details = [];
            parent::__construct(vsprintf($message, $args), $code, $previous);
        }

        public function addDetails($item)
        {
            $this->details[] = $item;
        }

        public function countDetails()
        {
            return count($this->details);
        }

        public function getDetails()
        {
            return $this->details;
        }

        public function addAllDetails(array $details)
        {
            foreach ($details as $detail) {
                $this->details[] = $detail;
            }
        }
    }

    ini_set('display_errors', 'stderr');

    set_error_handler(function ($errno, $errstr, $errfile, $errline) {
        throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
    }, E_ALL);

    set_exception_handler(function (\Throwable $exception) {
        global $_output_filepath, $_is_service;
        $log = [
            "id" => uniqid('', true),
            "Timestamp" => (string) date("Y-m-d H:i:s"),
            "Message" => $exception->getMessage(),
            "Previous" => $exception->getPrevious(),
            "Code" => (string) $exception->getCode(),
            "File" => $exception->getFile(),
            "Line" => (string) $exception->getLine(),
            "TraceAsString" => $exception->getTraceAsString(),
            "Class" => get_class($exception),
        ];
        $log['details'] = [];
        if ($_is_service == '1') {
            if ($exception instanceof myException) {
                foreach ($exception->getDetails() as $value) {
                    $log['details'][] = $value;
                }
            }
            $ret = ['success' => false, 'title' => 'Error!', 'icon' => 'error', 'message' => $exception->getMessage(), 'detail' => $log];
            // echo $ret;
            echo json_encode($ret, JSON_PRETTY_PRINT);
            file_put_contents($_output_filepath, json_encode($ret, JSON_PRETTY_PRINT));
            exit(0);
        } else {
            // echo $ret;
            file_put_contents($_output_filepath, json_encode($log, JSON_PRETTY_PRINT));
            exit(1);
        }
    });

    global $_output_filepath;
    global $_is_service;
    global $user_id;
    global $user_text;
    global $_money_symbol;
    global $_format_date_time;
    $_money_symbol = "$ ";
    $_format_date_time = "Y-m-d";
    $_output_filepath = RUTA_ROOT . "/resources/logs/log.log";
    $_is_service = "1";
    $user_id = isset($_COOKIE['user_id']) ? $_COOKIE['user_id'] : errorUserId("3");
    $user_text = isset($_COOKIE['user_text']) ? $_COOKIE['user_text'] : errorUserId("4");

    function errorUserId($rale)
    {
        if (isset($_REQUEST['metodo']) && ($_REQUEST['metodo'] != 'validateLogin' && $_REQUEST['metodo'] != 'changePsw')) {
            throw new myException("Usuario eliminado por el navegador, inicie sesión nuevamente.", []);
        }
    }

    function service_return(array $param)
    {
        $success = isset($param['success']) ? $param['success'] : true;
        $title = isset($param['title']) ? $param['title'] : 'Genial!';
        $icon = isset($param['icon']) ? $param['icon'] : 'success';
        $message = isset($param['message']) ? $param['message'] : 'success';
        $data = isset($param['data']) ? $param['data'] : [];
        $ret = ['success' => $success, 'title' => $title, 'icon' => $icon, 'message' => $message, 'detail' => $data];
        $raw_bytes = json_encode($ret, JSON_PRETTY_PRINT);

        echo $raw_bytes;
        exit(0);
    }


    function formatDateTime($nativeData)
    {

        global $_format_date_time;

        $format = $_format_date_time;

        if (!is_null($format)) {
            return date($format, $nativeData);
        } else {
            return $nativeData;
        }
    }

    function formatNumber($number)
    {

        global $_number_format;

        $format = $_number_format;

        if (!is_null($format)) {
            return number_format((float) $number, (int) $format['DECIMALS'], $format['DECIMAL_SEPARATOR'], $format['THOUSANDS_SEPARATOR']);
        } else {
            return number_format($number, 2, '.', ',');
        }
    }

    function formatMoney($number)
    {
        global $_money_symbol;
        return $_money_symbol . number_format((float) $number, 2, '.', ',');
    }

    function rlog(...$data)
    {
        $user = 'ANCIZAR_LOPEZ';
        $_app_name = 'InduSoft';
        foreach ($data as $item) {
            $debug_arr = debug_backtrace();
            file_put_contents(RUTA_ROOT . '/tmp/debug.log', "\n[ " . $user . " ]\n" . "[ " . date("Y-m-d H:i:s") . " ]\n" . "[ File: " . $debug_arr[0]['file'] . " ]\n[ Line: " . $debug_arr[0]['line'] . " ]\n[ Data: " . var_export($item, true) . " ]\n\n", FILE_APPEND);
        }
    }

    function crearSentenciaDelete(array $param)
    {
        $tabla = isset($param['tabla']) ? $param['tabla'] : '';
        $where = isset($param['where']) ? $param['where'] : [];
        $clause_where = ' 1 = 1 ';
        $_delete = " DELETE FROM ";
        if (!empty($where)) {
            foreach ($where as $column => $value) {
                if (is_object($value) || is_array($value) || isset($value['whereRaw'])) {
                    if (count($value) >= 3) {
                        try {
                            $where_groups[] = " $value[0] $value[1] $value[2] ";
                        } catch (\Throwable $th) {
                            throw new myException("Expected three parameters [field, operator, value]", 1);
                        }
                    } else {
                        throw new myException("Expected three parameters [field, operator, value]", 1);
                    }
                } else {
                    $where_groups[] = " $column = '" . addslashes(sprintf('%s', (string) $value)) . "'";
                }
            }
            $clause_where = implode(' AND ', $where_groups);
        }
        $_delete = $_delete . $tabla . ' WHERE ' . $clause_where;
        return $_delete;
    }

    function crearSentenciaInsert(array $param)
    {
        $tabla = isset($param['tabla']) ? $param['tabla'] : '';
        $conten = isset($param['conten']) ? $param['conten'] : [];

        $insert = " INSERT INTO $tabla ";
        $claves = "(" . implode(', ', array_keys($conten)) . ")";
        $valores = " values (" . implode(',', array_map(function ($item) {
            return trim(sprintf("'%s'", $item));
        }, $conten)) . ")";

        $insert = $insert . $claves . $valores;
        if (empty($tabla) || empty($conten)) {
            $insert = '';
        }

        return $insert;
    }

    function crearSentenciaUpdate(array $param)
    {
        $tabla = isset($param['tabla']) ? $param['tabla'] : '';
        $sets = isset($param['sets']) ? $param['sets'] : [];
        $where = isset($param['where']) ? $param['where'] : [];

        $update = " UPDATE $tabla ";
        foreach ($sets as $column => $value) {
            if (is_object($value) || is_array($value)) {
                if (count($value) >= 3) {
                    try {
                        $filed_groups[] = " $column = $value[0] $value[1] $value[2] ";
                    } catch (\Throwable $th) {
                        throw new myException("Expected three parameters [field, operator, value]", 1);
                    }
                } else {
                    throw new myException("Expected three parameters [field, operator, value]", 1);
                }
            } else {
                // $value = escapeNewlines($value);
                $filed_groups[] = " $column = '" . addslashes(sprintf('%s', (string) $value)) . "'";
            }
        }

        $columns_str = implode(',', $filed_groups);

        foreach ($where as $column => $value) {
            $where_groups[] = " $column = '" . addslashes(sprintf('%s', (string) $value)) . "'";
        }
        $clause_where = implode(' AND ', $where_groups);
        // $clause_where = 

        $update = $update . ' SET ' . $columns_str . ' WHERE ' . $clause_where;
        return $update;
    }

    function crearSentenciaSelect(array $param)
    {
        $tabla = isset($param['tabla']) ? $param['tabla'] : '';
        $fields = isset($param['fields']) ? $param['fields'] : [];
        $where = isset($param['where']) ? $param['where'] : [];
        $limit = isset($param['limit']) ? $param['limit'] : '';

        $columns_str = implode(', ', array_map(function ($item) {
            return "`$item`";
        }, $fields));
        if (empty($columns_str)) {
            $columns_str = ' * ';
        }

        foreach ($where as $column => $value) {
            $where_groups[] = " $column = '" . addslashes((string) $value) . "'";
        }
        $clause_where = implode(' AND ', $where_groups);

        $sql = "SELECT $columns_str FROM $tabla WHERE $clause_where $limit";

        return $sql;
    }

    function escapeNewlines($value)
    {
        return str_replace(["\r\n", "\n", "\r"], "\\n", $value);
    }

    function sgmEx(array $m_params)
    {
        /*DESCRIPTION: Stores a parameter in global memory */
        //**PARAMETERS START
        $id = $m_params['id']; //Id of the parameter to store in global memory
        $value = $m_params['value']; //Value to store in global memory. It can be a deep structure
        $done = isset($m_params['done']) ? $m_params['done'] : ''; //Routine to execute after successfully storing the parameter in global memory
        $fail = isset($m_params['fail']) ? $m_params['fail'] : ''; //Routine to execute if storing the parameter in global memory fails
        //**PARAMETERS END
        /*RETURN:(void)*/
        //** -- **

        global $user, $user_full_name, $user_id, $_language, $system;
        //$_global_memory[$user.$id] = $value;

        $data = json_encode(['id' => $user . $id, 'data' => $value]);

        $headers = [
            'Content-Type: application/json;charset=UTF-8',
            'User-Agent: InduSoft',
            'session_internal_data: ' . json_encode([$user, $user_full_name, $user_id, $_language, $system]),
            'Content-Length: ' . strlen($data)
        ];

        // $srvcfg = parse_ini_file('srvcfg.ini',true);
        // exit("llega");
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_POST, true);
        //curl_setopt($curl, CURLOPT_URL, "http://127.0.0.1:{$srvcfg['http_settings']['port_number']}/gm/put/internal/$system");
        curl_setopt($curl, CURLOPT_URL, "http://127.0.0.1/gm/put");
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLINFO_HEADER_OUT, true);
        $result_raw = curl_exec($curl);
        $http_reponse_code = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $header_out = curl_getinfo($curl, CURLINFO_HEADER_OUT);

        if ($http_reponse_code != 200)
            throw new \Exception("Global memory storage error: HTTP CODE:" . $http_reponse_code . "|RESPONSE:" . $result_raw . "|CURL:" . curl_error($curl), 1);
    }


    function mfex($field)
    {
        $trace = debug_backtrace();
        throw new \Exception("Mandatory parameter '$field' not found on function '" . $trace[1]['function'] . "' Location :" . $trace[1]['file'] . ':' . $trace[1]['line'] . "'");
        return "";
    }

    function dateFormat($date)
    {
        $newDate = $date;
        $d = new \DateTime();
        $dateVal = $d->createFromFormat('Y-m-d', $date);
        if ($dateVal) {
            $newDate = $dateVal->format('d-m-Y');
        }
        return $newDate;
    }

    function uniqueId()
    {
        $numeros_aleatorios = mt_rand(1000, 9999);
        $unidId = date("Ymdhis") . "_" . $numeros_aleatorios;
        return $unidId;
    }
}
