<?php
namespace Tqdev\PhpCrudApi\Database;

use Tqdev\PhpCrudApi\Column\Reflection\ReflectedTable;
use Tqdev\PhpCrudApi\Middleware\Communication\VariableStore;
use Tqdev\PhpCrudApi\Record\Condition\AndCondition;
use Tqdev\PhpCrudApi\Record\Condition\ColumnCondition;
use Tqdev\PhpCrudApi\Record\Condition\Condition;

class GenericDB
{
    private $driver;
    private $database;
    private $pdo;
    private $reflection;
    private $columns;
    private $conditions;
    private $converter;

    private function getDsn(String $address, String $port = null, String $database = null): String
    {
        switch ($this->driver) {
            case 'mysql':return "$this->driver:host=$address;port=$port;dbname=$database;charset=utf8mb4";
            case 'pgsql':return "$this->driver:host=$address port=$port dbname=$database options='--client_encoding=UTF8'";
            case 'sqlsrv':return "$this->driver:Server=$address,$port;Database=$database";
        }
    }

    private function getCommands(): array
    {
        switch ($this->driver) {
            case 'mysql':return [
                    'SET SESSION sql_warnings=1;',
                    'SET NAMES utf8mb4;',
                    'SET SESSION sql_mode = "ANSI,TRADITIONAL";',
                ];
            case 'pgsql':return [
                    "SET NAMES 'UTF8';",
                ];
            case 'sqlsrv':return [
                ];
        }
    }

    private function getOptions(): array
    {
        $options = array(
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        );
        switch ($this->driver) {
            case 'mysql':return $options + [
                    \PDO::ATTR_EMULATE_PREPARES => false,
                    \PDO::MYSQL_ATTR_FOUND_ROWS => true,
                    \PDO::ATTR_PERSISTENT => true,
                ];
            case 'pgsql':return $options + [
                    \PDO::ATTR_EMULATE_PREPARES => false,
                    \PDO::ATTR_PERSISTENT => true,
                ];
            case 'sqlsrv':return $options + [
                    \PDO::SQLSRV_ATTR_FETCHES_NUMERIC_TYPE => true,
                ];
        }
    }

    public function __construct(String $driver, String $address, String $port = null, String $database = null, String $username = null, String $password = null)
    {
        $this->driver = $driver;
        $this->database = $database;
        $dsn = $this->getDsn($address, $port, $database);
        $options = $this->getOptions();
        $this->pdo = new \PDO($dsn, $username, $password, $options);
        $commands = $this->getCommands();
        foreach ($commands as $command) {
            $this->pdo->query($command);
        }
        $this->reflection = new GenericReflection($this->pdo, $driver, $database);
        $this->definition = new GenericDefinition($this->pdo, $driver, $database);
        $this->conditions = new ConditionsBuilder($driver);
        $this->columns = new ColumnsBuilder($driver);
        $this->converter = new DataConverter($driver);
    }

    public function pdo(): \PDO
    {
        return $this->pdo;
    }

    public function reflection(): GenericReflection
    {
        return $this->reflection;
    }

    public function definition(): GenericDefinition
    {
        return $this->definition;
    }

    private function addAuthorizationCondition(Condition $condition2): Condition
    {
        $condition1 = VariableStore::get('authorization.condition');
        return $condition1 ? AndCondition::fromArray([$condition1, $condition2]) : $condition2;
    }

    public function createSingle(ReflectedTable $table, array $columnValues) /*: ?String*/
    {
        $this->converter->convertColumnValues($table, $columnValues);
        $insertColumns = $this->columns->getInsert($table, $columnValues);
        $tableName = $table->getName();
        $pkName = $table->getPk()->getName();
        $parameters = array_values($columnValues);
        $sql = 'INSERT INTO "' . $tableName . '" ' . $insertColumns;
        $stmt = $this->query($sql, $parameters);
        // return primary key value if specified in the input
        if (isset($columnValues[$pkName])) {
            return $columnValues[$pkName];
        }
        // work around missing "returning" or "output" in mysql
        switch ($this->driver) {
            case 'mysql':
                $stmt = $this->query('SELECT LAST_INSERT_ID()', []);
                break;
        }
        return $stmt->fetchColumn(0);
    }

    public function selectSingle(ReflectedTable $table, array $columnNames, String $id) /*: ?array*/
    {
        $selectColumns = $this->columns->getSelect($table, $columnNames);
        $tableName = $table->getName();
        $condition = new ColumnCondition($table->getPk(), 'eq', $id);
        $condition = $this->addAuthorizationCondition($condition);
        $parameters = array();
        $whereClause = $this->conditions->getWhereClause($condition, $parameters);
        $sql = 'SELECT ' . $selectColumns . ' FROM "' . $tableName . '" ' . $whereClause;
        $stmt = $this->query($sql, $parameters);
        $record = $stmt->fetch() ?: null;
        if ($record === null) {
            return null;
        }
        $records = array($record);
        $this->converter->convertRecords($table, $columnNames, $records);
        return $records[0];
    }

