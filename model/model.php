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

    public function queryPrepared(string $sql, array $params = []): array
    {
        return $this->dbh->myQuery($sql, $params);
    }

    public function executePrepared(string $sql, array $params = []): int
    {
        return $this->dbh->prepare_statement($sql, $params);
    }

    public function beginTransaction(): void
    {
        $this->dbh->beginTransaction();
    }

    public function commit(): void
    {
        $this->dbh->commit();
    }

    public function rollBack(): void
    {
        $this->dbh->rollBack();
    }
}
