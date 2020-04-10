<?php

/**
 * @see       https://github.com/josiahking/evolvephp for read me and documentation
 * @copyright https://github.com/josiahking/evolvephp/blob/master/COPYRIGHT.md
 * @license   https://github.com/josiahking/evolvephp/blob/master/LICENSE.md
 * @package EvolvePHP
 * @author 
 * @link Documentation on this file
 * @since Version 1.0
 * @filesource
 */

namespace EvolvePhpCore;
use EvolvePhpCore\ApplicationAbstract;
use EvolvePhpCore\Loader;
/**
 * Model
 * Application Model
 * Provides simple methods for interacting with database
 * 
 */
class Model extends ApplicationAbstract
{
    /**
     * $pdoConn holds the pdo connection object or null
     * @var object|null
     */
    private $pdoConn = null;
    
    /**
     * $table holds the list of tables of database as array
     * @var array
     */
    protected $tables = [];
    
    /**
     * $supportedDatabase holds list of supported database
     * @var array 
     */
    protected $supportedDatabase = [
        'mysql' => 'mysql',
        'pgsql' => 'pgsql',
        'sqlite_2' => 'sqlite2',
        'sqlite' => 'sqlite'
    ];
    
    /**
     * initiate database connect by default or not when $openConnect is false
     * @param bool $openConnect
     */
    protected function __construct(bool $openConnect = true) 
    {
        if(!$openConnect){
            return false;
        }
        $tablesConfig = Loader::loadFile(CONFIGS_DIR.'database-tables.config',true);
        $this->setTables($tablesConfig);
        $this->connect();
    }
    
    /**
     * getSupportedDatabase
     * return array of supported database
     * @return array
     */
    public function getSupportedDatabase() :array
    {
        return $this->supportedDatabase;
    }
    
    /**
     * addSupportedDatabase
     * add to the list of supported database and extends this class to write connection method to new  added database
     * @param string $key
     * @param string $type
     */
    protected function addSupportedDatabase(string $key,string $type) 
    {
        $this->supportedDatabase[$key] = $type;
    }
    
    /**
     * setTables
     * set table array
     * @param array $tablesConfig
     */
    protected function setTables(array $tablesConfig) 
    {
        $this->tables = $tablesConfig;
    }
    
    /**
     * getTables
     * get table array
     * @return array
     */
    protected function getTables() :array
    {
        return $this->tables;
    }