    public function selectMultiple(ReflectedTable $table, array $columnNames, array $ids): array
    {
        if (count($ids) == 0) {
            return [];
        }
        $selectColumns = $this->columns->getSelect($table, $columnNames);
        $tableName = $table->getName();
        $condition = new ColumnCondition($table->getPk(), 'in', implode(',', $ids));
        $condition = $this->addAuthorizationCondition($condition);
        $parameters = array();
        $whereClause = $this->conditions->getWhereClause($condition, $parameters);
        $sql = 'SELECT ' . $selectColumns . ' FROM "' . $tableName . '" ' . $whereClause;
        $stmt = $this->query($sql, $parameters);
        $records = $stmt->fetchAll();
        $this->converter->convertRecords($table, $columnNames, $records);
        return $records;
    }

    public function selectCount(ReflectedTable $table, Condition $condition): int
    {
        $tableName = $table->getName();
        $condition = $this->addAuthorizationCondition($condition);
        $parameters = array();
        $whereClause = $this->conditions->getWhereClause($condition, $parameters);
        $sql = 'SELECT COUNT(*) FROM "' . $tableName . '"' . $whereClause;
        $stmt = $this->query($sql, $parameters);
        return $stmt->fetchColumn(0);
    }

    public function selectAllUnordered(ReflectedTable $table, array $columnNames, Condition $condition): array
    {
        $selectColumns = $this->columns->getSelect($table, $columnNames);
        $tableName = $table->getName();
        $condition = $this->addAuthorizationCondition($condition);
        $parameters = array();
        $whereClause = $this->conditions->getWhereClause($condition, $parameters);
        $sql = 'SELECT ' . $selectColumns . ' FROM "' . $tableName . '"' . $whereClause;
        $stmt = $this->query($sql, $parameters);
        $records = $stmt->fetchAll();
        $this->converter->convertRecords($table, $columnNames, $records);
        return $records;
    }

    public function selectAll(ReflectedTable $table, array $columnNames, Condition $condition, array $columnOrdering, int $offset, int $limit): array
    {
        if ($limit == 0) {
            return array();
        }
        if (!$columnOrdering) {
            return $this->selectAllUnordered($table, $columnNames, $condition);
        }
        $selectColumns = $this->columns->getSelect($table, $columnNames);
        $tableName = $table->getName();
        $condition = $this->addAuthorizationCondition($condition);
        $parameters = array();
        $whereClause = $this->conditions->getWhereClause($condition, $parameters);
        $orderBy = $this->columns->getOrderBy($table, $columnOrdering);
        $offsetLimit = $this->columns->getOffsetLimit($offset, $limit);
        $sql = 'SELECT ' . $selectColumns . ' FROM "' . $tableName . '"' . $whereClause . ' ORDER BY ' . $orderBy . ' ' . $offsetLimit;
        $stmt = $this->query($sql, $parameters);
        $records = $stmt->fetchAll();
        $this->converter->convertRecords($table, $columnNames, $records);
        return $records;
    }

    public function updateSingle(ReflectedTable $table, array $columnValues, String $id)
    {
        if (count($columnValues) == 0) {
            return 0;
        }
        $this->converter->convertColumnValues($table, $columnValues);
        $updateColumns = $this->columns->getUpdate($table, $columnValues);
        $tableName = $table->getName();
        $condition = new ColumnCondition($table->getPk(), 'eq', $id);
        $condition = $this->addAuthorizationCondition($condition);
        $parameters = array_values($columnValues);
        $whereClause = $this->conditions->getWhereClause($condition, $parameters);
        $sql = 'UPDATE "' . $tableName . '" SET ' . $updateColumns . $whereClause;
        $stmt = $this->query($sql, $parameters);
        return $stmt->rowCount();
    }

    public function deleteSingle(ReflectedTable $table, String $id)
    {
        $tableName = $table->getName();
        $condition = new ColumnCondition($table->getPk(), 'eq', $id);
        $condition = $this->addAuthorizationCondition($condition);
        $parameters = array();
        $whereClause = $this->conditions->getWhereClause($condition, $parameters);
        $sql = 'DELETE FROM "' . $tableName . '" ' . $whereClause;
        $stmt = $this->query($sql, $parameters);
        return $stmt->rowCount();
    }

    public function incrementSingle(ReflectedTable $table, array $columnValues, String $id)
    {
        if (count($columnValues) == 0) {
            return 0;
        }
        $this->converter->convertColumnValues($table, $columnValues);
        $updateColumns = $this->columns->getIncrement($table, $columnValues);
        $tableName = $table->getName();
        $condition = new ColumnCondition($table->getPk(), 'eq', $id);
        $condition = $this->addAuthorizationCondition($condition);
        $parameters = array_values($columnValues);
        $whereClause = $this->conditions->getWhereClause($condition, $parameters);
        $sql = 'UPDATE "' . $tableName . '" SET ' . $updateColumns . $whereClause;
        $stmt = $this->query($sql, $parameters);
        return $stmt->rowCount();
    }

    private function query(String $sql, array $parameters): \PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        //echo "- $sql -- " . json_encode($parameters, JSON_UNESCAPED_UNICODE) . "\n";
        $stmt->execute($parameters);
        return $stmt;
    }
}
