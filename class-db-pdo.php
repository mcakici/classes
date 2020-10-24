<?php /* MÇ - PDO - Database Class with prepared statements - Last Modified at 11-08-2020 - https://github.com/mcakici */
class db {
    private $connection;
    private $querystring;
    private $query_closed = true;
    private $show_errors = true;
    private $log_errors = true;
    private $error_log_path = "./db-errors.log";
    private $log_queries = false;
    private $queries_log_path = "./db-queries.log";
    private $query_count = 0;
    private $transaction_process = false;

    public function __construct($dbhost = 'localhost', $dbuser = 'root', $dbpass = '', $dbname = '', $charset = 'utf8mb4') {
        try {
            //change PDO dsn if u want to use another databases - mysql,sqlite,firebird,pgsql
            $this->connection = new PDO("mysql:host={$dbhost};dbname={$dbname};charset={$charset}", $dbuser, $dbpass);
            $this->connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $e) {
            $this->error('Failed to connect to Database - ' . $e->getMessage());
        }
    }

    public function query($querystring) {
        if (!$this->query_closed && !$this->transaction_process) {
            $this->query = null;
        }
        try {
            $this->query = $this->connection->prepare($querystring);
            if (func_num_args() > 1) {
                $x = func_get_args();
                $args = array_slice($x, 1);
                foreach ($args as $k => &$arg) {
                    if (is_array($args[$k])) {
                        foreach ($args[$k] as $j => &$a) {
                            $this->query->bindParam(($j+1), $a, $this->_gettype($args[$k][$j]));
                        }
                    } else {
                        $this->query->bindParam(($k+1), $arg, $this->_gettype($args[$k]));
                    }
                }
            }
            $this->query->execute();
            $this->query_closed = false;
            $this->query_count++;
            if ($this->log_queries) {
                file_put_contents($this->queries_log_path, "[" . date("d-m-Y H:i:s") . " " . date_default_timezone_get() . "] [" . basename($_SERVER["SCRIPT_FILENAME"]) . "] " . $querystring . " [" . json_encode($args) . "]\n", FILE_APPEND);
            }
        } catch (PDOException $e) {
            if ($this->transaction_process) {
                return false;
            } else {
                $this->error( $e->getMessage() . ' | ' . $querystring );
            }
        }
        return $this;
    }

    public function fetchAll($callback = null) {
        $result = array();
        while ($row = $this->query->fetchAll(PDO::FETCH_ASSOC)) {
            $r = array();
            foreach ($row as $key => $val) {
                $r[$key] = $val;
            }
            if ($callback != null && is_callable($callback)) {
                $value = call_user_func($callback, $r);
                if ($value == 'break') {
                    break;
                }

            } else {
                $result[] = $r;
            }
        }
        $this->query = null;
        $this->query_closed = true;
        return $result;
    }

    public function fetchAllObject($callback = null) {
        $result = array();
        while ($row = $this->query->fetchAll(PDO::FETCH_OBJ)) {
            $r = array();
            foreach ($row as $key => $val) {
                $r[$key] = $val;
            }
            if ($callback != null && is_callable($callback)) {
                $value = call_user_func($callback, $r);
                if ($value == 'break') {
                    break;
                }

            } else {
                $result[] = $r;
            }
        }
        $this->query = null;
        $this->query_closed = true;
        return $result;
    }

    public function singleArray() {
        $result = $this->query->fetch(PDO::FETCH_ASSOC);
        $this->query = null;
        $this->query_closed = true;
        return $result;
    }

    public function singleObject() {
        $result = $this->query->fetch(PDO::FETCH_OBJ);
        $this->query = null;
        $this->query_closed = true;
        return $result;
    }

    public function close() {
        return $this->connection = null;
    }

    public function numRows() {
        return $this->query->rowCount();
    }

    public function affectedRows() {
        return $this->query->rowCount();
    }

    public function lastInsertID() {
        return $this->connection->lastInsertId();
    }

    public function queryCount() {
        return $this->query_count;
    }

    public function error($error) {
        if ($this->log_errors) {
            file_put_contents($this->error_log_path, "[" . date("d-m-Y H:i:s") . " " . date_default_timezone_get() . "] " . $error . " [" . basename($_SERVER["SCRIPT_FILENAME"]) . "]\n", FILE_APPEND);
        }
        if ($this->show_errors) {
            exit($error);
        }
    }

    private function _gettype($var) {
        if (is_string($var)) {
            return PDO::PARAM_STR;
        }
        if (is_int($var)) {
            return PDO::PARAM_INT;
        }
        if (is_bool($var)) {
            return PDO::PARAM_BOOL;
        }
        return PDO::PARAM_NULL;
    }

    public function begin() {
        $this->transaction_process = true;
        return $this->connection->beginTransaction();
    }

    public function commit() {
        $this->transaction_process = false;
        return $this->connection->commit();
    }

    public function rollback() {
        $this->transaction_process = false;
        return $this->connection->rollback();
    }

    public function __destruct() {
        $this->close();
    }
}