    /**
     * connect
     * open connection to the database using the define type
     * @return $this
     */
    protected function connect() {
        $dbConfig = $this->getDatabaseConfig();
        if(!in_array($dbConfig['type'], $this->getSupportedDatabase())){
            ExceptionFactory::getInstance()->triggerError("Database config type is not supported");
        }
        if($dbConfig['type'] == "pgsql"){
            $dsn = 'pgsql:host=' . $dbConfig['host'] . ';port=' . $dbConfig['port'] . ';dbname=' . $dbConfig['database'] . ';user=' . $dbConfig['user'] . ';password=' . $dbConfig['password'];
            try{
                $this->pdoConn = new \PDO($dsn);
                if (DEBUG) {
                    $this->pdoConn->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_WARNING);
                }
                else {
                    $this->pdoConn->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
                }
                $this->pdoConn->setAttribute(\PDO::ATTR_EMULATE_PREPARES, true);
            } catch (\PDOException $ex) {
                if(DEBUG){
                    echo $ex->getMessage();
                }
                else{
                    ExceptionFactory::getInstance()->triggerError('Could not communicate with the database server');
                }
            }
        }
        if($dbConfig['type'] == "mysql"){
            $dsn = 'mysql:host=' . $dbConfig['host'] . ';dbname=' . $dbConfig['database'];
            try{
                $this->pdoConn = new \PDO($dsn,$dbConfig['user'],$dbConfig['password']);
                if (DEBUG) {
                    $this->pdoConn->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_WARNING);
                }
                else {
                    $this->pdoConn->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
                }
                $this->pdoConn->setAttribute(\PDO::ATTR_EMULATE_PREPARES, true);
            } catch (\PDOException $ex) {
                if(DEBUG){
                    echo $ex->getMessage();
                }
                else{
                    ExceptionFactory::getInstance()->triggerError('Could not communicate with the database server');
                }
            }
        }
        if($dbConfig['type'] == "sqlite_2"){
            $dsn = 'sqlite2:' . $dbConfig['sqlite_option'];
            try{
                $this->pdoConn = new \PDO($dsn);
                if (DEBUG) {
                    $this->pdoConn->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_WARNING);
                }
                else {
                    $this->pdoConn->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
                }
            } catch (\PDOException $ex) {
                if(DEBUG){
                    echo $ex->getMessage();
                }
                else{
                    ExceptionFactory::getInstance()->triggerError('Could not communicate with the database');
                }
            }
        }
        if($dbConfig['type'] == "sqlite"){
            $dsn = 'sqlite:' . $dbConfig['sqlite_option'];
            try{
                $this->pdoConn = new \PDO($dsn);
            } catch (\PDOException $ex) {
                if(DEBUG){
                    echo $ex->getMessage();
                }
                else{
                    ExceptionFactory::getInstance()->triggerError('Could not communicate with the database');
                }
            }
        }
        return $this;
    }
    
    /**
     * reconnect
     * reopen connection to the database
     */
    protected function reconnect() 
    {
        if (empty($this->pdoConn)) {
            $this->connect();
        }
    }

    /**
     * getDatabaseConfig
     * get database configuration
     * @return array
     */
    private function getDatabaseConfig() :array
    {
        return Loader::loadFile(CONFIGS_DIR.'database.config',true);
    }

    /**
     * getLastError
     * get last error
     * @return array
     */
    protected function getLastError() :array
    {
        $this->reconnect();
        return $this->pdoConn->errorInfo();
    }
    
    /**
     * getLastInsertId
     * get last insert id
     * @param type $seq
     * @return int
     */
    protected function getLastInsertId($seq = false) :int
    {
        try {
            $this->reconnect();
            if (!$seq) {
                return $this->pdoConn->lastInsertId();
            }
            return $this->pdoConn->lastInsertId($seq);
        } catch (\PDOException $ex) {
            if (DEBUG) {
                echo $ex->getMessage();
            } else {
                ExceptionFactory::getInstance()->triggerError('Could not get last insert id');
            }
        }
    }
    
    /**
     * pdoPrepareInsertSql
     * write sql query and insert into database
     * @param string $query
     * @param array $data
     * @return bool
     */
    protected function pdoPrepareInsertSql(string $query, array $data) :bool
    {
        try {
            $this->reconnect();
            $stmt = $this->pdoConn->prepare($query);
            $result = $stmt->execute($data);
            if ($result === true) {
                return true;
            } else {
                if (DEBUG) {
                    ExceptionFactory::getInstance()->triggerError('Could not perform insert: '.$this->pdoConn->errorInfo()[2]);
                } 
                return false;
            }
        } catch (\PDOException $ex) {
            if (DEBUG) {
                echo $ex->getMessage();
            } else {
                ExceptionFactory::getInstance()->triggerError('Could not insert data');
            }
        }
    }
    
    /**
     * pdoPrepareCountSql
     * count number of return row(s)
     * @param string $query
     * @param array $data
     * @return bool|int
     */
    protected function pdoPrepareCountSql(string $query,array $data)
    {
        try {
            $this->reconnect();
            $stmt = $this->pdoConn->prepare($query);
            $result = $stmt->execute($data);
            if ($result === true) {
                return $stmt->rowCount();
            } else {
                return false;
            }
        } catch (\PDOException $ex) {
            if (DEBUG) {
                echo $ex->getMessage();
            } else {
                ExceptionFactory::getInstance()->triggerError('Could not perform count');
            }
        }
    }
    
    /**
     * pdoPrepareSelectSingleSql
     * select/fetch single data from table
     * @param string $query
     * @param array $data
     * @param type $mode
     * @return array
     */
    protected function pdoPrepareSelectSingleSql(string $query, array $data, $mode = false) :array
    {
        try {
            $this->reconnect();
            $stmt = $this->pdoConn->prepare($query);
            $result = $stmt->execute($data);
            if ($mode) {
                $stmt->setFetchMode($mode);
            } else {
                $stmt->setFetchMode(\PDO::FETCH_ASSOC);
            }
            if ($result === true) {
                if ($stmt->rowCount() > 0) {
                    return $stmt->fetch();
                } 
                return [];
            } else {
                ExceptionFactory::getInstance()->triggerError($this->pdoConn->errorInfo()[2]);
            }
        } catch (\PDOException $ex) {
            if (DEBUG) {
                echo $ex->getMessage();
            } else {
                ExceptionFactory::getInstance()->triggerError('Could not perform select single');
            }
        }
    }
    
    /**
     * pdoPrepareSelectAllSql
     * select/fetch all data from table
     * @param string $query
     * @param array $data
     * @param type $mode
     * @return array
     */
    protected function pdoPrepareSelectAllSql(string $query, array $data, $mode = false) :array
    {
        try {
            $this->reconnect();
            $stmt = $this->pdoConn->prepare($query);
            $result = $stmt->execute($data);
            if ($mode) {
                $stmt->setFetchMode($mode);
            } else {
                $stmt->setFetchMode(\PDO::FETCH_ASSOC);
            }
            if ($result === true) {
                if ($stmt->rowCount() > 0) {
                    return $stmt->fetchAll();
                }
                return [];
            } else {
                ExceptionFactory::getInstance()->triggerError($this->pdoConn->errorInfo()[2]);
            }
        } catch (\PDOException $ex) {
            if (DEBUG) {
                echo $ex->getMessage();
            } else {
                ExceptionFactory::getInstance()->triggerError('Could not perform select all');
            }
        }
    }
    
    /**
     * pdoPrepare
     * prepare sql query for pdo
     * @param string $query
     * @return object
     */
    protected function pdoPrepare(string $query)
    {
        try {
            $this->reconnect();
            return $this->pdoConn->prepare($query);
        } catch (\PDOException $ex) {
            if (DEBUG) {
                echo $ex->getMessage();
            } else {
                ExceptionFactory::getInstance()->triggerError('Could not prepare query');
            }
        }
    }
    
    /**
     * pdoExecute
     * execute pdo prepared statement
     * @param type $stmt
     * @param type $param
     * @return type
     * @throws InvalidArgumentException
     */
    protected function pdoExecute($stmt, $param = false)
    {
        try {
            if(!is_object($var)){
                throw new InvalidArgumentException(var_export($stmt)." is not an object");
            }
            if ($param) {
                return $stmt->exec($param);
            } else {
                return $stmt->exec();
            }
        } catch (\PDOException $ex) {
            if (DEBUG) {
                echo $ex->getMessage();
            } else {
                ExceptionFactory::getInstance()->triggerError('Could not execute statement');
            }
        }
    }
    
    /**
     * resetPdoConn
     * reset $pdoConn to null
     */
    protected function resetPdoConn()
    {
        $this->pdoConn = null;
    }
}
