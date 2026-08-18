<?php

use function InduSoft\mfex;
use function InduSoft\rlog;

/**
 *
 * @author Ancizar
 */
class model extends Global_class
{

    private $dbh;
    public function __construct()
    {

        $myQuery = new Global_class();
        $this->dbh = $myQuery;
    }

    public function executeScript(array $params)
    {
        $sql = !empty($params['sql']) ? $params['sql'] : '';
        return $this->dbh->myQuery($sql, []);
    }
}
